<?php

/**
 * HTTP 请求
 *
 * 把 $_SERVER / $_GET / $_POST / $_FILES 收敛成一个不可变对象，
 * 控制器从此不再直接碰超全局变量——这样参数从哪来、有没有被 trim，
 * 在类型签名上就看得见。
 */
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $body @param array<string,mixed> $files @param array<string,string> $server @param array<string,string> $cookies @param array<string,mixed> $attributes */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $server,
        private readonly array $cookies,
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        // 超全局数组在正常 FPM 请求里一定有值，但并非绝对：CLI 下、
        // 以及在 auto_globals_jit 生效期间访问未使用的超全局（$_COOKIE 只有在
        // 真的读过时才被填充）都可能拿到 null。array_map 收到 null 会直接抛
        // TypeError 并让整站 500，所以这里先归一化为数组。
        $server = is_array($_SERVER ?? null) ? $_SERVER : [];
        $cookie = is_array($_COOKIE ?? null) ? $_COOKIE : [];

        return new self(
            $method,
            self::normalizePath($path),
            is_array($_GET ?? null) ? $_GET : [],
            is_array($_POST ?? null) ? $_POST : [],
            is_array($_FILES ?? null) ? $_FILES : [],
            array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $server),
            array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $cookie),
        );
    }

    /**
     * 归一化路径：折叠重复斜杠、去掉结尾斜杠（根路径除外）。
     * 这样 /knowledge/ 与 /knowledge 命中同一条路由，不必为每种写法注册两次。
     */
    public static function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if ($path === '/') {
            return '/';
        }
        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** 兼容表单里 _method=PUT/DELETE 的写法（浏览器表单只支持 GET/POST） */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** 取查询参数（$_GET），始终返回字符串 */
    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $v = $this->query[$key] ?? null;
        return is_numeric($v) ? (int)$v : $default;
    }

    public function queryBool(string $key): bool
    {
        $v = $this->query[$key] ?? '';
        return is_scalar($v) && in_array((string)$v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 取表单字段（$_POST），始终返回去除首尾空白的字符串 */
    public function post(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function postInt(string $key, int $default = 0): int
    {
        $v = $this->body[$key] ?? null;
        return is_numeric($v) ? (int)$v : $default;
    }

    public function postBool(string $key): bool
    {
        $v = $this->body[$key] ?? '';
        return is_scalar($v) && in_array((string)$v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 未 trim 的原始字段，用于密码这类不能改动空白的输入 */
    public function raw(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * 上传文件组，已归一化为「每个文件一个数组」的列表形态。
     *
     * $_FILES 有两种形态（列表态与列式态），差异只在这里处理一次，
     * 上层拿到的永远是同一形状。
     *
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function files(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = [
                'name' => (string)($f['name'][$i] ?? ''),
                'type' => (string)($f['type'][$i] ?? ''),
                'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    public function server(string $key, string $default = ''): string
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * 这是否是一次「预取 / 预渲染」请求。
     *
     * 本站的导航预取会在鼠标悬停时提前抓取目标页，浏览器自身也有
     * prefetch / prerender 机制。这些请求**不是用户真的打开了页面**，
     * 因此挂在 GET 上的副作用（标记已读、累加浏览量）必须跳过，
     * 否则「鼠标划过工单列表」就会把每条都标成已读。
     *
     * 各家的信号不统一，这里把已知的都认一遍：
     *   Sec-Purpose / Purpose : prefetch | prerender   （现代标准）
     *   X-Moz                 : prefetch               （Firefox）
     *   X-Purpose             : preview                （旧 Safari/Chrome）
     */
    public function isPrefetch(): bool
    {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ', 'HTTP_X_PURPOSE'] as $key) {
            $v = strtolower($this->server($key));
            if ($v === '') {
                continue;
            }
            if (str_contains($v, 'prefetch') || str_contains($v, 'prerender') || str_contains($v, 'preview')) {
                return true;
            }
        }
        return false;
    }

    /** 顺带把「这是爬虫」也认一下——爬虫同样不该改数据 */
    public function isBot(): bool
    {
        $ua = strtolower($this->server('HTTP_USER_AGENT'));
        if ($ua === '') {
            // 没有 UA 的请求绝大多数不是正常浏览器
            return true;
        }
        foreach (['bot', 'crawler', 'spider', 'preview', 'curl', 'wget', 'python-requests',
                  'headless', 'monitor', 'fetcher'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否应跳过 GET 上的副作用（已读标记、浏览次数）。
     *
     * 把两个判断合在一起，是为了让调用点只写一行、意图明确；
     * 分散判断很容易在某个新页面上漏掉其中一个。
     */
    public function isPassiveFetch(): bool
    {
        return $this->isPrefetch() || $this->isBot();
    }

    public function cookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * 客户端 IP
     *
     * 只在 REMOTE_ADDR 不是本机回环时才信任 X-Forwarded-For：否则任何人
     * 加一个请求头就能伪造 IP，绕过按 IP 的频率限制。
     */
    public function ip(): string
    {
        $remote = $this->server('REMOTE_ADDR');
        $trusted = in_array($remote, ['127.0.0.1', '::1'], true) || $remote === '';

        if ($trusted) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
                $raw = $this->server($k);
                if ($raw === '') {
                    continue;
                }
                $ip = trim(explode(',', $raw)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr($this->server('HTTP_USER_AGENT'), 0, 250);
    }

    public function isSecure(): bool
    {
        return $this->server('HTTPS') === 'on'
            || $this->server('HTTP_X_FORWARDED_PROTO') === 'https'
            || $this->server('SERVER_PORT') === '443';
    }

    public function host(): string
    {
        $h = $this->server('HTTP_HOST');
        return $h !== '' ? $h : 'localhost';
    }

    /** 当前完整 URL（不含查询串），用于「登录后跳回原页」 */
    public function fullPath(): string
    {
        $q = http_build_query($this->query);
        return $this->path . ($q !== '' ? '?' . $q : '');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
