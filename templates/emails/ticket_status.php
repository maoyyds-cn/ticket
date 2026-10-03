<?php
/**
 * 邮件：状态变更
 *
 * @var bool $plain
 * @var string $no
 * @var string $title
 * @var string $link
 * @var string $site
 * @var string $from_status
 * @var string $to_status
 */
$plain = $plain ?? false;

if ($plain) {
    echo "你好，\n\n";
    echo "你的工单状态已更新。\n\n";
    echo "工单编号：{$no}\n";
    echo "问题标题：{$title}\n";
    echo "状态变化：{$from_status} → {$to_status}\n\n";
    echo "查看详情：{$link}\n\n";
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
      <h1 style="margin:0 0 16px;font-size:18px;font-weight:650">工单状态已更新</h1>

      <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:18px">
        <tr>
          <td style="padding:7px 0;color:#55637a;width:88px">工单编号</td>
          <td style="padding:7px 0;font-family:ui-monospace,Consolas,monospace;font-weight:600"><?= e($no) ?></td>
        </tr>
        <tr>
          <td style="padding:7px 0;color:#55637a">问题标题</td>
          <td style="padding:7px 0"><?= e($title) ?></td>
        </tr>
        <tr>
          <td style="padding:7px 0;color:#55637a">状态变化</td>
          <td style="padding:7px 0">
            <span style="color:#55637a"><?= e($from_status) ?></span>
            <span style="color:#7b8794"> → </span>
            <strong style="color:#4338ca"><?= e($to_status) ?></strong>
          </td>
        </tr>
      </table>

      <a href="<?= e($link) ?>"
         style="display:inline-block;padding:11px 24px;background:#4f46e5;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:14.5px">
        查看工单详情
      </a>

      <p style="margin:20px 0 0;font-size:13px;color:#55637a">
        如果状态变化不符合预期，可以直接在工单里回复说明，客服会继续跟进。
      </p>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送，请勿直接回复。
    </div>
  </div>
</div>
