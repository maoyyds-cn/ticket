<?php
/**
 * 邮件：客服回复
 *
 * @var bool $plain
 * @var string $no
 * @var string $title
 * @var string $link
 * @var string $site
 * @var string $content
 */
$plain = $plain ?? false;

if ($plain) {
    echo "你好，\n\n";
    echo "你的工单有新的回复。\n\n";
    echo "工单编号：{$no}\n";
    echo "问题标题：{$title}\n\n";
    echo "--- 客服回复 ---\n{$content}\n\n";
    echo "查看完整记录并回复：{$link}\n\n";
    echo "此邮件由系统自动发送，请勿直接回复。\n{$site}";
    return;
}
?>
<div style="margin:0;padding:24px 12px;background:#f7f9fc;font:15px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#0f172a">
  <div style="max-width:576px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:14px;overflow:hidden">
    <div style="padding:20px 26px;border-bottom:1px solid #eef2f7;background:#fbfcfe">
      <strong style="font-size:15px"><?= e($site) ?></strong>
    </div>

    <div style="padding:26px">
      <h1 style="margin:0 0 14px;font-size:18px;font-weight:650">你的工单有新回复</h1>

      <p style="margin:0 0 6px;font-size:13px;color:#55637a">
        工单 <span style="font-family:ui-monospace,Consolas,monospace;color:#0f172a"><?= e($no) ?></span>
      </p>
      <p style="margin:0 0 20px;font-weight:600"><?= e($title) ?></p>

      <div style="padding:15px 17px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px">
        <div style="font-size:12.5px;font-weight:700;color:#4338ca;margin-bottom:8px">客服回复</div>
        <div style="font-size:14px;color:#334155;white-space:pre-wrap"><?= e($content) ?></div>
      </div>

      <div style="margin-top:22px">
        <a href="<?= e($link) ?>"
           style="display:inline-block;padding:11px 24px;background:#4f46e5;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:14.5px">
          查看并回复
        </a>
      </div>

      <p style="margin:20px 0 0;font-size:13px;color:#55637a">
        如果问题已经解决，可以在工单页面评价并关闭它；如果问题仍然存在，直接回复即可。
      </p>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送，请勿直接回复。
    </div>
  </div>
</div>
