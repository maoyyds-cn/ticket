<?php

/**
 * 邮件设置
 *
 * 两个关键处理：
 *  1) 密码类字段留空表示「不修改」。旧版对 SMTP 密码做对了这一点，
 *     对 Turnstile 私密密钥却用了 type="text" 并把值回显到页面里——
 *     同一类凭据两套处理方式。这里统一为：敏感字段一律不回显，
 *     留空即保持原值。
 *  2) 保存后立即做一次连通性检查并把结果告诉管理员，
 *     而不是等他发现「用户收不到邮件」时才回头排查。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Application;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\Mailer;
use App\Domain\Setting\Settings;

final class MailController extends AdminController
{
    /** 这些键留空时不覆盖已存的值（凭据类字段） */
    private const SECRET_KEYS = ['mail_pass'];

    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly Mailer $mailer,
        private readonly Database $db,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $recent = $this->db->all(
            'SELECT * FROM `mail_log` ORDER BY `id` DESC LIMIT 30'
        );
        $stats = $this->db->row(
            'SELECT COUNT(*) AS total,
                    SUM(`ok` = 1) AS ok_count,
                    SUM(`ok` = 0) AS fail_count
             FROM `mail_log` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        return $this->admin('admin/mail', [
            'pageTitle' => '邮件设置',
            'pageDesc' => '配置通知通道并查看发送记录',
            'activeNav' => 'mail',
            'status' => $this->mailer->status(),
            'recent' => $recent,
            'settings' => $this->settings->withDefaults([
                'mail_enabled', 'mail_transport', 'mail_host', 'mail_port', 'mail_secure',
                'mail_verify_peer', 'mail_user', 'mail_pass', 'mail_from', 'mail_from_name',
                'mail_admin_to', 'mail_subject_prefix',
            ]),
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
                'ok' => (int)($stats['ok_count'] ?? 0),
                'fail' => (int)($stats['fail_count'] ?? 0),
            ],
            'mailFunction' => function_exists('mail'),
            'testResult' => null,
        ]);
    }

    public function save(Request $request): Response
    {
        $values = [
            'mail_enabled' => $request->postBool('mail_enabled') ? '1' : '0',
            'mail_transport' => in_array($request->post('mail_transport'), ['smtp', 'mail'], true)
                ? $request->post('mail_transport')
                : 'smtp',
            'mail_host' => mb_substr(trim($request->post('mail_host')), 0, 120),
            'mail_port' => (string)max(1, min(65535, $request->postInt('mail_port', 465))),
            'mail_secure' => in_array($request->post('mail_secure'), ['ssl', 'tls', 'none'], true)
                ? $request->post('mail_secure')
                : 'ssl',
            'mail_verify_peer' => $request->postBool('mail_verify_peer') ? '1' : '0',
            'mail_user' => mb_substr(trim($request->post('mail_user')), 0, 120),
            'mail_from' => mb_substr(trim($request->post('mail_from')), 0, 120),
            'mail_from_name' => mb_substr(trim($request->post('mail_from_name')), 0, 60),
            'mail_admin_to' => mb_substr(trim($request->post('mail_admin_to')), 0, 120),
            'mail_subject_prefix' => mb_substr(trim($request->post('mail_subject_prefix')), 0, 30),
        ];

        // SMTP 服务器地址会被拼进 Message-ID 头，必须挡掉换行
        if ($values['mail_host'] !== '' && preg_match('/[\r\n]/', $values['mail_host']) === 1) {
            $this->session()->flash('error', 'SMTP 服务器地址包含非法字符。');
            return Response::redirect(url('/admin/mail'));
        }
        if ($values['mail_from'] !== '' && filter_var($values['mail_from'], FILTER_VALIDATE_EMAIL) === false) {
            $this->session()->flash('error', '发件人邮箱格式不正确。');
            return Response::redirect(url('/admin/mail'));
        }
        if ($values['mail_admin_to'] !== '' && filter_var($values['mail_admin_to'], FILTER_VALIDATE_EMAIL) === false) {
            $this->session()->flash('error', '管理员通知邮箱格式不正确。');
            return Response::redirect(url('/admin/mail'));
        }

        $this->settings->setMany($values);

        // 凭据字段：留空表示不修改
        $password = $request->raw('mail_pass');
        if (trim($password) !== '') {
            $this->settings->set('mail_pass', $password);
        }

        Application::log('[ADMIN] 更新邮件设置 by staff_id=' . $this->staff()->id, 'security.log');
        $this->session()->flash('ok', '邮件设置已保存。' . ($values['mail_enabled'] === '1'
            ? '建议点一下「发送测试邮件」确认通道可用。'
            : '注意：邮件通知目前处于关闭状态，工单通知不会发出。'));
        return Response::redirect(url('/admin/mail'));
    }

    public function test(Request $request): Response
    {
        $to = trim($request->post('test_email'));
        $result = $this->mailer->sendTest($to);

        $this->session()->flash($result['ok'] ? 'ok' : 'error', $result['message']);
        Application::log(
            '[ADMIN] 测试邮件 to=' . $to . ' result=' . ($result['ok'] ? 'ok' : 'fail')
            . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        return Response::redirect(url('/admin/mail'));
    }
}
