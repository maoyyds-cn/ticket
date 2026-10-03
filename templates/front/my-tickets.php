<?php
/**
 * 我的工单（已登录）
 *
 * @var list<array> $items
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var string $statusFilter
 * @var list<string> $statuses
 * @var string $keyword
 * @var object $user
 * @var array<string,int> $counts
 * @var bool $ticketEnabled
 */
$totalAll = array_sum($counts);

/** 状态分组筛选：与旧版一致的分组口径，但用链接而不是重复的 page_link */
$chips = [
    ['label' => '全部', 'value' => '', 'count' => $totalAll],
    ['label' => '待处理', 'value' => 'pending', 'count' => (int)($counts['pending'] ?? 0)],
    ['label' => '处理中', 'value' => 'processing', 'count' => (int)($counts['processing'] ?? 0)],
    ['label' => '已回复', 'value' => 'replied', 'count' => (int)($counts['replied'] ?? 0)],
    ['label' => '已解决', 'value' => 'resolved', 'count' => (int)($counts['resolved'] ?? 0)],
    ['label' => '已关闭', 'value' => 'closed', 'count' => (int)($counts['closed'] ?? 0)],
];

$chipUrl = static function (string $value) use ($keyword): string {
    $params = [];
    if ($value !== '') {
        $params['status'] = $value;
    }
    if ($keyword !== '') {
        $params['q'] = $keyword;
    }
    return url('/my-tickets' . ($params !== [] ? '?' . http_build_query($params) : ''));
};
?>
<div class="page">
  <div class="wrap">
    <div class="detail-hd">
      <nav class="crumb" aria-label="面包屑">
        <a href="<?= e(url('/')) ?>">首页</a>
        <span class="sep" aria-hidden="true">/</span>
        <span>我的工单</span>
      </nav>
      <div class="row-between">
        <div>
          <h1 class="detail-title" style="margin-bottom:6px">我的工单</h1>
          <p class="muted small mb-0">
            <?= e($user->displayName()) ?>，这里是你提交过的全部工单。
          </p>
        </div>
        <?php if ($ticketEnabled): ?>
          <a class="btn btn-primary nowrap" href="<?= e(url('/submit')) ?>">＋ 提交工单</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- 统计卡：同时是快捷筛选入口 -->
    <div class="grid grid-4 mb-3">
      <a class="stat" href="<?= e($chipUrl('')) ?>">
        <div class="stat-k">全部工单</div>
        <div class="stat-v"><?= e((string)$totalAll) ?></div>
      </a>
      <a class="stat" href="<?= e($chipUrl('pending')) ?>">
        <div class="stat-k">待处理</div>
        <div class="stat-v"><?= e((string)(int)($counts['pending'] ?? 0)) ?></div>
      </a>
      <a class="stat" href="<?= e($chipUrl('replied')) ?>">
        <div class="stat-k">已回复</div>
        <div class="stat-v"><?= e((string)(int)($counts['replied'] ?? 0)) ?></div>
      </a>
      <a class="stat" href="<?= e($chipUrl('resolved')) ?>">
        <div class="stat-k">已解决</div>
        <div class="stat-v"><?= e((string)(int)($counts['resolved'] ?? 0)) ?></div>
      </a>
    </div>

    <!-- 筛选 -->
    <form class="card card-pad mb-2" method="get" action="<?= e(url('/my-tickets')) ?>" role="search">
      <?php if ($statusFilter !== ''): ?>
        <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
      <?php endif; ?>
      <div class="code-row">
        <div class="input-icon" style="flex:1 1 auto">
          <span class="ico" aria-hidden="true"><?= icon('search', 17) ?></span>
          <label class="sr-only" for="mySearch">搜索我的工单</label>
          <input class="input" id="mySearch" type="search" name="q"
                 value="<?= e($keyword) ?>" placeholder="搜索标题或工单编号">
        </div>
        <button class="btn btn-secondary" type="submit">搜索</button>
      </div>
    </form>

    <div class="row-wrap mb-2">
      <?php foreach ($chips as $chip): ?>
        <a class="chip<?= ($statusFilter === $chip['value']) ? ' is-active' : '' ?>"
           href="<?= e($chipUrl($chip['value'])) ?>">
          <?= e($chip['label']) ?><span class="n"><?= e((string)$chip['count']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- 列表 -->
    <?php if ($items === []): ?>
      <?php
        $icon = 'folder';
        $title = $keyword !== '' || $statusFilter !== '' ? '没有符合条件的工单' : '你还没有提交过工单';
        $text = $keyword !== '' || $statusFilter !== ''
            ? '试着换一个关键词，或者清除筛选条件。'
            : '遇到问题时可以提交工单，我们会尽快跟进。';
        $actions = [];
        if ($ticketEnabled) {
            $actions[] = ['url' => '/submit', 'label' => '提交工单', 'primary' => true];
        }
        if ($keyword !== '' || $statusFilter !== '') {
            $actions[] = ['url' => '/my-tickets', 'label' => '清除筛选'];
        }
        require dirname(__DIR__) . '/partials/empty.php';
      ?>
    <?php else: ?>
      <div class="stack-sm">
        <?php foreach ($items as $ticket): ?>
          <?php require dirname(__DIR__) . '/partials/ticket-row.php'; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($total > count($items) || $page > 1): ?>
        <p class="center muted tiny mt-2">
          共 <?= e((string)$total) ?> 条，当前第 <?= e((string)$page) ?> / <?= e((string)$totalPages) ?> 页
        </p>
      <?php endif; ?>

      <?php
        $pages = $totalPages;
        $params = [];
        if ($statusFilter !== '') { $params['status'] = $statusFilter; }
        if ($keyword !== '') { $params['q'] = $keyword; }
        $label = '我的工单分页';
        require dirname(__DIR__) . '/partials/pager.php';
      ?>
    <?php endif; ?>
  </div>
</div>
