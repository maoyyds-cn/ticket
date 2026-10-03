<?php

/**
 * 频率限制与登录防爆破
 *
 * v1 的登录「防爆破」只改提示文案，没有任何实际限制——猜到正确密码
 * 为止完全不受阻。这里把它做成真的：连续失败累计，达到阈值后锁定一段时间。
 *
 * 两类限制共用同一张思路，但存在不同的地方：
 *   - 登录失败次数：存在 staff 表（login_fail / locked_until），能跨进程生效；
 *   - 提交频率：存在 Session + 数据库计数，因为提交主要针对访客，
 *     没有账号可挂靠。
 */
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Database;

final class Throttle
{
    /** 连续失败多少次开始锁 */
    private const LOGIN_MAX_FAILS = 5;

    /**
     * 允许的连续失败次数。
     *
     * 暴露出来是给界面用的：登录页要能写出「连续 N 次密码错误会临时锁定」，
     * 否则那句提示只能含糊地说「次数过多」，用户不知道还剩几次机会。
     */
    public static function maxFails(): int
    {
        return self::LOGIN_MAX_FAILS;
    }

    /** 锁定时长阶梯（秒）：第 1 次锁 60 秒，之后翻倍，上限 30 分钟 */
    private const LOCK_STEPS = [60, 180, 600, 1800];

    public function __construct(private readonly Database $db)
    {
    }

    /** 允许做失败计数的表（白名单：表名会被拼进 SQL） */
    private const FAIL_TABLES = [
        'staff' => 'staff',
        'user' => 'user',
    ];

    /**
     * 记录一次登录失败，返回「本次之后是否已被锁定」。
     *
     * 用一条 UPDATE 完成自增，避免「先读后写」在并发下丢计数。
     *
     * @param string $kind staff|user —— 前台与后台两种身份的表结构一致
     *                     （都有 login_fail / locked_until），因此共用一份实现
     */
    public function recordFailure(string $kind, int $id): bool
    {
        $table = self::FAIL_TABLES[$kind] ?? 'staff';

        $this->db->query(
            'UPDATE `' . $table . '` SET `login_fail` = `login_fail` + 1 WHERE `id` = ?',
            [$id]
        );
        $fails = $this->db->int('SELECT `login_fail` FROM `' . $table . '` WHERE `id` = ?', [$id]);

        if ($fails < self::LOGIN_MAX_FAILS) {
            return false;
        }
        // 阶梯锁定：连续失败越多锁越久。原来的实现把计数在加锁的同一分支里清零，
        // 导致延时恒为第一档、后续档位永远走不到。
        $step = min(
            count(self::LOCK_STEPS) - 1,
            intdiv($fails - self::LOGIN_MAX_FAILS, 3)
        );
        $seconds = self::LOCK_STEPS[$step];
        $this->db->query(
            'UPDATE `' . $table . '` SET `locked_until` = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE `id` = ?',
            [$seconds, $id]
        );
        return true;
    }

    public function clearFailures(string $kind, int $id): void
    {
        $table = self::FAIL_TABLES[$kind] ?? 'staff';
        $this->db->query(
            'UPDATE `' . $table . '` SET `login_fail` = 0, `locked_until` = NULL WHERE `id` = ?',
            [$id]
        );
    }

    /** 兼容旧调用名（后台登录） */
    public function recordLoginFailure(int $staffId): bool
    {
        return $this->recordFailure('staff', $staffId);
    }

    /** 兼容旧调用名（后台登录） */
    public function clearLoginFailures(int $staffId): void
    {
        $this->clearFailures('staff', $staffId);
    }

    /**
     * 账号当前是否处于锁定状态。
     *
     * @param array<string,mixed> $account 含 locked_until 的账号行
     * @return int 剩余锁定秒数，0 表示未锁定
     */
    public function lockRemaining(array $account): int
    {
        $until = (string)($account['locked_until'] ?? '');
        if ($until === '' || str_starts_with($until, '0000')) {
            return 0;
        }
        $ts = strtotime($until);
        if ($ts === false) {
            return 0;
        }
        return max(0, $ts - time());
    }

    /**
     * 校验图形/算术验证码失败次数是否超限（防止无限猜题）。
     */
    public function captchaFailsExceeded(int $fails, int $max): bool
    {
        return $fails >= $max;
    }

    /**
     * 按 IP 限制提交频率。
     *
     * 计数直接查工单表，而不是维护一个独立的计数器：工单表本来就有
     * (ip, created_at) 上的索引，多一份计数状态就多一处可能与真实数据不一致
     * 的地方。这里统计的是「已成功落库的提交」，语义也正好是我们要的。
     */
    public function recentSubmissionsFromIp(string $ip, int $hours = 1): int
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return 0;
        }
        return $this->db->int(
            'SELECT COUNT(*) FROM `ticket` WHERE `ip` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$ip, max(1, $hours)]
        );
    }

    /** 同一 IP 一段时间内允许发送的邮箱验证码条数 */
    public function recentCodeSends(string $ip, string $email, int $minutes = 10): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM `email_code` WHERE (`ip` = ? OR `email` = ?) AND `created_at` >= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, $email, max(1, $minutes)]
        );
    }

    /** 同一邮箱发送验证码的最小间隔（秒）——避免连点导致邮件轰炸 */
    public function codeSendCooldown(string $email, int $seconds = 60): int
    {
        $last = $this->db->value(
            'SELECT `created_at` FROM `email_code` WHERE `email` = ? ORDER BY `id` DESC LIMIT 1',
            [$email]
        );
        if (!is_string($last) || $last === '') {
            return 0;
        }
        $ts = strtotime($last);
        if ($ts === false) {
            return 0;
        }
        return max(0, $seconds - (time() - $ts));
    }
}
