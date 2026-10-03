<?php

/**
 * 命令行安装 / 升级脚本
 *
 * 为什么不做 Web 安装向导：
 * 原版的 install/index.php 有一个未授权的 action=reset_lock，它会删掉锁文件，
 * 随后匿名访客就能对线上站点重新执行安装——填一个自己控制的数据库地址，
 * 就能写入一个 super 账号，并把 config.php 里的 app_key 换掉（于是所有访客
 * 工单链接一并失效）。这个洞的根因不是某个校验写漏了，而是「让 Web 请求
 * 去写配置、建表、建管理员」这件事本身。改成 CLI 之后整类风险消失：
 * 安装需要服务器 shell 权限，而那正是能重装系统的人本来就有的权限。
 *
 * 用法（在项目根目录）：
 *   php bin/setup.php                      # 安装或升级，并创建超管
 *   php bin/setup.php --admin=name --pass=xxx --email=a@b.com
 *   php bin/setup.php --seed-only          # 只补种子数据，不动管理员
 *   php bin/setup.php --force              # 结构已存在时仍然重跑（幂等）
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本脚本只能在命令行下运行。\n");
}

$appRoot = dirname(__DIR__);
require $appRoot . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Domain\Staff\Role;

/** @var Database $db */
$db = App\Core\App::container()->get(Database::class);

// ---------------- 参数 ----------------
$options = getopt('', ['admin::', 'pass::', 'email::', 'name::', 'seed-only', 'force', 'help']);

if (isset($options['help'])) {
    echo "用法: php bin/setup.php [选项]\n\n";
    echo "  --admin=用户名    超级管理员登录名（默认 admin）\n";
    echo "  --pass=密码       超级管理员密码（默认随机生成并打印）\n";
    echo "  --email=邮箱      超级管理员邮箱（可选）\n";
    echo "  --name=姓名       超级管理员显示名（可选）\n";
    echo "  --seed-only       只导入种子数据与结构，不创建/修改管理员\n";
    echo "  --force           表已存在时仍继续（结构脚本本身是幂等的）\n";
    exit(0);
}

$adminUser = isset($options['admin']) && is_string($options['admin']) && $options['admin'] !== '' ? $options['admin'] : 'admin';
$adminPass = isset($options['pass']) && is_string($options['pass']) && $options['pass'] !== '' ? $options['pass'] : '';
$adminEmail = isset($options['email']) && is_string($options['email']) ? $options['email'] : '';
$adminName = isset($options['name']) && is_string($options['name']) ? $options['name'] : '超级管理员';
$seedOnly = isset($options['seed-only']);
$generatedPassword = false;

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, "\n[失败] " . $message . PHP_EOL);
    exit(1);
}

out('');
out('  Roblox 查询机器人 · 工单与知识库系统  v' . Config::string('app.version', '2.0.0'));
out('  ─────────────────────────────────────────────');

// ---------------- 1. 环境自检 ----------------
out('');
out('[1/6] 环境自检');

$checks = [
    'PHP 版本 >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO 扩展' => extension_loaded('pdo'),
    'pdo_mysql 扩展' => extension_loaded('pdo_mysql'),
    'mbstring 扩展' => extension_loaded('mbstring'),
    'json 扩展' => extension_loaded('json'),
    'openssl 扩展（SMTP over TLS 需要）' => extension_loaded('openssl'),
    'password_hash 可用' => function_exists('password_hash'),
];
$envOk = true;
foreach ($checks as $label => $ok) {
    out(sprintf('      %s %s', $ok ? '✔' : '✘', $label));
    if (!$ok) {
        $envOk = false;
    }
}
if (!$envOk) {
    fail('环境不满足最低要求，请先补齐上面标 ✘ 的项。');
}

// 目录可写性：上传与日志目录必须可写，否则附件与错误日志会静默丢失
foreach (['storage/uploads' => '附件目录', 'storage/logs' => '日志目录'] as $rel => $label) {
    $dir = $appRoot . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail($label . '创建失败：' . $dir);
    }
    if (!is_writable($dir)) {
        fail($label . '不可写：' . $dir);
    }
    out(sprintf('      ✔ %s可写 (%s)', $label, $rel));
}

// ---------------- 2. 生成 app_key ----------------
out('');
out('[2/6] 应用密钥');

$existingKey = Config::string('app.key', '');
if ($existingKey === '') {
    $newKey = bin2hex(random_bytes(32));
    $localFile = $appRoot . '/config/local.php';
    if (!is_file($localFile)) {
        fail('缺少 config/local.php，请先从 config/local.example.php 复制一份。');
    }
    $content = (string)file_get_contents($localFile);
    // 只替换单引号内的空值，避免误伤其它配置
    $patched = preg_replace("/('key'\s*=>\s*)''/", "$1'" . $newKey . "'", $content, 1, $count);
    if ($patched === null || $count === 0) {
        fail("无法自动写入 app_key，请手工编辑 config/local.php，把 'key' 设为一段随机字符串。");
    }
    if (@file_put_contents($localFile, $patched) === false) {
        fail('无法写入 config/local.php，请检查文件权限。');
    }
    @chmod($localFile, 0640);
    Config::set('app.key', $newKey);
    out('      ✔ 已生成 app_key 并写入 config/local.php（权限 640）');
} else {
    out('      ✔ 已存在 app_key，保持不变');
    out('        （更换它会立即使所有访客工单链接失效，非必要不要动）');
}

// ---------------- 3. 数据库连接 ----------------
out('');
out('[3/6] 数据库连接');

$dbCfg = (array)Config::get('db', []);
out(sprintf('      主机 %s:%s  库名 %s  账号 %s',
    (string)($dbCfg['host'] ?? ''),
    (string)($dbCfg['port'] ?? ''),
    (string)($dbCfg['name'] ?? ''),
    (string)($dbCfg['user'] ?? '')
));

try {
    $db->pdo();
} catch (Throwable $e) {
    fail("数据库连接失败：\n        " . $e->getMessage() . "\n"
        . "        请检查 config/local.php 中的 db 配置，并确认该账号已获得库 " . (string)($dbCfg['name'] ?? '') . " 的全部权限。");
}
$charset = (string)$db->value('SELECT @@character_set_database');
$version = (string)$db->value('SELECT VERSION()');
out('      ✔ 连接成功（MySQL ' . $version . '，库字符集 ' . $charset . '）');

if (stripos($charset, 'utf8mb4') !== 0) {
    out('      ! 库字符集不是 utf8mb4，中文与 emoji 可能出错。建议：');
    out('        ALTER DATABASE `' . (string)($dbCfg['name'] ?? '') . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
}

// ---------------- 4. 建表 ----------------
out('');
out('[4/6] 数据库结构');

$schemaFile = $appRoot . '/database/schema.sql';
if (!is_file($schemaFile)) {
    fail('找不到 database/schema.sql');
}

$alreadyInstalled = $db->tableExists('ticket') && $db->tableExists('settings');
if ($alreadyInstalled && !isset($options['force'])) {
    out('      检测到结构已存在，跳过建表（如需重建请加 --force，脚本本身是幂等的）');
} else {
    $sql = (string)file_get_contents($schemaFile);
    $statements = splitSqlStatements($sql);
    $done = 0;
    foreach ($statements as $stmt) {
        try {
            $db->pdo()->exec($stmt);
            $done++;
        } catch (Throwable $e) {
            fail("执行 SQL 失败：\n        " . mb_substr($stmt, 0, 200) . "\n        " . $e->getMessage());
        }
    }
    out('      ✔ 已执行 ' . $done . ' 条结构语句');
}

// 记录一次结构版本，便于日后判断是否需要升级
$db->query(
    'INSERT INTO `schema_migration` (`version`) VALUES (?) ON DUPLICATE KEY UPDATE `applied_at` = `applied_at`',
    ['2.0.0-base']
);

$tables = array_map(
    static fn(array $r): string => (string)array_values($r)[0],
    $db->all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')
);
out('      ✔ 当前共 ' . count($tables) . ' 张表：' . implode(', ', $tables));

// 结构就绪，现在可以安全地加载路由与视图数据（它们会读 settings 表）。
// 正常请求由 bootstrap.php 自动完成这一步；命令行下由这里显式触发。
App\Core\App::container()->get('app.boot')->run();

// ---------------- 5. 种子数据 ----------------
$seed = require $appRoot . '/database/seed.php';

out('');
out('[5/6] 初始数据');

// 分类：以 slug 为唯一键做 upsert，重复运行不会产生重复分类
$catIdBySlug = [];
$catInserted = 0;
foreach ($seed['categories'] as $c) {
    $existing = $db->value('SELECT `id` FROM `category` WHERE `slug` = ?', [$c['slug']]);
    if ($existing !== null) {
        $catIdBySlug[$c['slug']] = (int)$existing;
        continue;
    }
    $id = $db->insert(
        'INSERT INTO `category` (`name`, `slug`, `icon`, `color`, `description`, `is_ticket`, `is_faq`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, 1, 1, ?, 1)',
        [$c['name'], $c['slug'], $c['icon'], $c['color'], $c['desc'], $c['sort']]
    );
    $catIdBySlug[$c['slug']] = $id;
    $catInserted++;
}
out('      ✔ 分类：新增 ' . $catInserted . ' 个，共 ' . count($catIdBySlug) . ' 个');

// FAQ：以 question 为唯一键做 upsert
$faqInserted = 0;
$faqSkipped = 0;
foreach ($seed['faqs'] as $f) {
    $exists = $db->int('SELECT COUNT(*) FROM `faq` WHERE `question` = ?', [$f['q']]);
    if ($exists > 0) {
        $faqSkipped++;
        continue;
    }
    $db->query(
        'INSERT INTO `faq` (`category_id`, `question`, `answer`, `keywords`, `is_hot`, `is_top`, `sort`, `status`)
         VALUES (?, ?, ?, ?, ?, ?, 0, 1)',
        [
            $catIdBySlug[$f['cat']] ?? 0,
            $f['q'],
            $f['a'],
            $f['kw'],
            !empty($f['hot']) ? 1 : 0,
            !empty($f['top']) ? 1 : 0,
        ]
    );
    $faqInserted++;
}
out('      ✔ 知识库：新增 ' . $faqInserted . ' 条，已存在 ' . $faqSkipped . ' 条');

// 站点配置：只补缺失的键，不覆盖管理员已经改过的值
$settingInserted = 0;
foreach ($seed['settings'] as $k => $v) {
    $exists = $db->int('SELECT COUNT(*) FROM `settings` WHERE `k` = ?', [(string)$k]);
    if ($exists > 0) {
        continue;
    }
    $db->query('INSERT INTO `settings` (`k`, `v`) VALUES (?, ?)', [(string)$k, (string)$v]);
    $settingInserted++;
}
out('      ✔ 站点配置：新增 ' . $settingInserted . ' 项，共 ' . $db->int('SELECT COUNT(*) FROM `settings`') . ' 项');

// ---------------- 6. 管理员 ----------------
out('');
out('[6/6] 超级管理员');

$staffCount = $db->int('SELECT COUNT(*) FROM `staff`');
if ($seedOnly) {
    out('      已指定 --seed-only，跳过');
} elseif ($staffCount > 0) {
    out('      已存在 ' . $staffCount . ' 个后台账号，跳过创建');
    out('      （需要新增请登录后台 → 成员管理，或使用 --admin 指定新账号名）');
} else {
    if ($adminPass === '') {
        // 随机密码：避免出现 admin/admin 这种一装就等着被扫的默认口令
        $adminPass = bin2hex(random_bytes(6)) . random_int(10, 99);
        $generatedPassword = true;
    }
    if (mb_strlen($adminPass) < 8) {
        fail('管理员密码至少 8 位。');
    }
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUser)) {
        fail('管理员登录名只能是 3–32 位字母、数字或下划线。');
    }
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        fail('管理员邮箱格式不正确。');
    }

    $db->query(
        'INSERT INTO `staff` (`username`, `password`, `realname`, `email`, `role`, `status`)
         VALUES (?, ?, ?, ?, ?, 1)',
        [$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminName, $adminEmail, Role::SUPER]
    );
    out('      ✔ 已创建超级管理员：' . $adminUser);
}

// ---------------- 完成 ----------------
out('');
out('  ─────────────────────────────────────────────');
out('  安装完成');
out('');
out('  后台地址：/admin/login');
out('  前台首页：/');
if ($generatedPassword) {
    out('');
    out('  ┌──────────────────────────────────────────┐');
    out('  │ 管理员账号：' . str_pad($adminUser, 28) . '│');
    out('  │ 初始密码　：' . str_pad($adminPass, 28) . '│');
    out('  └──────────────────────────────────────────┘');
    out('  请立即登录并在「我的账号」中修改密码。此密码只显示这一次。');
}
out('');
out('  部署提示：');
out('    • 确保 nginx 已按 deploy/nginx.conf.example 配置：');
out('      屏蔽 /app /config /database /storage /bin 目录，');
out('      设置 client_max_body_size 不小于上传上限，');
out('      并禁止 /storage/uploads 下的脚本执行。');
out('    • 把站点根目录设为项目下的 public/。');
out('    • config/local.php 权限建议 640。');
out('');
out('  维护命令：php bin/cron.php   # 清理过期验证码与旧日志（建议每天一次）');
out('');

/**
 * 把 SQL 文件拆成可逐条执行的语句。
 *
 * 原版的拆法是「按 ; 直接 explode」，只要 SQL 里出现行内 -- 注释或
 * COMMENT 字符串里带分号就会拆错。这里按字符扫描，正确处理：
 *   - 单引号 / 双引号 / 反引号字符串（含转义）
 *   - -- 与 # 行注释
 *   - /* ... *\/ 块注释
 *   - BEGIN...END 之外的普通语句边界
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // 行注释
        if ($ch === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        // 块注释
        if ($ch === '/' && $next === '*') {
            $i += 2;
            while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i += 2;
            continue;
        }
        // 字符串字面量：原样保留，内部的 ; 不当作语句分隔
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buffer .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                $buffer .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buffer .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $quote) {
                    // 连续两个引号是转义写法
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buffer .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        // 语句结束
        if ($ch === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            $i++;
            continue;
        }

        $buffer .= $ch;
        $i++;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}
