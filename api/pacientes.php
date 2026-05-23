<?php
require __DIR__ . '/_lib.php';
Lib::init();

const TABLE = 'pacientes';

// Whitelist de columnas (todo lo de la tabla excepto los auto-managed)
const COLS_WRITABLE = [
    'nombres','apellidos','nombre','nombre_completo','cedula','ruc',
    'fecha_nacimiento','sexo','email','telefono',
    'direccion','ciudad','sector','referencia','pais',
    'peso_actual','estatura','peso_meta','bmi',
    'presion_arterial','frecuencia_cardiaca',
    'condiciones','condiciones_grupo1','condiciones_grupo2','condiciones_grupo3',
    'opioides_3meses','cirugia_previa_peso','toma_medicamentos','toma_medicamentos_recetados',
    'medicamentos_detalle','intentos_previos','alergias','nivel_actividad','habitos',
    'compatibilidad_pct','compatibilidad_label',
    'estado','estado_pago',
    'medicamento_aprobado','dosis','numero_orden','notas_medico',
];

// Columnas válidas para SELECT/WHERE/ORDER (incluye las readonly)
const COLS_READABLE = ['id','created_at','updated_at', ...COLS_WRITABLE];

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

    case 'PATCH': {
        $q = Lib::parseQuery(COLS_READABLE);
        if (empty($q['where'])) {
            Lib::fail(400, 'PATCH requiere un filtro (ej. id=eq.123)');
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
        $q = Lib::parseQuery(COLS_READABLE);
        if (empty($q['where'])) {
            Lib::fail(400, 'DELETE requiere un filtro (ej. id=eq.123)');
        }
        // pacientes_auditoria.FK ON DELETE CASCADE limpia el historial solo
        $sql = "DELETE FROM `" . TABLE . "`{$q['where']}";
        $stmt = Lib::$db->prepare($sql);
        $stmt->execute($q['params']);
        http_response_code(204);
        exit;
    }

    default:
        Lib::fail(405, 'Método no permitido');
}
