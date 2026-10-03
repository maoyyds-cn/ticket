<?php

/**
 * 全局辅助函数
 *
 * 只放「模板里会高频用到、而且短到不值得写成类」的东西。
 * 业务逻辑一律进 app/ 下的类，避免这里变成第二个 helpers.php 大杂烩。
 */
declare(strict_types=1);

use App\Core\Config;
use App\Support\Str;

if (!function_exists('e')) {
    /**
     * HTML 转义。
     *
     * 模板里每一个来自数据库或用户输入的变量都必须过这个函数。
     * ENT_SUBSTITUTE 保证非法 UTF-8 字节被替换而不是让整个输出变成空字符串
     * ——v1 的 nl2br(preg_replace) 路径就踩过这个坑。
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** 生成站内绝对路径（带子目录部署前缀），始终以 / 开头 */
    function url(string $path = ''): string
    {
        $base = rtrim((string)Config::string('app.base_path', ''), '/');
        if ($path === '') {
            return $base === '' ? '/' : $base . '/';
        }
        // 已经是绝对 URL 就原样返回
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path) === 1) {
            return $path;
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** 静态资源地址，带版本号用于强制刷新缓存 */
    function asset(string $path): string
    {
        $file = Config::string('app.public_dir') . '/' . ltrim($path, '/');
        $ver = is_file($file) ? (string)filemtime($file) : Config::string('app.version', '1');
        return url($path) . '?v=' . $ver;
    }
}

if (!function_exists('upload_url')) {
    /** 附件访问地址 */
    function upload_url(string $relPath): string
    {
        return url('uploads/' . ltrim($relPath, '/'));
    }
}

if (!function_exists('q')) {
    /**
     * 在保留现有查询串的前提下覆盖部分参数，用于分页与筛选链接。
     *
     * v1 的 page_link() 直接 http_build_query($_GET)，导致翻页时
     * 会把上一次的 page 也带上，出现 ?page=2&page=3 这类脏地址。
     *
     * @param array<string,string|int|null> $params
     */
    function q(array $params, string $path = ''): string
    {
        $current = $_GET;
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                unset($current[$k]);
            } else {
                $current[$k] = (string)$v;
            }
        }
        $target = $path !== '' ? $path : (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $qs = http_build_query($current);
        return $qs === '' ? $target : $target . '?' . $qs;
    }
}

if (!function_exists('old')) {
    /** 表单回填值（配合 Session::keepOld 使用） */
    function old(string $key, string $default = ''): string
    {
        $session = App\Core\App::session();
        return e($session->old($key, $default));
    }
}

if (!function_exists('csrf_field')) {
    /**
     * CSRF 隐藏域。
     *
     * 令牌是无状态签名的（见 Support\Tokens），因此这里不需要会话——
     * 这正是匿名页面能够被边缘缓存的前提。
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_token')) {
    /** 当前请求的 CSRF 令牌 */
    function csrf_token(): string
    {
        /** @var App\Support\Tokens $tokens */
        $tokens = App\Core\App::container()->get(App\Support\Tokens::class);
        return $tokens->issue();
    }
}

if (!function_exists('active_class')) {
    /** 导航高亮：当前路径命中时返回 class 名 */
    function active_class(string $prefix, string $class = 'is-active'): string
    {
        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if ($prefix === '/') {
            return $path === '/' ? $class : '';
        }
        return str_starts_with($path, $prefix) ? $class : '';
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $s, int $chars, string $end = '…'): string
    {
        return e(Str::limit($s, $chars, $end));
    }
}
