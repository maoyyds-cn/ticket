<?php

/**
 * 路由器
 *
 * 支持 {name} 占位符、路由组前缀、组级中间件。
 *
 * 设计上刻意只支持一种占位符语法：v1 每个页面都是独立 .php 文件，
 * 想让 /knowledge/12 这种地址可读就得靠查询串或伪静态规则，
 * 引入路由器后地址结构第一次变成显式、可搜索、可测试的东西。
 */
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:mixed,middleware:list<mixed>,name:string}> */
    private array $routes = [];

    /** @var list<mixed> 当前组继承的中间件（类名或已实例化的中间件对象） */
    private array $groupMiddleware = [];

    /** 当前组前缀 */
    private string $groupPrefix = '';

    /** 当前组名称前缀 */
    private string $groupName = '';

    public function get(string $path, mixed $handler, array $middleware = [], string $name = ''): self
    {
        return $this->add('GET', $path, $handler, $middleware, $name);
    }

    public function post(string $path, mixed $handler, array $middleware = [], string $name = ''): self
    {
        return $this->add('POST', $path, $handler, $middleware, $name);
    }

    /**
     * 路由组：/admin 下所有页面共用前缀与中间件，不必每行重复写。
     *
     * @param list<mixed> $middleware
     */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $prevPrefix = $this->groupPrefix;
        $prevMw = $this->groupMiddleware;

        $this->groupPrefix = rtrim($prevPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = array_merge($prevMw, $middleware);

        $define($this);

        $this->groupPrefix = $prevPrefix;
        $this->groupMiddleware = $prevMw;
    }

    /**
     * @param list<string> $middleware
     */
    private function add(string $method, string $path, mixed $handler, array $middleware, string $name): self
    {
        $full = $this->groupPrefix . '/' . trim($path, '/');
        $full = Request::normalizePath($full === '' ? '/' : $full);

        // 把 {id} 编译成正则，同时记录参数名顺序
        $params = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $full) ?? $full;

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name' => $this->groupName . $name,
        ];
        return $this;
    }

    public function groupName(string $name, callable $define): void
    {
        $prev = $this->groupName;
        $this->groupName = $prev . $name;
        $define($this);
        $this->groupName = $prev;
    }

    /**
     * 匹配路由。
     *
     * 返回 [handler, 参数数组, 中间件列表]；路径存在但方法不符时返回
     * 'method_mismatch'，据此可以正确地回 405 而不是 404。
     *
     * @return array{0:mixed,1:array<string,string>,2:list<mixed>}|string|null
     */
    public function match(string $method, string $path): array|string|null
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatched = true;
                continue;
            }
            array_shift($m);
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = (string)($m[$i] ?? '');
            }
            return [$route['handler'], $args, $route['middleware']];
        }
        return $pathMatched ? 'method_mismatch' : null;
    }

    /** @return list<string> 所有已注册路径，供调试与「相关页面」提示使用 */
    public function paths(): array
    {
        return array_map(static fn(array $r): string => $r['regex'], $this->routes);
    }
}
