<?php
/** 成员总览 —— 超管在此查看全部角色并任免 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_super();

$rows = db_all(
    'SELECT a.*, (SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t WHERE t.assignee_id = a.id AND t.status IN ("pending","processing")) todo
     FROM ' . DB_PRE . 'admin a
     ORDER BY FIELD(a.role,"super","admin","supervisor","engineer","operator"), a.status DESC, a.id'
);

$byRole = [];
foreach ($rows as $r) {
    $byRole[(string)$r['role']][] = $r;
}

$pageTitle = '成员管理';
$pageDesc  = '共 ' . count($rows) . ' 位成员';
require __DIR__ . '/_head.php';
?>

<div class="alert alert-info" style="margin-bottom:18px">
  <span class="ic">ℹ</span>
  <div>
    五种角色分属两条权限线：<strong>系统配置线</strong>由技术管理员与超管负责；
    <strong>人事任免线</strong>由主管（任免客服与工程师）与超管（任免全部）负责。
    两条线互不替代，因此等级高低不等于权限大小。
  </div>
</div>

<div class="stats">
  <?php foreach (admin_roles() as $rk => $rm): ?>
    <div class="stat" style="color:var(--brand)">
      <div class="lb"><?= e($rm['label']) ?></div>
      <div class="vl"><?= e((string)count($byRole[$rk] ?? [])) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php foreach (admin_roles() as $rk => $rm):
  $list = $byRole[$rk] ?? [];
  $manageHref = admin_role_page($rk);
  ?>
  <div class="card mb">
    <div class="card-hd">
      <h2><?= e($rm['label']) ?> <span class="muted small">（<?= count($list) ?>）</span></h2>
      <span class="sp"></span>
      <?php if ($manageHref !== ''): ?>
        <a class="btn btn-o btn-sm" href="<?= e($manageHref) ?>">管理<?= e($rm['label']) ?></a>
      <?php endif; ?>
    </div>
    <div class="card-bd">
      <div class="muted small" style="margin-bottom:12px"><?= e($rm['desc']) ?></div>
      <?php if (!$list): ?>
        <div class="empty" style="padding:22px"><div class="ic">👤</div><p>暂无账号</p></div>
      <?php else: ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach ($list as $a): ?>
            <div style="display:flex;align-items:center;gap:9px;padding:8px 13px;background:#f8fafc;border:1px solid var(--line);border-radius:10px">
              <span class="badge badge-<?= e(admin_role_color($rk)) ?>"><?= e(admin_role_label($rk)) ?></span>
              <strong style="font-size:14px"><?= e($a['username']) ?></strong>
              <?php if ($a['realname']): ?><span class="muted small"><?= e($a['realname']) ?></span><?php endif; ?>
              <?php if ((int)$a['id'] === (int)$admin['id']): ?><span class="badge badge-blue">我</span><?php endif; ?>
              <?php if ((int)$a['status'] !== 1): ?><span class="badge badge-gray badge-dot">禁用</span><?php endif; ?>
              <?php if ((int)$a['todo'] > 0): ?><span class="badge badge-amber">待办 <?= (int)$a['todo'] ?></span><?php endif; ?>
              <?php if ($manageHref !== ''): ?>
                <a href="<?= e($manageHref) ?>?edit=<?= (int)$a['id'] ?>#mEdit" style="margin-left:2px">编辑</a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/_foot.php'; ?>
