<?php
/**
 * 邮件：邮箱验证码（只有 HTML 版本，纯文本在 Mailer 里直接拼）
 *
 * @var string $code
 * @var string $scene
 * @var string $site
 * @var string $minutes
 */
?>
<div style="margin:0;padding:24px 12px;background:#f7f9fc;font:15px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;color:#0f172a">
  <div style="max-width:576px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-radius:14px;overflow:hidden">
    <div style="padding:20px 26px;border-bottom:1px solid #eef2f7;background:#fbfcfe">
      <strong style="font-size:15px"><?= e($site) ?></strong>
    </div>

    <div style="padding:26px">
      <h1 style="margin:0 0 8px;font-size:18px;font-weight:650">邮箱验证码</h1>
      <p style="margin:0 0 22px;font-size:14px;color:#334155">
        你正在进行<strong><?= e($scene) ?></strong>操作，验证码如下：
      </p>

      <div style="padding:18px;text-align:center;background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;margin-bottom:20px">
        <span style="font-family:ui-monospace,Consolas,monospace;font-size:31px;font-weight:700;letter-spacing:7px;color:#4338ca"><?= e($code) ?></span>
      </div>

      <p style="margin:0;font-size:13.5px;color:#55637a">
        验证码在 <?= e($minutes) ?> 分钟内有效，且只能使用一次。
      </p>
      <p style="margin:14px 0 0;padding:11px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;font-size:13px;color:#b45309">
        如果这不是你本人的操作，请忽略本邮件，你的账号不会受到影响。
        任何情况下我们都不会向你索取这个验证码。
      </p>
    </div>

    <div style="padding:16px 26px;border-top:1px solid #eef2f7;background:#fbfcfe;font-size:12.5px;color:#7b8794">
      此邮件由系统自动发送，请勿直接回复。
    </div>
  </div>
</div>
