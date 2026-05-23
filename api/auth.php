<?php
/**
 * Auth endpoint — único punto donde se verifica un password.
 *
 * POST /api/auth.php?action=login
 *   body: { "user": "username_o_email", "pass": "..." }
 *   200  → row de admin_usuarios (sin secretos)
 *   401  → credenciales inválidas
 *   403  → cuenta pendiente / rechazada / suspendida
 *
 * No mantenemos sesión server-side — admin.html ya guarda el user en
 * sessionStorage tras el login. La autorización por rol sigue siendo
 * responsabilidad del cliente (igual que antes con Supabase).
 *
 * TODO técnico aparte: cuando el panel reciba más uso, mover la autorización
 * al server con tokens firmados (JWT corto + cookie HttpOnly). Por ahora
 * mantenemos paridad funcional con Supabase para no romper el flujo.
 */

require __DIR__ . '/_lib.php';
Lib::init();

if (Lib::method() !== 'POST') {
    Lib::fail(405, 'Solo POST');
}

$action = $_GET['action'] ?? '';
if ($action !== 'login') {
    Lib::fail(400, "Acción desconocida: '{$action}'");
}

$body = Lib::jsonBody();
$user = trim((string)($body['user'] ?? ''));
$pass = (string)($body['pass'] ?? '');

if ($user === '' || $pass === '') {
    Lib::fail(400, 'Faltan user/pass');
}

// Look up por username o email
$stmt = Lib::$db->prepare(
    "SELECT * FROM admin_usuarios WHERE username = ? OR email = ? LIMIT 1"
);
$stmt->execute([$user, $user]);
$row = $stmt->fetch();

if (!$row) {
    Lib::fail(401, 'Usuario o contraseña incorrectos');
}

if (!password_verify($pass, $row['password_hash'])) {
    Lib::fail(401, 'Usuario o contraseña incorrectos');
}

// Estado de la cuenta — distinguimos los casos para que admin.html muestre
// el mensaje correcto (pendiente vs rechazado/suspendido)
if (in_array($row['estado'], ['pendiente', 'rechazado', 'suspendido'], true)) {
    Lib::respond(403, [
        'error'  => 'cuenta_no_activa',
        'estado' => $row['estado'],
        'nombre_completo' => $row['nombre_completo'],
        'rol' => $row['rol'],
    ]);
}

// Si la lib de PHP determinó que el hash debería re-hashearse (cost actualizado),
// lo regeneramos de forma transparente en este mismo login
if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
    $newHash = password_hash($pass, PASSWORD_BCRYPT);
    $u = Lib::$db->prepare("UPDATE admin_usuarios SET password_hash = ? WHERE id = ?");
    $u->execute([$newHash, $row['id']]);
}

// Sanear antes de devolver
unset($row['password_hash'], $row['token_recuperacion']);
Lib::respond(200, $row);
