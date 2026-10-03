<?php
/**
 * 邮件：新工单通知（发给管理员）
 *
 * @var string $no
 * @var string $title
 * @var string $content
 * @var string $category
 * @var string $priority
 * @var string $contact
 * @var string $site
 * @var string $time
 */
?>
<div style="margin:0;padding:24px 12px;background:#f7f9fc;font:15px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#0f172a">
  <div style="max-width:576px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:14px;overflow:hidden">
    <div style="padding:20px 26px;border-bottom:1px solid #eef2f7;background:#eef2ff">
      <strong style="font-size:15px;color:#4338ca">新工单提醒 · <?= e($site) ?></strong>
    </div>

    <div style="padding:26px">
      <h1 style="margin:0 0 16px;font-size:17px;font-weight:650"><?= e($title) ?></h1>

      <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px">
        <tr>
          <td style="padding:6px 0;color:#55637a;width:88px">工单编号</td>
          <td style="padding:6px 0;font-family:ui-monospace,Consolas,monospace;font-weight:600"><?= e($no) ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;color:#55637a">分类</td>
          <td style="padding:6px 0"><?= e($category) ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;color:#55637a">优先级</td>
          <td style="padding:6px 0"><?= e($priority) ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;color:#55637a">联系方式</td>
          <td style="padding:6px 0"><?= e($contact !== '' ? $contact : '未提供') ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;color:#55637a">提交时间</td>
          <td style="padding:6px 0"><?= e($time) ?></td>
        </tr>
      </table>

      <div style="padding:14px 16px;background:#fbfcfe;border:1px solid #eef2f7;border-radius:10px;font-size:13.5px;color:#334155;white-space:pre-wrap"><?= e($content) ?></div>

      <p style="margin:20px 0 0;font-size:13px;color:#55637a">
        请登录后台「工单管理」查看并处理。
      </p>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送。可在「邮件设置 → 管理员通知邮箱」中关闭或修改接收地址。
    </div>
  </div>
</div>
