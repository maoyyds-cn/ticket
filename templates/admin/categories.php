<?php
/**
 * 分类管理
 *
 * @var list<array> $categories
 * @var array<int,array{tickets:int,faqs:int}> $usage
 * @var array|null $editing
 */
$isEdit = $editing !== null;
$currentUrl = '/admin/categories';
?>
<div class="a-card">
  <div class="a-card-hd">
    <h2>现有分类</h2>
    <button class="btn btn-primary btn-sm" type="button" data-modal="mCatNew">＋ 新增分类</button>
  </div>

  <?php if ($categories === []): ?>
    <div class="a-card-bd">
      <?php
        $icon = 'folder';
        $title = '还没有任何分类';
        $text = '分类用于给工单与知识库内容分组，建议至少建立一个。';
        $actions = [];
        require dirname(__DIR__) . '/partials/empty.php';
      ?>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th>分类</th>
            <th class="nowrap">别名</th>
            <th class="nowrap">用途</th>
            <th class="nowrap">内容量</th>
            <th class="nowrap">排序</th>
            <th class="nowrap">状态</th>
            <th class="nowrap">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $c):
              $u = $usage[(int)$c['id']] ?? ['tickets' => 0, 'faqs' => 0]; ?>
            <tr>
              <td>
                <span class="cat-dot" style="--cat:<?= e((string)$c['color']) ?>"></span>
                <strong style="color:var(--text-1)"><?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?></strong>
                <?php if ((string)$c['description'] !== ''): ?>
                  <span class="cell-sub"><?= e(\App\Support\Str::limit((string)$c['description'], 44)) ?></span>
                <?php endif; ?>
              </td>
              <td class="nowrap mono tiny"><?= e((string)$c['slug']) ?></td>
              <td class="nowrap tiny">
                <?php if ((int)$c['is_ticket'] === 1): ?><span class="badge badge-blue">工单</span><?php endif; ?>
                <?php if ((int)$c['is_faq'] === 1): ?><span class="badge badge-violet">知识库</span><?php endif; ?>
              </td>
              <td class="nowrap tiny">
                <a href="<?= e(url('/admin/tickets?cat=' . (int)$c['id'])) ?>"><?= e((string)$u['tickets']) ?> 工单</a>
                <span class="faint"> · </span>
                <a href="<?= e(url('/admin/faqs?cat=' . (int)$c['id'])) ?>"><?= e((string)$u['faqs']) ?> 条目</a>
              </td>
              <td class="num"><?= e((string)(int)$c['sort']) ?></td>
              <td class="nowrap">
                <?php if ((int)$c['status'] === 1): ?>
                  <span class="badge badge-green">启用</span>
                <?php else: ?>
                  <span class="badge badge-slate">停用</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <div class="tbl-act">
                  <a href="<?= e(url('/admin/categories?edit=' . (int)$c['id'])) ?>">编辑</a>
                  <form method="post" action="<?= e(url('/admin/categories/' . (int)$c['id'] . '/toggle')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                    <button type="submit"><?= (int)$c['status'] === 1 ? '停用' : '启用' ?></button>
                  </form>
                  <?php
                    // 删除按钮只在没有引用时才是「安全的」；仍有引用时后端会拒绝，
                    // 这里提前用提示说明原因，避免用户点了才发现不行
                    $inUse = $u['tickets'] > 0 || $u['faqs'] > 0;
                  ?>
                  <form method="post" action="<?= e(url('/admin/categories/' . (int)$c['id'] . '/delete')) ?>" style="display:inline"
                        data-confirm-form="<?= $inUse
                            ? '该分类下还有内容，删除会被拒绝。确定要尝试吗？'
                            : '确定删除这个分类吗？此操作不可撤销。' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                    <button class="danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
/**
 * 分类表单。
 * 抽成闭包是因为新增与编辑两个模态框字段完全相同；旧版把它写成一个
 * 页内函数 catForm()，虽然能用，但与同名的其它页内函数放在一起时
 * 一旦合并页面就会冲突。
 */
$catForm = static function (?array $cat): void {
    $isEdit = $cat !== null;
    ?>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="cName<?= $isEdit ? (int)$cat['id'] : 'new' ?>">名称</label>
        <input class="input" id="cName<?= $isEdit ? (int)$cat['id'] : 'new' ?>" type="text" name="name"
               required maxlength="50" value="<?= e($isEdit ? (string)$cat['name'] : '') ?>"
               placeholder="例如：常见报错">
      </div>
      <div class="field">
        <label class="field-label" for="cIcon<?= $isEdit ? (int)$cat['id'] : 'new' ?>">
          图标
          <span class="opt">可选图标名</span>
        </label>
        <?php
          /*
           * 这里原来是「填一个 emoji」的自由文本输入。
           *
           * 改成从固定集合里选：emoji 的字形与颜色由操作系统字体决定，
           * 既不能跟随分类自己配置的主题色，也无法在深色模式下调色，
           * 而且自由输入意味着任何人都能贴进来一个风格完全不同的彩色贴图。
           * 用下拉选择之后，全站图标保持同一套线宽与配色。
           */
          $iconOptions = [
              'folder' => '文件夹', 'book' => '书本', 'rocket' => '火箭',
              'gamepad' => '手柄', 'diamond' => '钻石', 'account' => '账号',
              'shield' => '盾牌', 'server' => '服务器', 'help' => '问号',
              'bolt' => '闪电', 'bell' => '铃铛', 'lock' => '锁',
              'key' => '钥匙', 'chart' => '图表', 'clipboard' => '剪贴板',
              'ticket' => '票据', 'chat' => '对话', 'warn' => '警告',
              'info' => '信息', 'star' => '星标', 'clock' => '时钟',
              'users' => '多人', 'mail' => '邮件', 'search' => '搜索',
          ];
          $currentIcon = $isEdit ? (string)$cat['icon'] : 'folder';
          if (!isset($iconOptions[$currentIcon])) {
              $currentIcon = 'folder';
          }
        ?>
        <div class="row-wrap">
          <span class="icon-preview" aria-hidden="true"><?= icon($currentIcon, 20) ?></span>
          <select class="select" id="cIcon<?= $isEdit ? (int)$cat['id'] : 'new' ?>"
                  name="icon" style="flex:1 1 160px">
            <?php foreach ($iconOptions as $value => $label): ?>
              <option value="<?= e($value) ?>"<?= $currentIcon === $value ? ' selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-tip">图标会使用上面配置的主题色，并自动适配深色模式。</div>
      </div>
    </div>

    <div class="form-row">
      <div class="field">
        <label class="field-label" for="cColor<?= $isEdit ? (int)$cat['id'] : 'new' ?>">主题色</label>
        <input class="input" id="cColor<?= $isEdit ? (int)$cat['id'] : 'new' ?>" type="color" name="color"
               value="<?= e($isEdit ? (string)$cat['color'] : '#4f46e5') ?>" style="height:38px;padding:3px">
        <div class="field-tip">只接受十六进制色值，其他内容会被忽略并回退到默认色。</div>
      </div>
      <div class="field">
        <label class="field-label" for="cSort<?= $isEdit ? (int)$cat['id'] : 'new' ?>">排序权重</label>
        <input class="input" id="cSort<?= $isEdit ? (int)$cat['id'] : 'new' ?>" type="number" name="sort"
               value="<?= e((string)($isEdit ? (int)$cat['sort'] : 0)) ?>">
        <div class="field-tip">数值越大越靠前。</div>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="cDesc<?= $isEdit ? (int)$cat['id'] : 'new' ?>">描述 <span class="opt">选填</span></label>
      <input class="input" id="cDesc<?= $isEdit ? (int)$cat['id'] : 'new' ?>" type="text" name="description"
             maxlength="255" value="<?= e($isEdit ? (string)$cat['description'] : '') ?>"
             placeholder="一句话说明这个分类涵盖哪些问题">
    </div>

    <div class="field">
      <label class="field-label" for="cSlug<?= $isEdit ? (int)$cat['id'] : 'new' ?>">别名 <span class="opt">选填</span></label>
      <input class="input" id="cSlug<?= $isEdit ? (int)$cat['id'] : 'new' ?>" type="text" name="slug"
             maxlength="60" value="<?= e($isEdit ? (string)$cat['slug'] : '') ?>"
             placeholder="留空自动生成">
      <div class="field-tip">用于程序识别，中文名称会生成一个带编号的别名。</div>
    </div>

    <div class="switch-row">
      <div class="sw-txt">
        <strong>作为工单分类</strong>
        <span>用户提交工单时可以在下拉框里选择。</span>
      </div>
      <label class="check">
        <input type="checkbox" name="is_ticket" value="1"<?= (!$isEdit || (int)$cat['is_ticket'] === 1) ? ' checked' : '' ?>>
        <span>启用</span>
      </label>
    </div>

    <div class="switch-row">
      <div class="sw-txt">
        <strong>作为知识库分类</strong>
        <span>用于知识库的分类筛选。</span>
      </div>
      <label class="check">
        <input type="checkbox" name="is_faq" value="1"<?= (!$isEdit || (int)$cat['is_faq'] === 1) ? ' checked' : '' ?>>
        <span>启用</span>
      </label>
    </div>

    <div class="switch-row">
      <div class="sw-txt">
        <strong>启用状态</strong>
        <span>停用后前台不再显示该分类。</span>
      </div>
      <label class="check">
        <input type="checkbox" name="status" value="1"<?= (!$isEdit || (int)$cat['status'] === 1) ? ' checked' : '' ?>>
        <span>启用</span>
      </label>
    </div>
    <?php
};
?>

<!-- 新增分类 -->
<div class="modal" id="mCatNew" role="dialog" aria-modal="true" aria-labelledby="mCatNewTitle">
  <div class="modal-box">
    <form data-guard method="post" action="<?= e(url('/admin/categories/save')) ?>">
      <?= csrf_field() ?>
      <div class="modal-hd">
        <h3 id="mCatNewTitle">新增分类</h3>
        <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="关闭">✕</button>
      </div>
      <div class="modal-bd"><?php $catForm(null); ?></div>
      <div class="modal-ft">
        <button class="btn btn-secondary" type="button" data-modal-close>取消</button>
        <button class="btn btn-primary" type="submit">创建分类</button>
      </div>
    </form>
  </div>
</div>

<!-- 编辑分类（?edit=ID 时自动打开） -->
<?php if ($isEdit): ?>
  <div class="modal" id="mCatEdit" role="dialog" aria-modal="true" aria-labelledby="mCatEditTitle" data-auto-open="1">
    <div class="modal-box">
      <form data-guard method="post" action="<?= e(url('/admin/categories/save')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string)(int)$editing['id']) ?>">
        <div class="modal-hd">
          <h3 id="mCatEditTitle">编辑分类：<?= e((string)$editing['name']) ?></h3>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/categories')) ?>" aria-label="关闭">✕</a>
        </div>
        <div class="modal-bd"><?php $catForm($editing); ?></div>
        <div class="modal-ft">
          <a class="btn btn-secondary" href="<?= e(url('/admin/categories')) ?>">取消</a>
          <button class="btn btn-primary" type="submit">保存修改</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
