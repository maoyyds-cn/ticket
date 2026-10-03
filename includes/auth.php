<?php
/**
 * 前后台认证
 */
declare(strict_types=1);

/** 后台闲置超时秒数（30 分钟无任何操作才登出） */
const ADMIN_IDLE_TIMEOUT = 1800;

// ---------------- 后台 ----------------

function admin_login(int $id, string $ip, string $ua): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']    = $id;
    $_SESSION['admin_login'] = time();
    $_SESSION['admin_active'] = time();
    // 后台登录同样清掉前台身份，两种身份不应在同一会话共存
    unset($_SESSION['user_id'], $_SESSION['user_login']);
    db_query('UPDATE ' . DB_PRE . 'admin SET last_login_at = NOW(), last_login_ip = ?, login_fail = 0, locked_until = NULL WHERE id = ?', [$ip, $id]);
    db_insert('INSERT INTO ' . DB_PRE . 'admin_session (admin_id, ip, user_agent) VALUES (?, ?, ?)', [$id, $ip, mb_substr($ua, 0, 250)]);
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_login'], $_SESSION['admin_active']);
    session_regenerate_id(true);
}

function current_admin(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return $cache = null;
    }
    // 闲置超时：基于最后活动时间，而非登录时刻。
    // 否则连续操作也可能在登录满 30 分钟时被误踢。
    $now = time();
    // 旧会话没有 admin_active 时回退到登录时间，避免误判为超时
    $lastActive = (int)($_SESSION['admin_active'] ?? $_SESSION['admin_login'] ?? 0);
    if ($lastActive > 0 && $now - $lastActive > ADMIN_IDLE_TIMEOUT) {
        admin_logout();
        // 记录原因，由 require_admin() 统一提示，避免重复刷屏
        $_SESSION['admin_timeout'] = 1;
        return $cache = null;
    }
    $row = db_row('SELECT * FROM ' . DB_PRE . 'admin WHERE id = ?', [$id]);
    if (!$row || (int)$row['status'] !== 1) {
        admin_logout();
        return $cache = null;
    }
    // 滑动刷新最后活动时间
    $_SESSION['admin_active'] = $now;
    return $cache = $row;
}

function is_admin(): bool
{
    return current_admin() !== null;
}

/**
 * 当前会话是否为后台特殊用户（客服/工程师/主管/技术管理员/超管）
 *
 * 用于禁止内部人员以自己的身份创建工单：内部账号对外应当只处理工单，
 * 不应该混在用户堆里提交工单，既干扰统计也让身份难以追溯。
 */
function is_staff(): bool
{
    return is_admin();
}

function require_admin(): array
{
    $a = current_admin();
    if ($a === null) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/');
        if (!empty($_SESSION['admin_timeout'])) {
            unset($_SESSION['admin_timeout']);
            flash('error', '登录已超时（30 分钟无操作），请重新登录后继续');
        } else {
            flash('error', '请先登录后台');
        }
        redirect('login.php?back=' . $back);
    }
    return $a;
}

function require_super(): array
{
    $a = require_admin();
    if ((string)$a['role'] !== 'super') {
        flash('error', '该操作需要超级管理员权限');
        redirect('index.php');
    }
    return $a;
}

/**
 * 要求最低角色等级：admin(4) < super(5)
 *
 * 仅用于「系统配置线」这类粗粒度门槛。人事任免线不走这里，
 * 因为主管(level 3) 能任免客服、技术管理员(level 4) 却不能，
 * 等级高低与任免能力不正交，用等级门槛会把权限判错。
 *
 * @param string $min admin|super
 */
function require_role(string $min): array
{
    $a = require_admin();
    $need = admin_role_level($min);
    if (admin_role_level((string)$a['role']) < $need) {
        flash('error', '该操作需要' . admin_role_label($min) . '权限');
        redirect('index.php');
    }
    return $a;
}

// ---------------- 前台用户 ----------------

function user_login(int $id, string $ip): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = $id;
    $_SESSION['user_login'] = time();
    // 前台登录必须清掉后台身份。原实现两者共存于同一会话，导致
    // 管理员在前台浏览时 is_admin() 仍为真，工单详情的回复框被
    // !$isAdminV 条件整块隐藏，看起来像「登录用户不能回复自己的工单」。
    unset($_SESSION['admin_id'], $_SESSION['admin_login'], $_SESSION['admin_active'], $_SESSION['admin_timeout']);
    db_query('UPDATE ' . DB_PRE . 'user SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, $id]);
}

function user_logout(): void
{
    unset($_SESSION['user_id'], $_SESSION['user_login']);
    session_regenerate_id(true);
}

function current_user(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return $cache = null;
    }
    $row = db_row('SELECT * FROM ' . DB_PRE . 'user WHERE id = ? AND status = 1', [$id]);
    return $cache = $row ?: null;
}

function is_login(): bool
{
    return current_user() !== null;
}
