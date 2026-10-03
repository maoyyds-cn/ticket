</main>

<footer class="foot">
  <div class="container">
    <div class="foot-grid">
      <div>
        <div class="foot-logo"><span class="logo-mark">🎫</span><?= e((string)setting('site_name', '工单中心')) ?></div>
        <p><?= e((string)setting('site_desc', '')) ?></p>
      </div>
      <div>
        <h4>快速入口</h4>
        <ul class="foot-links">
          <li><a href="<?= e(site_url('knowledge.php')) ?>">知识库</a></li>
          <?php if (!is_staff()): ?>
            <li><a href="<?= e(site_url('submit.php')) ?>">提交工单</a></li>
          <?php endif; ?>
          <li><a href="<?= e(site_url('my-tickets.php')) ?>">我的工单</a></li>
          <li><a href="<?= e(site_url('login.php')) ?>">登录 / 注册</a></li>
        </ul>
      </div>
      <div>
        <h4>热门分类</h4>
        <ul class="foot-links">
          <?php foreach (array_slice(category_list(false, true), 0, 6) as $c): ?>
            <li><a href="<?= e(site_url('knowledge.php?cat=' . (int)$c['id'])) ?>"><?= e($c['icon'] . ' ' . $c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4>服务支持</h4>
        <ul class="foot-links">
          <li><a href="<?= e(site_url('knowledge.php')) ?>">常见问题</a></li>
          <li><a href="<?= e(site_url('admin/login.php')) ?>">管理员入口</a></li>
          <li><a href="https://www.npmjs.com/package/koishi-plugin-robloxsearch" target="_blank" rel="noopener">插件主页</a></li>
        </ul>
      </div>
    </div>
    <div class="foot-bot">
      <span>© <?= date('Y') ?> <?= e((string)setting('site_name', '工单中心')) ?> · 工单系统 v<?= e(APP_VER) ?></span>
      <span><?= e((string)setting('site_icp', '')) ?></span>
    </div>
  </div>
</footer>

<script src="<?= e(site_url('assets/js/main.js')) ?>?v=<?= APP_VER ?>"></script>
<script src="<?= e(site_url('assets/js/progress.js')) ?>?v=<?= APP_VER ?>"></script>
</body>
</html>
