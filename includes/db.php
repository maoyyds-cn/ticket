<?php
/**
 * 数据库封装（PDO 单例）
 */
declare(strict_types=1);

if (!defined('DB_PRE')) {
    global $CFG;
    define('DB_PRE', (string)($CFG['db']['prefix'] ?? 'tk_'));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $CFG;
    $c   = $CFG['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['name']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/install/')) {
            throw $e;
        }
        http_response_code(500);
        exit('数据库连接失败，请检查 config/config.php 配置。');
    }

    // 把 MySQL 会话时区对齐到 PHP 时区。
    //
    // 为什么必须做：全站的时间戳都由 NOW() 写入，而 time_ago() 用 PHP 的
    // time() 做差。服务器上 MySQL 通常是 UTC、PHP 是 Asia/Shanghai，
    // 两者差 8 小时，于是每条记录都显示成「8 小时前」，刚提交的工单也一样。
    //
    // 用数字偏移而非 'Asia/Shanghai'：MySQL 未导入时区表时具名时区会直接报错。
    $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->getOffset();
    $sign   = $offset < 0 ? '-' : '+';
    $offset = abs($offset);
    $hh = str_pad((string)intdiv($offset, 3600), 2, '0', STR_PAD_LEFT);
    $mm = str_pad((string)(intdiv($offset, 60) % 60), 2, '0', STR_PAD_LEFT);
    $pdo->exec("SET time_zone = '{$sign}{$hh}:{$mm}'");

    return $pdo;
}

/**
 * 存量数据的时间戳与当前时间相差多少秒
 *
 * 用于识别历史数据是在时区对齐之前写入的（差值约等于 PHP 时区偏移）。
 * 返回 null 表示取不到参考行（如工单表为空），此时无从判断，不应纠偏。
 */
function db_tz_drift(string $table = 'ticket', string $column = 'created_at'): ?int
{
    $newest = db_one('SELECT `' . $column . '` FROM ' . DB_PRE . $table
        . ' ORDER BY `' . $column . '` DESC LIMIT 1', [], null);
    if (!$newest) {
        return null;
    }
    $ts = strtotime((string)$newest);
    if ($ts === false) {
        return null;
    }
    return time() - $ts;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $r = db_query($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function db_one(string $sql, array $params = [], $default = null)
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int)db()->lastInsertId();
}
