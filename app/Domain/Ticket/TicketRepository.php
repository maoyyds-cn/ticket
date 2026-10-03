<?php

/**
 * 工单数据访问
 *
 * v1 把工单相关的 SQL 直接写在页面里（index.php 6 条、ticket-view.php 5 条、
 * admin/tickets.php 与 admin/export.php 各有一份几乎相同的筛选 SQL，
 * 而且 v1 的注释自己承认「改了一处要记得改另一处」）。集中到这里之后，
 * 筛选条件的拼装只有一份实现。
 */
declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Core\Database;

final class TicketRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** 列表用的公共字段：附分类与处理人信息，避免视图层再做 N+1 查询 */
    private const LIST_SELECT = 'SELECT t.id, t.ticket_no, t.title, t.status, t.priority, t.reply_count,
                t.view_count, t.created_at, t.updated_at, t.contact_email, t.guest_name, t.user_id,
                t.category_id, t.assignee_id, t.is_read,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                s.username AS assignee_name, s.realname AS assignee_realname
         FROM `ticket` t
         LEFT JOIN `category` c ON c.id = t.category_id
         LEFT JOIN `staff` s ON s.id = t.assignee_id';

    /**
     * 详情用的完整字段。
     *
     * 为什么必须与列表分开：列表刻意只取渲染列表所需的最小字段集，
     * 但详情页需要 access_hash（访问控制）、content、attachments、
     * rating、first_reply_at 等。曾经详情复用了列表的字段集，
     * 结果 access_hash 读出来是空的，于是**访客拿着正确密钥也打不开自己的工单**
     * ——而页面表现只是 404，完全看不出是字段没取。
     *
     * 用 t.* 取全部列，避免以后再漏字段。
     */
    private const FULL_SELECT = 'SELECT t.*,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                s.username AS assignee_name, s.realname AS assignee_realname
         FROM `ticket` t
         LEFT JOIN `category` c ON c.id = t.category_id
         LEFT JOIN `staff` s ON s.id = t.assignee_id';

    // ---------------------------------------------------------------
    // 读取
    // ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findByNo(string $ticketNo): ?array
    {
        return $this->db->row(self::FULL_SELECT . ' WHERE t.ticket_no = ? LIMIT 1', [$ticketNo]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->row(self::FULL_SELECT . ' WHERE t.id = ? LIMIT 1', [$id]);
    }

    /**
     * 列表查询。
     *
     * @param array{
     *   user_id?:int, email?:string, status?:list<string>, category_id?:int,
     *   assignee_id?:int, unassigned?:bool, priority?:list<string>, q?:string,
     *   is_read?:int, created_from?:string, created_to?:string
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset, string $orderBy = 't.updated_at DESC'): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = self::LIST_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->safeOrder($orderBy)
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return $this->db->all($sql, $params);
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket` t WHERE ' . implode(' AND ', $where),
            $params
        );
    }

    /**
     * 把筛选条件编译成 WHERE 子句。
     *
     * 前台「我的工单」与后台「工单管理」共用这一处，
     * 因此后台能看到的所有维度（状态/分类/优先级/处理人/时间/关键词）
     * 前台也能按需复用，不会出现两边筛选能力不一致。
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 't.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['email'])) {
            $where[] = 't.contact_email = ?';
            $params[] = (string)$filters['email'];
        }
        if (!empty($filters['status']) && is_array($filters['status'])) {
            $valid = array_values(array_filter((array)$filters['status'], [TicketStatus::class, 'exists']));
            if ($valid !== []) {
                $where[] = 't.status IN (' . implode(',', array_fill(0, count($valid), '?')) . ')';
                foreach ($valid as $s) {
                    $params[] = $s;
                }
            }
        }
        if (!empty($filters['priority']) && is_array($filters['priority'])) {
            $valid = array_values(array_filter((array)$filters['priority'], [TicketPriority::class, 'exists']));
            if ($valid !== []) {
                $where[] = 't.priority IN (' . implode(',', array_fill(0, count($valid), '?')) . ')';
                foreach ($valid as $p) {
                    $params[] = $p;
                }
            }
        }
        if (!empty($filters['category_id'])) {
            $where[] = 't.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['unassigned'])) {
            $where[] = 't.assignee_id = 0';
        } elseif (!empty($filters['assignee_id'])) {
            // 注意：只有「未指派」标志没生效时才按处理人过滤。
            // 之前两者同时叠加，会生成 `assignee_id = -1 AND assignee_id = 0`
            // 这样自相矛盾的条件，「未指派」筛选永远返回 0 条。
            $where[] = 't.assignee_id = ?';
            $params[] = (int)$filters['assignee_id'];
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $where[] = 't.is_read = ?';
            $params[] = (int)$filters['is_read'];
        }
        if (!empty($filters['created_from'])) {
            $where[] = 't.created_at >= ?';
            $params[] = (string)$filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[] = 't.created_at <= ?';
            $params[] = (string)$filters['created_to'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            // 关键词同时匹配标题、编号与正文：客服最常拿到的线索就是其中之一
            $like = '%' . $this->escapeLike((string)$filters['q']) . '%';
            $where[] = '(t.title LIKE ? ESCAPE \'\\\\\' OR t.ticket_no LIKE ? ESCAPE \'\\\\\' OR t.content LIKE ? ESCAPE \'\\\\\')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    /**
     * LIKE 里的 % 与 _ 必须转义，否则用户搜「100%」会命中所有记录。
     */
    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** 排序字段白名单：ORDER BY 是拼接进 SQL 的，绝不能接受用户输入原样 */
    private function safeOrder(string $orderBy): string
    {
        $allowed = [
            't.updated_at DESC', 't.updated_at ASC',
            't.created_at DESC', 't.created_at ASC',
            't.priority DESC', 't.priority ASC',
        ];
        return in_array($orderBy, $allowed, true) ? $orderBy : 't.updated_at DESC';
    }

    // ---------------------------------------------------------------
    // 写入
    // ---------------------------------------------------------------

    /**
     * 插入工单。
     *
     * 工单号冲突（唯一索引 1062）由调用方重试，因此这里只负责插入与上抛。
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO `ticket`
                (`ticket_no`, `user_id`, `guest_name`, `contact_email`, `qq`, `category_id`,
                 `title`, `content`, `attachments`, `priority`, `status`, `assignee_id`,
                 `source`, `ip`, `user_agent`, `access_hash`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string)$data['ticket_no'],
                (int)($data['user_id'] ?? 0),
                (string)($data['guest_name'] ?? ''),
                (string)($data['contact_email'] ?? ''),
                (string)($data['qq'] ?? ''),
                (int)($data['category_id'] ?? 0),
                (string)$data['title'],
                (string)$data['content'],
                $data['attachments'] ?? null,
                (string)($data['priority'] ?? TicketPriority::NORMAL),
                (string)($data['status'] ?? TicketStatus::PENDING),
                (int)($data['assignee_id'] ?? 0),
                (string)($data['source'] ?? 'web'),
                (string)($data['ip'] ?? ''),
                (string)($data['user_agent'] ?? ''),
                (string)($data['access_hash'] ?? ''),
            ]
        );
    }

    /**
     * 更新工单字段。
     *
     * 只允许更新白名单里的列：字段名来自代码，但仍然收口，
     * 防止将来有人把表单键直接传进来拼 SQL。
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int
    {
        // 允许通过 update() 修改的列白名单。
        //
        // 不在白名单里的键会被**静默忽略**（不报错、不抛异常）。这个设计对角色的
        // 拼写错误是友好的，但也意味着「漏加一个列名」的表现是「功能悄悄失效」——
        // 撤销访问密钥就是这么坏的：revokeKey() 调 update(['access_hash' => ''])，
        // 而白名单里没有它，于是密钥原样保留，但页面照样提示「已撤销」、
        // 审计日志也记了一笔 revoke_key。假的安全感加假的审计记录，
        // 比功能缺失更糟。
        $allowed = [
            'title', 'content', 'attachments', 'priority', 'status', 'assignee_id',
            'category_id', 'is_read', 'reply_count', 'view_count', 'rating', 'rating_note',
            'first_reply_at', 'resolved_at', 'closed_at', 'contact_email', 'qq', 'guest_name',
            // 访问密钥的哈希：撤销密钥（写入空串）必须能落库
            'access_hash',
        ];
        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $sets[] = '`' . $col . '` = ?';
            $params[] = $val;
        }
        if ($sets === []) {
            return 0;
        }
        $params[] = $id;
        return $this->db->affected('UPDATE `ticket` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $params);
    }

    /** 原子自增浏览数，避免「先读后写」在并发下丢失计数 */
    public function incrementViews(int $id): void
    {
        $this->db->query('UPDATE `ticket` SET `view_count` = `view_count` + 1 WHERE `id` = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM `ticket` WHERE `id` = ?', [$id]);
    }

    // ---------------------------------------------------------------
    // 回复
    // ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     */
    public function insertReply(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO `ticket_reply`
                (`ticket_id`, `staff_id`, `author_name`, `author_role`, `content`, `attachments`, `is_internal`, `new_status`, `ip`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int)$data['ticket_id'],
                (int)($data['staff_id'] ?? 0),
                (string)($data['author_name'] ?? ''),
                (string)($data['author_role'] ?? ''),
                (string)$data['content'],
                $data['attachments'] ?? null,
                (int)($data['is_internal'] ?? 0),
                (string)($data['new_status'] ?? ''),
                (string)($data['ip'] ?? ''),
            ]
        );
    }

    /**
     * 取回复列表。
     *
     * @return list<array<string,mixed>>
     */
    public function replies(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT r.*, s.username AS staff_username, s.realname AS staff_realname, s.role AS staff_role,
                       s.status AS staff_status
                FROM `ticket_reply` r
                LEFT JOIN `staff` s ON s.id = r.staff_id
                WHERE r.ticket_id = ?'
            . ($includeInternal ? '' : ' AND r.is_internal = 0')
            . ' ORDER BY r.id ASC';
        return $this->db->all($sql, [$ticketId]);
    }

    public function findReply(int $replyId, int $ticketId): ?array
    {
        return $this->db->row(
            'SELECT * FROM `ticket_reply` WHERE `id` = ? AND `ticket_id` = ?',
            [$replyId, $ticketId]
        );
    }

    /**
     * 删除回复并同步工单的回复计数。
     *
     * reply_count 的口径是「对外可见的回复数」（与 recountReplies、前台展示一致，
     * 都不算内部备注）。因此删除内部备注时**不能**减计数——否则工单有 2 条公开
     * 回复 + 1 条内部备注时删掉内部备注，计数会变成 1，而页面上仍有 2 条回复，
     * 从此永久不一致。
     *
     * 这里直接重算，而不是做加减：加减要在「删的是哪一类」上判断正确，
     * 而重算天然不会错，代价只是一条 COUNT 查询。
     */
    public function deleteReply(int $replyId, int $ticketId): void
    {
        $this->db->transaction(function () use ($replyId, $ticketId): void {
            $this->db->query('DELETE FROM `ticket_reply` WHERE `id` = ? AND `ticket_id` = ?', [$replyId, $ticketId]);
            $this->db->query(
                'UPDATE `ticket` SET `reply_count` = (SELECT COUNT(*) FROM `ticket_reply` r
                    WHERE r.`ticket_id` = ? AND r.`is_internal` = 0) WHERE `id` = ?',
                [$ticketId, $ticketId]
            );
        });
    }

    /** 重算某工单的回复计数（仅用于数据修复场景） */
    public function recountReplies(int $ticketId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket_reply` WHERE `ticket_id` = ? AND `is_internal` = 0',
            [$ticketId]
        );
    }

    // ---------------------------------------------------------------
    // 操作日志
    // ---------------------------------------------------------------

    public function insertLog(int $ticketId, int $staffId, string $staffName, string $action, string $detail, string $ip): void
    {
        $this->db->query(
            'INSERT INTO `ticket_log` (`ticket_id`, `staff_id`, `staff_name`, `action`, `detail`, `ip`)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$ticketId, $staffId, $staffName, $action, mb_substr($detail, 0, 490), $ip]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function logs(int $ticketId, int $limit = 0): array
    {
        $sql = 'SELECT * FROM `ticket_log` WHERE `ticket_id` = ? ORDER BY `id` DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        return $this->db->all($sql, [$ticketId]);
    }

    /**
     * 全局操作日志（含工单编号，供后台审计页使用）。
     *
     * @param array{action?:string,q?:string,ticket_no?:string,staff_id?:int,created_from?:string,created_to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function auditLogs(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildLogWhere($filters);
        $sql = 'SELECT l.*, t.ticket_no, t.title AS ticket_title
                FROM `ticket_log` l
                LEFT JOIN `ticket` t ON t.id = l.ticket_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY l.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return $this->db->all($sql, $params);
    }

    /** @param array<string,mixed> $filters */
    public function auditLogCount(array $filters): int
    {
        [$where, $params] = $this->buildLogWhere($filters);
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket_log` l LEFT JOIN `ticket` t ON t.id = l.ticket_id WHERE ' . implode(' AND ', $where),
            $params
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function buildLogWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'l.action = ?';
            $params[] = (string)$filters['action'];
        }
        if (!empty($filters['staff_id'])) {
            $where[] = 'l.staff_id = ?';
            $params[] = (int)$filters['staff_id'];
        }
        if (!empty($filters['ticket_no'])) {
            $where[] = 't.ticket_no = ?';
            $params[] = (string)$filters['ticket_no'];
        }
        if (!empty($filters['created_from'])) {
            $where[] = 'l.created_at >= ?';
            $params[] = (string)$filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[] = 'l.created_at <= ?';
            $params[] = (string)$filters['created_to'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            $like = '%' . $this->escapeLike((string)$filters['q']) . '%';
            $where[] = '(l.detail LIKE ? ESCAPE \'\\\\\' OR l.staff_name LIKE ? ESCAPE \'\\\\\' OR t.title LIKE ? ESCAPE \'\\\\\' OR t.ticket_no LIKE ? ESCAPE \'\\\\\')';
            array_push($params, $like, $like, $like, $like);
        }
        return [$where, $params];
    }

    /** @return list<string> 出现过的动作类型，用于筛选下拉 */
    public function actions(): array
    {
        $rows = $this->db->all('SELECT `action`, COUNT(*) AS n FROM `ticket_log` GROUP BY `action` ORDER BY n DESC');
        return array_map(static fn(array $r): string => (string)$r['action'], $rows);
    }

    // ---------------------------------------------------------------
    // 统计
    // ---------------------------------------------------------------

    /**
     * 按状态计数。
     *
     * @return array<string,int>
     */
    public function countByStatus(): array
    {
        $out = array_fill_keys(TicketStatus::all(), 0);
        foreach ($this->db->all('SELECT `status`, COUNT(*) AS n FROM `ticket` GROUP BY `status`') as $r) {
            $s = (string)$r['status'];
            if (array_key_exists($s, $out)) {
                $out[$s] = (int)$r['n'];
            }
        }
        return $out;
    }

    public function countAll(): int
    {
        return $this->db->int('SELECT COUNT(*) FROM `ticket`');
    }

    /**
     * 指定用户的按状态计数。
     *
     * 前台「我的工单」必须用它，而不是站点级的 countByStatus()——
     * 后者会把全站工单数当成这个用户的数字显示在统计卡上，
     * 既误导用户，也泄露了站点的总量与状态分布。
     *
     * @return array<string,int>
     */
    public function countByStatusForUser(int $userId): array
    {
        $out = array_fill_keys(TicketStatus::all(), 0);
        if ($userId <= 0) {
            return $out;
        }
        $rows = $this->db->all(
            'SELECT `status`, COUNT(*) AS n FROM `ticket` WHERE `user_id` = ? GROUP BY `status`',
            [$userId]
        );
        foreach ($rows as $r) {
            $s = (string)$r['status'];
            if (array_key_exists($s, $out)) {
                $out[$s] = (int)$r['n'];
            }
        }
        return $out;
    }

    public function countToday(): int
    {
        return $this->db->int('SELECT COUNT(*) FROM `ticket` WHERE `created_at` >= CURDATE()');
    }

    public function countResolvedToday(): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket` WHERE `resolved_at` IS NOT NULL AND `resolved_at` >= CURDATE()'
        );
    }

    /** 平均首次响应时间（分钟），无数据返回 null —— 不返回 0，否则页面会把「无数据」显示成「0 分钟」 */
    public function avgFirstResponseMinutes(): ?int
    {
        $v = $this->db->value(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, `created_at`, `first_reply_at`))
             FROM `ticket` WHERE `first_reply_at` IS NOT NULL'
        );
        return $v === null ? null : (int)round((float)$v);
    }

    /** 平均满意度（仅统计已评分工单） */
    public function avgRating(): ?float
    {
        $v = $this->db->value('SELECT AVG(`rating`) FROM `ticket` WHERE `rating` > 0');
        return $v === null ? null : round((float)$v, 2);
    }

    /**
     * 近 N 天每日新建数，用于趋势图。
     *
     * 用一次 GROUP BY 取回，而不是在循环里查 N 次（v1 的首页就是这么干的）。
     *
     * @return array<string,int> 日期 => 数量
     */
    public function dailyCounts(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $rows = $this->db->all(
            'SELECT DATE(`created_at`) AS d, COUNT(*) AS n FROM `ticket`
             WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(`created_at`)',
            [$days - 1]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['d']] = (int)$r['n'];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = date('Y-m-d', strtotime('-' . $i . ' day'));
            $out[$key] = $map[$key] ?? 0;
        }
        return $out;
    }

    /**
     * 按分类计数。
     *
     * @return list<array{category_id:int,name:string,icon:string,color:string,n:int}>
     */
    public function countByCategory(): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.name, c.icon, c.color, COUNT(t.id) AS n
             FROM `category` c
             LEFT JOIN `ticket` t ON t.category_id = c.id
             WHERE c.status = 1
             GROUP BY c.id, c.name, c.icon, c.color
             ORDER BY n DESC, c.sort ASC'
        );
        return array_map(static fn(array $r): array => [
            'category_id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'icon' => (string)$r['icon'],
            'color' => (string)$r['color'],
            'n' => (int)$r['n'],
        ], $rows);
    }

    /**
     * 待办工单（未指派或指派给我）。
     *
     * @return list<array<string,mixed>>
     */
    public function todoFor(int $staffId, int $limit = 5): array
    {
        return $this->db->all(
            self::LIST_SELECT . ' WHERE t.status IN (?, ?, ?) AND (t.assignee_id = 0 OR t.assignee_id = ?)
             ORDER BY FIELD(t.priority, ?, ?, ?, ?), t.updated_at ASC LIMIT ' . max(1, $limit),
            [
                TicketStatus::PENDING, TicketStatus::PROCESSING, TicketStatus::REPLIED,
                $staffId,
                TicketPriority::URGENT, TicketPriority::HIGH, TicketPriority::NORMAL, TicketPriority::LOW,
            ]
        );
    }

    public function countOpen(): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket` WHERE `status` IN (?, ?, ?)',
            TicketStatus::openStates()
        );
    }

    public function countOpenAssignedTo(int $staffId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket` WHERE `status` IN (?, ?, ?) AND `assignee_id` = ?',
            array_merge(TicketStatus::openStates(), [$staffId])
        );
    }

    /**
     * 导出用：一次性取回筛选结果。
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function exportRows(array $filters, int $limit = 20000): array
    {
        [$where, $params] = $this->buildWhere($filters);
        return $this->db->all(
            'SELECT t.ticket_no, t.title, t.status, t.priority, t.contact_email, t.qq,
                    t.created_at, t.updated_at, t.resolved_at, t.rating,
                    c.name AS category_name, s.username AS assignee_name,
                    (SELECT COUNT(*) FROM `ticket_reply` r WHERE r.ticket_id = t.id AND r.is_internal = 0) AS replies
             FROM `ticket` t
             LEFT JOIN `category` c ON c.id = t.category_id
             LEFT JOIN `staff` s ON s.id = t.assignee_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.id DESC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /** 清空操作日志（保留最近 N 天） */
    public function pruneLogs(int $keepDays): int
    {
        $keepDays = max(0, $keepDays);
        return $this->db->affected(
            'DELETE FROM `ticket_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$keepDays]
        );
    }
}
