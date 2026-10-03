<?php

/**
 * 邮箱验证码
 *
 * 相对 v1 的三处收紧：
 *  1) 加盐改成「每行随机盐 + app_key」。v1 是 hash_hmac(code, app_key)，
 *     而验证码只有 6 位数字——10^6 次 HMAC 在普通机器上是秒级，
 *     库一旦泄露，未过期的验证码等于明文。加上每行随机盐后必须逐行爆破，
 *     成本被乘上盐空间，泄露即失效的假设才成立。
 *  2) 增加失败次数上限。v1 在 10 分钟窗口内可以无限猜 6 位码。
 *  3) 发送冷却 + 每邮箱/每 IP 配额，避免被当成邮件轰炸工具。
 */
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Support\Str;

final class EmailCode
{
    public const SCENE_REGISTER = 'register';
    public const SCENE_RESET = 'reset';

    private const TTL_MINUTES = 10;
    private const MAX_TRIES = 5;
    private const MAX_PER_WINDOW = 3;
    private const WINDOW_MINUTES = 10;
    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly Database $db,
        private readonly Throttle $throttle,
    ) {
    }

    /**
     * 生成并落库一个验证码，返回明文（仅用于发给用户，不落库）。
     *
     * @return array{ok:bool,code?:string,error?:string}
     */
    public function issue(string $email, string $scene, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return ['ok' => false, 'error' => '邮箱不能为空'];
        }

        $cooldown = $this->throttle->codeSendCooldown($email, self::COOLDOWN_SECONDS);
        if ($cooldown > 0) {
            return ['ok' => false, 'error' => '请等待 ' . $cooldown . ' 秒后再重新发送'];
        }
        if ($this->throttle->recentCodeSends($ip, $email, self::WINDOW_MINUTES) >= self::MAX_PER_WINDOW) {
            return ['ok' => false, 'error' => '发送过于频繁，请稍后再试'];
        }

        $code = Str::numericCode(6);
        $salt = Str::randomHex(16);

        // 盐单独一列存。
        //
        // 之前的写法是「先插 64 位 HMAC，再 UPDATE 把 32 位盐 CONCAT 到前面」，
        // 于是该列要装 96 个字符，而列定义是 CHAR(64)：严格模式下直接报
        // 1406 Data too long（整条注册流程 500），非严格模式下静默截断，
        // 截断后 substre(32) 拿到的是半个哈希，验证永远失败。
        // 无论哪种情况这个功能都不可能工作，所以这里改成两列。
        $this->db->query(
            'INSERT INTO `email_code` (`email`, `code_hash`, `salt`, `scene`, `ip`, `expire_at`)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$email, $this->hash($code, $salt), $salt, $scene, $ip, self::TTL_MINUTES]
        );

        return ['ok' => true, 'code' => $code];
    }

    /**
     * 校验验证码。成功即作废（一次性）。
     *
     * @return array{ok:bool,error?:string}
     */
    public function verify(string $email, string $scene, string $code): array
    {
        $email = mb_strtolower(trim($email));
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return ['ok' => false, 'error' => '验证码格式不正确'];
        }

        // 取出该邮箱当前所有「未使用且未过期」的验证码，逐个比对。
        //
        // 之前只取最新一条（ORDER BY id DESC LIMIT 1），于是「先要一个码、
        // 等了 60 秒再要一个、然后回去用第一封邮件里的码」这种很自然的操作
        // 会直接失败，而且还白烧一次尝试次数。
        $rows = $this->db->all(
            'SELECT * FROM `email_code`
             WHERE `email` = ? AND `scene` = ? AND `used` = 0 AND `expire_at` >= NOW()
             ORDER BY `id` DESC LIMIT 10',
            [$email, $scene]
        );
        if ($rows === []) {
            // 区分「从没要过」与「已过期」，提示才有用
            $any = $this->db->int(
                'SELECT COUNT(*) FROM `email_code` WHERE `email` = ? AND `scene` = ? AND `used` = 0',
                [$email, $scene]
            );
            return ['ok' => false, 'error' => $any > 0 ? '验证码已过期，请重新获取' : '请先获取验证码'];
        }

        // 尝试次数上限必须**在校验之前**判断，而且要看这个邮箱在该场景下的
        // 累计失败数，不能只看最新那一行。
        //
        // 之前的实现有两个漏洞，合起来等于没有上限：
        //   1. 命中时先返回成功（`$matched !== null` 的分支在前面），
        //      于是「错 5 次再猜对」照样通过——上限拦不住任何人；
        //   2. 计数只写最新一行，而「重新获取验证码」会插入 tries=0 的新行，
        //      于是提示用户「请重新获取」之后，计数就被他自己清零了。
        // 现在按 (email, scene) 汇总最近 10 分钟的失败数，并对该邮箱整体封顶，
        // 重新获取验证码也无法绕过。
        $usedTries = $this->db->int(
            'SELECT COALESCE(SUM(`tries`), 0) FROM `email_code`
             WHERE `email` = ? AND `scene` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            [$email, $scene]
        );
        if ($usedTries >= self::MAX_TRIES) {
            return ['ok' => false, 'error' => '验证码尝试次数过多，请稍后再试'];
        }

        $matched = null;
        foreach ($rows as $row) {
            $salt = (string)($row['salt'] ?? '');
            if ($salt === '' || strlen($salt) !== 32) {
                continue;
            }
            if (hash_equals((string)$row['code_hash'], $this->hash($code, $salt))) {
                $matched = $row;
                break;
            }
        }

        if ($matched === null) {
            // 失败计数落在**所有**当前有效行上，这样下次重新获取时
            // 汇总查询仍能看到这次失败，不会被新行的 tries=0 抹掉。
            $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $this->db->query('UPDATE `email_code` SET `tries` = `tries` + 1 WHERE `id` IN (' . $ph . ')', $ids);

            $left = self::MAX_TRIES - ($usedTries + 1);
            return [
                'ok' => false,
                'error' => $left > 0
                    ? '验证码不正确，还可尝试 ' . $left . ' 次'
                    : '验证码尝试次数过多，请稍后再试',
            ];
        }

        // 命中即作废该邮箱该场景下的全部未用码，避免「一个码反复用」
        $this->db->query(
            'UPDATE `email_code` SET `used` = 1 WHERE `email` = ? AND `scene` = ? AND `used` = 0',
            [$email, $scene]
        );
        return ['ok' => true];
    }

    private function hash(string $code, string $salt): string
    {
        return hash_hmac('sha256', $code, $salt . '|' . Config::string('app.key', 'ticket-app'));
    }

    /** 清理过期验证码，避免表无限增长 */
    public function prune(int $olderThanHours = 24): int
    {
        return $this->db->affected(
            'DELETE FROM `email_code` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [max(1, $olderThanHours)]
        );
    }
}
