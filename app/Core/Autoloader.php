<?php

/**
 * PSR-4 自动加载器
 *
 * 刻意不依赖 Composer：这台服务器上没有 Composer，而本项目的依赖为零。
 * 引入一个纯粹的类名到路径映射器，比让部署多一个构建步骤划算得多。
 *
 * 映射规则： App\Core\Router  ->  <root>/app/Core/Router.php
 */
declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    /** @var array<string,string> 命名空间前缀 => 根目录 */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDir): self
    {
        $this->prefixes[trim($prefix, '\\') . '\\'] = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        return $this;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
