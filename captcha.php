<?php
/** 验证码刷新接口 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

echo json_encode(['ok' => true, 'html' => captcha_html()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
