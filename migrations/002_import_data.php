<?php
/**
 * Siscormed — Importador one-shot desde CSVs de Supabase a MariaDB.
 *
 * Uso (en el NAS):
 *   DB_USER=siscormed_app DB_PASS='...' CSV_DIR=/tmp/siscormed_csvs \
 *     /usr/local/bin/php82 migrations/002_import_data.php
 *
 * Las creds NO van hardcoded — se leen de env. Pensado para correrse una sola
 * vez (idempotente: hace TRUNCATE de las tablas antes de cargar).
 *
 * Transformaciones aplicadas durante la carga:
 *   - Strings "null" del CSV se convierten a NULL real
 *   - Timestamps Postgres "YYYY-MM-DD HH:MM:SS.ffffff+00" se truncan al
 *     formato compatible con MariaDB TIMESTAMP(6)
 *   - admin_usuarios.password_hash entra en plano y se hashea con bcrypt
 *     (los users seguirán logueándose con su misma password, pero en la DB
 *     queda hash de verdad — esto cierra el bug de plaintext del admin viejo)
 */

declare(strict_types=1);

$dbUser = getenv('DB_USER') ?: 'siscormed_app';
$dbPass = getenv('DB_PASS');
$dbName = getenv('DB_NAME') ?: 'siscormed';
$dbSock = getenv('DB_SOCKET') ?: '/run/mysqld/mysqld10.sock';
$csvDir = getenv('CSV_DIR') ?: __DIR__ . '/../data-local';

if ($dbPass === false || $dbPass === '') {
    fwrite(STDERR, "FATAL: DB_PASS no está seteado.\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSock};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

echo "Conectado a MariaDB {$dbName} como {$dbUser}.\n";

/** Convierte "null" string a NULL, deja el resto igual. */
function v(?string $s): ?string
{
    if ($s === null) {
        return null;
    }
    $s = trim($s);
    return ($s === '' || strtolower($s) === 'null') ? null : $s;
}

/** Trunca timestamps de Postgres "...+00" al formato MariaDB. */
function ts(?string $s): ?string
{
    $s = v($s);
    if ($s === null) {
        return null;
    }
    // Quita zona horaria "+00" o "+HH:MM"
    return preg_replace('/[+-]\d{2}(:?\d{2})?$/', '', $s);
}

/** Lee un CSV con header en la primera fila. */
function readCsv(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("No puedo abrir {$path}");
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        throw new RuntimeException("CSV vacío: {$path}");
    }
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) !== count($header)) {
            // Filas malformadas se ignoran con warning, no abortan
            fwrite(STDERR, "WARN: fila mal-formada en {$path}, se ignora\n");
            continue;
        }
        $rows[] = array_combine($header, $r);
    }
    fclose($fh);
    return $rows;
}

/** Inserta filas en una tabla; las columnas vienen del header del CSV. */
function insertRows(PDO $pdo, string $table, array $rows, callable $transform): int
{
    if (empty($rows)) {
        echo "  → 0 filas en {$table}, nada que insertar\n";
        return 0;
    }
    $cols = array_keys($rows[0]);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colList = implode(',', array_map(fn($c) => "`{$c}`", $cols));
    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);

    $count = 0;
    foreach ($rows as $row) {
        $values = $transform($row);
        $stmt->execute(array_values($values));
        $count++;
    }
    return $count;
}

// ─────────────────────────────────────────────────────────────────────────
// PACIENTES
// ─────────────────────────────────────────────────────────────────────────
echo "\n== pacientes ==\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
// DELETE FROM en lugar de TRUNCATE: siscormed_app tiene DELETE pero no DROP
$pdo->exec("DELETE FROM pacientes_auditoria");
$pdo->exec("DELETE FROM pacientes");

$pacientes = readCsv($csvDir . '/pacientes.csv');
$tsCols = ['created_at', 'updated_at'];
$dateCols = ['fecha_nacimiento'];
$n = insertRows($pdo, 'pacientes', $pacientes, function (array $row) use ($tsCols, $dateCols): array {
    foreach ($row as $k => $val) {
        if (in_array($k, $tsCols, true)) {
            $row[$k] = ts($val);
        } elseif (in_array($k, $dateCols, true)) {
            $row[$k] = v($val);
        } else {
            $row[$k] = v($val);
        }
    }
    return $row;
});
echo "  → insertados {$n} pacientes\n";

// ─────────────────────────────────────────────────────────────────────────
// PACIENTES_AUDITORIA
// ─────────────────────────────────────────────────────────────────────────
echo "\n== pacientes_auditoria ==\n";
$audit = readCsv($csvDir . '/pacientes_auditoria.csv');
$n = insertRows($pdo, 'pacientes_auditoria', $audit, function (array $row): array {
    foreach ($row as $k => $val) {
        if ($k === 'created_at') {
            $row[$k] = ts($val);
        } else {
            $row[$k] = v($val);
        }
    }
    return $row;
});
echo "  → insertados {$n} eventos de auditoría\n";

// ─────────────────────────────────────────────────────────────────────────
// ADMIN_USUARIOS  (hashing bcrypt aquí — cerramos el bug de plaintext)
// ─────────────────────────────────────────────────────────────────────────
echo "\n== admin_usuarios (hasheando con bcrypt) ==\n";
$pdo->exec("DELETE FROM admin_usuarios");
$admins = readCsv($csvDir . '/admin_usuarios.csv');
$tsAdmin = ['created_at', 'updated_at', 'token_expiry'];
$boolAdmin = ['es_admin_principal'];

$n = insertRows($pdo, 'admin_usuarios', $admins, function (array $row) use ($tsAdmin, $boolAdmin): array {
    foreach ($row as $k => $val) {
        if (in_array($k, $tsAdmin, true)) {
            $row[$k] = ts($val);
        } elseif (in_array($k, $boolAdmin, true)) {
            $v = v($val);
            $row[$k] = ($v === null) ? 0 : (in_array(strtolower($v), ['true','t','1','yes','y'], true) ? 1 : 0);
        } else {
            $row[$k] = v($val);
        }
    }
    // Hash bcrypt — el CSV trae el password en plano
    if (!empty($row['password_hash'])) {
        $row['password_hash'] = password_hash($row['password_hash'], PASSWORD_BCRYPT);
    } else {
        // Fallback: si por alguna razón no había password, ponemos un hash imposible
        // (no se puede loguear hasta que el admin lo resetee)
        $row['password_hash'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    }
    return $row;
});
echo "  → insertados {$n} admin_usuarios (passwords hasheados con bcrypt)\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Resumen
echo "\n=== RESUMEN ===\n";
foreach (['pacientes', 'pacientes_auditoria', 'admin_usuarios'] as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "  {$t}: {$c} filas\n";
}
echo "\nImport OK.\n";
