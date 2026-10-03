<?php

/**
 * 后台工单管理
 *
 * 与旧版的关键差别：
 *  - 筛选条件与导出共用 TicketRepository::paginate/exportRows，
 *    因此不可能出现「导出的行数比列表少」这种两边筛选逻辑不同步的问题
 *    （旧版 export.php 里有一句注释承认了要手工保持同步）；
 *  - 批量操作只对真正被修改的行写日志。旧版对提交上来的每个 id 都写一条
 *    「批量关闭」日志，即使那行因为状态条件没被更新，审计记录因此是假的；
 *  - 删除工单前先删数据库、后删附件文件。旧版顺序相反，
 *    一旦数据库删除失败就会留下「记录在、文件没了」的破损工单。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;
use App\Domain\Staff\Role;
use App\Domain\Ticket\TicketPriority;
use App\Domain\Ticket\TicketService;
use App\Domain\Ticket\TicketStatus;
use App\Support\Uploader;

final class TicketController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly TicketService $tickets,
        private readonly Uploader $uploader,
        private readonly Database $db,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    /**
     * 工单列表。
     */
    public function index(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        $perPage = 20;
        $page = max(1, $request->queryInt('page', 1));

        $result = $this->tickets->paginate($filters, $page, $perPage);
        $staff = $this->staff();

        return $this->admin('admin/tickets', [
            'pageTitle' => '工单管理',
            'pageDesc' => '共 ' . $result['total'] . ' 条符合条件的工单',
            'activeNav' => 'tickets',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => (int)max(1, (int)ceil($result['total'] / $perPage)),
            'filters' => $filters,
            'statusCounts' => $this->tickets->repository()->countByStatus(),
            'allCount' => $this->tickets->repository()->countAll(),
            'categories' => $this->db->all('SELECT `id`, `name`, `icon` FROM `category` WHERE `is_ticket` = 1 ORDER BY `sort` DESC'),
            'assignees' => $this->db->all('SELECT `id`, `username`, `realname` FROM `staff` WHERE `status` = 1 ORDER BY `role` DESC, `id` ASC'),
            'statusOptions' => TicketStatus::options(),
            'priorityOptions' => TicketPriority::options(),
            'canExport' => $staff->can('ticket.export'),
            'activeStatus' => $request->query('status'),
            // 模板里要用它判断「标记垃圾」等按钮是否显示。
            // 漏传会导致模板里 $staff 未定义并直接 500（实际发生过）。
            'staff' => $staff,
        ]);
    }

    /**
     * 批量操作。
     */
    public function bulk(Request $request): Response
    {
        $action = $request->post('act');
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)($request->body()['ids'] ?? [])),
            static fn(int $i): bool => $i > 0
        ))) ;

        if ($ids === []) {
            $this->session()->flash('warn', '请先勾选要操作的工单。');
            return Response::redirect($this->backTo($request, '/admin/tickets'));
        }
        // 批量接口不设上限时，构造一个大数组就能让 IN(...) 变得很长
        if (count($ids) > 200) {
            $this->session()->flash('warn', '一次最多操作 200 条工单，请分批处理。');
            return Response::redirect($this->backTo($request, '/admin/tickets'));
        }

        $staff = $this->staff();
        $affected = 0;
        $message = '';

        switch ($action) {
            case 'processing':
            case 'resolved':
            case 'closed':
                if (!$staff->can('ticket.status')) {
                    $this->session()->flash('error', '你没有修改工单状态的权限。');
                    return Response::redirect($this->backTo($request, '/admin/tickets'));
                }
                // 逐条走服务层，保证状态机的合法性校验与派生字段
                // （resolved_at / closed_at）都被正确维护
                foreach ($ids as $id) {
                    $ticket = $this->tickets->findById($id);
                    if ($ticket === null) {
                        continue;
                    }
                    if ($this->tickets->changeStatus($ticket, $action)->ok) {
                        $affected++;
                    }
                }
                $message = '已更新 ' . $affected . ' 条工单为「' . TicketStatus::label($action) . '」';
                break;

            case 'spam':
                if (!$staff->can('ticket.spam')) {
                    $this->session()->flash('error', '你没有标记垃圾工单的权限。');
                    return Response::redirect($this->backTo($request, '/admin/tickets'));
                }
                foreach ($ids as $id) {
                    $ticket = $this->tickets->findById($id);
                    if ($ticket !== null && $this->tickets->changeStatus($ticket, TicketStatus::SPAM)->ok) {
                        $affected++;
                    }
                }
                $message = '已标记 ' . $affected . ' 条工单为垃圾工单';
                break;

            case 'assign':
                if (!$staff->can('ticket.assign')) {
                    $this->session()->flash('error', '你没有指派工单的权限。');
                    return Response::redirect($this->backTo($request, '/admin/tickets'));
                }
                $assignee = $request->postInt('assignee_id');
                // 指派目标必须是存在且启用的账号，否则会留下悬空的处理人
                if ($assignee > 0) {
                    $exists = $this->db->int('SELECT COUNT(*) FROM `staff` WHERE `id` = ? AND `status` = 1', [$assignee]);
                    if ($exists === 0) {
                        $this->session()->flash('error', '所选处理人不存在或已停用。');
                        return Response::redirect($this->backTo($request, '/admin/tickets'));
                    }
                }
                foreach ($ids as $id) {
                    $ticket = $this->tickets->findById($id);
                    if ($ticket !== null && $this->tickets->assign($ticket, $assignee)->ok) {
                        $affected++;
                    }
                }
                $message = $assignee > 0
                    ? '已指派 ' . $affected . ' 条工单'
                    : '已取消 ' . $affected . ' 条工单的指派';
                break;

            default:
                $this->session()->flash('error', '未知的批量操作。');
                return Response::redirect($this->backTo($request, '/admin/tickets'));
        }

        // 只报真实影响的行数：旧版无论是否更新成功都提示成功，
        // 于是「操作成功」和「什么都没发生」在界面上无法区分
        $this->session()->flash($affected > 0 ? 'ok' : 'warn', $affected > 0 ? $message : '没有工单被修改（可能状态不允许该操作）。');
        return Response::redirect($this->backTo($request, '/admin/tickets'));
    }

    /**
     * 工单详情。
     */
    public function show(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }

        // 已读标记只在后台真正打开详情时写入。
        //
        // 旧版把它挂在 GET 上（前台详情页也会置已读），于是爬虫和链接预取
        // 会把工单标成「已读」。现在挂在后台详情页，但**仍然要排除预取**：
        // 导航预取会在鼠标划过列表时就抓取每个链接，那样一划就把整页标成已读，
        // 「新」标记悄无声息地全部消失。
        if (!$request->isPassiveFetch()) {
            $this->tickets->markRead($ticket);
            $ticket['is_read'] = 1;
        }

        $staff = $this->staff();
        $replies = $this->tickets->replies((int)$ticket['id'], true);

        return $this->admin('admin/ticket', [
            'pageTitle' => '工单 ' . (string)$ticket['ticket_no'],
            'pageDesc' => (string)$ticket['title'],
            'activeNav' => 'tickets',
            'ticket' => $ticket,
            'replies' => $replies,
            'attachments' => $this->tickets->attachmentsOf($ticket['attachments'] ?? null),
            'logs' => $this->tickets->logs((int)$ticket['id'], 40),
            'assignees' => $this->db->all('SELECT `id`, `username`, `realname`, `role` FROM `staff` WHERE `status` = 1 ORDER BY `role` DESC, `id` ASC'),
            'categories' => $this->db->all('SELECT `id`, `name`, `icon` FROM `category` WHERE `is_ticket` = 1 ORDER BY `sort` DESC'),
            'statusOptions' => TicketStatus::options(),
            'priorityOptions' => TicketPriority::options(),
            'nextStates' => TicketStatus::nextStates((string)$ticket['status']),
            'canDelete' => $staff->can('ticket.delete'),
            'canAssign' => $staff->can('ticket.assign'),
            'staff' => $staff,
            'uploadMaxMb' => $this->settings->int('upload_max_mb', 10),
            'uploadMaxCount' => $this->settings->int('upload_max_count', 5),
        ]);
    }

    /**
     * 回复 / 内部备注。
     */
    public function reply(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }

        $back = $this->backTo($request, '/admin/tickets/' . (int)$ticket['id']);
        $staff = $this->staff();

        $isInternal = $request->postBool('is_internal');
        if ($isInternal && !$staff->can('ticket.internal_note')) {
            $this->session()->flash('error', '你没有添加内部备注的权限。');
            return Response::redirect($back);
        }

        $content = $request->post('content');
        if (mb_strlen(trim($content), 'UTF-8') < 2) {
            $this->session()->flash('error', '回复内容不能为空。');
            return Response::redirect($back);
        }

        $attachments = $this->uploader->save(
            $request->files('files'),
            $this->settings->int('upload_max_count', 5),
            $this->settings->int('upload_max_mb', 10)
        );

        // 内部备注不改变工单状态，也不通知用户
        $newStatus = $isInternal ? null : $request->post('new_status');

        // 让服务层知道这是内部备注：通过请求级属性传入，
        // 避免在服务签名里再加一个只用于内部备注的布尔参数到处传递
        $ticket['_internal'] = $isInternal;

        $result = $this->tickets->reply($ticket, $content, $attachments, $newStatus);
        if (!$result->ok) {
            $this->uploader->delete($attachments);
            $this->session()->flash('error', $result->error);
            return Response::redirect($back);
        }

        foreach ($this->uploader->errors() as $uploadError) {
            $this->session()->flash('warn', '附件提示：' . $uploadError);
        }
        $this->session()->flash('ok', $isInternal ? '内部备注已添加（用户不可见）。' : '回复已发送。');
        return Response::redirect($back);
    }

    /**
     * 修改优先级 / 处理人 / 分类。
     */
    public function attributes(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }
        $back = $this->backTo($request, '/admin/tickets/' . (int)$ticket['id']);

        $messages = [];

        $priority = $request->post('priority');
        if ($priority !== '' && $priority !== (string)$ticket['priority']) {
            $result = $this->tickets->setPriority($ticket, $priority);
            if (!$result->ok) {
                $this->session()->flash('error', $result->error);
                return Response::redirect($back);
            }
            $messages[] = '优先级已更新';
            $ticket = $this->tickets->findById((int)$ticket['id']) ?? $ticket;
        }

        // 处理人：0 表示取消指派，也必须显式允许
        if ($request->has('assignee_id')) {
            $assignee = $request->postInt('assignee_id');
            if ($assignee !== (int)$ticket['assignee_id']) {
                $result = $this->tickets->assign($ticket, $assignee);
                if (!$result->ok) {
                    $this->session()->flash('error', $result->error);
                    return Response::redirect($back);
                }
                $messages[] = $assignee > 0 ? '处理人已更新' : '已取消指派';
            }
        }

        // 分类：允许设置为 0（未分类）。旧版表单里有「未分类」选项，
        // 但处理逻辑对 0 直接报「所选分类不存在」，导致这个选项永远选不中。
        if ($request->has('category_id')) {
            $categoryId = $request->postInt('category_id');
            if ($categoryId !== (int)$ticket['category_id']) {
                if ($categoryId > 0) {
                    $ok = $this->db->int('SELECT COUNT(*) FROM `category` WHERE `id` = ?', [$categoryId]) > 0;
                    if (!$ok) {
                        $this->session()->flash('error', '所选分类不存在。');
                        return Response::redirect($back);
                    }
                }
                $this->tickets->repository()->update((int)$ticket['id'], ['category_id' => $categoryId]);
                $staff = $this->staff();
                $this->tickets->repository()->insertLog(
                    (int)$ticket['id'],
                    $staff->id,
                    $staff->displayName(),
                    'set_category',
                    $categoryId > 0 ? '调整了工单分类' : '取消工单分类',
                    $request->ip()
                );
                $messages[] = '分类已更新';
            }
        }

        $this->session()->flash($messages === [] ? 'info' : 'ok', $messages === [] ? '没有需要更新的内容。' : implode('，', $messages) . '。');
        return Response::redirect($back);
    }

    /**
     * 变更状态（快捷按钮）。
     */
    public function status(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }
        $back = $this->backTo($request, '/admin/tickets/' . (int)$ticket['id']);

        $target = $request->post('status');
        if ($target === TicketStatus::SPAM && !$this->staff()->can('ticket.spam')) {
            $this->session()->flash('error', '你没有标记垃圾工单的权限。');
            return Response::redirect($back);
        }

        $result = $this->tickets->changeStatus($ticket, $target);
        $this->session()->flash($result->ok ? 'ok' : 'error', $result->ok
            ? '状态已改为「' . TicketStatus::label($target) . '」。'
            : $result->error);
        return Response::redirect($back);
    }

    /**
     * 撤回访客访问密钥。
     *
     * 提示文案必须是「撤销」而不是旧版那句「用户需重新提交工单才能凭密钥查看」——
     * 重新提交是一个新工单，不可能恢复对旧工单的访问，那句话会让人误以为
     * 有挽回余地。
     */
    public function revokeKey(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }
        $back = $this->backTo($request, '/admin/tickets/' . (int)$ticket['id']);

        if ((string)$ticket['access_hash'] === '') {
            $this->session()->flash('warn', '该工单本来就没有设置访问密钥。');
            return Response::redirect($back);
        }

        $this->tickets->repository()->update((int)$ticket['id'], ['access_hash' => '']);
        $staff = $this->staff();
        $this->tickets->repository()->insertLog(
            (int)$ticket['id'],
            $staff->id,
            $staff->displayName(),
            'revoke_key',
            '撤销了访客访问密钥（该密钥立即失效且无法恢复）',
            $request->ip()
        );
        $this->session()->flash('ok', '访问密钥已撤销。持旧密钥的访客将无法再查看该工单。');
        return Response::redirect($back);
    }

    /**
     * 删除工单（仅超管）。
     */
    public function destroy(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }

        $result = $this->tickets->delete($ticket);
        $this->session()->flash($result->ok ? 'ok' : 'error', $result->ok
            ? '工单 ' . (string)$ticket['ticket_no'] . ' 已删除。'
            : $result->error);
        return Response::redirect(url('/admin/tickets'));
    }

    /**
     * 删除单条回复。
     */
    public function destroyReply(Request $request): Response
    {
        $ticket = $this->tickets->findById((int)$request->attribute('id', 0));
        if ($ticket === null) {
            $this->session()->flash('error', '工单不存在或已被删除。');
            return Response::redirect(url('/admin/tickets'));
        }
        $back = $this->backTo($request, '/admin/tickets/' . (int)$ticket['id']);

        $result = $this->tickets->deleteReply($ticket, (int)$request->attribute('replyId', 0));
        $this->session()->flash($result->ok ? 'ok' : 'error', $result->ok ? '该条回复已删除。' : $result->error);
        return Response::redirect($back);
    }

    /**
     * 导出 CSV。
     *
     * 修复两个旧版问题：
     *  - 导出与列表用同一份筛选逻辑，不会再出现导出行数少于筛选结果；
     *  - 公式注入防护不再依赖带 /u 的 preg_match（非法 UTF-8 会让它返回
     *    false 从而被判定为安全）。这里逐字节检查首个非空白字符。
     */
    public function export(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        $rows = $this->tickets->exportRows($filters, 50000);

        $staff = $this->staff();
        \App\Core\Application::log(
            '[EXPORT] staff_id=' . $staff->id . ' rows=' . count($rows) . ' filters=' . json_encode($filters, JSON_UNESCAPED_UNICODE),
            'security.log'
        );

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            $this->session()->flash('error', '导出失败，请稍后重试。');
            return Response::redirect(url('/admin/tickets'));
        }

        // UTF-8 BOM：没有它 Excel 打开中文会乱码
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['工单编号', '标题', '分类', '状态', '优先级', '提交邮箱', 'QQ', '处理人', '回复数', '评分', '创建时间', '更新时间', '解决时间'], ',', '"', '\\');

        foreach ($rows as $r) {
            fputcsv($fh, array_map([$this, 'csvCell'], [
                (string)$r['ticket_no'],
                (string)$r['title'],
                (string)($r['category_name'] ?? '未分类'),
                TicketStatus::label((string)$r['status']),
                TicketPriority::label((string)$r['priority']),
                (string)$r['contact_email'],
                (string)$r['qq'],
                (string)($r['assignee_name'] ?? ''),
                (string)$r['replies'],
                (int)$r['rating'] > 0 ? (string)(int)$r['rating'] : '',
                (string)$r['created_at'],
                (string)$r['updated_at'],
                (string)($r['resolved_at'] ?? ''),
            ]), ',', '"', '\\');
        }

        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);

        $filename = 'tickets-' . date('Ymd-His') . '.csv';
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * CSV 单元格转义。
     *
     * 以 = + - @ 或制表符/回车开头的值会被 Excel/Sheets 当作公式执行，
     * 因此前面补一个单引号。注意用字节级判断而不是带 /u 的正则——
     * 后者遇到非法 UTF-8 会返回 false，反而把危险值放行。
     */
    private function csvCell(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * 从请求中提取筛选条件（列表与导出共用）。
     *
     * @return array<string,mixed>
     */
    private function filtersFrom(Request $request): array
    {
        $statusRaw = $request->query('status');
        $statuses = $statusRaw === '' ? [] : array_values(array_filter(
            explode(',', $statusRaw),
            [TicketStatus::class, 'exists']
        ));

        $priorityRaw = $request->query('priority');
        $priorities = $priorityRaw === '' ? [] : array_values(array_filter(
            explode(',', $priorityRaw),
            [TicketPriority::class, 'exists']
        ));

        // 处理人：-1 是「只看未指派」的特殊值。
        // 必须把它与普通 id 分开：两者若同时进筛选条件，会拼出
        // `assignee_id = -1 AND assignee_id = 0` 这种永假条件。
        $assignee = $request->queryInt('assignee');
        $unassigned = $assignee === -1;

        // 日期必须校验格式再进 SQL。
        // 未校验时 MySQL 5.7（宽松）会把 'abc 00:00:00' 静默当成空值，
        // 筛选悄悄失效；而 8.0.19+ 会直接报 1525 让页面 500。
        // 这里统一在应用层挡掉，行为与版本无关。
        $from = $this->validDate($request->query('from'));
        $to = $this->validDate($request->query('to'));

        return [
            'status' => $statuses,
            'priority' => $priorities,
            'category_id' => $request->queryInt('cat'),
            'assignee_id' => $unassigned ? 0 : max(0, $assignee),
            'unassigned' => $unassigned,
            'q' => $request->query('q'),
            'created_from' => $from,
            'created_to' => $to,
        ];
    }

    /**
     * 只接受真实存在的 YYYY-MM-DD，其余当未填处理。
     *
     * 实现集中在 Validator::date()：只匹配形状是不够的，
     * `2024-02-31` 这种「形状合法但日子不存在」的值会让
     * MySQL 8.0.19+ 报错把页面打成 500，而 5.7 只是静默筛不出东西。
     */
    private function validDate(string $value): string
    {
        return \App\Support\Validator::date($value);
    }
}
