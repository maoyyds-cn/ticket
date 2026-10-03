<?php
/**
 * 邮件通知模块
 *
 * 两种发送通道：
 *   smtp —— 原生 fsockopen 实现，无需任何扩展，推荐使用
 *   mail —— PHP mail() 兜底，依赖服务器本机 MTA，多数云环境不可用
 *
 * 通道选择与凭据在后台「邮件通知设置」中配置。
 */
declare(strict_types=1);

/**
 * 单封邮件的总时间预算（秒）
 *
 * 为什么需要「总预算」而不仅是每步超时：一次 SMTP 会话有 11 个等待响应的
 * 步骤（220 / EHLO / STARTTLS / EHLO / AUTH×3 / MAIL FROM / RCPT TO / DATA /
 * 正文 250），每步各自的 stream_set_timeout 独立计时。服务器无响应或被
 * 限流时，每步都会耗满自己的超时，累加起来单封可达 200 秒以上；submit.php
 * 一次提交要发两封（用户确认 + 通知管理员），于是用户看到十几分钟的白屏。
 *
 * 15 秒对健康链路非常宽裕（正常 1~3 秒完成），足够覆盖慢速网络；
 * 超预算即放弃并记失败，不拖住页面。
 */
const MAIL_SMTP_BUDGET = 15;

/** 通道是否可用：SMTP 需 fsockopen，mail 需 mail() */
function mail_channel_ready(string $mode): bool
{
    return $mode === 'smtp' ? function_exists('fsockopen') : function_exists('mail');
}

function mail_mode(): string
{
    return ((string)setting('mail_mode', 'mail')) === 'smtp' ? 'smtp' : 'mail';
}

function mail_enabled(): bool
{
    return (int)setting('mail_enabled', '0') === 1;
}

/**
 * 待发邮件队列（本次请求内累积，请求结束后统一发出）
 *
 * 通知类邮件对用户是「迟一点也没关系」，但对页面是「等一秒都是损失」：
 * submit.php 一次提交要发两封，SMTP 不顺时每封最多耗掉整个预算。
 * 因此通知一律入队，等响应已经发给浏览器之后再发。
 *
 * 验证码（send-code.php）与后台测试邮件不走队列——那两处用户正在
 * 等结果，必须同步拿成败。
 *
 * @var list<array{0:string,1:string,2:string,3:int}>
 */
$GLOBALS['MAIL_QUEUE'] = [];

function mail_queue(string $to, string $subject, string $htmlBody, int $ticketId = 0): void
{
    $GLOBALS['MAIL_QUEUE'][] = [$to, $subject, $htmlBody, $ticketId];
}

/**
 * 把队列里的邮件发出去
 *
 * 由 shutdown 钩子在响应已经交给浏览器之后调用，因此这里的耗时
 * 用户感知不到，只占用 PHP 进程一小会儿。
 */
function mail_queue_flush(): void
{
    $queue = $GLOBALS['MAIL_QUEUE'] ?? [];
    if (!$queue) {
        return;
    }
    // 先清空，避免日志写入等操作再次触发钩子时无限重入
    $GLOBALS['MAIL_QUEUE'] = [];

    // 浏览器可能已经关闭连接；忽略中止让队列能发完。
    // 时限按队列长度给：每封最多 MAIL_SMTP_BUDGET 秒，再留 5 秒写日志。
    @ignore_user_abort(true);
    @set_time_limit(count($queue) * MAIL_SMTP_BUDGET + 5);

    foreach ($queue as [$to, $subject, $body, $tid]) {
        try {
            mail_send($to, $subject, $body, $tid);
        } catch (Throwable $e) {
            // 通知发不出去不能影响已完成的业务，也不能中断剩余队列
            app_error_log('[Mail] 队列发送异常：' . $e->getMessage());
        }
    }
}

/**
 * 把已经生成的响应内容交给浏览器
 *
 * 必须在 shutdown 阶段调用：此时业务代码已经结束、响应体已经完整，
 * 交出去之后继续连 SMTP 不会让用户多等。
 */
function mail_flush_response(): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    // mod_php / CLI 没有 fastcgi_finish_request，退而清空输出缓冲
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

/**
 * 发送邮件
 * @return array{ok:bool,msg:string}
 */
function mail_send(string $to, string $subject, string $htmlBody, ?int $ticketId = null): array
{
    $ticketId = $ticketId ?? 0;
    if (!is_valid_email($to)) {
        mail_log($ticketId, $to, $subject, 0, '收件人邮箱格式非法');
        return ['ok' => false, 'msg' => '收件人邮箱格式非法'];
    }

    $from = trim((string)setting('mail_from', ''));
    if ($from === '' || !is_valid_email($from)) {
        mail_log($ticketId, $to, $subject, 0, '发件人邮箱未配置或非法（后台 → 邮件通知设置）');
        return ['ok' => false, 'msg' => '发件人邮箱未配置'];
    }

    $fromName = (string)setting('mail_from_name', '工单中心');
    $body     = mail_wrap($subject, $htmlBody, $fromName);
    $mode     = mail_mode();

    if (!mail_channel_ready($mode)) {
        $msg = $mode === 'smtp' ? '服务器未启用 fsockopen，无法使用 SMTP 通道' : '服务器未启用 mail() 函数';
        mail_log($ticketId, $to, $subject, 0, $msg);
        return ['ok' => false, 'msg' => $msg];
    }

    $r = $mode === 'smtp'
        ? smtp_send($from, $fromName, $to, $subject, $body)
        : mail_send_native($from, $fromName, $to, $subject, $body);

    if (!$r['ok']) {
        mail_log($ticketId, $to, $subject, 0, $r['msg']);
        return $r;
    }
    mail_log($ticketId, $to, $subject, 1, '');
    return ['ok' => true, 'msg' => '发送成功'];
}

/**
 * mail() 兜底通道
 * @return array{ok:bool,msg:string}
 */
function mail_send_native(string $from, string $fromName, string $to, string $subject, string $htmlBody): array
{
    $enc = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $enc($fromName) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: TicketSystem/1.0',
    ];

    $ok = @mail($to, $enc($subject), $htmlBody, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        return ['ok' => false, 'msg' => 'mail() 返回 false：服务器未配置 MTA，建议改用 SMTP 通道'];
    }
    return ['ok' => true, 'msg' => '发送成功'];
}

/**
 * 原生 SMTP 客户端
 * @return array{ok:bool,msg:string}
 */
function smtp_send(string $from, string $fromName, string $to, string $subject, string $htmlBody): array
{
    $host = trim((string)setting('mail_smtp_host', ''));
    $port = (int)setting('mail_smtp_port', '465');
    $user = trim((string)setting('mail_smtp_user', ''));
    $pass = (string)setting('mail_smtp_pass', '');
    $sec  = (string)setting('mail_smtp_secure', 'ssl');

    if ($host === '') {
        return ['ok' => false, 'msg' => 'SMTP 服务器地址未配置'];
    }
    $port = $port > 0 ? $port : 465;
    $enc  = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';

    $hostName = preg_replace('/[^A-Za-z0-9.\-]/', '', $fromName) ?: 'MAILER';
    $msgId    = '<' . bin2hex(random_bytes(12)) . '@' . $host . '>';

    $headers = [
        'Date'         => date('r'),
        'From'         => $enc($fromName) . ' <' . $from . '>',
        'To'           => '<' . $to . '>',
        'Subject'      => $enc($subject),
        'Message-ID'   => $msgId,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'Reply-To'     => $from,
    ];

    $raw = '';
    foreach ($headers as $k => $v) {
        $raw .= $k . ': ' . $v . "\r\n";
    }
    $raw .= chunk_split(base64_encode($htmlBody), 76, "\r\n");

    $useSsl  = in_array($sec, ['ssl', 'tls'], true);
    $useImplicitTls = ($sec === 'ssl');   // 465 隐式 TLS

    // 会话级截止时间。fsockopen 的超时、每步的 stream_set_timeout、
    // 以及下面每个 expect 前的检查，都以它为准，任何一步都不许超出。
    $deadline = microtime(true) + MAIL_SMTP_BUDGET;
    $left     = static fn(): float => $deadline - microtime(true);

    $errno = 0;
    $errstr = '';
    // 连接超时也受预算约束：预算只剩 5 秒时不该再等满 15 秒
    $connectTimeout = (int)max(1, min(15, floor($left())));
    $fp = @fsockopen(
        $useImplicitTls ? 'ssl://' . $host : $host,
        $port,
        $errno,
        $errstr,
        $connectTimeout
    );
    if (!$fp) {
        return ['ok' => false, 'msg' => "无法连接 SMTP {$host}:{$port} — {$errstr} (errno {$errno})"];
    }

    $fail = static function (string $why) use ($fp): array {
        @fclose($fp);
        return ['ok' => false, 'msg' => $why];
    };

    /**
     * 读一行 SMTP 响应
     *
     * 每次 fgets 前把流超时压到「剩余预算」，于是总耗时被硬性封顶，
     * 而不是 11 个步骤各自耗满 stream_set_timeout。
     *
     * 只返回结果，不关连接——关闭统一由 $fail 负责，避免调用方
     * `return $fail($expect(...))` 时二次 fclose 触发 PHP 8 的 TypeError。
     */
    $readLine = static function ($fp, string $step) use ($left) {
        $remain = $left();
        if ($remain <= 0.2) {
            return ['ok' => false, 'msg' => "SMTP {$step} 超时：整封邮件耗时已超过 " . MAIL_SMTP_BUDGET . ' 秒预算'];
        }
        @stream_set_timeout($fp, (int)max(1, ceil($remain)));
        $l = @fgets($fp, 1024);
        if ($l === false) {
            $meta = stream_get_meta_data($fp);
            return ['ok' => false, 'msg' => !empty($meta['timed_out'])
                ? "SMTP {$step} 超时：剩余 " . (int)ceil($remain) . ' 秒内服务器无响应'
                : "SMTP {$step} 阶段连接中断"];
        }
        return ['ok' => true, 'line' => $l];
    };

    $expect = static function ($fp, array $codes, string $step) use ($readLine) {
        $line = '';
        while (true) {
            $r = $readLine($fp, $step);
            if (!$r['ok']) {
                return $r;
            }
            $l = $r['line'];
            $line = $l;
            // 末位字符为空格表示还有后续行
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($line, 0, 3);
        foreach ($codes as $c) {
            if ($code === $c) {
                return ['ok' => true, 'msg' => $line];
            }
        }
        return ['ok' => false, 'msg' => "SMTP {$step} 失败：{$line}"];
    };

    // 写入同样要受预算约束。fwrite 在阻塞式 socket 上遇到 TCP 零窗口
    // 会一直挂着，而写正文那一次（DATA 之后）可达数十 KB，是最容易卡住的地方。
    // stream_set_timeout 也会设 SO_SNDTIMEO，但它的值是上一次读时的剩余预算，
    // 不重新算就可能超发，所以这里每次写都重设一次。
    $cmd = static function ($fp, string $line) use ($left) {
        if ($left() <= 0.2) {
            return ['ok' => false, 'msg' => 'SMTP 写入超时：整封邮件耗时已超过 ' . MAIL_SMTP_BUDGET . ' 秒预算'];
        }

        // 必须循环补写。fwrite 只判断「是否返回 false」是不够的：
        // 套接字缓冲区满时 PHP 会提前返回，此时返回值是小于请求长度的
        // 正整数（部分写入），不等于 false，会被误判为成功。
        // DATA 之后那次写入可达数十 KB，一旦截断，服务器仍可能回 250，
        // 结果是邮件正文残缺而日志显示「发送成功」。
        $data = $line . "\r\n";
        $len  = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $remain = $left();
            if ($remain <= 0.2) {
                return ['ok' => false, 'msg' => 'SMTP 写入超时：整封邮件耗时已超过 ' . MAIL_SMTP_BUDGET . ' 秒预算'];
            }
            @stream_set_timeout($fp, (int)max(1, ceil($remain)));
            $n = @fwrite($fp, substr($data, $sent));
            if ($n === false || $n === 0) {
                return ['ok' => false, 'msg' => "SMTP 写入失败：{$line}"];
            }
            $sent += $n;
        }
        return ['ok' => true, 'msg' => ''];
    };

    // 写入后必须检查结果。原先 13 处调用都丢弃 $cmd 的返回值，
    // 写成「预算耗尽」也继续往下走，靠下一个 $expect 才偶然失败——
    // DATA 之后那次失败会丢掉整篇正文，日志里看不出是哪一步超的。
    // 统一用 $send 写：写不进去立刻结束会话。
    $send = static function ($fp, string $line) use ($cmd, $fail): array {
        $r = $cmd($fp, $line);
        return $r['ok'] ? ['ok' => true, 'msg' => ''] : $fail($r['msg']);
    };

    // 1. 握手
    $r = $expect($fp, [220], '连接');
    if (!$r['ok']) {
        return $fail($r['msg']);
    }

    $r = $send($fp, 'EHLO ' . $hostName);
    if (!$r['ok']) {
        return $r;
    }
    $r = $expect($fp, [250], 'EHLO');
    if (!$r['ok']) {
        // 部分服务器不支持 EHLO，退回 HELO
        $r = $send($fp, 'HELO ' . $hostName);
        if (!$r['ok']) {
            return $r;
        }
        $r = $expect($fp, [250], 'HELO');
        if (!$r['ok']) {
            return $fail($r['msg']);
        }
    }

    // 2. STARTTLS（隐式 SSL 模式跳过）
    if ($useSsl && !$useImplicitTls) {
        $r = $send($fp, 'STARTTLS');
        if (!$r['ok']) {
            return $r;
        }
        $r = $expect($fp, [220], 'STARTTLS');
        if (!$r['ok']) {
            return $fail($r['msg']);
        }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            return $fail('STARTTLS 加密协商失败，请确认服务器端口与加密方式（465/SSL 或 587/STARTTLS）是否匹配');
        }
        // 加密协商本身也消耗预算，且不受 stream_set_timeout 约束。
        // 这里是事后检查，只能记失败——PHP 的 TLS 握手有自己的超时，
        // 阻塞中的握手无法从外部中断，所以预算对这一步只是软上限。
        if ($left() <= 0.2) {
            return $fail('SMTP STARTTLS 超时：加密协商耗时已超过 ' . MAIL_SMTP_BUDGET . ' 秒预算');
        }
        // 加密后需重新握手
        $r = $send($fp, 'EHLO ' . $hostName);
        if (!$r['ok']) {
            return $r;
        }
        $r = $expect($fp, [250], 'EHLO(TLS)');
        if (!$r['ok']) {
            return $fail($r['msg']);
        }
    }

    // 3. 认证
    if ($user !== '') {
        $authUser = base64_encode($user);
        $authPass = base64_encode($pass);

        // 优先尝试 AUTH LOGIN
        $r = $send($fp, 'AUTH LOGIN');
        if (!$r['ok']) {
            return $r;
        }
        $r = $expect($fp, [334], 'AUTH LOGIN');
        if ($r['ok']) {
            $r = $send($fp, $authUser);
            if (!$r['ok']) {
                return $r;
            }
            $r = $expect($fp, [334], 'AUTH 用户名');
            if ($r['ok']) {
                $r = $send($fp, $authPass);
                if (!$r['ok']) {
                    return $r;
                }
                $r = $expect($fp, [235], 'AUTH 密码');
                if (!$r['ok']) {
                    return $fail('认证失败：' . $r['msg'] . '（请确认授权码/密码是否正确）');
                }
            } else {
                return $fail($r['msg']);
            }
        } else {
            // 回退 PLAIN
            $r = $send($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
            if (!$r['ok']) {
                return $r;
            }
            $r = $expect($fp, [235], 'AUTH PLAIN');
            if (!$r['ok']) {
                return $fail('认证失败：' . $r['msg'] . '（请确认授权码/密码是否正确）');
            }
        }
    }

    // 4. 发信
    $r = $send($fp, 'MAIL FROM:<' . $from . '>');
    if (!$r['ok']) {
        return $r;
    }
    $r = $expect($fp, [250], 'MAIL FROM');
    if (!$r['ok']) {
        return $fail($r['msg']);
    }

    $r = $send($fp, 'RCPT TO:<' . $to . '>');
    if (!$r['ok']) {
        return $r;
    }
    $r = $expect($fp, [250, 251], 'RCPT TO');
    if (!$r['ok']) {
        return $fail($r['msg'] . '（收件人被服务器拒绝，请检查地址是否被拉黑）');
    }

    $r = $send($fp, 'DATA');
    if (!$r['ok']) {
        return $r;
    }
    $r = $expect($fp, [354], 'DATA');
    if (!$r['ok']) {
        return $fail($r['msg']);
    }

    // 邮件正文中的行首点需转义，防止提前结束 DATA。
    // ?? $raw 是必需的：preg_replace 失败返回 null，(string)null 得空串，
    // 会把含全部邮件头的 $raw 一起清空，发出空正文还可能被服务器判成功。
    $body = preg_replace('/^\./m', '..', $raw) ?? $raw;
    $r = $send($fp, rtrim((string)$body, "\r\n") . "\r\n.");
    if (!$r['ok']) {
        return $r;
    }
    $r = $expect($fp, [250], '正文发送');
    if (!$r['ok']) {
        return $fail($r['msg']);
    }

    $cmd($fp, 'QUIT');
    @fclose($fp);
    return ['ok' => true, 'msg' => '发送成功'];
}

/**
 * 检测 SMTP 连通性（后台测试连接使用）
 * @return array{ok:bool,msg:string}
 */
function smtp_probe(): array
{
    $host = trim((string)setting('mail_smtp_host', ''));
    if ($host === '') {
        return ['ok' => false, 'msg' => 'SMTP 服务器地址未配置'];
    }
    $port = (int)setting('mail_smtp_port', '465');
    $port = $port > 0 ? $port : 465;
    $sec  = (string)setting('mail_smtp_secure', 'ssl');

    $implicit = ($sec === 'ssl');
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($implicit ? 'ssl://' . $host : $host, $port, $errno, $errstr, 10);
    if (!$fp) {
        // 区分常见原因，便于直接对症处理
        $hint = match ((int)$errno) {
            110      => '连接超时：该端口从本服务器出站被阻断。云厂商普遍封禁 25 端口，建议改用 465 或 587。',
            11       => '连接超时：请确认目标主机在线，或该端口被防火墙拦截。',
            111      => '连接被拒绝：目标端口未开放或服务器拒绝对该 IP 访问。',
            113      => '网络不可达：请检查服务器防火墙与出站规则。',
            default  => '请确认地址、端口与加密方式是否匹配（465=SSL / 587=STARTTLS / 25=明文）。',
        };
        return ['ok' => false, 'msg' => "无法连接 {$host}:{$port} — {$errstr}（errno {$errno}）。{$hint}"];
    }
    stream_set_timeout($fp, 10);
    $banner = fgets($fp, 1024);
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);

    if ($banner === false) {
        return ['ok' => false, 'msg' => '服务器无响应（非 SMTP 服务？）'];
    }
    if (substr($banner, 0, 3) !== '220') {
        return ['ok' => false, 'msg' => '端口可达但非 SMTP 服务：' . trim($banner)];
    }
    return ['ok' => true, 'msg' => "连接成功 {$host}:{$port}（" . trim($banner) . '）'];
}

function mail_wrap(string $title, string $content, string $siteName): string
{
    $site = e(setting('site_name', $siteName));
    $year = date('Y');
    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Microsoft YaHei,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 12px;"><tr><td align="center">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);">'
        . '<tr><td style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:26px 32px;">'
        . '<div style="color:#fff;font-size:18px;font-weight:700;">' . $site . '</div>'
        . '<div style="color:#e0e7ff;font-size:13px;margin-top:6px;">' . e($title) . '</div></td></tr>'
        . '<tr><td style="padding:30px 32px;font-size:14px;line-height:1.85;color:#374151;">' . $content . '</td></tr>'
        . '<tr><td style="background:#f9fafb;padding:18px 32px;font-size:12px;color:#9ca3af;text-align:center;">'
        . '© ' . $year . ' ' . $site . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_log(int $ticketId, string $to, string $subject, int $status, string $error): void
{
    try {
        db_insert('INSERT INTO ' . DB_PRE . 'mail_log (ticket_id, to_email, subject, status, error) VALUES (?,?,?,?,?)',
            [$ticketId, $to, mb_substr($subject, 0, 190), $status, mb_substr($error, 0, 480)]);
    } catch (Throwable $e) {
        // 记录失败不影响主流程
    }
}

/**
 * 新工单通知管理员
 */
function mail_notify_admins(array $ticket): void
{
    if (!mail_enabled()) {
        return;
    }
    $to = trim((string)setting('mail_admin_to', ''));
    if ($to === '' || !is_valid_email($to)) {
        return;
    }
    $link = site_url('admin/ticket-view.php?id=' . (int)$ticket['id']);
    $body = '<p>后台收到新工单，请及时处理。</p>'
        . '<div style="background:#f9fafb;padding:14px 16px;margin:16px 0;border-radius:8px">'
        . '<p style="margin:0 0 6px"><strong>编号：</strong>' . e((string)$ticket['ticket_no']) . '</p>'
        . '<p style="margin:0 0 6px"><strong>标题：</strong>' . e((string)$ticket['title']) . '</p>'
        . '<p style="margin:0 0 6px"><strong>分类：</strong>' . e((string)($ticket['category_name'] ?? '—')) . '</p>'
        . '<p style="margin:0 0 6px"><strong>优先级：</strong>' . e(priority_meta((string)$ticket['priority'])['label']) . '</p>'
        . '<p style="margin:0"><strong>提交邮箱：</strong>' . e((string)$ticket['contact_email']) . '</p>'
        . '</div>'
        . '<p style="margin:20px 0"><a href="' . e($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:11px 26px;border-radius:8px;font-weight:600;">进入后台处理</a></p>';
    mail_queue(
        $to,
        '【新工单】' . (string)($ticket['ticket_no'] ?? '') . ' ' . (string)($ticket['title'] ?? ''),
        $body,
        (int)($ticket['id'] ?? 0)
    );
}

/**
 * 工单进展通知（统一入口）
 * @param array  $ticket 工单行
 * @param string $event  事件标识：created / reply / status / closed
 */
function mail_notify_ticket(array $ticket, string $event, string $extra = ''): void
{
    if (!mail_enabled()) {
        return;
    }
    $email = trim((string)($ticket['contact_email'] ?? ''));
    if ($email === '' || !is_valid_email($email)) {
        return;
    }
    $tpl = (string)setting('mail_tpl_' . $event, '');
    if ($tpl === '') {
        return;
    }

    $no    = (string)($ticket['ticket_no'] ?? '');
    $title = (string)($ticket['title'] ?? '');
    // 邮件里不放访问密钥：用户的密钥由其本人保管，随邮件外发会在转发中泄露。
    // 收件人凭工单编号 + 自己的密钥即可查看。
    $link  = site_url('ticket-view.php?no=' . urlencode($no));

    $map = [
        '{no}'        => e($no),
        '{title}'     => e($title),
        '{link}'      => '<a href="' . e($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:11px 26px;border-radius:8px;font-weight:600;">查看工单详情</a>',
        '{hint}'      => '打开链接后，如提示需要验证，请输入工单编号 <b>' . e($no) . '</b> 和你提交时设置的访问密钥。',
        '{time}'      => date('Y-m-d H:i'),
        '{site}'      => e(setting('site_name', '工单中心')),
        '{status}'    => e(status_meta((string)($ticket['status'] ?? ''))['label']),
        '{content}'   => $extra,
    ];
    $subject = strtr((string)setting('mail_subject_' . $event, '工单有新进展：' . $no), $map);
    $body    = strtr($tpl, $map);

    mail_queue($email, $subject, $body, (int)($ticket['id'] ?? 0));
}
