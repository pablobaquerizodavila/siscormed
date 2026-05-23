<?php
require __DIR__ . '/_lib.php';
Lib::init();

const TABLE = 'pacientes_auditoria';

const COLS_WRITABLE = [
    'paciente_id', 'estado_anterior', 'estado_nuevo', 'usuario', 'rol',
];
const COLS_READABLE = ['id', 'created_at', ...COLS_WRITABLE];

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
        $row = Lib::insertRow(TABLE, $body, COLS_WRITABLE);
        if (Lib::prefersMinimal()) {
            http_response_code(201);
            exit;
        }
        Lib::respond(201, $row);
        break;
    }

    case 'DELETE': {
        $q = Lib::parseQuery(COLS_READABLE);
        if (empty($q['where'])) {
            Lib::fail(400, 'DELETE requiere un filtro');
        }
        $sql = "DELETE FROM `" . TABLE . "`{$q['where']}";
        $stmt = Lib::$db->prepare($sql);
        $stmt->execute($q['params']);
        http_response_code(204);
        exit;
    }

    default:
        Lib::fail(405, 'Método no permitido');
}
