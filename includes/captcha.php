<?php
/**
 * 防机器人验证
 * 四层防护：算术验证码 + 蜜罐字段 + 最短停留计时 + IP 频率限制
 * 不依赖 GD 扩展，算术题以富文本形式渲染，机器难以解析 DOM 语义。
 */
declare(strict_types=1);

/**
 * 生成算术验证码，返回 HTML 片段
 */
function captcha_html(): string
{
    $ops = ['+', '-', '×'];
    $op = $ops[random_int(0, 2)];
    $a  = random_int(2, 19);
    $b  = random_int(2, 9);
    if ($op === '-' && $b > $a) {
        [$a, $b] = [$b, $a];
    }
    $ans = $op === '+' ? $a + $b : ($op === '-' ? $a - $b : $a * $b);

    // 答案与表单 token 绑定，token 由服务端签名校验
    $formToken = hash('sha256', $GLOBALS['CFG']['app_key'] . '|' . bin2hex(random_bytes(8)));
    $_SESSION['captcha'] = [
        'hash'  => hash_hmac('sha256', (string)$ans, $GLOBALS['CFG']['app_key'] . $formToken),
        'exp'   => time() + 600,
        'tries' => 0,
    ];

    $html  = '<div class="captcha-box" data-captcha>';
    $html .= '<span class="captcha-q" aria-label="验证码题目">';
    $html .= '<span class="cn">' . $a . '</span><span class="cop">' . $op . '</span><span class="cn">' . $b . '</span>';
    $html .= '<span class="cop">=</span><span class="cn q">?</span>';
    $html .= '</span>';
    $html .= '<input type="text" name="captcha" class="input captcha-input" inputmode="numeric" maxlength="4" placeholder="答案" autocomplete="off" required>';
    $html .= '<button type="button" class="captcha-refresh" title="看不清？点击换一个" data-captcha-refresh>↻</button>';
    $html .= '<input type="hidden" name="captcha_token" value="' . e($formToken) . '">';
    $html .= '</div>';
    return $html;
}

/**
 * 校验验证码
 */
function captcha_verify(): bool
{
    $c   = $_SESSION['captcha'] ?? null;
    $ans = post('captcha');
    if (!is_array($c) || $ans === '' || !ctype_digit($ans)) {
        return false;
    }
    if ($c['exp'] < time()) {
        unset($_SESSION['captcha']);
        return false;
    }
    $c['tries']++;
    if ($c['tries'] > 3) {
        unset($_SESSION['captcha']);
        return false;
    }
    $_SESSION['captcha'] = $c;

    $formToken = (string)($_POST['captcha_token'] ?? '');
    $expect    = hash_hmac('sha256', (string)(int)$ans, $GLOBALS['CFG']['app_key'] . $formToken);
    return hash_equals($c['hash'], $expect);
}

/**
 * 防机器人综合校验
 * @return array{ok:bool,msg:string}
 */
function antibot_check(string $formId = 'submit'): array
{
    // 1) 蜜罐：正常用户看不到该字段
    if (post('hp_website') !== '' || post('hp_email') !== '') {
        return ['ok' => false, 'msg' => '提交异常，请重试'];
    }

    // 2) 计时陷阱：页面打开不足 3 秒直接判定为脚本
    $opened = (int)($_SESSION['form_opened'][$formId] ?? 0);
    if ($opened > 0 && (time() - $opened) < 3) {
        return ['ok' => false, 'msg' => '操作过快，请确认内容后重新提交'];
    }

    // 3) 人机验证：已配置 Turnstile 时用它，未配置则退回算术验证码。
    //    两条路径都保留，避免未配置密钥时游客完全无法提交工单。
    if (turnstile_enabled()) {
        $ts = turnstile_verify($formId);
        if (!$ts['ok']) {
            return ['ok' => false, 'msg' => $ts['msg']];
        }
    } elseif (!captcha_verify()) {
        return ['ok' => false, 'msg' => '验证码错误或已过期，请重新计算'];
    }

    // 4) IP 频率限制
    $ip    = client_ip();
    $limit = max(0, setting_int('antibot_rate_limit', 5));
    if ($limit > 0) {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $cnt   = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE ip = ? AND created_at > ?', [$ip, $since], 0);
        if ($cnt >= $limit) {
            return ['ok' => false, 'msg' => '您提交工单过于频繁（每小时最多 ' . $limit . ' 个），请稍后再试'];
        }
    }

    unset($_SESSION['form_opened'][$formId]);
    return ['ok' => true, 'msg' => ''];
}

/**
 * 访客追加回复的防滥用：蜜罐 + 会话级提交间隔限制
 * 不做验证码，避免正常用户频繁回复时被打扰
 */
function reply_rate_guard(int $ticketId): bool
{
    if (post('hp_website') !== '' || post('hp_email') !== '') {
        flash('error', '提交异常，请重试');
        return false;
    }

    $key = 'reply_ts_' . $ticketId;
    $now = time();
    // 同一会话对同一工单，20 秒内只允许提交一次
    if (isset($_SESSION[$key]) && ($now - (int)$_SESSION[$key]) < 20) {
        app_error_log('[Reply] 会话频率拦截: ticket=' . $ticketId
            . ' 距上次 ' . ($now - (int)$_SESSION[$key]) . ' 秒');
        flash('error', '提交过于频繁，请 20 秒后再试');
        return false;
    }
    $_SESSION[$key] = $now;

    // 同一 IP 对同一工单 1 小时内最多 10 条，防止脚本灌水
    $cnt = (int)db_one(
        'SELECT COUNT(*) FROM ' . DB_PRE . 'ticket_reply WHERE ip = ? AND ticket_id = ? AND created_at > ?',
        [client_ip(), $ticketId, date('Y-m-d H:i:s', $now - 3600)],
        0
    );
    if ($cnt >= 10) {
        app_error_log('[Reply] IP 频率拦截: ticket=' . $ticketId . ' ip=' . client_ip() . ' 1h内已有 ' . $cnt . ' 条');
        flash('error', '该工单回复过于频繁，请稍后再试');
        return false;
    }
    return true;
}

/**
 * 记录表单打开时间（渲染表单时调用）
 */
function antibot_mark_open(string $formId = 'submit'): void
{
    $_SESSION['form_opened'][$formId] = time();
}
