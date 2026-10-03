<?php

/**
 * HTTP 响应
 *
 * 控制器一律返回 Response 对象，由前端控制器统一发送。页面里不再出现
 * header() 与 exit()——这两样东西散落在业务代码里，是「改一处崩另一处」
 * 的主要来源。
 */
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $content = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * 跳转
     *
     * 只接受站内相对路径或同站绝对地址：把用户可控的 URL 直接丢进 Location
     * 会形成开放重定向（钓鱼跳板）。
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => self::safeRedirect($url)]);
    }

    public static function safeRedirect(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }
        // 相对路径（不以 / 或 scheme 开头）直接放行
        if (!str_starts_with($url, '/') && !str_starts_with($url, '\\')) {
            if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1 || str_starts_with($url, '//')) {
                return '/';
            }
            return '/' . $url;
        }
        // 协议相对地址 //evil.com 必须拦掉
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return '/';
        }
        // 含 CR/LF 的地址会被用于响应头注入
        return strpbrk($url, "\r\n") === false ? $url : '/';
    }

    public static function notFound(string $message = '页面不存在'): self
    {
        return self::html('<h1>404</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 404);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /** 覆盖已有响应体（用于把渲染好的布局套在外层） */
    public function withContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->content;
    }
}
