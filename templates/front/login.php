<?php
/**
 * 登录
 *
 * @var string $back
 */
?>
<div class="auth-shell">
  <div class="auth-card">
    <div class="auth-hd">
      <div class="auth-mark" aria-hidden="true"><?= brand_mark(46) ?></div>
      <h1>登录账号</h1>
      <p>登录后可查看你提交过的全部工单</p>
    </div>

    <form data-guard method="post" action="<?= e(url('/login')) ?>" data-busy>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">

      <div class="field">
        <label class="field-label" for="lUser">用户名或邮箱</label>
        <input class="input" id="lUser" type="text" name="username" required
               value="<?= old('username') ?>" autocomplete="username" autofocus>
      </div>

      <div class="field">
        <label class="field-label" for="lPass">密码</label>
        <input class="input" id="lPass" type="password" name="password" required
               autocomplete="current-password">
      </div>

      <button class="btn btn-primary btn-block" type="submit">登录</button>
      <p class="tiny muted center mt-2 mb-0" data-busy-hint hidden>正在验证…</p>
    </form>

    <div class="auth-ft">
      还没有账号？<a href="<?= e(url('/register' . ($back !== '' ? '?back=' . rawurlencode($back) : ''))) ?>">立即注册</a>
      <span class="faint"> · </span>
      <a href="<?= e(url('/my-tickets')) ?>">用编号查工单</a>
    </div>
  </div>

  <?php
    /*
     * 这条说明原先放在 .auth-shell 里、和卡片平级。
     * 但 .auth-shell 是 flex 容器，两个子元素会并排——于是这行字被挤到卡片右侧，
     * 又小又灰，看起来像一条「出错提示」浮在表单旁边。
     * 现在它作为卡片的兄弟、但在纵向布局里位于卡片正下方，
     * 并用 .auth-note 明确表达「这是规则说明」而不是「这是错误」。
     */
  ?>
  <div class="auth-note">
    <?= icon('info', 15) ?>
    <span>
      连续 <strong><?= e((string)\App\Domain\Auth\Throttle::maxFails()) ?> 次</strong>密码错误会临时锁定账号，
      锁定时长随失败次数递增（1 分钟起，最长 30 分钟），提示里会写明还要等多久。
      忘记密码时请在工单里说明，我们会协助你找回。
    </span>
  </div>
</div>
