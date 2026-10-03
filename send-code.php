<?php
/**
 * 发送注册邮箱验证码（AJAX 接口）
 *
 * 独立成接口而非复用 register.php 的 POST：验证码发送后页面若整页刷新，
 * Turnstile 令牌已被服务端消费，用户必须重新点一次验证，体验很差。
 * 这里返回 JSON，前端原地更新提示，不刷新页面。
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

// 全接口兜底：任何未捕获异常都必须以 JSON 返回。
// 否则前端 r.json() 解析 HTML 错误页失败，控制台只会显示 500，
// 看不到任何原因（tk_email_code 表缺失是最常见原因）。
set_exception_handler(static function (Throwable $ex): void {
    app_error_log('[SendCode] ' . get_class($ex) . ': ' . $ex->getMessage()
        . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    $hint = '';
    if (str_contains($ex->getMessage(), 'email_code')) {
        $hint = '（缺少数据库表，请执行 install/upgrade-turnstile.sql）';
    }
    echo json_encode(
        ['ok' => false, 'msg' => '服务异常：' . $ex->getMessage() . $hint, 'needTurnstile' => true],
        JSON_UNESCAPED_UNICODE
    );
});

if (!is_post()) {
    echo json_encode(['ok' => false, 'msg' => '请求方式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_login()) {
    echo json_encode(['ok' => false, 'msg' => '已登录，无需验证'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_guard();

$email = strtolower(trim((string)post('email')));

// 频控 + 蜜罐
$guard = email_code_rate_guard($email);
if (!$guard['ok']) {
    echo json_encode(['ok' => false, 'msg' => $guard['msg']], JSON_UNESCAPED_UNICODE);
    exit;
}

// 人机验证：必须在发信之前，否则验证码接口会被当成邮件炸弹接口
$ts = turnstile_verify('register');
if (!$ts['ok']) {
    echo json_encode(['ok' => false, 'msg' => $ts['msg'], 'needTurnstile' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_valid_email($email)) {
    echo json_encode(['ok' => false, 'msg' => '邮箱格式不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (db_one('SELECT id FROM ' . DB_PRE . 'user WHERE email = ? AND email <> ""', [$email])) {
    echo json_encode(['ok' => false, 'msg' => '该邮箱已被注册，请直接登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mail_enabled()) {
    echo json_encode(['ok' => false, 'msg' => '邮件发送未启用，请联系管理员'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = email_code_create($email, 'register');
$site = (string)setting('site_name', '工单中心');
$sent = mail_send(
    $email,
    $site . ' — 邮箱验证码',
    mail_wrap('邮箱验证码',
        '<p>你的验证码是：</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:6px;margin:18px 0;color:#4f46e5">' . $code . '</div>'
        . '<p style="color:#64748b">验证码 10 分钟内有效，请勿转发给他人。</p>'
        . '<p style="color:#94a3b8;font-size:13px">如果不是你本人操作，请忽略本邮件。</p>',
        $site)
);

if ($sent['ok']) {
    echo json_encode(
        ['ok' => true, 'msg' => '验证码已发送，请查收邮件（有效期 10 分钟）', 'needTurnstile' => true],
        JSON_UNESCAPED_UNICODE
    );
} else {
    echo json_encode(
        ['ok' => false, 'msg' => '发送失败：' . $sent['msg'], 'needTurnstile' => true],
        JSON_UNESCAPED_UNICODE
    );
}
