<?php
/**
 * 操作日志
 *
 * @var list<array> $rows
 * @var array $filters
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var list<string> $actionTypes
 * @var array<string,string> $actionLabels
 */
$f = $filters;
?>
<div class="a-card">
  <form class="a-card-bd" method="get" action="<?= e(url('/admin/logs')) ?>">
    <div class="filters">
      <div class="f-item grow">
        <label for="lq">关键词</label>
        <input class="input" id="lq" type="search" name="q" value="<?= e((string)$f['q']) ?>"
               placeholder="详情内容、操作人或工单标题">
      </div>
      <div class="f-item">
        <label for="laction">操作类型</label>
        <select class="select select-sm" id="laction" name="action" data-autosubmit>
          <option value="">全部类型</option>
          <?php foreach ($actionTypes as $action): ?>
            <option value="<?= e($action) ?>"<?= (string)$f['action'] === $action ? ' selected' : '' ?>>
              <?= e($actionLabels[$action] ?? $action) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-item">
        <label for="lno">工单编号</label>
        <input class="input select-sm mono" id="lno" type="text" name="no"
               value="<?= e((string)$f['ticket_no']) ?>" placeholder="RB2026…">
      </div>
      <div class="f-item">
        <label for="lfrom">开始日期</label>
        <input class="input select-sm" id="lfrom" type="date" name="from" value="<?= e((string)$f['created_from']) ?>">
      </div>
      <div class="f-item">
        <label for="lto">结束日期</label>
        <input class="input select-sm" id="lto" type="date" name="to" value="<?= e((string)$f['created_to']) ?>">
      </div>
      <div class="f-item">
        <span aria-hidden="true">&nbsp;</span>
        <div class="row-wrap">
          <button class="btn btn-primary btn-sm" type="submit">筛选</button>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/logs')) ?>">重置</a>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="a-card">
  <div class="a-card-hd">
    <h2>操作记录</h2>
    <div class="row-wrap">
      <span class="faint tiny">共 <?= e((string)$total) ?> 条</span>
      <?php if ($isSuper): ?>
        <button class="btn btn-secondary btn-sm" type="button" data-modal="mPrune">清理旧日志</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($rows === []): ?>
    <div class="a-card-bd">
      <?php
        $icon = 'list';
        $title = '没有符合条件的操作记录';
        $text = '工单的提交、回复、状态变更、指派等操作都会记录在这里。';
        $actions = [];
        require dirname(__DIR__) . '/partials/empty.php';
      ?>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th class="nowrap">时间</th>
            <th class="nowrap">操作人</th>
            <th class="nowrap">类型</th>
            <th>详情</th>
            <th class="nowrap">关联工单</th>
            <th class="nowrap">IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $log): ?>
            <tr>
              <td class="nowrap faint tiny"><?= e(\App\Support\Str::datetime((string)$log['created_at'], 'm-d H:i:s')) ?></td>
              <td class="nowrap tiny"><?= e((string)$log['staff_name'] ?: '系统') ?></td>
              <td class="nowrap">
                <span class="badge badge-slate">
                  <?= e($actionLabels[(string)$log['action']] ?? (string)$log['action']) ?>
                </span>
              </td>
              <td class="tiny"><?= e((string)$log['detail']) ?></td>
              <td class="nowrap tiny">
                <?php if (!empty($log['ticket_no'])): ?>
                  <a class="mono" href="<?= e(url('/admin/tickets/' . (int)$log['ticket_id'])) ?>">
                    <?= e((string)$log['ticket_no']) ?>
                  </a>
                <?php else: ?>
                  <span class="faint">—</span>
                <?php endif; ?>
              </td>
              <td class="nowrap faint tiny mono"><?= e((string)$log['ip']) ?></td>
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
          foreach (['q', 'action', 'no', 'from', 'to'] as $k) {
              if ((string)($f[$k === 'no' ? 'ticket_no' : ($k === 'from' ? 'created_from' : ($k === 'to' ? 'created_to' : $k))] ?? '') !== '') {
                  $params[$k] = (string)$f[$k === 'no' ? 'ticket_no' : ($k === 'from' ? 'created_from' : ($k === 'to' ? 'created_to' : $k))];
              }
          }
          $base = '/admin/logs';
          $label = '日志分页';
          require dirname(__DIR__) . '/partials/pager.php';
        ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($isSuper): ?>
  <div class="a-card">
    <div class="a-card-hd"><h2>关于日志</h2></div>
    <div class="a-card-bd">
      <p class="tiny muted mb-0">
        操作日志是排查「谁改了什么」的唯一依据。清理时只能选择保留最近若干天，
        并且不允许清空全部——旧版用一个没有条件的删除语句支持「清空全部日志」，
        而清空这个动作本身又不留记录，等于把审计线索一次性抹掉且无从追究。
      </p>
    </div>
  </div>

  <div class="modal" id="mPrune" role="dialog" aria-modal="true" aria-labelledby="mPruneTitle">
    <div class="modal-box">
      <form method="post" action="<?= e(url('/admin/logs/prune')) ?>"
            data-confirm-form="确定清理旧日志吗？清理后无法恢复。">
        <?= csrf_field() ?>
        <div class="modal-hd">
          <h3 id="mPruneTitle">清理旧日志</h3>
          <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="关闭">✕</button>
        </div>
        <div class="modal-bd">
          <div class="field mb-0">
            <label class="field-label" for="keepDays">保留范围</label>
            <select class="select" id="keepDays" name="keep_days" required>
              <option value="180">保留最近 180 天</option>
              <option value="90">保留最近 90 天</option>
              <option value="30">保留最近 30 天</option>
            </select>
            <div class="field-tip">更早的记录会被永久删除，且本次清理会记入日志。</div>
          </div>
        </div>
        <div class="modal-ft">
          <button class="btn btn-secondary" type="button" data-modal-close>取消</button>
          <button class="btn btn-danger" type="submit">确认清理</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
