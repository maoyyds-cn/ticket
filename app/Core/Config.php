<?php

/**
 * 配置容器
 *
 * 支持点号取值：Config::get('db.host')。配置值在首次读取时缓存，
 * 同一次请求内反复读取不会重复解析。
 */
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $dir): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // config/app.php 提供基础配置，config/local.php 覆盖敏感项（数据库口令等）。
        // 分开是为了让 local.php 可以单独 chmod 640 / 加入 .gitignore。
        foreach (['app.php', 'local.php'] as $file) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }
            /** @var array<string,mixed>|null $data */
            $data = require $path;
            if (is_array($data)) {
                self::$items = self::merge(self::$items, $data);
            }
        }
    }

    /**
     * 递归合并：local.php 只写需要覆盖的键，不必重复整棵数组。
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function string(string $key, string $default = ''): string
    {
        $v = self::get($key, $default);
        return is_scalar($v) ? (string)$v : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $cur = &self::$items;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $cur[$part] = $value;
                break;
            }
            if (!isset($cur[$part]) || !is_array($cur[$part])) {
                $cur[$part] = [];
            }
            $cur = &$cur[$part];
        }
        unset($cur);
    }

    /** 仅在测试/安装场景使用：清空已加载标记以便重新读取 */
    public static function reset(): void
    {
        self::$items = [];
        self::$loaded = false;
    }
}
