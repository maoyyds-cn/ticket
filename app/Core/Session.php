<?php

/**
 * 会话封装
 *
 * 这一版最重要的一处改动是**惰性启动**与**写入追踪**：
 *
 * 旧写法在引导阶段无条件 session_start()。后果不只是多一个 Cookie——
 * PHP 一旦开启会话就会下发 Set-Cookie，响应随即被标记为「因人而异」，
 * Cloudflare 等边缘节点不再缓存它。对一个部署在美国、用户在国内的站点，
 * 这意味着每一次切页都要跨国回源，是「每个页面都慢」的最大单一原因。
 *
 * 现在：
 *   - 只有真正要读会话时才启动（session_start() 被推迟到第一次 get/set）；
 *   - 记录会话是否被写过（$touched）。若整个请求下来一个字都没写，
 *     响应就是纯匿名的，可以放心交给边缘缓存；
 *   - CSRF 令牌不再依赖会话（见 Support\Tokens），因此渲染表单不会
 *     因为「需要一个令牌」而把匿名访客变成有状态访客。
 */
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private bool $started = false;

    /** 是否已经启动过（用于判断要不要写 Set-Cookie） */
    private bool $attempted = false;

    /** 本次请求是否往会话里写过东西 */
    private bool $touched = false;

    /** flash 消息在本次请求中已被取走的标记，避免重复弹出 */
    private bool $flushed = false;

    /** 会话名与安全参数，延迟到真正启动时使用 */
    private string $name = 'TKSESSID';

    private bool $secure = false;

    public function configure(string $name, bool $secure = false): void
    {
        $this->name = $name;
        $this->secure = $secure;
    }

    /**
     * 显式启动会话。除了需要写会话的入口，一般不必调用——
     * get/set/has/forget 会自行按需启动。
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // 诊断：把「谁启动了会话」写进日志。
        // 会话一旦启动就会下发 Set-Cookie，响应随即失去边缘缓存资格，
        // 所以这个调用点必须能被追溯；靠猜是查不出来的。
        if (\App\Core\Config::bool('app.diagnose_session_start', false)) {
            $trace = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $i => $frame) {
                if ($i === 0) {
                    continue;
                }
                $trace[] = basename((string)($frame['file'] ?? '?')) . ':' . ($frame['line'] ?? '?')
                    . ' ' . (($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''));
            }
            \App\Core\Application::log('[SESSION] start 调用链: ' . implode(' <- ', $trace), 'diag.log');
        }

        // 标记「尝试过启动」：即使最终没有会话，也不应重复尝试
        $this->attempted = true;

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $this->secure,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    /** 会话是否已在本次请求中启动（供缓存判断使用） */
    public function isStarted(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * 会话是否被写过。
     *
     * 只有当它为 false 且请求是匿名的，响应才允许被边缘缓存。
     */
    public function isTouched(): bool
    {
        return $this->touched;
    }

    /**
     * 会话里是否已经存在某个身份标记。
     *
     * 用于「不启动会话就能判断是否登录」——直接读 $_SESSION 在未启动时是空的，
     * 因此这里只对已经启动的会话有意义；对未启动的会话返回 false，
     * 调用方应当换成读 Cookie 是否存在。
     */
    public function hasIdentityCookie(): bool
    {
        $cookieName = $this->name;
        return isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] !== '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started && !$this->hasIdentityCookie()) {
            // 没有会话 Cookie 就不可能存在会话数据，无需为此启动会话
            return $default;
        }
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
        $this->touched = true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__TK_MISSING__') !== '__TK_MISSING__';
    }

    public function forget(string ...$keys): void
    {
        if (!$this->isStarted() && !$this->hasIdentityCookie()) {
            return;
        }
        $this->start();
        foreach ($keys as $k) {
            if (isset($_SESSION[$k])) {
                unset($_SESSION[$k]);
                $this->touched = true;
            }
        }
    }

    public function regenerate(): void
    {
        $this->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $this->touched = true;
        }
    }

    // ---------------- flash ----------------

    public function flash(string $type, string $message): void
    {
        $this->start();
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
        $this->touched = true;
    }

    /**
     * 取出并清空 flash 消息。
     *
     * 每个请求只清一次：布局与页面都可能调用它，第二次调用必须拿到空数组，
     * 否则同一条消息会渲染两遍。
     *
     * @return list<array{type:string,msg:string}>
     */
    public function flashes(): array
    {
        if ($this->flushed) {
            return [];
        }
        $this->flushed = true;

        if (!$this->isStarted() && !$this->hasIdentityCookie()) {
            return [];
        }
        $this->start();
        $all = $_SESSION['_flash'] ?? [];
        if (is_array($all) && $all !== []) {
            unset($_SESSION['_flash']);
            $this->touched = true;
            return array_values($all);
        }
        return [];
    }

    // ---------------- 表单回填 ----------------

    /** @param array<string,mixed> $data */
    public function keepOld(array $data): void
    {
        // access_key 必须一起剔除：它是访客查看工单的唯一凭据，
        // 页面上明确承诺「只保存哈希、不保存明文」。把它留在会话里
        // 既与承诺矛盾，也让明文密钥多存活一段时间。
        // 蜜罐字段同样没有回填价值。
        unset(
            $data['_token'],
            $data['password'],
            $data['password2'],
            $data['captcha'],
            $data['access_key'],
            $data['hp_website'],
            $data['hp_email'],
            $data['form_opened_at'],
        );
        $this->set('_old', $data);
    }

    public function old(string $key, string $default = ''): string
    {
        $v = $this->get('_old', []);
        if (!is_array($v)) {
            return $default;
        }
        $out = $v[$key] ?? $default;
        return is_scalar($out) ? (string)$out : $default;
    }

    public function clearOld(): void
    {
        $this->forget('_old');
    }
}
