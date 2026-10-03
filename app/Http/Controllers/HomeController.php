<?php

/**
 * 首页
 *
 * 旧版首页自己写了 6 条聚合 SQL（其中还有一条在分类循环里逐条 COUNT，
 * 是典型的 N+1），并且和 includes/ticket.php 里已有的 ticket_stats()
 * 各算一遍统计口径。这里统一走 TicketService::overviewStats()，
 * 保证首页数字与后台概览完全一致。
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Domain\Setting\Settings;
use App\Domain\Ticket\TicketService;

final class HomeController extends FrontController
{
    public function __construct(
        \App\Core\Container $container,
        \App\Domain\Knowledge\KnowledgeBase $kb,
        private readonly TicketService $tickets,
        private readonly Settings $settings,
    ) {
        parent::__construct($container, $kb);
    }

    public function index(): Response
    {
        $stats = $this->tickets->overviewStats();
        $categories = $this->kb->categories(false, true);
        $counts = $this->kb->countsByCategory();

        // 首页只展示启用了知识库用途且有内容的分类
        $visibleCategories = array_values(array_filter(
            $categories,
            static fn(array $c): bool => (int)$c['is_faq'] === 1
        ));

        // 平均首响：没有数据时为 null，页面据此隐藏这一项，
        // 而不是像旧版那样直接写死一行「服务运行中」的静态文案
        $avgFirst = $stats['avg_first_response'];

        return $this->front('front/home', [
            'pageTitle' => '',
            'pageDesc' => $this->settings->string('site_desc', ''),
            'stats' => $stats,
            'categories' => $visibleCategories,
            'categoryCounts' => $counts,
            'hotFaqs' => $this->kb->hot(8),
            'newFaqs' => $this->kb->latest(6),
            'faqCount' => $this->kb->countPublished(),
            'announce' => trim($this->settings->string('home_announce', '')),
            'avgFirstText' => $avgFirst === null
                ? ''
                : ($avgFirst < 60 ? $avgFirst . ' 分钟' : round($avgFirst / 60, 1) . ' 小时'),
            'activeNav' => '/',
        ]);
    }
}
