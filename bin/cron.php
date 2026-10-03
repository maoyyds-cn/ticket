<?php

/**
 * 定时维护脚本
 *
 * 用法（建议加入 crontab，每天一次即可）：
 *   0 4 * * *  cd /www/wwwroot/t.ili.ink && php bin/cron.php >> storage/logs/cron.log 2>&1
 *
 * 清理三类会无限增长的数据。旧版没有这个脚本，结果是：
 *   - email_code 表只增不减；
 *   - admin_session 表只写不读，也从不清理；
 *   - ticket_log 只能靠超管在后台点按钮清理（而那个按钮还有清空全表的隐患）。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本脚本只能在命令行下运行。\n");
}

$appRoot = dirname(__DIR__);
require $appRoot . '/bootstrap.php';

use App\Core\Application;
use App\Core\Database;

// 命令行下 bootstrap 不会自动完成最后装配，这里显式触发
// （清理任务本身不需要路由，但保持与 Web 请求一致的初始化状态，
//   便于将来在这里新增需要应用服务的任务）。
App\Core\App::container()->get('app.boot')->run();

/** @var Database $db */
$db = App\Core\App::container()->get(Database::class);

$start = microtime(true);
$lines = [];

// 1. 过期邮箱验证码：保留 24 小时，足够覆盖 10 分钟有效期与排障需要
$codes = $db->affected('DELETE FROM `email_code` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
$lines[] = '过期验证码清理：' . $codes . ' 条';

// 2. 后台登录记录：保留 90 天。这张表从前只写不读，
//    既没有界面展示也从不清理，属于纯粹的增长项；现在它被「我的账号」
//    页面用上了，因此保留一段时间是有意义的。
$sessions = $db->affected('DELETE FROM `staff_session` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)');
$lines[] = '后台登录记录清理：' . $sessions . ' 条';

// 3. 邮件发送记录：保留 90 天
$mails = $db->affected('DELETE FROM `mail_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)');
$lines[] = '邮件发送记录清理：' . $mails . ' 条';

// 4. 操作日志：保留 365 天。
//    刻意给一个很长的默认值——这是审计线索，不该被自动清理得太积极；
//    需要更短保留期时由管理员在后台显式选择。
$logs = $db->affected('DELETE FROM `ticket_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 365 DAY)');
$lines[] = '操作日志清理：' . $logs . ' 条';

// 5. 孤儿附件目录（磁盘上存在但没有数据库记录引用的文件）
$uploadDir = (string)\App\Core\Config::string('app.upload_dir');
$orphans = 0;
if (is_dir($uploadDir)) {
    // 收集所有被引用的相对路径
    $referenced = [];
    foreach ($db->all('SELECT `attachments` FROM `ticket` WHERE `attachments` IS NOT NULL') as $row) {
        $items = json_decode((string)$row['attachments'], true);
        if (is_array($items)) {
            foreach ($items as $it) {
                if (is_array($it) && !empty($it['path'])) {
                    $referenced[(string)$it['path']] = true;
                }
            }
        }
    }
    foreach ($db->all('SELECT `attachments` FROM `ticket_reply` WHERE `attachments` IS NOT NULL') as $row) {
        $items = json_decode((string)$row['attachments'], true);
        if (is_array($items)) {
            foreach ($items as $it) {
                if (is_array($it) && !empty($it['path'])) {
                    $referenced[(string)$it['path']] = true;
                }
            }
        }
    }

    // 只清理「按年月命名的子目录」下的文件，且必须超过 7 天，
    // 避免误删刚上传但事务已回滚的文件
    foreach (glob(rtrim($uploadDir, '/\\') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $rel = basename($dir) . '/' . basename($file);
            if (isset($referenced[$rel])) {
                continue;
            }
            if (filemtime($file) > time() - 7 * 86400) {
                continue;
            }
            if (@unlink($file)) {
                $orphans++;
            }
        }
    }
}
$lines[] = '孤儿附件清理：' . $orphans . ' 个文件';

$elapsed = round((microtime(true) - $start) * 1000);
$summary = '[' . date('Y-m-d H:i:s') . '] ' . implode('；', $lines) . '（耗时 ' . $elapsed . 'ms）';

echo $summary . PHP_EOL;
Application::log($summary, 'cron.log');
