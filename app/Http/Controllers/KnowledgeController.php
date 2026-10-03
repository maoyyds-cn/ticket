<?php

/**
 * 知识库
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Setting\Settings;

final class KnowledgeController extends FrontController
{
    public function __construct(
        Container $container,
        KnowledgeBase $kb,
        private readonly Settings $settings,
        private readonly Session $session,
    ) {
        parent::__construct($container, $kb);
    }

    public function index(Request $request): Response
    {
        $keyword = $request->query('q');
        $categoryId = $request->queryInt('cat');
        $sort = $request->query('sort');
        if (!in_array($sort, ['default', 'hot', 'new', 'helpful'], true)) {
            $sort = 'default';
        }

        $perPage = max(4, min(50, $this->settings->int('faq_page_size', 12)));
        $page = max(1, $request->queryInt('page', 1));

        // 先问一次总数，把页码收敛到有效范围，**再**取数据。
        //
        // 顺序反了会出一个很难解释的现象：?page=999 时先取回空列表，
        // 然后才把页码改成最后一页并交给分页器——于是页面上
        // 「没有找到相关内容」和「第 1 / 1 页」同时出现，数据与提示互相矛盾。
        // 只查一次计数的代价可以忽略，换来的是列表与页码始终自洽。
        $filters = ['category_id' => $categoryId, 'q' => $keyword, 'sort' => $sort, 'status' => 1];
        $probe = $this->kb->search($filters, 1, 1);
        $totalPages = (int)max(1, (int)ceil($probe['total'] / $perPage));
        $page = min($page, $totalPages);

        $result = $this->kb->search($filters, $page, $perPage);

        $categories = $this->kb->categories(false, true);
        $counts = $this->kb->countsByCategory();
        $currentCat = $categoryId > 0 ? $this->kb->findCategory($categoryId) : null;

        // 已投票记录：让页面能显示「你已评价」而不是永远显示可投票按钮。
        // 放进独立的 $votedById，模板循环时不能覆盖它（否则只有第一条正确）。
        $votedById = [];
        foreach ($result['items'] as $item) {
            $votedById[(int)$item['id']] = $this->kb->hasVoted((int)$item['id']);
        }

        // 记录浏览量（按 Cookie 去重，同一浏览器 30 分钟内只计一次）。
        // 预取与爬虫不计数——否则鼠标划过列表就等于逐条打开过。
        if (!$request->isPassiveFetch()) {
            foreach ($result['items'] as $item) {
                $this->kb->recordView((int)$item['id']);
            }
        }

        return $this->front('front/knowledge', [
            'pageTitle' => $currentCat !== null ? (string)$currentCat['name'] : '知识库',
            'pageDesc' => '共 ' . $this->kb->countPublished() . ' 条常见问题，覆盖注册、查询、积分、风控等场景。',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'keyword' => $keyword,
            'categoryId' => $categoryId,
            'currentCat' => $currentCat,
            'sort' => $sort,
            'categories' => $categories,
            'categoryCounts' => $counts,
            'votedById' => $votedById,
            'faqCount' => $this->kb->countPublished(),
        ]);
    }

    /**
     * 有用 / 没用 投票。
     *
     * 说明这个接口为什么曾经完全不可用：旧版从 $_GET['id'] 取条目 ID，
     * 而唯一的调用方把它放在 POST body 里，于是 $id 恒为 0，投票
     * 每次都静默跳转、什么都不记录，页面上数字永远不变。
     * 现在从 POST 取值，并把失败原因明确告诉用户。
     */
    public function vote(Request $request): Response
    {
        $faqId = $request->postInt('id');
        $helpful = $request->post('v') === '1';

        $result = $this->kb->vote($faqId, $helpful);
        $this->session->flash($result['ok'] ? 'ok' : 'warn', $result['message']);

        // 只接受站内路径作为回跳目标，否则一律回到知识库首页
        $back = $request->post('back');
        $target = ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//'))
            ? $back
            : '/knowledge';

        return Response::redirect(url($target));
    }
}
