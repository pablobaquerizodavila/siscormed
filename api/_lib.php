<?php
/**
 * Siscormed API — utilidades comunes.
 *
 *   - Carga de config (sin secretos en el repo)
 *   - Conexión PDO
 *   - Parser mínimo de query strings al estilo PostgREST, cubriendo SOLO los
 *     filtros que los HTML actuales realmente usan: `col=eq.X`, `select=*` o
 *     `select=col1,col2`, `order=col.asc|desc`, y `or=(a.eq.X,b.eq.Y,...)`.
 *     No es un reimplementador de PostgREST: si llega algo fuera de esto se
 *     responde 400 antes de tocar la BD.
 *   - Helpers de respuesta JSON / lectura de body / disparo de webhook a Make.
 */

declare(strict_types=1);

/** Permite que cualquier endpoint llame Lib::init() y obtenga PDO + config. */
final class Lib
{
    public static array $cfg;
    public static PDO $db;

    public static function init(): void
    {
        $cfgPath = __DIR__ . '/config.local.php';
        if (!is_file($cfgPath)) {
            self::fail(500, 'Config no encontrada — falta api/config.local.php');
        }
        self::$cfg = require $cfgPath;

        $dsn = isset(self::$cfg['db']['socket']) && self::$cfg['db']['socket']
            ? sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                self::$cfg['db']['socket'],
                self::$cfg['db']['name']
            )
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                self::$cfg['db']['host'],
                self::$cfg['db']['port'],
                self::$cfg['db']['name']
            );

        try {
            self::$db = new PDO(
                $dsn,
                self::$cfg['db']['user'],
                self::$cfg['db']['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            self::fail(500, 'DB connection failed', ['detail' => $e->getMessage()]);
        }

        self::cors();
    }

    /** Solo permite los orígenes listados en config; same-origin no necesita header. */
    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && in_array($origin, self::$cfg['cors_origins'] ?? [], true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Prefer');
            header('Vary: Origin');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::fail(400, 'Body must be a JSON object or array');
        }
        return $data;
    }

    public static function prefersMinimal(): bool
    {
        $h = $_SERVER['HTTP_PREFER'] ?? '';
        return stripos($h, 'return=minimal') !== false;
    }

    public static function respond(int $code, $payload = null): void
    {
        http_response_code($code);
        if ($payload === null) {
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $code, string $msg, array $extra = []): void
    {
        self::respond($code, ['error' => $msg] + $extra);
    }

    /**
     * Parsea $_GET en una estructura {filters, select, order, raw} y devuelve
     * un WHERE SQL parametrizado.
     *
     * Soporta:
     *   col=eq.VALUE           → WHERE col = ?
     *   or=(a.eq.X,b.eq.Y,...) → WHERE (a=? OR b=? ...)
     *   select=*               → "*"
     *   select=col1,col2       → "col1,col2"  (whitelist de columnas)
     *   order=col.asc|desc     → ORDER BY col {ASC|DESC}
     */
    public static function parseQuery(array $allowedCols): array
    {
        $select = '*';
        $where  = [];
        $params = [];
        $order  = '';

        foreach ($_GET as $k => $v) {
            if ($k === 'select') {
                if ($v === '*') {
                    $select = '*';
                } else {
                    $cols = array_map('trim', explode(',', $v));
                    foreach ($cols as $c) {
                        if (!in_array($c, $allowedCols, true)) {
                            self::fail(400, "Columna no permitida en select: {$c}");
                        }
                    }
                    $select = implode(',', array_map(fn($c) => "`{$c}`", $cols));
                }
                continue;
            }

            if ($k === 'order') {
                // order=col.asc o order=col.desc
                if (!preg_match('/^([a-z_][a-z0-9_]*)\.(asc|desc)$/i', $v, $m)) {
                    self::fail(400, "Sintaxis de order inválida: {$v}");
                }
                if (!in_array($m[1], $allowedCols, true)) {
                    self::fail(400, "Columna no permitida en order: {$m[1]}");
                }
                $order = " ORDER BY `{$m[1]}` " . strtoupper($m[2]);
                continue;
            }

            if ($k === 'or') {
                // or=(a.eq.X,b.eq.Y,c.eq.Z)
                if (!preg_match('/^\((.*)\)$/', $v, $m)) {
                    self::fail(400, 'Sintaxis de or= inválida (faltan paréntesis)');
                }
                $parts = self::splitCsvRespectingNothing($m[1]);
                $orClauses = [];
                foreach ($parts as $p) {
                    if (!preg_match('/^([a-z_][a-z0-9_]*)\.eq\.(.*)$/i', $p, $mm)) {
                        self::fail(400, "Sintaxis de filtro OR no soportada: {$p}");
                    }
                    if (!in_array($mm[1], $allowedCols, true)) {
                        self::fail(400, "Columna no permitida en or: {$mm[1]}");
                    }
                    $orClauses[] = "`{$mm[1]}` = ?";
                    $params[]    = $mm[2];
                }
                if (!empty($orClauses)) {
                    $where[] = '(' . implode(' OR ', $orClauses) . ')';
                }
                continue;
            }

            // Filtro simple: col=eq.X (otros operadores no soportados)
            if (preg_match('/^eq\.(.*)$/', $v, $m)) {
                if (!in_array($k, $allowedCols, true)) {
                    self::fail(400, "Columna no permitida en filtro: {$k}");
                }
                $where[]  = "`{$k}` = ?";
                $params[] = $m[1];
                continue;
            }

            // Cualquier otra cosa: rechazamos explícitamente para no abrir vector de inyección
            self::fail(400, "Parámetro no soportado: {$k}={$v}");
        }

        $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));

        return [
            'select' => $select,
            'where'  => $whereSql,
            'order'  => $order,
            'params' => $params,
        ];
    }

    /** Split CSV simple — no necesitamos respetar quotes en los filtros de PostgREST. */
    private static function splitCsvRespectingNothing(string $s): array
    {
        return array_map('trim', explode(',', $s));
    }

    /**
     * Inserta con whitelist de columnas. Cualquier campo en $body fuera de
     * $allowedCols se descarta silenciosamente.
     */
    public static function insertRow(string $table, array $body, array $allowedCols): array
    {
        $filtered = array_intersect_key($body, array_flip($allowedCols));
        if (empty($filtered)) {
            self::fail(400, 'Body sin columnas válidas');
        }
        $cols = array_keys($filtered);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', array_map(fn($c) => "`{$c}`", $cols));
        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
        try {
            self::$db->prepare($sql)->execute(array_values($filtered));
        } catch (PDOException $e) {
            // 23000 = violación de constraint (duplicado, FK, CHECK)
            $code = $e->getCode() === '23000' ? 23505 : 0;
            self::fail(409, 'INSERT failed', ['detail' => $e->getMessage(), 'code' => $code]);
        }
        $id = (int)self::$db->lastInsertId();
        $row = self::$db->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $row->execute([$id]);
        return $row->fetch() ?: ['id' => $id];
    }

    /** UPDATE con WHERE parametrizado y whitelist. Devuelve filas afectadas. */
    public static function updateRows(string $table, string $whereSql, array $whereParams, array $body, array $allowedCols): int
    {
        $filtered = array_intersect_key($body, array_flip($allowedCols));
        if (empty($filtered)) {
            self::fail(400, 'Body sin columnas válidas para UPDATE');
        }
        $set = [];
        $values = [];
        foreach ($filtered as $k => $v) {
            $set[] = "`{$k}` = ?";
            $values[] = $v;
        }
        $sql = "UPDATE `{$table}` SET " . implode(',', $set) . $whereSql;
        $stmt = self::$db->prepare($sql);
        $stmt->execute(array_merge($values, $whereParams));
        return $stmt->rowCount();
    }

    /** Dispara el webhook de Make.com fire-and-forget. Errores se loguean pero no rompen el flujo. */
    public static function fireWebhook(array $payload): void
    {
        $url = self::$cfg['make_webhook'] ?? null;
        if (!$url) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,  // si Make tarda más de 5s, no bloqueamos al usuario
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        @curl_exec($ch);
        // Ignoramos respuesta: la UX no debe romperse si Make.com está caído.
        curl_close($ch);
    }
}
