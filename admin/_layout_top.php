<?php
/** 后台侧栏（被各页面 include） */
$admin = $admin ?? current_admin();
$pendingCount = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket WHERE status IN ("pending","processing")', [], 0);
$cur = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * 侧栏分组
 *
 * 可见性有四种判据，不能一律按等级大小比较：
 *   'ticket'      工单线，所有后台角色都能处理工单
 *   'cfg'         系统配置线，需技术管理员(level 4)及以上
 *   'super'       仅超管
 *   'staff:<role>' 能任免 <role> 的角色（主管可任免客服与工程师）
 *   'staff:self'  「我的账号」自助改密，所有后台角色可见
 *
 * 早期版本统一用等级判断，结果是主管看不到客服管理页、技术管理员反而
 * 看得到，两者都错：任免能力与角色等级不正交，故改为按 manage 声明判定。
 */
$myRole = (string)($admin['role'] ?? 'operator');

// 角色管理页对非管理者只开放「改自己」这一条记录，但菜单按任免权限过滤后
// 本人看不到入口，等于没有自助改密的路径。仅当该角色管不了自己这个角色时
// 才补一条（超管能任免超管，团队里已有「超级管理员」入口，不重复）。
$selfPage = admin_can_manage_role($myRole, $myRole) ? '' : admin_role_page($myRole);

$navGroups = [
    '工单' => [
        ['tickets.php', '📋', '工单列表', $pendingCount, 'ticket'],
        ['index.php', '📊', '数据概览', 0, 'ticket'],
    ],
    '知识库' => [
        ['faqs.php', '❓', 'FAQ 管理', 0, 'ticket'],
        ['categories.php', '📂', '分类管理', 0, 'ticket'],
    ],
    '团队' => [
        ['support.php', '🎧', '客服管理', 0, 'staff:operator'],
        ['engineers.php', '🛠', '工程师管理', 0, 'staff:engineer'],
        ['supervisors.php', '🧭', '主管管理', 0, 'staff:supervisor'],
        ['admins.php', '🔧', '技术管理员', 0, 'staff:admin'],
        ['supers.php', '👑', '超级管理员', 0, 'staff:super'],
        ['staff.php', '🗂', '成员总览', 0, 'super'],
    ],
    '系统' => [
        ['users.php', '👥', '前台用户', 0, 'cfg'],
        ['mail.php', '📮', '邮件设置', 0, 'cfg'],
        ['logs.php', '📜', '操作日志', 0, 'cfg'],
        ['schema-check.php', '🩺', '结构自检', 0, 'super'],
        ['settings.php', '⚙️', '系统设置', 0, 'super'],
    ],
];
if ($selfPage !== '') {
    $navGroups['我的'] = [[$selfPage, '🔑', '我的账号', 0, 'staff:self']];
}

function nav_visible(string $need, string $role): bool
{
    if ($need === 'ticket') {
        return admin_can_handle_ticket($role);
    }
    if ($need === 'cfg') {
        return admin_role_level($role) >= admin_role_level('admin');
    }
    if ($need === 'super') {
        return $role === 'super';
    }
    if ($need === 'staff:self') {
        return admin_role_exists($role);
    }
    if (str_starts_with($need, 'staff:')) {
        return admin_can_manage_role($role, substr($need, 6));
    }
    return false;
}
?>
<aside class="side">
  <div class="side-hd">
    <a href="index.php">
      <span class="lg">🎫</span>
      <span>工单管理后台<small>TICKET SYSTEM v<?= e(APP_VER) ?></small></span>
    </a>
  </div>
  <nav class="side-nav">
    <?php foreach ($navGroups as $group => $items): ?>
        <?php
        // 先过滤再输出，否则会出现空分组标题
        $vis = array_filter($items, fn($it) => nav_visible((string)$it[4], $myRole));
        if (!$vis) { continue; }
        ?>
        <div class="grp"><?= e($group) ?></div>
        <?php foreach ($vis as [$href, $ic, $label, $tag]): ?>
        <a href="<?= e($href) ?>" class="<?= $cur === $href ? 'on' : '' ?>">
          <span class="ic"><?= $ic ?></span><span><?= e($label) ?></span>
          <?php if ($tag > 0): ?><span class="tag"><?= e((string)$tag) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
  </nav>
  <div class="side-ft">
    <a href="../index.php" target="_blank">🌐 查看前台</a>
    <a href="logout.php">🚪 退出登录</a>
  </div>
</aside>
