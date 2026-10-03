<?php

/**
 * 知识库管理
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Setting\Settings;
use App\Support\Validator;

final class FaqController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly KnowledgeBase $kb,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        $statusRaw = $request->query('status');
        $filters = [
            'category_id' => $request->queryInt('cat'),
            'q' => $request->query('q'),
            'sort' => $request->query('sort'),
        ];
        // 空字符串表示「全部」，因此只在明确传值时按状态过滤
        if ($statusRaw === '0' || $statusRaw === '1') {
            $filters['status'] = (int)$statusRaw;
        } else {
            $filters['status'] = -1; // 管理端默认看全部（含草稿）
        }

        $perPage = 20;
        $page = max(1, $request->queryInt('page', 1));

        // 管理端要看全部状态，这里手动放宽 status 条件
        $result = $this->kb->search($this->adminFilters($filters), $page, $perPage);

        return $this->admin('admin/faqs', [
            'pageTitle' => '知识库管理',
            'pageDesc' => '共 ' . $result['total'] . ' 条内容',
            'activeNav' => 'faqs',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => (int)max(1, (int)ceil($result['total'] / $perPage)),
            'filters' => $filters,
            'categories' => $this->kb->categories(false, true, false),
            'publishedCount' => $this->kb->countPublished(),
            'statusRaw' => (string)$statusRaw,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request);
    }

    public function edit(Request $request): Response
    {
        $faq = $this->kb->find((int)$request->attribute('id', 0));
        if ($faq === null) {
            $this->session()->flash('error', '该条目不存在或已被删除。');
            return Response::redirect(url('/admin/faqs'));
        }
        return $this->form($faq, $request);
    }

    /**
     * 新增 / 编辑共用一个视图。
     * 旧版是 list + 独立编辑页，并且列表里还有一批「切换热门」「切换置顶」
     * 的 POST 分支，而界面上没有任何按钮会发出这两个动作（死分支）。
     */
    private function form(?array $faq, Request $request): Response
    {
        return $this->admin('admin/faq-edit', [
            'pageTitle' => $faq === null ? '新建知识库条目' : '编辑知识库条目',
            'activeNav' => 'faqs',
            'faq' => $faq,
            'categories' => $this->kb->categories(false, true, false),
            'errors' => [],
            'formOpenedAt' => time(),
        ]);
    }

    public function save(Request $request): Response
    {
        $id = $request->postInt('id');
        $isUpdate = $id > 0;

        $validator = Validator::make($request->body(), [
            'question' => 'required|length:2,255',
            'answer' => 'required|length:2,60000',
            'keywords' => 'max:500',
        ], [
            'question' => '问题',
            'answer' => '解答',
            'keywords' => '关键词',
        ]);
        $errors = [];
        if ($validator->fails()) {
            $errors[] = $validator->firstError();
        }

        // 编辑时若目标已不存在（在另一个标签页被删掉），
        // 之前的实现会照常提示「已保存修改」——而实际上 0 行被更新。
        if ($isUpdate && $this->kb->find($id) === null) {
            $errors[] = '该条目已被删除，无法保存。请返回列表重新创建。';
        }

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->session()->flash('error', $message);
            }
            // 保留已填内容，避免用户重新输入一篇长解答
            $this->session()->keepOld($request->body());
            return Response::redirect(url($isUpdate ? '/admin/faqs/' . $id . '/edit' : '/admin/faqs/new'));
        }

        // 服务端必须自己卡长度：旧版只靠表单 maxlength，
        // 构造一个超长请求就会撞上 VARCHAR(255) 报 1406 而显示 500 错误页
        $result = $this->kb->saveFaq([
            'question' => (string)$validator->cleanValue('question'),
            'answer' => (string)$validator->cleanValue('answer'),
            'keywords' => (string)$validator->cleanValue('keywords'),
            'category_id' => $request->postInt('category_id'),
            'sort' => $request->postInt('sort'),
            'is_hot' => $request->postBool('is_hot'),
            'is_top' => $request->postBool('is_top'),
            'status' => $request->postBool('status'),
        ], $id);

        if (!$result['ok']) {
            $this->session()->flash('error', $result['error'] ?? '保存失败');
            return Response::redirect(url($isUpdate ? '/admin/faqs/' . $id . '/edit' : '/admin/faqs/new'));
        }

        \App\Core\Application::log(
            '[ADMIN] 知识库' . ($isUpdate ? '更新' : '新增') . ' id=' . ($result['id'] ?? 0)
            . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );

        $this->session()->flash('ok', $isUpdate ? '已保存修改。' : '已创建新条目。');
        return Response::redirect(url('/admin/faqs'));
    }

    /**
     * 批量操作。
     */
    public function bulk(Request $request): Response
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)($request->body()['ids'] ?? [])),
            static fn(int $i): bool => $i > 0
        )));
        if ($ids === []) {
            $this->session()->flash('warn', '请先勾选要操作的内容。');
            return Response::redirect($this->backTo($request, '/admin/faqs'));
        }
        if (count($ids) > 200) {
            $this->session()->flash('warn', '一次最多操作 200 条。');
            return Response::redirect($this->backTo($request, '/admin/faqs'));
        }

        $action = $request->post('act');
        $affected = match ($action) {
            'publish' => $this->kb->setFaqStatus($ids, 1),
            'draft' => $this->kb->setFaqStatus($ids, 0),
            'delete' => $this->kb->deleteFaqs($ids),
            default => -1,
        };

        if ($affected === -1) {
            $this->session()->flash('error', '未知的批量操作。');
            return Response::redirect($this->backTo($request, '/admin/faqs'));
        }

        $label = match ($action) {
            'publish' => '发布',
            'draft' => '转为草稿',
            default => '删除',
        };
        $this->session()->flash($affected > 0 ? 'ok' : 'warn', $affected > 0
            ? '已' . $label . ' ' . $affected . ' 条内容。'
            : '没有内容被修改。');
        return Response::redirect($this->backTo($request, '/admin/faqs'));
    }

    public function destroy(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $faq = $this->kb->find($id);
        if ($faq === null) {
            $this->session()->flash('error', '该条目不存在或已被删除。');
            return Response::redirect(url('/admin/faqs'));
        }

        $this->kb->deleteFaq($id);
        \App\Core\Application::log(
            '[ADMIN] 删除知识库条目 id=' . $id . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );
        $this->session()->flash('ok', '已删除该条目。');
        return Response::redirect($this->backTo($request, '/admin/faqs'));
    }

    /**
     * 管理端筛选：把 status = -1 解释成「全部状态」。
     *
     * KnowledgeBase::search() 默认只查已发布（前台语义）。
     * 管理端需要看到草稿，这里把「全部」翻译成一个检索层能理解的表达：
     * 不传 status 键时按已发布处理，因此用一个不可能的状态值让它返回空，
     * 再由下面替换成真实条件的方式不成立——所以直接走专用查询。
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function adminFilters(array $filters): array
    {
        if (($filters['status'] ?? 1) === -1) {
            // 用一个显式标记让检索层走「全部状态」分支
            $filters['status'] = null;
        }
        return $filters;
    }
}
