<?php

/**
 * 操作日志（审计）
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Application;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketService;

final class LogController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly TicketService $tickets,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'action' => $request->query('action'),
            'q' => $request->query('q'),
            'ticket_no' => $request->query('no'),
            'created_from' => $this->validDate($request->query('from')),
            'created_to' => $this->validDate($request->query('to')),
        ];

        $perPage = 40;
        $page = max(1, $request->queryInt('page', 1));
        $total = $this->tickets->auditLogCount($filters);
        $totalPages = (int)max(1, (int)ceil($total / $perPage));
        // 页码越界时收敛到最后一页：否则 ?page=999 会渲染出
        // 「没有符合条件的记录」这种与事实不符的空状态，而且分页器
        // 恰好在这个分支里不渲染，用户只能自己改地址才能回来
        $page = min($page, $totalPages);
        $rows = $this->tickets->auditLogs($filters, $perPage, ($page - 1) * $perPage);

        return $this->admin('admin/logs', [
            'pageTitle' => '操作日志',
            'pageDesc' => '共 ' . $total . ' 条记录',
            'activeNav' => 'logs',
            'rows' => $rows,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'actionTypes' => $this->tickets->actionTypes(),
            'actionLabels' => self::actionLabels(),
        ]);
    }

    /**
     * 只接受真实存在的 YYYY-MM-DD，其余当未填处理。
     *
     * 与后台工单列表共用 Validator::date()，不再各写一份——
     * 两份实现意味着两处可能不一致，而这类校验的不一致最难发现。
     */
    private function validDate(string $value): string
    {
        return \App\Support\Validator::date($value);
    }

    /**
     * 清理旧日志。
     *
     * 旧版这里是个隐患：days 缺失或为 0 时会执行一条没有 WHERE 的
     * DELETE FROM ticket_log，把唯一的审计线索整表清空——而删除动作本身
     * 又不留任何记录。这里要求显式选择保留天数，且最小 7 天。
     */
    public function prune(Request $request): Response
    {
        $keepDays = $request->postInt('keep_days');
        if (!in_array($keepDays, [30, 90, 180], true)) {
            $this->session()->flash('error', '请选择要保留的日志范围（30 / 90 / 180 天）。');
            return Response::redirect(url('/admin/logs'));
        }

        $deleted = $this->tickets->pruneLogs($keepDays);
        Application::log(
            '[ADMIN] 清理操作日志 keep_days=' . $keepDays . ' deleted=' . $deleted
            . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        $this->session()->flash('ok', '已清理 ' . $deleted . ' 条 ' . $keepDays . ' 天前的日志记录。');
        return Response::redirect(url('/admin/logs'));
    }

    /** @return array<string,string> 动作名到中文说明 */
    public static function actionLabels(): array
    {
        return [
            'create' => '提交工单',
            'reply' => '回复工单',
            'internal_note' => '添加内部备注',
            'change_status' => '变更状态',
            'assign' => '指派处理人',
            'set_priority' => '调整优先级',
            'set_category' => '调整分类',
            'revoke_key' => '撤销访问密钥',
            'rate' => '用户评价',
            'user_close' => '用户关闭',
            'user_reopen' => '用户重开',
            'delete_reply' => '删除回复',
            'delete' => '删除工单',
        ];
    }
}
