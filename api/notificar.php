<?php
/**
 * Proxy server-side al webhook de Make.com.
 *
 * Antes el URL estaba embebido en admin.html (público — cualquiera podía
 * disparar el escenario). Ahora el cliente sólo conoce /api/notificar.php
 * y el URL real vive en config.local.php.
 *
 * No bloqueamos el flujo si Make.com está caído: respondemos 202 (accepted)
 * y dejamos que el webhook falle en background sin afectar al usuario.
 */

require __DIR__ . '/_lib.php';
Lib::init();

if (Lib::method() !== 'POST') {
    Lib::fail(405, 'Solo POST');
}

$body = Lib::jsonBody();
if (empty($body['evento'])) {
    Lib::fail(400, 'Falta campo `evento`');
}

Lib::fireWebhook($body);
http_response_code(202);
exit;
