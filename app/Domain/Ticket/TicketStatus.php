<?php

/**
 * 工单状态、优先级与状态机
 *
 * 状态流转是工单系统的核心规则，v1 把它散在 ticket-view.php 与 admin/
 * ticket-view.php 的 if/else 里，前台与后台各写一份，两边的允许转移
 * 并不完全一致。这里把它收成一个显式的状态机：
 *   谁能改、能改成什么、改完要不要通知用户，都在这一处声明。
 */
declare(strict_types=1);

namespace App\Domain\Ticket;

final class TicketStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const REPLIED = 'replied';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
    public const SPAM = 'spam';

    /**
     * 状态元数据。
     * tone 对应设计系统里的语义色（CSS 变量 --tone-*），不直接写死色值
     * ——v1 把颜色字符串写进 PHP 数组，主题一改就要改代码。
     */
    private const META = [
        self::PENDING => ['label' => '待处理', 'tone' => 'amber', 'icon' => 'clock'],
        self::PROCESSING => ['label' => '处理中', 'tone' => 'blue', 'icon' => 'spinner'],
        self::REPLIED => ['label' => '已回复', 'tone' => 'violet', 'icon' => 'chat'],
        self::RESOLVED => ['label' => '已解决', 'tone' => 'green', 'icon' => 'check'],
        self::CLOSED => ['label' => '已关闭', 'tone' => 'slate', 'icon' => 'lock'],
        self::SPAM => ['label' => '垃圾工单', 'tone' => 'red', 'icon' => 'ban'],
    ];

    /**
     * 允许的状态转移（后台侧）。
     *
     * 注意：这里只表达「状态之间的合法性」，不表达「谁有权改」——
     * 权限归 Role，两者分开判断，避免像 v1 那样把权限和流转条件
     * 混在一个 if 里，最后谁也说不清某条分支到底在拦什么。
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING => [self::PROCESSING, self::REPLIED, self::RESOLVED, self::SPAM, self::CLOSED],
        self::PROCESSING => [self::REPLIED, self::RESOLVED, self::PENDING, self::SPAM, self::CLOSED],
        self::REPLIED => [self::PROCESSING, self::RESOLVED, self::PENDING, self::SPAM, self::CLOSED],
        self::RESOLVED => [self::REPLIED, self::PROCESSING, self::CLOSED, self::SPAM],
        self::CLOSED => [self::REPLIED, self::PROCESSING, self::RESOLVED],
        self::SPAM => [self::PENDING],
    ];

    /** 这些状态表示「客服已经介入过」，用户可以据此评价或关闭 */
    private const USER_ACTIONABLE = [self::REPLIED, self::RESOLVED, self::CLOSED];

    /** 计入「待处理」的状态 */
    private const OPEN = [self::PENDING, self::PROCESSING, self::REPLIED];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function exists(string $status): bool
    {
        return isset(self::META[$status]);
    }

    public static function label(string $status): string
    {
        return self::META[$status]['label'] ?? $status;
    }

    public static function tone(string $status): string
    {
        return self::META[$status]['tone'] ?? 'slate';
    }

    public static function icon(string $status): string
    {
        return self::META[$status]['icon'] ?? 'dot';
    }

    /** @return array<string,string> 状态 => 中文名，用于下拉框与筛选 */
    public static function options(): array
    {
        $out = [];
        foreach (self::META as $k => $m) {
            $out[$k] = $m['label'];
        }
        return $out;
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false; // 相同状态不算转移，避免产生无意义的日志与通知
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> 从当前状态可达的状态 */
    public static function nextStates(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    /** 用户能否对这个状态的工单执行「关闭 / 评价 / 追加」 */
    public static function userActionable(string $status): bool
    {
        return in_array($status, self::USER_ACTIONABLE, true);
    }

    /** 统计时用于「未完结」的口径 */
    public static function openStates(): array
    {
        return self::OPEN;
    }
}
