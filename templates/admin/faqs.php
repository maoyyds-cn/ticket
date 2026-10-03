<?php
/**
 * 知识库管理（列表）
 *
 * @var list<array> $items
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var array $filters
 * @var list<array> $categories
 * @var int $publishedCount
 * @var string $statusRaw
 */
$f = $filters;
$currentStatus = $statusRaw;
$currentUrl = '/admin/faqs' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

$filterUrl = static function (array $overrides) use ($f, $currentStatus): string {
    $params = [
        'q' => (string)$f['q'],
        'cat' => (int)$f['category_id'] > 0 ? (string)(int)$f['category_id'] : '',
        'status' => $currentStatus,
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    $params = array_filter($params, static fn($v): bool => $v !== '' && $v !== null);
    return url('/admin/faqs' . ($params !== [] ? '?' . http_build_query($params) : ''));
};
?>
<div class="a-card">
  <form class="a-card-bd" method="get" action="<?= e(url('/admin/faqs')) ?>">
    <div class="filters">
      <div class="f-item grow">
        <label for="fq">关键词</label>
        <input class="input" id="fq" type="search" name="q" value="<?= e((string)$f['q']) ?>"
               placeholder="问题、关键词或解答内容">
      </div>
      <div class="f-item">
        <label for="fcat">分类</label>
        <select class="select select-sm" id="fcat" name="cat" data-autosubmit>
          <option value="">全部分类</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e((string)(int)$c['id']) ?>"<?= (int)$f['category_id'] === (int)$c['id'] ? ' selected' : '' ?>>
              <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-item">
        <label for="fstatus">状态</label>
        <select class="select select-sm" id="fstatus" name="status" data-autosubmit>
          <option value=""<?= $currentStatus === '' ? ' selected' : '' ?>>全部状态</option>
          <option value="1"<?= $currentStatus === '1' ? ' selected' : '' ?>>已发布</option>
          <option value="0"<?= $currentStatus === '0' ? ' selected' : '' ?>>草稿</option>
        </select>
      </div>
      <div class="f-item">
        <span aria-hidden="true">&nbsp;</span>
        <div class="row-wrap">
          <button class="btn btn-primary btn-sm" type="submit">筛选</button>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/faqs')) ?>">重置</a>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="a-card">
  <form method="post" action="<?= e(url('/admin/faqs/bulk')) ?>" id="faqBulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
    <input type="hidden" name="act" value="">

    <div class="a-card-hd">
      <h2>知识库条目</h2>
      <div class="row-wrap">
        <span class="faint tiny">共 <?= e((string)$total) ?> 条，其中已发布 <?= e((string)$publishedCount) ?> 条</span>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/faqs/new')) ?>">＋ 新建条目</a>
      </div>
    </div>

    <div class="bulkbar" data-bulkbar hidden>
      <span class="sel-count">已选 <span data-check-count>0</span> 条</span>
      <div class="row-wrap">
        <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="publish">批量发布</button>
        <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="draft">转为草稿</button>
        <button class="btn btn-danger-soft btn-sm" type="button" data-bulk-act="delete"
                data-confirm="确定删除选中的知识库条目吗？此操作不可撤销。">批量删除</button>
      </div>
    </div>

    <?php if ($items === []): ?>
      <div class="a-card-bd">
        <?php
          $icon = 'book';
          $title = '没有符合条件的条目';
          $text = '试着放宽筛选条件，或直接新建一条。';
          $actions = [
              ['url' => '/admin/faqs/new', 'label' => '新建条目', 'primary' => true],
              ['url' => '/admin/faqs', 'label' => '清除筛选'],
          ];
          require dirname(__DIR__) . '/partials/empty.php';
        ?>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl">
          <thead>
            <tr>
              <th class="col-check">
                <input type="checkbox" data-check-all="1" aria-label="全选本页条目">
              </th>
              <th>问题</th>
              <th class="nowrap">分类</th>
              <th class="nowrap">标记</th>
              <th class="nowrap">浏览</th>
              <th class="nowrap">有用度</th>
              <th class="nowrap">状态</th>
              <th class="nowrap">更新时间</th>
              <th class="nowrap">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td class="col-check">
                  <input type="checkbox" name="ids[]" value="<?= e((string)(int)$item['id']) ?>"
                         data-check-item aria-label="选择条目">
                </td>
                <td>
                  <a class="cell-main" href="<?= e(url('/admin/faqs/' . (int)$item['id'] . '/edit')) ?>">
                    <?= e(\App\Support\Str::limit((string)$item['question'], 52)) ?>
                  </a>
                  <?php if ((string)$item['keywords'] !== ''): ?>
                    <span class="cell-sub"><?= e(\App\Support\Str::limit((string)$item['keywords'], 46)) ?></span>
                  <?php endif; ?>
                </td>
                <td class="nowrap tiny">
                  <?php if (!empty($item['category_name'])): ?>
                    <span class="cat-dot" style="--cat:<?= e((string)$item['category_color']) ?>"></span>
                    <?= e((string)$item['category_name']) ?>
                  <?php else: ?>
                    <span class="faint">未分类</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap">
                  <?php if ((int)$item['is_top'] === 1): ?><span class="badge badge-red">置顶</span><?php endif; ?>
                  <?php if ((int)$item['is_hot'] === 1): ?><span class="badge badge-amber">热门</span><?php endif; ?>
                  <?php if ((int)$item['is_top'] === 0 && (int)$item['is_hot'] === 0): ?>
                    <span class="faint">—</span>
                  <?php endif; ?>
                </td>
                <td class="num"><?= e((string)(int)$item['views']) ?></td>
                <td class="num tiny">
                  <span style="color:var(--ok-fg)"><?= e((string)(int)$item['helpful']) ?></span>
                  /
                  <span style="color:var(--err-fg)"><?= e((string)(int)$item['unhelpful']) ?></span>
                </td>
                <td class="nowrap">
                  <?php if ((int)$item['status'] === 1): ?>
                    <span class="badge badge-green">已发布</span>
                  <?php else: ?>
                    <span class="badge badge-slate">草稿</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap faint tiny"><?= e(\App\Support\Str::timeAgo((string)$item['updated_at'])) ?></td>
                <td class="nowrap">
                  <div class="tbl-act">
                    <a href="<?= e(url('/admin/faqs/' . (int)$item['id'] . '/edit')) ?>">编辑</a>
                    <a href="<?= e(url('/knowledge#faq-' . (int)$item['id'])) ?>" target="_blank" rel="noopener">预览</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="a-card-bd">
          <?php
            $pages = $totalPages;
            $params = [];
            if ((string)$f['q'] !== '') { $params['q'] = (string)$f['q']; }
            if ((int)$f['category_id'] > 0) { $params['cat'] = (int)$f['category_id']; }
            if ($currentStatus !== '') { $params['status'] = $currentStatus; }
            $base = '/admin/faqs';
            $label = '知识库分页';
            require dirname(__DIR__) . '/partials/pager.php';
          ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('faqBulkForm');
  if (!form) { return; }
  var actField = form.querySelector('[name="act"]');
  form.querySelectorAll('[data-bulk-act]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var act = btn.getAttribute('data-bulk-act');
      if (form.querySelectorAll('[data-check-item]:checked').length === 0) {
        window.alert('请先勾选要操作的内容。');
        return;
      }
      var confirmMsg = btn.getAttribute('data-confirm');
      if (confirmMsg && !window.confirm(confirmMsg)) { return; }
      actField.value = act;
      form.submit();
    });
  });
})();
</script>
