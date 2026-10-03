<?php

/**
 * 工单优先级
 */
declare(strict_types=1);

namespace App\Domain\Ticket;

final class TicketPriority
{
    public const LOW = 'low';
    public const NORMAL = 'normal';
    public const HIGH = 'high';
    public const URGENT = 'urgent';

    private const META = [
        self::LOW => ['label' => '低', 'tone' => 'slate', 'weight' => 1],
        self::NORMAL => ['label' => '普通', 'tone' => 'blue', 'weight' => 2],
        self::HIGH => ['label' => '高', 'tone' => 'amber', 'weight' => 3],
        self::URGENT => ['label' => '紧急', 'tone' => 'red', 'weight' => 4],
    ];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function exists(string $p): bool
    {
        return isset(self::META[$p]);
    }

    public static function label(string $p): string
    {
        return self::META[$p]['label'] ?? $p;
    }

    public static function tone(string $p): string
    {
        return self::META[$p]['tone'] ?? 'slate';
    }

    /** 用于 SQL 排序：紧急的排前面 */
    public static function weight(string $p): int
    {
        return self::META[$p]['weight'] ?? 2;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::META as $k => $m) {
            $out[$k] = $m['label'];
        }
        return $out;
    }
}
