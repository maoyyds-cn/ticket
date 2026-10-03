<?php
/** 后台页面头 + 顶栏 + flash
 * 使用：$pageTitle, $pageDesc(可选), $pageActions(可选 HTML)
 */
if (!defined('DB_PRE')) exit('direct access denied');
$admin = $admin ?? require_admin();
$flashes = flash_get();
$pageTitle = $pageTitle ?? '后台';
$pageDesc = $pageDesc ?? '';
$pageActions = $pageActions ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> · 工单管理后台</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
<link rel="stylesheet" href="<?= e(site_url('assets/css/admin.css')) ?>?v=<?= e(APP_VER) ?>">
<?= turnstile_head() ?>
</head>
<body>
<div class="adm">
<?php require __DIR__ . '/_layout_top.php'; ?>
<div class="main">
  <div class="topbar">
    <button class="burger" aria-label="菜单">☰</button>
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($pageDesc !== ''): ?><span class="muted small"><?= e($pageDesc) ?></span><?php endif; ?>
    <span class="sp"></span>
    <?= $pageActions ?>
    <div class="who">
      <div class="avatar"><?= e(mb_substr($admin['realname'] ?: $admin['username'], 0, 1)) ?></div>
      <span><?= e($admin['realname'] ?: $admin['username']) ?> · <?= e(admin_role_label($admin['role'])) ?></span>
    </div>
  </div>

  <div class="body">
    <?php if ($flashes): ?>
      <?php foreach ($flashes as $f):
        $map = ['ok' => ['ok', '✓'], 'error' => ['err', '!'], 'warn' => ['warn', '⚠'], 'info' => ['info', 'ℹ']];
        [$cls, $ic] = $map[$f['type']] ?? $map['info']; ?>
        <div class="alert alert-<?= e($cls) ?>">
          <span class="ic"><?= $ic ?></span><div><?= e($f['msg']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
