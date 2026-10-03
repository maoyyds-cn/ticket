<?php

/**
 * 无状态 CSRF 令牌
 *
 * 为什么不用「存进会话的随机串」：
 * 那种做法要求渲染每一个带表单的页面时都必须先有会话。而对匿名访客来说，
 * 一旦建立会话，PHP 就会下发 Set-Cookie，响应随即变成「个性化」的，
 * Cloudflare 等边缘节点不会缓存它——于是每一次切页都要跨国回源到源站。
 * 对一个部署在美国、用户在国内的站点，这是首屏与切页体验里最大的一块成本。
 *
 * 这里改用签名的自证明令牌（双提交模式）：
 *      token = base64(ts . "." . random) . "." . HMAC(app_key, ts . "." . random)
 * 校验时用令牌自身携带的内容重算签名，因此**服务端不需要存任何东西**。
 * 安全性来自 HMAC 密钥（只存在于服务端配置文件里），不来自会话。
 *
 * 同时保留与会话的绑定：如果当前已有会话，就把会话 ID 混入签名。
 * 这样令牌无法被移植到另一个会话里使用，比纯无状态方案更严一点。
 */
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Session;

final class Tokens
{
    /** 令牌有效期（秒）。够用户填完一个工单表单，又不会长期有效 */
    private const TTL = 7200;

    public function __construct(
        private readonly Session $session,
        private readonly string $appKey,
    ) {
    }

    public static function fromConfig(Session $session): self
    {
        return new self($session, Config::string('app.key', 'ticket-app'));
    }

    public function issue(): string
    {
        $ts = time();
        $nonce = bin2hex(random_bytes(16));
        $payload = $ts . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload . '|' . $this->binding(), $this->appKey);
        return base64_encode($payload) . '.' . $sig;
    }

    public function verify(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$encoded, $sig] = $parts;

        $payload = base64_decode($encoded, true);
        if ($payload === false || substr_count($payload, '.') !== 1) {
            return false;
        }
        [$ts, $nonce] = explode('.', $payload, 2);
        if (!ctype_digit($ts) || $nonce === '') {
            return false;
        }

        // 过期检查：令牌不能长期有效，否则泄露后窗口太大
        $issued = (int)$ts;
        if ($issued > time() + 60 || time() - $issued > self::TTL) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload . '|' . $this->binding(), $this->appKey);
        return hash_equals($expected, $sig);
    }

    /**
     * 绑定因子。
     *
     * 已有会话时用会话 ID，令牌因此不能跨会话使用；
     * 没有会话时（匿名、可缓存的页面）绑定空串，令牌依然可用且是自证明的。
     */
    private function binding(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 'stateless';
        }
        return (string)session_id();
    }
}
