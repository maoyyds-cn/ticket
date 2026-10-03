<?php
/** 后台数据概览 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$stats = ticket_stats();
$monthCount = (int)db_one("SELECT COUNT(*) FROM " . DB_PRE . "ticket WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')", [], 0);
$weekCount  = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)', [], 0);
$dayCount   = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE created_at >= CURDATE()', [], 0);
$unread     = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE status IN ("pending","processing") AND is_read = 0', [], 0);
$rated      = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE rating > 0', [], 0);
$avgRating  = (float)db_one('SELECT AVG(rating) FROM ' . DB_PRE . 'ticket WHERE rating > 0', [], 0);
$userCount  = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'user', [], 0);
$faqCount   = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq', [], 0);
$faqViews   = (int)db_one('SELECT SUM(views) FROM ' . DB_PRE . 'faq', [], 0);

// 近 14 天趋势
$trend = [];
$rows = db_all('SELECT DATE(created_at) d, COUNT(*) c FROM ' . DB_PRE . 'ticket
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)');
$map = [];
foreach ($rows as $r) $map[$r['d']] = (int)$r['c'];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trend[] = ['d' => $d, 'c' => $map[$d] ?? 0, 'l' => $i === 0 ? '今天' : date('m/d', strtotime($d))];
}
$maxTrend = max(1, max(array_column($trend, 'c')));

// 状态分布（用于环形图）
$statusList = [];
foreach (TICKET_STATUS as $k => $m) {
    $statusList[] = ['k' => $k, 'label' => $m['label'], 'color' => $m['color'], 'c' => (int)($stats[$k] ?? 0)];
}
$colorsMap = ['blue' => '#3b82f6', 'green' => '#10b981', 'amber' => '#f59e0b', 'red' => '#ef4444', 'violet' => '#8b5cf6', 'gray' => '#94a3b8'];
$seg = [];
$acc = 0;
$totalTickets = max(1, (int)$stats['total']);
foreach ($statusList as $s) {
    if ($s['c'] <= 0) {
        continue;
    }
    $seg[] = $colorsMap[$s['color']] . ' ' . round($acc, 2) . '% ' . round($acc + $s['c'] * 100 / $totalTickets, 2) . '%';
    $acc += $s['c'] * 100 / $totalTickets;
}
$donut = $seg ? 'conic-gradient(' . implode(',', $seg) . ')' : 'conic-gradient(#e2e8f0 0 100%)';

// 最新工单
$latest = db_all('SELECT t.*, c.name category_name, c.icon category_icon, c.color category_color
                  FROM ' . DB_PRE . 'ticket t LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id
                  ORDER BY t.id DESC LIMIT 8');

// 我的待办
$mine = db_all('SELECT t.*, c.icon category_icon, c.color category_color FROM ' . DB_PRE . 'ticket t
                LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id
                WHERE t.assignee_id = ? AND t.status IN ("pending","processing","replied")
                ORDER BY FIELD(t.priority,"urgent","high","normal","low"), t.id ASC LIMIT 6', [(int)$admin['id']]);

// 分类分布
$byCat = db_all('SELECT c.name, c.icon, c.color, COUNT(t.id) c FROM ' . DB_PRE . 'category c
                 LEFT JOIN ' . DB_PRE . 'ticket t ON t.category_id = c.id
                 GROUP BY c.id ORDER BY c DESC LIMIT 7');

$pageTitle = '数据概览';
require __DIR__ . '/_head.php';
?>

<div class="stats">
  <div class="stat" style="color:var(--brand)">
    <div class="lb"><span class="dot" style="background:var(--brand)"></span>今日新增</div>
    <div class="vl"><?= e((string)$dayCount) ?></div>
    <div class="sub">本周 <?= e((string)$weekCount) ?> · 本月 <?= e((string)$monthCount) ?></div>
  </div>
  <div class="stat" style="color:#f59e0b">
    <div class="lb"><span class="dot" style="background:#f59e0b"></span>待处理工单</div>
    <div class="vl"><?= e((string)($stats['pending'] + $stats['processing'])) ?></div>
    <div class="sub">未读 <?= e((string)$unread) ?> 条</div>
  </div>
  <div class="stat" style="color:#10b981">
    <div class="lb"><span class="dot" style="background:#10b981"></span>已解决</div>
    <div class="vl"><?= e((string)$stats['resolved']) ?></div>
    <div class="sub">累计 <?= e((string)$stats['total']) ?> 单</div>
  </div>
  <div class="stat" style="color:#8b5cf6">
    <div class="lb"><span class="dot" style="background:#8b5cf6"></span>用户评分</div>
    <div class="vl"><?= $avgRating > 0 ? e(number_format($avgRating, 1)) : '—' ?></div>
    <div class="sub"><?= e((string)$rated) ?> 人参与评价</div>
  </div>
</div>

<div class="two-col mb">
  <div class="card">
    <div class="card-hd"><h2>📈 近 14 天工单趋势</h2><span class="sp"></span>
      <span class="muted small">峰值 <?= e((string)$maxTrend) ?> 单/天</span></div>
    <div class="card-bd">
      <div class="chart-bars">
        <?php foreach ($trend as $t): ?>
          <div class="b">
            <div class="bar" style="height:<?= e((string)max(3, round($t['c'] / $maxTrend * 148))) ?>px" title="<?= e($t['d']) ?>：<?= e((string)$t['c']) ?> 单">
              <?php if ($t['c'] > 0): ?><span class="n"><?= e((string)$t['c']) ?></span><?php endif; ?>
            </div>
            <span class="lb"><?= e($t['l']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-hd"><h2>🎯 工单状态分布</h2></div>
    <div class="card-bd">
      <div class="donut-wrap">
        <div class="donut" style="background:<?= e($donut) ?>">
          <div class="ctr"><div><b><?= e((string)$stats['total']) ?></b><span>总计</span></div></div>
        </div>
        <ul class="legend">
          <?php foreach ($statusList as $s): ?>
            <li>
              <span class="sw" style="background:<?= e($colorsMap[$s['color']]) ?>"></span>
              <span class="nm"><?= e($s['label']) ?></span>
              <span class="vl"><?= e((string)$s['c']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-hd"><h2>🆕 最新工单</h2><span class="sp"></span>
      <a class="btn btn-o btn-sm" href="tickets.php">全部工单 →</a></div>
    <div class="card-bd np">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>工单</th><th>状态</th><th>提交时间</th><th style="text-align:right">操作</th></tr></thead>
          <tbody>
            <?php foreach ($latest as $t): ?>
              <tr>
                <td>
                  <div class="t-main"><?= e($t['title']) ?></div>
                  <div class="t-sub mono"><?= e($t['ticket_no']) ?> · <?= e($t['category_name'] ?: '未分类') ?></div>
                </td>
                <td><?= status_badge($t['status']) ?></td>
                <td class="muted small nowrap"><?= e(time_ago($t['created_at'])) ?></td>
                <td class="act"><a href="ticket-view.php?id=<?= (int)$t['id'] ?>">处理</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$latest): ?>
              <tr><td colspan="4" class="empty" style="padding:40px">暂无工单</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card mb">
      <div class="card-hd"><h2>📋 我的待办</h2><span class="sp"></span>
        <span class="badge badge-amber"><?= e((string)count($mine)) ?></span></div>
      <div class="card-bd np">
        <div class="tbl-wrap">
          <table class="tbl">
            <tbody>
              <?php foreach ($mine as $t): ?>
                <tr>
                  <td>
                    <div class="t-main" style="font-size:13.5px"><?= e(mb_strimwidth($t['title'], 0, 30, '…')) ?></div>
                    <div class="t-sub"><?= e($t['ticket_no']) ?></div>
                  </td>
                  <td class="act"><?= priority_badge($t['priority']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$mine): ?>
                <tr><td class="empty" style="padding:34px"><div class="ic">✨</div><p>当前没有分配给你的待办工单</p></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-hd"><h2>📂 分类工单量</h2></div>
      <div class="card-bd">
        <?php $maxCat = max(1, max(array_map(fn($x) => (int)$x['c'], $byCat) ?: [1])); ?>
        <?php foreach ($byCat as $c): ?>
          <div style="margin-bottom:11px">
            <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:5px">
              <span><?= e($c['icon'] . ' ' . $c['name']) ?></span>
              <strong><?= e((string)$c['c']) ?></strong>
            </div>
            <div style="height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden">
              <div style="height:100%;width:<?= e((string)round($c['c'] / $maxCat * 100)) ?>%;background:<?= e($c['color'] ?: '#4f46e5') ?>;border-radius:4px"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="stats mt">
  <div class="card card-bd" style="text-align:center;padding:18px">
    <div style="font-size:24px;font-weight:800"><?= e((string)$userCount) ?></div>
    <div class="muted small">注册用户</div>
    <a class="btn btn-g btn-xs mt" href="users.php">管理 →</a>
  </div>
  <div class="card card-bd" style="text-align:center;padding:18px">
    <div style="font-size:24px;font-weight:800"><?= e((string)$faqCount) ?></div>
    <div class="muted small">知识库条目</div>
    <a class="btn btn-g btn-xs mt" href="faqs.php">管理 →</a>
  </div>
  <div class="card card-bd" style="text-align:center;padding:18px">
    <div style="font-size:24px;font-weight:800"><?= e(fmt_num($faqViews)) ?></div>
    <div class="muted small">知识库浏览量</div>
    <a class="btn btn-g btn-xs mt" href="faqs.php">管理 →</a>
  </div>
  <div class="card card-bd" style="text-align:center;padding:18px">
    <div style="font-size:24px;font-weight:800"><?= (int)setting('mail_enabled', '0') === 1 ? '已开' : '未开' ?></div>
    <div class="muted small">邮件通知</div>
    <a class="btn btn-g btn-xs mt" href="mail.php">配置 →</a>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
