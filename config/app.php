<?php

/**
 * 基础配置（不含任何敏感信息，可以提交到版本库）
 *
 * 数据库口令、app_key 这类内容放 config/local.php，那个文件单独设权限、
 * 不进版本库。这样「导出一份代码」与「泄露一份凭据」是两件事。
 */
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Roblox 查询机器人 · 帮助中心',
        'version' => '2.0.0',
        'timezone' => 'Asia/Shanghai',

        // 调试模式：开启后错误页会附带堆栈。生产环境务必保持 false。
        'debug' => false,

        // 站点部署在子目录时填写，例如 '/ticket'；部署在根目录留空。
        // 这个值影响所有链接生成，配错会导致整站点不出样式。
        'base_path' => '',

        // 会话 Cookie 是否只在 HTTPS 下发送。站点已启用 HTTPS，可设为 true。
        'session_secure' => false,

        // 边缘缓存时长（秒）。仅对「未登录 + 未写会话 + 200」的 GET 响应生效，
        // 交给 Cloudflare 等 CDN 缓存，避免每次切页都跨国回源。
        // 设为 0 可完全关闭（退化为不缓存）。
        // 内容更新后最多滞后这么久，因此不建议设得很大。
        'edge_cache_seconds' => 60,
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'ticket',
        'user' => 'ticket',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
