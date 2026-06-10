<?php
/**
 * Crea una Stripe Checkout Session para una orden aprobada.
 *
 *   POST /api/stripe_checkout.php
 *     body: { "paciente_id": 123 }
 *     200  → { "checkout_url": "https://checkout.stripe.com/c/pay/cs_…" }
 *     400  → bad body
 *     404  → paciente no existe / no está en estado aprobado_medico
 *     409  → la orden ya fue pagada o no tiene laboratorio asignado
 *     503  → Stripe aún no está configurado (fase A — antes de tener cuenta)
 *
 * Modelo: marketplace con Stripe Connect (Destination Charge).
 *   - Cobra al paciente.
 *   - `transfer_data.destination` = `laboratorios.stripe_account_id`.
 *   - `application_fee_amount` = monto × `laboratorios.comision_pct` / 100.
 *   - El paciente ve el cobro como del laboratorio (statement descriptor).
 *
 * ─────────────────────────────────────────────────────────────────────
 * Este archivo es STUB en Fase A. Cuando exista cuenta Stripe:
 *
 * 1. `composer require stripe/stripe-php` desde el repo, agregar
 *    `vendor/` al .gitignore (ya está). Subir `vendor/` al NAS junto
 *    con este archivo.
 * 2. Configurar en api/config.local.php:
 *      'stripe' => [
 *        'secret_key'      => 'sk_test_…' (o sk_live_…),
 *        'webhook_secret'  => 'whsec_…',
 *        'price_id_map'    => [
 *          'Semaglutida 0.25mg (inicio)' => 'price_…',
 *          'Semaglutida 0.5mg'           => 'price_…',
 *          'Semaglutida 1mg'             => 'price_…',
 *          'Tirzepatida 2.5mg'           => 'price_…',
 *          'Tirzepatida 5mg'             => 'price_…',
 *          'Tirzepatida 10mg'            => 'price_…',
 *        ],
 *        'shipping_options' => [
 *          // ej. una tarifa fija nacional EC, en cents USD
 *          ['shipping_rate' => 'shr_…'],
 *        ],
 *      ],
 * 3. Borrar la rama del `if (!isset($cfg['stripe']))` de abajo.
 * 4. Implementar las secciones marcadas con `// TODO Fase B`.
 * ─────────────────────────────────────────────────────────────────────
 */

require __DIR__ . '/_lib.php';
Lib::init();

if (Lib::method() !== 'POST') {
    Lib::fail(405, 'Solo POST');
}

$cfg = Lib::$cfg;
if (!isset($cfg['stripe']) || !isset($cfg['stripe']['secret_key'])) {
    Lib::respond(503, [
        'error'  => 'stripe_not_configured',
        'detail' => 'Stripe aún no está configurado. Ver docs/stripe-discovery.md (Fase B).',
    ]);
}

$body       = Lib::jsonBody();
$pacienteId = (int)($body['paciente_id'] ?? 0);
if ($pacienteId <= 0) {
    Lib::fail(400, 'paciente_id requerido');
}

// Recuperar paciente + laboratorio asociado en una sola query
$stmt = Lib::$db->prepare(
    "SELECT p.id, p.email, p.nombre_completo, p.cedula, p.ruc,
            p.ciudad, p.estado, p.estado_pago,
            p.medicamento_aprobado, p.numero_orden,
            l.id AS lab_id, l.stripe_account_id, l.comision_pct,
            l.stripe_onboarded, l.activo AS lab_activo
       FROM pacientes p
       LEFT JOIN laboratorios l ON l.id = p.laboratorio_id
      WHERE p.id = ?"
);
$stmt->execute([$pacienteId]);
$paciente = $stmt->fetch();

if (!$paciente) {
    Lib::fail(404, 'Paciente no encontrado');
}
if ($paciente['estado'] !== 'aprobado_medico') {
    Lib::fail(409, 'La orden no está aprobada por el médico (estado=' . $paciente['estado'] . ')');
}
if ($paciente['estado_pago'] === 'pagado' || $paciente['estado_pago'] === 'pago_confirmado') {
    Lib::fail(409, 'La orden ya fue pagada');
}
if (!$paciente['lab_id']) {
    Lib::fail(409, 'La orden no tiene laboratorio asignado');
}
if (!$paciente['stripe_account_id'] || !$paciente['stripe_onboarded'] || !$paciente['lab_activo']) {
    Lib::fail(409, 'El laboratorio no tiene Stripe Connect activo');
}

$priceId = $cfg['stripe']['price_id_map'][$paciente['medicamento_aprobado']] ?? null;
if (!$priceId) {
    Lib::fail(409, 'No hay price_id mapeado para: ' . $paciente['medicamento_aprobado']);
}

// TODO Fase B: armar y crear la Checkout Session
//
// \Stripe\Stripe::setApiKey($cfg['stripe']['secret_key']);
// $session = \Stripe\Checkout\Session::create([
//     'mode'                => 'payment',
//     'line_items'          => [[ 'price' => $priceId, 'quantity' => 1 ]],
//     'shipping_options'    => $cfg['stripe']['shipping_options'] ?? [],
//     'customer_email'      => $paciente['email'],
//     'success_url'         => 'https://siscormed.com/pago-ok.html?session_id={CHECKOUT_SESSION_ID}',
//     'cancel_url'          => 'https://siscormed.com/pago-cancelado.html?numero_orden=' . urlencode($paciente['numero_orden']),
//     'metadata'            => [
//         'paciente_id'  => (string)$paciente['id'],
//         'numero_orden' => $paciente['numero_orden'],
//         'laboratorio_id' => (string)$paciente['lab_id'],
//     ],
//     'payment_intent_data' => [
//         // Destination charge — el laboratorio cobra, Siscormed se queda
//         // con application_fee_amount (en cents).
//         'application_fee_amount' => (int) round($AMOUNT_CENTS * ((float)$paciente['comision_pct'] / 100)),
//         'transfer_data' => [
//             'destination' => $paciente['stripe_account_id'],
//         ],
//         'metadata' => [
//             'paciente_id' => (string)$paciente['id'],
//         ],
//     ],
// ]);
//
// Lib::respond(200, [
//     'checkout_url' => $session->url,
//     'session_id'   => $session->id,
// ]);

// Por ahora (no debería llegar aquí — el 503 de arriba intercepta):
Lib::respond(503, ['error' => 'not_implemented']);
