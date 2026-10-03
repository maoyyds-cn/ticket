<?php
/**
 * 成员管理
 *
 * 旧版为每个角色建了一个独立页面（admins/supervisors/engineers/supers/
 * support.php，各 6 行），另有 staff.php 做只读总览并复制了一份成员查询。
 * 这里合并为一个页面，角色用页签切换。
 *
 * @var string $role
 * @var array<string,string> $roles 可管理的角色
 * @var list<array> $rows
 * @var array<string,int> $counts
 * @var array|null $editing
 * @var object $actor
 * @var array<string,array> $allRoles
 */
$isEdit = $editing !== null;
$currentUrl = '/admin/staff' . ($role !== '' ? '?role=' . rawurlencode($role) : '');
?>
<div class="a-card">
  <div class="a-card-bd" style="padding-bottom:14px">
    <div class="row-wrap">
      <?php foreach ($roles as $key => $label): ?>
        <a class="chip<?= $role === $key ? ' is-active' : '' ?>"
           href="<?= e(url('/admin/staff?role=' . rawurlencode($key))) ?>">
          <?= e($label) ?><span class="n"><?= e((string)($counts[$key] ?? 0)) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="a-card">
  <div class="a-card-hd">
    <h2><?= e($roles[$role] ?? '成员') ?> · 成员列表</h2>
    <button class="btn btn-primary btn-sm" type="button" data-modal="mStaffNew">＋ 新增<?= e($roles[$role] ?? '成员') ?></button>
  </div>

  <?php if ($rows === []): ?>
    <div class="a-card-bd">
      <?php
        $icon = 'shield';
        $title = '这个角色下还没有成员';
        $text = '可以新增一个账号，或切换到其他角色查看。';
        $actions = [];
        require dirname(__DIR__) . '/partials/empty.php';
      ?>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th>账号</th>
            <th class="nowrap">角色</th>
            <th class="nowrap">联系方式</th>
            <th class="nowrap">待办</th>
            <th class="nowrap">最近登录</th>
            <th class="nowrap">状态</th>
            <th class="nowrap">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s):
              $isSelf = (int)$s['id'] === $actor->id;
              $count = (int)($counts[(string)$s['role']] ?? 0);
              $isLastSuper = (string)$s['role'] === \App\Domain\Staff\Role::SUPER
                  && (int)$s['status'] === 1
                  && (int)($counts[\App\Domain\Staff\Role::SUPER] ?? 0) <= 1; ?>
            <tr>
              <td>
                <strong style="color:var(--text-1)"><?= e((string)($s['realname'] ?: $s['username'])) ?></strong>
                <?php if ($isSelf): ?><span class="badge badge-blue">我</span><?php endif; ?>
                <?php if ($isLastSuper): ?><span class="badge badge-amber">最后一名超管</span><?php endif; ?>
                <span class="cell-sub mono"><?= e((string)$s['username']) ?></span>
              </td>
              <td class="nowrap">
                <span class="badge badge-<?= e(\App\Domain\Staff\Role::color((string)$s['role'])) ?>">
                  <?= e(\App\Domain\Staff\Role::label((string)$s['role'])) ?>
                </span>
              </td>
              <td class="nowrap tiny">
                <?php if ((string)$s['email'] !== ''): ?>
                  <?= e((string)$s['email']) ?>
                <?php else: ?>
                  <span class="faint">未填写</span>
                <?php endif; ?>
              </td>
              <td class="num">
                <?php if ((int)$s['todo'] > 0): ?>
                  <a href="<?= e(url('/admin/tickets?assignee=' . (int)$s['id'])) ?>"><?= e((string)(int)$s['todo']) ?></a>
                <?php else: ?>
                  <span class="faint">0</span>
                <?php endif; ?>
              </td>
              <td class="nowrap faint tiny">
                <?= e(\App\Support\Str::timeAgo((string)($s['last_login_at'] ?? ''))) ?>
              </td>
              <td class="nowrap">
                <?php if ((int)$s['status'] === 1): ?>
                  <span class="badge badge-green">启用</span>
                <?php else: ?>
                  <span class="badge badge-slate">已停用</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <div class="tbl-act">
                  <a href="<?= e(url('/admin/staff?role=' . rawurlencode((string)$s['role']) . '&edit=' . (int)$s['id'])) ?>">编辑</a>

                  <?php if (!$isSelf && !$isLastSuper): ?>
                    <form data-guard method="post" action="<?= e(url('/admin/staff/' . (int)$s['id'] . '/toggle')) ?>" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                      <button type="submit"><?= (int)$s['status'] === 1 ? '停用' : '启用' ?></button>
                    </form>
                  <?php endif; ?>

                  <?php if (!$isSelf): ?>
                    <button type="button" data-modal="mReset<?= (int)$s['id'] ?>">重置密码</button>
                  <?php endif; ?>

                  <?php if (!$isSelf && !$isLastSuper): ?>
                    <form data-guard method="post" action="<?= e(url('/admin/staff/' . (int)$s['id'] . '/delete')) ?>" style="display:inline"
                          data-confirm-form="确定删除账号「<?= e((string)$s['username']) ?>」吗？该账号名下工单的处理人会被清空。">
                      <?= csrf_field() ?>
                      <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                      <button class="danger" type="submit">删除</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- 角色权限说明 -->
<div class="a-card">
  <div class="a-card-hd"><h2>角色权限说明</h2></div>
  <div class="a-card-bd">
    <div class="table-scroll">
      <table class="matrix">
        <thead>
          <tr>
            <th>角色</th>
            <th>说明</th>
            <th>可任免</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allRoles as $key => $meta): ?>
            <tr>
              <td class="nowrap">
                <span class="badge badge-<?= e((string)$meta['color']) ?>"><?= e((string)$meta['label']) ?></span>
              </td>
              <td><?= e((string)$meta['desc']) ?></td>
              <td class="tiny">
                <?php $manage = \App\Domain\Staff\Role::manageableRoles($key); ?>
                <?= $manage === [] ? '<span class="faint">不可任免任何角色</span>' : e(implode('、', $manage)) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="tiny muted mt-2 mb-0">
      权限判定统一由角色能力表决定，页面与后端使用同一套规则，不存在「界面上看不到但接口能用」的情况。
      系统始终保留至少一名启用中的超级管理员；任何账号都不能修改自己的角色或停用、删除自己。
    </p>
  </div>
</div>

<?php
/** 成员表单（新增 / 编辑共用） */
$staffForm = static function (?array $target, array $roleOptions) {
    $isEdit = $target !== null;
    $suffix = $isEdit ? (string)(int)$target['id'] : 'new';
    ?>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="sUser<?= $suffix ?>">登录名</label>
        <?php if ($isEdit): ?>
          <input class="input" id="sUser<?= $suffix ?>" type="text" value="<?= e((string)$target['username']) ?>" disabled>
          <div class="field-tip">登录名创建后不可修改。</div>
        <?php else: ?>
          <input class="input" id="sUser<?= $suffix ?>" type="text" name="username" required
                 maxlength="32" placeholder="3–32 位字母、数字或下划线" autocomplete="off">
          <div class="field-tip">只能使用字母、数字与下划线。</div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label class="field-label" for="sRole<?= $suffix ?>">角色</label>
        <select class="select" id="sRole<?= $suffix ?>" name="role"<?= $isEdit && (int)$target['id'] === 0 ? ' disabled' : '' ?>>
          <?php foreach ($roleOptions as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $isEdit && (string)$target['role'] === $key ? ' selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="field">
        <label class="field-label" for="sName<?= $suffix ?>">姓名 / 昵称 <span class="opt">选填</span></label>
        <input class="input" id="sName<?= $suffix ?>" type="text" name="realname" maxlength="50"
               value="<?= e($isEdit ? (string)$target['realname'] : '') ?>">
        <div class="field-tip">会显示在工单回复的头衔后面，例如「【客服】张三」。</div>
      </div>
      <div class="field">
        <label class="field-label" for="sMail<?= $suffix ?>">邮箱 <span class="opt">选填</span></label>
        <input class="input" id="sMail<?= $suffix ?>" type="email" name="email" maxlength="120"
               value="<?= e($isEdit ? (string)$target['email'] : '') ?>">
      </div>
    </div>

    <?php if (!$isEdit): ?>
      <div class="field mb-0">
        <label class="field-label" for="sPass<?= $suffix ?>">初始密码</label>
        <input class="input" id="sPass<?= $suffix ?>" type="text" name="password" required
               minlength="8" maxlength="72" autocomplete="off" placeholder="至少 8 位">
        <div class="field-tip">请通过安全渠道转告本人，并提醒其首次登录后自行修改。</div>
      </div>
    <?php endif;
};
?>

<!-- 新增成员 -->
<div class="modal" id="mStaffNew" role="dialog" aria-modal="true" aria-labelledby="mStaffNewTitle">
  <div class="modal-box">
    <form data-guard method="post" action="<?= e(url('/admin/staff/save')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
      <div class="modal-hd">
        <h3 id="mStaffNewTitle">新增成员</h3>
        <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="关闭">✕</button>
      </div>
      <div class="modal-bd"><?php $staffForm(null, $roles); ?></div>
      <div class="modal-ft">
        <button class="btn btn-secondary" type="button" data-modal-close>取消</button>
        <button class="btn btn-primary" type="submit">创建账号</button>
      </div>
    </form>
  </div>
</div>

<!-- 编辑成员 -->
<?php if ($isEdit): ?>
  <div class="modal" id="mStaffEdit" role="dialog" aria-modal="true" aria-labelledby="mStaffEditTitle" data-auto-open="1">
    <div class="modal-box">
      <form data-guard method="post" action="<?= e(url('/admin/staff/save')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string)(int)$editing['id']) ?>">
        <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
        <div class="modal-hd">
          <h3 id="mStaffEditTitle">编辑「<?= e((string)$editing['username']) ?>」</h3>
          <a class="btn btn-ghost btn-sm" href="<?= e(url($currentUrl)) ?>" aria-label="关闭">✕</a>
        </div>
        <div class="modal-bd">
          <?php $staffForm($editing, array_merge($roles, [ (string)$editing['role'] => \App\Domain\Staff\Role::label((string)$editing['role']) ])); ?>
          <?php if ((int)$editing['id'] === $actor->id): ?>
            <div class="alert alert-warn mt-2 mb-0">
              <span class="alert-ico" aria-hidden="true"><?= icon('warn', 17) ?></span>
              <div class="alert-body">这是你自己的账号，角色无法在这里修改，以免把自己锁在权限之外。</div>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-ft">
          <a class="btn btn-secondary" href="<?= e(url($currentUrl)) ?>">取消</a>
          <button class="btn btn-primary" type="submit">保存修改</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- 重置密码 -->
<?php foreach ($rows as $s): ?>
  <?php if ((int)$s['id'] === $actor->id) { continue; } ?>
  <div class="modal" id="mReset<?= (int)$s['id'] ?>" role="dialog" aria-modal="true"
       aria-labelledby="mResetTitle<?= (int)$s['id'] ?>">
    <div class="modal-box">
      <form data-guard method="post" action="<?= e(url('/admin/staff/' . (int)$s['id'] . '/password')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
        <div class="modal-hd">
          <h3 id="mResetTitle<?= (int)$s['id'] ?>">重置「<?= e((string)$s['username']) ?>」的密码</h3>
          <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="关闭">✕</button>
        </div>
        <div class="modal-bd">
          <div class="field mb-0">
            <label class="field-label" for="np<?= (int)$s['id'] ?>">新密码</label>
            <input class="input" id="np<?= (int)$s['id'] ?>" type="text" name="password" required
                   minlength="8" maxlength="72" autocomplete="off" placeholder="至少 8 位">
            <div class="field-tip">重置后该成员会被要求尽快自行修改。</div>
          </div>
        </div>
        <div class="modal-ft">
          <button class="btn btn-secondary" type="button" data-modal-close>取消</button>
          <button class="btn btn-primary" type="submit">确认重置</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
