<?php

/**
 * 前台账号设置
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Ticket\TicketService;
use App\Support\Validator;

final class AccountController extends FrontController
{
    public function __construct(
        Container $container,
        KnowledgeBase $kb,
        private readonly AuthService $auth,
        private readonly TicketService $tickets,
        private readonly Database $db,
        private readonly Session $session,
    ) {
        parent::__construct($container, $kb);
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(url('/login?back=' . rawurlencode('/account')));
        }

        return $this->front('front/account', [
            'pageTitle' => '账号设置',
            'pageDesc' => '修改联系方式与登录密码。',
            'noIndex' => true,
            'user' => $user,
            'ticketCount' => $this->db->int(
                'SELECT COUNT(*) FROM `ticket` WHERE `user_id` = ?',
                [$user->id]
            ),
            'openCount' => $this->db->int(
                'SELECT COUNT(*) FROM `ticket` WHERE `user_id` = ? AND `status` IN (?, ?, ?)',
                [$user->id, ...\App\Domain\Ticket\TicketStatus::openStates()]
            ),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(url('/login'));
        }

        $current = $request->raw('current_password');
        $new = $request->raw('new_password');
        $confirm = $request->raw('new_password2');

        if ($new !== $confirm) {
            $this->session->flash('error', '两次输入的新密码不一致。');
            return Response::redirect(url('/account'));
        }

        $validator = Validator::make(['new_password' => $new], ['new_password' => 'required|min:8|max:72'], [
            'new_password' => '新密码',
        ]);
        if ($validator->fails()) {
            $this->session->flash('error', $validator->firstError());
            return Response::redirect(url('/account'));
        }

        $result = $this->auth->changeUserPassword($user->id, $current, $new);
        if (!$result['ok']) {
            $this->session->flash('error', $result['error'] ?? '修改失败');
            return Response::redirect(url('/account'));
        }

        $this->session->flash('ok', '密码已更新。');
        return Response::redirect(url('/account'));
    }

    /**
     * 更新联系方式与昵称。
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(url('/login'));
        }

        $validator = Validator::make($request->body(), [
            'email' => 'email|max:120',
            'qq' => 'qq',
            'realname' => 'max:50',
        ], [
            'email' => '邮箱',
            'qq' => 'QQ 号',
            'realname' => '昵称',
        ]);
        if ($validator->fails()) {
            $this->session->flash('error', $validator->firstError());
            return Response::redirect(url('/account'));
        }

        $email = (string)$validator->cleanValue('email');
        // 邮箱是找回工单的唯一凭据，必须保持唯一，否则两个账号会互相看到对方的工单
        if ($email !== '' && $email !== (string)$user->email) {
            $taken = $this->db->int('SELECT COUNT(*) FROM `user` WHERE `email` = ? AND `id` <> ?', [$email, $user->id]);
            if ($taken > 0) {
                $this->session->flash('error', '该邮箱已被其他账号使用。');
                return Response::redirect(url('/account'));
            }
        }

        $this->db->query(
            'UPDATE `user` SET `email` = ?, `qq` = ?, `realname` = ? WHERE `id` = ?',
            [
                $email,
                (string)$validator->cleanValue('qq'),
                (string)$validator->cleanValue('realname') ?: (string)$user->username,
                $user->id,
            ]
        );

        // 身份是请求级缓存的，资料改了要刷新，否则页面仍显示旧昵称
        $this->auth->refresh();
        $this->session->flash('ok', '资料已保存。');
        return Response::redirect(url('/account'));
    }
}
