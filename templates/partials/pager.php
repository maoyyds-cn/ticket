<?php
/**
 * 分页
 *
 * 旧版有两套分页实现（一套带页码窗口、一套只有上/下页），
 * 而且窗口算法不一致：一处是 max(1,cur-2)…min(pages,from+4)，
 * 到了末尾只剩两个链接；另一处是 cur±2，前后不对称。
 * 这里用一套算法：始终尽量显示 5 个页码，且以当前页居中。
 *
 * @var int    $page    当前页
 * @var int    $pages   总页数
 * @var string $base    基础地址（不含查询串），默认当前路径
 * @var array  $params  额外保留的查询参数（会与 $_GET 合并）
 * @var string $label   无障碍标签，用于区分同一页上的多个分页器
 */
$page = max(1, (int)($page ?? 1));
$pages = max(1, (int)($pages ?? 1));
$params = $params ?? [];
$label = $label ?? '分页导航';

if ($pages <= 1) {
    return;
}

/** 生成某一页的地址，保留现有筛选条件 */
$link = static function (int $p) use ($params): string {
    return q(array_merge($params, ['page' => $p]));
};

// 页码窗口：总数不超过 7 时全部显示，否则始终显示 5 个并让当前页居中
$window = 5;
if ($pages <= 7) {
    $from = 1;
    $to = $pages;
} else {
    $from = max(1, $page - intdiv($window, 2));
    $to = $from + $window - 1;
    if ($to > $pages) {
        $to = $pages;
        $from = max(1, $to - $window + 1);
    }
}
?>
<nav class="pager" aria-label="<?= e($label) ?>">
  <?php if ($page > 1): ?>
    <a href="<?= e($link($page - 1)) ?>" rel="prev" aria-label="上一页">‹</a>
  <?php else: ?>
    <span class="is-disabled" aria-hidden="true">‹</span>
  <?php endif; ?>

  <?php if ($from > 1): ?>
    <a href="<?= e($link(1)) ?>">1</a>
    <?php if ($from > 2): ?><span class="gap" aria-hidden="true">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($p = $from; $p <= $to; $p++): ?>
    <?php if ($p === $page): ?>
      <span class="is-current" aria-current="page"><?= e((string)$p) ?></span>
    <?php else: ?>
      <a href="<?= e($link($p)) ?>"><?= e((string)$p) ?></a>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($to < $pages): ?>
    <?php if ($to < $pages - 1): ?><span class="gap" aria-hidden="true">…</span><?php endif; ?>
    <a href="<?= e($link($pages)) ?>"><?= e((string)$pages) ?></a>
  <?php endif; ?>

  <?php if ($page < $pages): ?>
    <a href="<?= e($link($page + 1)) ?>" rel="next" aria-label="下一页">›</a>
  <?php else: ?>
    <span class="is-disabled" aria-hidden="true">›</span>
  <?php endif; ?>
</nav>
