<?php
/**
 * 顶部导航
 *
 * 移动端菜单用原生 details/summary 之外的方式实现（见 app.js），
 * 但这里给按钮补上真实的 aria-expanded / aria-controls，
 * 旧版只切了个 class，屏幕阅读器完全不知道菜单开了没有。
 *
 * @var string $currentPath
 * @var bool   $isStaff
 * @var object|null $currentUser
 * @var object|null $currentStaff
 * @var string $siteName
 * @var bool   $ticketEnabled
 */

/** 判断某个路径前缀是否当前所在页 */
$navActive = static function (string $prefix) use ($currentPath): string {
    if ($prefix === '/') {
        return $currentPath === '/' ? ' is-active' : '';
    }
    return str_starts_with($currentPath, $prefix) ? ' is-active' : '';
};
$ariaCurrent = static function (string $prefix) use ($currentPath): string {
    $hit = $prefix === '/' ? $currentPath === '/' : str_starts_with($currentPath, $prefix);
    return $hit ? ' aria-current="page"' : '';
};

$identity = $currentStaff ?? $currentUser;
$displayName = $identity !== null ? $identity->displayName() : '';
$initial = $displayName !== '' ? mb_strtoupper(mb_substr($displayName, 0, 1, 'UTF-8'), 'UTF-8') : '?';
?>
<header class="nav">
  <div class="nav-in">
    <a class="brand" href="<?= e(url('/')) ?>">
      <span class="brand-mark" aria-hidden="true"><?= brand_mark(30) ?></span>
      <?php
        /*
         * 站点名在窄屏隐藏（只留图形标识）。
         *
         * 它是 white-space:nowrap 且 flex-shrink:0 的，因此在手机上是唯一的
         * 宽度主力：品牌名 + 主题切换 + 用户菜单 + ☰ 加起来比屏幕还宽，
         * 导致整页横向溢出、手机上能左右拖动。
         * 图形标识仍然保留，导航的可识别性和可达性不受影响。
         */
      ?>
      <span class="hide-sm"><?= e($siteName) ?></span>
    </a>

    <nav class="nav-menu" id="navMenu" aria-label="主导航">
      <a href="<?= e(url('/')) ?>" class="<?= ltrim($navActive('/'), ' ') ?>"<?= $ariaCurrent('/') ?>>首页</a>
      <a href="<?= e(url('/knowledge')) ?>" class="<?= ltrim($navActive('/knowledge'), ' ') ?>"<?= $ariaCurrent('/knowledge') ?>>知识库</a>
      <?php if (!$isStaff): ?>
        <?php if ($ticketEnabled): ?>
          <a href="<?= e(url('/submit')) ?>" class="<?= ltrim($navActive('/submit'), ' ') ?>"<?= $ariaCurrent('/submit') ?>>提交工单</a>
        <?php endif; ?>
        <a href="<?= e(url('/my-tickets')) ?>" class="<?= ltrim($navActive('/my-tickets'), ' ') ?>"<?= $ariaCurrent('/my-tickets') ?>>我的工单</a>
      <?php else: ?>
        <a href="<?= e(url('/admin/tickets')) ?>" class="<?= ltrim($navActive('/admin'), ' ') ?>">工单管理</a>
      <?php endif; ?>
    </nav>

    <div class="nav-actions">
      <?php require dirname(__DIR__) . '/partials/theme-toggle.php'; ?>
      <?php if ($identity !== null): ?>
        <details class="user-menu">
          <summary>
            <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
            <span class="hide-sm"><?= e($displayName) ?></span>
          </summary>
          <div class="user-menu-list">
            <div class="user-menu-head">
              <strong><?= e($displayName) ?></strong>
              <span><?= $isStaff ? e(\App\Domain\Staff\Role::label($currentStaff->role)) : '普通用户' ?></span>
            </div>
            <?php if ($isStaff): ?>
              <a href="<?= e(url('/admin')) ?>"><?= icon('chart', 16) ?>后台概览</a>
              <a href="<?= e(url('/admin/tickets')) ?>"><?= icon('ticket', 16) ?>工单管理</a>
              <a href="<?= e(url('/admin/account')) ?>"><?= icon('user', 16) ?>我的账号</a>
            <?php else: ?>
              <a href="<?= e(url('/my-tickets')) ?>"><?= icon('ticket', 16) ?>我的工单</a>
              <a href="<?= e(url('/account')) ?>"><?= icon('user', 16) ?>账号设置</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/logout')) ?>">
              <?= csrf_field() ?>
              <button type="submit"><span aria-hidden="true">↩</span>退出登录</button>
            </form>
          </div>
        </details>
      <?php else: ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/login')) ?>">登录</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>">注册</a>
      <?php endif; ?>

      <button class="nav-burger" type="button" id="navBurger"
              aria-label="展开导航菜单" aria-expanded="false" aria-controls="navMenu">☰</button>
    </div>
  </div>
</header>
