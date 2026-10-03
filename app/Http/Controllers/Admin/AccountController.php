<?php

/**
 * 后台「我的账号」
 *
 * 单独一个入口，而不是像旧版那样必须由超管在成员管理里重置自己的密码
 * （旧版甚至禁止管理员操作自己的记录，导致改自己的资料无处可去）。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;
use App\Support\Validator;

final class AccountController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly Database $db,
        private readonly Session $session,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $staff = $this->staff();

        $logins = $this->db->all(
            'SELECT `ip`, `user_agent`, `created_at` FROM `staff_session`
             WHERE `staff_id` = ? ORDER BY `id` DESC LIMIT 8',
            [$staff->id]
        );

        return $this->admin('admin/account', [
            'pageTitle' => '我的账号',
            'activeNav' => 'account',
            'staff' => $staff,
            'logins' => $logins,
            'todo' => $this->db->int(
                "SELECT COUNT(*) FROM `ticket` WHERE `assignee_id` = ? AND `status` IN ('pending','processing','replied')",
                [$staff->id]
            ),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $staff = $this->staff();
        $current = $request->raw('current_password');
        $new = $request->raw('new_password');
        $confirm = $request->raw('new_password2');

        if ($new !== $confirm) {
            $this->session()->flash('error', '两次输入的新密码不一致。');
            return Response::redirect(url('/admin/account'));
        }

        $validator = Validator::make(['new_password' => $new], ['new_password' => 'required|min:8|max:72'], [
            'new_password' => '新密码',
        ]);
        if ($validator->fails()) {
            $this->session()->flash('error', $validator->firstError());
            return Response::redirect(url('/admin/account'));
        }

        // 校验当前密码：不能只凭一个有效会话就改密码，
        // 否则任何一次会话被借用（例如公共电脑未登出）都能永久接管账号
        $row = $this->db->row('SELECT `password` FROM `staff` WHERE `id` = ?', [$staff->id]);
        if ($row === null || !password_verify($current, (string)$row['password'])) {
            \App\Core\Application::log(
                '[SECURITY] 后台改密时当前密码校验失败 staff_id=' . $staff->id,
                'security.log'
            );
            $this->session()->flash('error', '当前密码不正确。');
            return Response::redirect(url('/admin/account'));
        }

        $this->db->query(
            'UPDATE `staff` SET `password` = ? WHERE `id` = ?',
            [password_hash($new, PASSWORD_DEFAULT), $staff->id]
        );

        // 改密后重新生成会话 ID，使可能已泄露的旧会话失效
        $this->session->regenerate();
        \App\Core\Application::log('[ADMIN] 后台账号自行修改密码 staff_id=' . $staff->id, 'security.log');
        $this->session()->flash('ok', '密码已更新。');
        return Response::redirect(url('/admin/account'));
    }

    public function updateProfile(Request $request): Response
    {
        $staff = $this->staff();

        $validator = Validator::make($request->body(), [
            'realname' => 'max:50',
            'email' => 'email|max:120',
        ], ['realname' => '姓名', 'email' => '邮箱']);
        if ($validator->fails()) {
            $this->session()->flash('error', $validator->firstError());
            return Response::redirect(url('/admin/account'));
        }

        // 只允许改姓名与邮箱。角色与启用状态不在这里改——
        // 让它自己能改角色等于把「不能修改自己的角色」那条不变量绕过去了。
        $this->db->query(
            'UPDATE `staff` SET `realname` = ?, `email` = ? WHERE `id` = ?',
            [
                (string)$validator->cleanValue('realname'),
                (string)$validator->cleanValue('email'),
                $staff->id,
            ]
        );

        $this->auth->refresh();
        $this->session()->flash('ok', '资料已保存。');
        return Response::redirect(url('/admin/account'));
    }
}
