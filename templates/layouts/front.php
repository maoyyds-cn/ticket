<?php
/**
 * 前台主布局
 *
 * 布局只负责骨架：<head>、导航、主内容槽、页脚。
 * 与旧版的一个关键差别：flash 提示放在 <main> 内部而不是导航上方——
 * 旧版把提示渲染在粘性导航之上，滚动后就再也看不到，而且脱离了主内容区，
 * 屏幕阅读器跳转「跳到主内容」时会漏掉它。
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $pageDesc
 * @var string $bodyClass
 * @var string $siteName
 * @var string $siteDesc
 * @var bool   $noIndex
 */
$pageTitle = $pageTitle ?? '';
$pageDesc = $pageDesc ?? ($siteDesc ?? '');
$bodyClass = $bodyClass ?? '';
$noIndex = $noIndex ?? false;
$title = $pageTitle === '' ? $siteName : $pageTitle . ' · ' . $siteName;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($pageDesc !== ''): ?>
<meta name="description" content="<?= e($pageDesc) ?>">
<?php endif; ?>
<?php if (!empty($siteKeywords)): ?>
<meta name="keywords" content="<?= e($siteKeywords) ?>">
<?php endif; ?>
<?php if ($noIndex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="#4f46e5">
<meta name="color-scheme" content="light">
<?php require dirname(__DIR__) . '/partials/theme-head.php'; ?>
<?php // 内联 SVG 图标：不额外请求文件，也不会出现图标 404 ?>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">

<a class="skip-link" href="#main">跳到主要内容</a>

<?php require __DIR__ . '/_nav.php'; ?>

<main id="main">
<?php require __DIR__ . '/_flash.php'; ?>
<?= $content ?>
</main>

<?php require __DIR__ . '/_footer.php'; ?>

<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php require dirname(__DIR__) . '/partials/theme-script.php'; ?>
</body>
</html>
