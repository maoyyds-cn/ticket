<?php
/** FAQ 有用/没用 投票 */
require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('knowledge.php');
}
csrf_guard();

$id = get_int('id');
if ($id <= 0) {
    redirect('knowledge.php');
}

// 回跳地址仅允许站内相对路径，防止开放重定向
$back = (string)post('back', 'knowledge.php');
if (str_contains($back, '//') || str_contains($back, '..')
    || (!str_starts_with($back, 'knowledge.php') && !str_starts_with($back, '?'))) {
    $back = 'knowledge.php';
}

// 每个会话对同一条 FAQ 只能投一次
$voted = $_SESSION['faq_voted'] ?? [];
if (!isset($voted[$id])) {
    $v   = post('v') === '1' ? 1 : 0;
    $col = $v === 1 ? 'helpful' : 'unhelpful';
    db_query(
        'UPDATE ' . DB_PRE . 'faq SET ' . $col . ' = ' . $col . ' + 1 WHERE id = ? AND status = 1',
        [$id]
    );
    $voted[$id] = 1;
    $_SESSION['faq_voted'] = $voted;
}
redirect($back);
