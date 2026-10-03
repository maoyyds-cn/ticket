<?php

/**
 * 服务层返回值
 *
 * 服务方法需要同时表达「成功/失败」与「附带数据/错误原因」。v1 用
 * flash + redirect 直接写在页面里，导致同一段业务逻辑在页面、includes、
 * 后台三处的返回约定都不同。用一个显式的结果对象替代：
 * 调用方必须先判断 ok，拿不到数据也不会得到 null 引发的连锁错误。
 */
declare(strict_types=1);

namespace App\Domain\Ticket;

final class TicketResult
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly bool $ok,
        public readonly string $error = '',
        public readonly array $data = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function ok(array $data = []): self
    {
        return new self(true, '', $data);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->data[$key] ?? $default;
        return is_numeric($v) ? (int)$v : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->data[$key] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }
}
