# Siscormed — Plataforma de Tratamiento GLP-1 Personalizado

Plataforma web completa para la gestión de pacientes candidatos a tratamiento GLP-1, incluyendo pre-evaluación, revisión de laboratorio, aprobación médica y despacho de medicamentos.

---

## 🌐 Producción

| Recurso       | URL / Detalle                              |
| ------------- | ------------------------------------------ |
| Sitio web     | https://siscormed.com                      |
| Panel admin   | https://siscormed.com/admin.html           |
| Base de datos | Supabase (`wwskicwdqbufrpuarmzk`)          |
| Automatización| Make.com                                   |
| Servidor      | Synology NAS1821 (red interna)             |

---

## 📁 Estructura del proyecto

```
siscormed/
├── index.html             # Landing page principal
├── prescreener.html       # Pre-evaluación del paciente (paso 1)
├── datos-personales.html  # Formulario de datos personales (paso 2)
├── evaluacion.html        # Evaluación médica completa (paso 3)
├── siscormed.html         # Dashboard / vista del paciente
└── admin.html             # Panel de administración médica
```

---

## 🔄 Flujo del sistema

```
Paciente completa prescreener
        ↓
Ingresa datos personales
        ↓
Completa evaluación médica
        ↓
Webhook → Make.com → Notificación al laboratorio
        ↓
Laboratorio revisa (lab_pendiente → lab_en_proceso → lab_listo)
        ↓
Médico revisa (en_revision_medica)
        ↓
    ┌───┴───┐
Aprobado  Rechazado
    ↓         ↓
  Email     Email
 paciente  paciente
    ↓
[PENDIENTE] Pago via Stripe
    ↓
Medicamento despachado (enviado)
    ↓
Email al paciente con tracking
```

---

## 📊 Estados del paciente (`campo: estado`)

| Estado                | Descripción                       |
| --------------------- | --------------------------------- |
| `lab_pendiente`       | Caso enviado al laboratorio       |
| `lab_en_proceso`      | Laboratorio procesando            |
| `lab_listo`           | Laboratorio terminó revisión      |
| `en_revision_medica`  | Médico revisando                  |
| `aprobado_medico`     | Caso aprobado por médico          |
| `rechazado`           | Caso rechazado                    |
| `enviado`             | Medicamento despachado            |

---

## 🗄️ Base de datos (Supabase)

### Tablas principales

- **`pacientes`** — Datos completos del paciente
  - Información personal (nombre, email, cédula, dirección)
  - Datos médicos (condiciones, alergias, medicamentos)
  - Resultados del prescreener (`compatibilidad_pct`, `compatibilidad_label`)
  - Estado del flujo (`estado`, `estado_pago`)
  - Prescripción (`medicamento_aprobado`, `dosis`, `notas_medico`)
  - Número de orden
- **`pacientes_auditoria`** — Historial de cambios de estado
  - `estado_anterior`, `estado_nuevo`
  - `usuario`, `rol`, `created_at`
- **`admin_usuarios`** — Usuarios del panel administrativo
  - Roles: `medico`, `laboratorio`, `admin`
  - Autenticación propia (username + password_hash)
  - Sistema de recuperación de contraseña por token

---

## 📧 Notificaciones automáticas (Make.com)

Sistema de 4 rutas activadas por webhook de Supabase:

| Ruta                | Trigger                       | Destinatario                  | Remitente                       |
| ------------------- | ----------------------------- | ----------------------------- | ------------------------------- |
| Caso Aprobado       | `estado = aprobado_medico`    | Paciente                      | notificaciones@siscormed.com    |
| Caso Rechazado      | `estado = rechazado`          | Paciente                      | notificaciones@siscormed.com    |
| Lab Recibido        | `estado = lab_pendiente`      | laboratorio@siscormed.com     | notificaciones@siscormed.com    |
| Medicamento Enviado | `estado = enviado`            | Paciente                      | notificaciones@siscormed.com    |

### Servidor de correo

- **Proveedor:** Synology MailPlus Server
- **Dominio:** siscormed.com
- **SMTP:** `mail.siscormed.com:465` (TLS)
- **Cuenta:** `notificaciones@siscormed.com`

---

## 🖥️ Infraestructura

### Synology NAS1821

- **Red:** interna (LAN)
- **Web Station:** Nginx — sitio estático en `/web/siscormed`
- **MailPlus Server:** SMTP/IMAP activo para `siscormed.com`
- **Certificados SSL:** Let's Encrypt para `siscormed.com` y `mail.siscormed.com`

### DNS (GoDaddy)

| Tipo  | Nombre | Valor                              |
| ----- | ------ | ---------------------------------- |
| A     | @      | (IP pública del NAS)               |
| A     | mail   | (IP pública del NAS)               |
| CNAME | www    | siscormed.com                      |
| MX    | @      | `mail.siscormed.com` (prioridad 10)|
| TXT   | @      | `v=spf1 ip4:<IP-pública> ~all`     |

---

## 🔐 Panel de administración

Acceso en: `https://siscormed.com/admin.html`

### Roles disponibles

- **`admin`** — Acceso completo, gestión de usuarios
- **`medico`** — Aprobación/rechazo de casos
- **`laboratorio`** — Gestión de resultados de lab

### Funcionalidades

- Vista de todos los pacientes con filtros por estado
- Aprobación/rechazo de casos con notas médicas
- Prescripción de medicamentos y dosis
- Reversión de estados
- Gestión de usuarios admin
- Recuperación de contraseña por email

---

## 🚧 Pendiente de desarrollo

- [ ] **Módulo de pago** — Stripe Checkout con precio dinámico por medicamento
- [ ] **Facturación electrónica** — Comprobante PDF post-pago
- [ ] **Confirmación de pago** — Webhook Stripe → Supabase → Make.com
- [ ] **Notificación al lab** — Solo después de confirmación de pago
- [ ] **SPF/DKIM/DMARC** — Configuración completa anti-spam
- [ ] **`www` redirect** — Redirección `www.siscormed.com` → `siscormed.com`

---

## 📅 Historial de versiones

### v1.0.0 — Mayo 2026
- Lanzamiento inicial con nombre MedicVIP
- Flujo completo de pre-evaluación a despacho
- Panel admin con roles
- Notificaciones Make.com con Outlook (deprecado)

### v1.1.0 — Mayo 2026
- Cambio de marca: MedicVIP → Siscormed
- Migración email: Outlook → Synology MailPlus
- Despliegue en Synology NAS con dominio propio
- Certificados SSL Let's Encrypt
- Nuevo dominio: `siscormed.com`

---

## 👤 Autor

Pablo Baquerizo — pbaquerizo@siscormed.com

---

© 2026 Siscormed. Todos los derechos reservados.
