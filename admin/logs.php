<?php
/** 操作日志 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_role('admin');

$kw     = trim((string)get('q'));
$act    = (string)get('a');
$days   = get_int('days');
$tid    = get_int('tid');
$page   = max(1, get_int('page', 1));
$perPage = 40;

$where  = ['1=1'];
$params = [];
if ($tid > 0)  { $where[] = 'l.ticket_id = ?'; $params[] = $tid; }
if ($act !== '') { $where[] = 'l.action = ?'; $params[] = $act; }
if ($days > 0) { $where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'; }
if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(l.detail LIKE ? OR l.admin_name LIKE ? OR t.ticket_no LIKE ? OR t.title LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$wsql = ' WHERE ' . implode(' AND ', $where);
$join = ' FROM ' . DB_PRE . 'ticket_log l LEFT JOIN ' . DB_PRE . 'ticket t ON t.id = l.ticket_id';

$total = (int)db_one('SELECT COUNT(*)' . $join . $wsql, $params, 0);
$pg    = paginate($total, $perPage, $page);

$list = db_all('SELECT l.*, t.ticket_no, t.title' . $join . $wsql .
    ' ORDER BY l.id DESC LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'], $params);

$actions = array_column(db_all('SELECT DISTINCT action FROM ' . DB_PRE . 'ticket_log'), 'action');

$pageTitle = '操作日志';
$pageDesc  = '共 ' . $total . ' 条记录';
require __DIR__ . '/_head.php';
?>

<form method="get" class="tools">
  <div class="search">
    <span class="si">🔍</span>
    <input type="search" name="q" class="input" placeholder="搜索操作内容 / 操作人 / 工单编号" value="<?= e($kw) ?>">
  </div>
  <select name="a" class="select" style="width:auto;min-width:140px">
    <option value="">全部操作</option>
    <?php foreach ($actions as $x): ?>
      <option value="<?= e($x) ?>" <?= $act === $x ? 'selected' : '' ?>><?= e($x) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="days" class="select" style="width:auto;min-width:120px">
    <option value="">全部时间</option>
    <option value="1" <?= $days === 1 ? 'selected' : '' ?>>今天</option>
    <option value="7" <?= $days === 7 ? 'selected' : '' ?>>近 7 天</option>
    <option value="30" <?= $days === 30 ? 'selected' : '' ?>>近 30 天</option>
  </select>
  <?php if ($tid > 0): ?>
    <input type="hidden" name="tid" value="<?= $tid ?>">
    <span class="badge badge-blue">工单 #<?= $tid ?></span>
  <?php endif; ?>
  <button class="btn btn-p btn-sm" type="submit">筛选</button>
  <a class="btn btn-o btn-sm" href="logs.php">重置</a>
</form>

<div class="card">
  <div class="card-hd"><h2>日志列表</h2></div>
  <div class="card-bd np">
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th style="width:150px">时间</th><th style="width:110px">操作人</th><th style="width:120px">操作</th><th>详情</th><th>关联工单</th><th style="width:130px">IP</th></tr></thead>
        <tbody>
          <?php foreach ($list as $l): ?>
            <tr>
              <td class="muted small nowrap"><?= e(fmt_date($l['created_at'], 'Y-m-d H:i:s')) ?></td>
              <td class="small"><?= e($l['admin_name']) ?></td>
              <td><span class="badge badge-gray"><?= e($l['action']) ?></span></td>
              <td class="small"><?= e($l['detail'] ?: '—') ?></td>
              <td class="small">
                <?php if ($l['ticket_id'] > 0): ?>
                  <a href="ticket-view.php?id=<?= (int)$l['ticket_id'] ?>" class="mono"><?= e($l['ticket_no'] ?? ('#' . $l['ticket_id'])) ?></a>
                  <div class="t-sub"><?= e(mb_strimwidth((string)($l['title'] ?? ''), 0, 24, '…')) ?></div>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="muted small mono"><?= e($l['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$list): ?>
            <tr><td colspan="6"><div class="empty"><div class="ic">📜</div><p>暂无日志记录</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pg['pages'] > 1): ?>
    <div class="pager">
      <span>第 <?= $pg['current'] ?> / <?= $pg['pages'] ?> 页，共 <?= $total ?> 条</span>
      <div class="pgs">
        <a class="<?= $pg['has_prev'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] - 1)) ?>">上一页</a>
        <?php for ($p = max(1, $pg['current'] - 2); $p <= min($pg['pages'], $pg['current'] + 2); $p++):
            echo $p === $pg['current'] ? '<span class="on">' . $p . '</span>' : '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
        endfor; ?>
        <a class="<?= $pg['has_next'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] + 1)) ?>">下一页</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
