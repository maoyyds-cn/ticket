<?php

/**
 * 中间件约定
 *
 * handle() 拿到请求与「后续处理链」，可以：
 *   - 直接返回 Response 短路（例如未登录时跳转登录页）
 *   - 调 $next($request) 并加工返回的响应（例如统一加安全响应头）
 */
declare(strict_types=1);

namespace App\Core;

interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}
