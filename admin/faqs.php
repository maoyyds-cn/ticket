<?php
/** FAQ 管理 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$catId  = get_int('cat');
$kw     = trim((string)get('q'));
$status = (string)get('status');
$page   = max(1, get_int('page', 1));
$perPage = 20;

$where  = ['1=1'];
$params = [];
if ($catId > 0)  { $where[] = 'f.category_id = ?'; $params[] = $catId; }
if ($status !== '' && in_array($status, ['0', '1'], true)) { $where[] = 'f.status = ?'; $params[] = (int)$status; }
if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(f.question LIKE ? OR f.answer LIKE ? OR f.keywords LIKE ?)';
    array_push($params, $like, $like, $like);
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$total = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq f' . $wsql, $params, 0);
$pg    = paginate($total, $perPage, $page);

$list = db_all(
    'SELECT f.*, c.name cname, c.icon cicon FROM ' . DB_PRE . 'faq f
     LEFT JOIN ' . DB_PRE . 'category c ON c.id = f.category_id' . $wsql .
    ' ORDER BY f.is_top DESC, f.is_hot DESC, f.sort DESC, f.id DESC
     LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset'],
    $params
);

$cats = category_list(false, true);

// 操作
if (is_post()) {
    csrf_guard();
    $act = post('act');
    $id  = (int)post('id');

    if ($act === 'toggle_status') {
        db_query('UPDATE ' . DB_PRE . 'faq SET status = 1 - status WHERE id = ?', [$id]);
        flash('ok', '已切换发布状态');
    } elseif ($act === 'toggle_hot') {
        db_query('UPDATE ' . DB_PRE . 'faq SET is_hot = 1 - is_hot WHERE id = ?', [$id]);
        flash('ok', '已切换热门标记');
    } elseif ($act === 'toggle_top') {
        db_query('UPDATE ' . DB_PRE . 'faq SET is_top = 1 - is_top WHERE id = ?', [$id]);
        flash('ok', '已切换置顶标记');
    } elseif ($act === 'delete') {
        db_query('DELETE FROM ' . DB_PRE . 'faq WHERE id = ?', [$id]);
        flash('ok', 'FAQ 已删除');
    } elseif ($act === 'batch_publish' || $act === 'batch_draft') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if ($ids) {
            $v = $act === 'batch_publish' ? 1 : 0;
            db_query('UPDATE ' . DB_PRE . 'faq SET status = ? WHERE id IN (' . implode(',', $ids) . ')', [$v]);
            flash('ok', '已批量更新 ' . count($ids) . ' 条');
        }
    }
    redirect('faqs.php?' . http_build_query(array_diff_key($_GET, ['page' => 1])));
}

$pageTitle = 'FAQ 知识库管理';
$pageDesc  = '共 ' . $total . ' 条';
$pageActions = '<a class="btn btn-p btn-sm" href="faq-edit.php">+ 新增 FAQ</a>';
require __DIR__ . '/_head.php';
?>

<form method="get" class="tools">
  <div class="search">
    <span class="si">🔍</span>
    <input type="search" name="q" class="input" placeholder="搜索问题 / 答案 / 关键词" value="<?= e($kw) ?>">
  </div>
  <select name="cat" class="select" style="width:auto;min-width:140px">
    <option value="">全部分类</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? ' selected' : '' ?>>
        <?= e($c['icon'] . ' ' . $c['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="status" class="select" style="width:auto;min-width:110px">
    <option value="">全部状态</option>
    <option value="1" <?= $status === '1' ? ' selected' : '' ?>>已发布</option>
    <option value="0" <?= $status === '0' ? ' selected' : '' ?>>草稿</option>
  </select>
  <button class="btn btn-p btn-sm" type="submit">筛选</button>
  <a class="btn btn-o btn-sm" href="faqs.php">重置</a>
</form>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="">
  <div class="card">
    <div class="card-hd">
      <h2>FAQ 列表</h2><span class="sp"></span>
      <button class="btn btn-o btn-sm" name="act" value="batch_publish" type="submit">批量发布</button>
      <button class="btn btn-o btn-sm" name="act" value="batch_draft" type="submit">转为草稿</button>
    </div>
    <div class="card-bd np">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th style="width:34px"><input type="checkbox" class="tk-check" data-check-all></th>
              <th>问题</th>
              <th>分类</th>
              <th>标记</th>
              <th>数据</th>
              <th>状态</th>
              <th>更新时间</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $f): ?>
              <tr>
                <td><input type="checkbox" class="tk-check" data-check-item name="ids[]" value="<?= (int)$f['id'] ?>"></td>
                <td>
                  <div class="t-main"><?= e(mb_strimwidth($f['question'], 0, 46, '…')) ?></div>
                  <div class="t-sub"><?= e(mb_strimwidth(strip_tags($f['answer']), 0, 68, '…')) ?></div>
                </td>
                <td class="nowrap small"><?= e($f['cicon'] . ' ' . ($f['cname'] ?: '未分类')) ?></td>
                <td class="nowrap">
                  <?php if ((int)$f['is_top'] === 1): ?><span class="badge badge-violet">置顶</span><?php endif; ?>
                  <?php if ((int)$f['is_hot'] === 1): ?><span class="badge badge-red">HOT</span><?php endif; ?>
                </td>
                <td class="small muted nowrap">
                  👁 <?= e((string)$f['views']) ?> · 👍 <?= e((string)$f['helpful']) ?><br>
                  <span style="color:var(--muted)">👎 <?= e((string)$f['unhelpful']) ?></span>
                </td>
                <td>
                  <?php if ((int)$f['status'] === 1): ?>
                    <span class="badge badge-green badge-dot">已发布</span>
                  <?php else: ?>
                    <span class="badge badge-gray badge-dot">草稿</span>
                  <?php endif; ?>
                </td>
                <td class="muted small nowrap"><?= e(fmt_date($f['updated_at'], 'Y-m-d H:i')) ?></td>
                <td class="act">
                  <a href="faq-edit.php?id=<?= (int)$f['id'] ?>">编辑</a>
                  <a href="../knowledge.php#faq-<?= (int)$f['id'] ?>" target="_blank">预览</a>
                  <button type="submit" data-single="toggle_status" data-id="<?= (int)$f['id'] ?>">
                    <?= (int)$f['status'] === 1 ? '下架' : '发布' ?>
                  </button>
                  <button class="d" type="submit" data-single="delete" data-id="<?= (int)$f['id'] ?>"
                          data-confirm="确定删除该 FAQ？">删除</button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
              <tr><td colspan="8"><div class="empty"><div class="ic">❓</div><p>暂无 FAQ，点击右上角新增</p></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <input type="hidden" name="id" id="id" value="0">
    <?php if ($pg['pages'] > 1): ?>
      <div class="pager">
        <span>第 <?= $pg['current'] ?> / <?= $pg['pages'] ?> 页，共 <?= $total ?> 条</span>
        <div class="pgs">
          <a class="<?= $pg['has_prev'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] - 1)) ?>">上一页</a>
          <?php for ($p = max(1, $pg['current'] - 2); $p <= min($pg['pages'], $pg['current'] + 2); $p++):
              echo $p === $pg['current'] ? '<span class="on">' . $p . '</span>' : '<a href="' . e(page_link($p)) . '">' . $p . '</a>';
          endfor; ?>
          <a class="<?= $pg['has_next'] ? '' : 'dis' ?>" href="<?= e(page_link($pg['current'] + 1)) ?>">下一页</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php require __DIR__ . '/_foot.php'; ?>
