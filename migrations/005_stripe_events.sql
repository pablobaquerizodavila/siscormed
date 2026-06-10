-- Siscormed — auditoría e idempotency de eventos Stripe.
--
-- Stripe puede entregar el mismo webhook event varias veces (reintentos
-- ante 5xx, problemas de red). El handler tiene que ser idempotente:
-- mismo `event.id` procesado dos veces ≠ doble actualización del paciente.
--
-- Esta tabla tiene doble función:
--   - Deduplicación: PRIMARY KEY en `stripe_event_id` impide procesar dos
--     veces. El handler hace INSERT IGNORE; si ya existe, sólo responde
--     200 OK sin tocar nada.
--   - Auditoría: queda registro de qué eventos llegaron, en qué orden,
--     con qué resultado. Útil para debug cuando un pago "no aparece" en
--     la UI.
--
-- NO usamos `pacientes_auditoria` para esto — esa tabla es para
-- transiciones de estado del flujo médico (quién cambió a qué). Stripe
-- son eventos externos con semántica distinta.
--
-- Para aplicarla:
--   mysql -u root -p siscormed < migrations/005_stripe_events.sql

USE siscormed;

CREATE TABLE stripe_events (
  -- Stripe event_id viene como 'evt_…' (longitud variable hasta 256)
  stripe_event_id    VARCHAR(64)  NOT NULL,

  -- Tipo del evento: 'checkout.session.completed', 'charge.refunded', etc.
  event_type         VARCHAR(80)  NOT NULL,

  -- Reconciliación con nuestro dominio
  paciente_id        INT UNSIGNED NULL,   -- vía metadata.paciente_id de la sesión
  numero_orden       VARCHAR(50)  NULL,   -- vía metadata.numero_orden

  -- Resultado del procesamiento local
  -- 'ok'        — aplicado sin novedad
  -- 'duplicate' — vino de nuevo, ya estaba
  -- 'ignored'   — tipo de evento que no nos interesa (lo guardamos por audit)
  -- 'error'     — falló algo (ver error_msg)
  status             VARCHAR(16)  NOT NULL,
  error_msg          TEXT         NULL,

  -- Payload completo del evento (JSON crudo de Stripe) por si hay que
  -- revisar manualmente. Limitamos tamaño con MEDIUMTEXT para no
  -- explotar el row size.
  raw_payload        MEDIUMTEXT   NULL,

  received_at        TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  processed_at       TIMESTAMP(6) NULL,

  PRIMARY KEY (stripe_event_id),
  KEY idx_stripe_paciente (paciente_id, received_at),
  KEY idx_stripe_type     (event_type, received_at),
  KEY idx_stripe_status   (status),

  CONSTRAINT fk_stripe_paciente
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
