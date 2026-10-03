<?php

/**
 * 系统设置（仅超级管理员）
 *
 * 这里刻意不做旧版那个「结构自检 + 一键升级」页面。
 * 旧版那个页面的时间戳纠偏判断是单向的：
 *      elseif ($tzShift > 0 && $drift >= $tzShift - 300)
 * 正常的隔夜空闲就会让它误判为「时区错误」，随后把 9 张表的时间戳整体
 * 前移 8 小时；而偏移之后 drift 反而更大，条件依然成立，
 * 于是**每提交一次就再偏移一次，没有上界**，数据被持续破坏。
 * 结构升级属于运维动作，交给 bin/setup.php 与迁移脚本在命令行完成，
 * 不再暴露成一个「点一下就动全库」的网页按钮。
 */
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Application;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Setting\Settings;

final class SettingController extends AdminController
{
    public function __construct(
        Container $container,
        AuthService $auth,
        Settings $settings,
        private readonly Database $db,
    ) {
        parent::__construct($container, $auth, $settings);
    }

    public function index(Request $request): Response
    {
        return $this->admin('admin/settings', [
            'pageTitle' => '系统设置',
            'pageDesc' => '站点信息与工单规则',
            'activeNav' => 'settings',
            'settings' => $this->settings->withDefaults(array_keys(Settings::defaults())),
            'system' => $this->systemInfo(),
        ]);
    }

    public function save(Request $request): Response
    {
        $existing = $this->settings->withDefaults(array_keys(Settings::defaults()));

        $values = [
            'site_name' => mb_substr(trim($request->post('site_name')), 0, 60),
            'site_desc' => mb_substr(trim($request->post('site_desc')), 0, 300),
            'site_keywords' => mb_substr(trim($request->post('site_keywords')), 0, 200),
            'site_icp' => mb_substr(trim($request->post('site_icp')), 0, 60),
            'home_announce' => mb_substr(trim($request->post('home_announce')), 0, 1000),
            'ticket_prefix' => preg_replace('/[^A-Za-z0-9]/', '', $request->post('ticket_prefix')) ?: 'RB',
            'ticket_enabled' => $request->postBool('ticket_enabled') ? '1' : '0',
            'ticket_closed_notice' => mb_substr(trim($request->post('ticket_closed_notice')), 0, 300),
            'upload_max_mb' => (string)max(1, min(100, $request->postInt('upload_max_mb', 10))),
            'upload_max_count' => (string)max(1, min(20, $request->postInt('upload_max_count', 5))),
            'rate_limit_per_hour' => (string)max(0, min(200, $request->postInt('rate_limit_per_hour', 5))),
            'reg_enabled' => $request->postBool('reg_enabled') ? '1' : '0',
            'reg_require_email_code' => $request->postBool('reg_require_email_code') ? '1' : '0',
            'faq_page_size' => (string)max(4, min(50, $request->postInt('faq_page_size', 12))),
            'ticket_page_size' => (string)max(5, min(50, $request->postInt('ticket_page_size', 15))),
        ];

        // ---- 跨字段校验：这些组合会让站点不可用，必须在保存前拦住 ----
        $errors = [];

        if ($values['site_name'] === '') {
            $errors[] = '站点名称不能为空。';
        }

        // site_url 是邮件里链接的基准；不填就只能靠请求头推断，
        // 而请求头是客户端可控的，邮件链接可能指向别处
        $siteUrl = trim($request->post('site_url'));
        if ($siteUrl !== '' && filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = '站点地址不是合法 URL（需形如 https://example.com）。';
        }
        $values['site_url'] = rtrim($siteUrl, '/');

        // 要求邮箱验证码，却没有可用的邮件通道 —— 旧版默认就是这个组合，
        // 结果注册流程永远走不通，而界面上没有任何地方提示原因
        if ($values['reg_require_email_code'] === '1') {
            $mailReady = $this->settings->string('mail_from', '') !== ''
                && $this->settings->bool('mail_enabled', false)
                && ($this->settings->string('mail_transport', 'smtp') === 'mail'
                    ? function_exists('mail')
                    : $this->settings->string('mail_host', '') !== '');
            if (!$mailReady) {
                $errors[] = '「注册需要邮箱验证码」已开启，但邮件通道尚未配置完整，'
                    . '这样任何人都无法完成注册。请先到「邮件设置」配置发件人，或关闭该选项。';
            }
        }

        // 上传上限必须与 PHP 的实际上限匹配，否则用户上传时才失败，
        // 而且失败原因（413/超过 post_max_size）对用户毫无意义
        $phpMaxMb = $this->phpUploadLimitMb();
        if ($phpMaxMb !== null && (int)$values['upload_max_mb'] > $phpMaxMb) {
            $errors[] = '单个附件上限（' . $values['upload_max_mb'] . 'MB）超过了 PHP 允许的 '
                . $phpMaxMb . 'MB，超出部分的上传会失败。建议填 ' . $phpMaxMb . ' 或更小。';
        }

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->session()->flash('error', $message);
            }
            return Response::redirect(url('/admin/settings'));
        }

        $this->settings->setMany($values);

        Application::log(
            '[ADMIN] 更新系统设置 by staff_id=' . $this->staff()->id
            . ' changed=' . json_encode(array_keys($values), JSON_UNESCAPED_UNICODE),
            'security.log'
        );
        $this->session()->flash('ok', '设置已保存。');
        return Response::redirect(url('/admin/settings'));
    }

    /**
     * 页面展示用的环境信息。
     *
     * 旧版这个区块里还塞了 Turnstile 的私密密钥（type="text" 回显），
     * 等于把密钥写进 HTML、浏览器缓存与任何截图里。这里只显示
     * 「是否已配置」而不显示值本身。
     *
     * @return array<string,string|bool>
     */
    private function systemInfo(): array
    {
        $uploadMax = (string)ini_get('upload_max_filesize');
        $postMax = (string)ini_get('post_max_size');

        return [
            'PHP 版本' => PHP_VERSION,
            '数据库' => (string)$this->db->value('SELECT VERSION()'),
            '服务器时间' => date('Y-m-d H:i:s') . '（' . date_default_timezone_get() . '）',
            'upload_max_filesize' => $uploadMax,
            'post_max_size' => $postMax,
            'memory_limit' => (string)ini_get('memory_limit'),
            '附件目录可写' => is_writable((string)\App\Core\Config::string('app.upload_dir')),
            '日志目录可写' => is_writable((string)\App\Core\Config::string('app.log_dir')),
            'mail() 可用' => function_exists('mail'),
            '工单总数' => (string)$this->db->int('SELECT COUNT(*) FROM `ticket`'),
            '知识库条目' => (string)$this->db->int('SELECT COUNT(*) FROM `faq`'),
            '注册用户' => (string)$this->db->int('SELECT COUNT(*) FROM `user`'),
        ];
    }

    /** PHP 允许的单文件上限（MB），取 upload/post 两者较小值 */
    private function phpUploadLimitMb(): ?int
    {
        $toMb = static function (string $v): int {
            $v = trim($v);
            if ($v === '' || $v === '-1' || $v === '0') {
                return 0; // 0 表示不限制
            }
            $unit = strtolower(substr($v, -1));
            $num = (int)$v;
            return match ($unit) {
                'g' => $num * 1024,
                'm' => $num,
                'k' => (int)max(1, intdiv($num, 1024)),
                default => (int)max(1, intdiv($num, 1048576)),
            };
        };

        $upload = $toMb((string)ini_get('upload_max_filesize'));
        $post = $toMb((string)ini_get('post_max_size'));
        $limits = array_filter([$upload, $post], static fn(int $v): bool => $v > 0);
        return $limits === [] ? null : min($limits);
    }
}
