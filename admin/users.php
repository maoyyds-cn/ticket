<?php
/** 前台用户管理 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_role('admin');

$kw    = trim((string)get('q'));
$page  = max(1, get_int('page', 1));
$perPage = 20;

$where  = ['1=1'];
$params = [];
if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.qq LIKE ? OR u.realname LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$total = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'user u' . $wsql, $params, 0);
$pg    = paginate($total, $perPage, $page);

$list = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t WHERE t.user_id = u.id) ticket_count
     FROM ' . DB_PRE . 'user u' . $wsql .
    ' ORDER BY u.id DESC LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $params
);

if (is_post()) {
    csrf_guard();
    $act = post('act');
    $id  = (int)post('id');

    if ($act === 'toggle') {
        db_query('UPDATE ' . DB_PRE . 'user SET status = 1 - status WHERE id = ?', [$id]);
        flash('ok', '已切换用户状态');
    } elseif ($act === 'delete') {
        db_query('DELETE FROM ' . DB_PRE . 'user WHERE id = ?', [$id]);
        flash('ok', '用户已删除');
    } elseif ($act === 'reset_pass') {
        $np = post('new_password');
        if (mb_strlen($np) < 6) {
            flash('error', '新密码至少 6 位');
        } else {
            db_query('UPDATE ' . DB_PRE . 'user SET password = ? WHERE id = ?', [password_hash($np, PASSWORD_DEFAULT), $id]);
            flash('ok', '密码已重置');
        }
    }
    redirect('users.php?' . http_build_query(array_diff_key($_GET, ['page' => 1])));
}

$newToday = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'user WHERE created_at >= CURDATE()', [], 0);
$active7  = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'user WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', [], 0);

$pageTitle = '前台用户';
$pageDesc  = '共 ' . $total . ' 位';
require __DIR__ . '/_head.php';
?>

<div class="stats">
  <div class="stat" style="color:var(--brand)">
    <div class="lb">用户总数</div><div class="vl"><?= e((string)$total) ?></div>
  </div>
  <div class="stat" style="color:#10b981">
    <div class="lb">今日注册</div><div class="vl"><?= e((string)$newToday) ?></div>
  </div>
  <div class="stat" style="color:#8b5cf6">
    <div class="lb">近 7 天活跃</div><div class="vl"><?= e((string)$active7) ?></div>
  </div>
  <div class="stat" style="color:#f59e0b">
    <div class="lb">已禁用</div>
    <div class="vl"><?= e((string)(int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'user WHERE status = 0', [], 0)) ?></div>
  </div>
</div>

<form method="get" class="tools">
  <div class="search">
    <span class="si">🔍</span>
    <input type="search" name="q" class="input" placeholder="搜索用户名 / 邮箱 / QQ" value="<?= e($kw) ?>">
  </div>
  <button class="btn btn-p btn-sm" type="submit">搜索</button>
  <a class="btn btn-o btn-sm" href="users.php">重置</a>
</form>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-hd"><h2>用户列表</h2></div>
    <div class="card-bd np">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr>
            <th>用户</th><th>联系方式</th><th>工单数</th><th>状态</th><th>最近登录</th><th>注册时间</th><th style="text-align:right">操作</th>
          </tr></thead>
          <tbody>
            <?php foreach ($list as $u): ?>
              <tr>
                <td>
                  <div class="t-main"><?= e($u['username']) ?><?= $u['realname'] ? ' <span class="muted small">(' . e($u['realname']) . ')</span>' : '' ?></div>
                  <div class="t-sub mono">#<?= (int)$u['id'] ?></div>
                </td>
                <td class="small">
                  <div><?= e($u['email'] ?: '—') ?></div>
                  <div class="t-sub"><?= $u['qq'] ? 'QQ ' . e($u['qq']) : '' ?></div>
                </td>
                <td><a href="tickets.php?q=<?= e(urlencode($u['username'])) ?>"><?= (int)$u['ticket_count'] ?> 单</a></td>
                <td>
                  <?php if ((int)$u['status'] === 1): ?>
                    <span class="badge badge-green badge-dot">正常</span>
                  <?php else: ?>
                    <span class="badge badge-red badge-dot">已禁用</span>
                  <?php endif; ?>
                </td>
                <td class="muted small nowrap"><?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : '从未') ?></td>
                <td class="muted small nowrap"><?= e(fmt_date($u['created_at'], 'Y-m-d')) ?></td>
                <td class="act">
                  <button type="submit" data-single="toggle" data-id="<?= (int)$u['id'] ?>">
                    <?= (int)$u['status'] === 1 ? '禁用' : '启用' ?>
                  </button>
                  <a href="#" data-modal="mReset<?= (int)$u['id'] ?>">改密</a>
                  <button class="d" type="submit" data-single="delete" data-id="<?= (int)$u['id'] ?>"
                          data-confirm="确定删除用户「<?= e($u['username']) ?>」？">删除</button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
              <tr><td colspan="7"><div class="empty"><div class="ic">👥</div><p>暂无用户</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <input type="hidden" name="act" value="">
    <input type="hidden" name="id" value="0">
    <?php if ($pg['pages'] > 1): ?>
      <div class="pager">
        <span>第 <?= $pg['current'] ?> / <?= $pg['pages'] ?> 页，共 <?= $total ?> 位</span>
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
</form>

<?php foreach ($list as $u): ?>
<div class="modal" id="mReset<?= (int)$u['id'] ?>">
  <div class="modal-box">
    <div class="modal-hd"><h3>重置用户密码</h3><button type="button" data-close>×</button></div>
    <form method="post" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="reset_pass">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <div class="modal-bd">
        <p class="muted small" style="margin-bottom:14px">为用户 <strong><?= e($u['username']) ?></strong> 设置新密码</p>
        <div class="field">
          <label>新密码 <span class="req">*</span></label>
          <input type="text" name="new_password" class="input" required minlength="6" placeholder="至少 6 位">
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-o" data-close>取消</button>
        <button class="btn btn-p" type="submit">确认重置</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/_foot.php'; ?>
