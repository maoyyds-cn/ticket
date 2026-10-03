<?php

/**
 * 模板渲染
 *
 * 纯 PHP 模板：不发明新语法，模板里就是 <?= e($x) ?> 与 foreach。
 * 好处是编辑器、静态检查、调试器全都能正常工作，出错行号也准确。
 *
 * 渲染约定：
 *   1. $view->render('front/home', [...]) 会输出 templates/front/home.php；
 *   2. 模板可用 $layout 指定布局，布局里用 $content 取页面主体；
 *   3. 布局也可以完全不设，用于片段（fragment）渲染。
 */
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class View
{
    /** @var array<string,mixed> 全局共享数据（站点设置、当前登录身份等） */
    private array $shared = [];

    /** 已渲染的布局栈，用于嵌套 */
    private array $layoutStack = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function shareMany(array $data): void
    {
        foreach ($data as $k => $v) {
            $this->shared[$k] = $v;
        }
    }

    /** @return array<string,mixed> */
    public function shared(): array
    {
        return $this->shared;
    }

    /**
     * 渲染模板并套用布局。
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $content = $this->capture($template, $data);

        // 模板通过 $layout 声明布局；布局文件里用 $content 拿到主体
        $layout = $data['layout'] ?? null;
        if (is_string($layout) && $layout !== '') {
            return $this->capture($layout, array_merge($data, ['content' => $content]));
        }
        return $content;
    }

    /**
     * 渲染一个不带布局的片段（组件、局部模板）。
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $file = $this->resolve($template);

        // 变量在模板内以裸变量名可用（例如 e($title)），
        // 而不是每次都写 $data['title']。
        // 注意：本注释刻意不写出短标签原文——单行注释里出现 PHP 开标签
        // 会让分词器提前结束注释（见下方 capture() 的说明），
        // 一旦那样写，整个文件都会解析失败。
        $vars = array_merge($this->shared, $data);
        extract($vars, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (Throwable $e) {
            // 输出缓冲必须清掉，否则异常页会跟在半个页面后面
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /**
     * 解析模板路径，并阻止跳出模板目录。
     *
     * 模板名来自代码而非用户输入，但这里仍然校验一次：
     * 一旦将来有人把参数接到模板名上，越界读取会立刻变成异常而不是漏洞。
     */
    private function resolve(string $template): string
    {
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new RuntimeException('非法模板名：' . $template);
        }
        $file = rtrim($this->templateDir, '/\\') . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('模板不存在：' . $template . '（' . $file . '）');
        }
        return $file;
    }
}
