<?php
/**
 * 工单业务逻辑
 */
declare(strict_types=1);

function ticket_prefix(): string
{
    return (string)setting('ticket_prefix', 'TK');
}

function ticket_make_no(): string
{
    // 工单号格式：前缀 + Ymd + 4位序号 + 4位随机，总长 = 前缀长 + 16。
    // 数据库 ticket_no 为 VARCHAR(24)，因此前缀最长 8 位；
    // 超出则截断，否则插入会因列长度不足直接失败。
    $d     = date('Ymd');
    $prefix = mb_substr((string)ticket_prefix(), 0, 8, 'UTF-8');
    $rand   = strtoupper(bin2hex(random_bytes(2)));
    $seq    = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE ticket_no LIKE ?', [$prefix . $d . '%'], 0) + 1;
    return $prefix . $d . str_pad((string)$seq, 4, '0', STR_PAD_LEFT) . $rand;
}

/**
 * 访客自设访问密钥。
 *
 * 密钥由用户在提交工单时自行设定，数据库只保存哈希（sha256 + 服务端 app_key 加盐），
 * 因此即使数据库泄露也无法反推出用户的原始密钥。
 * access_key 为空表示该工单未设密钥，仅登录用户与管理员可访问。
 */
function ticket_key_hash(string $raw): string
{
    global $CFG;
    return hash_hmac('sha256', mb_strtolower(trim($raw)), $CFG['app_key'] ?? 'tk');
}

function ticket_find(string $no): ?array
{
    return db_row('SELECT t.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color, a.username AS assignee_name
                   FROM ' . DB_PRE . 'ticket t
                   LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id
                   LEFT JOIN ' . DB_PRE . 'admin a ON a.id = t.assignee_id
                   WHERE t.ticket_no = ? LIMIT 1', [$no]);
}

/**
 * 校验访问权限：管理员 / 归属用户 / 持密钥访客
 */
function ticket_can_access(?array $ticket, ?string $key = null): bool
{
    if (!$ticket) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    $u = current_user();
    if ($u && (int)$ticket['user_id'] === (int)$u['id']) {
        return true;
    }
    $hash = (string)($ticket['access_key'] ?? '');
    if ($hash === '' || $key === null || $key === '') {
        return false;
    }
    return hash_equals($hash, ticket_key_hash($key));
}

function ticket_attachments(?string $json): array
{
    if (!$json) {
        return [];
    }
    $a = json_decode($json, true);
    if (!is_array($a)) {
        return [];
    }
    // 归一化：过滤掉结构不完整 / 无 path 的记录。
    // 早期数据或手工改库可能存入非数组元素，
    // 直接访问 $a['path'] 会触发 warning 并让整块渲染中断。
    $out = [];
    foreach ($a as $item) {
        if (is_array($item) && !empty($item['path'])) {
            $out[] = [
                'name' => (string)($item['name'] ?? basename((string)$item['path'])),
                'path' => (string)$item['path'],
                'size' => (int)($item['size'] ?? 0),
            ];
        }
    }
    return $out;
}

function ticket_replies(int $id, bool $withInternal = false): array
{
    // a.role 仅用于历史数据兜底：author_role 为空时按当前角色补头衔
    $sql = 'SELECT r.*, a.username AS admin_username, a.realname AS admin_realname, a.role AS admin_role
            FROM ' . DB_PRE . 'ticket_reply r
            LEFT JOIN ' . DB_PRE . 'admin a ON a.id = r.admin_id
            WHERE r.ticket_id = ?' . ($withInternal ? '' : ' AND r.is_internal = 0') . '
            ORDER BY r.id ASC';
    return db_all($sql, [$id]);
}

/**
 * 回复者显示名 = 头衔 + 姓名
 *
 * 优先用回复时快照的 author_role，而不是 admin 表的当前角色：
 * 客服升为主管后，他此前的回复仍应显示「【客服】」，否则历史记录会被
 * 改写成当时并不存在的身份。
 */
function reply_author_label(array $r): string
{
    if ((int)($r['admin_id'] ?? 0) <= 0) {
        return (string)($r['author_name'] ?: '访客');
    }
    $role = (string)($r['author_role'] ?? '');
    if ($role === '') {
        $role = (string)($r['admin_role'] ?? '');
    }
    // 四级兜底：快照姓名 → 账号真名 → 账号用户名 → 泛称。
    // 用 ?? 兜住缺键，避免调用方传入非 ticket_replies() 的数组时触发 warning。
    $name = (string)($r['author_name'] ?? '');
    if ($name === '') { $name = (string)($r['admin_realname'] ?? ''); }
    if ($name === '') { $name = (string)($r['admin_username'] ?? ''); }
    if ($name === '') { $name = '客服'; }
    $title = $role !== '' ? admin_role_title($role) : '';
    return $title . $name;
}

/** 回复者角色徽章的 HTML（后台会话记录用） */
function reply_author_badge(array $r): string
{
    $role = (string)($r['author_role'] ?? '');
    if ($role === '') {
        $role = (string)($r['admin_role'] ?? '');
    }
    if ($role === '') {
        return '';
    }
    return '<span class="badge badge-' . e(admin_role_color($role)) . '">' . e(admin_role_label($role)) . '</span>';
}

function ticket_logs(int $id): array
{
    return db_all('SELECT * FROM ' . DB_PRE . 'ticket_log WHERE ticket_id = ? ORDER BY id DESC', [$id]);
}

function ticket_add_log(int $ticketId, string $action, string $detail = ''): void
{
    $a = current_admin();
    db_insert('INSERT INTO ' . DB_PRE . 'ticket_log (ticket_id, admin_id, admin_name, action, detail, ip) VALUES (?,?,?,?,?,?)', [
        $ticketId,
        (int)($a['id'] ?? 0),
        (string)($a['username'] ?? 'system'),
        $action,
        mb_substr($detail, 0, 480),
        client_ip(),
    ]);
}

function ticket_render_body(string $content): string
{
    $safe = nl2br(e($content));
    // 识别以 / 或 roblox/ 开头的命令，展示为命令代码块
    $safe = preg_replace_callback('/(?<!\w)((?:roblox\/)?[\x{4e00}-\x{9fa5}\w]+(?: [^<\n]{0,40})?)/u', static function ($m) {
        $t = trim($m[1]);
        if ($t === '' || mb_strlen($t) < 2) {
            return $m[0];
        }
        if (str_starts_with($t, '/') || str_starts_with($t, 'roblox/')) {
            return '<code class="cmd-chip">' . $t . '</code>';
        }
        return $m[0];
    }, $safe);
    // /u 修饰符遇到非法 UTF-8 序列时 preg 返回 null；此时若直接返回，
    // 会在 ': string' 返回类型上抛 TypeError 导致整页 500。回退到已转义的纯文本。
    if ($safe === null) {
        $safe = nl2br(e($content));
    }
    return '<div class="rich-text">' . $safe . '</div>';
}

function ticket_status_changed(array $old, array $new, array $ticket): void
{
    if (($old['status'] ?? '') === ($new['status'] ?? '')) {
        return;
    }
    $from = status_meta((string)$old['status'])['label'];
    $to   = status_meta((string)$new['status'])['label'];
    ticket_add_log((int)$ticket['id'], 'change_status', "状态由「{$from}」变更为「{$to}」");
    mail_notify_ticket($ticket, 'status', '<p>您的工单状态已更新：<strong>' . e($from) . '</strong> → <strong>' . e($to) . '</strong></p>');
}

/**
 * 允许访客自助操作的状态
 */
function ticket_user_can_close(array $t): bool
{
    return in_array($t['status'], ['resolved', 'replied', 'closed'], true);
}

/**
 * 统计
 */
function ticket_stats(): array
{
    $rows = db_all('SELECT status, COUNT(*) c FROM ' . DB_PRE . 'ticket GROUP BY status');
    $s = array_fill_keys(array_keys(TICKET_STATUS), 0);
    foreach ($rows as $r) {
        $s[$r['status']] = (int)$r['c'];
    }
    $s['total'] = array_sum($s);
    $s['pending_count'] = $s['pending'] + $s['processing'] + $s['replied'];
    return $s;
}

function ticket_today_count(): int
{
    return (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE created_at >= CURDATE()', [], 0);
}

function category_list(bool $isTicket = true, bool $isFaq = false): array
{
    $where = [];
    if ($isTicket) $where[] = 'is_ticket = 1';
    if ($isFaq)    $where[] = 'is_faq = 1';
    $sql = 'SELECT * FROM ' . DB_PRE . 'category WHERE status = 1' . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY sort ASC, id ASC';
    return db_all($sql);
}
