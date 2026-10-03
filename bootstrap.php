<?php

/**
 * 应用引导
 *
 * 只做装配，不含业务逻辑：注册自动加载、读配置、建容器、注册服务、
 * 注册路由。这个文件是唯一允许「知道全局」的地方。
 */
declare(strict_types=1);

use App\Core\Application;
use App\Core\App;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Core\ViewContext;
use App\Core\Database;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\EmailCode;
use App\Domain\Auth\Throttle;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Mail\Mailer;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketRepository;
use App\Domain\Ticket\TicketService;
use App\Support\Captcha;
use App\Support\Tokens;
use App\Support\Uploader;

if (defined('TK_APP_BOOTED')) {
    return App::container()->get(Application::class);
}
define('TK_APP_BOOTED', true);

// bootstrap.php 就在项目根目录下，因此项目根就是它自己所在的目录。
// 这里刻意不做 dirname()：先前写成 dirname(__DIR__) 会让根目录上跳一层，
// 于是所有 require 都指向了父目录，整站直接无法启动。
$appRoot = __DIR__;

// ---- 自动加载 ----
require $appRoot . '/app/Core/Autoloader.php';
$loader = new Autoloader();
$loader->addNamespace('App', $appRoot . '/app');
$loader->register();

require $appRoot . '/app/Support/helpers.php';
require $appRoot . '/app/Support/icons.php';

// ---- 配置 ----
Config::load($appRoot . '/config');

// 时区必须在任何时间函数之前设定：服务器系统时区是 UTC，
// 而站点面向中文用户；不设的话所有时间显示都会偏 8 小时。
date_default_timezone_set(Config::string('app.timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');

// 日志目录先于错误处理器写进配置，因为错误处理器自己要读它
Config::set('app.log_dir', $appRoot . '/storage/logs');
Config::set('app.upload_dir', $appRoot . '/storage/uploads');
Config::set('app.public_dir', $appRoot . '/public');
Config::set('app.root', $appRoot);

// ---- 容器 ----
// 只通过 Container::fresh() 创建：直接 new 会得到一个不共享任何服务的空容器，
// 因此 Container 的构造函数会主动拦截这种写法。
$container = Container::fresh();
App::setContainer($container);

// 必须把容器自身也注册为实例。
//
// 这不是可选项：Controller 基类的构造函数签名是 (Container $container)，
// 若不注册，自动解析会尝试构造一个新的 Container；配合上面的拦截，
// 会立刻抛出明确的错误而不是悄悄换成一个空容器。
$container->instance(Container::class, $container);

$container->set(Session::class, static fn(): Session => new Session());
$container->set(Database::class, static fn(): Database => new Database((array)Config::get('db', [])));
$container->set(View::class, static fn(): View => new View($appRoot . '/templates'));
$container->set(Settings::class, static fn(Container $c): Settings => new Settings($c->get(Database::class)));
$container->set(Throttle::class, static fn(Container $c): Throttle => new Throttle($c->get(Database::class)));
$container->set(AuthService::class, static fn(Container $c): AuthService => new AuthService(
    $c->get(Database::class),
    $c->get(Session::class),
    $c->get(Throttle::class),
));
$container->set(EmailCode::class, static fn(Container $c): EmailCode => new EmailCode(
    $c->get(Database::class),
    $c->get(Throttle::class),
));
$container->set(Mailer::class, static fn(Container $c): Mailer => new Mailer(
    $c->get(Settings::class),
    $c->get(Database::class),
    $c->get(View::class),
));
$container->set(TicketRepository::class, static fn(Container $c): TicketRepository => new TicketRepository(
    $c->get(Database::class)
));
$container->set(TicketService::class, static fn(Container $c): TicketService => new TicketService(
    $c->get(Database::class),
    $c->get(TicketRepository::class),
    $c->get(Settings::class),
    $c->get(AuthService::class),
    $c->get(Throttle::class),
    $c->get(Mailer::class),
    new Uploader((string)Config::string('app.upload_dir')),
));
$container->set(KnowledgeBase::class, static fn(Container $c): KnowledgeBase => new KnowledgeBase(
    $c->get(Database::class),
    $c->get(Session::class),
));
$container->set(Captcha::class, static fn(): Captcha => new Captcha(Config::string('app.key', 'ticket-app')));
$container->set(Uploader::class, static fn(): Uploader => new Uploader((string)Config::string('app.upload_dir')));
$container->set(Tokens::class, static fn(Container $c): Tokens => Tokens::fromConfig($c->get(Session::class)));
$container->set(ViewContext::class, static fn(Container $c): ViewContext => new ViewContext(
    $c->get(View::class),
    $c->get(Settings::class),
    $c->get(Session::class),
    Config::string('app.version', '2.0.0'),
));

// ---- 应用 ----
$app = new Application($container, $appRoot, (string)Config::string('app.log_dir'));
$container->instance(Application::class, $app);
$app->registerErrorHandling();

// ---- 会话 ----
// 只做「配置」，不启动。
//
// 启动被推迟到真正需要读写会话时（见 Core\Session）。这样匿名访客浏览
// 公开页面时不会产生会话、不会下发 Set-Cookie，响应因此可以被边缘缓存——
// 对一个源站在境外、用户在国内的部署来说，这是切页速度最关键的一步。
// 会话名带 app_key 派生片段：同一个域名下跑多个实例时不会互相顶掉登录态。
$session = $container->get(Session::class);
$session->configure(
    'TKSESS' . substr(md5(Config::string('app.key', 'ticket-app')), 0, 10),
    Config::bool('app.session_secure', false)
);

// 身份解析会读会话；这里只解析一次，后续所有判断复用。
$identity = $container->get(AuthService::class)->identity();

// ---- 视图共享数据 ----
// 这两步读取 settings 表，因此**首次安装时表还不存在**，会抛 SQL 异常。
// 这里容忍「表还没建」这一种情况（安装器随后会显式重新调用 boot()），
// 其它数据库异常照常上抛——把「配置写错了」也一起吞掉，
// 只会让问题推迟到更难定位的地方爆发。
try {
    $container->get(ViewContext::class)->shareIdentity($identity);
    $container->get(ViewContext::class)->share();
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), '1146')) {
        throw $e;
    }
    // 1146 = 表不存在：安装尚未完成，跳过即可
}

// 工单展示需要的三个小工具，注入视图而不是在模板里 new 服务：
// 旧版的模板直接调用全局函数并现场解析 JSON 附件，同一个解析逻辑
// 在工单正文和每条回复里各写了一遍。收成闭包后模板只负责摆放。
$ticketService = $container->get(TicketService::class);
$container->get(View::class)->shareMany([
    'ticketsSplit' => static fn(?string $json): array => $ticketService->splitAttachments(
        $ticketService->attachmentsOf($json)
    ),
    'ticketsAuthor' => static fn(array $reply): array => $ticketService->replyAuthor($reply),
    'ticketsAttachments' => static fn(?string $json): array => $ticketService->attachmentsOf($json),
]);

/**
 * 完成与「已有数据」相关的最后装配：路由表与邮件队列钩子。
 *
 * 为什么拆成函数：安装器需要在建表之后重新走一遍这一步
 * （建表前 settings 表还不存在，上面的共享数据是跳过的）。
 * 普通请求则在文件末尾自动调用一次，行为与拆开之前完全一致。
 */
$boot = static function () use ($container, $appRoot): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // 路由必须在会话启动、视图上下文就绪之后再加载：
    // routes/web.php 里的中间件需要读会话与访问控制信息。
    require $appRoot . '/routes/web.php';

    // 邮件在响应输出之后统一发送：用户不必对着加载动画等 SMTP 往返。
    // 注意 shutdown 回调是后注册先执行，因此这里放在最后注册，
    // 会最先运行——此时响应尚未输出，flush 内部会自己先结束响应。
    register_shutdown_function(static function () use ($container): void {
        try {
            $mailer = $container->get(Mailer::class);
            if (!$mailer->hasQueued()) {
                return;
            }
            // 让 PHP 把已生成的响应交给浏览器后再发信
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (ob_get_level() > 0) {
                @ob_end_flush();
                @flush();
            }
            $mailer->flush();
        } catch (Throwable $e) {
            Application::log('[MAIL] 队列发送异常：' . $e->getMessage(), 'mail.log');
        }
    });
};

// 命令行安装器（bin/setup.php）会自行决定何时调用 $boot()，
// 因为它在建表之前不能加载依赖 settings 的路由与视图数据。
if (PHP_SAPI !== 'cli') {
    $boot();
}

// 把装配函数放进容器，供 bin/setup.php 在建表之后显式调用
$container->instance('app.boot', new class($boot) {
    /** @param \Closure():void $boot */
    public function __construct(private readonly \Closure $boot)
    {
    }

    public function run(): void
    {
        ($this->boot)();
    }
});

return $app;
