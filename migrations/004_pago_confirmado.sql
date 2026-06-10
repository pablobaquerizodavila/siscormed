-- Siscormed — agregar `pago_confirmado` al CHECK de `pacientes.estado`.
--
-- Máquina de estados nueva (aprobada 2026-06-10):
--
--   en_revision_medica
--     ↓ médico aprueba
--   aprobado_medico
--     ↓ paciente paga (Stripe Checkout) → webhook checkout.session.completed
--   pago_confirmado    ← NUEVO
--     ↓ lab inicia procesamiento (manual o cuando reciba el email post-pago)
--   lab_en_proceso
--     ↓
--   lab_listo
--     ↓
--   enviado
--
-- `lab_pendiente` se mantiene en el CHECK por compatibilidad con casos
-- históricos pero la app nueva no debería volver a poner pacientes ahí —
-- la ruta correcta post-aprobación es `aprobado_medico → pago_confirmado`.
-- En Make.com, la ruta `LAB_RECIBIDO` cambia su trigger de
-- `estado = lab_pendiente` a `estado = pago_confirmado` cuando se haga
-- el cambio en el dashboard (cierra el pendiente #4 del session log).
--
-- Para aplicarla:
--   mysql -u root -p siscormed < migrations/004_pago_confirmado.sql

USE siscormed;

-- MariaDB no permite "ALTER CHECK" directamente; hay que dropear y
-- volver a crear el constraint con la lista nueva.
ALTER TABLE pacientes DROP CONSTRAINT chk_pacientes_estado;

ALTER TABLE pacientes
  ADD CONSTRAINT chk_pacientes_estado
  CHECK (estado IN (
    'lab_pendiente',       -- deprecated, mantener por compatibilidad
    'lab_en_proceso',
    'lab_listo',
    'en_revision_medica',
    'aprobado_medico',
    'pago_confirmado',     -- ← NUEVO
    'rechazado',
    'enviado'
  ));
