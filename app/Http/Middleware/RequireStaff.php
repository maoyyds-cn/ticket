<?php

/**
 * 后台访问控制
 *
 * 旧版的问题不在「忘了调用 require_admin()」——11 处调用点都在——
 * 而在于 require_admin() 只检查 status=1，**从不校验角色的合法性**。
 * 于是一个角色值不在枚举内的账号（例如历史上被 ALTER 改坏、或手工写库
 * 留下的空角色）依然能拿到全部工单、FAQ 与分类权限，而侧栏却因为
 * 查不到角色对应的页面而整个隐藏——一个看不见菜单却有全部权限的账号。
 *
 * 这里在进入后台的第一道门就把「登录状态」「账号启用」「角色必须存在」
 * 三件事一起查完，后面所有权限判断都建立在「角色一定合法」的前提上。
 */
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Staff\Role;

final class RequireStaff implements Middleware
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $staff = $this->auth->staff();

        if ($staff === null) {
            if ($this->session->get('staff_timeout')) {
                $this->session->forget('staff_timeout');
                $this->session->flash('warn', '登录已超时（30 分钟无操作），请重新登录。');
            } else {
                $this->session->flash('error', '请先登录后台。');
            }
            // 登录后跳回原页面：只接受站内路径，Response::safeRedirect 会再做一次校验
            $back = $request->fullPath();
            return Response::redirect(url('/admin/login') . '?back=' . rawurlencode($back));
        }

        // 角色必须存在于角色表中，否则拒绝进入后台。
        // 这样历史上被改坏的角色值不会继承任何权限。
        if (!Role::exists($staff->role)) {
            $this->auth->staffLogout();
            $this->session->flash('error', '该账号的角色配置异常，已自动退出，请联系超级管理员检查账号设置。');
            \App\Core\Application::log(
                '[SECURITY] 角色非法，拒绝后台访问：staff_id=' . $staff->id . ' role=' . var_export($staff->role, true),
                'security.log'
            );
            return Response::redirect(url('/admin/login'));
        }

        // 把身份同步进视图，后台布局需要它渲染侧栏与顶栏
        \App\Core\App::container()->get(\App\Core\ViewContext::class)->shareIdentity($staff);

        return $next($request);
    }
}
