<?php
/** 主管管理 —— 仅超管可任免 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$STAFF_ROLE = 'supervisor';
require __DIR__ . '/staff_lib.php';
