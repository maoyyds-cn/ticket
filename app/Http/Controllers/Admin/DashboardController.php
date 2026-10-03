<?php

/**
 * 后台概览
 *
 * 旧版这个页面自己发了十次 COUNT/AVG 查询，另加一次在分类循环里的
 * 逐条 COUNT（N+1）。这里全部走 TicketService::overviewStats()，
 * 一共两次聚合查询，并且与前台首页共用同一口径——
 * 旧版首页与后台各算一套「解决率」，两个数字对不上。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\Mailer;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketService;
use App\Domain\Ticket\TicketStatus;

final class DashboardController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly TicketService $tickets,
        private readonly Mailer $mailer,
        private readonly Database $db,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $staff = $this->staff();
        $stats = $this->tickets->overviewStats();
        $repo = $this->tickets->repository();

        // 最新工单：最多 8 条
        $latest = $repo->paginate([], 8, 0, 't.created_at DESC');

        // 我的待办：指派给我的未完结工单优先，其次是未指派的
        $todo = $this->tickets->todoFor($staff->id, 6);

        // 未指派且未完结的数量，概览页要给出明确入口
        $unassigned = $stats['unassigned'];

        // 近 24 小时的邮件失败提醒。
        // 邮件发不出去时用户不会来投诉「我没收到邮件」，他只会以为问题没人管，
        // 所以这件事必须由后台主动暴露出来，而不是等管理员去翻发送记录。
        $mailWarning = '';
        $mailReady = $this->mailer->isConfigured();
        if ($mailReady) {
            $failed = $this->db->int(
                'SELECT COUNT(*) FROM `mail_log` WHERE `ok` = 0 AND `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            );
            if ($failed > 0) {
                $lastError = (string)$this->db->value(
                    'SELECT `error` FROM `mail_log` WHERE `ok` = 0 ORDER BY `id` DESC LIMIT 1',
                    [],
                    ''
                );
                $mailWarning = '过去 24 小时有 ' . $failed . ' 封通知邮件发送失败。'
                    . ($lastError !== '' ? '最近一次原因：' . mb_substr($lastError, 0, 160) : '');
            }
        } else {
            $mailWarning = '邮件通道当前不可用或未开启，工单通知不会发出。';
        }

        return $this->admin('admin/dashboard', [
            'pageTitle' => '概览',
            'pageDesc' => '站点运行状况与待处理工作',
            'activeNav' => 'dashboard',
            'stats' => $stats,
            'latest' => $latest,
            'todo' => $todo,
            'unassigned' => $unassigned,
            'staff' => $staff,
            'statusOptions' => TicketStatus::options(),
            'mailWarning' => $mailWarning,
            'mailReady' => $mailReady,
        ]);
    }
}
