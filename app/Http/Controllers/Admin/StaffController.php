<?php

/**
 * 成员管理
 *
 * 旧版把「按角色管理成员」拆成 5 个文件：admins.php、supervisors.php、
 * engineers.php、supers.php、support.php，每个 6 行，只差一个角色常量；
 * 另有一个 staff.php 做只读总览，内部又复制了一份成员查询。
 * 这里合并成一条路由 /admin/staff，角色通过查询参数选择。
 *
 * 权限判定完全交给 Role::canManageRole()，并且守住三条不变量：
 *  1) 不能操作自己（改角色、停用、删除、重置自己的密码走另外的入口）；
 *  2) 系统必须始终保留至少一名启用中的超级管理员；
 *  3) 不能把自己停用。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;
use App\Domain\Staff\Role;
use App\Support\Validator;

final class StaffController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly Database $db,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $actor = $this->staff();
        $manageable = Role::manageableRoles($actor->role);

        // 未指定角色时默认看第一个可管理的角色，或全部
        $role = $request->query('role');
        if ($role === '' || !Role::exists($role)) {
            $role = array_key_first($manageable) ?: '';
        }

        if ($role !== '' && !Role::canManageRole($actor->role, $role)) {
            $this->session()->flash('error', '你没有管理「' . Role::label($role) . '」的权限。');
            return Response::redirect(url('/admin/staff'));
        }

        $rows = $role === ''
            ? []
            : $this->db->all(
                // 字符串字面量一律用单引号：服务器若开启 ANSI_QUOTES，
                // 双引号会被当作标识符，这类 SQL 会直接报错
                "SELECT s.*,
                        (SELECT COUNT(*) FROM `ticket` t WHERE t.assignee_id = s.id
                           AND t.status IN ('pending','processing','replied')) AS todo
                 FROM `staff` s WHERE s.role = ? ORDER BY s.status DESC, s.id ASC",
                [$role]
            );

        // 各角色的成员数，用于页签上的计数
        $counts = [];
        foreach ($this->db->all('SELECT `role`, COUNT(*) AS n FROM `staff` GROUP BY `role`') as $r) {
            $counts[(string)$r['role']] = (int)$r['n'];
        }

        $editId = $request->queryInt('edit');
        $editing = null;
        if ($editId > 0) {
            $candidate = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$editId]);
            if ($candidate !== null && Role::canManageRole($actor->role, (string)$candidate['role'])) {
                $editing = $candidate;
            }
        }

        return $this->admin('admin/staff', [
            'pageTitle' => '成员管理',
            'pageDesc' => '管理后台账号与角色',
            'activeNav' => 'staff',
            'role' => $role,
            'roles' => $manageable,
            'rows' => $rows,
            'counts' => $counts,
            'editing' => $editing,
            'actor' => $actor,
            'allRoles' => Role::meta(),
        ]);
    }

    public function save(Request $request): Response
    {
        $actor = $this->staff();
        $id = $request->postInt('id');

        // 新增
        if ($id === 0) {
            $validator = Validator::make($request->body(), [
                'username' => 'required|username',
                'password' => 'required|password:8|max:72',
                'realname' => 'max:50',
                'email' => 'email|max:120',
            ], [
                'username' => '登录名',
                'password' => '初始密码',
                'realname' => '姓名',
                'email' => '邮箱',
            ]);
            if ($validator->fails()) {
                $this->session()->flash('error', $validator->firstError());
                return Response::redirect($this->backTo($request, '/admin/staff'));
            }

            $role = $request->post('role');
            if (!Role::canManageRole($actor->role, $role)) {
                $this->session()->flash('error', '不能创建该角色的账号。');
                return Response::redirect($this->backTo($request, '/admin/staff'));
            }

            $result = $this->auth->createStaff(
                (string)$validator->cleanValue('username'),
                $request->raw('password'),
                $role,
                (string)$validator->cleanValue('realname'),
                (string)$validator->cleanValue('email')
            );
            if (!$result['ok']) {
                $this->session()->flash('error', $result['error'] ?? '创建失败');
                return Response::redirect($this->backTo($request, '/admin/staff'));
            }

            \App\Core\Application::log(
                '[ADMIN] 创建后台账号 id=' . $result['id'] . ' role=' . $role . ' by staff_id=' . $actor->id,
                'security.log'
            );
            $this->session()->flash('ok', '账号已创建。');
            return Response::redirect(url('/admin/staff?role=' . rawurlencode($role)));
        }

        // 修改
        $target = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$id]);
        if ($target === null) {
            $this->session()->flash('error', '账号不存在。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }
        if (!Role::canManageRole($actor->role, (string)$target['role'])) {
            $this->session()->flash('error', '你没有管理该账号的权限。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $validator = Validator::make($request->body(), [
            'realname' => 'max:50',
            'email' => 'email|max:120',
        ], ['realname' => '姓名', 'email' => '邮箱']);
        if ($validator->fails()) {
            $this->session()->flash('error', $validator->firstError());
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        // 角色：只有能管理目标角色的管理者才能改，且新角色也必须在可管理范围内
        $newRole = $request->post('role');
        if ($newRole === '' || !Role::exists($newRole)) {
            $newRole = (string)$target['role'];
        }
        if (!Role::canManageRole($actor->role, $newRole)) {
            $this->session()->flash('error', '不能把账号改为该角色。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $result = $this->auth->updateStaff(
            $target,
            $newRole,
            (string)$validator->cleanValue('realname'),
            (string)$validator->cleanValue('email'),
            null,
            $actor->id
        );
        if (!$result['ok']) {
            $this->session()->flash('error', $result['error'] ?? '保存失败');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        \App\Core\Application::log(
            '[ADMIN] 修改后台账号 id=' . $id . ' role=' . $newRole . ' by staff_id=' . $actor->id,
            'security.log'
        );
        $this->session()->flash('ok', '账号已保存。');
        return Response::redirect(url('/admin/staff?role=' . rawurlencode($newRole)));
    }

    public function toggle(Request $request): Response
    {
        $actor = $this->staff();
        $id = (int)$request->attribute('id', 0);
        $target = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$id]);

        if ($target === null) {
            $this->session()->flash('error', '账号不存在。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }
        if (!Role::canManageRole($actor->role, (string)$target['role'])) {
            $this->session()->flash('error', '你没有管理该账号的权限。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $next = (int)$target['status'] === 1 ? 0 : 1;
        $result = $this->auth->updateStaff($target, null, null, null, $next, $actor->id);
        if (!$result['ok']) {
            $this->session()->flash('error', $result['error'] ?? '操作失败');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        \App\Core\Application::log(
            '[ADMIN] ' . ($next === 1 ? '启用' : '停用') . '后台账号 id=' . $id . ' by staff_id=' . $actor->id,
            'security.log'
        );
        $this->session()->flash('ok', '账号已' . ($next === 1 ? '启用' : '停用') . '。');
        return Response::redirect($this->backTo($request, '/admin/staff'));
    }

    public function resetPassword(Request $request): Response
    {
        $actor = $this->staff();
        $id = (int)$request->attribute('id', 0);
        $target = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$id]);

        if ($target === null) {
            $this->session()->flash('error', '账号不存在。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }
        // 超管可以重置自己的密码（走另一个入口 /admin/account），
        // 但这里禁止通过成员管理重置自己，避免误操作把自己锁在外面
        if ($id === $actor->id) {
            $this->session()->flash('warn', '修改自己的密码请到「我的账号」页面。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }
        if (!Role::canManageRole($actor->role, (string)$target['role'])) {
            $this->session()->flash('error', '你没有管理该账号的权限。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $password = $request->raw('password');
        if (mb_strlen($password) < 8) {
            $this->session()->flash('error', '新密码至少 8 位。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $this->auth->resetStaffPassword($id, $password);
        \App\Core\Application::log(
            '[ADMIN] 重置后台账号密码 id=' . $id . ' by staff_id=' . $actor->id,
            'security.log'
        );
        $this->session()->flash('ok', '密码已重置，请通知该成员尽快修改。');
        return Response::redirect($this->backTo($request, '/admin/staff'));
    }

    public function destroy(Request $request): Response
    {
        $actor = $this->staff();
        $id = (int)$request->attribute('id', 0);
        $target = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$id]);

        if ($target === null) {
            $this->session()->flash('error', '账号不存在。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }
        if (!Role::canManageRole($actor->role, (string)$target['role'])) {
            $this->session()->flash('error', '你没有管理该账号的权限。');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        $result = $this->auth->deleteStaff($target, $actor->id);
        if (!$result['ok']) {
            $this->session()->flash('error', $result['error'] ?? '删除失败');
            return Response::redirect($this->backTo($request, '/admin/staff'));
        }

        // 与旧版不同：删除账号时把其名下工单的处理人清空，
        // 否则列表里会出现一个指向已删除账号的「处理人」，
        // 指派筛选也会因此查不到这些工单。
        $this->db->query('UPDATE `ticket` SET `assignee_id` = 0 WHERE `assignee_id` = ?', [$id]);

        \App\Core\Application::log(
            '[ADMIN] 删除后台账号 id=' . $id . ' username=' . (string)$target['username']
            . ' role=' . (string)$target['role'] . ' by staff_id=' . $actor->id,
            'security.log'
        );
        $this->session()->flash('ok', '账号已删除，其名下工单的处理人已清空。');
        return Response::redirect($this->backTo($request, '/admin/staff'));
    }
}
