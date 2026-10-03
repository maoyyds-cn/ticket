<?php
/**
 * 后台概览
 *
 * @var array $stats
 * @var list<array> $latest
 * @var list<array> $todo
 * @var int $unassigned
 * @var object $staff
 */
$daily = $stats['daily'];
$maxDaily = max(1, max($daily));
$byStatus = $stats['by_status'];
$statusTotal = max(1, array_sum($byStatus));

// 邮件通道状态：管理员最常漏配的一项，放在概览上比藏在设置页里更容易被发现
$mailConfigured = $mailReady ?? null;
?>
<?php if (!empty($mailWarning)): ?>
  <div class="alert alert-warn mb-3" role="alert">
    <span class="alert-ico" aria-hidden="true"><?= icon('warn', 17) ?></span>
    <div class="alert-body">
      <div class="alert-title">邮件通知可能没有送达</div>
      <?= e($mailWarning) ?>
      <a href="<?= e(url('/admin/mail')) ?>">前往邮件设置 →</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($unassigned > 0 && $staff?->can('ticket.assign')): ?>
  <div class="alert alert-info mb-3">
    <span class="alert-ico" aria-hidden="true"><?= icon('inbox', 17) ?></span>
    <div class="alert-body">
      有 <strong><?= e((string)$unassigned) ?></strong> 条未完结工单还没有指派处理人。
      <a href="<?= e(url('/admin/tickets?assignee=-1&status=pending,processing,replied')) ?>">查看未指派工单 →</a>
    </div>
  </div>
<?php endif; ?>

<!-- 关键指标 -->
<div class="grid grid-4 mb-3">
  <div class="stat">
    <div class="stat-k">未完结工单</div>
    <div class="stat-v"><?= e((string)(int)$stats['open']) ?></div>
    <div class="stat-sub">
      其中 <a href="<?= e(url('/admin/tickets?assignee=-1')) ?>">未指派 <?= e((string)$unassigned) ?></a>
    </div>
  </div>
  <div class="stat">
    <div class="stat-k">今日新增</div>
    <div class="stat-v"><?= e((string)(int)$stats['today']) ?></div>
    <div class="stat-sub">今日已解决 <?= e((string)(int)$stats['resolved_today']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-k">累计工单</div>
    <div class="stat-v"><?= e((string)(int)$stats['total']) ?></div>
    <div class="stat-sub">
      <?php if ($stats['resolution_rate'] !== null): ?>
        解决率 <?= e((string)$stats['resolution_rate']) ?>%
      <?php else: ?>
        还没有数据
      <?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-k">平均满意度</div>
    <?php if ($stats['avg_rating'] !== null): ?>
      <div class="stat-v"><?= e((string)$stats['avg_rating']) ?><span class="faint" style="font-size:15px"> / 5</span></div>
      <div class="stat-sub">基于已评价的工单</div>
    <?php else: ?>
      <div class="stat-v is-muted">—</div>
      <div class="stat-sub">还没有用户评价</div>
    <?php endif; ?>
  </div>
</div>

<div class="a-split">
  <div>
    <!-- 趋势 -->
    <div class="a-card">
      <div class="a-card-hd">
        <h2>近 14 天新增工单</h2>
        <span class="faint tiny">合计 <?= e((string)array_sum($daily)) ?> 条</span>
      </div>
      <div class="a-card-bd">
        <div class="spark">
          <?php foreach ($daily as $date => $count): ?>
            <div class="spark-col" title="<?= e($date) ?>：<?= e((string)$count) ?> 条">
              <span class="spark-val"><?= $count > 0 ? e((string)$count) : '' ?></span>
              <span class="spark-bar" style="height:<?= e((string)max(3, (int)round($count / $maxDaily * 58))) ?>px"></span>
              <span class="spark-lbl"><?= e(date('n/j', strtotime($date))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- 我的待办 -->
    <div class="a-card">
      <div class="a-card-hd">
        <h2>我的待办</h2>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/tickets?assignee=' . $staff?->id)) ?>">查看全部</a>
      </div>
      <?php if ($todo === []): ?>
        <div class="a-card-bd">
          <p class="muted small mb-0">当前没有指派给你的未完结工单。</p>
        </div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl">
            <thead>
              <tr>
                <th>工单</th>
                <th class="nowrap">分类</th>
                <th class="nowrap">优先级</th>
                <th class="nowrap">状态</th>
                <th class="nowrap">更新时间</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($todo as $t): ?>
                <tr>
                  <td>
                    <a class="cell-main" href="<?= e(url('/admin/tickets/' . (int)$t['id'])) ?>">
                      <?= e(\App\Support\Str::limit((string)$t['title'], 40)) ?>
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
                  <td class="nowrap">
                    <span class="badge badge-<?= e(\App\Domain\Ticket\TicketPriority::tone((string)$t['priority'])) ?>">
                      <?= e(\App\Domain\Ticket\TicketPriority::label((string)$t['priority'])) ?>
                    </span>
                  </td>
                  <td class="nowrap">
                    <span class="badge badge-<?= e(\App\Domain\Ticket\TicketStatus::tone((string)$t['status'])) ?>">
                      <?= e(\App\Domain\Ticket\TicketStatus::label((string)$t['status'])) ?>
                    </span>
                  </td>
                  <td class="nowrap faint tiny"><?= e(\App\Support\Str::timeAgo((string)$t['updated_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- 最新工单 -->
    <div class="a-card">
      <div class="a-card-hd">
        <h2>最新工单</h2>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/tickets')) ?>">全部工单</a>
      </div>
      <?php if ($latest === []): ?>
        <div class="a-card-bd">
          <p class="muted small mb-0">
            还没有任何工单。把站点地址分享给用户后，这里会显示最新提交。
          </p>
        </div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl">
            <thead>
              <tr>
                <th>工单</th>
                <th class="nowrap">提交者</th>
                <th class="nowrap">状态</th>
                <th class="nowrap">处理人</th>
                <th class="nowrap">时间</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($latest as $t): ?>
                <tr>
                  <td>
                    <a class="cell-main" href="<?= e(url('/admin/tickets/' . (int)$t['id'])) ?>">
                      <?= e(\App\Support\Str::limit((string)$t['title'], 40)) ?>
                    </a>
                    <span class="cell-sub mono"><?= e((string)$t['ticket_no']) ?></span>
                  </td>
                  <td class="nowrap tiny">
                    <?= e((string)$t['guest_name']) ?>
                    <?php if ((int)$t['user_id'] === 0): ?>
                      <span class="faint">· 访客</span>
                    <?php endif; ?>
                  </td>
                  <td class="nowrap">
                    <span class="badge badge-<?= e(\App\Domain\Ticket\TicketStatus::tone((string)$t['status'])) ?>">
                      <?= e(\App\Domain\Ticket\TicketStatus::label((string)$t['status'])) ?>
                    </span>
                  </td>
                  <td class="nowrap tiny">
                    <?php if ((int)$t['assignee_id'] > 0): ?>
                      <?= e((string)($t['assignee_realname'] ?: $t['assignee_name'])) ?>
                    <?php else: ?>
                      <span class="faint">未指派</span>
                    <?php endif; ?>
                  </td>
                  <td class="nowrap faint tiny"><?= e(\App\Support\Str::timeAgo((string)$t['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 侧栏 -->
  <div class="a-side">
    <div class="a-card">
      <div class="a-card-hd"><h2>状态分布</h2></div>
      <div class="a-card-bd">
        <?php if ((int)$stats['total'] === 0): ?>
          <p class="muted small mb-0">还没有工单数据。</p>
        <?php else: ?>
          <div class="dist">
            <?php foreach ($byStatus as $status => $count): ?>
              <?php if ($count === 0) { continue; } ?>
              <div class="dist-row">
                <span class="dist-name"><?= e(\App\Domain\Ticket\TicketStatus::label((string)$status)) ?></span>
                <span class="dist-bar">
                  <i style="width:<?= e((string)round($count / $statusTotal * 100)) ?>%"></i>
                </span>
                <span class="dist-n"><?= e((string)$count) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="a-card">
      <div class="a-card-hd"><h2>分类分布</h2></div>
      <div class="a-card-bd">
        <?php if ($stats['by_category'] === []): ?>
          <p class="muted small mb-0">还没有分类数据。</p>
        <?php else: ?>
          <ul class="side-list">
            <?php foreach (array_slice($stats['by_category'], 0, 8) as $c): ?>
              <li>
                <a class="t" href="<?= e(url('/admin/tickets?cat=' . (int)$c['category_id'])) ?>">
                  <span class="cat-dot" style="--cat:<?= e((string)$c['color']) ?>"></span>
                  <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
                </a>
                <span class="n"><?= e((string)$c['n']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="a-card">
      <div class="a-card-hd"><h2>服务指标</h2></div>
      <div class="a-card-bd">
        <dl class="kv">
          <div>
            <dt>平均首次响应</dt>
            <dd>
              <?php if ($stats['avg_first_response'] !== null): ?>
                <?php $m = (int)$stats['avg_first_response']; ?>
                <?= e($m < 60 ? $m . ' 分钟' : round($m / 60, 1) . ' 小时') ?>
              <?php else: ?>
                <span class="faint">暂无数据</span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>解决率</dt>
            <dd>
              <?= $stats['resolution_rate'] !== null
                  ? e((string)$stats['resolution_rate']) . '%'
                  : '<span class="faint">暂无数据</span>' ?>
            </dd>
          </div>
          <div><dt>垃圾工单</dt><dd><?= e((string)(int)$stats['spam']) ?></dd></div>
          <div>
            <dt>今日解决</dt>
            <dd><?= e((string)(int)$stats['resolved_today']) ?></dd>
          </div>
        </dl>
      </div>
    </div>

    <?php if ($staff?->can('setting.manage')): ?>
      <div class="a-card">
        <div class="a-card-hd"><h2>快捷入口</h2></div>
        <div class="a-card-bd">
          <div class="stack-sm">
            <a class="btn btn-secondary btn-block btn-sm" href="<?= e(url('/admin/faqs/new')) ?>">＋ 新建知识库条目</a>
            <a class="btn btn-secondary btn-block btn-sm" href="<?= e(url('/admin/settings')) ?>">系统设置</a>
            <a class="btn btn-secondary btn-block btn-sm" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">查看前台站点 ↗</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
