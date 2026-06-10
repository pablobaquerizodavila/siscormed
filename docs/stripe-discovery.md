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

⏳ **Pendiente Pablo:** decidir modelo A/B/C. Recomendación: **(A)** si
hay un solo laboratorio o pocos, **Connect Custom** o **Express** para
manejar el onboarding. **(C)** sólo si el laboratorio se niega a tener
Stripe.

⏳ **Datos a tener listos:**
- Razón social, RUC y datos bancarios del laboratorio
- ¿Es un solo laboratorio o varios? (afecta si hay que mantener una
  tabla `laboratorios` con `stripe_account_id` por cada uno)
- ¿Existe contrato firmado de agente de recaudación entre Siscormed y el
  laboratorio? (necesario fiscalmente; afecta también qué % cobra
  Siscormed)
- Si la factura SRI sale del laboratorio, ¿el laboratorio ya tiene su
  emisor electrónico funcionando? ¿Cómo le pasamos los datos del
  paciente para que emita: email automático con datos al laboratorio,
  webhook a un endpoint del laboratorio, panel manual donde el lab vea
  los pedidos pagados?

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

## 6. Nueva máquina de estados — propuesta

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
├── 003_stripe_events.sql        # tablas stripe_events_seen + stripe_events
└── 004_estado_pago_confirmado.sql # CHECK constraint actualizado
```

## 8. Lo que necesito de ti

Para arrancar, las respuestas mínimas son:

1. ¿Test mode o live mode? Si test, dame `sk_test_…` y `pk_test_…`.
2. ¿One-shot u suscripción?
3. ¿Cómo entrega el paciente el pago: email-link, portal, ambos?
4. ¿Tienes proveedor de factura electrónica EC ya, o vamos fase-1
   (PDF simple) por ahora?
5. ¿Apruebas el cambio de máquina de estados de la sección 6?

Con esas 5 respuestas puedo armar un plan de implementación pasable a
sesión de codeo de un solo tirón.
