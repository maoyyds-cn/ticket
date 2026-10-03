<?php

/**
 * 分类管理
 *
 * 这个页面正是旧版存储型 CSS 注入的来源：颜色字段没有任何校验，
 * 原样入库后又被直接拼进 style="background:..."，于是任意一个后台角色
 * 都能存一段 CSS 影响到超管面板和整个前台。现在颜色在服务层就被
 * 白名单化（只接受 #rgb / #rrggbb），非法值回退到品牌色。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Knowledge\KnowledgeBase;
use App\Domain\Setting\Settings;

final class CategoryController extends AdminController
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
        $editId = $request->queryInt('edit');
        $editing = $editId > 0 ? $this->kb->findCategory($editId) : null;

        return $this->admin('admin/categories', [
            'pageTitle' => '分类管理',
            'pageDesc' => '分类同时用于工单与知识库，可分别控制用途',
            'activeNav' => 'categories',
            'categories' => $this->kb->categories(false, false, false),
            'usage' => $this->kb->categoryUsage(),
            'editing' => $editing,
        ]);
    }

    public function save(Request $request): Response
    {
        $id = $request->postInt('id');
        $result = $this->kb->saveCategory([
            'name' => $request->post('name'),
            'slug' => $request->post('slug'),
            'icon' => $request->post('icon'),
            'color' => $request->post('color'),
            'description' => $request->post('description'),
            'sort' => $request->postInt('sort'),
            'is_ticket' => $request->postBool('is_ticket'),
            'is_faq' => $request->postBool('is_faq'),
            'status' => $request->postBool('status'),
        ], $id);

        if (!$result['ok']) {
            $this->session()->flash('error', $result['error'] ?? '保存失败');
            return Response::redirect($this->backTo($request, '/admin/categories'));
        }

        \App\Core\Application::log(
            '[ADMIN] 分类' . ($id > 0 ? '更新' : '新增') . ' id=' . ($result['id'] ?? 0)
            . ' by staff_id=' . $this->staff()->id,
            'security.log'
        );

        $this->session()->flash('ok', $id > 0 ? '分类已更新。' : '分类已创建。');
        return Response::redirect(url('/admin/categories'));
    }

    public function toggle(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $category = $this->kb->findCategory($id);
        if ($category === null) {
            $this->session()->flash('error', '分类不存在。');
            return Response::redirect(url('/admin/categories'));
        }

        $next = (int)$category['status'] === 1 ? 0 : 1;
        $result = $this->kb->saveCategory([
            'name' => $category['name'],
            'slug' => $category['slug'],
            'icon' => $category['icon'],
            'color' => $category['color'],
            'description' => $category['description'],
            'sort' => $category['sort'],
            'is_ticket' => (int)$category['is_ticket'] === 1,
            'is_faq' => (int)$category['is_faq'] === 1,
            'status' => $next === 1,
        ], $id);

        // 旧版无论是否真的改成功都提示成功
        $this->session()->flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? '分类已' . ($next === 1 ? '启用' : '停用') . '。'
            : ($result['error'] ?? '操作失败'));
        return Response::redirect($this->backTo($request, '/admin/categories'));
    }

    public function destroy(Request $request): Response
    {
        $id = (int)$request->attribute('id', 0);
        $result = $this->kb->deleteCategory($id);
        $this->session()->flash($result['ok'] ? 'ok' : 'error', $result['ok']
            ? '分类已删除。'
            : ($result['error'] ?? '删除失败'));
        return Response::redirect($this->backTo($request, '/admin/categories'));
    }
}
