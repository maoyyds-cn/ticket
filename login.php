<?php
/** 前台登录 */
require __DIR__ . '/includes/bootstrap.php';

if (is_login()) {
    redirect('my-tickets.php');
}

$back = (string)get('back');
if (!str_starts_with($back, '/') || str_contains($back, '..')) {
    $back = 'my-tickets.php';
}

if (is_post()) {
    csrf_guard();
    $username = post('username');
    $password = post('password');

    $u = db_row('SELECT * FROM ' . DB_PRE . 'user WHERE username = ?', [$username]);
    if (!$u || !password_verify($password, $u['password'])) {
        // 防爆破
        $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0) + 1;
        $wait = $_SESSION['login_fail'] >= 5 ? min(300, 30 * ($_SESSION['login_fail'] - 4)) : 0;
        flash('error', $wait > 0
            ? '登录失败次数过多，请 ' . $wait . ' 秒后再试'
            : '用户名或密码错误');
        redirect('login.php?back=' . urlencode($back));
    }
    if ((int)$u['status'] !== 1) {
        flash('error', '该账号已被禁用，请联系管理员');
        redirect('login.php?back=' . urlencode($back));
    }

    unset($_SESSION['login_fail']);
    user_login((int)$u['id'], client_ip());
    flash('ok', '欢迎回来，' . $u['username']);
    redirect($back);
}

$pageTitle = '登录';
require TPL_PATH . '/header.php';
?>
<main>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-hd">
      <div class="logo-mark">🎫</div>
      <h1>登录账号</h1>
      <p>登录后可统一管理你的全部工单</p>
    </div>

    <form method="post" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="field">
        <label>用户名</label>
        <input type="text" name="username" class="input" required autocomplete="username" placeholder="请输入用户名" value="<?= e(old('username')) ?>">
      </div>
      <div class="field">
        <label>密码</label>
        <input type="password" name="password" class="input" required autocomplete="current-password" placeholder="请输入密码">
      </div>
      <button class="btn btn-p btn-block btn-lg" type="submit">登录</button>
    </form>

    <div class="divider">还没有账号？</div>
    <div class="auth-ft"><a href="<?= e(site_url('register.php?back=' . urlencode($back))) ?>">免费注册</a> · <a href="<?= e(site_url('my-tickets.php')) ?>">用邮箱查询工单</a></div>
  </div>
</div>
<?php require TPL_PATH . '/footer.php'; ?>
