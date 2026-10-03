<?php
/** 前台注册：需邮箱验证码 + Turnstile 人机验证 */
require __DIR__ . '/includes/bootstrap.php';

if (is_login()) {
    redirect('my-tickets.php');
}

$back = (string)get('back');
if (!str_starts_with($back, '/') || str_contains($back, '..')) {
    $back = 'my-tickets.php';
}

$email = strtolower(trim((string)post('email')));

// ---- 提交注册 ----
if (is_post()) {
    csrf_guard();
    $username = post('username');
    $email    = strtolower(trim((string)post('email')));
    $qq       = post('qq');
    $code     = (string)($_POST['email_code'] ?? '');
    $pass     = (string)($_POST['password'] ?? '');
    $pass2    = (string)($_POST['password2'] ?? '');
    $errors   = [];

    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $username)) {
        $errors[] = '用户名需为 3-20 位字母、数字、下划线或中文';
    }
    if (!is_valid_email($email)) {
        $errors[] = '邮箱格式不正确';
    }
    if (mb_strlen($pass) < 6) {
        $errors[] = '密码至少 6 位';
    }
    if ($pass !== $pass2) {
        $errors[] = '两次输入的密码不一致';
    }

    // 验证码校验：邮箱格式正确才有意义。
    // 后台关闭「注册需邮箱验证码」时跳过，兼容原有注册流程。
    if (!$errors && setting_int('reg_require_email_code', 1) === 1
        && !email_code_verify($email, $code, 'register')) {
        $errors[] = '邮箱验证码错误或已过期';
    }

    if (!$errors) {
        $exists = db_one('SELECT id FROM ' . DB_PRE . 'user WHERE username = ?', [$username]);
        if ($exists) {
            $errors[] = '该用户名已被注册';
        } elseif (db_one('SELECT id FROM ' . DB_PRE . 'user WHERE email = ? AND email <> ""', [$email])) {
            $errors[] = '该邮箱已被注册';
        } else {
            $uid = db_insert('INSERT INTO ' . DB_PRE . 'user (username,password,email,qq) VALUES (?,?,?,?)',
                [$username, password_hash($pass, PASSWORD_DEFAULT), $email, $qq]);
            user_login($uid, client_ip());
            db_query('DELETE FROM ' . DB_PRE . 'email_code WHERE email = ?', [$email]);
            old_clear();
            flash('ok', '注册成功，欢迎加入！');
            redirect($back);
        }
    }
    old_keep($_POST);
    foreach ($errors as $e) {
        flash('error', $e);
    }
    redirect('register.php?back=' . urlencode($back));
}

$pageTitle = '注册';
require TPL_PATH . '/header.php';
?>
<main>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-hd">
      <div class="logo-mark">🎫</div>
      <h1>创建账号</h1>
      <p>注册后无需每次填写邮箱查单</p>
    </div>

    <form method="post" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="field">
        <label>用户名 <span class="req">*</span></label>
        <input type="text" name="username" class="input" required placeholder="3-20 位，字母/数字/中文"
               value="<?= e(old('username')) ?>">
      </div>
      <div class="field">
        <label>邮箱 <span class="req">*</span></label>
        <?php if (setting_int('reg_require_email_code', 1) === 1): ?>
          <div class="code-row">
            <input type="email" name="email" class="input" id="regEmail" required
                   placeholder="用于接收验证码与工单通知" value="<?= e(old('email')) ?>">
            <button class="btn btn-g" type="button" data-sendcode
                    data-url="<?= e(site_url('send-code.php')) ?>">获取验证码</button>
          </div>
        <?php else: ?>
          <input type="email" name="email" class="input" required
                 placeholder="用于接收工单通知" value="<?= e(old('email')) ?>">
        <?php endif; ?>
      </div>
      <?php if (setting_int('reg_require_email_code', 1) === 1): ?>
      <div class="field">
        <label>验证码 <span class="req">*</span></label>
        <input type="text" name="email_code" class="input" inputmode="numeric" maxlength="6"
               required placeholder="6 位数字验证码" autocomplete="one-time-code">
      </div>
      <?php endif; ?>
      <div class="field">
        <label>QQ 号</label>
        <input type="text" name="qq" class="input" maxlength="20" placeholder="选填" value="<?= e(old('qq')) ?>">
      </div>
      <div class="form-row">
        <div class="field">
          <label>密码 <span class="req">*</span></label>
          <input type="password" name="password" class="input" required minlength="6" placeholder="至少 6 位" autocomplete="new-password">
        </div>
        <div class="field">
          <label>确认密码 <span class="req">*</span></label>
          <input type="password" name="password2" class="input" required minlength="6" placeholder="再次输入" autocomplete="new-password">
        </div>
      </div>

      <div class="field">
        <label>人机验证 <span class="req">*</span></label>
        <?= turnstile_html('register') ?>
      </div>

      <button class="btn btn-p btn-block btn-lg" type="submit">注册并登录</button>
    </form>

    <div class="divider">已有账号？</div>
    <div class="auth-ft"><a href="<?= e(site_url('login.php?back=' . urlencode($back))) ?>">去登录</a> · <a href="<?= e(site_url('index.php')) ?>">返回首页</a></div>
  </div>
</div>
<?php require TPL_PATH . '/footer.php'; ?>
