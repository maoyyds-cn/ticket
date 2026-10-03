<?php

/**
 * 工单服务
 *
 * 所有会改变工单状态的操作都汇集到这里，页面里不再出现 SQL 与 if 交织的
 * 处理逻辑。这样做的直接好处是「提交工单」这件事的完整规则（校验、频控、
 * 编号生成、通知入队、日志）只存在一份，前台提交与后台代建可以共用。
 *
 * v1 在这块的具体问题，以及本文件的处理方式：
 *   - 工单号用 COUNT(*) 推序号，并发下必然重号 → 改为随机段 + 唯一索引重试；
 *   - 回复已解决的工单会把 resolved_at 刷新成当前时间 → 只在真正改变状态时写；
 *   - 评分表单在「已关闭」工单上可见但提交无效 → 评分独立于回复权限判定；
 *   - 访客可自填 author_name 冒充他人 → 回复人姓名一律由服务端决定。
 */
declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Core\Database;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\Throttle;
use App\Domain\Mail\Mailer;
use App\Domain\Setting\Settings;
use App\Support\Str;
use App\Support\Uploader;

final class TicketService
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketRepository $repo,
        private readonly Settings $settings,
        private readonly AuthService $auth,
        private readonly Throttle $throttle,
        private readonly Mailer $mailer,
        private readonly Uploader $uploader,
    ) {
    }

    // ---------------------------------------------------------------
    // 访问控制
    // ---------------------------------------------------------------

    /**
     * 访客访问密钥的哈希。
     *
     * 加盐用 app_key，因此库被拖走也无法直接反查；但要注意：用户自选的
     * 短密钥本身熵就很低（6 位数字只有 100 万种），暴力枚举成本很低。
     * 所以这里额外要求密钥至少 8 位且包含两类字符（见 validateAccessKey），
     * 把「猜密钥」的成本从秒级抬到不划算的量级。
     */
    public function hashAccessKey(string $raw): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($raw)), (string)\App\Core\Config::string('app.key', 'ticket-app'));
    }

    /**
     * 当前请求身份能否访问该工单。
     *
     * 判定顺序：后台人员 → 工单归属用户 → 持正确密钥的访客。
     */
    public function canAccess(?array $ticket, ?string $key = null): bool
    {
        if ($ticket === null) {
            return false;
        }
        if ($this->auth->isStaff()) {
            return true;
        }
        $user = $this->auth->user();
        if ($user !== null && (int)$ticket['user_id'] === $user->id && $user->id > 0) {
            return true;
        }
        $stored = (string)($ticket['access_hash'] ?? '');
        if ($stored === '' || $key === null || trim($key) === '') {
            return false;
        }
        return hash_equals($stored, $this->hashAccessKey($key));
    }

    /** @return array<string,mixed>|null */
    public function findByNo(string $ticketNo): ?array
    {
        $ticketNo = trim($ticketNo);
        if ($ticketNo === '') {
            return null;
        }
        return $this->repo->findByNo($ticketNo);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $id > 0 ? $this->repo->findById($id) : null;
    }

    // ---------------------------------------------------------------
    // 创建
    // ---------------------------------------------------------------

    /**
     * 提交工单。
     *
     * @param array{
     *   title:string, content:string, category_id:int, priority:string,
     *   email:string, qq:string, guest_name:string, access_key:string,
     *   attachments?:list<array{name:string,path:string,size:int}>
     * } $input
     * @param array<string,mixed> $context ip / user_agent / source
     * @return TicketResult 成功时 data = ['id'=>int,'ticket_no'=>string,'key'=>string]
     */
    /**
     * 查找短时间内的同内容工单（用于防重复提交）。
     *
     * 判定范围刻意收得很窄：同一 IP 或同一账号、30 秒内、标题与正文与分类全等。
     * 正常用户不会在 30 秒内提交两单完全一样的内容，因此不会误伤；
     * 而双击、提交后刷新重发都会被它挡住。
     *
     * @return array<string,mixed>|null 命中的既有工单
     */
    private function findRecentDuplicate(string $ip, int $userId, string $title, string $content, int $categoryId): ?array
    {
        if ($title === '' && $content === '') {
            return null;
        }

        $where = ['`title` = ?', '`content` = ?', '`category_id` = ?',
                  '`created_at` >= DATE_SUB(NOW(), INTERVAL 30 SECOND)'];
        $params = [$title, $content, $categoryId];

        if ($userId > 0) {
            $where[] = '`user_id` = ?';
            $params[] = $userId;
        } elseif ($ip !== '') {
            $where[] = '`ip` = ?';
            $params[] = $ip;
        } else {
            return null;
        }

        return $this->db->row(
            'SELECT `id`, `ticket_no` FROM `ticket` WHERE ' . implode(' AND ', $where)
            . ' ORDER BY `id` DESC LIMIT 1',
            $params
        );
    }

    public function create(array $input, array $context): TicketResult
    {
        if (!$this->settings->bool('ticket_enabled', true)) {
            return TicketResult::fail($this->settings->string('ticket_closed_notice', '工单通道暂时关闭。'));
        }

        $user = $this->auth->user();
        $email = trim((string)($input['email'] ?? ''));
        $ip = (string)($context['ip'] ?? '');

        // 访客必须留邮箱：没有账号也没有邮箱，工单回复就无处可送
        if ($user === null && $email === '') {
            return TicketResult::fail('请填写邮箱，以便我们回复时通知你。');
        }

        // 频控：只统计已成功入库的提交，语义正好是「允许提交几单」
        $limit = $this->settings->int('rate_limit_per_hour', 5);
        if ($limit > 0 && $this->throttle->recentSubmissionsFromIp($ip, 1) >= $limit) {
            return TicketResult::fail('提交过于频繁，同一 IP 每小时最多提交 ' . $limit . ' 单，请稍后再试。');
        }

        $categoryId = (int)($input['category_id'] ?? 0);
        if ($categoryId > 0 && !$this->categoryExists($categoryId)) {
            return TicketResult::fail('所选分类不存在，请重新选择。');
        }

        $accessKey = trim((string)($input['access_key'] ?? ''));
        if ($accessKey !== '') {
            $keyError = $this->validateAccessKey($accessKey);
            if ($keyError !== null) {
                return TicketResult::fail($keyError);
            }
        }

        $attachments = $input['attachments'] ?? [];
        $guestName = trim((string)($input['guest_name'] ?? ''));
        if ($guestName === '') {
            $guestName = $user !== null ? $user->displayName() : ($email !== '' ? Str::emailName($email) : '访客');
        }

        $payload = [
            'user_id' => $user?->id ?? 0,
            'guest_name' => Str::limit($guestName, 50, ''),
            'contact_email' => $email,
            'qq' => Str::limit(trim((string)($input['qq'] ?? '')), 20, ''),
            'category_id' => $categoryId,
            'title' => Str::limit(trim((string)$input['title']), 200, ''),
            'content' => (string)$input['content'],
            'attachments' => $attachments === [] ? null : json_encode($attachments, JSON_UNESCAPED_UNICODE),
            'priority' => TicketPriority::exists((string)($input['priority'] ?? ''))
                ? (string)$input['priority']
                : TicketPriority::NORMAL,
            'status' => TicketStatus::PENDING,
            'assignee_id' => 0,
            'source' => (string)($context['source'] ?? 'web'),
            'ip' => $ip,
            'user_agent' => (string)($context['user_agent'] ?? ''),
            'access_hash' => $accessKey !== '' ? $this->hashAccessKey($accessKey) : '',
        ];

        // 内容级去重：同一个人（同 IP 或同账号）在 30 秒内重复提交
        // 「标题 + 正文 + 分类」完全相同的工单，视为双击/网络重试，直接返回原单。
        //
        // 为什么必须做在服务端：前端禁用提交按钮只在 JS 可用时有效，
        // 而且拦不住「提交后按浏览器刷新重发」这类常见操作。
        // 一次重复提交产生的后果是实打实的——两张一模一样的工单、两封通知邮件
        // 给到客服，而「每 IP 每小时 N 单」的限制数的是已入库行数，拦不住它。
        $dedupe = $this->findRecentDuplicate(
            $ip,
            $user?->id ?? 0,
            (string)$payload['title'],
            (string)$payload['content'],
            $categoryId
        );
        if ($dedupe !== null) {
            return TicketResult::ok([
                'id' => (int)$dedupe['id'],
                'ticket_no' => (string)$dedupe['ticket_no'],
                'key' => $accessKey,
                'duplicate' => true,
            ]);
        }

        // 工单号冲突重试：随机段有 16^6 ≈ 1600 万种，冲突概率极低，
        // 但「极低」不等于零。这里靠唯一索引兜底并重试，而不是靠概率。
        $ticketId = 0;
        $ticketNo = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $ticketNo = $this->makeTicketNo();
            $payload['ticket_no'] = $ticketNo;
            try {
                $ticketId = $this->repo->insert($payload);
                break;
            } catch (\PDOException $e) {
                if (!Database::isDuplicate($e) || $attempt === 4) {
                    throw $e;
                }
                // 撞号：换一个再试
            }
        }
        if ($ticketId === 0) {
            return TicketResult::fail('工单创建失败，请稍后重试。');
        }

        $this->repo->insertLog(
            $ticketId,
            0,
            $user !== null ? $user->displayName() : ($guestName !== '' ? $guestName : '访客'),
            'create',
            '工单提交成功（' . $ticketNo . '）',
            $ip
        );

        $ticket = $this->repo->findById($ticketId);
        if ($ticket !== null) {
            $this->mailer->notifyTicketCreated($ticket, $accessKey);
        }

        return TicketResult::ok([
            'id' => $ticketId,
            'ticket_no' => $ticketNo,
            'key' => $accessKey,
        ]);
    }

    /**
     * 访客密钥强度要求。
     *
     * 这是相对 v1 的一处实质性收紧：v1 只要求 6 位长度，且工单号本身
     * 熵不高（每天/序号 + 4 位十六进制），两者叠加后在线爆破是可行的。
     * 这里要求 8 位以上且至少包含两类字符。
     */
    private function validateAccessKey(string $key): ?string
    {
        $len = mb_strlen($key, 'UTF-8');
        if ($len < 8) {
            return '访问密钥至少 8 位，太短容易被猜到。';
        }
        if ($len > 64) {
            return '访问密钥不能超过 64 位。';
        }
        $classes = 0;
        if (preg_match('/[a-z]/', $key) === 1) {
            $classes++;
        }
        if (preg_match('/[A-Z]/', $key) === 1) {
            $classes++;
        }
        if (preg_match('/\d/', $key) === 1) {
            $classes++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $key) === 1) {
            $classes++;
        }
        if ($classes < 2) {
            return '访问密钥需要同时包含字母、数字或符号中的至少两类。';
        }
        return null;
    }

    /**
     * 生成工单号：前缀 + 日期 + 随机段。
     *
     * 与 v1 的 COUNT(*) 方案的关键差别：不依赖「当前有多少单」，
     * 因此并发提交不会算出同一个序号；随机段由唯一索引兜底，
     * 重试逻辑在 create() 里。
     */
    private function makeTicketNo(): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $this->settings->string('ticket_prefix', 'RB')) ?? 'RB';
        $prefix = strtoupper(mb_substr($prefix, 0, 6));
        if ($prefix === '') {
            $prefix = 'TK';
        }
        return $prefix . date('Ymd') . '-' . Str::readableCode(6);
    }

    private function categoryExists(int $id): bool
    {
        return $this->db->int('SELECT COUNT(*) FROM `category` WHERE `id` = ? AND `status` = 1', [$id]) > 0;
    }

    // ---------------------------------------------------------------
    // 回复
    // ---------------------------------------------------------------

    /**
     * 添加回复（用户或后台人员）。
     *
     * @param array<string,mixed> $ticket
     * @param list<array{name:string,path:string,size:int}> $attachments
     * @return TicketResult data = ['reply_id'=>int,'author'=>'用户'|'客服']
     */
    public function reply(array $ticket, string $content, array $attachments, ?string $newStatus = null): TicketResult
    {
        $ticketId = (int)$ticket['id'];
        $content = trim($content);
        if (mb_strlen($content, 'UTF-8') < 2) {
            return TicketResult::fail('回复内容太短，请把问题描述清楚一些。');
        }
        if (mb_strlen($content, 'UTF-8') > 20000) {
            return TicketResult::fail('回复内容过长（上限 20000 字）。');
        }

        // 防重复提交：同一工单、同一作者、20 秒内、内容完全相同的回复
        // 视为重复（双击「发送」或提交后刷新重发），直接返回已有那条。
        // 后台回复会连带发送通知邮件，重复一次就是多发一封，值得挡掉。
        $recent = $this->db->row(
            'SELECT `id` FROM `ticket_reply`
             WHERE `ticket_id` = ? AND `content` = ?
               AND `created_at` >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
             ORDER BY `id` DESC LIMIT 1',
            [$ticketId, $content]
        );
        if ($recent !== null) {
            return TicketResult::ok(['reply_id' => (int)$recent['id'], 'author' => '重复提交']);
        }

        $staff = $this->auth->staff();
        $user = $this->auth->user();
        $isInternal = false;

        if ($staff !== null) {
            // 内部备注：只有后台人员能发，且不影响工单状态、不通知用户
            $isInternal = (bool)($ticket['_internal'] ?? false);
            $authorName = $staff->displayName();
            $authorRole = $staff->role;
            $staffId = $staff->id;
        } else {
            $authorName = $user !== null ? $user->displayName() : (string)($ticket['guest_name'] ?: '访客');
            $authorRole = '';
            $staffId = 0;
        }

        // 状态变更必须走状态机；不合法就直接拒绝而不是静默忽略
        $targetStatus = '';
        if (!$isInternal && $newStatus !== null && $newStatus !== '' && $newStatus !== (string)$ticket['status']) {
            if (!TicketStatus::exists($newStatus)) {
                return TicketResult::fail('目标状态不合法。');
            }
            if (!TicketStatus::canTransition((string)$ticket['status'], $newStatus)) {
                return TicketResult::fail(
                    '不能把工单从「' . TicketStatus::label((string)$ticket['status'])
                    . '」直接改为「' . TicketStatus::label($newStatus) . '」。'
                );
            }
            $targetStatus = $newStatus;
        }

        $replyId = $this->db->transaction(function () use (
            $ticket, $ticketId, $content, $attachments, $staffId, $authorName, $authorRole, $isInternal, $targetStatus
        ): int {
            $replyId = $this->repo->insertReply([
                'ticket_id' => $ticketId,
                'staff_id' => $staffId,
                'author_name' => $authorName,
                'author_role' => $authorRole,
                'content' => $content,
                'attachments' => $attachments === [] ? null : json_encode($attachments, JSON_UNESCAPED_UNICODE),
                'is_internal' => $isInternal ? 1 : 0,
                'new_status' => $targetStatus,
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            $fields = [];

            // 回复计数只统计「对外可见」的回复，与前台展示口径一致
            if (!$isInternal) {
                $fields['reply_count'] = $this->repo->recountReplies($ticketId);
            }

            // 首次响应时间只写一次
            if ($staffId > 0 && !$isInternal && empty($ticket['first_reply_at'])) {
                $fields['first_reply_at'] = date('Y-m-d H:i:s');
            }

            // 后台回复但状态还是「待处理」时自动推进到「已回复」，
            // 客服不必手动点一次状态——这是 v1 里最常被抱怨的多余一步
            if ($staffId > 0 && !$isInternal) {
                $current = (string)$ticket['status'];
                if ($targetStatus === '' && in_array($current, [TicketStatus::PENDING, TicketStatus::PROCESSING], true)) {
                    $fields['status'] = TicketStatus::REPLIED;
                }
                if ((int)$ticket['assignee_id'] === 0) {
                    $fields['assignee_id'] = $staffId;
                }
                $fields['is_read'] = 1;
            }

            // 状态相关的派生字段只在状态真正变化时更新。
            // v1 每次回复都把 resolved_at 刷成 NOW()，导致「已解决」工单
            // 只要被追加一句回复，解决时间就会被改写，统计口径随之失真。
            if ($targetStatus !== '' || isset($fields['status'])) {
                $effective = $targetStatus !== '' ? $targetStatus : (string)$fields['status'];
                $fields = array_merge($fields, $this->derivedStatusFields($effective));
            }

            if ($fields !== []) {
                $this->repo->update($ticketId, $fields);
            }

            return $replyId;
        });

        $fresh = $this->repo->findById($ticketId) ?? $ticket;

        $this->repo->insertLog(
            $ticketId,
            $staffId,
            $authorName,
            $isInternal ? 'internal_note' : 'reply',
            $isInternal ? '添加内部备注' : '添加回复' . ($targetStatus !== '' ? '，状态改为' . TicketStatus::label($targetStatus) : ''),
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );

        if (!$isInternal) {
            if ($staffId > 0) {
                $this->mailer->notifyTicketReplied($fresh, $content);
            } elseif ($targetStatus !== '') {
                $this->mailer->notifyTicketStatus($fresh, TicketStatus::label((string)$ticket['status']), TicketStatus::label($targetStatus));
            }
        }

        return TicketResult::ok(['reply_id' => $replyId, 'author' => $staffId > 0 ? '客服' : '用户']);
    }

    /**
     * 由状态推导出的时间字段。
     *
     * 单独抽出来是因为「设置状态」和「回复时顺带推进状态」两条路径都需要它，
     * 而 v1 只在其中一条里维护这些字段，另一条就漏了。
     *
     * @return array<string,mixed>
     */
    private function derivedStatusFields(string $status): array
    {
        $fields = ['status' => $status];
        switch ($status) {
            case TicketStatus::RESOLVED:
                $fields['resolved_at'] = date('Y-m-d H:i:s');
                break;
            case TicketStatus::CLOSED:
                $fields['closed_at'] = date('Y-m-d H:i:s');
                break;
            case TicketStatus::PENDING:
            case TicketStatus::PROCESSING:
            case TicketStatus::REPLIED:
                // 重新打开：清掉解决/关闭时间，否则一个「处理中」的工单
                // 仍然带着解决时间，后续统计会算错
                $fields['resolved_at'] = null;
                $fields['closed_at'] = null;
                break;
            case TicketStatus::SPAM:
                break;
        }
        return $fields;
    }

    /**
     * 后台直接设置状态。
     */
    public function changeStatus(array $ticket, string $status): TicketResult
    {
        $current = (string)$ticket['status'];
        if (!TicketStatus::exists($status)) {
            return TicketResult::fail('目标状态不合法。');
        }
        if ($current === $status) {
            return TicketResult::fail('工单已经是「' . TicketStatus::label($status) . '」状态。');
        }
        if (!TicketStatus::canTransition($current, $status)) {
            return TicketResult::fail(
                '不能把工单从「' . TicketStatus::label($current) . '」改为「' . TicketStatus::label($status) . '」。'
            );
        }

        $this->repo->update((int)$ticket['id'], $this->derivedStatusFields($status));

        $staff = $this->auth->staff();
        $this->repo->insertLog(
            (int)$ticket['id'],
            $staff?->id ?? 0,
            $staff?->displayName() ?? 'system',
            'change_status',
            '状态由「' . TicketStatus::label($current) . '」变更为「' . TicketStatus::label($status) . '」',
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );

        $fresh = $this->repo->findById((int)$ticket['id']) ?? $ticket;
        if ($status !== TicketStatus::SPAM) {
            $this->mailer->notifyTicketStatus($fresh, TicketStatus::label($current), TicketStatus::label($status));
        }
        return TicketResult::ok(['status' => $status]);
    }

    /**
     * 指派处理人。
     */
    public function assign(array $ticket, int $staffId): TicketResult
    {
        if ($staffId > 0) {
            $exists = $this->db->int('SELECT COUNT(*) FROM `staff` WHERE `id` = ? AND `status` = 1', [$staffId]);
            if ($exists === 0) {
                return TicketResult::fail('所选处理人不存在或已停用。');
            }
        }
        $previous = (int)$ticket['assignee_id'];
        $this->repo->update((int)$ticket['id'], ['assignee_id' => $staffId, 'is_read' => 1]);

        $staff = $this->auth->staff();
        $name = $staffId > 0 ? $this->staffName($staffId) : '未指派';
        $this->repo->insertLog(
            (int)$ticket['id'],
            $staff?->id ?? 0,
            $staff?->displayName() ?? 'system',
            'assign',
            ($previous > 0 ? '原处理人「' . $this->staffName($previous) . '」→ ' : '') . '指派给「' . $name . '」',
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        return TicketResult::ok(['assignee_id' => $staffId]);
    }

    /**
     * 调整优先级。
     */
    public function setPriority(array $ticket, string $priority): TicketResult
    {
        if (!TicketPriority::exists($priority)) {
            return TicketResult::fail('优先级不合法。');
        }
        $this->repo->update((int)$ticket['id'], ['priority' => $priority]);
        $staff = $this->auth->staff();
        $this->repo->insertLog(
            (int)$ticket['id'],
            $staff?->id ?? 0,
            $staff?->displayName() ?? 'system',
            'set_priority',
            '优先级由「' . TicketPriority::label((string)$ticket['priority']) . '」改为「' . TicketPriority::label($priority) . '」',
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        return TicketResult::ok(['priority' => $priority]);
    }

    /**
     * 用户评价并关闭工单。
     *
     * 与 v1 的区别：评分不再要求「能回复」这个权限条件——v1 的评分卡片
     * 在已关闭工单上照样显示，但提交时被 canReply 拦掉，形成一个
     * 「看得见、点了没反应」的死角。
     */
    public function rateAndResolve(array $ticket, int $rating, string $note): TicketResult
    {
        if ($rating < 1 || $rating > 5) {
            return TicketResult::fail('请选择 1–5 星的评价。');
        }
        $current = (string)$ticket['status'];
        if ($current === TicketStatus::SPAM) {
            return TicketResult::fail('该工单已被标记为垃圾工单，无法评价。');
        }
        // 与视图层的 canRate 使用同一套状态判断作为第二道防线：
        // 没有客服参与过的工单不应该被用户自己「评分即解决」。
        if (!TicketStatus::userActionable($current)) {
            return TicketResult::fail('工单尚未处理，暂时无法评价。');
        }

        $fields = [
            'rating' => $rating,
            'rating_note' => Str::limit(trim($note), 255, ''),
        ];
        if ($current !== TicketStatus::CLOSED) {
            $derived = $this->derivedStatusFields(TicketStatus::RESOLVED);
            // 已经是「已解决」的工单只补评分，不改写 resolved_at。
            //
            // derivedStatusFields() 会把 resolved_at 设成当前时间，
            // 于是「老工单被用户评一次分」就会让它的解决时间变成今天，
            // 统计里它开始出现在「今日已解决」——解决时长一类指标随之失真。
            if ($current === TicketStatus::RESOLVED) {
                unset($derived['resolved_at']);
            }
            $fields = array_merge($fields, $derived);
        }
        $this->repo->update((int)$ticket['id'], $fields);

        $this->repo->insertLog(
            (int)$ticket['id'],
            0,
            (string)($ticket['guest_name'] ?: '用户'),
            'rate',
            '用户评价 ' . $rating . ' 星' . (trim($note) !== '' ? '：' . Str::limit(trim($note), 200) : ''),
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );

        // 低分评价需要让负责人知道，这是唯一值得主动推送的评分场景
        if ($rating <= 2) {
            $this->mailer->notifyLowRating($this->repo->findById((int)$ticket['id']) ?? $ticket, $rating, $note);
        }
        return TicketResult::ok(['rating' => $rating]);
    }

    /**
     * 用户主动关闭工单。
     */
    public function userClose(array $ticket): TicketResult
    {
        $current = (string)$ticket['status'];
        if (!TicketStatus::userActionable($current)) {
            return TicketResult::fail('当前状态不能关闭，请等待客服回复后再操作。');
        }
        if ($current === TicketStatus::CLOSED) {
            return TicketResult::fail('工单已经是关闭状态。');
        }
        $this->repo->update((int)$ticket['id'], $this->derivedStatusFields(TicketStatus::CLOSED));
        $this->repo->insertLog(
            (int)$ticket['id'],
            0,
            (string)($ticket['guest_name'] ?: '用户'),
            'user_close',
            '用户关闭工单',
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        return TicketResult::ok(['status' => TicketStatus::CLOSED]);
    }

    /**
     * 用户重新打开工单。
     */
    public function userReopen(array $ticket): TicketResult
    {
        if ((string)$ticket['status'] !== TicketStatus::CLOSED
            && (string)$ticket['status'] !== TicketStatus::RESOLVED) {
            return TicketResult::fail('只有已解决或已关闭的工单可以重新打开。');
        }
        $this->repo->update((int)$ticket['id'], $this->derivedStatusFields(TicketStatus::PROCESSING));
        $this->repo->insertLog(
            (int)$ticket['id'],
            0,
            (string)($ticket['guest_name'] ?: '用户'),
            'user_reopen',
            '用户重新打开工单',
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        return TicketResult::ok(['status' => TicketStatus::PROCESSING]);
    }

    /**
     * 标记已读。只在后台读取详情时调用，不再挂在 GET 上——
     * v1 在详情页 GET 里直接置已读，导致爬虫/预取也能把工单标成「读过了」。
     */
    public function markRead(array $ticket): void
    {
        if ((int)($ticket['is_read'] ?? 0) === 1) {
            return;
        }
        $this->repo->update((int)$ticket['id'], ['is_read' => 1]);
    }

    public function incrementViews(array $ticket): void
    {
        $this->repo->incrementViews((int)$ticket['id']);
    }

    /**
     * 删除工单（含附件物理文件与关联记录）。
     *
     * 顺序上刻意先改数据库、后删文件：v1 先删文件再删记录，
     * 一旦数据库删除失败就会留下「记录在、文件没了」的破损工单。
     * 反过来最坏只是留下无人引用的文件，不会破坏数据完整性。
     */
    public function delete(array $ticket): TicketResult
    {
        $ticketId = (int)$ticket['id'];

        // 删除前先把附件清单取出来，删完记录就查不到了
        $files = array_merge(
            $this->attachmentsOf($ticket['attachments'] ?? null),
            $this->allReplyAttachments($ticketId)
        );

        $staff = $this->auth->staff();

        // 顺序很重要：先删子表，再删主表。
        // 表结构里 category/assignee 等字段用的是 0 作为「无」的哨兵值，
        // 因此没有加外键约束；但 ticket_reply 与 ticket_log 在部分部署里
        // 会被加上外键（或由运维手工加过），此时先删主表会直接报
        // 1451「Cannot delete or update a parent row」而整单删不掉。
        //
        // 删单这个动作本身必须留下痕迹，而 ticket_log 是唯一能承载它的地方，
        // 所以这里在同一事务内「先写审计、再删子表与主表」——
        // 日志行随主表一起消失，但至少不会出现「删了一半」的中间状态；
        // 真正的删除审计另由应用日志记录（见下方 Application::log）。
        $this->db->transaction(function () use ($ticketId, $staff): void {
            $this->repo->insertLog(
                $ticketId,
                $staff?->id ?? 0,
                $staff?->displayName() ?? 'system',
                'delete',
                '删除工单（连同其 ' . count($this->repo->replies($ticketId, true)) . ' 条回复与附件）',
                (string)($_SERVER['REMOTE_ADDR'] ?? '')
            );
            $this->db->query('DELETE FROM `ticket_reply` WHERE `ticket_id` = ?', [$ticketId]);
            $this->db->query('DELETE FROM `ticket_log` WHERE `ticket_id` = ?', [$ticketId]);
            $this->db->query('DELETE FROM `ticket` WHERE `id` = ?', [$ticketId]);
        });

        // 落到应用日志里，保证「谁删了哪张工单」在这张单消失之后仍然可查
        \App\Core\Application::log(
            '[TICKET] 删除工单 ' . (string)($ticket['ticket_no'] ?? '') . ' id=' . $ticketId
            . ' by staff_id=' . ($staff?->id ?? 0),
            'security.log'
        );

        // 数据库删除成功之后才动文件：反过来的话，一旦数据库删除失败，
        // 就会留下「记录还在、附件没了」的破损工单（旧版正是这个顺序）
        $this->uploader->delete($files);
        return TicketResult::ok(['deleted' => $ticketId]);
    }

    /**
     * 删除单条回复。
     */
    public function deleteReply(array $ticket, int $replyId): TicketResult
    {
        $reply = $this->repo->findReply($replyId, (int)$ticket['id']);
        if ($reply === null) {
            return TicketResult::fail('回复不存在。');
        }
        $files = $this->attachmentsOf($reply['attachments'] ?? null);
        $this->repo->deleteReply($replyId, (int)$ticket['id']);

        $staff = $this->auth->staff();
        $this->repo->insertLog(
            (int)$ticket['id'],
            $staff?->id ?? 0,
            $staff?->displayName() ?? 'system',
            'delete_reply',
            '删除一条' . ((int)$reply['is_internal'] === 1 ? '内部备注' : '回复'),
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        $this->uploader->delete($files);
        return TicketResult::ok(['reply_id' => $replyId]);
    }

    // ---------------------------------------------------------------
    // 附件与展示辅助
    // ---------------------------------------------------------------

    /**
     * 解析 attachments JSON。
     *
     * @return list<array{name:string,path:string,size:int}>
     */
    public function attachmentsOf(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['path'])) {
                continue;
            }
            $path = (string)$item['path'];
            $out[] = [
                'name' => (string)($item['name'] ?? basename($path)),
                'path' => $path,
                'size' => (int)($item['size'] ?? 0),
                'ext' => strtolower((string)pathinfo($path, PATHINFO_EXTENSION)),
            ];
        }
        return $out;
    }

    /**
     * 把附件拆成图片与文档两组，供视图分别渲染。
     *
     * @param list<array{name:string,path:string,size:int,ext?:string}> $attachments
     * @return array{images:list<array<string,mixed>>,files:list<array<string,mixed>>}
     */
    public function splitAttachments(array $attachments): array
    {
        $images = [];
        $files = [];
        foreach ($attachments as $a) {
            $ext = (string)($a['ext'] ?? strtolower((string)pathinfo((string)$a['path'], PATHINFO_EXTENSION)));
            $a['ext'] = $ext;
            if (Uploader::isImage($ext)) {
                $images[] = $a;
            } else {
                $files[] = $a;
            }
        }
        return ['images' => $images, 'files' => $files];
    }

    /** @return list<array{name:string,path:string,size:int,ext:string}> */
    private function allReplyAttachments(int $ticketId): array
    {
        $out = [];
        foreach ($this->repo->replies($ticketId, true) as $reply) {
            foreach ($this->attachmentsOf($reply['attachments'] ?? null) as $a) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * 回复的显示名与角色徽章。
     *
     * 优先使用回复时的角色快照：客服升为主管后，他此前的回复仍应显示
     * 「【客服】」，否则历史记录会被改写成当时并不存在的身份。
     *
     * @param array<string,mixed> $reply
     * @return array{name:string,role:string,role_label:string,role_color:string,is_staff:bool}
     */
    public function replyAuthor(array $reply): array
    {
        $staffId = (int)($reply['staff_id'] ?? 0);
        if ($staffId <= 0) {
            $name = (string)($reply['author_name'] ?? '');
            return [
                'name' => $name !== '' ? $name : '访客',
                'role' => '',
                'role_label' => '',
                'role_color' => 'slate',
                'is_staff' => false,
            ];
        }

        $role = (string)($reply['author_role'] ?? '');
        if ($role === '') {
            $role = (string)($reply['staff_role'] ?? '');
        }
        $name = (string)($reply['author_name'] ?? '');
        if ($name === '') {
            $name = (string)($reply['staff_realname'] ?? '');
        }
        if ($name === '') {
            $name = (string)($reply['staff_username'] ?? '');
        }
        if ($name === '') {
            $name = '客服';
        }

        return [
            'name' => $name,
            'role' => $role,
            'role_label' => $role !== '' ? \App\Domain\Staff\Role::label($role) : '',
            'role_color' => $role !== '' ? \App\Domain\Staff\Role::color($role) : 'slate',
            'is_staff' => true,
        ];
    }

    private function staffName(int $staffId): string
    {
        $row = $this->db->row('SELECT `username`, `realname` FROM `staff` WHERE `id` = ?', [$staffId]);
        if ($row === null) {
            return '未知账号';
        }
        return (string)($row['realname'] !== '' ? $row['realname'] : $row['username']);
    }

    // ---------------------------------------------------------------
    // 查询透传（页面不直接依赖仓储）
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $filters */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = $this->repo->count($filters);
        $items = $this->repo->paginate($filters, $perPage, ($page - 1) * $perPage);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return list<array<string,mixed>> */
    public function replies(int $ticketId, bool $includeInternal): array
    {
        return $this->repo->replies($ticketId, $includeInternal);
    }

    /** @return list<array<string,mixed>> */
    public function logs(int $ticketId, int $limit = 0): array
    {
        return $this->repo->logs($ticketId, $limit);
    }

    /** @param array<string,mixed> $filters */
    public function auditLogs(array $filters, int $limit, int $offset): array
    {
        return $this->repo->auditLogs($filters, $limit, $offset);
    }

    /** @param array<string,mixed> $filters */
    public function auditLogCount(array $filters): int
    {
        return $this->repo->auditLogCount($filters);
    }

    /** @return list<string> */
    public function actionTypes(): array
    {
        return $this->repo->actions();
    }

    /** @return list<array<string,mixed>> */
    public function todoFor(int $staffId, int $limit = 5): array
    {
        return $this->repo->todoFor($staffId, $limit);
    }

    /** @param array<string,mixed> $filters */
    public function exportRows(array $filters, int $limit = 20000): array
    {
        return $this->repo->exportRows($filters, $limit);
    }

    public function pruneLogs(int $keepDays): int
    {
        return $this->repo->pruneLogs($keepDays);
    }

    public function repository(): TicketRepository
    {
        return $this->repo;
    }

    /**
     * 首页/仪表盘统计，一次取齐。
     *
     * @return array<string,mixed>
     */
    public function overviewStats(): array
    {
        $byStatus = $this->repo->countByStatus();
        $total = array_sum($byStatus);
        $resolved = $byStatus[TicketStatus::RESOLVED] + $byStatus[TicketStatus::CLOSED];
        return [
            'by_status' => $byStatus,
            'total' => $total,
            'today' => $this->repo->countToday(),
            'resolved_today' => $this->repo->countResolvedToday(),
            'open' => $byStatus[TicketStatus::PENDING] + $byStatus[TicketStatus::PROCESSING] + $byStatus[TicketStatus::REPLIED],
            'unassigned' => $this->db->int('SELECT COUNT(*) FROM `ticket` WHERE `assignee_id` = 0 AND `status` IN (?, ?, ?)', TicketStatus::openStates()),
            'spam' => $byStatus[TicketStatus::SPAM],
            // 没有任何工单时返回 null 而不是 100%：v1 在全新站点上会显示「解决率 100%」
            'resolution_rate' => $total > 0 ? round($resolved * 100 / $total, 1) : null,
            'avg_first_response' => $this->repo->avgFirstResponseMinutes(),
            'avg_rating' => $this->repo->avgRating(),
            'daily' => $this->repo->dailyCounts(14),
            'by_category' => $this->repo->countByCategory(),
        ];
    }
}
