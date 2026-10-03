<?php

/**
 * 前台控制器基类
 *
 * 只放前台页面共用的东西：渲染时自动套前台布局、注入页脚分类。
 * 旧版每个页面都要自己 require 一次 header 与 footer，
 * 忘掉其中一次就会输出一个没有导航也没有 </body> 的残缺页面。
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Controller;
use App\Core\Response;
use App\Domain\Knowledge\KnowledgeBase;

abstract class FrontController extends Controller
{
    public function __construct(Container $container, protected readonly KnowledgeBase $kb)
    {
        parent::__construct($container);
    }

    /**
     * 渲染前台页面。
     *
     * @param array<string,mixed> $data
     */
    protected function front(string $template, array $data = [], int $status = 200): Response
    {
        $data['layout'] = $data['layout'] ?? 'layouts/front';

        // 页脚热门分类：在这里统一提供，避免页脚自己查库（旧版的做法），
        // 也避免个别页面忘记提供而出现空列。
        if (!array_key_exists('footCategories', $data)) {
            $data['footCategories'] = array_slice($this->kb->categories(false, true), 0, 6);
        }

        return Response::html($this->view()->render($template, $data), $status);
    }
}
