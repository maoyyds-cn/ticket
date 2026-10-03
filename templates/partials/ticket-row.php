<?php
/**
 * 工单列表行
 *
 * @var array $ticket
 */
$t = $ticket;
$status = (string)$t['status'];
$priority = (string)$t['priority'];
$catColor = (string)($t['category_color'] ?? '') ?: '#4f46e5';
$url = url('/ticket/' . rawurlencode((string)$t['ticket_no']));
?>
<a class="tk-row" href="<?= e($url) ?>">
  <span class="tk-row-ico" aria-hidden="true" style="--c:<?= e($catColor) ?>">
    <?= icon((string)($t['category_icon'] ?? '') ?: 'file', 20) ?>
  </span>

  <span class="tk-row-main">
    <span class="tk-row-title"><?= e((string)$t['title']) ?></span>
    <span class="tk-row-meta">
      <span class="no"><?= e((string)$t['ticket_no']) ?></span>
      <?php if (!empty($t['category_name'])): ?>
        <span><?= e((string)$t['category_name']) ?></span>
      <?php endif; ?>
      <?php if ((int)$t['reply_count'] > 0): ?>
        <span><?= e((string)(int)$t['reply_count']) ?> 条回复</span>
      <?php endif; ?>
      <?php if (!empty($t['assignee_name']) || !empty($t['assignee_realname'])): ?>
        <span>处理人：<?= e((string)($t['assignee_realname'] ?: $t['assignee_name'])) ?></span>
      <?php endif; ?>
      <span><?= e(\App\Support\Str::timeAgo((string)$t['updated_at'])) ?></span>
    </span>
  </span>

  <span class="tk-row-side">
    <span class="badge badge-<?= e(\App\Domain\Ticket\TicketStatus::tone($status)) ?>">
      <span class="dot"></span><?= e(\App\Domain\Ticket\TicketStatus::label($status)) ?>
    </span>
    <?php if ($priority !== \App\Domain\Ticket\TicketPriority::NORMAL): ?>
      <span class="badge badge-<?= e(\App\Domain\Ticket\TicketPriority::tone($priority)) ?>">
        <?= e(\App\Domain\Ticket\TicketPriority::label($priority)) ?>
      </span>
    <?php endif; ?>
  </span>
</a>
