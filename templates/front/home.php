<?php
/**
 * 首页
 *
 * @var array $stats
 * @var list<array> $categories
 * @var array<int,int> $categoryCounts
 * @var list<array> $hotFaqs
 * @var list<array> $newFaqs
 * @var int   $faqCount
 * @var string $announce
 * @var string $avgFirstText
 * @var bool  $isStaff
 * @var bool  $ticketEnabled
 */
?>
<?php /* 注意：这里不加 <main>。主内容标签由布局统一开合——
         旧版由页面开 <main>、页脚关 </main>，两个页面（访客查询、404）
         又各自多关了一次，产生重复的 </main>。 */ ?>

<!-- Hero -->
<section class="hero">
  <div class="wrap hero-in">
    <div>
      <span class="hero-tag">
        <span class="pulse" aria-hidden="true"></span>
        服务运行中<?= $avgFirstText !== '' ? ' · 平均响应 ' . e($avgFirstText) : '' ?>
      </span>
      <h1>Roblox 查询机器人<br><span class="grad">问题一站式解决</span></h1>
      <p class="hero-lead"><?= e($siteDesc !== '' ? $siteDesc : '先到知识库自助排查，多数问题一分钟内解决；仍未解决？提交工单，客服会跟进到底。') ?></p>
      <div class="hero-acts">
        <?php if (!$isStaff && $ticketEnabled): ?>
          <a class="btn btn-primary btn-lg" href="<?= e(url('/submit')) ?>">提交工单 →</a>
        <?php endif; ?>
        <a class="btn btn-secondary btn-lg" href="<?= e(url('/knowledge')) ?>">浏览知识库</a>
      </div>
    </div>

    <div class="hero-stats">
      <div class="hero-stat">
        <div class="v"><?= e(\App\Support\Str::compactNumber((int)$stats['total'])) ?></div>
        <div class="k">累计处理工单</div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= e((string)$faqCount) ?></div>
        <div class="k">知识库条目</div>
      </div>
      <?php /* 「解决率」只在确实有已解决工单时才显示数字。
              旧版在全新站点上直接显示 100%（用总数兜底），而这里若显示 0%
              同样会误导——它看起来像「一个都没解决」，实际只是还没有数据。 */ ?>
      <div class="hero-stat">
        <?php if ((int)($stats['by_status'][\App\Domain\Ticket\TicketStatus::RESOLVED] ?? 0) > 0): ?>
          <div class="v"><?= e((string)$stats['resolution_rate']) ?>%</div>
          <div class="k">问题解决率</div>
        <?php else: ?>
          <div class="v is-muted">—</div>
          <div class="k">问题解决率</div>
        <?php endif; ?>
      </div>
      <div class="hero-stat">
        <div class="v"><?= e((string)(int)$stats['today']) ?></div>
        <div class="k">今日新增</div>
      </div>
    </div>
  </div>
</section>

<?php if ($announce !== ''): ?>
<div class="wrap" style="margin-top:-26px;position:relative;z-index:5">
  <div class="alert alert-warn">
    <span class="alert-ico" aria-hidden="true"><?= icon('bell', 17) ?></span>
    <div class="alert-body">
      <div class="alert-title">系统公告</div>
      <?= nl2br(e($announce)) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 服务特点 -->
<section class="sec">
  <div class="wrap">
    <div class="grid grid-4">
      <div class="panel">
        <div class="feat">
          <div class="feat-ico" aria-hidden="true"><?= icon('book', 20) ?></div>
          <div>
            <h3>知识库自助</h3>
            <p><?= e((string)$faqCount) ?> 条常见问题与命令说明，覆盖注册、查询、积分、风控全场景。</p>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="feat">
          <div class="feat-ico" aria-hidden="true"><?= icon('bolt', 20) ?></div>
          <div>
            <h3>响应可追踪</h3>
            <p>工单提交后状态全程可见，回复与状态变化都会记录在时间线里。</p>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="feat">
          <div class="feat-ico" aria-hidden="true"><?= icon('bell', 20) ?></div>
          <div>
            <h3>邮件通知</h3>
            <p>留下邮箱后，工单有新回复或状态变化时会第一时间通知你。</p>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="feat">
          <div class="feat-ico" aria-hidden="true"><?= icon('shield', 20) ?></div>
          <div>
            <h3>反机器人防护</h3>
            <p>多层校验拦截恶意刷单，人工工单不会被垃圾信息淹没。</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- 分类 -->
<?php if ($categories !== []): ?>
<section class="sec sec-alt">
  <div class="wrap">
    <div class="sec-hd">
      <span class="eyebrow">知识库分类</span>
      <h2>按问题类型自助排查</h2>
      <p>选择对应分类快速定位问题，绝大多数场景无需提交工单。</p>
    </div>
    <div class="grid grid-4">
      <?php foreach ($categories as $c):
          $count = (int)($categoryCounts[(int)$c['id']] ?? 0);
          $color = (string)$c['color']; ?>
        <?php
          /*
           * 之前这里用字符串拼接做浅色底：$bg = $color . '1a'。
           * 对 #rrggbb 有效，但分类颜色允许 #rgb 这种三位写法
           * （normalizeColor 接受），拼出来就是 `#abc1a` —— 非法颜色值，
           * 于是整条 background 声明在计算阶段被丢弃，图标底色直接消失。
           * 改用 CSS 的 color-mix()，它自己会做混色，任何合法颜色写法都对；
           * 深色模式下也自然适配（不需要为深色单独写一组透明色）。
           */
        ?>
        <a class="cat-card" href="<?= e(url('/knowledge?cat=' . (int)$c['id'])) ?>"
           style="--cat:<?= e($color) ?>">
          <span class="cat-ico" aria-hidden="true"><?= icon(category_icon_name((string)($c['slug'] ?? ''), (string)$c['name']), 22) ?></span>
          <h3><?= e((string)$c['name']) ?></h3>
          <p><?= e((string)$c['description']) ?></p>
          <span class="cat-more"><?= e((string)$count) ?> 个问题 →</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 热门问题 + 侧栏 -->
<section class="sec">
  <div class="wrap with-side">
    <div>
      <div class="sec-hd sec-hd-left" style="text-align:left;margin-bottom:22px">
        <span class="eyebrow">高频问题</span>
        <h2 style="font-size:23px">大家都在问</h2>
      </div>

      <div class="card card-pad">
        <?php if ($hotFaqs === []): ?>
          <?php
            $icon = 'inbox';
            $title = '知识库还没有内容';
            $text = '管理员尚未发布知识库条目。如果有疑问，可以直接提交工单。';
            $actions = $ticketEnabled && !$isStaff
                ? [['url' => '/submit', 'label' => '提交工单', 'primary' => true]]
                : [];
            require dirname(__DIR__) . '/partials/empty.php';
          ?>
        <?php else: ?>
          <?php $i = 0; foreach ($hotFaqs as $faq): $i++; ?>
            <?php
              $index = $i;
              $open = $i <= 3;
              $showVote = false;
              $showLink = true;
              $voted = null;
              require dirname(__DIR__) . '/partials/faq-item.php';
            ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($hotFaqs !== []): ?>
        <div class="center mt-3">
          <a class="btn btn-secondary" href="<?= e(url('/knowledge')) ?>">查看全部 <?= e((string)$faqCount) ?> 条知识 →</a>
        </div>
      <?php endif; ?>
    </div>

    <aside class="side">
      <div class="panel">
        <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('refresh', 17) ?></span>最新更新</div>
        <ul class="side-list">
          <?php if ($newFaqs === []): ?>
            <li><span class="faint tiny">暂无数据</span></li>
          <?php else: ?>
            <?php foreach ($newFaqs as $f): ?>
              <li>
                <a class="t" href="<?= e(url('/knowledge#faq-' . (int)$f['id'])) ?>">
                  <?= e(\App\Support\Str::limit((string)$f['question'], 24)) ?>
                </a>
                <span class="n"><?= e(\App\Support\Str::timeAgo((string)$f['updated_at'])) ?></span>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="promo">
        <h3>没找到答案？</h3>
        <p>把问题描述清楚，并附上截图或报错原文，客服处理效率会高很多。</p>
        <?php if ($isStaff): ?>
          <a class="btn btn-primary btn-block btn-sm" href="<?= e(url('/admin/tickets')) ?>">前往工单列表</a>
        <?php elseif ($ticketEnabled): ?>
          <a class="btn btn-primary btn-block btn-sm" href="<?= e(url('/submit')) ?>">立即提交工单</a>
        <?php else: ?>
          <span class="badge badge-slate">工单通道已关闭</span>
        <?php endif; ?>
      </div>

      <div class="panel">
        <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('chart', 17) ?></span>服务数据</div>
        <ul class="side-list">
          <li><span>累计工单</span><span class="n"><?= e((string)(int)$stats['total']) ?></span></li>
          <li><span>未完结</span><span class="n"><?= e((string)(int)$stats['open']) ?></span></li>
          <li><span>今日新增</span><span class="n"><?= e((string)(int)$stats['today']) ?></span></li>
          <li>
            <span>平均首响</span>
            <span class="n"><?= $avgFirstText !== '' ? e($avgFirstText) : '—' ?></span>
          </li>
          <?php if ($stats['avg_rating'] !== null): ?>
            <li><span>平均满意度</span><span class="n"><?= e((string)$stats['avg_rating']) ?> / 5</span></li>
          <?php endif; ?>
        </ul>
      </div>
    </aside>
  </div>
</section>

<!-- CTA -->
<section class="sec sec-alt">
  <div class="wrap">
    <div class="cta">
      <h2>问题还在？直接找我们</h2>
      <p>提交工单后负责人会收到邮件通知，回复与状态变化都会第一时间发到你的邮箱。</p>
      <div class="row-wrap" style="justify-content:center">
        <?php if (!$isStaff && $ticketEnabled): ?>
          <a class="btn btn-primary btn-lg" href="<?= e(url('/submit')) ?>">提交工单</a>
        <?php endif; ?>
        <a class="btn btn-secondary btn-lg" href="<?= e(url('/knowledge')) ?>">再看一遍知识库</a>
      </div>
    </div>
  </div>
</section>
