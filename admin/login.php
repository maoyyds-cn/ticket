<?php
/** 后台登录 */
require __DIR__ . '/../includes/bootstrap.php';

if (is_admin()) {
    redirect('index.php');
}

$back = (string)get('back');
if (!str_starts_with($back, 'admin/') || str_contains($back, '..')) {
    $back = 'index.php';
}

if (is_post()) {
    csrf_guard();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');

    $a = db_row('SELECT * FROM ' . DB_PRE . 'admin WHERE username = ?', [$username]);

    // 锁定检查
    if ($a && $a['locked_until'] && strtotime($a['locked_until']) > time()) {
        $left = strtotime($a['locked_until']) - time();
        flash('error', '账号已被锁定，请 ' . ceil($left / 60) . ' 分钟后再试');
        redirect('login.php');
    }

    if (!$a || !password_verify($password, $a['password'])) {
        if ($a) {
            $fail = (int)$a['login_fail'] + 1;
            $lockUntil = null;
            if ($fail >= 5) {
                $mins = min(60, 5 * ($fail - 4));
                $lockUntil = date('Y-m-d H:i:s', time() + $mins * 60);
                $fail = 0;
            }
            db_query('UPDATE ' . DB_PRE . 'admin SET login_fail = ?, locked_until = ? WHERE id = ?', [$fail, $lockUntil, (int)$a['id']]);
            flash('error', $lockUntil ? '登录失败次数过多，账号已锁定 ' . ceil((strtotime($lockUntil) - time()) / 60) . ' 分钟' : '用户名或密码错误');
        } else {
            flash('error', '用户名或密码错误');
        }
        redirect('login.php');
    }

    if ((int)$a['status'] !== 1) {
        flash('error', '该账号已被禁用');
        redirect('login.php');
    }

    admin_login((int)$a['id'], client_ip(), user_agent());
    flash('ok', '欢迎回来，' . ($a['realname'] ?: $a['username']));
    redirect($back);
}

$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台登录 · 工单系统</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>?v=<?= e(APP_VER) ?>">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="login-hd">
      <div class="lg">🎫</div>
      <h1>工单管理后台</h1>
      <p>请使用管理员账号登录</p>
    </div>

    <?php foreach ($flashes as $f):
      $map = ['ok' => ['ok', '✓'], 'error' => ['err', '!'], 'warn' => ['warn', '⚠'], 'info' => ['info', 'ℹ']];
      [$cls, $ic] = $map[$f['type']] ?? $map['info']; ?>
      <div class="alert alert-<?= e($cls) ?>"><span class="ic"><?= $ic ?></span><div><?= e($f['msg']) ?></div></div>
    <?php endforeach; ?>

    <form method="post" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="field">
        <label>管理员账号</label>
        <input type="text" name="username" class="input" required autocomplete="username" placeholder="请输入账号" autofocus>
      </div>
      <div class="field">
        <label>登录密码</label>
        <input type="password" name="password" class="input" required autocomplete="current-password" placeholder="请输入密码">
      </div>
      <button class="btn btn-p btn-block" type="submit" style="padding:11px">登录后台</button>
    </form>

    <div class="muted small" style="text-align:center;margin-top:22px">
      <a href="../index.php">← 返回前台</a>
    </div>
  </div>
</div>
<script src="<?= e(site_url('assets/js/progress.js')) ?>?v=<?= e(APP_VER) ?>"></script>
</body>
</html>
