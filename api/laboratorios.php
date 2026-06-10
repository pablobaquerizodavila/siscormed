<?php
/**
 * Laboratorios — CRUD para el panel de administración.
 *
 * Esta tabla manda quién despacha cada orden de paciente y, vía Stripe
 * Connect, dónde aterriza el dinero del cobro (`stripe_account_id`)
 * minus la comisión de Siscormed (`comision_pct`).
 *
 * Sigue el mismo patrón que el resto de los endpoints (admin_usuarios,
 * pacientes): query string PostgREST-like, whitelist de columnas, sin
 * auth server-side todavía (la autorización del panel sigue siendo
 * responsabilidad del cliente — TODO conjunto con la deuda de
 * auth.php).
 */

require __DIR__ . '/_lib.php';
Lib::init();

const TABLE = 'laboratorios';

const COLS_WRITABLE = [
    'nombre', 'razon_social', 'ruc', 'email', 'telefono', 'direccion', 'ciudad',
    'comision_pct',
    'stripe_account_id', 'stripe_onboarded',
    'activo', 'notas',
];

const COLS_READABLE = ['id', 'created_at', 'updated_at', ...COLS_WRITABLE];

switch (Lib::method()) {
    case 'GET': {
        $q = Lib::parseQuery(COLS_READABLE);
        $sql = "SELECT {$q['select']} FROM `" . TABLE . "`{$q['where']}{$q['order']}";
        $stmt = Lib::$db->prepare($sql);
        $stmt->execute($q['params']);
        Lib::respond(200, $stmt->fetchAll());
        break;
    }

    case 'POST': {
        $body = Lib::jsonBody();
        if (empty($body['nombre']) || empty($body['email'])) {
            Lib::fail(400, 'Faltan campos requeridos (nombre, email)');
        }
        // comision_pct se valida en el CHECK de la DB (0–100); si no viene,
        // toma el default (10.00).
        $row = Lib::insertRow(TABLE, $body, COLS_WRITABLE);
        if (Lib::prefersMinimal()) {
            http_response_code(201);
            exit;
        }
        Lib::respond(201, $row);
        break;
    }

    case 'PATCH': {
        $q = Lib::parseQuery(COLS_READABLE);
        if (empty($q['where'])) {
            Lib::fail(400, 'PATCH requiere un filtro (ej. id=eq.1)');
        }
        $body = Lib::jsonBody();
        $n = Lib::updateRows(TABLE, $q['where'], $q['params'], $body, COLS_WRITABLE);
        if ($n === 0) {
            Lib::respond(404, ['error' => 'No rows matched']);
        }
        http_response_code(204);
        exit;
    }

    case 'DELETE': {
        // Borrar laboratorios "duro" rompe la FK ON DELETE SET NULL en
        // pacientes (los pacientes quedan huérfanos). En vez de eso, el
        // admin debe usar PATCH con { activo: 0 } para soft-delete.
        Lib::fail(405, 'DELETE no permitido — usar PATCH { activo: 0 } para desactivar');
        break;
    }

    default:
        Lib::fail(405, 'Método no permitido');
}
