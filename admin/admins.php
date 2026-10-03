<?php
/** 技术管理员管理 —— 仅超管可任免 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$STAFF_ROLE = 'admin';
require __DIR__ . '/staff_lib.php';
