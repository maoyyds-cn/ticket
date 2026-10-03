<?php

/**
 * 后台登录 / 登出
 *
 * 与旧版的差异：
 *  - 旧版只把提示文案改了，并没有真正限制尝试次数；这里锁定在校验密码
 *    **之前**判断，且失败计数落在数据库里（跨会话、跨浏览器都有效）；
 *  - 旧版的「递增锁定时长」是死代码（在加锁的同一分支里把计数清零，
 *    于是延时恒为固定值），这里用 AuthService + Throttle 明确实现；
 *  - 旧版的 back 参数判断写成 str_starts_with($back, 'admin/')，
 *    而实际传入的是 /admin/... （带前导斜杠），条件永远不成立，
 *    于是「登录后回到原页面」这个功能从来没有生效过。这里修正。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;
use App\Support\Validator;

final class AuthController extends Controller
{
    public function __construct(
        Container $container,
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly Settings $settings,
    ) {
        parent::__construct($container);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->isStaff()) {
            return Response::redirect(url('/admin'));
        }

        return $this->render('admin/login', [
            'layout' => 'layouts/blank',
            'pageTitle' => '后台登录',
            'noIndex' => true,
            'back' => $this->safeBack($request->query('back')),
            'siteName' => $this->settings->string('site_name', '工单系统'),
        ]);
    }

    public function login(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->raw('password');
        $back = $this->safeBack($request->post('back'));

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => 'required', 'password' => 'required'],
            ['username' => '账号', 'password' => '密码']
        );
        if ($validator->fails()) {
            $this->session->flash('error', $validator->firstError());
            $this->session->keepOld(['username' => $username]);
            return Response::redirect(url('/admin/login'));
        }

        $result = $this->auth->loginStaff($username, $password, $request->ip(), $request->userAgent());
        if (!($result['ok'] ?? false)) {
            $this->session->flash('error', $result['error'] ?? '登录失败');
            $this->session->keepOld(['username' => $username]);
            return Response::redirect(url('/admin/login'));
        }

        $this->session->clearOld();
        $this->session->flash('ok', '登录成功。');
        return Response::redirect(url($back));
    }

    public function logout(): Response
    {
        $this->auth->staffLogout();
        $this->session->flash('ok', '已退出后台。');
        return Response::redirect(url('/admin/login'));
    }

    /**
     * 只接受后台站内路径。
     * 旧版这里既拦不住 //evil.com（开放重定向），又永远匹配不上真实传入的
     * /admin/... 路径，等于形同虚设。
     */
    private function safeBack(string $back): string
    {
        $back = trim($back);
        if ($back === '' || !str_starts_with($back, '/admin') || str_starts_with($back, '//') || str_contains($back, '\\')) {
            return '/admin';
        }
        // 不允许跳回登录页本身
        if (str_starts_with($back, '/admin/login')) {
            return '/admin';
        }
        return $back;
    }
}
