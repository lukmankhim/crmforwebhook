<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(config('db'));
    }
    return $pdo;
}

function db_connect(array $c): PDO
{
    $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * Query tanpa filter tenant. Hanya untuk tabel global (users, tenants, tenant_users,
 * rate_limits) dan worker yang memilih tenant sendiri. Data bisnis wajib lewat q_tenant().
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $name = is_int($key) ? $key + 1 : ':' . $key;
        $type = match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
    }
    $stmt->execute();
    return $stmt;
}

/**
 * Query data bisnis. SQL wajib memakai placeholder :tid, nilainya selalu diambil dari
 * konteks tenant (session atau token), tidak pernah dari input user.
 */
function q_tenant(string $sql, array $params = []): PDOStatement
{
    if (!preg_match('/:tid\b/', $sql)) {
        throw new LogicException('q_tenant: query wajib memfilter tenant dengan :tid');
    }
    if (array_key_exists('tid', $params)) {
        throw new LogicException('q_tenant: :tid diisi otomatis, jangan dikirim');
    }
    $params['tid'] = tenant_id();
    return q($sql, $params);
}

function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}
