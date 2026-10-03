<?php
/**
 * 页脚
 *
 * 属于布局骨架的一部分（与导航成对），因此放在 layouts/ 下。
 * 热门分类由控制器通过 $footCategories 传入，这里不查库——
 * 旧版页脚里直接调用 category_list() 发查询，导致每个前台页面的
 * 渲染路径上都多一次数据库往返，即使页面根本不显示页脚分类。
 *
 * @var string $siteName
 * @var string $siteDesc
 * @var string $siteIcp
 * @var array  $footCategories
 * @var string $appVersion
 * @var bool   $isStaff
 * @var bool   $ticketEnabled
 */
$footCategories = $footCategories ?? [];
?>
<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="foot-brand">
          <span class="brand-mark" aria-hidden="true"><?= brand_mark(28) ?></span>
          <span><?= e($siteName) ?></span>
        </div>
        <p class="foot-desc"><?= e($siteDesc) ?></p>
      </div>

      <div>
        <h4>快速入口</h4>
        <ul class="foot-links">
          <li><a href="<?= e(url('/knowledge')) ?>">知识库</a></li>
          <?php if (empty($isStaff) && !empty($ticketEnabled)): ?>
            <li><a href="<?= e(url('/submit')) ?>">提交工单</a></li>
          <?php endif; ?>
          <li><a href="<?= e(url('/my-tickets')) ?>">我的工单</a></li>
          <li><a href="<?= e(url('/login')) ?>">登录 / 注册</a></li>
        </ul>
      </div>

      <div>
        <h4>热门分类</h4>
        <ul class="foot-links">
          <?php if ($footCategories === []): ?>
            <li class="faint tiny">暂无分类</li>
          <?php else: ?>
            <?php foreach ($footCategories as $c): ?>
              <li>
                <a href="<?= e(url('/knowledge?cat=' . (int)$c['id'])) ?>">
                  <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div>
        <h4>服务支持</h4>
        <ul class="foot-links">
          <li><a href="<?= e(url('/knowledge')) ?>">常见问题</a></li>
          <li><a href="<?= e(url('/admin/login')) ?>">管理员入口</a></li>
          <li>
            <a href="https://www.npmjs.com/package/koishi-plugin-robloxsearch"
               target="_blank" rel="noopener noreferrer">插件主页</a>
          </li>
        </ul>
      </div>
    </div>

    <div class="foot-bot">
      <span>© <?= e(date('Y')) ?> <?= e($siteName) ?> · 工单系统 v<?= e($appVersion) ?></span>
      <?php if (!empty($siteIcp)): ?><span><?= e($siteIcp) ?></span><?php endif; ?>
    </div>
  </div>
</footer>
