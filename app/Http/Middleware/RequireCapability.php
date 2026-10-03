<?php

/**
 * 能力校验中间件
 *
 * 权限判断只有一处实现：Role::can()。页面不再出现
 * 「if ($role === 'admin' || $role === 'super')」这类散落各处的硬编码，
 * 也不再出现旧版那种 require_role('supervisor') 会把 admin/super 一起
 * 放行的等级比较（等级与人事任免是两条正交的线）。
 *
 * 关键性质：未声明的能力名一律拒绝。旧版的 require_role($unknown) 会算出
 * 等级 0，从而对所有人放行——一个「权限门」写错名字就变成敞开的大门。
 */
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;

final class RequireCapability implements Middleware
{
    /**
     * @param string $capability 需要的能力名。给默认值是为了让容器在
     *        没有显式传参时也能构造（构造出的实例会拒绝一切，
     *        因为空能力名在 Role::can() 里查不到，属于安全的方向）。
     */
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly string $capability = '',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // 能力名优先取构造函数传入的值；也支持由路由参数临时指定
        $capability = $this->capability !== ''
            ? $this->capability
            : (string)$request->attribute('_capability', '');
        $staff = $this->auth->staff();

        if ($staff === null || !$staff->can($capability)) {
            $this->session->flash('error', '你没有执行该操作的权限。');
            \App\Core\Application::log(
                '[SECURITY] 权限不足：staff_id=' . ($staff?->id ?? 0)
                . ' role=' . ($staff?->role ?? '-')
                . ' capability=' . $capability
                . ' path=' . $request->path(),
                'security.log'
            );

            // 后台页面请求跳回概览；接口请求返回 403 JSON，避免把 HTML 塞进 fetch
            if ($request->server('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest') {
                return Response::json(['ok' => false, 'message' => '权限不足'], 403);
            }
            return Response::redirect(url('/admin'));
        }

        return $next($request);
    }
}
