<?php
/** 退出登录 */
require __DIR__ . '/includes/bootstrap.php';
user_logout();
flash('info', '已退出登录');
redirect('index.php');
