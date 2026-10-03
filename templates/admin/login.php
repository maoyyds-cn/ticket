<?php
/**
 * 后台登录
 *
 * @var string $back
 * @var string $siteName
 */
?>
<div class="auth-shell" style="min-height:100vh">
  <div class="auth-card">
    <div class="auth-hd">
      <div class="auth-mark" aria-hidden="true"><?= brand_mark(46) ?></div>
      <h1>管理后台</h1>
      <p><?= e($siteName) ?></p>
    </div>

    <form data-guard method="post" action="<?= e(url('/admin/login')) ?>" data-busy>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">

      <div class="field">
        <label class="field-label" for="aUser">登录名</label>
        <input class="input" id="aUser" type="text" name="username" required
               value="<?= old('username') ?>" autocomplete="username" autofocus>
      </div>

      <div class="field">
        <label class="field-label" for="aPass">密码</label>
        <input class="input" id="aPass" type="password" name="password" required
               autocomplete="current-password">
      </div>

      <button class="btn btn-primary btn-block" type="submit">登录</button>
      <p class="tiny muted center mt-2 mb-0" data-busy-hint hidden>正在验证…</p>
    </form>

    <div class="auth-ft">
      <a href="<?= e(url('/')) ?>">← 返回站点首页</a>
    </div>
  </div>
</div>
