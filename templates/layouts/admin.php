<?php
/**
 * 后台布局
 *
 * 与旧版的差别：旧版把 shell 拆成 _head.php（开文档 + 顶栏）、
 * _layout_top.php（侧栏）、_foot.php（收尾 + 灯箱）三部分，
 * 靠 require 顺序拼起来，而且 _layout_top.php 与 _foot.php 都没有
 * 直接访问防护（单独请求会因常量未定义触发致命错误页）。
 * 这里合并为两个布局文件，且布局本身不承担任何鉴权职责——
 * 鉴权是中间件的事，页面不需要知道。
 *
 * @var string $content
 * @var string $pageTitle
 * @var string $pageDesc
 * @var string $activeNav
 * @var string $siteName
 * @var string $appVersion
 * @var bool   $isSuper
 */

/** 侧栏项：能力名 => null 表示所有后台角色可见 */
$navGroups = [
    [
        'title' => null,
        'items' => [
            ['key' => 'dashboard', 'label' => '概览', 'icon' => 'chart', 'url' => '/admin', 'cap' => null],
            ['key' => 'tickets', 'label' => '工单管理', 'icon' => 'ticket', 'url' => '/admin/tickets', 'cap' => 'ticket.view.any'],
        ],
    ],
    [
        'title' => '内容',
        'items' => [
            ['key' => 'faqs', 'label' => '知识库', 'icon' => 'book', 'url' => '/admin/faqs', 'cap' => 'faq.manage'],
            ['key' => 'categories', 'label' => '分类管理', 'icon' => 'folder', 'url' => '/admin/categories', 'cap' => 'category.manage'],
        ],
    ],
    [
        'title' => '运营',
        'items' => [
            ['key' => 'users', 'label' => '前台用户', 'icon' => 'users', 'url' => '/admin/users', 'cap' => 'user.manage'],
            ['key' => 'staff', 'label' => '成员管理', 'icon' => 'shield', 'url' => '/admin/staff', 'cap' => 'staff.manage'],
            ['key' => 'mail', 'label' => '邮件设置', 'icon' => 'mail', 'url' => '/admin/mail', 'cap' => 'mail.manage'],
            ['key' => 'logs', 'label' => '操作日志', 'icon' => 'list', 'url' => '/admin/logs', 'cap' => 'log.view'],
        ],
    ],
    [
        'title' => '系统',
        'items' => [
            ['key' => 'settings', 'label' => '系统设置', 'icon' => 'gear', 'url' => '/admin/settings', 'cap' => 'setting.manage'],
            ['key' => 'account', 'label' => '我的账号', 'icon' => 'user', 'url' => '/admin/account', 'cap' => null],
        ],
    ],
];

$staffName = $currentStaff?->displayName() ?? '';
$staffRole = $currentStaff !== null ? \App\Domain\Staff\Role::label($currentStaff->role) : '';
$initial = $staffName !== '' ? mb_strtoupper(mb_substr($staffName, 0, 1, 'UTF-8'), 'UTF-8') : '?';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · 管理后台</title>
<?php // 后台页面不应被搜索引擎收录：旧版部分页面缺少 noindex ?>
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<?php require dirname(__DIR__) . '/partials/theme-head.php'; ?>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="admin">

<a class="skip-link" href="#adminMain">跳到主要内容</a>

<div class="adm">
  <aside class="adm-side" id="admSide">
    <a class="adm-brand" href="<?= e(url('/admin')) ?>">
      <span class="brand-mark" aria-hidden="true"><?= brand_mark(30) ?></span>
      <span>管理后台</span>
    </a>

    <nav class="adm-nav" aria-label="后台导航">
      <?php foreach ($navGroups as $group):
          // 先按能力筛掉不可见的项，再判断整组是否为空——
          // 旧版把「空标题不渲染」写成了先渲染标题再判断，
          // 于是某些角色会看到只有一个标题的空分组
          $items = array_values(array_filter(
              $group['items'],
              static fn(array $item): bool => $item['cap'] === null || ($currentStaff?->can($item['cap']) ?? false)
          ));
          if ($items === []) {
              continue;
          } ?>
        <?php if ($group['title'] !== null): ?>
          <div class="adm-nav-title"><?= e($group['title']) ?></div>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
          <a class="adm-nav-item<?= $activeNav === $item['key'] ? ' is-active' : '' ?>"
             href="<?= e(url($item['url'])) ?>"
             <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
            <span class="adm-nav-ico" aria-hidden="true"><?= icon((string)$item['icon'], 18) ?></span>
            <span><?= e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="adm-side-foot">
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">查看前台 ↗</a>
      <span class="faint tiny">v<?= e($appVersion) ?></span>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-top">
      <button class="adm-burger" type="button" id="admBurger"
              aria-label="展开侧栏" aria-expanded="false" aria-controls="admSide">☰</button>
      <div class="adm-title">
        <h1><?= e($pageTitle) ?></h1>
        <?php if (!empty($pageDesc)): ?><p><?= e($pageDesc) ?></p><?php endif; ?>
      </div>
      <div class="adm-top-act">
        <?php
          /*
           * $pageActions 已移除。
           *
           * 它原本是把「各页拼好的原始 HTML」插到顶栏，但没有任何模板传它，
           * 于是只是一行永不执行的代码——却留下一个「原样输出」的口子：
           * 哪天有人真往里塞用户数据就是 XSS。顶栏现在只保留身份与退出。
           */
        ?>
        <?php require dirname(__DIR__) . '/partials/theme-toggle.php'; ?>
        <div class="adm-who">
          <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
          <span class="adm-who-txt">
            <strong><?= e($staffName) ?></strong>
            <span><?= e($staffRole) ?></span>
          </span>
          <form method="post" action="<?= e(url('/admin/logout')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">退出</button>
          </form>
        </div>
      </div>
    </header>

    <main class="adm-body" id="adminMain">
      <?php require __DIR__ . '/_flash.php'; ?>
      <?= $content ?>
    </main>
  </div>
</div>

<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/admin.js')) ?>" defer></script>
<?php require dirname(__DIR__) . '/partials/theme-script.php'; ?>
</body>
</html>
