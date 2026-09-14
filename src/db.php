<?php
/**
 * 数据库层 —— 基于 PDO 的单例连接 + 常用查询助手。
 * 所有 SQL 均使用预处理语句，避免 SQL 注入。
 */
declare(strict_types=1);

use PDO;
use PDOException;

/** @var ?PDO $__pdo */
$__pdo = null;

/**
 * 获取 PDO 单例连接。
 */
function db(): PDO
{
    global $__pdo;
    if ($__pdo !== null) {
        return $__pdo;
    }
    $cfg = $GLOBALS['APP_CONFIG']['db'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'] ?? '127.0.0.1',
        (string)($cfg['port'] ?? 3306),
        $cfg['name'] ?? '',
        $cfg['charset'] ?? 'utf8mb4'
    );
    try {
        $__pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    } catch (PDOException $e) {
        if (APP_DEBUG) {
            throw $e;
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("数据库连不上：请检查 config.php 中的 db 配置。\n");
    }
    return $__pdo;
}

/**
 * 执行写操作(INSERT/UPDATE/DELETE)，返回受影响行数。
 */
function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * 查询单行。
 */
function db_fetch(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 查询多行。
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 插入并返回自增 ID。
 */
function db_insert(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}

/**
 * 是否存在满足条件的记录。
 */
function db_exists(string $table, string $where, array $params = []): bool
{
    $row = db_fetch("SELECT 1 FROM `{$table}` WHERE {$where} LIMIT 1", $params);
    return $row !== null;
}
