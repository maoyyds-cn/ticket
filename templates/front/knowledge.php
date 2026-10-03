<?php
/**
 * 知识库
 *
 * @var list<array> $items
 * @var int   $total
 * @var int   $page
 * @var int   $totalPages
 * @var string $keyword
 * @var int   $categoryId
 * @var array|null $currentCat
 * @var string $sort
 * @var list<array> $categories
 * @var array<int,int> $categoryCounts
 * @var array<int,bool|null> $voted
 * @var int   $faqCount
 */
$sortOptions = [
    'default' => '推荐',
    'hot' => '最热',
    'helpful' => '最有用',
    'new' => '最新',
];
?>
<div class="page">
  <div class="wrap">
    <!-- 页头 -->
    <div class="detail-hd">
      <nav class="crumb" aria-label="面包屑">
        <a href="<?= e(url('/')) ?>">首页</a>
        <span class="sep" aria-hidden="true">/</span>
        <a href="<?= e(url('/knowledge')) ?>">知识库</a>
        <?php if ($currentCat !== null): ?>
          <span class="sep" aria-hidden="true">/</span>
          <span><?= e((string)$currentCat['name']) ?></span>
        <?php endif; ?>
      </nav>
      <h1 class="detail-title">
        <?= $currentCat !== null
            ? e((string)$currentCat['name'])
            : '知识库' ?>
      </h1>
      <p class="muted small mb-0">
        <?php if ($keyword !== ''): ?>
          关键词「<?= e($keyword) ?>」共找到 <strong><?= e((string)$total) ?></strong> 条内容
        <?php elseif ($currentCat !== null && (string)$currentCat['description'] !== ''): ?>
          <?= e((string)$currentCat['description']) ?>
        <?php else: ?>
          共 <?= e((string)$faqCount) ?> 条常见问题，按分类浏览或直接搜索。
        <?php endif; ?>
      </p>
    </div>

    <div class="with-side">
      <div>
        <!-- 搜索 -->
        <?php /* 搜索表单保留当前分类与排序：旧版的搜索表单会丢掉 sort，
                 于是「按最新排序后搜索」会悄悄回到默认排序 */ ?>
        <form class="card card-pad mb-2" method="get" action="<?= e(url('/knowledge')) ?>" role="search">
          <?php if ($categoryId > 0): ?>
            <input type="hidden" name="cat" value="<?= e((string)$categoryId) ?>">
          <?php endif; ?>
          <?php if ($sort !== 'default'): ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <?php endif; ?>
          <div class="code-row">
            <div class="input-icon" style="flex:1 1 auto">
              <span class="ico" aria-hidden="true"><?= icon('search', 17) ?></span>
              <label class="sr-only" for="kbSearch">搜索知识库</label>
              <input class="input" id="kbSearch" type="search" name="q"
                     value="<?= e($keyword) ?>" placeholder="输入关键词，例如：裂图、签到、限流">
            </div>
            <button class="btn btn-primary" type="submit">搜索</button>
            <?php if ($keyword !== '' || $categoryId > 0 || $sort !== 'default'): ?>
              <a class="btn btn-secondary" href="<?= e(url('/knowledge')) ?>">重置</a>
            <?php endif; ?>
          </div>
        </form>

        <!-- 分类筛选 -->
        <?php
          /*
           * 所有筛选芯片（全部 / 各分类 / 排序）都从**同一份基础参数**派生，
           * 每次只覆写自己负责的那一项。
           *
           * 之前是每处各拼各的：分类芯片保留了关键词与排序，而「全部」芯片和
           * 排序芯片各漏了一部分——搜索后点「全部」会丢失关键词，
           * 点排序会丢失分类。统一成一个 $base 之后，这类「某个入口忘了带参数」
           * 的错误不会再出现。
           */
          $base = [];
          if ($keyword !== '') { $base['q'] = $keyword; }
          if ($sort !== 'default') { $base['sort'] = $sort; }
          $baseCat = $base;
          if ($categoryId > 0) { $baseCat['cat'] = $categoryId; }
        ?>
        <?php if ($categories !== []): ?>
          <div class="row-wrap mb-2">
            <a class="chip<?= $categoryId === 0 ? ' is-active' : '' ?>"
               href="<?= e(url('/knowledge' . ($base !== [] ? '?' . http_build_query($base) : ''))) ?>">
              全部 <span class="n"><?= e((string)$faqCount) ?></span>
            </a>
            <?php foreach ($categories as $c):
                $count = (int)($categoryCounts[(int)$c['id']] ?? 0);
                if ($count === 0) { continue; }
                $params = $base;
                $params['cat'] = (int)$c['id']; ?>
              <a class="chip<?= $categoryId === (int)$c['id'] ? ' is-active' : '' ?>"
                 href="<?= e(url('/knowledge?' . http_build_query($params))) ?>">
                <?= icon(category_icon_name((string)($c['slug'] ?? ''), (string)$c['name']), 15) ?>
                <?= e((string)$c['name']) ?>
                <span class="n"><?= e((string)$count) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- 排序 -->
        <?php if ($items !== []): ?>
          <div class="row-wrap mb-2">
            <span class="faint tiny">排序：</span>
            <?php foreach ($sortOptions as $key => $label):
                $params = $baseCat;
                if ($key !== 'default') { $params['sort'] = $key; }
                else { unset($params['sort']); } ?>
              <a class="chip<?= $sort === $key ? ' is-active' : '' ?>"
                 href="<?= e(url('/knowledge' . ($params !== [] ? '?' . http_build_query($params) : ''))) ?>">
                <?= e($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- 结果 -->
        <?php if ($items === []): ?>
          <?php
            $icon = $keyword !== '' ? 'search' : 'inbox';
            $title = $keyword !== '' ? '没有找到相关内容' : '这个分类下还没有内容';
            $text = $keyword !== ''
                ? '换一个关键词试试，或者直接提交工单让客服帮你看看。'
                : '换一个分类看看，或者直接提交工单。';
            $actions = [];
            if ($ticketEnabled && !$isStaff) {
                $actions[] = ['url' => '/submit', 'label' => '提交工单', 'primary' => true];
            }
            $actions[] = ['url' => '/knowledge', 'label' => '查看全部内容'];
            require dirname(__DIR__) . '/partials/empty.php';
          ?>
        <?php else: ?>
          <div class="card card-pad">
            <?php $i = 0; foreach ($items as $faq):
                $i++;
                $index = ($page - 1) * $perPage + $i;
                // 深链（#faq-N）应直接展开对应条目，而不是保持折叠
                $open = false;
                $showVote = true;
                $showLink = false;
                // 用独立的变量接收状态：绝对不能复用 $voted 本身。
                // 之前写成 $voted = $votedMap[...] ?? ($voted[...] ?? null)，
                // 于是从第二条开始 $voted 已经变成标量/ null，
                // 后续所有条目的「你已评价」都不再显示（只有第一条对）。
                $votedState = $votedById[(int)$faq['id']] ?? null;
                $voted = $votedState;
                require dirname(__DIR__) . '/partials/faq-item.php';
            endforeach; ?>
          </div>

          <?php
            $pages = $totalPages;
            require dirname(__DIR__) . '/partials/pager.php';
          ?>
        <?php endif; ?>
      </div>

      <!-- 侧栏 -->
      <aside class="side">
        <div class="promo">
          <h3>没找到答案？</h3>
          <p>把问题描述清楚，并附上报错原文或截图，处理会快很多。</p>
          <?php if ($isStaff): ?>
            <a class="btn btn-primary btn-block btn-sm" href="<?= e(url('/admin/tickets')) ?>">前往工单列表</a>
          <?php elseif ($ticketEnabled): ?>
            <a class="btn btn-primary btn-block btn-sm" href="<?= e(url('/submit')) ?>">立即提交工单</a>
          <?php else: ?>
            <span class="badge badge-slate">工单通道已关闭</span>
          <?php endif; ?>
        </div>

        <?php if ($categories !== []): ?>
          <div class="panel">
            <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('folder', 17) ?></span>按分类浏览</div>
            <ul class="side-list">
              <?php foreach ($categories as $c):
                  $count = (int)($categoryCounts[(int)$c['id']] ?? 0); ?>
                <li>
                  <a class="t" href="<?= e(url('/knowledge?cat=' . (int)$c['id'])) ?>">
                    <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
                  </a>
                  <span class="n"><?= e((string)$count) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('bolt', 17) ?></span>常用搜索</div>
          <div class="row-wrap">
            <?php foreach (['裂图', '签到', '限流', '绑定', '兑换码', '权限不足'] as $term): ?>
              <a class="chip" href="<?= e(url('/knowledge?q=' . rawurlencode($term))) ?>"><?= e($term) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </aside>
    </div>
  </div>
</div>
