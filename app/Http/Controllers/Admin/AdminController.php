<?php

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;

/**
 * 后台控制器基类
 *
 * 提供后台布局渲染与「当前操作者」的取用。
 * 权限本身不在这里判断——那是中间件的职责，页面里不再出现权限分支。
 * 旧版把权限判断写在每个文件第 4 行，与业务逻辑混在一起，
 * 于是「这个页面到底谁能进」需要读代码才能确认。
 */
abstract class AdminController extends Controller
{
    public function __construct(
        Container $container,
        protected readonly AuthService $auth,
        protected readonly Settings $settings,
    ) {
        parent::__construct($container);
    }

    /**
     * 渲染后台页面。
     *
     * @param array<string,mixed> $data
     */
    protected function admin(string $template, array $data = [], int $status = 200): Response
    {
        $data['layout'] = $data['layout'] ?? 'layouts/admin';
        $data['activeNav'] = $data['activeNav'] ?? '';

        return Response::html($this->view()->render($template, $data), $status);
    }

    /** 当前登录的后台账号；中间件已保证它存在 */
    protected function staff(): \App\Domain\Auth\Identity
    {
        $staff = $this->auth->staff();
        if ($staff === null) {
            // 走到这里说明中间件被绕过，属于编程错误而不是用户输入问题
            throw new \RuntimeException('后台控制器要求已登录的后台身份');
        }
        return $staff;
    }

    /** 会话快捷方式（后台页面几乎每处操作都要写提示） */
    protected function session(): \App\Core\Session
    {
        return $this->container->get(\App\Core\Session::class);
    }

    /**
     * 回跳地址只接受站内路径。
     *
     * DELETE/批量操作后回到原筛选条件是常见需求，但直接把用户可控的
     * URL 用于跳转就是开放重定向。
     */
    protected function backTo(Request $request, string $fallback): string
    {
        $back = $request->post('back');
        if ($back === '') {
            $back = $request->query('back');
        }
        $back = trim($back);
        if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//') || str_contains($back, '\\')) {
            return url($fallback);
        }
        return url($back);
    }
}
