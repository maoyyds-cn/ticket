<?php
/**
 * 无壳布局（登录页等）
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $siteName
 */
$title = ($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $siteName : $siteName;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<?php require dirname(__DIR__) . '/partials/theme-head.php'; ?>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/_flash.php'; ?>
<div class="theme-float"><?php require dirname(__DIR__) . '/partials/theme-toggle.php'; ?></div>
<?= $content ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php require dirname(__DIR__) . '/partials/theme-script.php'; ?>
</body>
</html>
