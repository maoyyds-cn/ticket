<?php

/**
 * 身份对象
 *
 * 前台用户与后台账号在会话里只保留一个：v1 允许两者共存，结果出现了
 * 「管理员在前台浏览时回复框被隐藏」这种自相矛盾的界面（v1 注释记录过）。
 * 这里用同一个不可变值对象表示当前身份，kind 区分 user / staff，
 * 并且互斥登录——登入任一侧都会清掉另一侧。
 */
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Staff\Role;

final class Identity
{
    public const KIND_USER = 'user';
    public const KIND_STAFF = 'staff';

    /** @param array<string,mixed> $row */
    private function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $username,
        public readonly string $realname,
        public readonly string $email,
        public readonly string $qq,
        public readonly string $role,
        private readonly array $row,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromUser(array $row): self
    {
        return new self(
            self::KIND_USER,
            (int)$row['id'],
            (string)$row['username'],
            (string)($row['realname'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['qq'] ?? ''),
            '',
            $row,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromStaff(array $row): self
    {
        return new self(
            self::KIND_STAFF,
            (int)$row['id'],
            (string)$row['username'],
            (string)($row['realname'] ?? ''),
            (string)($row['email'] ?? ''),
            '',
            (string)$row['role'],
            $row,
        );
    }

    public function isStaff(): bool
    {
        return $this->kind === self::KIND_STAFF;
    }

    public function isUser(): bool
    {
        return $this->kind === self::KIND_USER;
    }

    /** 页面上显示的名字：优先真名，其次账号名 */
    public function displayName(): string
    {
        return $this->realname !== '' ? $this->realname : $this->username;
    }

    /** 权限判定：只有后台身份才有能力，前台用户恒为 false */
    public function can(string $capability): bool
    {
        return $this->isStaff() && Role::can($this->role, $capability);
    }

    public function isSuper(): bool
    {
        return $this->role === Role::SUPER;
    }

    /** @return array<string,mixed> 原始行，供个别页面取额外字段 */
    public function row(): array
    {
        return $this->row;
    }
}
