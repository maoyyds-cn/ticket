<?php

/**
 * 人机校验
 *
 * v1 用了四层：算术验证码、蜜罐字段、计时陷阱、IP 频率限制。这套思路是
 * 对的，问题出在算术验证码的实现上——它把「答案」签名后存进 Session，
 * 但算式本身由客户端回传。于是同一个签名可以被反复使用，而且服务器
 * 无法验证「这道题是不是自己出的」。
 *
 * 这里的实现把两个操作数也纳入签名：
 *      sig = HMAC(app_key, a . ':' . b . ':' . issuedAt)
 * 校验时用客户端回传的 a、b、时间戳重算签名，再判断 a+b 是否等于用户答案。
 * 这样既不能改操作数、也不能改答案，而且签名带时效，无法长期复用。
 *
 * 另外三层（蜜罐、计时、频率）在 TicketService 里按同样思路保留。
 */
declare(strict_types=1);

namespace App\Support;

final class Captcha
{
    /** 题目有效期（秒） */
    private const TTL = 600;

    /** 同一个 Session 允许的失败次数 */
    private const MAX_FAILS = 5;

    public function __construct(private readonly string $appKey)
    {
    }

    /**
     * 出一道题。
     *
     * @return array{a:int,b:int,op:string,question:string,sig:string,ts:int}
     */
    public function issue(): array
    {
        $a = random_int(2, 19);
        $b = random_int(2, 19);

        // 随机决定加法或减法，减法保证结果非负，避免出现「-3」这种答案让人怀疑人生
        if (random_int(0, 1) === 1) {
            if ($a < $b) {
                [$a, $b] = [$b, $a];
            }
            $op = '-';
            $question = $a . ' − ' . $b;
        } else {
            $op = '+';
            $question = $a . ' + ' . $b;
        }

        $ts = time();
        return [
            'a' => $a,
            'b' => $b,
            'op' => $op,
            'question' => $question,
            'sig' => $this->sign($a, $b, $op, $ts),
            'ts' => $ts,
        ];
    }

    /**
     * 校验用户答案。
     *
     * @param string $answer 用户提交的答案
     * @param string $sig    题目里带回来的签名
     * @param int    $a      操作数
     * @param int    $b      操作数
     * @param string $op     运算符
     * @param int    $ts     出题时间戳
     */
    public function verify(string $answer, string $sig, int $a, int $b, string $op, int $ts): bool
    {
        if ($answer === '' || !preg_match('/^-?\d{1,3}$/', $answer)) {
            return false;
        }
        if (time() - $ts > self::TTL || $ts > time() + 60) {
            return false; // 过期或时间戳被伪造到未来
        }
        if (!hash_equals($this->sign($a, $b, $op, $ts), $sig)) {
            return false; // 操作数或时间戳被改动
        }
        $expected = $op === '-' ? $a - $b : $a + $b;
        return (int)$answer === $expected;
    }

    private function sign(int $a, int $b, string $op, int $ts): string
    {
        return hash_hmac('sha256', $a . ':' . $b . ':' . $op . ':' . $ts, $this->appKey);
    }

    public function maxFails(): int
    {
        return self::MAX_FAILS;
    }
}
