<?php
/**
 * 我的工单
 */
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$status = (string)get('status');
$kw     = trim((string)get('q'));
$page   = max(1, get_int('page', 1));
$perPage = 10;

$where = ['1=1'];
$params = [];

if ($user) {
    $where[] = 't.user_id = ?';
    $params[] = (int)$user['id'];
} else {
    // 访客：只允许凭「工单号 + 访问密钥」查看单张工单
    $no  = trim((string)get('no'));
    $key = (string)get('key');
    if ($no !== '') {
        $t = ticket_find($no);
        if (!$t || !ticket_can_access($t, $key)) {
            flash('error', '工单不存在或访问密钥无效');
            redirect('my-tickets.php');
        }
        redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
    }

    $pageTitle = '查询我的工单';
    $activeNav = 'my';
    require TPL_PATH . '/header.php';
    ?>
    <main><section class="page-hd"><div class="container">
      <div class="crumb"><a href="<?= e(site_url('index.php')) ?>">首页</a> / 我的工单</div>
      <h1>查看我的工单</h1>
      <p>登录后可统一查看全部工单；未登录请使用提交时收到的工单链接</p>
    </div></section>
    <section class="sec" style="padding-top:34px"><div class="container" style="max-width:520px">
      <div class="card card-p">
        <form method="get">
          <div class="field">
            <label>工单编号</label>
            <input type="text" name="no" class="input" required placeholder="例如 RB202601010001A1B2C" value="<?= e($no) ?>">
          </div>
          <div class="field">
            <label>访问密钥</label>
            <input type="text" name="key" class="input" required placeholder="你提交工单时自行设置的密钥" value="<?= e($key) ?>">
            <div class="tip">为保护你的工单内容，站点不保存密钥明文，遗失后无法找回</div>
          </div>
          <button class="btn btn-p btn-block" type="submit">查看工单</button>
        </form>
        <div class="divider">或</div>
        <div style="text-align:center;font-size:14px;color:var(--muted)">
          <a href="<?= e(site_url('login.php?back=' . urlencode('my-tickets.php'))) ?>">登录账号</a> 统一管理 ·
          <a href="<?= e(site_url('register.php')) ?>">免费注册</a>
        </div>
      </div>
    </div></section></main>
    <?php
    require TPL_PATH . '/footer.php';
    exit;
}

// 基础条件（用于列表与统计，不含筛选）
$baseWhere  = array_slice($where, 0);
$baseParams = array_slice($params, 0);

// 支持逗号分隔的多状态筛选，如 status=pending,processing,replied
if ($status !== '') {
    $wanted = array_values(array_intersect(
        array_filter(array_map('trim', explode(',', $status))),
        array_keys(TICKET_STATUS)
    ));
    if ($wanted) {
        $where[] = 't.status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
        foreach ($wanted as $s) {
            $params[] = $s;
        }
    }
}
if ($kw !== '') {
    $where[] = '(t.title LIKE ? OR t.ticket_no LIKE ?)';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
}

$wsql = ' WHERE ' . implode(' AND ', $where);
$total = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t' . $wsql, $params, 0);
$pg = paginate($total, $perPage, $page);

$list = db_all(
    'SELECT t.*, c.name category_name, c.icon category_icon, c.color category_color
     FROM ' . DB_PRE . 'ticket t LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id' . $wsql .
    ' ORDER BY t.id DESC LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $params
);

$myStats = [];
$statRows = db_all(
    'SELECT t.status, COUNT(*) c FROM ' . DB_PRE . 'ticket t WHERE ' . implode(' AND ', $baseWhere) . ' GROUP BY t.status',
    $baseParams
);
foreach ($statRows as $r) {
    $myStats[$r['status']] = (int)$r['c'];
}

// 本页仅对登录用户展示，链接只需保留筛选参数
$selfLink = static function (string $extra = '') use ($status, $kw): string {
    $q = ['status' => $status, 'q' => $kw];
    return site_url('my-tickets.php?' . http_build_query(array_filter($q)) . ($extra !== '' ? '&' . $extra : ''));
};

$pageTitle = '我的工单';
$activeNav = 'my';
require TPL_PATH . '/header.php';
?>
<main>

<section class="page-hd">
  <div class="container">
    <div class="crumb"><a href="<?= e(site_url('index.php')) ?>">首页</a> / 我的工单</div>
    <h1>我的工单</h1>
    <p>你好，<?= e($user['username']) ?>。这里是你提交的全部工单</p>
  </div>
</section>

<section class="sec" style="padding-top:30px">
  <div class="container">

    <div class="grid g4" style="margin-bottom:26px">
      <a class="card card-p" href="<?= e($selfLink()) ?>" style="text-align:center">
        <div style="font-size:26px;font-weight:800;color:var(--brand)"><?= e((string)array_sum($myStats)) ?></div>
        <div style="font-size:13px;color:var(--muted)">全部工单</div>
      </a>
      <a class="card card-p" href="<?= e($selfLink('status=pending,processing,replied')) ?>" style="text-align:center">
        <div style="font-size:26px;font-weight:800;color:#2563eb"><?= e((string)(($myStats['pending'] ?? 0) + ($myStats['processing'] ?? 0) + ($myStats['replied'] ?? 0))) ?></div>
        <div style="font-size:13px;color:var(--muted)">处理中</div>
      </a>
      <a class="card card-p" href="<?= e($selfLink('status=resolved')) ?>" style="text-align:center">
        <div style="font-size:26px;font-weight:800;color:#059669"><?= e((string)($myStats['resolved'] ?? 0)) ?></div>
        <div style="font-size:13px;color:var(--muted)">已解决</div>
      </a>
      <a class="card card-p" href="<?= e($selfLink('status=closed')) ?>" style="text-align:center">
        <div style="font-size:26px;font-weight:800;color:#64748b"><?= e((string)($myStats['closed'] ?? 0)) ?></div>
        <div style="font-size:13px;color:var(--muted)">已关闭</div>
      </a>
    </div>

    <div class="filters">
      <a class="chip<?= $status === '' ? ' on' : '' ?>" href="<?= e($selfLink()) ?>">全部</a>
      <?php foreach (TICKET_STATUS as $k => $m): ?>
        <a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e($selfLink('status=' . $k)) ?>"><?= e($m['label']) ?></a>
      <?php endforeach; ?>
      <div style="margin-left:auto;display:flex;gap:9px;align-items:center">
        <form method="get" action="my-tickets.php" style="display:flex;gap:8px">
          <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
          <input type="search" name="q" class="input" style="width:210px;padding:8px 13px" placeholder="搜索标题或编号" value="<?= e($kw) ?>">
          <button class="btn btn-o btn-sm" type="submit">搜索</button>
        </form>
        <?php if (!is_staff()): ?>
          <a class="btn btn-p btn-sm" href="<?= e(site_url('submit.php')) ?>">+ 新建工单</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($list): ?>
      <?php foreach ($list as $t): ?>
        <a class="tk-row" href="<?= e(site_url('ticket-view.php?no=' . urlencode($t['ticket_no']))) ?>">
          <div class="tk-ico" style="--cb:<?= e($t['category_color'] ?: '#4f46e5') ?>1a"><?= e($t['category_icon'] ?: '🎫') ?></div>
          <div class="tk-main">
            <div class="tk-t"><?= e($t['title']) ?></div>
            <div class="tk-m">
              <span class="tk-no"><?= e($t['ticket_no']) ?></span>
              <span>·</span>
              <span><?= e($t['category_name'] ?: '未分类') ?></span>
              <span>·</span>
              <span><?= e($t['reply_count']) ?> 条回复</span>
              <?php if (!empty($t['assignee_name'])): ?><span>· 处理人 <?= e($t['assignee_name']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="tk-side">
            <div style="display:flex;gap:6px"><?= status_badge($t['status']) ?><?= priority_badge($t['priority']) ?></div>
            <span class="tk-time"><?= e(time_ago($t['updated_at'])) ?></span>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if ($pg['pages'] > 1): ?>
        <div class="pager">
          <a class="<?= $pg['has_prev'] ? '' : 'dis' ?>" href="<?= e($selfLink('page=' . ($pg['current'] - 1))) ?>">上一页</a>
          <span class="on"><?= $pg['current'] ?> / <?= $pg['pages'] ?></span>
          <a class="<?= $pg['has_next'] ? '' : 'dis' ?>" href="<?= e($selfLink('page=' . ($pg['current'] + 1))) ?>">下一页</a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty">
        <div class="ic">📭</div>
        <h3>暂无工单</h3>
        <p>你还没有提交过工单，遇到问题随时来找我们</p>
        <?php if (!is_staff()): ?>
          <a class="btn btn-p" href="<?= e(site_url('submit.php')) ?>">提交第一个工单</a>
        <?php else: ?>
          <a class="btn btn-p" href="<?= e(site_url('admin/tickets.php')) ?>">前往工单列表</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php require TPL_PATH . '/footer.php'; ?>
