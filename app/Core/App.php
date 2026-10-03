<?php

/**
 * 内核门面
 *
 * 模板与全局辅助函数需要访问容器里的服务，但模板不该拿到整个 Application。
 * 这里提供一个极薄的静态入口，只暴露「模板确实需要」的几项。
 *
 * 注意：这是有意的权衡。业务代码（Controller / Service / Repository）
 * 一律走构造函数注入，只有视图层与 helpers.php 用这个门面——
 * 否则依赖关系会退化成隐式的全局状态，正是 v1 难以维护的根源之一。
 */
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static ?Container $container = null;

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('容器尚未初始化');
        }
        return self::$container;
    }

    public static function session(): Session
    {
        /** @var Session $s */
        $s = self::container()->get(Session::class);
        return $s;
    }

    public static function view(): View
    {
        /** @var View $v */
        $v = self::container()->get(View::class);
        return $v;
    }

    public static function db(): Database
    {
        /** @var Database $d */
        $d = self::container()->get(Database::class);
        return $d;
    }
}
