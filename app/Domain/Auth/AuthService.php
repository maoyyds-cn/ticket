<?php

/**
 * 认证与账号服务
 *
 * 一个入口管两种身份（前台用户、后台账号），因为它们的规则差异很小，
 * 而 v1 把它们拆在 auth.php 的两半里，两半的写法并不一致（前台没有
 * 登录失败计数、后台没有状态检查的重复），改一处忘一处。
 *
 * 这里明确的规则：
 *   - 两种身份互斥：登录任一侧都会清掉另一侧；
 *   - 会话 ID 在登录时重新生成（防会话固定）；
 *   - 后台账号有闲置超时、失败锁定；前台用户只需 status=1。
 */
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Database;
use App\Core\Session;

final class AuthService
{
    /** 后台闲置多久自动退出（秒） */
    public const STAFF_IDLE_TIMEOUT = 1800;

    private ?Identity $identity = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Throttle $throttle,
    ) {
    }

    // ---------------------------------------------------------------
    // 当前身份
    // ---------------------------------------------------------------

    public function identity(): ?Identity
    {
        if ($this->resolved) {
            return $this->identity;
        }
        $this->resolved = true;
        $this->identity = $this->resolve();
        return $this->identity;
    }

    private function resolve(): ?Identity
    {
        $staffId = (int)$this->session->get('staff_id', 0);
        if ($staffId > 0) {
            return $this->resolveStaff($staffId);
        }
        $userId = (int)$this->session->get('user_id', 0);
        if ($userId > 0) {
            $row = $this->db->row('SELECT * FROM `user` WHERE `id` = ? AND `status` = 1', [$userId]);
            if ($row === null) {
                $this->session->forget('user_id');
                return null;
            }
            return Identity::fromUser($row);
        }
        return null;
    }

    private function resolveStaff(int $staffId): ?Identity
    {
        $row = $this->db->row('SELECT * FROM `staff` WHERE `id` = ?', [$staffId]);
        if ($row === null || (int)$row['status'] !== 1) {
            $this->staffLogout();
            return null;
        }

        // 闲置超时：以最后活动时间为准，而不是登录时刻。
        // 用登录时刻判定会让连续操作的人满 30 分钟被无故踢出。
        $now = time();
        $lastActive = (int)$this->session->get('staff_active', 0);
        if ($lastActive > 0 && $now - $lastActive > self::STAFF_IDLE_TIMEOUT) {
            $this->staffLogout();
            $this->session->set('staff_timeout', 1);
            return null;
        }
        $this->session->set('staff_active', $now);
        return Identity::fromStaff($row);
    }

    /** 强制重新读取身份（资料修改后调用） */
    public function refresh(): void
    {
        $this->resolved = false;
        $this->identity = null;
    }

    public function user(): ?Identity
    {
        $i = $this->identity();
        return $i !== null && $i->isUser() ? $i : null;
    }

    public function staff(): ?Identity
    {
        $i = $this->identity();
        return $i !== null && $i->isStaff() ? $i : null;
    }

    public function check(): bool
    {
        return $this->identity() !== null;
    }

    public function isStaff(): bool
    {
        return $this->staff() !== null;
    }

    public function can(string $capability): bool
    {
        $i = $this->identity();
        return $i !== null && $i->can($capability);
    }

    // ---------------------------------------------------------------
    // 前台用户
    // ---------------------------------------------------------------

    /**
     * 注册前台用户。
     *
     * 「先查重、再插入」在并发下挡不住重复：两个同时到达的请求会各查到
     * 「没人用」，然后都去插入。真正的保证来自唯一索引（username 与 email
     * 都是唯一键），因此这里额外捕获 1062 并翻译成可读提示，
     * 而不是把一个 PDO 异常抛到页面上变成 500。
     *
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function registerUser(string $username, string $password, string $email, string $qq): array
    {
        $exists = $this->db->int('SELECT COUNT(*) FROM `user` WHERE `username` = ?', [$username]);
        if ($exists > 0) {
            return ['ok' => false, 'error' => '该用户名已被注册'];
        }
        if ($email !== '') {
            $mailUsed = $this->db->int('SELECT COUNT(*) FROM `user` WHERE `email` = ?', [$email]);
            if ($mailUsed > 0) {
                return ['ok' => false, 'error' => '该邮箱已被注册'];
            }
        }

        try {
            $id = $this->db->insert(
                'INSERT INTO `user` (`username`, `password`, `email`, `qq`, `realname`, `status`)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $email, $qq, $username]
            );
        } catch (\PDOException $e) {
            if (Database::isDuplicate($e)) {
                // 唯一键冲突：判断到底是用户名还是邮箱被占用
                $msg = str_contains($e->getMessage(), 'uk_user_email') ? '该邮箱已被注册' : '该用户名已被注册';
                return ['ok' => false, 'error' => $msg];
            }
            throw $e;
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * 把「还要等多久」变成人话。
     *
     * 不用 ceil(秒 / 60) 直接说「N 分钟」：只剩 10 秒时也显示「1 分钟」，
     * 用户等满一分钟回来再试，会因为重新失败而进入下一档锁定，
     * 于是「按提示等了还是不行」——看起来像系统坏了。
     * 这里对不满一分钟的情况给出秒数，且始终向上取整，宁可多说不少说。
     */
    private static function humanDuration(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        $minutes = (int)ceil($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' 分钟';
        }
        $hours = (int)ceil($minutes / 60);
        return $hours . ' 小时';
    }

    /**
     * 前台登录。
     *
     * 失败次数与锁定时间落在数据库里（user.login_fail / locked_until）。
     *
     * 之前这套「防爆破」放在会话里：连续失败计数写在 $_SESSION，于是
     * 只要清掉 Cookie 就归零，等于可以无限猜密码——而注释和文档都说它有防护。
     * 会话是可以被客户端随意丢弃的，任何安全计数器都不能只存在那里。
     *
     * @return array{ok:bool,error?:string}
     */
    public function loginUser(string $username, string $password, string $ip): array
    {
        $row = $this->db->row(
            'SELECT * FROM `user` WHERE `username` = ? OR (`email` <> "" AND `email` = ?) LIMIT 1',
            [$username, mb_strtolower($username)]
        );
        // 账号不存在时也走一次哈希校验，避免通过响应时间差枚举出哪些用户名存在
        if ($row === null) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ['ok' => false, 'error' => '用户名或密码不正确'];
        }

        // 锁定判断必须在密码校验之前：否则爆破者仍能靠响应差异
        // 判断密码是否正确，锁定就只是表面功夫。
        //
        // 提示里给出真实剩余时长，而不是向上取整成「1 分钟」：
        // 只剩 10 秒时也告诉用户「1 分钟后再试」，他等满一分钟回来再试，
        // 又会因重新失败而进入下一档锁定——区间对不上会让人以为系统坏了。
        $remaining = $this->throttle->lockRemaining($row);
        if ($remaining > 0) {
            return ['ok' => false, 'error' => '账号已临时锁定，请 ' . self::humanDuration($remaining) . '后再试'];
        }

        if ((int)$row['status'] !== 1) {
            return ['ok' => false, 'error' => '该账号已被禁用，请联系管理员'];
        }
        if (!password_verify($password, (string)$row['password'])) {
            $this->throttle->recordFailure('user', (int)$row['id']);
            // 本次失败刚好触发锁定：直接告知还要等多久，
            // 而不是回一句「密码不正确」让用户继续试、继续累加锁定档位
            $after = $this->db->row('SELECT `locked_until` FROM `user` WHERE `id` = ?', [(int)$row['id']]);
            $wait = $after !== null ? $this->throttle->lockRemaining($after) : 0;
            if ($wait > 0) {
                return ['ok' => false, 'error' => '密码连续错误，账号已临时锁定，请 ' . self::humanDuration($wait) . '后再试'];
            }
            return ['ok' => false, 'error' => '用户名或密码不正确'];
        }

        $this->throttle->clearFailures('user', (int)$row['id']);
        $this->session->regenerate();
        $this->session->forget('staff_id', 'staff_login', 'staff_active', 'staff_timeout');
        $this->session->set('user_id', (int)$row['id']);
        $this->session->set('user_login', time());

        $this->db->query(
            'UPDATE `user` SET `last_login_at` = NOW(), `last_login_ip` = ? WHERE `id` = ?',
            [$ip, (int)$row['id']]
        );

        // 密码算法升级：PHP 调整默认 cost 后，旧哈希会在用户下次登录时自动重算
        if (password_needs_rehash((string)$row['password'], PASSWORD_DEFAULT)) {
            $this->db->query(
                'UPDATE `user` SET `password` = ? WHERE `id` = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]
            );
        }

        $this->refresh();
        return ['ok' => true];
    }

    /**
     * 退出前台登录。
     *
     * 这里同时清掉后台身份：前台的「退出登录」入口对所有身份都会显示，
     * 如果只清 user_id，工作人员点了之后会看到「已退出登录」的提示，
     * 但下一次请求又因为 staff_id 还在而被重新识别为已登录——
     * 提示和实际状态不一致，用户会以为退出失败。
     */
    public function userLogout(): void
    {
        $this->session->forget('user_id', 'user_login', 'staff_id', 'staff_login', 'staff_active', 'staff_timeout');
        $this->session->regenerate();
        $this->refresh();
    }

    /**
     * 修改前台用户密码。
     *
     * @return array{ok:bool,error?:string}
     */
    public function changeUserPassword(int $userId, string $current, string $new): array
    {
        $row = $this->db->row('SELECT `password` FROM `user` WHERE `id` = ?', [$userId]);
        if ($row === null) {
            return ['ok' => false, 'error' => '账号不存在'];
        }
        if (!password_verify($current, (string)$row['password'])) {
            return ['ok' => false, 'error' => '当前密码不正确'];
        }
        $this->db->query(
            'UPDATE `user` SET `password` = ? WHERE `id` = ?',
            [password_hash($new, PASSWORD_DEFAULT), $userId]
        );
        return ['ok' => true];
    }

    /** @return array<string,mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        return $this->db->row('SELECT * FROM `user` WHERE `email` = ? LIMIT 1', [mb_strtolower($email)]);
    }

    // ---------------------------------------------------------------
    // 后台账号
    // ---------------------------------------------------------------

    /**
     * 后台登录。
     *
     * @return array{ok:bool,error?:string}
     */
    public function loginStaff(string $username, string $password, string $ip, string $ua): array
    {
        $row = $this->db->row('SELECT * FROM `staff` WHERE `username` = ? LIMIT 1', [$username]);
        if ($row === null) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ['ok' => false, 'error' => '账号或密码不正确'];
        }

        // 锁定检查必须在密码校验**之前**：否则爆破者仍能靠响应差异
        // 判断密码是否正确，锁定就只是表面功夫。
        $remaining = $this->throttle->lockRemaining($row);
        if ($remaining > 0) {
            return ['ok' => false, 'error' => '账号已临时锁定，请 ' . self::humanDuration($remaining) . '后再试'];
        }

        if (!password_verify($password, (string)$row['password'])) {
            $locked = $this->throttle->recordLoginFailure((int)$row['id']);
            if (!$locked) {
                return ['ok' => false, 'error' => '账号或密码不正确'];
            }
            $after = $this->db->row('SELECT `locked_until` FROM `staff` WHERE `id` = ?', [(int)$row['id']]);
            $wait = $after !== null ? $this->throttle->lockRemaining($after) : 0;
            return [
                'ok' => false,
                'error' => '密码连续错误，账号已临时锁定'
                    . ($wait > 0 ? '，请 ' . self::humanDuration($wait) . '后再试' : ''),
            ];
        }

        if ((int)$row['status'] !== 1) {
            return ['ok' => false, 'error' => '该账号已被停用，请联系超级管理员'];
        }

        $this->session->regenerate();
        $this->session->forget('user_id', 'user_login', 'staff_timeout');
        $this->session->set('staff_id', (int)$row['id']);
        $this->session->set('staff_login', time());
        $this->session->set('staff_active', time());

        $this->throttle->clearLoginFailures((int)$row['id']);
        $this->db->query(
            'UPDATE `staff` SET `last_login_at` = NOW(), `last_login_ip` = ? WHERE `id` = ?',
            [$ip, (int)$row['id']]
        );
        $this->db->query(
            'INSERT INTO `staff_session` (`staff_id`, `ip`, `user_agent`) VALUES (?, ?, ?)',
            [(int)$row['id'], $ip, mb_substr($ua, 0, 250)]
        );

        if (password_needs_rehash((string)$row['password'], PASSWORD_DEFAULT)) {
            $this->db->query(
                'UPDATE `staff` SET `password` = ? WHERE `id` = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]
            );
        }

        $this->refresh();
        return ['ok' => true];
    }

    public function staffLogout(): void
    {
        $this->session->forget('staff_id', 'staff_login', 'staff_active');
        $this->session->regenerate();
        $this->refresh();
    }

    /**
     * 新增后台账号。
     *
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function createStaff(string $username, string $password, string $role, string $realname, string $email): array
    {
        $exists = $this->db->int('SELECT COUNT(*) FROM `staff` WHERE `username` = ?', [$username]);
        if ($exists > 0) {
            return ['ok' => false, 'error' => '该登录名已存在'];
        }
        $id = $this->db->insert(
            'INSERT INTO `staff` (`username`, `password`, `role`, `realname`, `email`, `status`)
             VALUES (?, ?, ?, ?, ?, 1)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $role, $realname, $email]
        );
        return ['ok' => true, 'id' => $id];
    }

    /**
     * 修改后台账号。
     *
     * 这里守住两条底线，v1 没有守住：
     *   1) 不能把最后一个启用中的超级管理员降级或停用，否则系统再也没人能管；
     *   2) 不能修改自己的角色（防止管理员把自己降成客服后无人可恢复）。
     *
     * @param array<string,mixed> $target
     * @return array{ok:bool,error?:string}
     */
    public function updateStaff(array $target, ?string $role, ?string $realname, ?string $email, ?int $status, int $actorId): array
    {
        $targetId = (int)$target['id'];
        $isSelf = $targetId === $actorId;

        if ($isSelf && $role !== null && $role !== (string)$target['role']) {
            return ['ok' => false, 'error' => '不能修改自己的角色，请让其他管理员操作'];
        }

        $demoting = $role !== null && $role !== (string)$target['role'];
        $disabling = $status !== null && $status === 0;
        $wasSuper = (string)$target['role'] === \App\Domain\Staff\Role::SUPER;

        if ($isSelf && $disabling) {
            return ['ok' => false, 'error' => '不能停用自己的账号'];
        }

        $sets = [];
        $params = [];
        if ($role !== null) {
            $sets[] = '`role` = ?';
            $params[] = $role;
        }
        if ($realname !== null) {
            $sets[] = '`realname` = ?';
            $params[] = $realname;
        }
        if ($email !== null) {
            $sets[] = '`email` = ?';
            $params[] = $email;
        }
        if ($status !== null) {
            $sets[] = '`status` = ?';
            $params[] = $status;
        }
        if ($sets === []) {
            return ['ok' => true];
        }

        // 「检查最后一个超管」与「执行修改」必须在同一个事务里，
        // 否则两个并发请求会各自通过检查（见 lockAndCountSupers 的说明）。
        $error = null;
        $this->db->transaction(function () use (
            $target, $targetId, $wasSuper, $demoting, $disabling, $sets, $params, &$error
        ): void {
            if ($wasSuper && ($demoting || $disabling) && $this->lockAndCountSupers() <= 1) {
                $error = '系统必须保留至少一名启用中的超级管理员';
                return;
            }
            $params[] = $targetId;
            $this->db->query('UPDATE `staff` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $params);
        });

        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        // 被改动的正是当前登录者时，刷新会话里的身份缓存
        if ($isSelf) {
            $this->refresh();
        }
        return ['ok' => true];
    }

    public function resetStaffPassword(int $staffId, string $newPassword): void
    {
        $this->db->query(
            'UPDATE `staff` SET `password` = ?, `login_fail` = 0, `locked_until` = NULL WHERE `id` = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), $staffId]
        );
    }

    /**
     * 删除后台账号。
     *
     * @param array<string,mixed> $target
     * @return array{ok:bool,error?:string}
     */
    public function deleteStaff(array $target, int $actorId): array
    {
        if ((int)$target['id'] === $actorId) {
            return ['ok' => false, 'error' => '不能删除自己的账号'];
        }

        // 同样需要事务 + 行锁：否则两个并发删除各自的「最后一名超管」会双双通过
        $error = null;
        $this->db->transaction(function () use ($target, &$error): void {
            if ((string)$target['role'] === \App\Domain\Staff\Role::SUPER && $this->lockAndCountSupers() <= 1) {
                $error = '系统必须保留至少一名超级管理员';
                return;
            }
            $this->db->query('DELETE FROM `staff` WHERE `id` = ?', [(int)$target['id']]);
        });

        return $error !== null ? ['ok' => false, 'error' => $error] : ['ok' => true];
    }

    private function activeSuperCount(): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `staff` WHERE `role` = ? AND `status` = 1',
            [\App\Domain\Staff\Role::SUPER]
        );
    }

    /**
     * 在事务内对 staff 表加行锁后统计启用中的超管数量。
     *
     * 必须加锁的原因：原来的实现是「先查计数、再改」，两个并发请求
     * （例如超管 A 降级 B、同时 B 降级 A）会各自读到 2，于是双双通过检查，
     * 结果是系统里一个启用中的超管都不剩——此后没人能进系统设置或成员管理，
     * 不可恢复。用 FOR UPDATE 把「统计 + 修改」变成串行的临界区即可避免。
     */
    private function lockAndCountSupers(): int
    {
        // 先锁住相关行。聚合函数不能直接 FOR UPDATE，因此先取出主键再锁。
        $ids = array_map(
            static fn(array $r): int => (int)$r['id'],
            $this->db->all('SELECT `id` FROM `staff` WHERE `role` = ? FOR UPDATE', [\App\Domain\Staff\Role::SUPER])
        );
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->int(
            'SELECT COUNT(*) FROM `staff` WHERE `id` IN (' . $placeholders . ') AND `status` = 1',
            $ids
        );
    }
}
