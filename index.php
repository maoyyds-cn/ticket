<?php
/**
 * 前台首页
 */
require __DIR__ . '/includes/bootstrap.php';

$siteName = (string)setting('site_name', '工单中心');
$siteDesc = (string)setting('site_desc', '');

$cats    = category_list(true, true);
$hotFaqs = db_all('SELECT f.*, c.name cname, c.icon cicon FROM ' . DB_PRE . 'faq f
                   LEFT JOIN ' . DB_PRE . 'category c ON c.id = f.category_id
                   WHERE f.status = 1 AND (f.is_hot = 1 OR f.is_top = 1)
                   ORDER BY f.is_top DESC, f.views DESC LIMIT 8');
$newFaqs = db_all('SELECT f.*, c.name cname, c.icon cicon FROM ' . DB_PRE . 'faq f
                   LEFT JOIN ' . DB_PRE . 'category c ON c.id = f.category_id
                   WHERE f.status = 1 ORDER BY f.is_top DESC, f.sort DESC, f.id DESC LIMIT 6');

$stats = db_row('SELECT COUNT(*) total, SUM(created_at >= CURDATE()) today, SUM(status = "resolved") resolved FROM ' . DB_PRE . 'ticket');
$total = (int)($stats['total'] ?? 0);
$today = (int)($stats['today'] ?? 0);
$done  = (int)($stats['resolved'] ?? 0);
$rate  = $total > 0 ? round($done * 100 / $total) : 100;
$faqCount = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq WHERE status = 1', [], 0);
$answered = (int)db_one('SELECT COUNT(DISTINCT ticket_id) FROM ' . DB_PRE . 'ticket_reply WHERE is_internal = 0', [], 0);

// 平均首响时间（真实计算，无数据时不展示）
$avgFirst = (int)db_one(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_reply_at)), 0)
     FROM ' . DB_PRE . 'ticket WHERE first_reply_at IS NOT NULL',
    [],
    0
);
$avgFirstText = $avgFirst > 0
    ? ($avgFirst < 60 ? $avgFirst . ' 分钟内' : round($avgFirst / 60, 1) . ' 小时内')
    : '';

// 公告
$announce = trim((string)setting('home_announce', ''));

$pageTitle = $siteName;
$activeNav = 'home';
require TPL_PATH . '/header.php';
?>
<main>

<!-- Hero -->
<section class="hero">
  <div class="container hero-in">
    <div>
      <span class="hero-tag"><span class="dot"></span>服务运行中<?= $avgFirstText !== '' ? ' · 平均响应 ' . e($avgFirstText) : '' ?></span>
      <h1>Roblox 查询机器人<br><span class="hl">问题一站式解决</span></h1>
      <p class="lead"><?= e($siteDesc ?: '先到知识库自助排查，多数问题 1 分钟内解决；仍未解决？提交工单，客服会跟进到底。') ?></p>
      <div class="hero-btns">
        <?php if (!is_staff()): ?>
          <a class="btn btn-p btn-lg" href="<?= e(site_url('submit.php')) ?>">提交工单 →</a>
        <?php endif; ?>
        <a class="btn btn-o btn-lg" href="<?= e(site_url('knowledge.php')) ?>">浏览知识库</a>
      </div>
    </div>
    <div class="hero-stats">
      <div class="hstat"><div class="n"><?= e((string)fmt_num($total)) ?></div><div class="l">累计处理工单</div></div>
      <div class="hstat"><div class="n"><?= e((string)$faqCount) ?></div><div class="l">知识库条目</div></div>
      <div class="hstat"><div class="n"><?= e((string)$rate) ?>%</div><div class="l">问题解决率</div></div>
      <div class="hstat"><div class="n"><?= e((string)$today) ?></div><div class="l">今日新增</div></div>
    </div>
  </div>
</section>

<?php if ($announce !== ''): ?>
<div class="container" style="margin-top:-28px;position:relative;z-index:5">
  <div class="card card-p" style="border-left:4px solid var(--warn)">
    <div style="display:flex;gap:12px;align-items:flex-start">
      <span style="font-size:20px">📢</span>
      <div><strong style="display:block;margin-bottom:4px">系统公告</strong>
      <div class="rich-text" style="font-size:14.5px;color:var(--ink-2)"><?= nl2br(e($announce)) ?></div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 服务优势 -->
<section class="sec">
  <div class="container">
    <div class="grid g4">
      <div class="card card-p" style="text-align:center">
        <div style="font-size:30px;margin-bottom:10px">📚</div>
        <h3 style="font-size:16px;margin-bottom:7px">知识库自助</h3>
        <p style="font-size:13.5px;color:var(--muted)"><?= e((string)$faqCount) ?> 条常见问题与命令说明，覆盖注册、查询、积分、风控全场景</p>
      </div>
      <div class="card card-p" style="text-align:center">
        <div style="font-size:30px;margin-bottom:10px">⚡</div>
        <h3 style="font-size:16px;margin-bottom:7px">快速响应</h3>
        <p style="font-size:13.5px;color:var(--muted)">工单提交即邮件通知负责人，全程可追踪状态，不会漏掉你的问题</p>
      </div>
      <div class="card card-p" style="text-align:center">
        <div style="font-size:30px;margin-bottom:10px">🔔</div>
        <h3 style="font-size:16px;margin-bottom:7px">邮件通知</h3>
        <p style="font-size:13.5px;color:var(--muted)">填写邮箱后，工单有回复或状态变化时第一时间通知你</p>
      </div>
      <div class="card card-p" style="text-align:center">
        <div style="font-size:30px;margin-bottom:10px">🛡️</div>
        <h3 style="font-size:16px;margin-bottom:7px">反机器人防护</h3>
        <p style="font-size:13.5px;color:var(--muted)">四层验证拦截恶意刷单，人工工单不被垃圾信息淹没</p>
      </div>
    </div>
  </div>
</section>

<!-- 分类 -->
<section class="sec sec-alt" id="cats">
  <div class="container">
    <div class="sec-hd">
      <span class="eyebrow">知识库分类</span>
      <h2>按问题类型自助排查</h2>
      <p>选择对应分类，快速定位你遇到的问题，绝大多数场景无需提交工单</p>
    </div>
    <div class="grid g4">
      <?php foreach ($cats as $c):
        $n = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq WHERE category_id = ? AND status = 1', [(int)$c['id']], 0); ?>
        <a class="cat-card" href="<?= e(site_url('knowledge.php?cat=' . (int)$c['id'])) ?>"
           style="--c:<?= e($c['color']) ?>;--cb:<?= e($c['color']) ?>1a">
          <div class="cat-ico"><?= e($c['icon']) ?></div>
          <h3><?= e($c['name']) ?></h3>
          <p><?= e($c['description']) ?></p>
          <div class="cat-more"><?= e((string)$n) ?> 个问题 →</div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- 热门问题 -->
<section class="sec">
  <div class="container with-side">
    <div>
      <div class="sec-hd" style="text-align:left;margin-bottom:26px">
        <span class="eyebrow">高频问题</span>
        <h2 style="font-size:26px">大家都在问</h2>
      </div>
      <div id="faq-list">
        <?php $i = 0; foreach ($hotFaqs as $f): $i++; ?>
          <div class="faq-item<?= $i <= 3 ? ' open' : '' ?>">
            <div class="faq-q">
              <span class="idx"><?= $i ?></span>
              <span class="t"><?= e($f['question']) ?></span>
              <span class="arw">▼</span>
            </div>
            <div class="faq-a">
              <div class="faq-a-in">
                <div class="rich-text"><?= rich_answer($f['answer']) ?></div>
                <div style="margin-top:14px;font-size:13px;color:var(--muted)">
                  <?php if (!empty($f['cname'])): ?>
                    <a href="<?= e(site_url('knowledge.php?cat=' . (int)$f['category_id'])) ?>"><?= e($f['cicon'] . ' ' . $f['cname']) ?></a> ·
                  <?php endif; ?>
                  <a href="<?= e(site_url('knowledge.php#faq-' . (int)$f['id'])) ?>">查看完整解答 →</a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$hotFaqs): ?>
          <div class="empty"><div class="ic">📭</div><h3>暂无内容</h3><p>管理员尚未发布知识库条目</p></div>
        <?php endif; ?>
      </div>
      <div style="text-align:center;margin-top:26px">
        <a class="btn btn-o" href="<?= e(site_url('knowledge.php')) ?>">查看全部 <?= e((string)$faqCount) ?> 条知识 →</a>
      </div>
    </div>

    <aside>
      <div class="side-box">
        <h3>🆕 最新更新</h3>
        <ul class="side-list">
          <?php foreach ($newFaqs as $f): ?>
            <li>
              <a href="<?= e(site_url('knowledge.php#faq-' . (int)$f['id'])) ?>">
                <span><?= e(mb_strimwidth($f['question'], 0, 26, '…')) ?></span>
              </a>
              <span class="n"><?= e(time_ago($f['updated_at'])) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (!$newFaqs): ?><li style="color:var(--muted)">暂无数据</li><?php endif; ?>
        </ul>
      </div>

      <div class="side-box" style="background:linear-gradient(135deg,#eef2ff,#faf5ff);border-color:#ddd6fe">
        <h3 style="border:0;padding:0;margin-bottom:10px">💡 没找到答案？</h3>
        <p style="font-size:13.5px;color:var(--ink-2);margin-bottom:16px">把问题描述清楚，附上截图或报错信息，客服处理效率会高很多。</p>
        <?php if (!is_staff()): ?>
          <a class="btn btn-p btn-block btn-sm" href="<?= e(site_url('submit.php')) ?>">立即提交工单</a>
        <?php else: ?>
          <a class="btn btn-p btn-block btn-sm" href="<?= e(site_url('admin/tickets.php')) ?>">前往工单列表</a>
        <?php endif; ?>
      </div>

      <div class="side-box">
        <h3>📊 服务数据</h3>
        <ul class="side-list">
          <li><span>累计工单</span><span class="n"><?= e((string)$total) ?></span></li>
          <li><span>已解决</span><span class="n"><?= e((string)$done) ?></span></li>
          <li><span>客服回复数</span><span class="n"><?= e((string)$answered) ?></span></li>
          <li><span>解决率</span><span class="n"><?= e((string)$rate) ?>%</span></li>
        </ul>
      </div>
    </aside>
  </div>
</section>

<!-- CTA -->
<section class="sec sec-alt">
  <div class="container">
    <div class="cta">
      <h2>问题还在？直接找我们</h2>
      <p>提交工单后负责人会立即收到邮件通知，回复与状态变化都会第一时间发到你的邮箱。</p>
      <div style="display:flex;gap:13px;justify-content:center;flex-wrap:wrap">
        <?php if (!is_staff()): ?>
          <a class="btn btn-lg" href="<?= e(site_url('submit.php')) ?>">提交工单</a>
        <?php endif; ?>
        <a class="btn btn-o2 btn-lg" href="<?= e(site_url('knowledge.php')) ?>">再看一遍知识库</a>
      </div>
    </div>
  </div>
</section>

<?php require TPL_PATH . '/footer.php'; ?>
