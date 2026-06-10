# HANDOFF — Siscormed

> Estado de continuidad entre sesiones. Quien retoma lee esto antes de
> abrir cualquier archivo.

---

## Última sesión — 2026-06-10

### Lo que entró a `main`

| Commit | Cambio | Cierra |
| ------ | ------ | ------ |
| `569abcf` | `sec(admin): move password recovery and admin reset to server-side` | Deuda de seguridad en admin.html (tokens CSPRNG, password fuera del payload del webhook) |
| `df7cd34` | `docs(stripe): scope and open questions before implementation` | Discovery doc para módulo de pagos (`docs/stripe-discovery.md`) |

### Cambios en infra (no en repo)

Estos no quedan reflejados en git pero conviene tener presente:

- **PHP-FPM Nasr24 (`192.168.0.116`)** — se habilitó `curl.so` en
  `/usr/syno/etc/packages/WebStation/php_profile/95f7fe6c-…/conf.d/user_settings.ini`.
  Sin esto, `fireWebhook()` en `_lib.php` fallaba con `undefined function
  curl_init()` y los webhooks a Make.com nunca salían.
- **nginx Nasr24** — se movieron los `*.bak` de
  `/usr/local/etc/nginx/sites-enabled/` (del 4-jun y 6-jun, fix-socket y
  telcomag) a `/usr/local/etc/nginx/backups/`. Eran la causa de los
  warnings `conflicting server name` para techtrafo/medicvip/siscormed.
  Regla operativa: nginx Synology incluye `sites-enabled/*` (TODOS los
  archivos, no `*.conf`), nunca dejar backups ahí.
- **DMARC siscormed.com** — `_dmarc.siscormed.com` subió de `p=none` a
  `p=quarantine` vía API GoDaddy. Verificado pass de los 3 (DKIM/SPF/
  DMARC) con email enviado desde mailcow (`notificaciones@siscormed.com`
  → Gmail). DKIM real publicado es `dkim._domainkey.siscormed.com`
  (mailcow, selector `dkim`), no el `mail._domainkey` viejo de MailPlus.

### Estado del flujo en producción

`https://siscormed.com/api/*.php` operativo. Smoke tests verdes:

- `GET /api/pacientes.php` → 200
- `POST /api/auth.php?action=login` con admin/Admin@2025! → 200, hash
  bcrypt verify OK
- `POST /api/admin_recovery.php?action=request` user=admin → 200, token
  de 32 hex chars escrito en DB, NO devuelto al cliente
- `POST /api/admin_recovery.php?action=admin_reset` → 200, nueva
  password bcrypt-hasheada, login con la nueva pass OK, revert OK

---

## Lo que queda pendiente

### Bloqueado por decisión de Pablo

- **#5 Stripe Checkout + factura PDF + webhook** — ver
  [`docs/stripe-discovery.md`](docs/stripe-discovery.md), sección "Lo
  que necesito de ti". Hay 5 preguntas que necesito que respondas
  (entorno test/live, modelo one-shot vs suscripción, flujo del
  paciente, proveedor de factura SRI EC, cambio de máquina de estados).
  Una vez respondidas: 1 sesión de codeo.

- **#4 Notificación lab post-pago** — naturalmente dependiente de #5.
  Cuando exista el estado `pago_confirmado`, en Make.com basta cambiar
  el trigger de la ruta `LAB_RECIBIDO` de `estado = lab_pendiente` a
  `estado = pago_confirmado`. Está propuesto en la sección 6 del
  discovery de Stripe.

### Mejora opcional, no urgente

- **DMARC → `p=reject`** — actualmente en `p=quarantine`. Después de
  monitorear los reportes en `notificaciones@techtrafo.com` durante 1–2
  semanas, si todo se ve limpio, subir a `p=reject` con el mismo PUT
  vía API GoDaddy.

- **Magic-link self-service recovery** — el flujo actual de
  `/api/admin_recovery.php` sigue dependiendo de que un admin haga el
  reset manual con el código del email. Un magic-link firmado quitaría
  ese paso humano y eliminaría la nueva password del log de Make.com.
  Documentado en `project_siscormed_security_debt` (memoria).

- **`generarNumeroOrden` con `Math.random()`** — `admin.html:1357`.
  Los números de orden tipo `MV-260105-X9K2P` no son secretos, así que
  no es security-critical, pero si en algún momento se quiere
  garantizar unicidad mejor cambiar a un INSERT que use AUTO_INCREMENT
  + fecha derivada del row.

---

## Cosas que recordar al retomar

- **Repo local:** `C:\Users\Pablo B\repos\siscormed`
- **Mirror:** `G:\Documentos\compañias\Desarrollos\siscormed\repo-mirror`
  (sincronizar con `git pull --ff-only` después de cada push; reservar
  `reset --hard` para cuando haya divergencia de SHA con contenido
  idéntico)
- **PII fuera de repo:** la carpeta `G:\…\siscormed\` raíz tiene CSVs
  con datos reales de pacientes; nunca commitearlos.
- **NAS (Nasr24, `192.168.0.116`):**
  - SSH `pbaquerizo@…` con `Groundunder8299*` (ASTERISCO)
  - MariaDB root `Groundunder8299$` (DÓLAR)
  - SSH host key fingerprint `SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8`
  - El vhost de siscormed vive en `/usr/local/etc/nginx/sites-enabled/server.vhosts-pablo.conf`, archivo manual fuera del path de Web Station UI.
- **mailcow (`192.168.0.3`):** `pbaquerizo` (= root, sin sudo) con
  `Groundunder8299` (sin sufijo). Cuenta de notificaciones para
  siscormed.com:
  `notificaciones@siscormed.com` / `nL40rOCiQOwL01KCPr`.
- **VM reverse-proxy (`192.168.0.7`):** `pbaquerizo` /
  `Groundunder8299` (sin sufijo). Sites en
  `/etc/nginx/sites-available/netvoice`. **Nunca tocar los server
  blocks de `eneural.org` ni `panel.eneural.org`.**

Para validar que el sitio sigue vivo en cualquier momento:

```
curl -s -o /dev/null -w "%{http_code}\n" https://siscormed.com/
curl -s -o /dev/null -w "%{http_code}\n" https://siscormed.com/api/pacientes.php
```

Ambos deben ser 200.
