<?php
/** 后台退出 */
require __DIR__ . '/../includes/bootstrap.php';
admin_logout();
redirect('login.php');
