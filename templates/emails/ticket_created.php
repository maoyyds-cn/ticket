<?php
/**
 * 邮件：工单提交成功
 *
 * @var bool $plain 是否纯文本版本
 * @var string $no
 * @var string $title
 * @var string $link
 * @var string $site
 * @var string $time
 * @var string $has_key
 * @var string $key
 * @var string $content
 */
$plain = $plain ?? false;

if ($plain) {
    echo "你好，\n\n";
    echo "你的工单已提交成功，客服会尽快处理。\n\n";
    echo "工单编号：{$no}\n";
    echo "问题标题：{$title}\n";
    echo "提交时间：{$time}\n";
    if ($has_key === '1' && $key !== '') {
        echo "访问密钥：{$key}\n";
        echo "（密钥遗失后无法找回，请立即记录）\n";
    }
    echo "\n查看进度：{$link}\n\n";
    echo "--- 问题描述 ---\n{$content}\n";
    echo "\n此邮件由系统自动发送，请勿直接回复。\n{$site}";
    return;
}
?>
<div style="margin:0;padding:24px 12px;background:#f7f9fc;font:15px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#0f172a">
  <div style="max-width:576px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:14px;overflow:hidden">
    <div style="padding:20px 26px;border-bottom:1px solid #eef2f7;background:#fbfcfe">
      <strong style="font-size:15px"><?= e($site) ?></strong>
    </div>

    <div style="padding:26px">
      <h1 style="margin:0 0 14px;font-size:18px;font-weight:650">工单已提交</h1>
      <p style="margin:0 0 18px;color:#334155">
        我们已经收到你的问题，客服会尽快查看并回复。后续进展会通过邮件通知你。
      </p>

      <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px">
        <tr>
          <td style="padding:7px 0;color:#55637a;width:88px">工单编号</td>
          <td style="padding:7px 0;font-family:ui-monospace,Consolas,monospace;font-weight:600"><?= e($no) ?></td>
        </tr>
        <tr>
          <td style="padding:7px 0;color:#55637a">问题标题</td>
          <td style="padding:7px 0"><?= e($title) ?></td>
        </tr>
        <tr>
          <td style="padding:7px 0;color:#55637a">提交时间</td>
          <td style="padding:7px 0"><?= e($time) ?></td>
        </tr>
        <?php if ($has_key === '1' && $key !== ''): ?>
          <tr>
            <td style="padding:7px 0;color:#55637a">访问密钥</td>
            <td style="padding:7px 0;font-family:ui-monospace,Consolas,monospace;font-weight:600"><?= e($key) ?></td>
          </tr>
        <?php endif; ?>
      </table>

      <?php if ($has_key === '1' && $key !== ''): ?>
        <div style="padding:12px 15px;background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;font-size:13px;color:#b45309;margin-bottom:20px">
          <strong>请立即记录工单编号与访问密钥。</strong>
          系统只保存密钥的哈希值，<strong>遗失后无法找回</strong>。
          你也可以注册账号来集中查看所有工单。
        </div>
      <?php endif; ?>

      <a href="<?= e($link) ?>"
         style="display:inline-block;padding:11px 24px;background:#4f46e5;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:14.5px">
        查看工单进度
      </a>

      <div style="margin-top:24px;padding-top:18px;border-top:1px solid #eef2f7">
        <p style="margin:0 0 8px;font-size:13px;color:#55637a">你提交的问题描述：</p>
        <div style="padding:13px 15px;background:#fbfcfe;border:1px solid #eef2f7;border-radius:9px;font-size:13.5px;color:#334155;white-space:pre-wrap"><?= e($content) ?></div>
      </div>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送，请勿直接回复。如需补充信息，请打开上面的链接在工单里回复。
    </div>
  </div>
</div>
