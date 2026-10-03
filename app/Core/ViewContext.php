<?php

/**
 * 视图共享数据
 *
 * 布局里到处都要用站点名称、导航高亮、当前登录身份。若每个控制器都自己
 * share 一遍，迟早会有页面漏掉其中一项，表现就是导航高亮丢失或登录按钮
 * 状态错乱。这里集中解析一次，控制器只管业务数据。
 */
declare(strict_types=1);

namespace App\Core;

use App\Domain\Auth\Identity;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketPriority;
use App\Domain\Ticket\TicketStatus;

final class ViewContext
{
    public function __construct(
        private readonly View $view,
        private readonly Settings $settings,
        private readonly Session $session,
        private readonly string $version = '2.0.0',
    ) {
        // 身份键先给一份安全默认值（未登录），再由 shareIdentity() 覆盖。
        // 这样即使某个入口忘了调用 shareIdentity()，模板读到的也是
        // 「未登录」而不是未定义变量——失败方向是安全的。
        //
        // 注意这里必须写 $this->view：构造器属性提升只会创建属性，
        // 不会创建同名局部变量；写成 $view 会在运行时得到 null。
        $this->view->shareMany([
            'currentUser' => null,
            'currentStaff' => null,
            'isStaff' => false,
            'isSuper' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function share(array $extra = []): void
    {
        $data = [
            'siteName' => $this->settings->string('site_name', 'Roblox 查询机器人 · 帮助中心'),
            'siteDesc' => $this->settings->string('site_desc', ''),
            'siteKeywords' => $this->settings->string('site_keywords', ''),
            'siteIcp' => $this->settings->string('site_icp', ''),
            'appVersion' => $this->version,
            'currentPath' => (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),

            // 表单令牌与提示：布局会用到，页面不必再关心
            // 表单令牌：来自无状态签名，不需要会话，因此渲染匿名页面
            // 不会让访客变成「有状态」从而失去边缘缓存资格
            'csrfToken' => \App\Core\App::container()->get(\App\Support\Tokens::class)->issue(),
            'flashes' => $this->session->flashes(),

            // 身份相关的键（currentUser / currentStaff / isStaff / isSuper）
            // 刻意不在这里写默认值：它们由 shareIdentity() 负责设置。
            // 如果这里也写一份 null，调用顺序稍有变化就会把已解析的身份覆盖掉。

            // 页脚分类：默认空数组，控制器按需覆盖。
            // 旧版在页脚里直接查库，于是每个页面渲染都要多一次数据库往返。
            'footCategories' => [],

            'ticketEnabled' => $this->settings->bool('ticket_enabled', true),

            // 状态/优先级字典：徽章渲染在各页反复出现，统一放进来
            'statusOptions' => TicketStatus::options(),
            'priorityOptions' => TicketPriority::options(),
        ];

        $this->view->shareMany(array_merge($data, $extra));
    }

    /**
     * 把登录身份同步进视图。
     *
     * 单独一个方法是因为它依赖 AuthService，而 AuthService 解析身份时会读
     * 会话与数据库；把它放在 share() 里会让每次渲染都触发一次查询，
     * 哪怕这个页面根本不显示身份。
     */
    public function shareIdentity(?Identity $identity): void
    {
        $this->view->shareMany([
            'currentUser' => $identity !== null && $identity->isUser() ? $identity : null,
            'currentStaff' => $identity !== null && $identity->isStaff() ? $identity : null,
            'isStaff' => $identity !== null && $identity->isStaff(),
            'isSuper' => $identity !== null && $identity->isSuper(),
        ]);
    }
}
