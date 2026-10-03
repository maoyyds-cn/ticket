<?php

/**
 * 路由表
 *
 * 全部地址集中在这里，因此「站点有哪些页面」是一眼可见的。
 * 旧版要看懂地址结构得把每个 .php 文件的头部读一遍，
 * 而页与页之间的参数约定（back、no、key、cat、q、sort…）没有任何地方声明。
 *
 * 约定：
 *  - 所有写操作都是 POST，并由 VerifyCsrf 统一校验令牌；
 *  - 后台路由带 RequireStaff（登录 + 角色合法性），
 *    再按需叠加 RequireCapability（具体能力）；
 *  - 后台登录页在 /admin 组之外，否则未登录就访问不到登录页。
 */
declare(strict_types=1);

use App\Core\Application;
use App\Core\App;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController as FrontAuthController;
use App\Http\Controllers\AccountController as FrontAccountController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\VerifyCsrf;

/** @var Application $app */
$app = App::container()->get(Application::class);
$router = $app->router();

/** 构造一个「要求某能力」的中间件。能力名作为配置传入，不走容器注入。 */
$cap = static fn(string $capability): RequireCapability => new RequireCapability(
    App::container()->get(\App\Domain\Auth\AuthService::class),
    App::container()->get(\App\Core\Session::class),
    $capability
);

// =====================================================================
// 前台
// =====================================================================
$router->get('/', [HomeController::class, 'index'], [VerifyCsrf::class], 'home');

// 知识库
$router->get('/knowledge', [KnowledgeController::class, 'index'], [VerifyCsrf::class], 'knowledge');
$router->post('/knowledge/vote', [KnowledgeController::class, 'vote'], [VerifyCsrf::class], 'knowledge.vote');

// 提交工单
$router->get('/submit', [TicketController::class, 'create'], [VerifyCsrf::class], 'ticket.create');
$router->post('/submit', [TicketController::class, 'store'], [VerifyCsrf::class], 'ticket.store');

// 我的工单（含访客按编号 + 密钥查询）
$router->get('/my-tickets', [TicketController::class, 'mine'], [VerifyCsrf::class], 'ticket.mine');
$router->post('/my-tickets', [TicketController::class, 'lookup'], [VerifyCsrf::class], 'ticket.lookup');

// 工单详情
$router->get('/ticket/{no}', [TicketController::class, 'show'], [VerifyCsrf::class], 'ticket.show');
$router->post('/ticket/{no}/reply', [TicketController::class, 'reply'], [VerifyCsrf::class], 'ticket.reply');
$router->post('/ticket/{no}/rate', [TicketController::class, 'rate'], [VerifyCsrf::class], 'ticket.rate');
$router->post('/ticket/{no}/close', [TicketController::class, 'close'], [VerifyCsrf::class], 'ticket.close');
$router->post('/ticket/{no}/reopen', [TicketController::class, 'reopen'], [VerifyCsrf::class], 'ticket.reopen');

// 前台账号
$router->get('/login', [FrontAuthController::class, 'showLogin'], [VerifyCsrf::class], 'login');
$router->post('/login', [FrontAuthController::class, 'login'], [VerifyCsrf::class], 'login.post');
$router->get('/register', [FrontAuthController::class, 'showRegister'], [VerifyCsrf::class], 'register');
$router->post('/register', [FrontAuthController::class, 'register'], [VerifyCsrf::class], 'register.post');
// 登出改为 POST：旧版是 GET，一张 <img src="/logout.php"> 就能把人踢下线
$router->post('/logout', [FrontAuthController::class, 'logout'], [VerifyCsrf::class], 'logout');

// 账号设置
$router->get('/account', [FrontAccountController::class, 'index'], [VerifyCsrf::class], 'account');
$router->post('/account/password', [FrontAccountController::class, 'changePassword'], [VerifyCsrf::class], 'account.password');
$router->post('/account/profile', [FrontAccountController::class, 'updateProfile'], [VerifyCsrf::class], 'account.profile');

// 邮箱验证码接口（注册 / 找回）
$router->post('/api/email-code', [FrontAuthController::class, 'sendCode'], [VerifyCsrf::class], 'api.email_code');

// =====================================================================
// 后台登录（必须在 RequireStaff 之外）
// =====================================================================
$router->get('/admin/login', [AuthController::class, 'showLogin'], [VerifyCsrf::class], 'admin.login');
$router->post('/admin/login', [AuthController::class, 'login'], [VerifyCsrf::class], 'admin.login.post');

// =====================================================================
// 后台
// =====================================================================
$router->group('/admin', [VerifyCsrf::class, RequireStaff::class], function ($r) use ($cap): void {

    // 登出：POST + CSRF
    $r->post('/logout', [AuthController::class, 'logout'], [], 'admin.logout');

    // 概览：所有后台角色都能看
    $r->get('', [DashboardController::class, 'index'], [], 'admin.home');
    $r->get('/', [DashboardController::class, 'index'], [], 'admin.home.slash');

    // 我的账号：任何后台角色都能改自己的密码
    $r->get('/account', [AccountController::class, 'index'], [], 'admin.account');
    $r->post('/account/password', [AccountController::class, 'changePassword'], [], 'admin.account.password');
    $r->post('/account/profile', [AccountController::class, 'updateProfile'], [], 'admin.account.profile');

    // ---------------- 工单 ----------------
    $r->get('/tickets', [AdminTicketController::class, 'index'], [$cap('ticket.view.any')], 'admin.tickets');
    $r->post('/tickets/bulk', [AdminTicketController::class, 'bulk'], [$cap('ticket.status')], 'admin.tickets.bulk');
    $r->get('/tickets/export', [AdminTicketController::class, 'export'], [$cap('ticket.export')], 'admin.tickets.export');
    $r->get('/tickets/{id}', [AdminTicketController::class, 'show'], [$cap('ticket.view.any')], 'admin.ticket');
    $r->post('/tickets/{id}/reply', [AdminTicketController::class, 'reply'], [$cap('ticket.reply')], 'admin.ticket.reply');
    $r->post('/tickets/{id}/attributes', [AdminTicketController::class, 'attributes'], [$cap('ticket.assign')], 'admin.ticket.attributes');
    $r->post('/tickets/{id}/status', [AdminTicketController::class, 'status'], [$cap('ticket.status')], 'admin.ticket.status');
    $r->post('/tickets/{id}/revoke-key', [AdminTicketController::class, 'revokeKey'], [$cap('ticket.internal_note')], 'admin.ticket.revoke_key');
    $r->post('/tickets/{id}/delete', [AdminTicketController::class, 'destroy'], [$cap('ticket.delete')], 'admin.ticket.delete');
    $r->post('/tickets/{id}/reply/{replyId}/delete', [AdminTicketController::class, 'destroyReply'], [$cap('ticket.delete')], 'admin.ticket.reply.delete');

    // ---------------- 知识库 ----------------
    $r->get('/faqs', [FaqController::class, 'index'], [$cap('faq.manage')], 'admin.faqs');
    $r->get('/faqs/new', [FaqController::class, 'create'], [$cap('faq.manage')], 'admin.faq.new');
    $r->post('/faqs/save', [FaqController::class, 'save'], [$cap('faq.manage')], 'admin.faq.save');
    $r->get('/faqs/{id}/edit', [FaqController::class, 'edit'], [$cap('faq.manage')], 'admin.faq.edit');
    $r->post('/faqs/bulk', [FaqController::class, 'bulk'], [$cap('faq.manage')], 'admin.faq.bulk');
    $r->post('/faqs/{id}/delete', [FaqController::class, 'destroy'], [$cap('faq.manage')], 'admin.faq.delete');

    // ---------------- 分类 ----------------
    $r->get('/categories', [CategoryController::class, 'index'], [$cap('category.manage')], 'admin.categories');
    $r->post('/categories/save', [CategoryController::class, 'save'], [$cap('category.manage')], 'admin.category.save');
    $r->post('/categories/{id}/toggle', [CategoryController::class, 'toggle'], [$cap('category.manage')], 'admin.category.toggle');
    $r->post('/categories/{id}/delete', [CategoryController::class, 'destroy'], [$cap('category.manage')], 'admin.category.delete');

    // ---------------- 成员（四种角色合并为一条路由） ----------------
    // 旧版为每个角色建了一个 6 行的文件（admins/supervisors/engineers/supers/
    // support.php），彼此只差一个角色常量，唯一的差别还在守卫上（supers 用
    // require_super，其余用 manage 判定）。合并后守卫只有一处。
    $r->get('/staff', [StaffController::class, 'index'], [$cap('staff.manage')], 'admin.staff');
    $r->post('/staff/save', [StaffController::class, 'save'], [$cap('staff.manage')], 'admin.staff.save');
    $r->post('/staff/{id}/toggle', [StaffController::class, 'toggle'], [$cap('staff.manage')], 'admin.staff.toggle');
    $r->post('/staff/{id}/password', [StaffController::class, 'resetPassword'], [$cap('staff.manage')], 'admin.staff.password');
    $r->post('/staff/{id}/delete', [StaffController::class, 'destroy'], [$cap('staff.manage')], 'admin.staff.delete');

    // ---------------- 前台用户 ----------------
    $r->get('/users', [UserController::class, 'index'], [$cap('user.manage')], 'admin.users');
    $r->post('/users/{id}/toggle', [UserController::class, 'toggle'], [$cap('user.manage')], 'admin.user.toggle');
    $r->post('/users/{id}/password', [UserController::class, 'resetPassword'], [$cap('user.manage')], 'admin.user.password');
    $r->post('/users/{id}/delete', [UserController::class, 'destroy'], [$cap('user.manage')], 'admin.user.delete');

    // ---------------- 邮件 ----------------
    $r->get('/mail', [MailController::class, 'index'], [$cap('mail.manage')], 'admin.mail');
    $r->post('/mail/save', [MailController::class, 'save'], [$cap('mail.manage')], 'admin.mail.save');
    $r->post('/mail/test', [MailController::class, 'test'], [$cap('mail.manage')], 'admin.mail.test');

    // ---------------- 操作日志 ----------------
    $r->get('/logs', [LogController::class, 'index'], [$cap('log.view')], 'admin.logs');
    $r->post('/logs/prune', [LogController::class, 'prune'], [$cap('log.view')], 'admin.logs.prune');

    // ---------------- 系统设置 ----------------
    $r->get('/settings', [SettingController::class, 'index'], [$cap('setting.manage')], 'admin.settings');
    $r->post('/settings/save', [SettingController::class, 'save'], [$cap('setting.manage')], 'admin.settings.save');
});
