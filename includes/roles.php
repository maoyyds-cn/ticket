<?php
/**
 * 后台角色定义 —— 权限模型的唯一来源
 *
 * 五种角色，四条权限线彼此独立，不要用「等级大小」一刀切：
 *
 *   super      超级管理员  全部权限，可任免其他四种角色
 *   admin      技术管理员  系统配置线（邮件 / 用户 / 日志 / 导出），不参与人事任免
 *   supervisor 主管        工单线 + 可任免客服、工程师
 *   engineer   工程师      工单线，可处理任意工单（含他人负责的）
 *   operator   客服        工单线，可处理任意工单（含他人负责的）
 *
 * 为什么不用 level 大小比较：
 * 「任免」和「系统配置」是两条正交的线。主管(level 3) 比技术管理员(level 4)
 * 低，但主管能任免客服、技术管理员反而不能。若用 require_role('admin') 这类
 * 等级门槛实现，任免能力会错位。因此这里显式声明每种角色「能管哪些角色」，
 * 由 admin_can_manage_role() 判定。
 */
declare(strict_types=1);

/**
 * 角色元数据表
 * label  显示名（不带方括号，用于下拉、徽章）
 * title  头衔（回复工单时显示在名字前，如「【客服】张三」）
 * manage 可任免的角色列表。super 直接列出全部五种，不使用通配符 ——
 *        admin_can_manage_role() 只做字面匹配，写 '*' 会让超管任免不了任何人。
 */
function admin_roles(): array
{
    static $roles = null;
    if ($roles !== null) {
        return $roles;
    }
    return $roles = [
        'super' => [
            'label' => '超级管理员',
            'title' => '【超级管理员】',
            'level' => 5,
            'color' => 'violet',
            'manage' => ['super', 'admin', 'supervisor', 'engineer', 'operator'],
            'desc'  => '拥有全部权限，可任免其他四种角色',
        ],
        'admin' => [
            'label' => '技术管理员',
            'title' => '【技术管理员】',
            'level' => 4,
            'color' => 'blue',
            'manage' => [],
            'desc'  => '负责邮件配置、用户管理、操作日志、导出，不参与人事任免',
        ],
        'supervisor' => [
            'label' => '主管',
            'title' => '【主管】',
            'level' => 3,
            'color' => 'amber',
            'manage' => ['engineer', 'operator'],
            'desc'  => '可处理工单，并任免客服与工程师',
        ],
        'engineer' => [
            'label' => '工程师',
            'title' => '【工程师】',
            'level' => 2,
            'color' => 'green',
            'manage' => [],
            'desc'  => '可处理任意工单，包括其他工程师负责的工单',
        ],
        'operator' => [
            'label' => '客服',
            'title' => '【客服】',
            'level' => 1,
            'color' => 'gray',
            'manage' => [],
            'desc'  => '可处理任意工单，包括其他客服负责的工单',
        ],
    ];
}

function admin_role_meta(string $r): array
{
    $all = admin_roles();
    return $all[$r] ?? [
        'label' => $r,
        'title' => '【' . $r . '】',
        'level' => 0,
        'color' => 'gray',
        'manage' => [],
        'desc'  => '',
    ];
}

function admin_role_label(string $r): string
{
    return admin_role_meta($r)['label'];
}

/** 回复工单时显示的头衔，如「【客服】」 */
function admin_role_title(string $r): string
{
    return admin_role_meta($r)['title'];
}

function admin_role_color(string $r): string
{
    return admin_role_meta($r)['color'];
}

/** 角色等级，用于导航显示与「至少达到某级」这类粗粒度判断 */
function admin_role_level(string $r): int
{
    return (int)admin_role_meta($r)['level'];
}

function admin_role_exists(string $r): bool
{
    return isset(admin_roles()[$r]);
}

/**
 * 角色对应的管理页文件名
 *
 * 集中在这里而不是各处内联数组：admin/staff.php（成员总览入口）、
 * admin/_layout_top.php（侧栏「我的账号」）、admin/staff_lib.php（改任后跳转）
 * 都要用到这张映射。分散写三份，新增角色时必然漏改其中一处，
 * 漏改的那处会静默变成死链。
 */
function admin_role_page(string $r): string
{
    return [
        'super'      => 'supers.php',
        'admin'      => 'admins.php',
        'supervisor' => 'supervisors.php',
        'engineer'   => 'engineers.php',
        'operator'   => 'support.php',
    ][$r] ?? '';
}

/**
 * 判断 $actor 能否任免 $target 角色
 *
 * 注意这里只管「角色」，不管具体人。最终能否操作还要叠加：
 *   - 不能操作自己
 *   - 系统必须始终保留至少一名启用中的超级管理员
 */
function admin_can_manage_role(string $actor, string $target): bool
{
    $meta = admin_role_meta($actor);
    $allow = (array)$meta['manage'];
    return in_array($target, $allow, true);
}

/** 该角色能操作工单（所有后台角色都可以，保留此函数便于将来收紧） */
function admin_can_handle_ticket(string $r): bool
{
    return admin_role_exists($r);
}

/** 列出所有可被当前管理员任免的角色，供下拉框使用 */
function admin_manageable_roles(string $actor): array
{
    $out = [];
    foreach ((array)admin_role_meta($actor)['manage'] as $r) {
        $out[$r] = admin_role_label($r);
    }
    return $out;
}
