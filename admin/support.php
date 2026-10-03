<?php
/** 客服管理 —— 主管与超管可任免客服 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$STAFF_ROLE = 'operator';
require __DIR__ . '/staff_lib.php';
