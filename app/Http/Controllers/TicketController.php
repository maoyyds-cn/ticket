<?php

/**
 * 前台工单控制器
 *
 * 覆盖：提交、列表、访客按编号查询、详情、回复、评分、关闭、重开。
 *
 * 与旧版相比修复的具体缺陷（都在这个文件对应的旧页面里）：
 *  1) 回复内容过短时旧版用 `return;` 直接退出脚本，页面变成空白
 *     （flash 也没机会渲染，要到下一次请求才看到）。这里统一走 PRG。
 *  2) 评分表单在「已关闭」工单上可见，但提交时被 canReply 拦掉，
 *     点了没反应也没有提示。这里评分独立判定，且失败一定有提示。
 *  3) 访客可以自填 author_name 冒充客服。这里回复人姓名一律由服务端决定。
 *  4) 恶意用户可以直接 POST 到前台的回复接口以管理员身份发帖。
 *     这里检测到后台身份时明确拒绝，引导其去后台处理。
 *  5) 旧版的 back 参数只拦 `..`，`//evil.com` 能通过，形成开放重定向。
 *     这里统一用 Response::redirect 的安全校验。
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketService;
use App\Domain\Ticket\TicketStatus;
use App\Support\Captcha;
use App\Support\Uploader;
use App\Support\Validator;

final class TicketController extends FrontController
{
    public function __construct(
        Container $container,
        KnowledgeBase $kb,
        private readonly TicketService $tickets,
        private readonly Settings $settings,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly Captcha $captcha,
        private readonly Uploader $uploader,
    ) {
        parent::__construct($container, $kb);
    }

    // ---------------------------------------------------------------
    // 提交工单
    // ---------------------------------------------------------------

    public function create(Request $request): Response
    {
        if (!$this->settings->bool('ticket_enabled', true)) {
            return $this->front('front/ticket-closed', [
                'pageTitle' => '工单通道已关闭',
                'notice' => $this->settings->string('ticket_closed_notice', '工单通道暂时关闭维护。'),
            ]);
        }

        $user = $this->auth->user();
        $categories = $this->kb->categories(true, false);

        return $this->front('front/submit', [
            'pageTitle' => '提交工单',
            'pageDesc' => '描述你遇到的问题，客服会尽快跟进。',
            'categories' => $categories,
            'user' => $user,
            'errors' => [],
            'captcha' => $this->captcha->issue(),
            'uploadMaxMb' => $this->settings->int('upload_max_mb', 10),
            'uploadMaxCount' => $this->settings->int('upload_max_count', 5),
            'ticketEnabled' => true,
            'formOpenedAt' => time(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->settings->bool('ticket_enabled', true)) {
            $this->session->flash('error', '工单通道当前已关闭。');
            return Response::redirect(url('/'));
        }

        $errors = [];

        // ---- 人机校验 ----
        // 三层，且每一层的失败都会给出具体原因：
        // 蜜罐（隐藏字段被填）→ 计时陷阱（提交间隔过短）→ 算术验证码
        $honeypot = $request->post('hp_website') . $request->post('hp_email');
        if (trim($honeypot) !== '') {
            // 触发蜜罐时不能告诉对方「你被识别为机器人」，那等于教它绕过；
            // 但也不能白屏，因此给一个通用提示。
            $this->session->flash('error', '提交未通过验证，请刷新页面后重试。');
            return Response::redirect(url('/submit'));
        }

        $openedAt = $request->postInt('form_opened_at');
        // 计时陷阱：只有在时间戳存在且明显不合理时才拒绝。
        // 旧版在标记缺失时直接跳过检查（fail-open），一旦会话里没有这个标记，
        // 计时层就完全失效；这里改为「缺失时用会话里的标记兜底」。
        if ($openedAt <= 0) {
            $openedAt = (int)$this->session->get('form_opened_at', 0);
        }
        if ($openedAt > 0 && (time() - $openedAt) < 3) {
            $errors['captcha'] = '提交过快，请确认你是真人后再提交一次。';
        }

        // 失败次数必须在**校验之前**判断并拦截。
        //
        // 之前的顺序是：读计数 → 校验 → 答对就清零 → 再拿「读到的旧值」比较上限。
        // 于是「连续答错 5 次，然后答对」会被拒绝，提示「连续失败次数过多」——
        // 用户明明算对了却说不对，必须再提交一次才能过（那时计数已被清零）。
        $fails = (int)$this->session->get('captcha_fails', 0);
        if ($fails >= $this->captcha->maxFails()) {
            $this->session->flash('error', '人机验证连续失败次数过多，请稍后再试。');
            return Response::redirect(url('/submit'));
        }
        if ($this->captcha->verify(
            $request->post('captcha'),
            $request->post('captcha_sig'),
            $request->postInt('captcha_a'),
            $request->postInt('captcha_b'),
            $request->post('captcha_op'),
            $request->postInt('captcha_ts')
        )) {
            $this->session->forget('captcha_fails');
        } else {
            $this->session->set('captcha_fails', $fails + 1);
            $errors['captcha'] = '人机验证答案不正确，请重新计算。';
        }

        // ---- 字段校验 ----
        $user = $this->auth->user();
        $rules = [
            'title' => 'required|length:4,200',
            'content' => 'required|length:10,20000',
        ];
        if ($user === null) {
            $rules['email'] = 'required|email|max:120';
        } else {
            $rules['email'] = 'email|max:120';
        }
        $rules['qq'] = 'qq';
        $rules['guest_name'] = 'max:50';

        $validator = Validator::make($request->body(), $rules, [
            'title' => '问题标题',
            'content' => '问题描述',
            'email' => '联系邮箱',
            'qq' => 'QQ 号',
            'guest_name' => '昵称',
        ]);
        foreach ($validator->errors() as $field => $message) {
            $errors[$field] = $message;
        }

        $categoryId = $request->postInt('category_id');
        if ($categoryId > 0) {
            $valid = false;
            foreach ($this->kb->categories(true, false) as $c) {
                if ((int)$c['id'] === $categoryId) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                $errors['category_id'] = '所选分类不可用，请重新选择。';
            }
        }

        if ($errors !== []) {
            $this->session->keepOld($request->body());
            foreach ($errors as $message) {
                $this->session->flash('error', $message);
            }
            return Response::redirect(url('/submit'));
        }

        // ---- 附件 ----
        $attachments = $this->uploader->save(
            $request->files('files'),
            $this->settings->int('upload_max_count', 5),
            $this->settings->int('upload_max_mb', 10)
        );

        // ---- 落库 ----
        $result = $this->tickets->create([
            'title' => (string)$validator->cleanValue('title'),
            'content' => (string)$validator->cleanValue('content'),
            'category_id' => $categoryId,
            'priority' => $request->post('priority'),
            'email' => (string)$validator->cleanValue('email'),
            'qq' => (string)$validator->cleanValue('qq'),
            'guest_name' => (string)$validator->cleanValue('guest_name'),
            'access_key' => $request->post('access_key'),
            'attachments' => $attachments,
        ], [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'source' => 'web',
        ]);

        if (!$result->ok) {
            // 落库失败时把已上传的文件删掉，避免留下没人引用的孤儿附件
            $this->uploader->delete($attachments);
            $this->session->keepOld($request->body());
            $this->session->flash('error', $result->error);
            return Response::redirect(url('/submit'));
        }

        foreach ($this->uploader->errors() as $uploadError) {
            $this->session->flash('warn', '附件提示：' . $uploadError);
        }

        $this->session->forget('form_opened_at');
        $this->session->clearOld();

        $no = $result->string('ticket_no');
        $key = $result->string('key');

        // 密钥在这一步就存进会话，因此跳转地址里不需要带它，
        // 用户也不会经历「先进带 key 的地址、再被重定向到干净地址」这一跳。
        // 密钥从此不出现在 URL、浏览器历史与访问日志里。
        $this->rememberKey($no, $key);

        $this->session->flash('ok', '工单 ' . $no . ' 已提交，我们会尽快处理。');

        return Response::redirect(url('/ticket/' . rawurlencode($no) . '?new=1'));
    }

    // ---------------------------------------------------------------
    // 我的工单 / 访客查询
    // ---------------------------------------------------------------

    public function mine(Request $request): Response
    {
        $user = $this->auth->user();
        $perPage = max(5, min(50, $this->settings->int('ticket_page_size', 15)));
        // 未登录：展示「按编号 + 密钥查询」表单
        if ($user === null) {
            return $this->front('front/my-tickets-guest', [
                'pageTitle' => '我的工单',
                'pageDesc' => '输入工单编号与访问密钥查看进度；登录后可查看全部工单。',
                'no' => $request->query('no'),
                'error' => '',
            ]);
        }

        $statusFilter = $request->query('status');
        $statuses = $statusFilter === '' ? [] : array_values(array_filter(
            explode(',', $statusFilter),
            [TicketStatus::class, 'exists']
        ));

        $page = max(1, $request->queryInt('page', 1));
        $filters = [
            'user_id' => $user->id,
            'status' => $statuses,
            'q' => $request->query('q'),
        ];

        $perPage = max(5, min(50, $perPage));
        $total = $this->tickets->repository()->count($filters);
        $totalPages = (int)max(1, (int)ceil($total / $perPage));
        // 越界页码收敛到最后一页，避免「?page=999」渲染出
        // 「你还没有提交过工单」这种与事实不符的空状态
        $page = min($page, $totalPages);

        $result = $this->tickets->paginate($filters, $page, $perPage);

        return $this->front('front/my-tickets', [
            'pageTitle' => '我的工单',
            'pageDesc' => '查看你提交过的全部工单与处理进度。',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $totalPages,
            'statusFilter' => $statusFilter,
            'statuses' => $statuses,
            'keyword' => $request->query('q'),
            'user' => $user,
            // 只统计这个用户自己的工单（不是全站）
            'counts' => $this->tickets->repository()->countByStatusForUser($user->id),
        ]);
    }

    /**
     * 访客按编号 + 密钥查询。
     *
     * 用 POST 提交，且校验通过后把密钥存进**会话**而不是 URL：
     * 旧版把密钥放在查询串里并在 8 个跳转之间反复传递，于是它会进入
     * 浏览器历史、服务器访问日志、以及任何被复制粘贴的链接里。
     * 会话保存之后，详情页地址就是干净的 /ticket/编号。
     */
    public function lookup(Request $request): Response
    {
        $no = $request->post('no');
        $key = trim($request->post('key'));

        $ticket = $this->tickets->findByNo($no);
        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            // 不区分「不存在」与「密钥错误」：否则可以据此枚举工单号
            return $this->front('front/my-tickets-guest', [
                'pageTitle' => '我的工单',
                'no' => $no,
                'error' => '工单不存在，或访问密钥不正确。请核对编号与密钥后重试。',
            ], 403);
        }

        $this->rememberKey((string)$ticket['ticket_no'], $key);
        return Response::redirect(url('/ticket/' . rawurlencode((string)$ticket['ticket_no'])));
    }

    // ---------------------------------------------------------------
    // 详情
    // ---------------------------------------------------------------

    /**
     * 取出当前请求可用于访问该工单的密钥。
     *
     * 来源优先级：本次 POST 提交的密钥 → 会话中记住的密钥 →
     * 首次跳转时带在查询串里的密钥（提交成功后唯一的入口）。
     */
    private function accessKeyFor(array $ticket, ?string $posted = null): string
    {
        if ($posted !== null && trim($posted) !== '') {
            return trim($posted);
        }
        $store = $this->session->get('ticket_keys', []);
        if (is_array($store)) {
            $remembered = (string)($store[(string)$ticket['ticket_no']] ?? '');
            if ($remembered !== '') {
                return $remembered;
            }
        }
        // 提交成功后的那一跳会把密钥带在查询串上；
        // show() 会立刻把它转存进会话并把地址换干净。
        return trim((string)($_GET['key'] ?? ''));
    }

    /** 把通过校验的密钥记进会话，供后续请求复用 */
    private function rememberKey(string $ticketNo, string $key): void
    {
        if (trim($key) === '') {
            return;
        }
        $store = $this->session->get('ticket_keys', []);
        if (!is_array($store)) {
            $store = [];
        }
        $store[$ticketNo] = trim($key);
        // 只保留最近访问的一批，避免会话无限膨胀
        if (count($store) > 12) {
            $store = array_slice($store, -12, null, true);
        }
        $this->session->set('ticket_keys', $store);
    }

    public function show(Request $request): Response
    {
        $no = (string)$request->attribute('no', '');
        $ticket = $this->tickets->findByNo($no);
        $key = $ticket !== null ? $this->accessKeyFor($ticket) : '';

        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            // 统一 404 且文案不透露任何信息：存在性本身也是隐私
            return $this->front('front/ticket-notfound', [
                'pageTitle' => '工单不存在',
                'no' => $no,
            ], 404);
        }

        // 密钥一旦通过校验就转存进会话，并立刻把地址里的密钥抹掉。
        // 这样密钥不会长期留在浏览器历史、访问日志与可复制粘贴的链接里。
        // （提交成功后的跳转已经不带密钥，这条分支只在用户直接粘贴
        //   带 key 的链接进来时命中，属于一次性的清理跳转。）
        if (trim((string)$request->query('key')) !== '') {
            $this->rememberKey((string)$ticket['ticket_no'], (string)$request->query('key'));
            $clean = '/ticket/' . rawurlencode((string)$ticket['ticket_no']);
            if ($request->queryBool('new')) {
                $clean .= '?new=1';
            }
            return Response::redirect(url($clean));
        }

        $staff = $this->auth->staff();
        $canSeeInternal = $staff !== null;
        $replies = $this->tickets->replies((int)$ticket['id'], $canSeeInternal);

        // 浏览量只统计真实访问：预取与爬虫会让这个数字失去意义
        if (!$this->passiveFetch($request)) {
            $this->tickets->incrementViews($ticket);
        }

        $status = (string)$ticket['status'];
        $isNew = $request->queryBool('new');

        return $this->front('front/ticket', [
            'pageTitle' => (string)$ticket['title'],
            'pageDesc' => '工单 ' . (string)$ticket['ticket_no'] . ' 的处理进度与往来记录。',
            'noIndex' => true,
            'ticket' => $ticket,
            'replies' => $replies,
            'attachments' => $this->tickets->attachmentsOf($ticket['attachments'] ?? null),
            'hasKey' => $key !== '',
            'isNew' => $isNew,
            'canReply' => TicketStatus::userActionable($status) && !$staff,
            // 评分必须和「能不能回复」用同一套状态判断。
            //
            // 之前只排除了垃圾工单，于是**刚提交、还没有客服回复过的工单**
            // 也会显示「问题解决了吗？」的评分卡。用户一点，工单就跳到「已解决」、
            // 写下 resolved_at 并带上评分——而实际上没有任何人处理过它。
            // 这既让工单提前离开待处理队列，也污染了解决率与响应时长统计。
            'canRate' => TicketStatus::userActionable($status)
                && $status !== TicketStatus::SPAM
                && (int)$ticket['rating'] === 0
                && !$staff,
            'canClose' => TicketStatus::userActionable($status) && $status !== TicketStatus::CLOSED && !$staff,
            'canReopen' => in_array($status, [TicketStatus::CLOSED, TicketStatus::RESOLVED], true) && !$staff,
            'staff' => $staff,
            'captcha' => $this->captcha->issue(),
            'uploadMaxMb' => $this->settings->int('upload_max_mb', 10),
            'uploadMaxCount' => $this->settings->int('upload_max_count', 5),
            'formOpenedAt' => time(),
            'logs' => $staff !== null ? $this->tickets->logs((int)$ticket['id'], 12) : [],
        ]);
    }

    /**
     * 是否应跳过 GET 上的副作用（标记已读、累加浏览量）。
     *
     * 预取与爬虫请求不该改变数据：导航预取会在鼠标悬停时抓取目标页，
     * 若无条件累加，「鼠标划过」就等于「打开过」。
     */
    private function passiveFetch(Request $request): bool
    {
        return $request->isPassiveFetch();
    }

    /**
     * 追加回复。
     *
     * 访客不需要验证码（避免正常跟进修问题时被打断），改用轻量频控：
     * 同一会话对同一工单 20 秒内只能提交一次。
     * 关键点：频控的时间戳只在**校验通过之后**才写入。
     * 旧版在调用方校验内容之前就写了，导致一次空提交也会把 20 秒窗口烧掉。
     */
    public function reply(Request $request): Response
    {
        $no = (string)$request->attribute('no', '');
        $ticket = $this->tickets->findByNo($no);

        $key = $ticket !== null ? $this->accessKeyFor($ticket, $request->post('key')) : '';
        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            return $this->front('front/ticket-notfound', ['pageTitle' => '工单不存在', 'no' => $no], 404);
        }

        // 地址里不再带密钥：密钥已在会话中，回跳用干净地址即可
        $returnUrl = url('/ticket/' . rawurlencode((string)$ticket['ticket_no']));

        // 后台人员不应该从前台回复：那会以「用户」身份写入，
        // 之后再在后台看到自己的发言，身份与统计都会乱掉。
        if ($this->auth->isStaff()) {
            $this->session->flash('warn', '你正在以工作人员身份访问，请到后台工单页进行回复。');
            return Response::redirect($returnUrl);
        }

        // 频控
        $rateKey = 'reply_at_' . (int)$ticket['id'];
        $last = (int)$this->session->get($rateKey, 0);
        if ($last > 0 && time() - $last < 20) {
            $this->session->flash('warn', '刚刚已经提交过一次，请等待 ' . (20 - (time() - $last)) . ' 秒后再发送。');
            return Response::redirect($returnUrl);
        }

        if (trim($request->post('hp_website')) !== '') {
            $this->session->flash('error', '提交未通过验证，请刷新页面后重试。');
            return Response::redirect($returnUrl);
        }

        $content = $request->post('content');
        if (mb_strlen(trim($content), 'UTF-8') < 2) {
            $this->session->flash('error', '回复内容不能为空。');
            return Response::redirect($returnUrl);
        }

        // 校验通过，才记录频控时间戳
        $this->session->set($rateKey, time());

        $attachments = $this->uploader->save(
            $request->files('files'),
            $this->settings->int('upload_max_count', 5),
            $this->settings->int('upload_max_mb', 10)
        );

        $result = $this->tickets->reply($ticket, $content, $attachments);
        if (!$result->ok) {
            $this->uploader->delete($attachments);
            $this->session->flash('error', $result->error);
            return Response::redirect($returnUrl);
        }

        foreach ($this->uploader->errors() as $uploadError) {
            $this->session->flash('warn', '附件提示：' . $uploadError);
        }
        $this->session->flash('ok', '回复已发送。');
        return Response::redirect($returnUrl);
    }

    /**
     * 评分并结束工单。
     */
    public function rate(Request $request): Response
    {
        $no = (string)$request->attribute('no', '');
        $ticket = $this->tickets->findByNo($no);
        $key = $ticket !== null ? $this->accessKeyFor($ticket, $request->post('key')) : '';

        $fallback = url('/ticket/' . rawurlencode($no));
        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            return $this->front('front/ticket-notfound', ['pageTitle' => '工单不存在', 'no' => $no], 404);
        }
        if ($this->auth->isStaff()) {
            $this->session->flash('warn', '工作人员账号不能对工单评分。');
            return Response::redirect($fallback);
        }

        $rating = $request->postInt('rating');
        if ($rating < 1 || $rating > 5) {
            $this->session->flash('error', '请先选择 1–5 星的评价。');
            return Response::redirect($fallback);
        }

        // 同一工单只允许评价一次，避免反复提交刷分
        if ((int)$ticket['rating'] > 0) {
            $this->session->flash('warn', '该工单已经评价过了，感谢反馈。');
            return Response::redirect($fallback);
        }

        $result = $this->tickets->rateAndResolve($ticket, $rating, $request->post('rating_note'));
        $this->session->flash($result->ok ? 'ok' : 'error', $result->ok ? '感谢你的评价，工单已标记为已解决。' : $result->error);
        return Response::redirect($fallback);
    }

    public function close(Request $request): Response
    {
        $no = (string)$request->attribute('no', '');
        $ticket = $this->tickets->findByNo($no);
        $key = $ticket !== null ? $this->accessKeyFor($ticket, $request->post('key')) : '';
        $fallback = url('/ticket/' . rawurlencode($no));

        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            return $this->front('front/ticket-notfound', ['pageTitle' => '工单不存在', 'no' => $no], 404);
        }
        if ($this->auth->isStaff()) {
            $this->session->flash('warn', '请到后台工单页变更状态。');
            return Response::redirect($fallback);
        }

        $result = $this->tickets->userClose($ticket);
        $this->session->flash($result->ok ? 'ok' : 'warn', $result->ok ? '工单已关闭。' : $result->error);
        return Response::redirect($fallback);
    }

    public function reopen(Request $request): Response
    {
        $no = (string)$request->attribute('no', '');
        $ticket = $this->tickets->findByNo($no);
        $key = $ticket !== null ? $this->accessKeyFor($ticket, $request->post('key')) : '';
        $fallback = url('/ticket/' . rawurlencode($no));

        if ($ticket === null || !$this->tickets->canAccess($ticket, $key)) {
            return $this->front('front/ticket-notfound', ['pageTitle' => '工单不存在', 'no' => $no], 404);
        }
        if ($this->auth->isStaff()) {
            $this->session->flash('warn', '请到后台工单页变更状态。');
            return Response::redirect($fallback);
        }

        $result = $this->tickets->userReopen($ticket);
        $this->session->flash($result->ok ? 'ok' : 'warn', $result->ok ? '工单已重新打开，客服会继续跟进。' : $result->error);
        return Response::redirect($fallback);
    }
}
