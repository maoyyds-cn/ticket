<?php
/**
 * 知识库 / FAQ
 */
require __DIR__ . '/includes/bootstrap.php';

$catId   = get_int('cat');
$kw      = trim((string)get('q'));
$sort    = (string)get('sort', 'default');
$page    = max(1, get_int('page', 1));
$perPage = 12;

$cats = category_list(false, true);

// 条件构造
$where  = ['f.status = 1'];
$params = [];
if ($catId > 0) {
    $where[] = 'f.category_id = ?';
    $params[] = $catId;
}
if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(f.question LIKE ? OR f.answer LIKE ? OR f.keywords LIKE ?)';
    array_push($params, $like, $like, $like);
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$order = match ($sort) {
    'hot'   => 'f.views DESC, f.is_top DESC',
    'new'   => 'f.updated_at DESC',
    default => 'f.is_top DESC, f.is_hot DESC, f.sort DESC, f.id DESC',
};

$total = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq f' . $wsql, $params, 0);
$pg    = paginate($total, $perPage, $page);

$faqs = db_all(
    'SELECT f.*, c.name cname, c.icon cicon, c.color ccolor FROM ' . DB_PRE . 'faq f
     LEFT JOIN ' . DB_PRE . 'category c ON c.id = f.category_id' . $wsql . "
     ORDER BY $order LIMIT " . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $params
);

// 分类统计
$counts = [];
foreach (db_all('SELECT category_id, COUNT(*) c FROM ' . DB_PRE . 'faq WHERE status = 1 GROUP BY category_id') as $r) {
    $counts[(int)$r['category_id']] = (int)$r['c'];
}

// 浏览量：同一会话对同一条 FAQ 只计一次
$seen  = $_SESSION['faq_seen'] ?? [];
$fresh = [];
foreach ($faqs as $f) {
    $fid = (int)$f['id'];
    if (!isset($seen[$fid])) {
        $fresh[$fid] = 1;
        $seen[$fid]  = 1;
    }
}
if ($fresh) {
    // 限制会话内记录规模，避免长期浏览导致 session 膨胀
    if (count($seen) > 300) {
        $seen = array_slice($seen, -200, null, true);
    }
    $_SESSION['faq_seen'] = $seen;
    db_query('UPDATE ' . DB_PRE . 'faq SET views = views + 1 WHERE id IN (' . implode(',', array_keys($fresh)) . ')');
    foreach ($faqs as $i => $f) {
        if (isset($fresh[(int)$f['id']])) {
            $faqs[$i]['views'] = (int)$f['views'] + 1;
        }
    }
}

$pageTitle = $kw !== '' ? '搜索：' . $kw : '知识库';
$activeNav = 'kb';
require TPL_PATH . '/header.php';

// 统一构造筛选链接，避免残留旧参数或产生重复键
$kbLink = static function (array $over = []) use ($kw, $catId, $sort): string {
    $q = ['q' => $kw !== '' ? $kw : null, 'cat' => $catId > 0 ? $catId : null, 'sort' => $sort !== 'default' ? $sort : null];
    foreach ($over as $k => $v) {
        $q[$k] = $v;
    }
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return site_url('knowledge.php' . ($q ? '?' . http_build_query($q) : ''));
};
?>
<main>

<section class="page-hd">
  <div class="container">
    <div class="crumb"><a href="<?= e(site_url('index.php')) ?>">首页</a> / 知识库</div>
    <h1>知识库 · 常见问题</h1>
    <p>共 <?= e((string)$total) ?> 条内容<?= $kw !== '' ? '，关键词「' . e($kw) . '」' : '' ?></p>
  </div>
</section>

<section class="sec" style="padding-top:34px">
  <div class="container">

    <div class="search-box">
      <form method="get" action="knowledge.php">
        <span class="si">🔍</span>
        <input type="search" name="q" class="input" placeholder="搜索问题、命令、报错… 例如：签到 / 图片不显示 / 限流" value="<?= e($kw) ?>">
        <?php if ($catId > 0): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
        <button class="btn btn-p sb" type="submit">搜索</button>
      </form>
    </div>

    <div class="filters">
      <a class="chip<?= $catId === 0 ? ' on' : '' ?>" href="<?= e($kbLink(['cat' => null])) ?>">全部分类</a>
      <?php foreach ($cats as $c): ?>
        <a class="chip<?= $catId === (int)$c['id'] ? ' on' : '' ?>" href="<?= e($kbLink(['cat' => (int)$c['id']])) ?>">
          <?= e($c['icon'] . ' ' . $c['name']) ?><?= isset($counts[(int)$c['id']]) ? '（' . $counts[(int)$c['id']] . '）' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="filters" style="margin-top:-8px">
      <a class="chip<?= $sort === 'default' ? ' on' : '' ?>" href="<?= e($kbLink(['sort' => null])) ?>">推荐排序</a>
      <a class="chip<?= $sort === 'hot' ? ' on' : '' ?>" href="<?= e($kbLink(['sort' => 'hot'])) ?>">最多浏览</a>
      <a class="chip<?= $sort === 'new' ? ' on' : '' ?>" href="<?= e($kbLink(['sort' => 'new'])) ?>">最近更新</a>
    </div>

    <?php if ($faqs): ?>
      <div class="with-side">
        <div>
          <?php foreach ($faqs as $i => $f): ?>
            <div class="faq-item<?= $i === 0 ? ' open' : '' ?>" id="faq-<?= (int)$f['id'] ?>">
              <div class="faq-q">
                <span class="idx"><?= $pg['offset'] + $i + 1 ?></span>
                <span class="t">
                  <?= e($f['question']) ?>
                  <?php if ((int)$f['is_hot'] === 1): ?><span class="badge badge-red" style="margin-left:8px;font-size:11.5px">HOT</span><?php endif; ?>
                  <?php if ((int)$f['is_top'] === 1): ?><span class="badge badge-violet" style="margin-left:6px;font-size:11.5px">置顶</span><?php endif; ?>
                </span>
                <span class="arw">▼</span>
              </div>
              <div class="faq-a">
                <div class="faq-a-in">
                  <div class="rich-text"><?= rich_answer($f['answer']) ?></div>

                  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:18px;padding-top:14px;border-top:1px dashed var(--line)">
                    <div style="font-size:12.5px;color:var(--muted)">
                      <?php if (!empty($f['cname'])): ?>
                        <a href="<?= e(site_url('knowledge.php?cat=' . (int)$f['category_id'])) ?>"><?= e($f['cicon'] . ' ' . $f['cname']) ?></a> ·
                      <?php endif; ?>
                      浏览 <?= e((string)$f['views']) ?> 次 · 更新于 <?= e(fmt_date($f['updated_at'], 'Y-m-d')) ?>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                      <span style="font-size:12.5px;color:var(--muted)">这条解决了吗？</span>
                      <form method="post" action="<?= e(site_url('faq-vote.php')) ?>" style="display:inline-flex;gap:8px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <input type="hidden" name="back" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? 'knowledge.php')) ?>">
                        <button class="btn btn-o btn-sm" type="submit" name="v" value="1">👍 有用</button>
                        <button class="btn btn-o btn-sm" type="submit" name="v" value="0">👎 没用</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($pg['pages'] > 1): ?>
            <div class="pager">
              <a class="<?= $pg['has_prev'] ? '' : 'dis' ?>" href="<?= e(page_link(max(1, $pg['current'] - 1))) ?>">上一页</a>
              <?php
              $from = max(1, $pg['current'] - 2);
              $to   = min($pg['pages'], $from + 4);
              $from = max(1, $to - 4);
              if ($from > 1) { echo '<span class="gap">…</span>'; }
              for ($p = $from; $p <= $to; $p++):
                  echo $p === $pg['current']
                      ? '<span class="on">' . $p . '</span>'
                      : '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
              endfor;
              if ($to < $pg['pages']) { echo '<span class="gap">…</span>'; }
              ?>
              <a class="<?= $pg['has_next'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] + 1)) ?>">下一页</a>
            </div>
          <?php endif; ?>
        </div>

        <aside>
          <div class="side-box" style="background:linear-gradient(135deg,#eef2ff,#faf5ff);border-color:#ddd6fe">
            <h3 style="border:0;padding:0;margin-bottom:10px">💬 仍未解决？</h3>
            <p style="font-size:13.5px;color:var(--ink-2);margin-bottom:16px">提交工单，描述清楚你执行的命令和返回结果，客服会跟进处理。</p>
            <?php if (!is_staff()): ?>
              <a class="btn btn-p btn-block btn-sm" href="<?= e(site_url('submit.php')) ?>">提交工单</a>
            <?php else: ?>
              <a class="btn btn-p btn-block btn-sm" href="<?= e(site_url('admin/tickets.php')) ?>">前往工单列表</a>
            <?php endif; ?>
          </div>

          <div class="side-box">
            <h3>📂 分类导航</h3>
            <ul class="side-list">
              <?php foreach ($cats as $c): ?>
                <li>
                  <a href="<?= e(site_url('knowledge.php?cat=' . (int)$c['id'])) ?>">
                    <span><?= e($c['icon'] . ' ' . $c['name']) ?></span>
                  </a>
                  <span class="n"><?= e((string)($counts[(int)$c['id']] ?? 0)) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <div class="side-box">
            <h3>🔥 快捷提问</h3>
            <ul class="side-list">
              <li><a href="<?= e(site_url('knowledge.php?q=签到')) ?>"><span>签到怎么用？</span></a></li>
              <li><a href="<?= e(site_url('knowledge.php?q=限流')) ?>"><span>查询被限流</span></a></li>
              <li><a href="<?= e(site_url('knowledge.php?q=图片')) ?>"><span>图片加载失败</span></a></li>
              <li><a href="<?= e(site_url('knowledge.php?q=封禁')) ?>"><span>被封禁怎么办</span></a></li>
              <li><a href="<?= e(site_url('knowledge.php?q=绑定')) ?>"><span>账号绑定问题</span></a></li>
            </ul>
          </div>
        </aside>
      </div>
    <?php else: ?>
      <div class="empty">
        <div class="ic">🔍</div>
        <h3>没有找到相关内容</h3>
        <p>换个关键词试试，或直接提交工单让客服帮你排查</p>
        <?php if (!is_staff()): ?>
          <a class="btn btn-p" href="<?= e(site_url('submit.php')) ?>">提交工单</a>
        <?php else: ?>
          <a class="btn btn-p" href="<?= e(site_url('admin/tickets.php')) ?>">前往工单列表</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php require TPL_PATH . '/footer.php'; ?>
