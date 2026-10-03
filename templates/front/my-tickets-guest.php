<?php
/**
 * 我的工单（未登录：按编号 + 密钥查询）
 *
 * @var string $no
 * @var string $error
 */
?>
<div class="auth-shell">
  <div style="width:100%;max-width:460px">
    <?php if ($error !== ''): ?>
      <div class="alert alert-err mb-2" role="alert">
        <span class="alert-ico" aria-hidden="true"><?= icon('bang', 17) ?></span>
        <div class="alert-body"><?= e($error) ?></div>
      </div>
    <?php endif; ?>

    <div class="auth-card">
      <div class="auth-hd">
        <div class="auth-mark" aria-hidden="true"><?= icon('search', 30) ?></div>
        <h1>查询工单进度</h1>
        <p>输入工单编号与提交时设置的访问密钥</p>
      </div>

      <form method="post" action="<?= e(url('/my-tickets')) ?>">
        <?= csrf_field() ?>

        <div class="field">
          <label class="field-label" for="qNo">工单编号 <span class="req" aria-hidden="true">*</span></label>
          <input class="input mono" id="qNo" type="text" name="no" required
                 value="<?= e($no) ?>" placeholder="例如 RB20261003-7F3A2C" autocomplete="off">
          <div class="field-tip">提交成功时页面上显示过，也发到了你的邮箱。</div>
        </div>

        <div class="field">
          <label class="field-label" for="qKey">访问密钥 <span class="req" aria-hidden="true">*</span></label>
          <input class="input" id="qKey" type="password" name="key" required
                 autocomplete="off" placeholder="提交工单时你设置的密钥">
          <div class="field-tip">
            密钥只保存哈希值，<strong>我们无法查看或找回</strong>。
            如果忘记了，可以<a href="<?= e(url('/login')) ?>">登录账号</a>查看已绑定的工单。
          </div>
        </div>

        <button class="btn btn-primary btn-block" type="submit">查询工单</button>
      </form>

      <div class="auth-ft">
        还没有账号？<a href="<?= e(url('/register')) ?>">注册</a>后提交的工单会自动关联，
        以后登录即可查看全部记录。
      </div>
    </div>

    <?php if ($ticketEnabled && !$isStaff): ?>
      <div class="center mt-2">
        <a class="btn btn-secondary" href="<?= e(url('/submit')) ?>">提交新工单</a>
      </div>
    <?php endif; ?>
  </div>
</div>
