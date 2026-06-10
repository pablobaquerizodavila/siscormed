# Stripe Checkout + factura — discovery

Este documento captura las decisiones que necesito de Pablo antes de
implementar el módulo de pagos. Una vez decididas, el código sale más o
menos directo. Hasta entonces, no inicio implementación para no construir
sobre supuestos equivocados.

---

## 1. Cuenta Stripe y entorno

⏳ Pendiente Pablo (10-jun-2026): aún no hay cuenta Stripe creada, no se
ha decidido. Notas para cuando se decida:

- Stripe Ecuador soporta cuentas locales con RUC. Aceptan tarjetas
  internacionales y locales (Diners/MasterCard/Visa). El onboarding pide
  RUC del emisor, info bancaria EC, y verificación de identidad del
  representante legal.
- Una vez creada, claves vienen en pares: test (`sk_test_…` / `pk_test_…`)
  y live (`sk_live_…` / `pk_live_…`). Recomendado fuertemente arrancar
  en test mode hasta el primer flujo end-to-end verde, después promover
  a live.
- Webhook secret (`whsec_…`) se genera en el dashboard al definir el
  endpoint; va en `config.local.php`.

## 2. Modelo de cobro — DECIDIDO 2026-06-10

- ✅ **Modalidad: ONE-SHOT por orden.** Cada `numero_orden` aprobado por
  el médico se cobra una sola vez. Si el paciente sigue tratamiento, el
  médico aprueba una nueva orden el mes siguiente y vuelve al checkout.
  Implica: NO usamos `customer.subscription.*`, no hay Customer Portal,
  el webhook a manejar es `checkout.session.completed`.
- ✅ **Precio: en Stripe Dashboard.** Cada medicamento × dosis se
  define como un `Price` (recurring=false) en el dashboard. La tabla
  `pacientes.medicamento_aprobado` debe poder mapearse 1:1 a un
  `price_id`. Plan de mapeo (a confirmar al crear los productos):
  | medicamento_aprobado           | producto Stripe sugerido  |
  | ------------------------------ | ------------------------- |
  | Semaglutida 0.25mg (inicio)    | Semaglutide 0.25mg one-shot |
  | Semaglutida 0.5mg              | Semaglutide 0.5mg one-shot |
  | Semaglutida 1mg                | Semaglutide 1mg one-shot   |
  | Tirzepatida 2.5mg              | Tirzepatide 2.5mg one-shot |
  | Tirzepatida 5mg                | Tirzepatide 5mg one-shot   |
  | Tirzepatida 10mg               | Tirzepatide 10mg one-shot  |

  Mantengo un mapa string→price_id en `api/config.local.php` (gitignored)
  para no hardcodear price_ids en código público.
- ✅ **Moneda: USD.**
- ✅ **Envío: cobra aparte.** Uso `shipping_options` en la sesión de
  Checkout. Pendiente definir: ¿una sola tarifa fija "Envío nacional EC
  $X" o varias opciones (estándar / express)? Por defecto asumo una
  tarifa fija — me dices el monto cuando vayamos a crearlo.

## 3. Flujo de pago para el paciente — DECIDIDO 2026-06-10

- ✅ **(c) Ambas**: email al paciente con link de Checkout cuando el
  médico aprueba **+** portal en `siscormed.com` donde el paciente entra
  con cédula/email y ve un botón "Pagar ahora". Ambas rutas terminan en
  la misma `Checkout.Session`.
- ✅ **Datos de Stripe Checkout**:
  - `customer_email` prefilled con `pacientes.email`
  - `success_url` → `https://siscormed.com/pago-ok.html?session_id={CHECKOUT_SESSION_ID}`
  - `cancel_url` → `https://siscormed.com/pago-cancelado.html?numero_orden=…`
  - `metadata.paciente_id` y `metadata.numero_orden` para reconciliar en
    webhook
- ✅ Recibo automático de Stripe queda **prendido** como recibo legible
  inmediato; la factura SRI formal la emite el laboratorio (ver §4) y
  llega después por email.

## 4. Factura — DECIDIDO 2026-06-10 (modelo marketplace)

**Esquema acordado:** Siscormed **NO emite factura**. Siscormed es
**agente de recaudación**. La factura electrónica SRI la emite el
laboratorio o la persona natural/jurídica autorizada a comercializar el
producto médico al paciente.

Esto convierte el sistema en una pequeña **marketplace**: la
plataforma (Siscormed) cobra al paciente, el dinero pertenece
fiscalmente al laboratorio, y Siscormed se queda con una comisión por
el servicio de coordinación + cobranza.

Stripe tiene el patrón resuelto con **Stripe Connect**. Tres modelos
posibles:

| Modelo | Quién recibe el cobro inicial | Quién factura al paciente | Pros / Contras |
| ------ | ------ | ------ | ------ |
| **(A) Destination charge con `application_fee_amount`** | El laboratorio (connected account) directo en Stripe. Stripe split-ea: `monto - application_fee` al laboratorio, `application_fee` a Siscormed. | El laboratorio (su factura SRI cubre el monto bruto). Siscormed factura su comisión aparte al laboratorio. | + Cumple naturalmente con "el laboratorio cobra y factura". + Limpio fiscalmente. − Requiere onboarding del laboratorio en Stripe Connect (KYC). |
| **(B) Separate charges (laboratorio dueño)** | El laboratorio directo. Siscormed no toca el monto. | El laboratorio. Siscormed factura comisión post-hoc. | + Aún más limpio fiscalmente. − Siscormed no controla el flujo de checkout; menos UX integrada. |
| **(C) Charge directo a Siscormed + transferencia manual** | Siscormed cobra todo el monto bruto. Después transfiere off-Stripe al laboratorio (transferencia bancaria, ACH). | El laboratorio factura al paciente cuando recibe el monto. Siscormed factura comisión al laboratorio. | + No requiere Stripe Connect ni onboarding del laboratorio. − Siscormed mueve dinero que fiscalmente no es suyo → tratamiento contable delicado, posiblemente requiere registro como agente de retención. |

✅ **DECIDIDO 2026-06-10:** **Modelo A — Destination charge con
`application_fee_amount`**. Connect Express para minimizar fricción de
onboarding del laboratorio.

✅ **Multi-laboratorio:** uno solo al inicio, crece a varios después.
Tratamos esto como multi-tenant desde día 1: tabla `laboratorios` con
`stripe_account_id` propio por cada uno; cada paciente queda asociado
al laboratorio que va a despachar su medicamento via
`pacientes.laboratorio_id`.

✅ **Comisión:** ~10% como referencia inicial pero **variable por
laboratorio**. Vive como `laboratorios.comision_pct` y el endpoint de
checkout la lee al crear la `Session`. Posible iteración futura:
descuentos por volumen / promociones temporales.

⏳ **Pendiente — proveedor de factura SRI del laboratorio:** Pablo no
sabe todavía. Asumimos para el código que el laboratorio decide después.
Lo que sí queda decidido es **cómo le pasamos los datos al
laboratorio post-pago** mientras tanto:

- El webhook `checkout.session.completed` dispara un email server-side
  al `laboratorios.email` (NO al paciente — al lab) con un payload
  estructurado: paciente (cédula/RUC/nombre/dirección/ciudad/email),
  monto bruto, medicamento, dosis, `numero_orden`, fecha pago,
  `stripe_session_id`.
- El lab elige libremente cómo facturar (su emisor SRI, manual, etc.).
- En una iteración futura se le puede dar al lab un panel
  (`siscormed.com/lab.html`) o un webhook a su sistema; por ahora email
  con todo en el body es suficiente.

**Datos del paciente para la factura** (a pasarle al laboratorio post-
pago): cédula / RUC / nombre completo / dirección / ciudad / email,
monto, medicamento, número de orden. Todos están en `pacientes` ya.

## 5. Webhook handler

- Endpoint propuesto: `POST /api/stripe_webhook.php`
- Verificación firma: `Stripe-Signature` header con `whsec_…`
- Eventos a manejar (mínimo):
  - `checkout.session.completed` → marcar `estado_pago = pagado`,
    cambiar `estado` de `aprobado_medico` a (lo que decidamos en #6),
    disparar email de confirmación al paciente, **disparar notificación
    al lab** (esto cierra #4).
  - `checkout.session.expired` → marcar `estado_pago = expirado`,
    revertir al paciente a `aprobado_medico` con link nuevo.
  - `charge.refunded` → marcar `estado_pago = reembolsado`, **NO** revertir
    el estado del flujo automáticamente (decisión humana).
  - Solo si suscripción: `customer.subscription.deleted`,
    `invoice.payment_failed`.
- **Idempotency**: usar `event.id` como key de deduplicación en una
  tabla `stripe_events_seen` para no procesar dos veces.
- **Logging**: cada evento procesado deja una fila en una tabla nueva
  `stripe_events` para auditoría (no en `pacientes_auditoria` que es
  para flujo médico).

## 6. Nueva máquina de estados — APROBADA 2026-06-10

Hoy el flujo es:

```
en_revision_medica → aprobado_medico → lab_pendiente → lab_en_proceso
→ lab_listo → enviado
```

Propuesta para meter el pago de forma limpia y cerrar #4 al mismo tiempo:

```
en_revision_medica
        ↓ (médico aprueba)
aprobado_medico                       ← email con link Stripe al paciente
        ↓ (Stripe webhook: pago OK)
pago_confirmado                       ← email al paciente + email al lab
        ↓ (lab inicia procesamiento manual)
lab_en_proceso
        ↓
lab_listo
        ↓
enviado
```

Cambios concretos:

- Agregar `pago_confirmado` al CHECK constraint de `estado` en MariaDB.
- Quitar `lab_pendiente` (o dejarlo como alias deprecated; el lab recibe
  notificación al entrar a `pago_confirmado`).
- En Make.com: la ruta `LAB_RECIBIDO` cambia su trigger de
  `estado = lab_pendiente` a `estado = pago_confirmado`. Esto cierra #4.
- En admin.html: cuando el médico aprueba, ya no se manda al lab; sólo
  queda en `aprobado_medico` esperando el webhook de Stripe.

## 7. Archivos nuevos esperados

```
api/
├── stripe_checkout.php          # POST: crea Checkout Session, devuelve URL
├── stripe_webhook.php           # POST: Stripe llama aquí
├── pago-recibo.php              # GET ?session_id=… : muestra/genera PDF
└── _stripe.php                  # helpers: cliente PHP, verificación firma

paciente.html (o ampliación de siscormed.html)
                                 # pantalla del paciente con botón Pagar
pago-ok.html                     # success_url, muestra confirmación
pago-cancelado.html              # cancel_url, ofrece reintentar

vendor/                          # composer install stripe/stripe-php
                                 # (vendor/ va al .gitignore)

migrations/
├── 003_laboratorios.sql         # tabla laboratorios + pacientes.laboratorio_id
├── 004_pago_confirmado.sql      # CHECK constraint con el estado nuevo
└── 005_stripe_events.sql        # idempotency + auditoría
```

## 8. Plan de ejecución — Fase A / Fase B

Con todo lo decidido arriba, el trabajo se separa así:

### Fase A — sin depender de cuenta Stripe (ESTA SESIÓN)

1. Migrations DB: `laboratorios`, `pacientes.laboratorio_id`,
   `stripe_events`, CHECK con `pago_confirmado`.
2. `/api/laboratorios.php` — CRUD admin.
3. `/api/stripe_checkout.php` y `/api/stripe_webhook.php` como **stubs**
   que devuelven `503 Service Unavailable` con mensaje
   "Stripe no configurado todavía" hasta que existan las claves. El
   esqueleto completo del flujo está adentro como TODOs (forma del
   payload, eventos a escuchar, transición de estado), listo para
   completarse en Fase B sin tocar el resto.
4. Insert de placeholder del primer laboratorio para que el sistema
   pueda funcionar end-to-end inmediatamente cuando llegue Stripe.

### Fase B — cuando exista cuenta Stripe Connect Express

1. Onboarding del primer laboratorio (Express link → KYC).
2. Crear los `Price` en dashboard, llenar el mapa
   `medicamento_aprobado → price_id` en `config.local.php`.
3. `composer require stripe/stripe-php`, llenar los stubs.
4. Probar el ciclo completo en test mode.
5. Reapuntar la ruta `LAB_RECIBIDO` de Make.com de
   `estado=lab_pendiente` a `estado=pago_confirmado`.
6. Cambiar a live mode.
7. Portal del paciente (`/api/paciente.php` + `paciente.html`) — se
   puede empezar también en Fase B, no es bloqueador para A.
