<?php

/**
 * 站点配置仓储
 *
 * v1 用 $GLOBALS['SETTINGS'] 缓存 + setting() 全局函数，且每次写入都要
 * 改全局数组。这里换成对象，并且把「未设置」与「设为空字符串」区分开
 * ——v1 的 setting() 把空字符串当作未设置处理，导致管理员**无法把某项
 * 配置清空**，只能被迫留着一个值。这是个真实且很容易被忽略的缺陷。
 */
declare(strict_types=1);

namespace App\Domain\Setting;

use App\Core\Database;

final class Settings
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    /** @var array<string,string> 本次请求内写入的值 */
    private array $dirty = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->all('SELECT `k`, `v` FROM `settings`');
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['k']] = (string)($r['v'] ?? '');
        }
        return $this->cache = $out;
    }

    /**
     * 取配置值。
     *
     * 与 v1 不同：这里「键存在但值为空」返回空字符串，只有键不存在才返回
     * 默认值。管理员清空某项配置后，页面上就该是空的。
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->load();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, null);
        return $v === null ? $default : $v;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, null);
        return $v === null || $v === '' ? $default : (int)$v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, null);
        if ($v === null) {
            return $default;
        }
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->db->query(
            'INSERT INTO `settings` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)',
            [$key, $value]
        );
        if ($this->cache === null) {
            $this->load();
        }
        $this->cache[$key] = $value;
        $this->dirty[$key] = $value;
    }

    /**
     * 批量写入。
     *
     * @param array<string,string|int|bool> $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            $this->set($k, is_bool($v) ? ($v ? '1' : '0') : (string)$v);
        }
    }

    /** @return array<string,string> 已写入的键值，供审计日志使用 */
    public function dirty(): array
    {
        return $this->dirty;
    }

    /**
     * 站点默认配置。
     *
     * 集中在这里而不是散落在各页面的 setting('x', '默认值')：默认值只写一次，
     * 改起来不会漏掉某处页面。
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'site_name' => 'Roblox 查询机器人 · 帮助中心',
            'site_desc' => '先到知识库自助排查，多数问题一分钟内解决；仍未解决？提交工单，客服会跟进到底。',
            'site_keywords' => 'Roblox,查询机器人,工单,知识库,帮助中心,koishi',
            'site_icp' => '',
            'site_url' => '',
            'home_announce' => '',
            'ticket_prefix' => 'RB',
            'ticket_enabled' => '1',
            'ticket_closed_notice' => '工单通道暂时关闭维护，请稍后再试，或先查阅知识库。',
            'upload_max_mb' => '10',
            'upload_max_count' => '5',
            'rate_limit_per_hour' => '5',
            'reg_enabled' => '1',
            'reg_require_email_code' => '0',
            'faq_page_size' => '12',
            'ticket_page_size' => '15',
            'mail_enabled' => '0',
            'mail_transport' => 'smtp',
            'mail_host' => '',
            'mail_port' => '465',
            'mail_secure' => 'ssl',
            'mail_user' => '',
            'mail_pass' => '',
            'mail_from' => '',
            'mail_from_name' => '',
            'mail_admin_to' => '',
            'mail_subject_prefix' => '',
        ];
    }

    /**
     * 读取配置并回落到默认值。
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    public function withDefaults(array $keys): array
    {
        $defaults = self::defaults();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->string($k, $defaults[$k] ?? '');
        }
        return $out;
    }
}
