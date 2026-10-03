<?php

/**
 * 字符串与格式化工具
 *
 * 全部方法都按 UTF-8 处理：中文站点里用 substr/mb_strlen 混用是
 * 「截断后出现半个字」这类乱码的常见来源，这里只暴露 mb_* 语义的方法。
 */
declare(strict_types=1);

namespace App\Support;

final class Str
{
    /** 安全截断，超长补省略号 */
    public static function limit(?string $s, int $chars, string $end = '…'): string
    {
        $s = (string)$s;
        if ($s === '' || mb_strlen($s, 'UTF-8') <= $chars) {
            return $s;
        }
        return mb_substr($s, 0, $chars, 'UTF-8') . $end;
    }

    /** 随机十六进制串（密码重置令牌、访客密钥等） */
    public static function randomHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** 便于人工抄写的工单号片段：去掉容易看混的 0/O/1/I */
    public static function readableCode(int $length = 6): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** 数字验证码（邮箱验证码用） */
    public static function numericCode(int $length = 6): string
    {
        $min = (int)str_pad('1', $length, '0');
        $max = (int)str_pad('', $length, '9');
        return (string)random_int($min, $max);
    }

    /**
     * 邮箱打码：a***z@example.com
     * 用于在页面上回显联系方式而不完整暴露。
     */
    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$user, $domain] = explode('@', $email, 2);
        $len = mb_strlen($user, 'UTF-8');
        if ($len <= 2) {
            return $user . '***@' . $domain;
        }
        return mb_substr($user, 0, 1, 'UTF-8') . '***' . mb_substr($user, -1, 1, 'UTF-8') . '@' . $domain;
    }

    /** 取邮箱用户名部分，用作访客默认昵称 */
    public static function emailName(string $email): string
    {
        return str_contains($email, '@') ? explode('@', $email, 2)[0] : $email;
    }

    /** 人类可读文件大小 */
    public static function filesize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /** 大数字缩写：12345 -> 1.2w */
    public static function compactNumber(int $n): string
    {
        if ($n >= 100000000) {
            return round($n / 100000000, 1) . '亿';
        }
        if ($n >= 10000) {
            return round($n / 10000, 1) . 'w';
        }
        return (string)$n;
    }

    /**
     * 相对时间：刚刚 / 5 分钟前 / 3 小时前 / 2 天前 / 具体日期
     */
    public static function timeAgo(?string $datetime): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '—';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            return '刚刚';
        }
        return match (true) {
            $diff < 60 => '刚刚',
            $diff < 3600 => intdiv($diff, 60) . ' 分钟前',
            $diff < 86400 => intdiv($diff, 3600) . ' 小时前',
            $diff < 2592000 => intdiv($diff, 86400) . ' 天前',
            default => date('Y-m-d', $ts),
        };
    }

    /** 统一的时间显示，空值显示破折号而不是 1970 */
    public static function datetime(?string $datetime, string $format = 'Y-m-d H:i'): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '—';
        }
        $ts = strtotime($datetime);
        return $ts === false ? '—' : date($format, $ts);
    }

    /** 生成 URL 友好的 slug（分类别名等），非 ASCII 一律转成连字符 */
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /**
     * 把纯文本转成可安全内嵌的 HTML 片段：
     * 先转义，再把裸 URL 变成链接，最后处理换行。
     *
     * 这是「客服回复 / 工单正文」的渲染路径，用户内容绝不能当 HTML 用。
     */
    public static function linkify(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace_callback(
            '#(https?://[^\s<>"\']+)#i',
            static function (array $m): string {
                $url = $m[1];
                // 结尾的标点多半不属于链接
                $trail = '';
                while ($url !== '' && str_contains('.,;:!?)]}，。；：！？）】', mb_substr($url, -1))) {
                    $trail = mb_substr($url, -1) . $trail;
                    $url = mb_substr($url, 0, -1);
                }
                return '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . $url . '</a>' . $trail;
            },
            $safe
        ) ?? $safe;
        return nl2br($safe, false);
    }

    /**
     * 高亮 Roblox 查询命令，便于客服一眼识别用户到底发了什么。
     * 输入必须是已转义文本。
     */
    public static function highlightCommands(string $escaped): string
    {
        $out = preg_replace_callback(
            '#(?<![\w/])(/?[a-zA-Z][\w]*(?:/[\w]+)*)(?:\s+([^\n<]{1,60}))?#u',
            static function (array $m): string {
                $cmd = $m[1];
                // 只有形如 /xxx 或 roblox/xxx 的才当命令，避免把普通英文单词包起来
                if (!str_starts_with($cmd, '/') && !str_starts_with($cmd, 'roblox/')) {
                    return $m[0];
                }
                $rest = isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : '';
                return '<code class="cmd">' . $cmd . '</code>' . $rest;
            },
            $escaped
        );
        return $out ?? $escaped;
    }
}
