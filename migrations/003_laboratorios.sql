-- Siscormed — multi-laboratorio (marketplace via Stripe Connect)
--
-- Siscormed actúa como agente de recaudación. Cada orden de paciente la
-- factura el laboratorio que despacha el medicamento (no Siscormed).
-- Stripe Connect Destination Charge se encarga de partir el monto:
-- `monto - application_fee` se queda con el laboratorio (Connect account),
-- `application_fee` viene a Siscormed como comisión por el servicio.
--
-- Esta migration agrega:
--   1. Tabla `laboratorios` — uno al inicio, varios después.
--   2. FK `pacientes.laboratorio_id` — qué laboratorio despacha cada caso.
--
-- Para aplicarla:
--   mysql -u root -p siscormed < migrations/003_laboratorios.sql

USE siscormed;

-- ─────────────────────────────────────────────────────────────────────────
-- laboratorios
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE laboratorios (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Identidad y fiscalidad
  nombre               VARCHAR(200) NOT NULL,
  razon_social         VARCHAR(200) NULL,        -- legal vs comercial
  ruc                  VARCHAR(20)  NULL,        -- 13 dígitos en EC
  email                VARCHAR(150) NOT NULL,    -- destino del email post-pago con datos del paciente
  telefono             VARCHAR(30)  NULL,
  direccion            TEXT         NULL,
  ciudad               VARCHAR(100) NULL,

  -- Económico
  comision_pct         DECIMAL(5,2) NOT NULL DEFAULT 10.00,  -- % que se queda Siscormed (application_fee)
                                                              -- variable por laboratorio según contrato

  -- Stripe Connect
  stripe_account_id    VARCHAR(50)  NULL,        -- 'acct_…' cuando se complete onboarding Connect Express
  stripe_onboarded     TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 cuando Stripe confirma capabilities activas

  -- Operativo
  activo               TINYINT(1)   NOT NULL DEFAULT 1,  -- soft-delete; el admin desactiva en vez de borrar
  notas                TEXT         NULL,

  created_at           TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (id),
  UNIQUE KEY uq_lab_ruc (ruc),
  UNIQUE KEY uq_lab_email (email),
  KEY idx_lab_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La comisión debe ser un porcentaje razonable (0–100). Bloqueamos valores
-- absurdos en el constraint para no depender solo de validación PHP.
ALTER TABLE laboratorios
  ADD CONSTRAINT chk_lab_comision_pct
  CHECK (comision_pct >= 0 AND comision_pct <= 100);

-- ─────────────────────────────────────────────────────────────────────────
-- pacientes.laboratorio_id — FK al laboratorio que despacha
-- ─────────────────────────────────────────────────────────────────────────
-- Nullable porque pacientes ya existentes no tienen laboratorio asignado
-- todavía. Cuando el médico aprueba la orden y selecciona medicamento,
-- también selecciona laboratorio (o se asigna por default al único activo
-- si hay uno solo).
ALTER TABLE pacientes
  ADD COLUMN laboratorio_id INT UNSIGNED NULL AFTER dosis,
  ADD KEY idx_pacientes_laboratorio (laboratorio_id),
  ADD CONSTRAINT fk_pacientes_laboratorio
    FOREIGN KEY (laboratorio_id) REFERENCES laboratorios(id) ON DELETE SET NULL;
