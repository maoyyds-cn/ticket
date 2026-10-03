<?php
/** FAQ 新增/编辑 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$id  = get_int('id');
$faq = $id > 0 ? db_row('SELECT * FROM ' . DB_PRE . 'faq WHERE id = ?', [$id]) : null;
if ($id > 0 && !$faq) {
    flash('error', 'FAQ 不存在');
    redirect('faqs.php');
}

$cats = category_list(false, true);

$data = [
    'question'   => $faq['question'] ?? '',
    'answer'     => $faq['answer'] ?? '',
    'keywords'   => $faq['keywords'] ?? '',
    'category_id' => (int)($faq['category_id'] ?? 0),
    'is_hot'     => (int)($faq['is_hot'] ?? 0),
    'is_top'     => (int)($faq['is_top'] ?? 0),
    'sort'       => (int)($faq['sort'] ?? 0),
    'status'     => (int)($faq['status'] ?? 1),
];

if (is_post()) {
    csrf_guard();
    $data = [
        'question'    => post('question'),
        'answer'      => post('answer'),
        'keywords'    => post('keywords'),
        'category_id' => (int)post('category_id'),
        'is_hot'      => (int)post('is_hot'),
        'is_top'      => (int)post('is_top'),
        'sort'        => (int)post('sort'),
        'status'      => (int)post('status'),
    ];
    $errors = [];
    if (mb_strlen($data['question']) < 4)  $errors[] = '问题标题至少 4 个字';
    if (mb_strlen($data['answer']) < 10)   $errors[] = '答案内容至少 10 个字';
    if ($data['category_id'] <= 0)         $errors[] = '请选择分类';
    // 校验分类真实存在，避免 POST 伪造 id 产生孤儿数据
    elseif (!(int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'category WHERE id = ?', [$data['category_id']], 0)) {
        $errors[] = '所选分类不存在，请重新选择';
    }

    if (!$errors) {
        if ($id > 0) {
            db_query('UPDATE ' . DB_PRE . 'faq SET question=?, answer=?, keywords=?, category_id=?, is_hot=?, is_top=?, sort=?, status=? WHERE id=?', [
                $data['question'], $data['answer'], $data['keywords'], $data['category_id'],
                $data['is_hot'], $data['is_top'], $data['sort'], $data['status'], $id,
            ]);
            flash('ok', 'FAQ 已更新');
        } else {
            $id = db_insert('INSERT INTO ' . DB_PRE . 'faq (question, answer, keywords, category_id, is_hot, is_top, sort, status) VALUES (?,?,?,?,?,?,?,?)', [
                $data['question'], $data['answer'], $data['keywords'], $data['category_id'],
                $data['is_hot'], $data['is_top'], $data['sort'], $data['status'],
            ]);
            flash('ok', 'FAQ 已创建');
        }
        redirect('faqs.php');
    }
    foreach ($errors as $e) flash('error', $e);
}

$pageTitle = $id > 0 ? '编辑 FAQ' : '新增 FAQ';
$pageActions = '<a class="btn btn-o btn-sm" href="faqs.php">← 返回列表</a>';
require __DIR__ . '/_head.php';
?>

<div style="max-width:920px;margin:0 auto">
  <form method="post" data-oneshot>
    <?= csrf_field() ?>

    <div class="card mb">
      <div class="card-hd"><h2>基本信息</h2></div>
      <div class="card-bd">
        <div class="field">
          <label>问题标题 <span class="req">*</span></label>
          <input type="text" name="question" class="input" required maxlength="255"
                 placeholder="例如：签到怎么获得积分？" value="<?= e($data['question']) ?>">
        </div>

        <div class="field">
          <label>答案内容 <span class="req">*</span></label>
          <div class="ed-tool" data-ed-wrap>
            <button type="button" data-ed="<b>加粗</b>"><b>B</b></button>
            <button type="button" data-ed="`命令`">命令</button>
            <button type="button" data-ed="\n- ">• 列表项</button>
            <button type="button" data-ed="\n\n---\n\n">分隔线</button>
            <button type="button" data-ed="\n">换行</button>
          </div>
          <textarea name="answer" class="textarea ed" required data-count="answer"
                            placeholder="详细解答…&#10;&#10;支持 HTML 排版标签，命令用 &lt;code&gt;包裹&#10;换行即新段落"><?= e($data['answer']) ?></textarea>
                  <div class="tip">
                    命令需用 <code>&lt;code&gt;/签到&lt;/code&gt;</code> 包裹才会高亮显示。
                    另可用标签：
                    <code>&lt;strong&gt;</code> 加粗、
                    <code>&lt;ul&gt;&lt;li&gt;</code> 列表、
                    <code>&lt;table&gt;</code> 表格、
                    <code>&lt;h4&gt;</code> 小标题、
                    <code>&lt;a href="https://…"&gt;</code> 链接。
                    <br>反引号 <code>`</code> 不会转换，请勿使用。
                  </div>
                  <div class="muted small" style="text-align:right">当前 <span data-count-for="answer">0</span> 字</div>
        </div>

        <div class="frow">
          <div class="field">
            <label>所属分类 <span class="req">*</span></label>
            <select name="category_id" class="select" required>
              <option value="">请选择分类</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $data['category_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                  <?= e($c['icon'] . ' ' . $c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>搜索关键词</label>
            <input type="text" name="keywords" class="input" maxlength="255"
                   placeholder="多个关键词用空格分隔" value="<?= e($data['keywords']) ?>">
            <div class="tip">填写后用户搜索这些词也能找到本条</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb">
      <div class="card-hd"><h2>展示设置</h2></div>
      <div class="card-bd">
        <div class="frow-3">
          <div class="field">
            <label>排序权重</label>
            <input type="number" name="sort" class="input" value="<?= (int)$data['sort'] ?>" step="10">
            <div class="tip">数值越大越靠前</div>
          </div>
          <div class="field">
            <label>发布状态</label>
            <select name="status" class="select">
              <option value="1" <?= $data['status'] === 1 ? ' selected' : '' ?>>已发布</option>
              <option value="0" <?= $data['status'] === 0 ? ' selected' : '' ?>>草稿（不显示）</option>
            </select>
          </div>
          <div class="field">
            <label>特殊标记</label>
            <div style="display:flex;gap:18px;padding-top:8px">
              <label class="check"><input type="checkbox" name="is_top" value="1" <?= $data['is_top'] === 1 ? ' checked' : '' ?>> 置顶</label>
              <label class="check"><input type="checkbox" name="is_hot" value="1" <?= $data['is_hot'] === 1 ? ' checked' : '' ?>> 热门</label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:11px">
      <button class="btn btn-p" type="submit"><?= $id > 0 ? '保存修改' : '创建 FAQ' ?></button>
      <a class="btn btn-o" href="faqs.php">取消</a>
      <?php if ($id > 0): ?>
        <a class="btn btn-g" href="../knowledge.php#faq-<?= (int)$id ?>" target="_blank" style="margin-left:auto">前台预览 →</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
