<?php

/**
 * 邮件服务
 *
 * 三类职责，集中在一处：通道选择（SMTP / mail()）、队列发送、通知模板。
 *
 * 关于队列：v1 也用「响应发给浏览器后再发信」的思路，方向是对的，
 * 但队列只存在进程内存里，worker 被杀或部署重启就静默丢信，且没有任何记录。
 * 这里同样在响应后发送（避免用户对着白屏等 SMTP），但每封信无论成败都会
 * 写入 mail_log，因此「没收到通知」这件事至少是可追溯的。
 *
 * 关键取舍：邮件失败绝不影响业务结果。工单已经落库、日志已经写好，
 * 发信失败只记录、不抛错——通知是副产物，不该让用户看到失败页面。
 */
declare(strict_types=1);

namespace App\Domain\Mail;

use App\Core\Database;
use App\Core\View;
use App\Domain\Setting\Settings;
use Throwable;

final class Mailer
{
    /** @var list<array{to:string,toName:string,subject:string,html:string,text:string,ticketId:int}> */
    private array $queue = [];

    private bool $flushed = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly Database $db,
        private readonly View $view,
    ) {
    }

    // ---------------------------------------------------------------
    // 配置读取
    // ---------------------------------------------------------------

    private function enabled(): bool
    {
        return $this->settings->bool('mail_enabled', false);
    }

    private function fromAddress(): string
    {
        $from = trim($this->settings->string('mail_from', ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            return $from;
        }
        // 回退到 SMTP 账号：多数邮箱服务要求发件人与认证账号一致
        $user = trim($this->settings->string('mail_user', ''));
        return filter_var($user, FILTER_VALIDATE_EMAIL) !== false ? $user : '';
    }

    private function fromName(): string
    {
        $name = trim($this->settings->string('mail_from_name', ''));
        return $name !== '' ? $name : $this->settings->string('site_name', '工单中心');
    }

    private function transport(): string
    {
        $mode = $this->settings->string('mail_transport', 'smtp');
        if ($mode === 'mail') {
            return function_exists('mail') ? 'mail' : 'smtp';
        }
        return $mode === 'smtp' ? 'smtp' : 'mail';
    }

    /** 通道是否可用（后台页面上用于显示「当前是否真的能发信」） */
    public function isConfigured(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if ($this->fromAddress() === '') {
            return false;
        }
        if ($this->transport() === 'smtp') {
            return $this->settings->string('mail_host', '') !== '';
        }
        return function_exists('mail');
    }

    /** 给后台展示的状态摘要 */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'transport' => $this->transport(),
            'from' => $this->fromAddress(),
            'configured' => $this->isConfigured(),
            'note' => !$this->enabled()
                ? '邮件通知未开启'
                : ($this->fromAddress() === ''
                    ? '未填写发件人邮箱'
                    : ($this->transport() === 'smtp' && $this->settings->string('mail_host', '') === ''
                        ? '未配置 SMTP 服务器'
                        : '配置看起来完整')),
        ];
    }

    // ---------------------------------------------------------------
    // 队列
    // ---------------------------------------------------------------

    /**
     * 入队一封信，返回是否已入队（未入队表示通道不可用，不是错误）。
     */
    public function queue(string $to, string $subject, string $html, string $text = '', string $toName = '', int $ticketId = 0): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            // 非法收件人直接记一笔，不必等到发信时才失败
            $this->log($ticketId, $to, $subject, false, '收件人地址不合法');
            return false;
        }

        $prefix = trim($this->settings->string('mail_subject_prefix', ''));
        if ($prefix !== '') {
            $subject = $prefix . ' ' . $subject;
        }

        $this->queue[] = [
            'to' => $to,
            'toName' => $toName,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'ticketId' => $ticketId,
        ];
        return true;
    }

    public function hasQueued(): bool
    {
        return $this->queue !== [];
    }

    /**
     * 发送队列里的全部邮件。
     *
     * 由 shutdown 钩子在响应输出之后调用；也供后台「测试发送」手动调用。
     *
     * @return array{sent:int,failed:int,errors:list<string>}
     */
    public function flush(): array
    {
        if ($this->flushed || $this->queue === []) {
            $this->flushed = true;
            return ['sent' => 0, 'failed' => 0, 'errors' => []];
        }
        $this->flushed = true;

        $pending = $this->queue;
        $this->queue = [];

        // 发信可能较慢，且发生在响应之后：即使客户端已断开也应该发完
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $mail) {
            $error = $this->deliver($mail);
            if ($error === null) {
                $sent++;
                $this->log($mail['ticketId'], $mail['to'], $mail['subject'], true, '');
            } else {
                $failed++;
                $errors[] = $mail['to'] . '：' . $error;
                $this->log($mail['ticketId'], $mail['to'], $mail['subject'], false, $error);
                \App\Core\Application::log('[MAIL] 发送失败 ' . $mail['to'] . '：' . $error, 'mail.log');
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 同步发送一封（后台测试用），返回错误信息或 null。
     *
     * @param array{to:string,toName:string,subject:string,html:string,text:string,ticketId:int} $mail
     */
    private function deliver(array $mail): ?string
    {
        $from = $this->fromAddress();
        if ($from === '') {
            return '未配置发件人邮箱';
        }

        try {
            if ($this->transport() === 'smtp') {
                $secure = $this->settings->string('mail_secure', 'ssl');
                $transport = new SmtpTransport(
                    host: trim($this->settings->string('mail_host', '')),
                    port: $this->settings->int('mail_port', 465),
                    secure: in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl',
                    username: trim($this->settings->string('mail_user', '')),
                    password: (string)$this->settings->string('mail_pass', ''),
                    timeoutSeconds: 15,
                    verifyPeer: $this->settings->bool('mail_verify_peer', true),
                );
                $transport->send([
                    'from' => $from,
                    'fromName' => $this->fromName(),
                    'to' => $mail['to'],
                    'toName' => $mail['toName'],
                    'subject' => $mail['subject'],
                    'html' => $mail['html'],
                    'text' => $mail['text'],
                ]);
                return null;
            }

            return $this->deliverWithMailFunction($from, $mail);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** @param array{to:string,toName:string,subject:string,html:string,text:string} $mail */
    private function deliverWithMailFunction(string $from, array $mail): ?string
    {
        $boundary = 'tk' . bin2hex(random_bytes(8));
        $headers = [
            'MIME-Version: 1.0',
            'From: =?UTF-8?B?' . base64_encode($this->fromName()) . '?= <' . $from . '>',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Roblox-Ticket-System',
        ];
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . ($mail['text'] !== '' ? $mail['text'] : strip_tags($mail['html'])) . "\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . $mail['html'] . "\r\n"
            . '--' . $boundary . "--\r\n";

        $subject = '=?UTF-8?B?' . base64_encode($mail['subject']) . '?=';
        $to = $mail['toName'] !== ''
            ? '=?UTF-8?B?' . base64_encode($mail['toName']) . '?= <' . $mail['to'] . '>'
            : $mail['to'];

        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        return $ok ? null : 'mail() 返回失败（服务器可能未安装 MTA）';
    }

    private function log(int $ticketId, string $to, string $subject, bool $ok, string $error): void
    {
        try {
            $this->db->query(
                'INSERT INTO `mail_log` (`ticket_id`, `to_email`, `subject`, `ok`, `error`)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $ticketId,
                    mb_substr($to, 0, 120),
                    mb_substr($subject, 0, 200),
                    $ok ? 1 : 0,
                    mb_substr($error, 0, 500),
                ]
            );
        } catch (Throwable) {
            // 记日志失败绝不能影响发信流程本身
        }
    }

    // ---------------------------------------------------------------
    // 通知
    // ---------------------------------------------------------------

    /**
     * 组装一封通知：渲染邮件模板并决定收件人。
     *
     * @param array<string,string> $vars
     */
    private function notify(array $ticket, string $template, array $vars): void
    {
        $to = trim((string)($ticket['contact_email'] ?? ''));
        if ($to === '') {
            return;
        }

        $vars['site'] = $this->settings->string('site_name', '工单中心');
        $vars['no'] = (string)($ticket['ticket_no'] ?? '');
        $vars['title'] = (string)($ticket['title'] ?? '');
        $vars['status'] = (string)($ticket['status'] ?? '');
        $vars['time'] = date('Y-m-d H:i');

        // 这几个键是所有邮件模板共用的「可选段落」，缺省补齐。
        // 不补的话，状态变更邮件会因为模板里引用 content 而报
        // 「Undefined array key」，日志被刷满警告（实际发生过）。
        //
        // 注意 plain 不在这里：它是「渲染 HTML 版还是纯文本版」的开关，
        // 一旦被补成空串，$vars + ['plain' => false] 就无法覆盖它
        //（+ 运算符不会覆盖已存在的键），结果是每个邮件模板的纯文本分支
        // 都失效，text/plain 部分塞进的是 HTML 源码。
        foreach (['content', 'link', 'key', 'has_key', 'from_status', 'to_status'] as $key) {
            $vars[$key] = (string)($vars[$key] ?? '');
        }

        try {
            $html = $this->view->partial('emails/' . $template, $vars + ['plain' => false]);
            $text = $this->view->partial('emails/' . $template, $vars + ['plain' => true]);
        } catch (Throwable $e) {
            \App\Core\Application::log('[MAIL] 模板渲染失败 ' . $template . '：' . $e->getMessage(), 'mail.log');
            return;
        }

        $subject = (string)($vars['subject'] ?? ('【' . $vars['site'] . '】工单 ' . $vars['no']));
        $this->queue($to, $subject, $html, $text, (string)($ticket['guest_name'] ?? ''), (int)($ticket['id'] ?? 0));
    }

    /**
     * 顺便通知管理员邮箱（有新工单时）。
     */
    private function notifyAdmin(array $ticket): void
    {
        $adminTo = trim($this->settings->string('mail_admin_to', ''));
        if ($adminTo === '' || filter_var($adminTo, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            $html = $this->view->partial('emails/admin_new_ticket', [
                'no' => (string)$ticket['ticket_no'],
                'title' => (string)$ticket['title'],
                'content' => (string)$ticket['content'],
                'category' => (string)($ticket['category_name'] ?? '未分类'),
                'priority' => \App\Domain\Ticket\TicketPriority::label((string)$ticket['priority']),
                'contact' => (string)$ticket['contact_email'],
                'site' => $this->settings->string('site_name', '工单中心'),
                'time' => date('Y-m-d H:i'),
            ]);
        } catch (Throwable $e) {
            \App\Core\Application::log('[MAIL] 管理端模板渲染失败：' . $e->getMessage(), 'mail.log');
            return;
        }
        $this->queue(
            $adminTo,
            '【新工单】' . (string)$ticket['ticket_no'] . ' ' . mb_substr((string)$ticket['title'], 0, 40),
            $html,
            strip_tags($html),
            '',
            (int)$ticket['id']
        );
    }

    /**
     * 工单提交成功通知（用户 + 管理员）。
     *
     * @param array<string,mixed> $ticket
     */
    public function notifyTicketCreated(array $ticket, string $accessKey = ''): void
    {
        $link = $this->ticketLink($ticket, $accessKey);
        $this->notify($ticket, 'ticket_created', [
            'subject' => '【' . $this->settings->string('site_name', '工单中心') . '】工单已提交：' . (string)$ticket['ticket_no'],
            'link' => $link,
            'has_key' => $accessKey !== '' ? '1' : '',
            'key' => $accessKey,
            'content' => (string)$ticket['content'],
        ]);
        $this->notifyAdmin($ticket);
    }

    /**
     * 客服回复通知用户。
     *
     * @param array<string,mixed> $ticket
     */
    public function notifyTicketReplied(array $ticket, string $replyContent): void
    {
        $this->notify($ticket, 'ticket_replied', [
            'subject' => '【' . $this->settings->string('site_name', '工单中心') . '】工单有新回复：' . (string)$ticket['ticket_no'],
            'link' => $this->ticketLink($ticket),
            'content' => $replyContent,
        ]);
    }

    /**
     * 状态变更通知用户。
     *
     * @param array<string,mixed> $ticket
     */
    public function notifyTicketStatus(array $ticket, string $fromLabel, string $toLabel): void
    {
        $this->notify($ticket, 'ticket_status', [
            'subject' => '【' . $this->settings->string('site_name', '工单中心') . '】工单状态更新：' . (string)$ticket['ticket_no'],
            'link' => $this->ticketLink($ticket),
            'from_status' => $fromLabel,
            'to_status' => $toLabel,
        ]);
    }

    /**
     * 低分评价提醒管理员。
     *
     * @param array<string,mixed> $ticket
     */
    public function notifyLowRating(array $ticket, int $rating, string $note): void
    {
        $adminTo = trim($this->settings->string('mail_admin_to', ''));
        if ($adminTo === '' || filter_var($adminTo, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            $html = $this->view->partial('emails/admin_low_rating', [
                'no' => (string)$ticket['ticket_no'],
                'title' => (string)$ticket['title'],
                'rating' => (string)$rating,
                'note' => $note,
                'site' => $this->settings->string('site_name', '工单中心'),
                'time' => date('Y-m-d H:i'),
            ]);
        } catch (Throwable) {
            return;
        }
        $this->queue($adminTo, '【低分评价】' . (string)$ticket['ticket_no'] . ' 获得 ' . $rating . ' 星', $html, strip_tags($html), '', (int)$ticket['id']);
    }

    /**
     * 邮箱验证码。
     */
    public function sendVerificationCode(string $to, string $code, string $scene): bool
    {
        try {
            $html = $this->view->partial('emails/verify_code', [
                'code' => $code,
                'scene' => $scene,
                'site' => $this->settings->string('site_name', '工单中心'),
                'minutes' => '10',
            ]);
        } catch (Throwable $e) {
            \App\Core\Application::log('[MAIL] 验证码模板渲染失败：' . $e->getMessage(), 'mail.log');
            return false;
        }
        $subject = '【' . $this->settings->string('site_name', '工单中心') . '】邮箱验证码';
        $queued = $this->queue($to, $subject, $html, "你的验证码是 {$code}，10 分钟内有效。", '', 0);
        if ($queued) {
            // 验证码是用户正在等待的东西，不能等到响应之后才发
            $this->flush();
        }
        return $queued;
    }

    /**
     * 后台「发送测试邮件」，同步执行并把结果直接返回给页面。
     *
     * @return array{ok:bool,message:string}
     */
    public function sendTest(string $to): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => '邮件通知未开启，请先在上方开启并保存。'];
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => '收件人邮箱格式不正确。'];
        }
        $from = $this->fromAddress();
        if ($from === '') {
            return ['ok' => false, 'message' => '未填写有效的发件人邮箱。'];
        }

        $mail = [
            'to' => $to,
            'toName' => '',
            'subject' => '【' . $this->settings->string('site_name', '工单中心') . '】测试邮件',
            'html' => '<p>这是一封测试邮件。</p><p>收到它说明当前的邮件通道配置可以正常工作。</p>'
                . '<p style="color:#64748b;font-size:13px">发件人：' . e($from)
                . '　通道：' . e($this->transport()) . '</p>',
            'text' => "这是一封测试邮件。\n收到它说明当前的邮件通道配置可以正常工作。\n发件人：{$from}",
            'ticketId' => 0,
        ];

        $error = $this->deliver($mail);
        if ($error === null) {
            $this->log(0, $to, $mail['subject'], true, '');
            return ['ok' => true, 'message' => '测试邮件已发送到 ' . $to . '，请查收（也看看垃圾箱）。'];
        }
        $this->log(0, $to, $mail['subject'], false, $error);
        return ['ok' => false, 'message' => '发送失败：' . $error];
    }

    /**
     * 生成工单链接。
     *
     * site_url 未配置时回退到当前请求的 Host——这与 v1 的行为一致，
     * 但 v1 没有对 Host 做任何约束。这里额外用配置项 site_url 作为首选，
     * 并在后台设置页提示管理员显式填写，避免邮件链接被 Host 头左右。
     *
     * @param array<string,mixed> $ticket
     */
    private function ticketLink(array $ticket, string $accessKey = ''): string
    {
        $base = rtrim($this->settings->string('site_url', ''), '/');
        if ($base === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            // Host 头只允许合法域名形态，防止换行/路径注入进邮件正文
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . $host;
        }

        $url = $base . '/ticket/' . rawurlencode((string)$ticket['ticket_no']);
        if ($accessKey !== '') {
            $url .= '?key=' . rawurlencode($accessKey);
        }
        return $url;
    }
}
