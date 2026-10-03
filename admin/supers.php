<?php
/** 超级管理员管理 —— 仅超管本人可进入，用于新增/编辑其他超管账号 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_super();
$STAFF_ROLE = 'super';
require __DIR__ . '/staff_lib.php';
