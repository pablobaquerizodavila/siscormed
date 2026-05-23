-- Siscormed — schema inicial MariaDB 10
-- Migración desde Supabase (Postgres). Para usar:
--   mysql -u root -p < migrations/001_schema.sql
--
-- Las decisiones de tipos están basadas en los CSVs reales de Supabase y el
-- uso de cada campo en admin.html / prescreener.html / evaluacion.html.
-- Charset utf8mb4 para soportar acentos y emojis correctamente.

CREATE DATABASE IF NOT EXISTS siscormed
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE siscormed;

-- ─────────────────────────────────────────────────────────────────────────
-- pacientes
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE pacientes (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at                  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at                  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  -- Identidad
  nombres                     VARCHAR(100)  NULL,
  apellidos                   VARCHAR(100)  NULL,
  nombre                      VARCHAR(200)  NULL,            -- legado (prescreener escribía aquí)
  nombre_completo             VARCHAR(200)  NULL,
  cedula                      VARCHAR(20)   NULL,
  ruc                         VARCHAR(20)   NULL,
  fecha_nacimiento            DATE          NULL,
  sexo                        VARCHAR(20)   NULL,
  email                       VARCHAR(150)  NULL,
  telefono                    VARCHAR(30)   NULL,

  -- Dirección
  direccion                   TEXT          NULL,
  ciudad                      VARCHAR(100)  NULL,
  sector                      VARCHAR(100)  NULL,
  referencia                  VARCHAR(200)  NULL,
  pais                        VARCHAR(50)   NULL,

  -- Datos clínicos del prescreener
  peso_actual                 DECIMAL(6,2)  NULL,            -- kg
  estatura                    DECIMAL(6,2)  NULL,            -- cm
  peso_meta                   DECIMAL(6,2)  NULL,
  bmi                         DECIMAL(5,2)  NULL,
  presion_arterial            VARCHAR(50)   NULL,
  frecuencia_cardiaca         VARCHAR(50)   NULL,

  -- Condiciones (almacenadas como JSON o texto plano; admin.html las lee como string)
  condiciones                 TEXT          NULL,
  condiciones_grupo1          VARCHAR(200)  NULL,
  condiciones_grupo2          VARCHAR(200)  NULL,
  condiciones_grupo3          VARCHAR(200)  NULL,
  opioides_3meses             VARCHAR(10)   NULL,            -- Sí/No
  cirugia_previa_peso         VARCHAR(10)   NULL,
  toma_medicamentos           VARCHAR(20)   NULL,
  toma_medicamentos_recetados VARCHAR(10)   NULL,
  medicamentos_detalle        TEXT          NULL,
  intentos_previos            TEXT          NULL,
  alergias                    TEXT          NULL,
  nivel_actividad             VARCHAR(50)   NULL,
  habitos                     TEXT          NULL,

  -- Resultado del prescreener
  compatibilidad_pct          TINYINT UNSIGNED NULL,         -- 0-100
  compatibilidad_label        VARCHAR(100)  NULL,            -- "Candidato Perfecto", etc.

  -- Flujo
  estado                      VARCHAR(40)   NOT NULL DEFAULT 'lab_pendiente',
  estado_pago                 VARCHAR(40)   NULL,

  -- Prescripción médica
  medicamento_aprobado        VARCHAR(150)  NULL,
  dosis                       VARCHAR(100)  NULL,
  numero_orden                VARCHAR(50)   NULL,
  notas_medico                TEXT          NULL,

  PRIMARY KEY (id),
  KEY idx_pacientes_estado    (estado),
  KEY idx_pacientes_email     (email),
  KEY idx_pacientes_cedula    (cedula),
  KEY idx_pacientes_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CHECK para los estados conocidos del flujo. MariaDB 10.2+ enforces CHECK.
ALTER TABLE pacientes
  ADD CONSTRAINT chk_pacientes_estado
  CHECK (estado IN (
    'lab_pendiente','lab_en_proceso','lab_listo',
    'en_revision_medica','aprobado_medico','rechazado','enviado'
  ));

-- ─────────────────────────────────────────────────────────────────────────
-- pacientes_auditoria — historial de cambios de estado
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE pacientes_auditoria (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  paciente_id       INT UNSIGNED NOT NULL,
  estado_anterior   VARCHAR(40)  NULL,
  estado_nuevo      VARCHAR(40)  NOT NULL,
  usuario           VARCHAR(100) NOT NULL,
  rol               VARCHAR(30)  NOT NULL,
  created_at        TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (id),
  KEY idx_audit_paciente (paciente_id, created_at),
  CONSTRAINT fk_audit_paciente
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- admin_usuarios — usuarios del panel administrativo
-- En esta migración cambiamos password_hash de plano a bcrypt (60 chars).
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE admin_usuarios (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_completo       VARCHAR(200) NOT NULL,
  email                 VARCHAR(150) NOT NULL,
  username              VARCHAR(50)  NOT NULL,
  password_hash         CHAR(60)     NOT NULL,                -- bcrypt $2y$10$...
  rol                   VARCHAR(30)  NOT NULL,                -- admin | medico | laboratorio

  -- Datos específicos por rol
  numero_licencia       VARCHAR(50)  NULL,
  especialidad          VARCHAR(100) NULL,
  numero_permiso        VARCHAR(50)  NULL,
  nombre_laboratorio    VARCHAR(150) NULL,

  telefono              VARCHAR(30)  NULL,
  estado                VARCHAR(20)  NOT NULL DEFAULT 'pendiente',  -- pendiente | aprobado | rechazado

  email_recuperacion    VARCHAR(150) NULL,
  token_recuperacion    VARCHAR(64)  NULL,
  token_expiry          TIMESTAMP    NULL,

  es_admin_principal    TINYINT(1)   NOT NULL DEFAULT 0,
  creado_por            INT UNSIGNED NULL,

  created_at            TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username),
  UNIQUE KEY uq_admin_email    (email),
  KEY idx_admin_estado         (estado),
  KEY idx_admin_rol            (rol),
  CONSTRAINT fk_admin_creador
    FOREIGN KEY (creado_por) REFERENCES admin_usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE admin_usuarios
  ADD CONSTRAINT chk_admin_rol
  CHECK (rol IN ('admin','medico','laboratorio'));

ALTER TABLE admin_usuarios
  ADD CONSTRAINT chk_admin_estado
  CHECK (estado IN ('pendiente','aprobado','rechazado'));
