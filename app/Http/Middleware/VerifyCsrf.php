<?php

/**
 * CSRF 校验中间件
 *
 * 对**所有**非 GET 请求强制校验令牌。旧版是每个 POST 处理块各自调用一次
 * csrf_guard()，11 处调用点里漏了登出（logout.php 用 GET 直接执行状态变更，
 * 一张 <img src="/logout.php"> 就能把用户踢下线）。
 *
 * 放在中间件里之后，「忘记加校验」这种错误在结构上不再可能发生：
 * 新路由默认受到保护，要放行得显式排除。
 *
 * 令牌改为无状态签名（Support\Tokens）之后，这里也不再需要会话：
 * 校验只依赖 app_key 与令牌自身携带的内容。好处是渲染表单不会
 * 让匿名访客变成有状态访客，页面因此可以被边缘缓存。
 */
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\Tokens;

final class VerifyCsrf implements Middleware
{
    public function __construct(
        private readonly Tokens $tokens,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // 只读方法不需要令牌
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (!$this->tokens->verify($request->post('_token'))) {
            // 给出可操作的提示，而不是像旧版那样直接 exit 一行纯文本
            $this->session->flash('error', '页面已过期或请求来源不可信，请返回上一页重新提交。');
            return Response::html($this->expiredPage(), 419);
        }

        return $next($request);
    }

    /**
     * 令牌失效页。
     *
     * 不直接抛 400 白屏：用户看到的是「请重新提交」，而不是一个错误码。
     */
    private function expiredPage(): string
    {
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>页面已过期</title><style>'
            . 'body{margin:0;padding:72px 20px;background:#f7f9fc;color:#0f172a;text-align:center;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
            . '.b{max-width:440px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:16px;'
            . 'padding:34px 30px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -12px rgba(15,23,42,.12)}'
            . 'h1{margin:0 0 10px;font-size:19px}p{margin:0 0 22px;color:#55637a;font-size:14px}'
            . 'a{display:inline-block;padding:10px 22px;background:#4f46e5;color:#fff;border-radius:10px;'
            . 'text-decoration:none;font-weight:600}</style></head><body><div class="b">'
            . '<h1>页面已过期</h1>'
            . '<p>为了安全，表单在本页面停留过久后会失效。请返回上一页，刷新后重新提交。</p>'
            . '<a href="javascript:history.back()">← 返回上一页</a>'
            . '</div></body></html>';
    }
}
