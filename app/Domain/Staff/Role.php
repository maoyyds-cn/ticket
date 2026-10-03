<?php

/**
 * 后台角色与权限
 *
 * v1 的五种角色里，engineer（工程师）与 operator（客服）的权限**完全等价**
 * ——权限表里两者都是「可处理任意工单」，代码里没有任何一处区分它们。
 * 多一个角色分支意味着多一套人事页面、多一个下拉选项、多一条永远走不到
 * 的判断，因此这里精简为四种。
 *
 * 权限判定统一走 capabilities()：一个角色能做什么，只在这一处声明。
 * 页面与接口都问 can($role, 'x')，不允许再出现「if role === 'admin' || ...」
 * 这类散落各处的硬编码判断——那是 v1 权限错漏的根源。
 */
declare(strict_types=1);

namespace App\Domain\Staff;

final class Role
{
    public const OPERATOR = 'operator';
    public const SUPERVISOR = 'supervisor';
    public const ADMIN = 'admin';
    public const SUPER = 'super';

    /**
     * 角色等级。
     *
     * 等级只用于「至少达到某级」的粗粒度判断（例如某些页面 admin 及以上可见）。
     * 人事任免能力**不**走等级，走 canManageRole()——因为「能任免谁」与
     * 「等级高低」并不一致：主管能任免客服，而等级更高的管理员反而不能。
     */
    private const LEVELS = [
        self::OPERATOR => 1,
        self::SUPERVISOR => 2,
        self::ADMIN => 3,
        self::SUPER => 4,
    ];

    private const META = [
        self::OPERATOR => [
            'label' => '客服',
            'title' => '【客服】',
            'color' => 'slate',
            'desc' => '处理工单、维护知识库',
        ],
        self::SUPERVISOR => [
            'label' => '主管',
            'title' => '【主管】',
            'color' => 'amber',
            'desc' => '客服全部权限，并可新增与任免客服',
        ],
        self::ADMIN => [
            'label' => '管理员',
            'title' => '【管理员】',
            'color' => 'blue',
            'desc' => '主管全部权限，并可管理前台用户、邮件设置与操作日志',
        ],
        self::SUPER => [
            'label' => '超级管理员',
            'title' => '【超级管理员】',
            'color' => 'violet',
            'desc' => '拥有全部权限，包括任免管理员与修改系统设置',
        ],
    ];

    /**
     * 权限清单：能力 => 所需最低等级。
     *
     * 这样声明的好处是「某个页面需要什么权限」一眼可见，
     * 而不必去翻那个页面里的 if 判断。
     */
    private const CAPABILITIES = [
        // 工单线
        'ticket.view.any' => 1,
        'ticket.reply' => 1,
        'ticket.assign' => 1,
        'ticket.status' => 1,
        'ticket.internal_note' => 1,
        'ticket.export' => 3,
        'ticket.delete' => 4,
        'ticket.spam' => 2,
        // 知识库线
        'faq.manage' => 1,
        'category.manage' => 1,
        // 运营线
        'stats.view' => 1,
        'user.manage' => 3,
        'log.view' => 3,
        'mail.manage' => 3,
        // 系统线
        'staff.manage' => 2,   // 主管起步，能管到谁由 canManageRole 决定
        'setting.manage' => 4,
        'audit.view' => 3,
    ];

    /**
     * 角色能否任免目标角色。
     *
     * 规则：等级必须更高，且不能是「管理员任免管理员」这种平级互管。
     * 超级管理员例外，可以任免所有角色（含其他超管）。
     */
    private const MANAGEABLE = [
        self::OPERATOR => [],
        self::SUPERVISOR => [self::OPERATOR],
        self::ADMIN => [self::OPERATOR, self::SUPERVISOR],
        self::SUPER => [self::OPERATOR, self::SUPERVISOR, self::ADMIN, self::SUPER],
    ];

    /** @return list<string> 全部角色标识 */
    public static function all(): array
    {
        return array_keys(self::LEVELS);
    }

    /** @return array<string,array{label:string,title:string,color:string,desc:string}> */
    public static function meta(): array
    {
        return self::META;
    }

    public static function exists(string $role): bool
    {
        return isset(self::LEVELS[$role]);
    }

    public static function level(string $role): int
    {
        return self::LEVELS[$role] ?? 0;
    }

    public static function label(string $role): string
    {
        return self::META[$role]['label'] ?? $role;
    }

    /** 回复工单时显示在姓名前的头衔，如「【客服】」 */
    public static function replyTitle(string $role): string
    {
        return self::META[$role]['title'] ?? '';
    }

    public static function color(string $role): string
    {
        return self::META[$role]['color'] ?? 'slate';
    }

    public static function description(string $role): string
    {
        return self::META[$role]['desc'] ?? '';
    }

    /** 单个权限判定 */
    public static function can(string $role, string $capability): bool
    {
        $need = self::CAPABILITIES[$capability] ?? null;
        if ($need === null) {
            return false; // 未声明的能力一律拒绝，避免拼错能力名就悄悄放行
        }
        return self::level($role) >= $need;
    }

    /** @return list<string> 该角色拥有的全部能力，供界面展示 */
    public static function capabilities(string $role): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $cap => $need) {
            if (self::level($role) >= $need) {
                $out[] = $cap;
            }
        }
        return $out;
    }

    /** 任免判定 */
    public static function canManageRole(string $actor, string $target): bool
    {
        return in_array($target, self::MANAGEABLE[$actor] ?? [], true);
    }

    /** @return array<string,string> 可任免角色（用于下拉框） */
    public static function manageableRoles(string $actor): array
    {
        $out = [];
        foreach (self::MANAGEABLE[$actor] ?? [] as $r) {
            $out[$r] = self::label($r);
        }
        return $out;
    }

    /**
     * 任一后台角色都能处理工单，但「不能自己给自己派单」这类业务规则
     * 不在权限层实现，在 Service 里判定。
     */
    public static function canHandleTickets(string $role): bool
    {
        return self::can($role, 'ticket.reply');
    }
}
