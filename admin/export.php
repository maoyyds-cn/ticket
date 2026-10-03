<?php
/** 导出工单 CSV */
require __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$status   = (string)get('status');
$priority = (string)get('priority');
$catId    = get_int('cat');
$assignee = get_int('assignee');
$kw       = trim((string)get('q'));
$days     = get_int('days');

$where  = ['1=1'];
$params = [];
if ($status !== '' && array_key_exists($status, TICKET_STATUS)) { $where[] = 't.status = ?'; $params[] = $status; }
if ($priority !== '' && array_key_exists($priority, TICKET_PRIORITY)) { $where[] = 't.priority = ?'; $params[] = $priority; }
if ($catId > 0) { $where[] = 't.category_id = ?'; $params[] = $catId; }
if ($assignee > 0) { $where[] = 't.assignee_id = ?'; $params[] = $assignee; }
if ($days > 0) { $where[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'; }
if ($kw !== '') {
    $like = '%' . $kw . '%';
    // 搜索字段与列表页保持一致，否则导出行数会少于筛选结果
    $where[] = '(t.title LIKE ? OR t.ticket_no LIKE ? OR t.contact_email LIKE ?
                 OR t.guest_name LIKE ? OR t.content LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

$rows = db_all(
    'SELECT t.*, c.name category_name, a.username assignee_name
     FROM ' . DB_PRE . 'ticket t
     LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id
     LEFT JOIN ' . DB_PRE . 'admin a ON a.id = t.assignee_id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 5000',
    $params
);

$filename = 'tickets_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

/**
 * CSV 公式注入防护
 * 工单标题/内容由匿名访客可控，若以 = + - @ 开头，Excel 打开时会当作公式执行
 */
$csvSafe = static function ($v): string {
    $s = (string)$v;
    return preg_match('/^[=+\-@\t\r]/u', $s) ? "'" . $s : $s;
};

fputcsv($out, ['工单编号', '标题', '分类', '状态', '优先级', '提交者', '邮箱', 'QQ', '处理人', '回复数', '评分', '创建时间', '最后更新', '问题描述']);

foreach ($rows as $r) {
    fputcsv($out, array_map($csvSafe, [
        $r['ticket_no'],
        $r['title'],
        $r['category_name'] ?: '',
        status_meta($r['status'])['label'],
        priority_meta($r['priority'])['label'],
        $r['guest_name'] ?: ($r['user_id'] ? '用户#' . $r['user_id'] : '访客'),
        $r['contact_email'],
        $r['qq'],
        $r['assignee_name'] ?: '',
        $r['reply_count'],
        $r['rating'] ?: '',
        $r['created_at'],
        $r['updated_at'],
        mb_substr($r['content'], 0, 500),
    ]));
}
fclose($out);
exit;
