# Stripe Checkout + factura — discovery

Este documento captura las decisiones que necesito de Pablo antes de
implementar el módulo de pagos. Una vez decididas, el código sale más o
menos directo. Hasta entonces, no inicio implementación para no construir
sobre supuestos equivocados.

---

## 1. Cuenta Stripe y entorno

- [ ] ¿Ya hay cuenta Stripe activa para Siscormed (o se reutiliza otra)?
- [ ] ¿En qué país está dada de alta? (afecta métodos de pago disponibles
      y soporte de Ecuador)
- [ ] ¿Empezamos en **test mode** (claves `sk_test_…` / `pk_test_…`) y
      después promovemos a live, o vamos directo a live con un primer
      caso real chico?
- [ ] Webhook secret (`whsec_…`) lo configuras tú en Stripe Dashboard una
      vez tengamos el endpoint; lo guardo en `config.local.php`.

## 2. Modelo de cobro

GLP-1 típicamente se cobra mensual (la dosis es semanal pero el envío y
la suscripción suelen ser mensuales).

- [ ] **Modalidad**:
  - (a) **One-shot por orden**: cada `numero_orden` aprobado por el médico
        se cobra una vez; si el paciente sigue tratamiento se le crea otra
        orden el mes siguiente. Más simple, sin Stripe Customer Portal.
  - (b) **Suscripción mensual**: Stripe crea un `customer` y un
        `subscription`. Cobra automáticamente. Requiere `customer_portal`
        para que el paciente pause/cancele. Más complejo pero menos
        fricción para el paciente recurrente.
  - (c) **Híbrido**: primer mes one-shot (consulta + primer envío),
        después suscripción si el paciente acepta seguir.
- [ ] **Precio**:
  - Lista actual de medicamentos: Semaglutida 0.25mg / 0.5mg / 1mg,
    Tirzepatida 2.5mg / 5mg / 10mg.
  - ¿El precio se define en Stripe Dashboard (Stripe `Price` por
    medicamento) y `numero_orden` referencia el `price_id`, o se calcula
    server-side y se manda como `unit_amount` ad-hoc al crear la sesión?
    Lo primero es más limpio, lo segundo es más flexible si hay
    promociones / descuentos.
- [ ] **Moneda**: USD por defecto en Ecuador. ¿OK?
- [ ] **Costo de envío** dentro de Ecuador: ¿incluido en el precio o se
      cobra aparte como `shipping_options`?

## 3. Flujo de pago para el paciente

- [ ] **Disparador**:
  - (a) Cuando el médico aprueba (`estado = aprobado_medico`),
        Make.com envía email al paciente con link de Stripe Checkout.
  - (b) Cuando el médico aprueba, el paciente entra a
        `siscormed.com/paciente.html?email=…` (o usa `siscormed.html`
        existente con su cédula), ve el estado y un botón "Pagar ahora"
        que abre Checkout.
  - (c) Ambas (email con link + portal).
- [ ] **Datos de Stripe Checkout**:
  - prefill `customer_email` con `pacientes.email` ✓
  - `success_url` → `siscormed.com/pago-ok.html?session_id={CHECKOUT_SESSION_ID}`
  - `cancel_url` → `siscormed.com/pago-cancelado.html?numero_orden=…`
  - `metadata.paciente_id` para reconciliar en webhook
  - `metadata.numero_orden`
- [ ] **Recibo de Stripe**: Stripe ofrece email de recibo automático.
      ¿Lo dejamos prendido (recibo legible inmediato) o lo apagamos y
      mandamos sólo nuestra factura SRI?

## 4. Factura electrónica Ecuador (SRI)

Ecuador exige facturación electrónica con autorización SRI: clave de
acceso de 49 dígitos, XML firmado con certificado del contribuyente,
estado autorizado, formato RIDE PDF.

- [ ] **¿Quién emite las facturas Siscormed hoy?**
  - (a) Pablo / la empresa tiene su propio proveedor de facturación
        electrónica (FactuPro, Defontana, ContiFico, FACTURAEC, etc.).
        En ese caso integramos webhook → API del proveedor con datos del
        paciente + monto + medicamento.
  - (b) Aún no se emiten facturas formales y queremos arrancar con un PDF
        "recibo" simple (sin clave de acceso SRI) hasta tener el flujo de
        SRI. Esto puede generar problemas legales/tributarios si los
        montos pasan ciertos umbrales.
- [ ] **Datos fiscales del cliente**:
  - Cédula la tenemos en `pacientes.cedula`.
  - RUC opcional en `pacientes.ruc` (si la persona compra a nombre de
    empresa).
  - Nombre completo, dirección, ciudad, email. Todos presentes.
- [ ] **Datos fiscales del emisor** (Siscormed):
  - Razón social, RUC, dirección, número de establecimiento, punto de
    emisión, secuencial. ¿Estos están definidos / dónde se guardan?
- [ ] **Plan tentativo**:
  - **Fase 1 (rápida)**: post-pago, Make.com manda al paciente un email
    con resumen + recibo PDF generado server-side con `dompdf` o
    `mPDF` (sin clave SRI). NO es factura tributaria pero deja
    comprobante al paciente.
  - **Fase 2 (formal)**: integración con proveedor de facturación
    electrónica EC; el webhook llama al proveedor, recibe clave de acceso
    y PDF autorizado, lo adjunta al email al paciente.

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
