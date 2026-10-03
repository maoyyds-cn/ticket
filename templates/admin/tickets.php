<?php
/**
 * 工单管理
 *
 * @var list<array> $items
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var array $filters
 * @var array<string,int> $statusCounts
 * @var int $allCount
 * @var list<array> $categories
 * @var list<array> $assignees
 * @var bool $canExport
 * @var string $activeStatus
 * @var object $staff
 */
// 用 ?? 兜住：漏传 $staff 时，模板应当少渲染一个按钮，
// 而不是整页 500（这个情况实际发生过一次）。
$staff = $staff ?? null;
$f = $filters;
$statusParam = is_array($f['status']) ? implode(',', $f['status']) : '';

/**
 * 生成筛选用地址。
 *
 * 这里用 q() 而不是旧版的 page_link()：page_link() 直接 http_build_query($_GET)，
 * 于是「全部」按钮会因为把当前 status 又带上一次而永远清不掉筛选，
 * 并且会产生 ?status=pending&status= 这种重复参数。
 */
$filterUrl = static function (array $overrides) use ($f, $statusParam): string {
    $params = [
        'q' => (string)$f['q'],
        'status' => $statusParam,
        'priority' => is_array($f['priority']) ? implode(',', $f['priority']) : '',
        'cat' => (int)$f['category_id'] > 0 ? (string)(int)$f['category_id'] : '',
        'assignee' => (int)$f['assignee_id'] !== 0 ? (string)(int)$f['assignee_id'] : '',
        'from' => (string)$f['created_from'],
        'to' => (string)$f['created_to'],
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    // 去掉空值，避免地址里出现一堆空参数
    $params = array_filter($params, static fn($v): bool => $v !== '' && $v !== null);
    return url('/admin/tickets' . ($params !== [] ? '?' . http_build_query($params) : ''));
};

$statusChips = [
    '' => ['label' => '全部', 'count' => $allCount],
    'pending' => ['label' => '待处理', 'count' => (int)($statusCounts['pending'] ?? 0)],
    'processing' => ['label' => '处理中', 'count' => (int)($statusCounts['processing'] ?? 0)],
    'replied' => ['label' => '已回复', 'count' => (int)($statusCounts['replied'] ?? 0)],
    'resolved' => ['label' => '已解决', 'count' => (int)($statusCounts['resolved'] ?? 0)],
    'closed' => ['label' => '已关闭', 'count' => (int)($statusCounts['closed'] ?? 0)],
    'spam' => ['label' => '垃圾', 'count' => (int)($statusCounts['spam'] ?? 0)],
];
$currentUrl = '/admin/tickets' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<div class="a-card">
  <!-- 筛选 -->
  <form data-guard class="a-card-bd" method="get" action="<?= e(url('/admin/tickets')) ?>">
    <div class="filters">
      <div class="f-item grow">
        <label for="fq">关键词</label>
        <input class="input" id="fq" type="search" name="q" value="<?= e((string)$f['q']) ?>"
               placeholder="标题、编号或正文内容">
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
        <label for="fpri">优先级</label>
        <select class="select select-sm" id="fpri" name="priority" data-autosubmit>
          <option value="">全部优先级</option>
          <?php foreach ($priorityOptions as $value => $label): ?>
            <?php $sel = in_array($value, is_array($f['priority']) ? $f['priority'] : [], true); ?>
            <option value="<?= e($value) ?>"<?= $sel ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-item">
        <label for="fasg">处理人</label>
        <select class="select select-sm" id="fasg" name="assignee" data-autosubmit>
          <option value="">全部</option>
          <option value="-1"<?= (int)$f['assignee_id'] === -1 ? ' selected' : '' ?>>未指派</option>
          <?php foreach ($assignees as $a): ?>
            <option value="<?= e((string)(int)$a['id']) ?>"<?= (int)$f['assignee_id'] === (int)$a['id'] ? ' selected' : '' ?>>
              <?= e((string)($a['realname'] ?: $a['username'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-item">
        <label for="ffrom">开始日期</label>
        <input class="input select-sm" id="ffrom" type="date" name="from" value="<?= e((string)$f['created_from']) ?>">
      </div>
      <div class="f-item">
        <label for="fto">结束日期</label>
        <input class="input select-sm" id="fto" type="date" name="to" value="<?= e((string)$f['created_to']) ?>">
      </div>
      <?php /* 保留当前状态，否则点「筛选」会把状态筛选丢掉 */ ?>
      <?php if ($statusParam !== ''): ?>
        <input type="hidden" name="status" value="<?= e($statusParam) ?>">
      <?php endif; ?>
      <div class="f-item">
        <span class="f-item-label" aria-hidden="true">&nbsp;</span>
        <div class="row-wrap">
          <button class="btn btn-primary btn-sm" type="submit">筛选</button>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/tickets')) ?>">重置</a>
        </div>
      </div>
    </div>
  </form>

  <!-- 状态快捷筛选 -->
  <div class="a-card-bd" style="border-top:1px solid var(--border-2);padding-top:14px;padding-bottom:14px">
    <div class="row-wrap">
      <?php foreach ($statusChips as $value => $chip): ?>
        <a class="chip<?= $statusParam === $value ? ' is-active' : '' ?>"
           href="<?= e($filterUrl(['status' => $value])) ?>">
          <?= e($chip['label']) ?><span class="n"><?= e((string)$chip['count']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- 列表 -->
<div class="a-card">
  <form data-guard method="post" action="<?= e(url('/admin/tickets/bulk')) ?>" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
    <input type="hidden" name="act" value="">
    <input type="hidden" name="assignee_id" value="">

    <div class="a-card-hd">
      <h2>工单列表</h2>
      <div class="row-wrap">
        <span class="faint tiny">共 <?= e((string)$total) ?> 条</span>
        <?php if ($canExport): ?>
          <?php
            // 导出走独立路由，且必须带上与列表完全相同的筛选条件
            // （两边共用 TicketRepository::buildWhere，因此不会出现
            //   「导出条数比列表少」这种筛选逻辑不同步的问题）
            $exportParams = [];
            foreach (['q', 'status', 'priority', 'cat', 'assignee', 'from', 'to'] as $k) {
                $v = $_GET[$k] ?? '';
                if (is_scalar($v) && (string)$v !== '') {
                    $exportParams[$k] = (string)$v;
                }
            }
          ?>
          <a class="btn btn-secondary btn-sm"
             href="<?= e(url('/admin/tickets/export' . ($exportParams !== [] ? '?' . http_build_query($exportParams) : ''))) ?>">
            导出 CSV
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- 批量操作条：勾选后才显示 -->
    <div class="bulkbar" data-bulkbar hidden>
      <span class="sel-count">已选 <span data-check-count>0</span> 条</span>
      <div class="row-wrap">
        <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="processing">标记处理中</button>
        <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="resolved">标记已解决</button>
        <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="closed">关闭</button>
        <?php if ($staff?->can('ticket.spam')): ?>
          <button class="btn btn-danger-soft btn-sm" type="button" data-bulk-act="spam"
                  data-confirm="把这些工单标记为垃圾工单？用户将看不到它们。">标记垃圾</button>
        <?php endif; ?>
        <?php if ($staff?->can('ticket.assign')): ?>
          <label class="sr-only" for="bulkAssignee">指派给</label>
          <select class="select select-sm" id="bulkAssignee" style="width:auto">
            <option value="">指派给…</option>
            <option value="0">取消指派</option>
            <?php foreach ($assignees as $a): ?>
              <option value="<?= e((string)(int)$a['id']) ?>"><?= e((string)($a['realname'] ?: $a['username'])) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-secondary btn-sm" type="button" data-bulk-act="assign">应用指派</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($items === []): ?>
      <div class="a-card-bd">
        <?php
          $icon = 'search';
          $title = '没有符合条件的工单';
          $text = '试着放宽筛选条件，或者清除全部筛选。';
          $actions = [['url' => '/admin/tickets', 'label' => '清除筛选']];
          require dirname(__DIR__) . '/partials/empty.php';
        ?>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl">
          <thead>
            <tr>
              <th class="col-check">
                <input type="checkbox" data-check-all="1" aria-label="全选本页工单">
              </th>
              <th>工单</th>
              <th class="nowrap">分类</th>
              <th class="nowrap">联系人</th>
              <th class="nowrap">状态</th>
              <th class="nowrap">优先级</th>
              <th class="nowrap">处理人</th>
              <th class="nowrap">更新时间</th>
              <th class="nowrap">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $t): ?>
              <tr>
                <td class="col-check">
                  <input type="checkbox" name="ids[]" value="<?= e((string)(int)$t['id']) ?>"
                         data-check-item aria-label="选择工单 <?= e((string)$t['ticket_no']) ?>">
                </td>
                <td>
                  <a class="cell-main" href="<?= e(url('/admin/tickets/' . (int)$t['id'])) ?>">
                    <?php if ((int)$t['is_read'] === 0): ?>
                      <span class="badge badge-blue" style="padding:1px 6px;font-size:11px">新</span>
                    <?php endif; ?>
                    <?= e(\App\Support\Str::limit((string)$t['title'], 46)) ?>
                  </a>
                  <span class="cell-sub mono"><?= e((string)$t['ticket_no']) ?></span>
                </td>
                <td class="nowrap">
                  <?php if (!empty($t['category_name'])): ?>
                    <span class="cat-dot" style="--cat:<?= e((string)$t['category_color']) ?>"></span>
                    <?= e((string)$t['category_name']) ?>
                  <?php else: ?>
                    <span class="faint">未分类</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap tiny">
                  <?= e((string)$t['guest_name']) ?>
                  <?php if ((string)$t['contact_email'] !== ''): ?>
                    <span class="cell-sub"><?= e(\App\Support\Str::maskEmail((string)$t['contact_email'])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="nowrap">
                  <span class="badge badge-<?= e(\App\Domain\Ticket\TicketStatus::tone((string)$t['status'])) ?>">
                    <?= e(\App\Domain\Ticket\TicketStatus::label((string)$t['status'])) ?>
                  </span>
                </td>
                <td class="nowrap">
                  <span class="badge badge-<?= e(\App\Domain\Ticket\TicketPriority::tone((string)$t['priority'])) ?>">
                    <?= e(\App\Domain\Ticket\TicketPriority::label((string)$t['priority'])) ?>
                  </span>
                </td>
                <td class="nowrap tiny">
                  <?php if ((int)$t['assignee_id'] > 0): ?>
                    <?= e((string)($t['assignee_realname'] ?: $t['assignee_name'])) ?>
                  <?php else: ?>
                    <span class="faint">未指派</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap faint tiny"><?= e(\App\Support\Str::timeAgo((string)$t['updated_at'])) ?></td>
                <td class="nowrap">
                  <div class="tbl-act">
                    <a href="<?= e(url('/admin/tickets/' . (int)$t['id'])) ?>">处理</a>
                    <a href="<?= e(url('/ticket/' . rawurlencode((string)$t['ticket_no']))) ?>"
                       target="_blank" rel="noopener">前台</a>
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
            if ($statusParam !== '') { $params['status'] = $statusParam; }
            if ((string)$f['q'] !== '') { $params['q'] = (string)$f['q']; }
            if ((int)$f['category_id'] > 0) { $params['cat'] = (int)$f['category_id']; }
            if ((int)$f['assignee_id'] !== 0) { $params['assignee'] = (int)$f['assignee_id']; }
            if ((string)$f['created_from'] !== '') { $params['from'] = (string)$f['created_from']; }
            if ((string)$f['created_to'] !== '') { $params['to'] = (string)$f['created_to']; }
            $base = '/admin/tickets';
            $label = '工单列表分页';
            require dirname(__DIR__) . '/partials/pager.php';
          ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </form>
</div>

<script>
// 批量操作：把动作与指派目标写进隐藏字段再提交。
// 内联脚本放在这里是可以接受的——它只服务于本页的一个控件，
// 抽成公共文件反而会让「这个按钮到底提交什么」变得难以追踪。
(function () {
  var form = document.getElementById('bulkForm');
  if (!form) { return; }
  var actField = form.querySelector('[name="act"]');
  var assigneeField = form.querySelector('[name="assignee_id"]');

  form.querySelectorAll('[data-bulk-act]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var act = btn.getAttribute('data-bulk-act');
      var checked = form.querySelectorAll('[data-check-item]:checked').length;
      if (checked === 0) {
        window.alert('请先勾选要操作的工单。');
        return;
      }
      if (act === 'assign') {
        var sel = document.getElementById('bulkAssignee');
        assigneeField.value = sel ? sel.value : '';
        if (assigneeField.value === '') {
          window.alert('请先选择要指派给谁。');
          return;
        }
      }
      var confirmMsg = btn.getAttribute('data-confirm');
      if (confirmMsg && !window.confirm(confirmMsg)) { return; }
      actField.value = act;
      form.submit();
    });
  });

  // 导出按钮现在是普通链接（带完整筛选条件），不再需要 JS 改写地址
})();
</script>
