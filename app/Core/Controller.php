<?php

namespace App\Core;

/** 控制器基类：提供容器访问与渲染/跳转的便捷方法 */
abstract class Controller
{
    public function __construct(protected readonly Container $container)
    {
    }

    protected function view(): View
    {
        return $this->container->get(View::class);
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view()->render($template, $data), $status);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function db(): Database
    {
        return $this->container->get(Database::class);
    }
}
