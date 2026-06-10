<?php
/**
 * Receptor de webhooks de Stripe.
 *
 *   POST /api/stripe_webhook.php
 *     headers: Stripe-Signature (verificada con webhook_secret)
 *     body:    JSON crudo del evento (no parsearlo antes de verificar firma)
 *     200      → procesado / duplicado / ignorado (Stripe deja de reintentar)
 *     4xx      → firma inválida o malformación (Stripe no reintenta)
 *     5xx      → fallo transitorio del backend (Stripe reintenta)
 *     503      → Stripe aún no está configurado
 *
 * Eventos manejados (Fase B):
 *   - checkout.session.completed → marcar estado_pago = pagado, cambiar
 *     estado del paciente a `pago_confirmado`, escribir auditoría,
 *     disparar email al laboratorio con datos del paciente (para que el
 *     lab emita la factura SRI), opcionalmente confirmar al paciente.
 *   - checkout.session.expired   → estado_pago = expirado, paciente
 *     queda en aprobado_medico esperando link nuevo.
 *   - charge.refunded            → estado_pago = reembolsado, NO revertir
 *     el estado del flujo automáticamente (decisión humana).
 *   - account.updated            → si es del laboratorio, actualizar
 *     `laboratorios.stripe_onboarded`.
 *
 * Idempotency: todos los eventos pasan por `stripe_events`. Si el
 * `event.id` ya existe, devolvemos 200 sin reprocessar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * Este archivo es STUB en Fase A. Para activar en Fase B:
 *
 * 1. composer require stripe/stripe-php (vendor/ en .gitignore).
 * 2. Configurar `stripe.webhook_secret` en config.local.php.
 * 3. En el dashboard de Stripe, crear endpoint pointing a
 *    https://siscormed.com/api/stripe_webhook.php y suscribir a los
 *    eventos listados arriba.
 * 4. Implementar handlers marcados con `// TODO Fase B`.
 * ─────────────────────────────────────────────────────────────────────
 */

require __DIR__ . '/_lib.php';
Lib::init();

if (Lib::method() !== 'POST') {
    Lib::fail(405, 'Solo POST');
}

$cfg = Lib::$cfg;
if (!isset($cfg['stripe']) || !isset($cfg['stripe']['webhook_secret'])) {
    // Devolvemos 503 con un cuerpo identificable para que cualquier test
    // accidental (curl manual desde el dashboard) muestre la razón clara.
    Lib::respond(503, [
        'error'  => 'stripe_not_configured',
        'detail' => 'webhook_secret aún no está configurado. Ver docs/stripe-discovery.md (Fase B).',
    ]);
}

// 1) Leer body raw — NO Lib::jsonBody() porque eso consume el stream y
//    perdemos el payload para verificar la firma.
$payload   = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '' || $signature === '') {
    Lib::fail(400, 'Falta payload o Stripe-Signature');
}

// 2) Verificar firma
//
// TODO Fase B:
//
// require __DIR__ . '/../vendor/autoload.php';
// try {
//     $event = \Stripe\Webhook::constructEvent(
//         $payload, $signature, $cfg['stripe']['webhook_secret']
//     );
// } catch (\UnexpectedValueException $e) {
//     Lib::fail(400, 'Invalid payload');
// } catch (\Stripe\Exception\SignatureVerificationException $e) {
//     Lib::fail(400, 'Invalid signature');
// }

// Hasta Fase B, simplemente registramos que llegó y respondemos 503
// (Stripe va a reintentar pero al menos vamos a tener el evento en log
// si algún día se prueba el webhook contra el endpoint).
try {
    $stub = Lib::$db->prepare(
        "INSERT IGNORE INTO stripe_events
            (stripe_event_id, event_type, status, error_msg, raw_payload, received_at)
         VALUES (?, ?, 'error', ?, ?, CURRENT_TIMESTAMP(6))"
    );
    // No tenemos event.id porque no parseamos antes de verificar firma.
    // Usamos un placeholder con timestamp para que la PK no choque.
    $stub->execute([
        'unverified_' . bin2hex(random_bytes(8)),
        'unverified',
        'stripe_not_configured_in_fase_a',
        substr($payload, 0, 65535),  // truncate por seguridad
    ]);
} catch (\Throwable $e) {
    // Si la tabla no existe (ej. Fase A pre-migration), ignoramos para
    // no romper el endpoint entero.
}

Lib::respond(503, ['error' => 'stripe_not_configured']);

// ─────────────────────────────────────────────────────────────────────
// Fase B (lo que va abajo del verify):
//
// $eventId   = $event->id;
// $eventType = $event->type;
//
// // Idempotency: INSERT IGNORE; si ya existe, return 200 sin reprocesar.
// $ins = Lib::$db->prepare(
//     "INSERT IGNORE INTO stripe_events
//          (stripe_event_id, event_type, status, raw_payload, received_at)
//      VALUES (?, ?, 'pending', ?, CURRENT_TIMESTAMP(6))"
// );
// $ins->execute([$eventId, $eventType, $payload]);
// if ($ins->rowCount() === 0) {
//     // Ya estaba — duplicado. No reprocesar.
//     Lib::respond(200, ['ok' => true, 'duplicate' => true]);
// }
//
// $obj = $event->data->object;
// $pacienteId  = (int)($obj->metadata->paciente_id ?? 0);
// $numeroOrden = $obj->metadata->numero_orden ?? null;
//
// try {
//     switch ($eventType) {
//         case 'checkout.session.completed':
//             // 1. Actualizar paciente
//             $upd = Lib::$db->prepare(
//                 "UPDATE pacientes
//                     SET estado_pago = 'pagado',
//                         estado      = 'pago_confirmado'
//                   WHERE id = ?"
//             );
//             $upd->execute([$pacienteId]);
//
//             // 2. Auditar el cambio de estado en pacientes_auditoria
//             // 3. Notificar al laboratorio con datos del paciente (para
//             //    que emita su factura SRI). Usa Lib::fireWebhook al
//             //    /api/notificar.php → ruta nueva en Make.com, o directo
//             //    SMTP al laboratorios.email con el payload estructurado.
//             // 4. (Opcional) confirmar al paciente.
//             break;
//
//         case 'checkout.session.expired':
//             // Marcar estado_pago='expirado'; paciente queda en
//             // aprobado_medico (no cambia estado).
//             break;
//
//         case 'charge.refunded':
//             // estado_pago='reembolsado'; estado del flujo lo decide humano.
//             break;
//
//         case 'account.updated':
//             // Si event.data.object es de un Connect account, ver si
//             // capabilities está activa y actualizar
//             // laboratorios.stripe_onboarded en consecuencia.
//             break;
//
//         default:
//             // Tipos no manejados quedan como 'ignored' en stripe_events
//             $statusFinal = 'ignored';
//             break;
//     }
//     $statusFinal = $statusFinal ?? 'ok';
//
//     Lib::$db->prepare(
//         "UPDATE stripe_events
//             SET status = ?, processed_at = CURRENT_TIMESTAMP(6),
//                 paciente_id = ?, numero_orden = ?
//           WHERE stripe_event_id = ?"
//     )->execute([$statusFinal, $pacienteId ?: null, $numeroOrden, $eventId]);
//
//     Lib::respond(200, ['ok' => true]);
//
// } catch (\Throwable $e) {
//     Lib::$db->prepare(
//         "UPDATE stripe_events
//             SET status = 'error', error_msg = ?, processed_at = CURRENT_TIMESTAMP(6)
//           WHERE stripe_event_id = ?"
//     )->execute([$e->getMessage(), $eventId]);
//     Lib::fail(500, 'Internal error', ['detail' => $e->getMessage()]);
// }
