<?php
/** @var string $pageTitle */
/** @var string $activeNav */
$pageTitle = $pageTitle ?? '首页';
$activeNav = $activeNav ?? '';
$siteName  = (string)setting('site_name', '工单中心');
$flashes   = flash_get();
$user      = current_user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle === $siteName ? $siteName : $pageTitle . ' · ' . $siteName) ?></title>
<meta name="description" content="<?= e((string)setting('site_desc', '')) ?>">
<meta name="keywords" content="<?= e((string)setting('site_keywords', '')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎫</text></svg>">
<link rel="stylesheet" href="<?= e(site_url('assets/css/main.css')) ?>?v=<?= APP_VER ?>">
<?= turnstile_head() ?>
</head>
<body>

<?php if ($flashes): ?>
<div class="container" style="padding-top:14px">
  <?php foreach ($flashes as $f):
    $map = ['ok' => ['ok', '✓'], 'error' => ['err', '!'], 'warn' => ['warn', '⚠'], 'info' => ['info', 'ℹ']];
    [$cls, $ic] = $map[$f['type']] ?? $map['info']; ?>
    <div class="alert alert-<?= e($cls) ?>" style="margin-bottom:10px">
      <span class="alert-ic"><?= $ic ?></span><div><?= e($f['msg']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<nav class="nav">
  <div class="nav-in">
    <button class="nav-toggle" aria-label="菜单">☰</button>
    <a class="logo" href="<?= e(site_url('index.php')) ?>">
      <span class="logo-mark">🎫</span><span><?= e($siteName) ?></span>
    </a>
    <div class="nav-links">
      <a href="<?= e(site_url('index.php')) ?>" class="<?= $activeNav === 'home' ? 'on' : '' ?>">首页</a>
      <a href="<?= e(site_url('knowledge.php')) ?>" class="<?= $activeNav === 'kb' ? 'on' : '' ?>">知识库</a>
      <?php if (!is_staff()): ?>
        <a href="<?= e(site_url('submit.php')) ?>" class="<?= $activeNav === 'submit' ? 'on' : '' ?>">提交工单</a>
      <?php endif; ?>
      <a href="<?= e(site_url('my-tickets.php')) ?>" class="<?= $activeNav === 'my' ? 'on' : '' ?>">我的工单</a>
      <?php if (is_admin()): ?>
        <a href="<?= e(site_url('admin/index.php')) ?>">后台管理</a>
      <?php endif; ?>
    </div>
    <div class="nav-act">
      <?php if ($user): ?>
        <a class="btn btn-o btn-sm" href="<?= e(site_url('my-tickets.php')) ?>"><?= e($user['username']) ?></a>
        <a class="btn btn-g btn-sm" href="<?= e(site_url('logout.php')) ?>">退出</a>
      <?php else: ?>
        <a class="btn btn-g btn-sm" href="<?= e(site_url('login.php')) ?>">登录</a>
        <a class="btn btn-p btn-sm" href="<?= e(site_url('register.php')) ?>">注册</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
