<?php

/**
 * 应用内核
 *
 * 职责：装配容器 → 加载路由 → 匹配 → 跑中间件链 → 执行控制器 → 发送响应。
 * 全局错误处理也在这里收口，v1 里那种「白屏 500、日志里什么都没有」的情况
 * 必须消失：任何异常都会记录到 storage/logs 并在页面上给出可读提示。
 */
declare(strict_types=1);

namespace App\Core;

use App\Domain\Knowledge\KnowledgeBase;
use Closure;
use Throwable;

final class Application
{
    private Router $router;

    private bool $debug = false;

    /**
     * 日志目录的静态副本，供 shutdown 阶段使用。
     *
     * 邮件是在响应之后、由 shutdown 钩子发送的，那时容器可能已经析构，
     * 静态方法 log() 里通过 Config 读不到 app.log_dir，
     * 于是记录会回退到 PHP 的错误日志——「邮件投递耗时」这类关键信息
     * 就落进了 php-error.log，而不是你预期去看的 mail.log。
     * 这里在构造时把目录留存一份，保证整条生命周期都能写对文件。
     */
    private static string $staticLogDir = '';

    public function __construct(
        private readonly Container $container,
        private readonly string $basePath,
        private readonly string $logDir,
    ) {
        $this->router = new Router();
        $this->debug = Config::bool('app.debug', false);
        self::$staticLogDir = $logDir;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function basePath(string $append = ''): string
    {
        return rtrim($this->basePath, '/\\') . ($append !== '' ? DIRECTORY_SEPARATOR . ltrim($append, '/\\') : '');
    }

    // ---------------------------------------------------------------
    // 错误处理
    // ---------------------------------------------------------------

    public function registerErrorHandling(): void
    {
        $logDir = $this->logDir;

        ini_set('display_errors', $this->debug ? '1' : '0');
        ini_set('log_errors', '1');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('error_log', rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'php-error.log');
        error_reporting(E_ALL);

        set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $no) === 0) {
                return false; // 被 @ 抑制
            }
            $label = match ($no) {
                E_WARNING, E_USER_WARNING => 'WARNING',
                E_NOTICE, E_USER_NOTICE => 'NOTICE',
                E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
                default => 'ERROR',
            };
            self::log('[' . $label . '] ' . $str . ' in ' . basename($file) . ':' . $line);
            return true;
        });

        set_exception_handler(function (Throwable $e): void {
            $this->renderThrowable($e);
        });

        // 致命错误兜底：Parse error / 内存耗尽等不会被异常处理器接住
        register_shutdown_function(function (): void {
            $e = error_get_last();
            if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            self::log('[FATAL] ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line']);
            // 已经有输出时不再补错误页，避免拼出半截页面
            if (!headers_sent()) {
                http_response_code(500);
                echo $this->errorPage('服务器发生致命错误', $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
            }
        });
    }

    /**
     * 写一条日志。
     *
     * 优先使用构造时留存的目录（见 $staticLogDir 的说明）：邮件是在响应之后
     * 由 shutdown 钩子发送的，那时容器可能已析构，通过 Config 读不到 log_dir，
     * 记录就会回退到 PHP 的错误日志——「邮件投递耗时」这类关键信息
     * 会落进 php-error.log，而不是你预期去看的 mail.log。
     *
     * 拿不到目录时才退回 PHP 错误日志，绝不静默丢弃：
     * 丢日志会让「出错了但查不到」再次发生。
     */
    public static function log(string $message, string $file = 'app.log'): void
    {
        $dir = self::$staticLogDir;
        if ($dir === '') {
            $dir = Config::string('app.log_dir', '');
        }

        if ($dir === '') {
            @error_log($message);
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        if (@file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file, $line, FILE_APPEND | LOCK_EX) === false) {
            @error_log($message);
        }
    }

    private function renderThrowable(Throwable $e): void
    {
        $where = basename($e->getFile()) . ':' . $e->getLine();
        self::log('[EXCEPTION] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $where);

        if (headers_sent()) {
            echo "\n<!-- 未捕获异常：" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
            return;
        }
        http_response_code(500);
        $detail = $e->getMessage() . ' @ ' . $where;
        if ($this->debug) {
            $detail .= "\n\n" . $e->getTraceAsString();
        }
        echo $this->errorPage('服务器处理请求时发生异常', $detail);
    }

    private function errorPage(string $title, string $detail): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $d = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $t . '</title><style>'
            . 'body{margin:0;padding:56px 18px;background:#f8fafc;color:#0f172a;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
            . '.b{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;'
            . 'padding:30px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -12px rgba(15,23,42,.12)}'
            . 'h1{margin:0 0 8px;font-size:19px}p{margin:0 0 16px;color:#64748b}'
            . 'code{display:block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:12px 14px;'
            . 'font:13px/1.6 ui-monospace,Consolas,monospace;white-space:pre-wrap;word-break:break-word;color:#b91c1c}'
            . 'a{color:#4f46e5;text-decoration:none}</style></head><body><div class="b">'
            . '<h1>' . $t . '</h1>'
            . '<p>详细错误已写入 <code style="display:inline;padding:2px 6px">storage/logs/</code>，查看该目录下的日志即可定位。</p>'
            . '<code>' . $d . '</code>'
            . '<p style="margin:18px 0 0"><a href="/">← 返回首页</a></p>'
            . '</div></body></html>';
    }

    // ---------------------------------------------------------------
    // 请求处理
    // ---------------------------------------------------------------

    public function run(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            $where = basename($e->getFile()) . ':' . $e->getLine();
            self::log('[EXCEPTION] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $where);
            $detail = $e->getMessage() . ' @ ' . $where . ($this->debug ? "\n\n" . $e->getTraceAsString() : '');
            $response = Response::html($this->errorPage('服务器处理请求时发生异常', $detail), 500);
        }

        // 缓存策略与收尾钩子必须基于同一个判断，否则必然错位：
        // 如果这里判断「可缓存」而收尾时又下发 Set-Cookie，
        // 响应就会同时带着 s-maxage 和 Set-Cookie，CDN 会拒绝缓存，白忙一场。
        [$response, $cacheable] = $this->applyCachePolicy($request, $response);

        // 请求收尾钩子：把本次请求期间累计的副作用一次性落到响应头里。
        //
        // 「浏览量去重 Cookie」必须在这里写，而不是渲染每条 FAQ 时就写：
        // 一个列表页会渲染十几条，逐条 setcookie 会发出十几个同名 Cookie，
        // 只有最后一个生效，响应头也被灌满垃圾。
        try {
            $this->container->get(KnowledgeBase::class)->flushViewCookie($cacheable);
        } catch (Throwable $e) {
            // 统计类副作用绝不能让整个请求失败
            self::log('[FLUSH] 浏览记录写入失败：' . $e->getMessage());
        }

        return $response;
    }

    /**
     * 决定响应是否可被边缘缓存，并据此设置 Cache-Control。
     *
     * 背景：源站在境外、主要用户在国内。不缓存的话每一次切页都要跨国走一趟
     * TCP/TLS 加一个来回，这是「每个页面都慢」里最大的一块，且与代码质量无关。
     *
     * 允许缓存的条件是刻意保守的，必须同时满足：
     *   1) GET —— 写操作一律不缓存；
     *   2) 未登录（没有会话 Cookie）—— 登录用户的页面必须因人而异，绝不能共享；
     *   3) 本次请求没写过会话 —— 写过就说明响应带上了个性化痕迹
     *      （flash 消息、表单回填、访客访问密钥等）；
     *   4) 页面真的渲染出来了（2xx）—— 不缓存错误页与跳转；
     *   5) **不含 POST 表单** —— 见下方说明。
     *
     * 第 5 条是踩过的一次坑：登录页原本也被缓存 60 秒，结果前台改了提示文案后，
     * 访问者（尤其是从手机上打开的）在最长几分钟内仍看到旧文字，
     * 而这类页面恰恰是「改了文案希望立刻生效」的地方。
     *
     * 更实质的原因是 POST 表单里含有隐藏字段：CSRF 令牌、表单打开时间。
     * 缓存会把同一份内容发给所有访问者，于是隐藏字段也一起被共享；
     * 一旦某次提交失败需要回填，或校验依赖这些字段的时效性，
     * 就会出现难以复现的怪异行为。这类页面都是几 KB 的小响应，
     * 省下的带宽远不如可靠性重要。
     *
     * 注意只排除 **POST** 表单：知识库的搜索与筛选也用表单，但方法是 GET、
     * 没有隐藏字段，且知识库恰恰是最该被缓存的页面——一刀切会把它误伤掉。
     *
     * @return array{0:Response,1:bool} 处理后的响应，以及是否可缓存
     */
    private function applyCachePolicy(Request $request, Response $response): array
    {
        $session = $this->container->get(Session::class);

        $cacheable = $request->method() === 'GET'
            && $response->status() >= 200 && $response->status() < 300
            && !$session->hasIdentityCookie()
            && !$session->isTouched()
            && !$session->isStarted()
            && !$this->hasPostForm($response);

        if (!$cacheable) {
            // 有状态 / 非成功 / 含 POST 表单的响应一律不缓存
            return [$response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private'), false];
        }

        $ttl = (int)$this->container->get(Config::class)->get('app.edge_cache_seconds', 60);
        if ($ttl <= 0) {
            return [$response->withHeader('Cache-Control', 'no-store, must-revalidate'), false];
        }

        return [
            $response
                ->withHeader('Cache-Control', 'public, max-age=0, s-maxage=' . $ttl
                    . ', stale-while-revalidate=300, stale-if-error=600')
                // 让 CDN 按「是否压缩」分开缓存，避免把压缩版本发给不支持的客户端
                ->withHeader('Vary', 'Accept-Encoding'),
            true,
        ];
    }

    /**
     * 响应里是否含 POST 表单（需要 CSRF 令牌、会带回填内容的那种）。
     *
     * 判定 `<form ...>` 标签里是否出现 method="post"。没有 method 属性时
     * 浏览器按 GET 处理，因此不视为需要排除。
     * 页脚的搜索框、导航里的 GET 筛选表单都不受影响。
     */
    private function hasPostForm(Response $response): bool
    {
        $body = $response->content();
        if ($body === '' || stripos($body, '<form') === false) {
            return false;
        }
        // 抓出所有 <form ...> 开标签，检查其中是否声明了 post
        if (preg_match_all('/<form\b[^>]*>/i', $body, $m) === 0) {
            return false;
        }
        foreach ($m[0] as $tag) {
            if (preg_match('/\bmethod\s*=\s*["\']?post["\']?/i', $tag) === 1) {
                return true;
            }
        }
        return false;
    }

    private function dispatch(Request $request): Response
    {
        $matched = $this->router->match($request->method(), $request->path());

        if ($matched === null) {
            return $this->notFound($request);
        }
        if ($matched === 'method_mismatch') {
            return Response::html(
                $this->errorPage('请求方式不被支持', $request->method() . ' ' . $request->path()),
                405
            );
        }

        [$handler, $args, $middleware] = $matched;

        // 把路由参数挂到请求上，控制器用 $request->attribute('id') 取，
        // 避免每个 action 都多一个位置参数
        foreach ($args as $k => $v) {
            $request->setAttribute($k, $v);
        }

        $core = function (Request $req) use ($handler): Response {
            return $this->invoke($handler, $req);
        };

        // 从右往左包：声明顺序 = 执行顺序
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (callable $next, mixed $mw): callable {
                return function (Request $req) use ($mw, $next): Response {
                    // 中间件既可以是类名（走容器解析），也可以是已构造好的对象。
                    // 后者用于需要构造参数的中间件，例如「要求某项能力」——
                    // 能力名是配置而不是依赖，不适合靠容器自动注入。
                    $instance = is_object($mw) ? $mw : $this->container->get((string)$mw);
                    if (!$instance instanceof Middleware) {
                        throw new \RuntimeException('中间件必须实现 ' . Middleware::class);
                    }
                    return $instance->handle($req, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }

    private function invoke(mixed $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, $this->container);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request);
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request);
        } else {
            throw new \RuntimeException('无法识别的路由处理器');
        }

        if (!$result instanceof Response) {
            throw new \RuntimeException('控制器必须返回 ' . Response::class . ' 实例');
        }
        return $result;
    }

    private function notFound(Request $request): Response
    {
        // 404 也走统一的错误页，保持站点观感一致
        try {
            $view = $this->container->get(View::class);
            $html = $view->render('errors/404', ['layout' => 'layouts/front']);
            $resp = Response::html($html, 404);
        } catch (Throwable) {
            $resp = Response::html($this->errorPage('页面不存在', $request->path()), 404);
        }
        return $resp;
    }

    /**
     * 应用统一的安全响应头。
     * 这些头在 nginx 层也配了一份，两处都写是为了：PHP 层保证任何
     * 部署方式下都生效，nginx 层保证静态文件也带上。
     */
    public function securityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }
}
