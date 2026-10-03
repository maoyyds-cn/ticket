<?php
/**
 * 知识库条目编辑
 *
 * 注意这里只提供**纯文本**编辑：不提供 HTML 输入，也不做 HTML 白名单清洗。
 * 旧版允许后台写任意 HTML 并用正则清洗，但那个清洗器只识别字面量的
 * javascript: / data: / vbscript:，实体编码写法（java&#115;cript:）能绕过，
 * 于是一条被存进知识库的链接就能在前台形成点击型 XSS，
 * 而任何后台角色都能编辑知识库。改为纯文本后，这一整类问题消失。
 *
 * @var array|null $faq
 * @var list<array> $categories
 * @var int $formOpenedAt
 */
$isEdit = $faq !== null;
$action = url('/admin/faqs/save');
?>
<div class="a-card">
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= e((string)(int)$faq['id']) ?>">
    <?php endif; ?>

    <div class="a-card-hd">
      <h2><?= $isEdit ? '编辑条目' : '新建条目' ?></h2>
      <?php if ($isEdit): ?>
        <a class="btn btn-secondary btn-sm"
           href="<?= e(url('/knowledge#faq-' . (int)$faq['id'])) ?>" target="_blank" rel="noopener">
          前台预览 ↗
        </a>
      <?php endif; ?>
    </div>

    <div class="a-card-bd">
      <div class="field">
        <label class="field-label" for="eQuestion">问题 <span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="eQuestion" type="text" name="question" required maxlength="255"
               value="<?= e($isEdit ? (string)$faq['question'] : old('question')) ?>"
               placeholder="用用户会搜索的说法来描述问题">
        <div class="field-tip">2–255 字。用户搜索时最先匹配的是标题，尽量包含他们可能输入的关键词。</div>
      </div>

      <div class="field">
        <label class="field-label" for="eAnswer">解答内容 <span class="req" aria-hidden="true">*</span></label>
        <div class="ed-bar" data-ed-wrap="#eAnswer">
          <button type="button" data-ed="bold">强调</button>
          <button type="button" data-ed="bullet">项目符号</button>
          <button type="button" data-ed="number">编号步骤</button>
          <button type="button" data-ed="code">命令</button>
          <button type="button" data-ed="divider">分隔线</button>
        </div>
        <textarea class="textarea textarea-lg" id="eAnswer" name="answer" required
                  placeholder="直接写纯文本即可：&#10;1. 第一步&#10;2. 第二步&#10;&#10;空行会分段，以 / 开头的命令会自动高亮。"><?= e($isEdit ? (string)$faq['answer'] : old('answer')) ?></textarea>
        <div class="field-tip row-between">
          <span>纯文本编辑，格式由系统统一渲染——不支持也不需要使用 HTML。</span>
          <span class="counter" data-count-for="answer" data-count-max="60000">0 / 60000</span>
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="eKeywords">检索关键词 <span class="opt">选填</span></label>
        <input class="input" id="eKeywords" type="text" name="keywords" maxlength="500"
               value="<?= e($isEdit ? (string)$faq['keywords'] : old('keywords')) ?>"
               placeholder="用空格分隔，例如：裂图 图片 加载失败 图床">
        <div class="field-tip">
          留空时会自动从标题里提取关键词，所以新条目一建好就能被搜到。
          中文检索不做分词，靠这里的词做匹配，因此同义词值得都写上。
        </div>
      </div>
    </div>

    <div class="a-card-hd" style="border-top:1px solid var(--border-2)">
      <h2>展示设置</h2>
    </div>
    <div class="a-card-bd">
      <div class="form-row">
        <div class="field">
          <label class="field-label" for="eCategory">所属分类</label>
          <select class="select" id="eCategory" name="category_id">
            <option value="0">未分类</option>
            <?php $currentCat = $isEdit ? (int)$faq['category_id'] : 0; ?>
            <?php foreach ($categories as $c): ?>
              <option value="<?= e((string)(int)$c['id']) ?>"<?= $currentCat === (int)$c['id'] ? ' selected' : '' ?>>
                <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="eSort">排序权重</label>
          <input class="input" id="eSort" type="number" name="sort"
                 value="<?= e((string)($isEdit ? (int)$faq['sort'] : 0)) ?>">
          <div class="field-tip">数值越大越靠前（在同等置顶/热门条件下）。</div>
        </div>
      </div>

      <div class="switch-row">
        <div class="sw-txt">
          <strong>发布状态</strong>
          <span>关闭后该条目在前台不可见，但仍保留在后台列表里（草稿）。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="status" value="1"
                 <?= (!$isEdit || (int)$faq['status'] === 1) ? 'checked' : '' ?>>
          <span>已发布</span>
        </label>
      </div>

      <div class="switch-row">
        <div class="sw-txt">
          <strong>标记为热门</strong>
          <span>会出现在首页「大家都在问」区块。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="is_hot" value="1"
                 <?= ($isEdit && (int)$faq['is_hot'] === 1) ? 'checked' : '' ?>>
          <span>热门</span>
        </label>
      </div>

      <div class="switch-row">
        <div class="sw-txt">
          <strong>置顶</strong>
          <span>在列表与搜索结果中始终排在最前面，并带「置顶」标记。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="is_top" value="1"
                 <?= ($isEdit && (int)$faq['is_top'] === 1) ? 'checked' : '' ?>>
          <span>置顶</span>
        </label>
      </div>
    </div>

    <div class="a-card-ft">
      <div class="row-wrap" style="justify-content:flex-end">
        <a class="btn btn-secondary" href="<?= e(url('/admin/faqs')) ?>">取消</a>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? '保存修改' : '创建条目' ?></button>
      </div>
    </div>
  </form>
</div>

<?php if ($isEdit): ?>
  <div class="a-card">
    <div class="a-card-hd"><h2>条目数据</h2></div>
    <div class="a-card-bd">
      <dl class="kv">
        <div><dt>浏览量</dt><dd><?= e((string)(int)$faq['views']) ?></dd></div>
        <div><dt>认为有用</dt><dd><?= e((string)(int)$faq['helpful']) ?></dd></div>
        <div><dt>认为没用</dt><dd><?= e((string)(int)$faq['unhelpful']) ?></dd></div>
        <div><dt>创建时间</dt><dd><?= e(\App\Support\Str::datetime((string)$faq['created_at'])) ?></dd></div>
        <div><dt>更新时间</dt><dd><?= e(\App\Support\Str::datetime((string)$faq['updated_at'])) ?></dd></div>
      </dl>

      <form method="post" action="<?= e(url('/admin/faqs/' . (int)$faq['id'] . '/delete')) ?>"
            style="margin-top:16px"
            data-confirm-form="确定删除这条知识库内容吗？此操作不可撤销。">
        <?= csrf_field() ?>
        <button class="btn btn-danger-soft btn-sm" type="submit">删除该条目</button>
      </form>
    </div>
  </div>
<?php endif; ?>
