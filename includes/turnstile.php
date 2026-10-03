<?php
/**
 * Cloudflare Turnstile 人机验证
 *
 * 用于两处：
 *   1) 游客提交工单（取代原算术验证码）
 *   2) 注册页「发送邮箱验证码」（防止被刷邮件）
 *
 * 设计要点：
 *   - 密钥存数据库 setting，不写死在代码里，泄露后可随时更换
 *   - 校验失败不消耗「一次性令牌」，用户点一次即可重试
 *   - 网络故障与「验证未通过」区分开，避免用户被反复要求点验证码
 */
declare(strict_types=1);

const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

/**
 * 站点密钥（前端渲染 widget 用，可公开）
 */
function turnstile_site_key(): string
{
    return trim((string)setting('turnstile_site_key', ''));
}

/**
 * 私密密钥（服务端校验用，绝不能输出到前端）
 */
function turnstile_secret_key(): string
{
    return trim((string)setting('turnstile_secret_key', ''));
}

function turnstile_enabled(): bool
{
    return turnstile_site_key() !== '' && turnstile_secret_key() !== '';
}

/**
 * 渲染 Turnstile 组件
 *
 * @param string $formId 表单标识，用于区分同一页面上的多个 widget
 */
function turnstile_html(string $formId = 'submit'): string
{
    $key = turnstile_site_key();
    if ($key === '') {
        // 未配置时降级为「直接放行」，并保持蜜罐/频控等其余防护生效，
        // 避免未配置就把整个工单系统锁死。
        return '<input type="hidden" name="turnstile_bypass" value="1">';
    }

    // data-sitekey 供 JS 读取以调用 turnstile.render()；
    // 隐藏 input 兜底，供 JS 尚未加载时也能把密钥带上。
    $html  = '<div class="turnstile-wrap" data-turnstile="' . e($formId) . '" data-sitekey="' . e($key) . '"></div>';
    $html .= '<input type="hidden" name="turnstile_site_key" value="' . e($key) . '">';
    return $html;
}

/**
 * 在页面 <head> 输出 Turnstile 脚本
 *
 * 必须用官方 onload 回调而不是单纯 async：脚本下载完成后才渲染组件，
 * 否则组件容器会一直空白且没有任何提示，用户完全不知道发生了什么。
 * 回调挂在 window.turnstileReady 上，由 main.js 消费。
 */
function turnstile_head(): string
{
    if (turnstile_site_key() === '') {
        return '';
    }
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=turnstileReady&render=explicit" async defer></script>';
}

/**
 * 服务端校验令牌
 *
 * @return array{ok:bool,msg:string}
 */
function turnstile_verify(string $formId = 'submit'): array
{
    // 未配置密钥：跳过校验（其余防机器人层仍然生效）
    if (!turnstile_enabled()) {
        return ['ok' => true, 'msg' => ''];
    }

    $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'msg' => '请先完成人机验证（点击方框或勾选）'];
    }

    // 绑定来源 IP。Turnstile 官方建议传入，可防止令牌被跨站盗用。
    $payload = [
        'secret'   => turnstile_secret_key(),
        'response' => $token,
    ];
    $remoteip = client_ip();
    if ($remoteip !== '') {
        $payload['remoteip'] = $remoteip;
    }

    $ch = curl_init(TURNSTILE_VERIFY_URL);
    if ($ch === false) {
        return ['ok' => false, 'msg' => '验证服务暂不可用，请稍后重试'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err !== '') {
        app_error_log('[Turnstile] 网络异常: ' . $err);
        // 网络故障不是用户的错，明确区分，避免用户反复点验证码
        return ['ok' => false, 'msg' => '验证服务连接失败，请稍后重试（这不代表您操作有误）'];
    }
    if ($code !== 200) {
        app_error_log('[Turnstile] HTTP ' . $code . ' 响应: ' . substr((string)$body, 0, 200));
        return ['ok' => false, 'msg' => '验证服务返回异常，请稍后重试'];
    }

    $res = json_decode((string)$body, true);
    if (!is_array($res)) {
        app_error_log('[Turnstile] 响应无法解析: ' . substr((string)$body, 0, 200));
        return ['ok' => false, 'msg' => '验证服务响应异常，请稍后重试'];
    }

    if (empty($res['success'])) {
        $codes = (array)($res['error-codes'] ?? []);
        app_error_log('[Turnstile] 校验失败: ' . implode(',', $codes));
        $msg = in_array('timeout-or-duplicate', $codes, true)
            ? '验证已超时，请重新点击验证'
            : '人机验证未通过，请重试';
        return ['ok' => false, 'msg' => $msg];
    }

    // Cloudflare 的 action / cdata 是可选回显字段，用于二次核对
    return ['ok' => true, 'msg' => ''];
}

/**
 * 发送邮箱验证码前的防刷校验：蜜罐 + 频率限制
 *
 * 验证码接口是邮件系统的重灾区，攻击者可用它把邮箱打爆。
 * 这里不依赖浏览器环境，纯服务端计数。
 */
function email_code_rate_guard(string $email): array
{
    // 蜜罐字段，正常用户看不到
    if (post('hp_website') !== '' || post('hp_email') !== '') {
        return ['ok' => false, 'msg' => '提交异常，请重试'];
    }

    $ip = client_ip();
    $email = strtolower(trim($email));

    // 同一 IP：10 分钟内最多 5 次
    $ipCnt = (int)db_one(
        'SELECT COUNT(*) FROM ' . DB_PRE . 'email_code WHERE ip = ? AND created_at > ?',
        [$ip, date('Y-m-d H:i:s', time() - 600)],
        0
    );
    if ($ipCnt >= 5) {
        return ['ok' => false, 'msg' => '发送过于频繁，请 10 分钟后再试'];
    }

    // 同一邮箱：10 分钟内最多 3 次
    $mailCnt = (int)db_one(
        'SELECT COUNT(*) FROM ' . DB_PRE . 'email_code WHERE email = ? AND created_at > ?',
        [$email, date('Y-m-d H:i:s', time() - 600)],
        0
    );
    if ($mailCnt >= 3) {
        return ['ok' => false, 'msg' => '该邮箱已多次发送验证码，请 10 分钟后再试'];
    }

    return ['ok' => true, 'msg' => ''];
}

/**
 * 生成并写入验证码
 */
function email_code_create(string $email, string $scene = 'register'): string
{
    $code = (string)random_int(100000, 999999);
    $ip   = client_ip();

    db_query(
        'INSERT INTO ' . DB_PRE . 'email_code (email,code,scene,ip,expire_at,used) VALUES (?,?,?,?,?,0)',
        [$email, hash_hmac('sha256', $code, $GLOBALS['CFG']['app_key']), $scene, $ip, date('Y-m-d H:i:s', time() + 600)]
    );
    // 清理过期记录，避免表无限膨胀
    db_query('DELETE FROM ' . DB_PRE . 'email_code WHERE expire_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);

    return $code;
}

/**
 * 校验验证码（校验后立即作废，防重复使用）
 */
function email_code_verify(string $email, string $input, string $scene = 'register'): bool
{
    $email = strtolower(trim($email));
    $input = trim($input);
    if (!is_valid_email($email) || !ctype_digit($input) || strlen($input) !== 6) {
        return false;
    }

    $row = db_row(
        'SELECT * FROM ' . DB_PRE . 'email_code WHERE email = ? AND scene = ? ORDER BY id DESC LIMIT 1',
        [$email, $scene]
    );
    if (!$row) {
        return false;
    }
    if ((int)$row['used'] === 1) {
        return false;
    }
    if (strtotime((string)$row['expire_at']) < time()) {
        return false;
    }

    $expect = hash_hmac('sha256', $input, $GLOBALS['CFG']['app_key']);
    if (!hash_equals((string)$row['code'], $expect)) {
        return false;
    }

    // 校验通过后作废
    db_query('UPDATE ' . DB_PRE . 'email_code SET used = 1 WHERE id = ?', [(int)$row['id']]);
    return true;
}
