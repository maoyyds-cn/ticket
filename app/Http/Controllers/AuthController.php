<?php

/**
 * 前台登录 / 注册 / 登出 / 邮箱验证码
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\EmailCode;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Mail\Mailer;
use App\Domain\Setting\Settings;
use App\Support\Validator;

final class AuthController extends FrontController
{
    /** 连续失败多少次后开始锁定 */
    private const LOGIN_MAX_FAILS = 5;
    /** 锁定时长（秒）。旧版的「递增时长」是死代码，这里就用一个明确的固定值 */
    private const LOGIN_LOCK_SECONDS = 300;

    public function __construct(
        Container $container,
        KnowledgeBase $kb,
        private readonly AuthService $auth,
        private readonly EmailCode $emailCode,
        private readonly Mailer $mailer,
        private readonly Settings $settings,
        private readonly Session $session,
    ) {
        parent::__construct($container, $kb);
    }

    // ---------------------------------------------------------------
    // 登录
    // ---------------------------------------------------------------

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect(url($this->safeBack($request->query('back'), '/my-tickets')));
        }
        return $this->front('front/login', [
            'pageTitle' => '登录',
            'back' => $this->safeBack($request->query('back'), ''),
            'errors' => [],
        ]);
    }

    public function login(Request $request): Response
    {
        $username = $request->post('username');
        $password = $request->raw('password');
        $back = $this->safeBack($request->post('back'), '/my-tickets');

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => 'required', 'password' => 'required'],
            ['username' => '账号', 'password' => '密码']
        );
        if ($validator->fails()) {
            $this->session->flash('error', $validator->firstError());
            $this->session->keepOld(['username' => $username, 'back' => $back]);
            return Response::redirect(url('/login?back=' . rawurlencode($back)));
        }

        // 失败计数与锁定完全由 AuthService 依据数据库判定。
        //
        // 这里刻意不再碰 $_SESSION：会话是客户端可以随手丢弃的东西，
        // 拿它当安全计数器等于「清个 Cookie 就重置」，那样的防护是假的。
        $result = $this->auth->loginUser($username, $password, $request->ip());
        if (!$result['ok']) {
            $this->session->flash('error', $result['error'] ?? '登录失败');
            $this->session->keepOld(['username' => $username, 'back' => $back]);
            return Response::redirect(url('/login?back=' . rawurlencode($back)));
        }

        $this->session->clearOld();
        $this->session->flash('ok', '登录成功，欢迎回来。');
        return Response::redirect(url($back));
    }

    // ---------------------------------------------------------------
    // 注册
    // ---------------------------------------------------------------

    public function showRegister(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect(url('/my-tickets'));
        }
        if (!$this->settings->bool('reg_enabled', true)) {
            $this->session->flash('warn', '注册通道当前已关闭。');
            return Response::redirect(url('/login'));
        }
        return $this->front('front/register', [
            'pageTitle' => '注册',
            'back' => $this->safeBack($request->query('back'), ''),
            'errors' => [],
            'requireEmailCode' => $this->settings->bool('reg_require_email_code', false),
            'mailReady' => $this->mailer->isConfigured(),
        ]);
    }

    public function register(Request $request): Response
    {
        if (!$this->settings->bool('reg_enabled', true)) {
            $this->session->flash('warn', '注册通道当前已关闭。');
            return Response::redirect(url('/login'));
        }

        $back = $this->safeBack($request->post('back'), '/my-tickets');
        $requireCode = $this->settings->bool('reg_require_email_code', false);

        // 蜜罐
        if (trim($request->post('hp_website') . $request->post('hp_email')) !== '') {
            $this->session->flash('error', '提交未通过验证，请刷新页面后重试。');
            return Response::redirect(url('/register'));
        }

        $rules = [
            'username' => 'required|username',
            'email' => 'required|email|max:120',
            'qq' => 'qq',
            'password' => 'required|password:8|max:72',
        ];
        $validator = Validator::make($request->body(), $rules, [
            'username' => '用户名',
            'email' => '邮箱',
            'qq' => 'QQ 号',
            'password' => '密码',
        ]);
        $errors = $validator->errors();

        $password = $request->raw('password');
        if ($password !== $request->raw('password2')) {
            $errors['password2'] = '两次输入的密码不一致';
        }

        // 邮箱验证码：开启时必须校验；关闭时跳过。
        // 旧版的注册页渲染了 Turnstile 组件却从不调用校验接口，
        // 也就是说那个「人机验证」纯属装饰。这里要么真校验，要么不显示。
        if ($requireCode && !isset($errors['email'])) {
            $verify = $this->emailCode->verify(
                (string)$validator->cleanValue('email'),
                EmailCode::SCENE_REGISTER,
                $request->post('email_code')
            );
            if (!$verify['ok']) {
                $errors['email_code'] = $verify['error'] ?? '验证码不正确';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->session->flash('error', $message);
            }
            $this->session->keepOld($request->body());
            return Response::redirect(url('/register'));
        }

        $result = $this->auth->registerUser(
            (string)$validator->cleanValue('username'),
            $password,
            (string)$validator->cleanValue('email'),
            (string)$validator->cleanValue('qq')
        );
        if (!$result['ok']) {
            $this->session->flash('error', $result['error'] ?? '注册失败');
            $this->session->keepOld($request->body());
            return Response::redirect(url('/register'));
        }

        // 注册成功直接登录，省掉一次重复输入
        $login = $this->auth->loginUser((string)$validator->cleanValue('username'), $password, $request->ip());
        $this->session->clearOld();

        if ($login['ok']) {
            $this->session->flash('ok', '注册成功，欢迎使用。');
            return Response::redirect(url($back));
        }

        $this->session->flash('ok', '注册成功，请登录。');
        return Response::redirect(url('/login'));
    }

    // ---------------------------------------------------------------
    // 登出
    // ---------------------------------------------------------------

    public function logout(): Response
    {
        $this->auth->userLogout();
        $this->session->flash('ok', '已退出登录。');
        return Response::redirect(url('/'));
    }

    // ---------------------------------------------------------------
    // 邮箱验证码接口
    // ---------------------------------------------------------------

    public function sendCode(Request $request): Response
    {
        // 蜜罐
        if (trim($request->post('hp_website')) !== '') {
            return Response::json(['ok' => false, 'message' => '请求未通过验证'], 400);
        }

        $email = mb_strtolower($request->post('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::json(['ok' => false, 'message' => '请先填写正确的邮箱地址'], 422);
        }

        if (!$this->mailer->isConfigured()) {
            // 明确告知原因，而不是像旧版那样抛一个被截断的异常信息出去
            return Response::json([
                'ok' => false,
                'message' => '站点尚未配置邮件通道，暂时无法发送验证码。请联系管理员，或关闭「注册需要邮箱验证码」后注册。',
            ], 503);
        }

        $issued = $this->emailCode->issue($email, EmailCode::SCENE_REGISTER, $request->ip());
        if (!($issued['ok'] ?? false)) {
            return Response::json(['ok' => false, 'message' => $issued['error'] ?? '发送失败'], 429);
        }

        $sent = $this->mailer->sendVerificationCode($email, (string)$issued['code'], '注册');
        if (!$sent) {
            return Response::json(['ok' => false, 'message' => '验证码发送失败，请稍后重试或联系管理员。'], 502);
        }

        return Response::json([
            'ok' => true,
            'message' => '验证码已发送到 ' . $email . '，10 分钟内有效。',
            'cooldown' => 60,
        ]);
    }

    /**
     * 校验登录后的回跳地址。
     *
     * 旧版的判断是「以 / 开头且不含 ..」，于是 `//evil.com` 能通过，
     * 形成开放重定向；同时它又要求路径不以 / 开头才保留，
     * 导致应用内部传入的路径（都带 /）一律被丢弃，回跳功能实际是坏的。
     * 这里：只接受以单个 / 开头的站内路径。
     */
    private function safeBack(string $back, string $fallback): string
    {
        $back = trim($back);
        if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//') || str_contains($back, '\\')) {
            return $fallback;
        }
        // 不允许回跳到登录/注册页自身，避免来回打转
        foreach (['/login', '/register', '/logout'] as $skip) {
            if (str_starts_with($back, $skip)) {
                return $fallback;
            }
        }
        return $back;
    }
}
