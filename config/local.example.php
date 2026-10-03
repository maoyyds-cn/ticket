<?php

/**
 * 本机配置（敏感项，权限应设为 640，且不要提交到版本库）
 *
 * 这里的值会覆盖 config/app.php 中同名的键。
 * 首次部署请复制 config/local.example.php 为本文件，或直接运行
 *   php bin/setup.php
 * 由安装脚本生成。
 */
declare(strict_types=1);

return [
    // 用于给访客访问密钥、验证码等做 HMAC 加盐。
    // 一旦更改，所有既有的访客工单链接都会失效，请勿随意替换。
    'app' => [
        'key' => '',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'ticket',
        'user' => 'ticket',
        'pass' => 'CHANGE_ME',
    ],
];
