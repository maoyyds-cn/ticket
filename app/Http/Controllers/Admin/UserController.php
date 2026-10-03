<?php

/**
 * 前台用户管理
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;

final class UserController extends AdminController
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
        $keyword = $request->query('q');
        $statusRaw = $request->query('status');

        $where = ['1 = 1'];
        $params = [];
        if ($keyword !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $where[] = "(u.username LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.qq LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        if ($statusRaw === '0' || $statusRaw === '1') {
            $where[] = 'u.status = ?';
            $params[] = (int)$statusRaw;
        }

        $perPage = 20;
        $page = max(1, $request->queryInt('page', 1));

        $total = $this->db->int(
            'SELECT COUNT(*) FROM `user` u WHERE ' . implode(' AND ', $where),
            $params
        );

        $rows = $this->db->all(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM `ticket` t WHERE t.user_id = u.id) AS ticket_count,
                    (SELECT COUNT(*) FROM `ticket` t WHERE t.user_id = u.id
                       AND t.status IN ('pending','processing','replied')) AS open_count
             FROM `user` u
             WHERE " . implode(' AND ', $where) . '
             ORDER BY u.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return $this->admin('admin/users', [
            'pageTitle' => '前台用户',
            'pageDesc' => '共 ' . $total . ' 个账号',
            'activeNav' => 'users',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'totalPages' => (int)max(1, (int)ceil($total / $perPage)),
            'keyword' => $keyword,
            'statusRaw' => (string)$statusRaw,
            'enabledCount' => $this->db->int('SELECT COUNT(*) FROM `user` WHERE `status` = 1'),
        ]);
    }

    public function toggle(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $user = $this->db->row('SELECT * FROM `user` WHERE `id` = ?', [$id]);
        if ($user === null) {
            $this->session()->flash('error', '用户不存在。');
            return Response::redirect($this->backTo($request, '/admin/users'));
        }

        $next = (int)$user['status'] === 1 ? 0 : 1;
        $this->db->query('UPDATE `user` SET `status` = ? WHERE `id` = ?', [$next, $id]);

        // 被停用的用户如果正持有有效会话，应当立刻失去访问能力：
        // current_user() 每次都会校验 status=1，所以无需额外踢出逻辑，
        // 这里只是记录一次审计。
        \App\Core\Application::log(
            '[ADMIN] ' . ($next === 1 ? '启用' : '停用') . '前台用户 id=' . $id
            . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        $this->session()->flash('ok', '账号已' . ($next === 1 ? '启用' : '停用') . '。');
        return Response::redirect($this->backTo($request, '/admin/users'));
    }

    public function resetPassword(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $user = $this->db->row('SELECT * FROM `user` WHERE `id` = ?', [$id]);
        if ($user === null) {
            $this->session()->flash('error', '用户不存在。');
            return Response::redirect($this->backTo($request, '/admin/users'));
        }

        $password = $request->raw('password');
        if (mb_strlen($password) < 8) {
            $this->session()->flash('error', '新密码至少 8 位。');
            return Response::redirect($this->backTo($request, '/admin/users'));
        }

        $this->db->query(
            'UPDATE `user` SET `password` = ? WHERE `id` = ?',
            [password_hash($password, PASSWORD_DEFAULT), $id]
        );
        \App\Core\Application::log(
            '[ADMIN] 重置前台用户密码 id=' . $id . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        $this->session()->flash('ok', '密码已重置，请把新密码转告该用户。');
        return Response::redirect($this->backTo($request, '/admin/users'));
    }

    /**
     * 删除用户。
     *
     * 与旧版的差别：旧版直接 DELETE，留下 user_id 指向不存在行的工单，
     * 详情页会显示成「注册用户#N」这种悬空引用。这里先把工单转为访客工单
     * （清空 user_id 但保留 contact_email），历史工单因此仍然可查、可回复。
     */
    public function destroy(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $user = $this->db->row('SELECT * FROM `user` WHERE `id` = ?', [$id]);
        if ($user === null) {
            $this->session()->flash('error', '用户不存在。');
            return Response::redirect($this->backTo($request, '/admin/users'));
        }

        $ticketCount = $this->db->int('SELECT COUNT(*) FROM `ticket` WHERE `user_id` = ?', [$id]);

        $this->db->transaction(function () use ($id): void {
            // 解除关联而不是删工单：工单是客服的工作记录，不该因为账号被删而消失
            $this->db->query('UPDATE `ticket` SET `user_id` = 0 WHERE `user_id` = ?', [$id]);
            $this->db->query('DELETE FROM `user` WHERE `id` = ?', [$id]);
        });

        \App\Core\Application::log(
            '[ADMIN] 删除前台用户 id=' . $id . ' username=' . (string)$user['username']
            . ' tickets_detached=' . $ticketCount . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        $this->session()->flash('ok', $ticketCount > 0
            ? '账号已删除，其名下 ' . $ticketCount . ' 个工单已转为访客工单并保留。'
            : '账号已删除。');
        return Response::redirect($this->backTo($request, '/admin/users'));
    }
}
