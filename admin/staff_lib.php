<?php
/**
 * 后台账号任免 —— 各角色管理页的共享实现
 *
 * 由 support.php / engineers.php / supervisors.php / admins.php / supers.php
 * 五个入口页 include 进来，通过 $STAFF_ROLE 声明本页管理哪个角色。
 * staff.php（成员总览）是独立页面，不走这份实现。
 *
 * 为什么抽出来而不是各页复制：
 * 任免逻辑里有三处极易出错的分支（最后一名超管保护、不能操作自己、
 * 角色变更权限校验），复制五份等于复制五个出错点。
 */
declare(strict_types=1);

if (!defined('DB_PRE')) {
    exit('direct access denied');
}

// ---------------- 入口校验 ----------------

if (!isset($STAFF_ROLE) || !admin_role_exists((string)$STAFF_ROLE)) {
    exit('direct access denied');
}
$STAFF_ROLE = (string)$STAFF_ROLE;
$meta       = admin_role_meta($STAFF_ROLE);
$pageTitle  = $meta['label'] . '管理';
$self       = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'staff.php'));

/**
 * 访问授权
 *
 * $canManage —— 能任免本站角色的管理者（超管可管全部，主管可管客服/工程师）
 * $canSelf   —— 角色与本站相同，但只允许改「自己」这一条记录
 *
 * 这里必须把两者严格区分。若把「角色相同」直接等同于「可管理本角色全部账号」，
 * 客服就能进 support.php 改其他客服的密码；再叠加 toggle 分支缺角色校验，
 * 任何后台角色都能禁用超级管理员。
 */
$myRole      = (string)$admin['role'];
$canManage   = admin_can_manage_role($myRole, $STAFF_ROLE);
$canSelf     = ($myRole === $STAFF_ROLE);
if (!$canManage && !$canSelf) {
    flash('error', '「' . $meta['label'] . '管理」需要' . e(admin_role_label($STAFF_ROLE)) . '任免权限，请联系主管或超级管理员');
    redirect('index.php');
}

// 两个概念必须分开，否则列表会混在一起：
//
//   $STAFF_ROLE   本页负责的角色。列表恒定只显示这个角色的账号 ——
//                 「客服管理」里就该只有客服，不该把超管、主管也列进来。
//   $assignable   本次操作允许把账号设成哪些角色，即角色下拉框的选项。
//                 超管进「客服管理」时下拉框里有 5 个角色，所以可以在本页
//                 把某个客服提拔为主管；主管进来时只有 2 个（客服/工程师）。
//
// 早期版本把两者混成一个 $targetRoles 并同时用于列表过滤，导致超管在任何
// 角色页看到的都是全部人。
$assignable = $canManage ? admin_manageable_roles($myRole) : [];
if (!$assignable) {
    // 非管理者只能改自己，角色无从选择；防御性补齐避免下拉框空掉
    $assignable = [$STAFF_ROLE => $meta['label']];
}
// 本页下拉框的默认项永远排在最前，新建时不必手动去选
if (isset($assignable[$STAFF_ROLE])) {
    $assignable = [$STAFF_ROLE => $assignable[$STAFF_ROLE]] + $assignable;
}

/** 非管理者只能操作自己那条记录 */
function staff_may_touch(array $target, string $myRole, string $pageRole, bool $canManage, int $myId): bool
{
    if ($canManage) {
        return true;
    }
    return (int)$target['id'] === $myId && $myRole === $pageRole;
}

/**
 * 系统必须始终保有至少一名启用中的超管，否则谁都进不了后台
 *
 * 只在「目标账号当前是启用中的超管，且本次操作要让它不再是」时触发。
 * 否则任何人都能把最后一个超管降级，系统直接失去后台入口。
 */
function staff_last_super_guard(int $id, string $newRole, int $newStatus): ?string
{
    $row = db_row('SELECT role, status FROM ' . DB_PRE . 'admin WHERE id = ?', [$id]);
    if (!$row || (string)$row['role'] !== 'super' || (int)$row['status'] !== 1) {
        return null;
    }
    if ($newRole === 'super' && $newStatus === 1) {
        return null;
    }
    $alive = (int)db_one("SELECT COUNT(*) FROM " . DB_PRE . "admin WHERE role = 'super' AND status = 1 AND id <> ?", [$id], 0);
    if ($alive <= 0) {
        return '系统必须至少保留一名启用状态的超级管理员，无法对该账号降级或禁用';
    }
    return null;
}

// ---------------- POST 处理 ----------------

/**
 * 取出待操作账号并校验其确实属于本页
 *
 * 三道校验缺一不可：
 *   1. 记录存在
 *   2. 当前角色 === 本页角色 —— 列表只显示本站角色，这里也必须一致，
 *      否则改 POST 的 id 就能改到别页的人（超管在「客服管理」里改掉主管）
 *   3. 非管理者只能操作自己
 *
 * 注意第 2 条校验的是「该账号现在是什么角色」，而保存时提交的目标角色
 * 走另一套校验（$assignable）。两者分开，才能既保证列表干净、
 * 又允许在本页把客服提拔为主管。
 *
 * @return array{0:?array,1:?string} [记录, 错误信息]
 */
function staff_target(int $id, string $pageRole, string $myRole, bool $canManage, int $myId): array
{
    $row = db_row('SELECT * FROM ' . DB_PRE . 'admin WHERE id = ?', [$id]);
    if (!$row) {
        return [null, '账号不存在'];
    }
    if ((string)$row['role'] !== $pageRole) {
        return [null, '该账号是' . admin_role_label((string)$row['role']) . '，不在本页，请前往对应管理页'];
    }
    if (!staff_may_touch($row, $myRole, $pageRole, $canManage, $myId)) {
        return [null, '你只能修改自己的账号'];
    }
    return [$row, null];
}

if (is_post()) {
    csrf_guard();
    $act = post('act');
    $id  = (int)post('id');

    // 新建 / 编辑
    if ($act === 'save') {
        $wantRole = (string)post('role', $STAFF_ROLE);
        $status   = (int)post('status') === 1 ? 1 : 0;

        // 目标角色必须在 $assignable 内，防止改 POST 越权给自己升权。
        // 空值也在这里被拦下：$roleLocked 时隐藏域可能提交空串。
        if (!isset($assignable[$wantRole])) {
            flash('error', $wantRole === ''
                ? '缺少角色参数，无法保存'
                : '无权将该账号设为「' . admin_role_label($wantRole) . '」');
            redirect($id > 0 ? $self . '?edit=' . $id : $self);
        }

        $username = post('username');
        $pass     = (string)($_POST['password'] ?? '');
        $realname = post('realname');
        $email    = post('email');

        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            flash('error', '账号需为 3-32 位字母、数字或下划线');
            redirect($id > 0 ? $self . '?edit=' . $id : $self);
        }
        if ($pass !== '' && mb_strlen($pass) < 6) {
            flash('error', '密码至少 6 位');
            redirect($id > 0 ? $self . '?edit=' . $id : $self);
        }

        if ($id > 0) {
            [$row, $err] = staff_target($id, $STAFF_ROLE, $myRole, $canManage, (int)$admin['id']);
            if ($err !== null) {
                flash('error', $err);
                redirect($self);
            }
            // 非管理者只能改自己，这里再锁死启用状态：
            // 否则禁用前已登录的客服能在会话未超时的窗口里靠 save 把自己改回启用，
            // 绕过 toggle 分支的「不能禁用自己」。
            if (!$canManage) {
                $status = (int)$row['status'] === 1 ? 1 : 0;
            }
            $guard = staff_last_super_guard($id, $wantRole, $status);
            if ($guard !== null) {
                flash('error', $guard);
                redirect($self . '?edit=' . $id);
            }
            if (db_one('SELECT id FROM ' . DB_PRE . 'admin WHERE username = ? AND id <> ?', [$username, $id])) {
                flash('error', '该账号名已存在');
            } else {
                $set = 'username = ?, realname = ?, email = ?, role = ?, status = ?';
                $p   = [$username, $realname, $email, $wantRole, $status];
                if ($pass !== '') {
                    $set .= ', password = ?';
                    $p[]   = password_hash($pass, PASSWORD_DEFAULT);
                }
                $p[] = $id;
                db_query('UPDATE ' . DB_PRE . 'admin SET ' . $set . ' WHERE id = ?', $p);

                // 改了角色就意味着这个人不再属于本页，跳到他现在所属角色的
                // 管理页去，否则会看到「账号不见了」，以为操作失败
                if ($wantRole !== $STAFF_ROLE) {
                    flash('ok', admin_role_label((string)$row['role']) . '「' . $username . '」已改为' . admin_role_label($wantRole));
                    redirect(admin_role_page($wantRole) ?: $self);
                }
                flash('ok', '账号已更新');
            }
            redirect($self);
        }

        // 新建只对管理者开放：非管理者进来只能改自己
        if (!$canManage) {
            flash('error', '你只能修改自己的账号，无权新建');
            redirect($self);
        }
        if (db_one('SELECT id FROM ' . DB_PRE . 'admin WHERE username = ?', [$username])) {
            flash('error', '该账号名已存在');
        } elseif ($pass === '') {
            flash('error', '新建账号必须设置登录密码');
        } else {
            db_insert('INSERT INTO ' . DB_PRE . 'admin (username,password,realname,email,role,status) VALUES (?,?,?,?,?,?)',
                [$username, password_hash($pass, PASSWORD_DEFAULT), $realname, $email, $wantRole, $status]);
            flash('ok', admin_role_label($wantRole) . '账号已创建');
        }
        redirect($self);
    }

    // 启用 / 禁用
    if ($act === 'toggle') {
        [$row, $err] = staff_target($id, $STAFF_ROLE, $myRole, $canManage, (int)$admin['id']);
        if ($err !== null) {
            flash('error', $err);
        } elseif ((int)$id === (int)$admin['id']) {
            flash('error', '不能禁用自己');
        } else {
            $next = (int)$row['status'] === 1 ? 0 : 1;
            $guard = staff_last_super_guard($id, (string)$row['role'], $next);
            if ($guard !== null) {
                flash('error', $guard);
            } else {
                db_query('UPDATE ' . DB_PRE . 'admin SET status = ? WHERE id = ?', [$next, $id]);
                flash('ok', '已' . ($next ? '启用' : '禁用') . '「' . $row['username'] . '」');
            }
        }
        redirect($self);
    }

    // 删除
    if ($act === 'delete') {
        if ((int)$id === (int)$admin['id']) {
            flash('error', '不能删除自己的账号');
            redirect($self);
        }
        [$row, $err] = staff_target($id, $STAFF_ROLE, $myRole, $canManage, (int)$admin['id']);
        if ($err !== null) {
            flash('error', $err);
        } else {
            $guard = staff_last_super_guard($id, (string)$row['role'], 0);
            if ($guard !== null) {
                flash('error', $guard);
            } else {
                // 历史回复保留 admin_id，但姓名与头衔已快照在
                // author_name / author_role，删除账号不会让旧记录变成空白
                db_query('DELETE FROM ' . DB_PRE . 'admin WHERE id = ?', [$id]);
                // 处理人被删除后工单回到「未指派」，避免筛选条件指向空人
                db_query('UPDATE ' . DB_PRE . 'ticket SET assignee_id = 0 WHERE assignee_id = ?', [$id]);
                flash('ok', '账号已删除，其名下工单已恢复为未指派');
            }
        }
        redirect($self);
    }
}

// ---------------- 列表 ----------------

$editId = get_int('edit');

$sql = 'SELECT a.*, (SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t WHERE t.assignee_id = a.id AND t.status IN ("pending","processing")) todo,
               (SELECT COUNT(*) FROM ' . DB_PRE . 'ticket t WHERE t.assignee_id = a.id) total
        FROM ' . DB_PRE . 'admin a
        WHERE a.role = ?
        ORDER BY a.status DESC, a.id';
$lp  = [$STAFF_ROLE];

// 非管理者只能看到自己那一条
if (!$canManage) {
    $sql .= ' AND a.id = ?';
    $lp[] = (int)$admin['id'];
}

$list = db_all($sql, $lp);

$editing = $editId > 0 ? db_row('SELECT * FROM ' . DB_PRE . 'admin WHERE id = ?', [$editId]) : null;
if ($editing) {
    [$editing, $editingErr] = staff_target($editId, $STAFF_ROLE, $myRole, $canManage, (int)$admin['id']);
    if ($editingErr !== null) {
        flash('error', $editingErr);
        $editing = null;
    }
}

$enabledCount = count(array_filter($list, fn($a) => (int)$a['status'] === 1));
$busyCount    = array_sum(array_map(fn($a) => (int)$a['todo'], $list));
$totalTickets = array_sum(array_map(fn($a) => (int)$a['total'], $list));
$pageDesc     = $canManage
    ? '共 ' . count($list) . ' 位，启用 ' . $enabledCount . ' 位'
    : '仅显示你自己的账号';
$pageActions  = $canManage
    ? '<button class="btn btn-p btn-sm" data-modal="mAdd">+ 新增' . e($meta['label']) . '</button>'
    : '<button class="btn btn-p btn-sm" data-modal="mEditSelf">编辑我的资料</button>';

// 非管理者进来只能看自己一条记录，页面语义从「XX 管理」变成「我的账号」，
// 下面的团队统计对他也没有意义
if (!$canManage) {
    $pageTitle = '我的账号';
    $pageDesc  = '修改你的姓名、邮箱与登录密码';
}

require __DIR__ . '/_head.php';
?>

<div class="alert alert-info" style="margin-bottom:18px">
  <span class="ic">ℹ</span>
  <div>
    <?php if ($canManage): ?>
      <?= e($meta['desc']) ?>。<?= e($meta['label']) ?>之间不存在「只能处理自己名下工单」的限制，可以互相接手。
      <?php if (count($assignable) > 1): ?>
        在本页改动角色后，账号会移到对应角色的列表。
      <?php endif; ?>
    <?php else: ?>
      <?php
      // 「谁能任免本站角色」要从角色表反查，不能读 $meta['manage'] ——
      // 那是「本站角色能管谁」。对 admin/engineer/operator 而言它恰好是空数组，
      // 直接 implode 会输出「任免权限属于 ，」这样的断句。
      $appointees = [];
      foreach (admin_roles() as $rk2 => $rm2) {
          if (admin_can_manage_role($rk2, $STAFF_ROLE)) {
              $appointees[] = $rm2['label'];
          }
      }
      ?>
      你可以修改自己的账号资料与密码，但无法自行新增账号、调整角色或启用禁用 ——
      <?= e($meta['label']) ?>由<?= e(implode('、', $appointees)) ?>任免。
    <?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
<div class="stats">
  <div class="stat" style="color:var(--brand)">
    <div class="lb"><?= e($meta['label']) ?>总数</div><div class="vl"><?= e((string)count($list)) ?></div>
  </div>
  <div class="stat" style="color:#10b981">
    <div class="lb">启用中</div><div class="vl"><?= e((string)$enabledCount) ?></div>
  </div>
  <div class="stat" style="color:#f59e0b">
    <div class="lb">名下待办</div><div class="vl"><?= e((string)$busyCount) ?></div>
  </div>
  <div class="stat" style="color:#8b5cf6">
    <div class="lb">累计处理</div><div class="vl"><?= e((string)$totalTickets) ?></div>
  </div>
</div>
<?php endif; ?>

<div class="card mb">
  <div class="card-hd"><h2><?= e($meta['label']) ?>列表</h2></div>
  <div class="card-bd np">
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr>
          <th>账号</th><th>角色</th><th>邮箱</th><th>待办 / 累计</th><th>状态</th><th>最后登录</th><th style="text-align:right">操作</th>
        </tr></thead>
        <tbody>
          <?php foreach ($list as $a): ?>
            <tr>
              <td>
                <div class="t-main">
                  <?= e($a['username']) ?>
                  <?php if ((int)$a['id'] === (int)$admin['id']): ?><span class="badge badge-blue" style="margin-left:5px">我</span><?php endif; ?>
                </div>
                <div class="t-sub"><?= e($a['realname'] ?: '—') ?> · #<?= (int)$a['id'] ?></div>
              </td>
              <td>
                <span class="badge badge-<?= e(admin_role_color((string)$a['role'])) ?>"><?= e(admin_role_label((string)$a['role'])) ?></span>
              </td>
              <td class="small"><?= e($a['email'] ?: '—') ?></td>
              <td>
                <?php if ((int)$a['todo'] > 0): ?>
                  <span class="badge badge-amber"><?= (int)$a['todo'] ?></span>
                <?php else: ?>
                  <span class="muted">0</span>
                <?php endif; ?>
                <span class="t-sub">/ <?= (int)$a['total'] ?></span>
              </td>
              <td>
                <?php if ((int)$a['status'] === 1): ?>
                  <span class="badge badge-green badge-dot">启用</span>
                <?php else: ?>
                  <span class="badge badge-gray badge-dot">禁用</span>
                <?php endif; ?>
              </td>
              <td class="muted small nowrap">
                <?= e($a['last_login_at'] ? time_ago($a['last_login_at']) : '从未') ?>
                <?php if ($a['last_login_ip']): ?><div class="t-sub mono"><?= e($a['last_login_ip']) ?></div><?php endif; ?>
              </td>
              <td class="act">
                <a href="?edit=<?= (int)$a['id'] ?>#mEdit">编辑</a>
                <?php if ($canManage): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit"><?= (int)$a['status'] === 1 ? '禁用' : '启用' ?></button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="d" type="submit" data-confirm="确定删除账号「<?= e($a['username']) ?>」？其名下工单将恢复为未指派。"
                            <?= (int)$a['id'] === (int)$admin['id'] ? 'disabled' : '' ?>>删除</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$list): ?>
            <tr><td colspan="7"><div class="empty"><div class="ic">👤</div><p>暂无<?= e($meta['label']) ?>账号</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
/**
 * 账号表单弹层
 * 注意：本函数是文件顶层的无条件声明，PHP 在编译期即会提升，
 * 因此虽然定义在 require _head.php 之后、调用点在更下方，仍可正常调用。
 * 请勿将其包进 if / 条件块或闭包中，否则会因作用域变化而找不到函数。
 */
function staffForm(array $a, string $title, string $submitText, string $formId, array $roleOptions, bool $roleLocked, string $defaultRole = 'operator', bool $statusLocked = false): void
{
    $isEdit  = !empty($a['id']);
    $sampleTk = admin_role_title((string)(array_key_first($roleOptions) ?: $defaultRole));
    // 本页角色固定时走不到下面的 else 分支，这里只在可多选时有值
    $assignToLabel = implode(' / ', $roleOptions);
    ?>
    <div class="modal-hd"><h3><?= e($title) ?></h3><button type="button" data-close>×</button></div>
    <form method="post" id="<?= e($formId) ?>" data-oneshot>
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($a['id'] ?? 0) ?>">
      <div class="modal-bd">
        <div class="frow">
          <div class="field">
            <label>登录账号 <span class="req">*</span></label>
            <input type="text" name="username" class="input" required maxlength="32" value="<?= e($a['username'] ?? '') ?>" placeholder="字母/数字/下划线">
          </div>
          <div class="field">
            <label><?= $isEdit ? '重置密码' : '登录密码' ?> <?= $isEdit ? '' : '<span class="req">*</span>' ?></label>
            <input type="text" name="password" class="input" <?= $isEdit ? '' : 'required' ?> minlength="6"
                   placeholder="<?= $isEdit ? '留空则不修改' : '至少 6 位' ?>">
          </div>
          <div class="field">
            <label>姓名 / 昵称</label>
            <input type="text" name="realname" class="input" maxlength="50" value="<?= e($a['realname'] ?? '') ?>">
          </div>
          <div class="field">
            <label>邮箱</label>
            <input type="email" name="email" class="input" value="<?= e($a['email'] ?? '') ?>">
          </div>
          <div class="field">
            <label>角色权限</label>
            <select name="role" class="select" <?= $roleLocked ? 'disabled' : '' ?>>
              <?php foreach ($roleOptions as $rk => $rl): ?>
                <option value="<?= e($rk) ?>" <?= (string)($a['role'] ?? '') === (string)$rk ? 'selected' : '' ?>><?= e($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($roleLocked): ?>
              <input type="hidden" name="role" value="<?= e((string)($a['role'] ?? $defaultRole)) ?>">
              <div class="tip">你在本页没有任免权限，角色不可更改</div>
            <?php else: ?>
              <div class="tip">改完后该账号会移到「<?= e($assignToLabel) ?>」列表，本页不再显示</div>
            <?php endif; ?>
          </div>
          <div class="field">
            <label>账号状态</label>
            <select name="status" class="select" <?= $statusLocked ? 'disabled' : '' ?>>
              <option value="1" <?= (int)($a['status'] ?? 1) === 1 ? 'selected' : '' ?>>启用</option>
              <option value="0" <?= (int)($a['status'] ?? 0) === 0 ? 'selected' : '' ?>>禁用</option>
            </select>
            <?php if ($statusLocked): ?>
              <input type="hidden" name="status" value="<?= (int)($a['status'] ?? 1) ?>">
              <div class="tip">启用与禁用需由任免者操作</div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!$roleLocked): ?>
          <div class="tip" style="margin-top:12px">
            该账号回复工单时，名字前会自动附加头衔（如「<?= e($sampleTk) ?>」前缀）。
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-o" data-close>取消</button>
        <button class="btn btn-p" type="submit"><?= e($submitText) ?></button>
      </div>
    </form>
    <?php
}
?>

<?php if ($canManage): ?>
<div class="modal" id="mAdd">
  <div class="modal-box">
    <?php staffForm([], '新增' . $meta['label'] . '账号', '创建', 'fAddStaff', $assignable, count($assignable) === 1, $STAFF_ROLE); ?>
  </div>
</div>
<?php endif; ?>

<?php if ($editing): ?>
<div class="modal open" id="mEdit">
  <div class="modal-box">
    <?php staffForm($editing, '编辑' . admin_role_label((string)$editing['role']) . '账号', '保存', 'fEditStaff', $assignable, count($assignable) === 1, $STAFF_ROLE); ?>
  </div>
</div>
<?php elseif (!$canManage): ?>
<div class="modal" id="mEditSelf">
  <div class="modal-box">
    <?php staffForm($list[0] ?? [], '编辑我的资料', '保存', 'fSelfStaff', $assignable, true, $STAFF_ROLE, true); ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_foot.php'; ?>
