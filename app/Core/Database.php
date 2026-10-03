<?php

/**
 * 数据库连接与查询门面
 *
 * 保留 v1 里被验证有效的一个设计：把 MySQL 会话时区对齐到 PHP 时区。
 * 服务器系统时区是 UTC，PHP 与 MySQL 若不一致，所有「几分钟前」都会
 * 整体偏 8 小时——v1 的注释记录过这个真实故障，这里继续守住。
 */
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    private int $txDepth = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($this->config['host'] ?? '127.0.0.1'),
            (int)($this->config['port'] ?? 3306),
            (string)($this->config['name'] ?? ''),
            (string)($this->config['charset'] ?? 'utf8mb4')
        );

        $this->pdo = new PDO(
            $dsn,
            (string)($this->config['user'] ?? ''),
            (string)($this->config['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );

        $this->pdo->exec("SET time_zone = '" . $this->tzOffset() . "'");
        return $this->pdo;
    }

    /**
     * 当前 PHP 时区相对 UTC 的偏移，格式 +08:00。
     *
     * 用数字偏移而不是 'Asia/Shanghai'：MySQL 未导入时区表时具名时区会直接报错。
     */
    private function tzOffset(): string
    {
        $offset = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->getOffset();
        $sign = $offset < 0 ? '-' : '+';
        $abs = abs($offset);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs, 60) % 60);
    }

    /** @param array<int|string,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->query($sql, $params)->fetchAll();
        return $rows;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** @param array<int|string,mixed> $params */
    public function int(string $sql, array $params = [], int $default = 0): int
    {
        $v = $this->value($sql, $params, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    /** @param array<int|string,mixed> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int)$this->pdo()->lastInsertId();
    }

    /** @param array<int|string,mixed> $params */
    public function affected(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * 事务：支持嵌套调用（内层用 SAVEPOINT），
     * 这样服务之间互相调用不会因为「已经开过事务」而互相破坏原子性。
     */
    public function transaction(callable $fn): mixed
    {
        $savepoint = 'sp' . $this->txDepth;
        if ($this->txDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT ' . $savepoint);
        }
        $this->txDepth++;

        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo()->commit();
            } else {
                $this->pdo()->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0) {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
            } else {
                try {
                    $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    // 回滚失败只能上抛原始异常，掩盖它会丢掉真正的错误原因
                }
            }
            throw $e;
        }
    }

    /** 判断异常是否为主键/唯一键冲突（工单号重试逻辑依赖它） */
    public static function isDuplicate(Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $code = $e->errorInfo[1] ?? null;
        return $code === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
    }

    public function tableExists(string $table): bool
    {
        $n = $this->value(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        return ((int)$n) > 0;
    }

    public function fail(string $message): never
    {
        throw new RuntimeException($message);
    }
}
