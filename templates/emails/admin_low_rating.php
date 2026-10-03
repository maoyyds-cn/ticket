<?php
/**
 * 邮件：低分评价提醒（发给管理员）
 *
 * @var string $no
 * @var string $title
 * @var string $rating
 * @var string $note
 * @var string $site
 * @var string $time
 */
?>
<div style="margin:0;padding:24px 12px;background:#f7f9fc;font:15px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#0f172a">
  <div style="max-width:576px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:14px;overflow:hidden">
    <div style="padding:20px 26px;border-bottom:1px solid #eef2f7;background:#fef2f2">
      <strong style="font-size:15px;color:#b91c1c">低分评价提醒 · <?= e($site) ?></strong>
    </div>

    <div style="padding:26px">
      <h1 style="margin:0 0 14px;font-size:17px;font-weight:650"><?= e($title) ?></h1>

      <p style="margin:0 0 6px;font-size:13px;color:#55637a">
        工单 <span style="font-family:ui-monospace,Consolas,monospace;color:#0f172a"><?= e($no) ?></span>
        · <?= e($time) ?>
      </p>

      <div style="padding:15px 17px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;margin:18px 0">
        <div style="font-size:24px;color:#b45309;letter-spacing:3px;margin-bottom:6px"><?= e(str_repeat('★', max(0, min(5, (int)$rating)))) ?></div>
        <div style="font-size:13.5px;color:#b91c1c;font-weight:600">用户评分 <?= e($rating) ?> / 5</div>
        <?php if ($note !== ''): ?>
          <div style="margin-top:9px;font-size:13.5px;color:#334155">「<?= e($note) ?>」</div>
        <?php endif; ?>
      </div>

      <p style="margin:0;font-size:13.5px;color:#55637a">
        建议查看该工单的处理过程，确认是否存在响应过慢或沟通不足的问题。
      </p>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送。只有 1–2 星的评价会触发本提醒。
    </div>
  </div>
</div>
