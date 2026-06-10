<?php
/**
 * Admin password recovery — single endpoint for two flows.
 *
 *   POST /api/admin_recovery.php?action=request
 *     body: { "user": "username | email | email_recuperacion" }
 *     200  → { "ok": true } (always succeeds if user exists; no echo of token)
 *     404  → { "error": "no_account" }
 *
 *     Generates a 32-hex-char (128-bit) crypto-safe token, stores it on the
 *     user row with a 1-hour expiry, and fires the RECUPERAR_PASSWORD webhook.
 *     The token NEVER comes back to the client — it only travels to Make.com
 *     and from there to the recovery email.
 *
 *   POST /api/admin_recovery.php?action=admin_reset
 *     body: { "admin_username": "...", "target_id": 123, "new_password": "..." }
 *     200  → { "ok": true }
 *     400  → bad body
 *     404  → target not found
 *
 *     Hashes the new password with bcrypt, writes it to admin_usuarios,
 *     clears the recovery token, and fires PASSWORD_RESETEADO with a
 *     server-built `mensaje`. The new password is NOT sent as a separate
 *     field — only inside `mensaje`, which is what the recipient email
 *     uses. (A future iteration should switch to magic-link reset so the
 *     password never travels through Make.com at all.)
 *
 * Notes on what this fixes:
 *   - Old client used Math.random().toString(36) — 40 bits, predictable.
 *   - Old client sent `nueva_password: newPass` as a separate field in
 *     the webhook payload, exposing it twice in Make.com logs.
 */

require __DIR__ . '/_lib.php';
Lib::init();

if (Lib::method() !== 'POST') {
    Lib::fail(405, 'Solo POST');
}

$action = $_GET['action'] ?? '';

if ($action === 'request') {
    handleRequest();
} elseif ($action === 'admin_reset') {
    handleAdminReset();
} else {
    Lib::fail(400, "Acción desconocida: '{$action}'");
}

// ─────────────────────────────────────────────────────────────────────────

function handleRequest(): void
{
    $body  = Lib::jsonBody();
    $input = trim((string)($body['user'] ?? ''));
    if ($input === '') {
        Lib::fail(400, 'Falta el campo `user`');
    }

    // Match por username, email o email_recuperacion (3 placeholders distintos:
    // PDO con EMULATE_PREPARES=false no permite reusar un :named).
    $stmt = Lib::$db->prepare(
        "SELECT id, nombre_completo, email, email_recuperacion, username
         FROM admin_usuarios
         WHERE username = ? OR email = ? OR email_recuperacion = ?
         LIMIT 1"
    );
    $stmt->execute([$input, $input, $input]);
    $user = $stmt->fetch();

    if (!$user) {
        Lib::fail(404, 'no_account');
    }

    // 32 hex chars = 128 bits desde el CSPRNG de PHP
    $token  = bin2hex(random_bytes(16));
    $expiry = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    $upd = Lib::$db->prepare(
        "UPDATE admin_usuarios
            SET token_recuperacion = ?, token_expiry = ?
          WHERE id = ?"
    );
    $upd->execute([$token, $expiry, $user['id']]);

    // El destinatario REAL del email — preferir email_recuperacion para que
    // un atacante con la cuenta principal comprometida no pueda interceptar.
    $email = $user['email_recuperacion'] ?: $user['email'];

    Lib::fireWebhook([
        'evento'           => 'RECUPERAR_PASSWORD',
        'nombre_completo'  => $user['nombre_completo'],
        'email'            => $email,
        'username'         => $user['username'],
        // El token va sólo en `mensaje` para no duplicarlo como key
        // estructurada; Make.com debe extraerlo del cuerpo si lo necesita
        // para construir un link de reset.
        'mensaje'          => "Tu código de recuperación temporal es: {$token}. Válido por 1 hora. "
                            . "Contacta al administrador con este código para que restablezca tu contraseña.",
        'fecha'            => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);

    // No devolvemos el token al cliente — solo confirmación
    Lib::respond(200, ['ok' => true]);
}

function handleAdminReset(): void
{
    $body          = Lib::jsonBody();
    $adminUsername = trim((string)($body['admin_username'] ?? ''));
    $targetId      = (int)($body['target_id'] ?? 0);
    $newPassword   = (string)($body['new_password'] ?? '');

    if ($adminUsername === '' || $targetId <= 0 || $newPassword === '') {
        Lib::fail(400, 'Faltan campos requeridos (admin_username, target_id, new_password)');
    }
    if (strlen($newPassword) < 8) {
        Lib::fail(400, 'La contraseña debe tener al menos 8 caracteres');
    }

    // Buscar al target
    $stmt = Lib::$db->prepare(
        "SELECT id, nombre_completo, email, email_recuperacion, username
         FROM admin_usuarios WHERE id = ?"
    );
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        Lib::fail(404, 'Target user no encontrado');
    }

    // Hashear y guardar; limpia token si tenía uno pendiente
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd  = Lib::$db->prepare(
        "UPDATE admin_usuarios
            SET password_hash = ?, token_recuperacion = NULL, token_expiry = NULL
          WHERE id = ?"
    );
    $upd->execute([$hash, $targetId]);

    $email = $target['email_recuperacion'] ?: $target['email'];

    Lib::fireWebhook([
        'evento'          => 'PASSWORD_RESETEADO',
        'nombre_completo' => $target['nombre_completo'],
        'email'           => $email,
        'username'        => $target['username'],
        'admin'           => $adminUsername,
        // La nueva password viaja una sola vez, dentro de `mensaje`.
        // No la repetimos como campo separado para reducir su superficie
        // de exposición en logs de Make.com.
        'mensaje'         => "El administrador ha restablecido tu contraseña. "
                           . "Tu nueva contraseña temporal es: {$newPassword}. "
                           . "Por favor cámbiala después de ingresar.",
        'fecha'           => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);

    Lib::respond(200, ['ok' => true]);
}
