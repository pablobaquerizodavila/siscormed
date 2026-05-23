<?php
/**
 * admin_usuarios endpoint.
 *
 * Diferencia clave vs. el resto: cuando llega un body con `password_hash`
 * (típicamente en POST=registro y en PATCH=reset), el valor entra como texto
 * plano y se hashea con bcrypt antes de pegarle a la BD. Esto se hace para
 * que admin.html pueda seguir enviando el campo con su nombre actual sin
 * que volvamos a guardar plaintexts.
 *
 * Las consultas GET NUNCA devuelven password_hash al cliente, aunque el HTML
 * lo pida con `select=*` — se filtra a nivel de proyección.
 */

require __DIR__ . '/_lib.php';
Lib::init();

const TABLE = 'admin_usuarios';

const COLS_WRITABLE = [
    'nombre_completo', 'email', 'username', 'password_hash', 'rol',
    'numero_licencia', 'especialidad', 'numero_permiso', 'nombre_laboratorio',
    'telefono', 'estado',
    'email_recuperacion', 'token_recuperacion', 'token_expiry',
    'es_admin_principal', 'creado_por',
];
const COLS_READABLE = ['id', 'created_at', 'updated_at', ...COLS_WRITABLE];

// Estas columnas NUNCA salen del API, ni siquiera con select=*
const COLS_SECRET = ['password_hash', 'token_recuperacion'];

function stripSecrets(array $row): array
{
    foreach (COLS_SECRET as $c) {
        unset($row[$c]);
    }
    return $row;
}

switch (Lib::method()) {
    case 'GET': {
        $q = Lib::parseQuery(COLS_READABLE);
        $sql = "SELECT {$q['select']} FROM `" . TABLE . "`{$q['where']}{$q['order']}";
        $stmt = Lib::$db->prepare($sql);
        $stmt->execute($q['params']);
        $rows = array_map('stripSecrets', $stmt->fetchAll());
        Lib::respond(200, $rows);
        break;
    }

    case 'POST': {
        $body = Lib::jsonBody();
        // Hashear password antes de INSERT
        if (isset($body['password_hash']) && $body['password_hash'] !== '') {
            $body['password_hash'] = password_hash((string)$body['password_hash'], PASSWORD_BCRYPT);
        }
        $row = Lib::insertRow(TABLE, $body, COLS_WRITABLE);
        Lib::respond(201, stripSecrets($row));
        break;
    }

    case 'PATCH': {
        $q = Lib::parseQuery(COLS_READABLE);
        if (empty($q['where'])) {
            Lib::fail(400, 'PATCH requiere un filtro (ej. id=eq.123)');
        }
        $body = Lib::jsonBody();
        // Mismo tratamiento: si llega password_hash con string no vacío, se hashea
        if (isset($body['password_hash']) && $body['password_hash'] !== '' && $body['password_hash'] !== null) {
            $body['password_hash'] = password_hash((string)$body['password_hash'], PASSWORD_BCRYPT);
        }
        $n = Lib::updateRows(TABLE, $q['where'], $q['params'], $body, COLS_WRITABLE);
        if ($n === 0) {
            Lib::respond(404, ['error' => 'No rows matched']);
        }
        http_response_code(204);
        exit;
    }

    default:
        Lib::fail(405, 'Método no permitido');
}
