<?php
/** 分类管理 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$editId = get_int('edit');

if (is_post()) {
    csrf_guard();
    $act = post('act');

    if ($act === 'save') {
        $id  = (int)post('id');
        $name = post('name');
        $data = [
            'name'        => $name,
            'slug'        => post('slug') ?: mb_substr((string)preg_replace('/[^\w\-]/u', '', $name), 0, 60),
            'icon'        => mb_substr(post('icon'), 0, 10) ?: '📁',
            'color'       => post('color') ?: '#4f46e5',
            'description' => mb_substr(post('description'), 0, 250),
            'is_ticket'   => (int)post('is_ticket'),
            'is_faq'      => (int)post('is_faq'),
            'sort'        => (int)post('sort'),
            'status'      => (int)post('status'),
        ];
        if ($name === '') {
            flash('error', '分类名称不能为空');
        } elseif ($id > 0) {
            db_query('UPDATE ' . DB_PRE . 'category SET name=?,slug=?,icon=?,color=?,description=?,is_ticket=?,is_faq=?,sort=?,status=? WHERE id=?',
                array_merge(array_values($data), [$id]));
            flash('ok', '分类已更新');
        } else {
            db_insert('INSERT INTO ' . DB_PRE . 'category (name,slug,icon,color,description,is_ticket,is_faq,sort,status) VALUES (?,?,?,?,?,?,?,?,?)',
                array_values($data));
            flash('ok', '分类已创建');
        }
        redirect('categories.php');
    }

    if ($act === 'delete') {
        $id = (int)post('id');
        $tk = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE category_id = ?', [$id], 0);
        $fq = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'faq WHERE category_id = ?', [$id], 0);
        if ($tk > 0 || $fq > 0) {
            flash('error', "该分类下还有 $tk 个工单、$fq 条 FAQ，请先迁移后再删除");
        } else {
            db_query('DELETE FROM ' . DB_PRE . 'category WHERE id = ?', [$id]);
            flash('ok', '分类已删除');
        }
        redirect('categories.php');
    }

    if ($act === 'toggle') {
        db_query('UPDATE ' . DB_PRE . 'category SET status = 1 - status WHERE id = ?', [(int)post('id')]);
        flash('ok', '已切换状态');
        redirect('categories.php');
    }
}

$list  = db_all('SELECT c.*,
                 (SELECT COUNT(*) FROM ' . DB_PRE . 'faq f WHERE f.category_id = c.id) faq_count,
                 (SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t WHERE t.category_id = c.id) ticket_count
                 FROM ' . DB_PRE . 'category c ORDER BY c.sort ASC, c.id ASC');
$editing = $editId > 0 ? array_filter($list, fn($c) => (int)$c['id'] === $editId) : null;
$editing = $editing ? reset($editing) : null;

$pageTitle = '分类管理';
$pageDesc  = '共 ' . count($list) . ' 个分类';
$pageActions = '<button class="btn btn-p btn-sm" data-modal="mAdd">+ 新增分类</button>';
require __DIR__ . '/_head.php';

/**
 * 分类表单弹层
 * 注意：本函数是文件顶层的无条件声明，PHP 在编译期即会提升，
 * 因此虽然定义在 require _head.php 之后、调用点在更下方，仍可正常调用。
 * 请勿将其包进 if / 条件块或闭包中，否则会因作用域变化而找不到函数。
 */
function catForm(array $c, string $title, string $submitText, string $formId = 'fCat'): void
{
    $isEdit = !empty($c['id']);
    ?>
    <div class="modal-hd"><h3><?= e($title) ?></h3><button type="button" data-close>×</button></div>
    <form method="post" id="<?= e($formId) ?>" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?>">
      <div class="modal-bd">
        <div class="frow">
          <div class="field">
            <label>名称 <span class="req">*</span></label>
            <input type="text" name="name" class="input" required maxlength="50" value="<?= e($c['name'] ?? '') ?>" placeholder="例如：常见报错">
          </div>
          <div class="field">
            <label>别名（URL 用）</label>
            <input type="text" name="slug" class="input" maxlength="60" value="<?= e($c['slug'] ?? '') ?>" placeholder="留空自动生成">
          </div>
          <div class="field">
            <label>图标（Emoji）</label>
            <input type="text" name="icon" class="input" maxlength="10" value="<?= e($c['icon'] ?? '📁') ?>">
          </div>
          <div class="field">
            <label>主题色</label>
            <input type="color" name="color" class="input" style="height:42px;padding:4px" value="<?= e($c['color'] ?? '#4f46e5') ?>">
          </div>
        </div>
        <div class="field">
          <label>描述</label>
          <input type="text" name="description" class="input" maxlength="250" value="<?= e($c['description'] ?? '') ?>" placeholder="显示在分类卡片上">
        </div>
        <div class="frow-3">
          <div class="field">
            <label>排序</label>
            <input type="number" name="sort" class="input" value="<?= (int)($c['sort'] ?? 0) ?>" step="10">
          </div>
          <div class="field">
            <label>用途</label>
            <div style="padding-top:8px;display:flex;gap:16px">
              <label class="check"><input type="checkbox" name="is_ticket" value="1" <?= (int)($c['is_ticket'] ?? 1) === 1 ? 'checked' : '' ?>> 工单分类</label>
              <label class="check"><input type="checkbox" name="is_faq" value="1" <?= (int)($c['is_faq'] ?? 1) === 1 ? 'checked' : '' ?>> FAQ 分类</label>
            </div>
          </div>
          <div class="field">
            <label>状态</label>
            <select name="status" class="select">
              <option value="1" <?= (int)($c['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
              <option value="0" <?= (int)($c['status'] ?? 0) === 0 ? 'selected' : '' ?>>停用</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-o" data-close>取消</button>
        <button class="btn btn-p" type="submit"><?= e($submitText) ?></button>
      </div>
    </form>
    <?php
}
?>

<div class="card">
  <div class="card-hd"><h2>分类列表</h2><span class="sp"></span>
    <span class="muted small">分类同时用于工单类型与知识库目录</span></div>
  <div class="card-bd np">
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr>
          <th>分类</th><th>别名</th><th>用途</th><th>数据量</th><th>排序</th><th>状态</th><th style="text-align:right">操作</th>
        </tr></thead>
        <tbody>
          <?php foreach ($list as $c): ?>
            <tr>
              <td>
                <div class="t-main">
                  <span style="color:<?= e($c['color']) ?>"><?= e($c['icon']) ?></span> <?= e($c['name']) ?>
                </div>
                <div class="t-sub"><?= e($c['description'] ?: '—') ?></div>
              </td>
              <td class="mono muted small"><?= e($c['slug'] ?: '—') ?></td>
              <td class="nowrap small">
                <?php if ((int)$c['is_ticket'] === 1): ?><span class="badge badge-blue">工单</span><?php endif; ?>
                <?php if ((int)$c['is_faq'] === 1): ?><span class="badge badge-violet">FAQ</span><?php endif; ?>
              </td>
              <td class="small muted nowrap">📄 <?= (int)$c['faq_count'] ?> · 🎫 <?= (int)$c['ticket_count'] ?></td>
              <td class="small"><?= (int)$c['sort'] ?></td>
              <td>
                <?php if ((int)$c['status'] === 1): ?>
                  <span class="badge badge-green badge-dot">启用</span>
                <?php else: ?>
                  <span class="badge badge-gray badge-dot">停用</span>
                <?php endif; ?>
              </td>
              <td class="act">
                <a href="?edit=<?= (int)$c['id'] ?>#mEdit">编辑</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button type="submit"><?= (int)$c['status'] === 1 ? '停用' : '启用' ?></button>
                </form>
                <form method="post" style="display:inline" data-oneshot>
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="d" type="submit" data-confirm="确定删除分类「<?= e($c['name']) ?>」？">删除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$list): ?>
            <tr><td colspan="7"><div class="empty"><div class="ic">📂</div><p>暂无分类</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal" id="mAdd">
  <div class="modal-box">
    <?php catForm([], '新增分类', '创建', 'fAdd'); ?>
  </div>
</div>

<?php if ($editing): ?>
<div class="modal<?= $editId > 0 ? ' open' : '' ?>" id="mEdit">
  <div class="modal-box">
    <?php catForm($editing, '编辑分类', '保存', 'fEdit'); ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
