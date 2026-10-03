<?php
/**
 * 注册
 *
 * @var string $back
 * @var bool   $requireEmailCode
 * @var bool   $mailReady
 */
?>
<div class="auth-shell">
  <div class="auth-card">
    <div class="auth-hd">
      <div class="auth-mark" aria-hidden="true"><?= brand_mark(46) ?></div>
      <h1>创建账号</h1>
      <p>注册后提交的工单会自动关联，随时可查</p>
    </div>

    <?php if ($requireEmailCode && !$mailReady): ?>
      <?php /* 旧版默认就是「要求邮箱验证码 + 邮件通道未配置」这个组合，
             结果是注册流程永远走不通，而页面上没有任何地方说明原因。 */ ?>
      <div class="alert alert-warn mb-2">
        <span class="alert-ico" aria-hidden="true"><?= icon('warn', 17) ?></span>
        <div class="alert-body">
          站点当前要求邮箱验证码，但邮件通道尚未配置完成，验证码无法发出。
          请联系管理员，或稍后再试。
        </div>
      </div>
    <?php endif; ?>

    <form data-guard method="post" action="<?= e(url('/register')) ?>" data-busy>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">

      <?php /* 蜜罐 */ ?>
      <div class="hp" aria-hidden="true">
        <input type="text" name="hp_website" tabindex="-1" autocomplete="off">
        <input type="email" name="hp_email" tabindex="-1" autocomplete="off">
      </div>

      <div class="field">
        <label class="field-label" for="rUser">用户名</label>
        <input class="input" id="rUser" type="text" name="username" required
               value="<?= old('username') ?>" autocomplete="username"
               maxlength="32" autofocus>
        <div class="field-tip">2–32 位，可使用中英文、数字与下划线。</div>
      </div>

      <div class="field">
        <label class="field-label" for="rEmail">邮箱</label>
        <input class="input" id="rEmail" type="email" name="email" required
               value="<?= old('email') ?>" autocomplete="email" maxlength="120"
               placeholder="you@example.com">
        <div class="field-tip">用于接收工单通知，也是找回工单的凭据。</div>
      </div>

      <?php if ($requireEmailCode): ?>
        <div class="field">
          <label class="field-label" for="rCode">邮箱验证码</label>
          <div class="code-row">
            <input class="input" id="rCode" type="text" name="email_code" inputmode="numeric"
                   maxlength="6" autocomplete="off" placeholder="6 位数字">
            <button class="btn btn-secondary" type="button"
                    data-send-code="<?= e(url('/api/email-code')) ?>">获取验证码</button>
          </div>
          <div class="field-tip" data-code-status>验证码 10 分钟内有效。</div>
        </div>
      <?php endif; ?>

      <div class="field">
        <label class="field-label" for="rQq">QQ 号 <span class="opt">选填</span></label>
        <input class="input" id="rQq" type="text" name="qq" inputmode="numeric"
               value="<?= old('qq') ?>" maxlength="12">
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="rPass">密码</label>
          <input class="input" id="rPass" type="password" name="password" required
                 autocomplete="new-password" minlength="8">
          <div class="field-tip">至少 8 位，建议包含字母与数字。</div>
        </div>
        <div class="field">
          <label class="field-label" for="rPass2">确认密码</label>
          <input class="input" id="rPass2" type="password" name="password2" required
                 autocomplete="new-password" minlength="8">
        </div>
      </div>

      <button class="btn btn-primary btn-block" type="submit">注册并登录</button>
      <p class="tiny muted center mt-2 mb-0" data-busy-hint hidden>正在创建账号…</p>
    </form>

    <div class="auth-ft">
      已经有账号？<a href="<?= e(url('/login' . ($back !== '' ? '?back=' . rawurlencode($back) : ''))) ?>">直接登录</a>
    </div>
  </div>
</div>
