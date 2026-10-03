<?php
/** 工单列表管理 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$status   = (string)get('status');
$priority = (string)get('priority');
$catId    = get_int('cat');
$assignee = get_int('assignee');
$kw       = trim((string)get('q'));
$days     = get_int('days');
$page     = max(1, get_int('page', 1));
$perPage  = 20;

$where  = ['1=1'];
$params = [];

if ($status !== '' && array_key_exists($status, TICKET_STATUS)) { $where[] = 't.status = ?'; $params[] = $status; }
if ($priority !== '' && array_key_exists($priority, TICKET_PRIORITY)) { $where[] = 't.priority = ?'; $params[] = $priority; }
if ($catId > 0) { $where[] = 't.category_id = ?'; $params[] = $catId; }
if ($assignee > 0) { $where[] = 't.assignee_id = ?'; $params[] = $assignee; }
if ($days > 0) { $where[] = 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'; }
if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(t.title LIKE ? OR t.ticket_no LIKE ? OR t.contact_email LIKE ? OR t.guest_name LIKE ? OR t.content LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$total = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t' . $wsql, $params, 0);
$pg    = paginate($total, $perPage, $page);

$list = db_all(
    'SELECT t.*, c.name category_name, c.icon category_icon, c.color category_color, a.username assignee_name, a.realname assignee_real
     FROM ' . DB_PRE . 'ticket t
     LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id
     LEFT JOIN ' . DB_PRE . 'admin a ON a.id = t.assignee_id' . $wsql .
    ' ORDER BY FIELD(t.priority,"urgent","high","normal","low"), t.id DESC
     LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $params
);

$admins = db_all('SELECT id, username, realname FROM ' . DB_PRE . 'admin WHERE status = 1 ORDER BY id');
$cats   = category_list(true, false);

// 批量操作
if (is_post()) {
    csrf_guard();
    $act = post('act');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (!$ids) {
        flash('error', '请先选择工单');
    } else {
        $in = implode(',', $ids);
        if ($act === 'processing') {
            db_query('UPDATE ' . DB_PRE . 'ticket SET status = "processing", is_read = 1 WHERE id IN (' . $in . ') AND status IN ("pending","replied")');
            foreach ($ids as $id) ticket_add_log($id, 'batch', '批量标记为处理中');
            flash('ok', '已将 ' . count($ids) . ' 个工单标记为处理中');
        } elseif ($act === 'closed') {
            db_query('UPDATE ' . DB_PRE . 'ticket SET status = "closed", closed_at = NOW() WHERE id IN (' . $in . ') AND status <> "closed"');
            foreach ($ids as $id) ticket_add_log($id, 'batch', '批量关闭');
            flash('ok', '已关闭 ' . count($ids) . ' 个工单');
        } elseif ($act === 'assign') {
            $to = (int)post('assignee_id');
            db_query('UPDATE ' . DB_PRE . 'ticket SET assignee_id = ? WHERE id IN (' . $in . ')', [$to]);
            foreach ($ids as $id) ticket_add_log($id, 'batch', '批量指派处理人');
            flash('ok', '已指派 ' . count($ids) . ' 个工单');
        } elseif ($act === 'spam') {
            db_query('UPDATE ' . DB_PRE . 'ticket SET status = "spam" WHERE id IN (' . $in . ')');
            foreach ($ids as $id) ticket_add_log($id, 'batch', '批量标记为垃圾工单');
            flash('ok', '已标记 ' . count($ids) . ' 个工单为垃圾');
        }
    }
    redirect('tickets.php?' . http_build_query(array_diff_key($_GET, ['page' => 1])));
}

$pageTitle = '工单管理';
$pageDesc  = '共 ' . $total . ' 条';
$exportQs  = http_build_query(array_intersect_key($_GET, array_flip(['status', 'priority', 'cat', 'q', 'days', 'assignee'])));
$exportUrl = 'export.php?type=csv' . ($exportQs !== '' ? '&' . $exportQs : '');
// 导出属系统配置线，需技术管理员及以上。与侧栏 nav_visible('cfg') 用同一判据，
// 否则会出现「按钮看得到、点进去被 require_role 拦下」的空操作。
$canExport   = admin_role_level((string)$admin['role']) >= admin_role_level('admin');
$pageActions = $canExport ? '<a class="btn btn-o btn-sm" href="' . e($exportUrl) . '">导出 CSV</a>' : '';
require __DIR__ . '/_head.php';
?>

<form method="get" class="tools">
  <div class="search">
    <span class="si">🔍</span>
    <input type="search" name="q" class="input" placeholder="搜索标题 / 编号 / 邮箱 / 内容" value="<?= e($kw) ?>">
  </div>
  <select name="cat" class="select" style="width:auto;min-width:130px">
    <option value="">全部分类</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="priority" class="select" style="width:auto;min-width:110px">
    <option value="">全部优先级</option>
    <?php foreach (TICKET_PRIORITY as $k => $m): ?>
      <option value="<?= e($k) ?>" <?= $priority === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="assignee" class="select" style="width:auto;min-width:120px">
    <option value="">全部处理人</option>
    <?php foreach ($admins as $a): ?>
      <option value="<?= (int)$a['id'] ?>" <?= $assignee === (int)$a['id'] ? ' selected' : '' ?>>
        <?= e($a['realname'] ?: $a['username']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="days" class="select" style="width:auto;min-width:110px">
    <option value="">全部时间</option>
    <option value="1" <?= $days === 1 ? ' selected' : '' ?>>今天</option>
    <option value="7" <?= $days === 7 ? ' selected' : '' ?>>近 7 天</option>
    <option value="30" <?= $days === 30 ? ' selected' : '' ?>>近 30 天</option>
  </select>
  <button class="btn btn-p btn-sm" type="submit">筛选</button>
  <a class="btn btn-o btn-sm" href="tickets.php">重置</a>
</form>

<div class="chips mb">
  <a class="chip<?= $status === '' ? ' on' : '' ?>" href="<?= e(page_link(1)) ?>">全部</a>
  <?php foreach (TICKET_STATUS as $k => $m): ?>
    <a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e(page_link(1)) ?>&status=<?= e($k) ?>">
      <?= e($m['label']) ?>
    </a>
  <?php endforeach; ?>
</div>

<form method="post" id="bulkForm">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-hd">
      <h2>工单列表</h2><span class="sp"></span>
      <select name="assignee_id" class="select" style="width:auto;min-width:150px;padding:6px 12px;font-size:13px">
        <option value="0">未指派</option>
        <?php foreach ($admins as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= e($a['realname'] ?: $a['username']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-o btn-sm" name="act" value="assign" type="submit">批量指派</button>
      <button class="btn btn-o btn-sm" name="act" value="processing" type="submit">标记处理中</button>
      <button class="btn btn-o btn-sm" name="act" value="closed" type="submit">批量关闭</button>
      <button class="btn btn-o btn-sm" name="act" value="spam" type="submit"
              data-confirm="确定将选中工单标记为垃圾？">垃圾</button>
    </div>

    <div class="card-bd np">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th style="width:34px"><input type="checkbox" class="tk-check" data-check-all></th>
              <th>工单信息</th>
              <th>分类</th>
              <th>联系方式</th>
              <th>状态</th>
              <th>处理人</th>
              <th>提交时间</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $t): ?>
              <tr>
                <td><input type="checkbox" class="tk-check" data-check-item name="ids[]" value="<?= (int)$t['id'] ?>"></td>
                <td>
                  <div class="t-main">
                    <a href="ticket-view.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
                    <?php if ((int)$t['is_read'] === 0): ?><span class="badge badge-red" style="margin-left:6px;font-size:11px">NEW</span><?php endif; ?>
                  </div>
                  <div class="t-sub">
                    <span class="mono"><?= e($t['ticket_no']) ?></span> ·
                    <?= e(mb_strimwidth($t['content'], 0, 42, '…')) ?>
                  </div>
                </td>
                <td class="nowrap">
                  <span style="color:<?= e($t['category_color'] ?: '#64748b') ?>"><?= e($t['category_icon'] . ' ' . ($t['category_name'] ?: '未分类')) ?></span>
                </td>
                <td>
                  <div class="small"><?= e($t['contact_email'] ?: '—') ?></div>
                  <div class="t-sub"><?= e($t['guest_name'] ?: ($t['user_id'] ? '注册用户#' . $t['user_id'] : '访客')) ?><?= $t['qq'] ? ' · QQ ' . e($t['qq']) : '' ?></div>
                </td>
                <td class="nowrap">
                  <?= status_badge($t['status']) ?>
                  <div style="margin-top:3px"><?= priority_badge($t['priority']) ?></div>
                </td>
                <td class="small nowrap"><?= $t['assignee_real'] ? e($t['assignee_real']) : ($t['assignee_name'] ? e($t['assignee_name']) : '<span class="muted">未指派</span>') ?></td>
                <td class="muted small nowrap"><?= e(time_ago($t['created_at'])) ?></td>
                <td class="act">
                  <a href="ticket-view.php?id=<?= (int)$t['id'] ?>">处理</a>
                  <a href="../ticket-view.php?no=<?= e(urlencode($t['ticket_no'])) ?>" target="_blank" title="前台预览">👁</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
              <tr><td colspan="8"><div class="empty"><div class="ic">📭</div><p>没有符合条件的工单</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($pg['pages'] > 1): ?>
      <div class="pager">
        <span>第 <?= $pg['current'] ?> / <?= $pg['pages'] ?> 页，共 <?= $total ?> 条</span>
        <div class="pgs">
          <a class="<?= $pg['has_prev'] ? '' : 'dis' ?>" href="<?= e(page_link(max(1, $pg['current'] - 1))) ?>">上一页</a>
          <?php
          $from = max(1, $pg['current'] - 2);
          $to   = min($pg['pages'], $from + 4);
          for ($p = $from; $p <= $to; $p++):
              echo $p === $pg['current'] ? '<span class="on">' . $p . '</span>' : '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
          endfor;
          ?>
          <a class="<?= $pg['has_next'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] + 1)) ?>">下一页</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php require __DIR__ . '/_foot.php'; ?>
