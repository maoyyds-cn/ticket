<?php
/**
 * 应用引导：常量、配置、数据库、函数库加载
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VER', '1.2.0');
define('APP_PATH', APP_ROOT . DIRECTORY_SEPARATOR . 'includes');
define('UPLOAD_PATH', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
define('TPL_PATH', APP_ROOT . DIRECTORY_SEPARATOR . 'templates');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * 统一错误处理：写入 logs/error.log，并在页面上给出可读提示。
 *
 * 修复的两个真实问题：
 * 1) display_errors 关闭且没有任何处理器，运行期错误一律表现为空白「HTTP 500」，
 *    页面和日志里都拿不到任何线索，无法定位。
 * 2) PHP 的 error_log 目标未设置时，error_log() 会被静默丢弃（落到 SAPI 默认位置，
 *    多数虚拟主机不可读），等于什么都没记。这里显式指向自己的日志目录。
 */
const LOG_PATH = APP_ROOT . DIRECTORY_SEPARATOR . 'logs';

ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . DIRECTORY_SEPARATOR . 'php-error.log');

function app_error_log(string $message): void
{
    if (!is_dir(LOG_PATH)) {
        @mkdir(LOG_PATH, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    // 目录不可写时不能让日志失败再触发一次致命错误
    if (@file_put_contents(LOG_PATH . DIRECTORY_SEPARATOR . 'error.log', $line, FILE_APPEND | LOCK_EX) === false) {
        @error_log($message);
    }
}

function app_error_exit(string $title, string $detail): never
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>'
        . 'body{margin:0;padding:40px 16px;background:#f4f5f9;color:#1f2333;'
        . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}'
        . '.box{max-width:680px;margin:0 auto;background:#fff;border:1px solid #e6e8f0;'
        . 'border-radius:14px;padding:28px 30px;box-shadow:0 6px 24px rgba(31,35,51,.06)}'
        . 'h1{margin:0 0 10px;font-size:19px}p{margin:0 0 12px;color:#5b6178}'
        . 'code{display:block;background:#f7f8fc;border:1px solid #e6e8f0;border-radius:8px;'
        . 'padding:11px 13px;font:13px/1.6 ui-monospace,Consolas,monospace;white-space:pre-wrap;'
        . 'word-break:break-all;color:#b42318}'
        . 'a{color:#4f46e5}</style></head><body><div class="box">'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>详细错误已写入 <code>logs/error.log</code>，查看该文件即可定位问题。</p>'
        . '<code>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</code>'
        . '<p style="margin-top:16px"><a href="javascript:history.back()">← 返回上一页</a></p>'
        . '</div></body></html>';
    exit;
}

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {   // 被 @ 抑制的错误不重复记录
        return false;
    }
    app_error_log(match ($no) {
        E_WARNING      => '[WARNING] ',
        E_NOTICE       => '[NOTICE] ',
        E_DEPRECATED   => '[DEPRECATED] ',
        E_USER_WARNING => '[USER_WARNING] ',
        E_USER_NOTICE  => '[USER_NOTICE] ',
        default        => '[ERROR] ',
    } . $str . ' in ' . $file . ':' . $line);
    return true;
});

set_exception_handler(static function (Throwable $ex): void {
    $msg = get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine();
    app_error_log('[EXCEPTION] ' . $msg);
    app_error_exit('服务器处理请求时发生异常', $msg);
});

/**
 * 兜底捕获未被异常处理器接住的致命错误（Parse/E_ERROR/内存耗尽等）。
 * error_get_last() 返回的是「最后一条错误」，可能只是被处理的 NOTICE，
 * 因此必须用类型白名单过滤，不能只看是否为空。
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    app_error_log('[FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    app_error_exit('服务器发生致命错误', $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
});

$configFile = APP_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) {
    exit('配置文件不存在，请先运行安装向导：/install/index.php');
}

/** @var array $CFG */
$CFG = require $configFile;

if (empty($CFG['installed'])) {
    // 这里不能用 302 跳转。安装页在「锁文件存在但配置未生效」时会再次把
    // 用户判定为未安装并弹回自身，形成无限循环——这是实际发生过的问题。
    // 改为直接输出提示页并给出链接，链路不会自动循环，故障点也一目了然。
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir    = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
    $link   = htmlspecialchars($dir . '/install/index.php', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>系统尚未完成安装</title>'
       . '<div style="max-width:540px;margin:90px auto;padding:0 20px;font:16px/1.75 system-ui,-apple-system,sans-serif;color:#1e293b;text-align:center">'
       . '<h1 style="font-size:22px;margin:0 0 12px">系统尚未完成安装</h1>'
       . '<p style="color:#64748b;margin:0 0 28px">检测到 config/config.php 中的 installed 标记仍为未安装状态。</p>'
       . '<a href="' . $link . '" style="display:inline-block;padding:11px 26px;background:#4f46e5;color:#fff;border-radius:9px;text-decoration:none;font-weight:500">前往安装向导</a>'
       . '<p style="color:#94a3b8;font-size:13px;margin:32px 0 0">若系统确实已安装却看到此页，说明 config/config.php 被覆盖了，'
       . '其中 <code>installed</code> 应为 <code>true</code>。</p>'
       . '</div></html>';
    exit;
}

// ---- 安全响应头 ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ---- 会话 ----
if (session_status() === PHP_SESSION_NONE) {
    session_name('TKSESSID' . substr(md5($CFG['app_key'] ?? 'tk'), 0, 8));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require APP_PATH . '/db.php';
require APP_PATH . '/helpers.php';
require APP_PATH . '/roles.php';
require APP_PATH . '/auth.php';
require APP_PATH . '/captcha.php';
require APP_PATH . '/turnstile.php';
require APP_PATH . '/mailer.php';
require APP_PATH . '/ticket.php';

// ---- 设置缓存 ----
function boot_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $rows = db_all('SELECT skey, svalue FROM ' . DB_PRE . 'settings');
    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['skey']] = $r['svalue'];
    }
    return $settings;
}

$GLOBALS['SETTINGS'] = boot_settings();

/**
 * 通知邮件在响应发出之后再发
 *
 * 通知类邮件此前是在业务代码里同步发送的，submit.php 一次提交要发两封
 * （用户确认 + 通知管理员），SMTP 不顺时用户只能对着白屏等上几分钟。
 *
 * 这里挂到 shutdown 而非直接发：PHP 会在输出完成后才执行 shutdown 钩子，
 * 此时再 fastcgi_finish_request() 把响应交给浏览器，然后才去连 SMTP。
 * 注册在最后，是为了排在上面两个错误处理器之后——致命错误页面不该
 * 再顺带发通知。
 */
register_shutdown_function(static function (): void {
    if (empty($GLOBALS['MAIL_QUEUE'])) {
        return;
    }
    // 致命错误已被前面的钩子处理并输出错误页，此时补发通知没有意义，
    // 还会把错误页的输出缓冲冲掉
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    // 会话必须先落盘并释放锁，再去发邮件。
    // 否则用户在拿到页面后立刻点链接，下一个请求会因会话文件仍被本进程
    // 锁住而阻塞，直到整个邮件队列发完——异步优化反而制造了一个新的卡顿。
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    mail_flush_response();
    mail_queue_flush();
});
