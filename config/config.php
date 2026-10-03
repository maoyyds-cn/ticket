<?php
/**
 * 全局配置文件
 * 安装向导 install/index.php 会自动重写本文件。
 * 请勿将本文件权限设为公开可写（建议 640）。
 */
return [
    'installed'    => false,
    'app_key'      => '',
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'ticket',
        'user'    => 'root',
        'pass'    => '',
        'prefix'  => 'tk_',
    ],
];
