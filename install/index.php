<?php
/**
 * 网页安装向导
 * 访问 /install/index.php 即可完成安装
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root     = dirname(__DIR__);
$lockFile = __DIR__ . '/.installed';
$step     = (int)($_GET['step'] ?? 0);
$errors   = [];
$old      = ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'ticket', 'db_user' => 'root', 'db_pass' => '', 'db_prefix' => 'tk_', 'admin_user' => 'admin', 'site_name' => 'Roblox 查询机器人 · 工单中心'];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function test_db(array $d): array
{
    $errs = [];
    if (trim($d['db_name']) === '') $errs[] = '数据库名不能为空';
    if (trim($d['db_user']) === '') $errs[] = '数据库用户名不能为空';
    if (mb_strlen($d['db_name']) > 60) $errs[] = '数据库名过长';

    $ext = array_map('strtolower', array_filter(get_loaded_extensions()));
    $miss = [];
    if (!in_array('pdo', $ext, true))                 $miss[] = 'pdo';
    if (!in_array('pdo_mysql', $ext, true))           $miss[] = 'pdo_mysql';
    if (!in_array('mbstring', $ext, true))            $miss[] = 'mbstring';
    if (!function_exists('password_hash'))            $miss[] = 'password 支持';
    if (!function_exists('mail'))                     $miss[] = 'mail()';
    if ($miss) $errs[] = '缺少 PHP 扩展/函数：' . implode('、', $miss);

    if ($errs) return $errs;

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $d['db_host'], (int)$d['db_port']);
        $pdo = new PDO($dsn, $d['db_user'], $d['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $exists = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($d['db_name']))->fetch();
        if (!$exists) {
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $d['db_name']) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $pdo->exec('USE `' . str_replace('`', '', $d['db_name']) . '`');
        return [];
    } catch (PDOException $e) {
        return ['数据库连接失败：' . $e->getMessage()];
    }
}

function run_install(array $d, array $admin): array
{
    $errs = test_db($d);
    if ($errs) return $errs;

    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $admin['admin_user'])) {
        $errs[] = '管理员账号需为 3-32 位字母、数字或下划线';
    }
    if (mb_strlen($admin['admin_pass']) < 6)  $errs[] = '管理员密码至少 6 位';
    if ($admin['admin_pass'] !== $admin['admin_pass2']) $errs[] = '两次输入的密码不一致';
    if ($errs) return $errs;

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['db_host'], (int)$d['db_port'], $d['db_name']),
        $d['db_user'], $d['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $d['db_prefix']) ?: 'tk_';
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        return ['无法读取 install/schema.sql'];
    }
    // 去掉整行注释后按分号切分
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        if (!str_starts_with(trim($line), '--')) {
            $lines[] = $line;
        }
    }
    $sql = str_replace('`tk_', '`' . $prefix, implode("\n", $lines));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // 已存在的表跳过，便于「锁文件丢失后重装」时复用库
            if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1050) {
                continue;   // 1050 = Table already exists
            }
            return ['建表失败：' . $e->getMessage()];
        }
    }

    // 写入配置
    $appKey = bin2hex(random_bytes(16));
    $conf   = "<?php\n/**\n * 全局配置文件（由安装向导生成）\n */\nreturn " . var_export([
        'installed' => true,
        'app_key'   => (string)$appKey,
        'db'        => [
            'host'   => (string)$d['db_host'],
            'port'   => (int)$d['db_port'],
            'name'   => (string)$d['db_name'],
            'user'   => (string)$d['db_user'],
            'pass'   => (string)$d['db_pass'],
            'prefix' => (string)$prefix,
        ],
    ], true) . ";\n";
    $cfgFile = dirname(__DIR__) . '/config/config.php';
    if (@file_put_contents($cfgFile, $conf) === false) {
        return ['无法写入 config/config.php，请赋予该文件写权限'];
    }
    // 回读校验：写入成功不等于内容可用。若被 open_basedir / 磁盘满 /
    // 权限不足等情况截断，回读会失败或内容不完整，此时必须报错而不是
    // 继续往下走——否则会留下「锁文件已写、配置却是 installed=false」
    // 的状态，访问任何页面都会被弹回安装向导，形成死循环。
    clearstatcache(true, $cfgFile);
    $written = @file_get_contents($cfgFile);
    if ($written === false || strpos($written, "'installed' => true") === false) {
        @unlink($cfgFile);
        return ['config/config.php 写入后校验失败（内容不完整），已删除该文件，请检查目录写权限与磁盘空间后重试'];
    }

    // 载入种子
    $seed = require __DIR__ . '/seed.php';

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'admin` (username,password,realname,email,role,status) VALUES (?,?,?,?,?,1)');
        $st->execute([$admin['admin_user'], password_hash($admin['admin_pass'], PASSWORD_DEFAULT), $admin['admin_name'] ?: '超级管理员', $admin['admin_email'], 'super']);

        $catIds = [];
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'category` (name,slug,icon,color,description,is_ticket,is_faq,sort,status) VALUES (?,?,?,?,?,1,1,?,1)');
        foreach ($seed['categories'] as $c) {
            $slug = $c['slug'] ?? '';
            $st->execute([
                $c['name'] ?? '',
                $slug,
                $c['icon'] ?? '📁',
                $c['color'] ?? '#4f46e5',
                $c['description'] ?? '',
                (int)($c['sort'] ?? 0),
            ]);
            $catIds[$slug] = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'faq` (category_id,question,answer,keywords,is_hot,is_top,sort,status) VALUES (?,?,?,?,?,?,1,1)');
        $sort = 0;
        foreach ($seed['faqs'] as $f) {
            // hot / top 允许省略，默认 0
            $st->execute([
                $catIds[$f['cat'] ?? ''] ?? 0,
                $f['q'] ?? '',
                $f['a'] ?? '',
                $f['kw'] ?? '',
                (int)($f['hot'] ?? 0),
                (int)($f['top'] ?? 0),
            ]);
            $sort += 10;
            $pdo->exec('UPDATE `' . $prefix . 'faq` SET sort = ' . $sort . ' WHERE id = ' . (int)$pdo->lastInsertId());
        }

        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
        foreach ($seed['settings'] as $k => $v) {
            $st->execute([$k, (string)$v]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['写入初始数据失败：' . $e->getMessage()];
    }

    // 附件目录
    $up = dirname(__DIR__) . '/uploads';
    if (!is_dir($up)) @mkdir($up, 0755, true);
    @file_put_contents($up . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php3|php4|php5)$\">\n    Require all denied\n</FilesMatch>\n");
    @file_put_contents($up . '/index.html', '');

    // 错误日志目录：线上运行时若不存在，PHP 会静默丢弃日志
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/.htaccess', "Require all denied\n");
    @file_put_contents($logDir . '/index.html', '');

    // 落库结果校验：数据没真正写入时不写锁文件，
    // 否则会留下「有锁但没装好」的状态，安装向导再也无法重跑。
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'admin`')->fetchColumn();
    if ($adminCount < 1) {
        return ['安装未完成：管理员数据写入失败，请清空数据库后重试'];
    }

    @file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

    return [];
}

/**
 * 判定系统是否真的安装完成。
 *
 * 不能只看 install/.installed 锁文件：配置文件写入失败时锁文件可能已存在，
 * 此时访问任何页面都会被 bootstrap 弹回安装向导，而安装页又因锁文件显示
 * 「安装完成」，形成死循环。这里以配置文件中的 installed 为准。
 */
function tk_is_installed(string $cfgFile, string $lockFile): bool
{
    if (!is_file($lockFile) || !is_file($cfgFile)) {
        return false;
    }
    $cfg = @include $cfgFile;
    return is_array($cfg) && !empty($cfg['installed']);
}

$msg       = '';
$cfgFile   = dirname(__DIR__) . '/config/config.php';
$installed = tk_is_installed($cfgFile, $lockFile);
$lockOnly  = is_file($lockFile) && !$installed;

if ($installed) {
    // 正常安装态：直接跳完成页，不再显示表单
    $step = 3;
    $msg  = '系统已安装。如需重新安装，请删除 install/.installed 文件并清空数据库。';
} elseif ($lockOnly) {
    // 异常态：锁文件在但配置未生效。必须如实告知并给出可执行修复，
    // 否则「谎报安装完成 → 进后台被弹回 → 再谎报」会形成死循环。
    $step = 3;
    $msg  = '检测到安装状态异常：install/.installed 存在，但 config/config.php 中 installed 不为 true。'
          . '这通常是因为上一次安装时配置文件写入失败。请点击下方按钮清除锁文件并重新安装。';
}

// 异常状态下的「一键重置」：只删锁文件并把步骤退回表单页，不碰数据库。
// 供用户在网页上自救，避免必须 SSH 才能重新安装。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_lock') {
    @unlink($lockFile);
    clearstatcache(true, $lockFile);
    if (is_file($lockFile)) {
        $msg      = '锁文件删除失败，请手动删除 install/.installed 后刷新本页。';
        $lockOnly = true;
    } else {
        // 锁已清除，回到安装表单
        $msg       = '';
        $lockOnly  = false;
        $installed = false;
        $step      = 0;
    }
}

// 已安装（锁文件在 或 配置里 installed=true）时一律拒绝重装。
// 原条件只判断锁文件，删掉锁文件就能重装，会把 app_key、数据库密码、
// 管理员账号和全部种子数据重置，属于严重隐患。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed) {
    $msg = '系统已安装，不允许重复安装。如需重装，请同时删除 install/.installed '
         . '并将 config/config.php 中的 installed 改为 false。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_file($lockFile)) {
    $old = [
        'db_host' => trim($_POST['db_host'] ?? '127.0.0.1'),
        'db_port' => (int)($_POST['db_port'] ?? 3306),
        'db_name' => trim($_POST['db_name'] ?? 'ticket'),
        'db_user' => trim($_POST['db_user'] ?? 'root'),
        'db_pass' => (string)($_POST['db_pass'] ?? ''),
        'db_prefix' => trim($_POST['db_prefix'] ?? 'tk_'),
        'site_name' => trim($_POST['site_name'] ?? 'Roblox 查询机器人 · 工单中心'),
        'admin_user' => trim($_POST['admin_user'] ?? 'admin'),
        'admin_pass' => (string)($_POST['admin_pass'] ?? ''),
        'admin_pass2' => (string)($_POST['admin_pass2'] ?? ''),
        'admin_name' => trim($_POST['admin_name'] ?? ''),
        'admin_email' => trim($_POST['admin_email'] ?? ''),
    ];
    $errors = run_install($old, $old);
    if (!$errors) {
        session_start();
        $_SESSION['install_admin'] = $old['admin_user'];
        header('Location: index.php?step=3');
        exit;
    }
    $step = 1;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 · 工单系统</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81 50%,#4c1d95);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;color:#1f2937}
.wrap{width:100%;max-width:720px}
.card{background:#fff;border-radius:20px;box-shadow:0 30px 70px rgba(0,0,0,.35);overflow:hidden}
.head{background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:34px 40px;color:#fff}
.head h1{font-size:23px;font-weight:700;letter-spacing:.5px}
.head p{color:#ddd6fe;font-size:13px;margin-top:8px}
.steps{display:flex;gap:6px;padding:20px 40px 0}
.steps div{flex:1;height:4px;border-radius:3px;background:#e5e7eb;font-size:0}
.steps div.on{background:linear-gradient(90deg,#4f46e5,#7c3aed)}
.body{padding:30px 40px 38px}
h2.s{font-size:16px;color:#111827;margin-bottom:6px}
p.d{font-size:13px;color:#6b7280;margin-bottom:22px}
label{display:block;font-size:13px;font-weight:600;color:#374151;margin:16px 0 7px}
input{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;outline:none;transition:.18s;font-family:inherit}
input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.13)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}
.grid .full{grid-column:1/-1}
.hint{font-size:12px;color:#9ca3af;margin-top:6px}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:13px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;line-height:1.8}
.err ul{padding-left:18px}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;padding:14px 18px;border-radius:10px;font-size:13.5px;margin-bottom:20px;line-height:1.8}
.chk{background:#f9fafb;border-radius:14px;padding:20px 24px;margin-top:8px}
.chk h3{font-size:14px;color:#111827;margin-bottom:12px}
.chk li{list-style:none;font-size:13px;color:#4b5563;padding:6px 0;display:flex;justify-content:space-between}
.pass{font-weight:700}
.pass.y{color:#059669}.pass.n{color:#dc2626}
button{width:100%;padding:14px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border:0;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;margin-top:26px;transition:.2s;font-family:inherit}
button:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(79,70,229,.4)}
.foot{text-align:center;font-size:12px;color:#9ca3af;margin-top:20px}
.note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 16px;border-radius:10px;font-size:12.5px;margin-top:18px;line-height:1.75}
.links a{display:inline-block;margin:0 8px;padding:11px 24px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
.l1{background:#4f46e5;color:#fff}.l2{background:#f3f4f6;color:#4b5563}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
  <div class="head">
    <h1>工单系统 · 安装向导</h1>
    <p>Roblox 查询机器人配套工单与知识库系统</p>
  </div>
  <div class="steps">
    <div class="<?= $step >= 0 ? 'on' : '' ?>"></div>
    <div class="<?= $step >= 1 ? 'on' : '' ?>"></div>
    <div class="<?= $step >= 2 ? 'on' : '' ?>"></div>
    <div class="<?= $step >= 3 ? 'on' : '' ?>"></div>
  </div>
  <div class="body">

<?php if ($step === 3): ?>
<?php if ($lockOnly): ?>
  <h2 class="s">⚠️ 安装状态异常</h2>
  <div class="err"><ul><li><?= h($msg) ?></li></ul></div>
  <p class="d">若数据库里已有残留的表，安装脚本会自动跳过已存在的表，不会中断。</p>
  <form method="post" onsubmit="return confirm('确定清除安装锁并重新安装？此操作不会删除数据库中的数据。')">
    <input type="hidden" name="action" value="reset_lock">
    <button type="submit">清除锁文件并重新安装</button>
  </form>
  <div class="note">若按钮无效，请让服务器管理员删除 <code>install/.installed</code> 文件后刷新本页。</div>
<?php else: ?>
  <h2 class="s">🎉 安装完成</h2>
  <p class="d">系统已就绪，请妥善保管以下信息。</p>
  <div class="ok">
    后台地址：<strong>/admin/login.php</strong><br>
    前台地址：<strong>/</strong><br>
    管理员账号：<strong><?= h($_SESSION['install_admin'] ?? 'admin') ?></strong><?php unset($_SESSION['install_admin']); ?>
  </div>
  <div class="note">安全提示：建议现在删除 <code>install</code> 目录，或删除其中的 <code>.installed</code> 锁定文件以防他人重装。如需修改数据库配置，可直接编辑 <code>config/config.php</code>。</div>
  <div class="links" style="text-align:center;margin-top:26px">
    <a class="l1" href="../admin/login.php">进入后台</a>
    <a class="l2" href="../index.php">访问前台</a>
  </div>
<?php endif; ?>
<?php else: ?>

  <?php if ($errors): ?>
  <div class="err"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <h2 class="s">① 环境检测与数据库配置</h2>
  <p class="d">请填写 MySQL 连接信息。若数据库不存在，系统会自动创建。</p>

  <form method="post" autocomplete="off">
    <div class="grid">
      <div><label>数据库地址</label><input name="db_host" value="<?= h($old['db_host']) ?>" required></div>
      <div><label>端口</label><input name="db_port" type="number" value="<?= h((string)$old['db_port']) ?>" required></div>
      <div class="full"><label>数据库名</label><input name="db_name" value="<?= h($old['db_name']) ?>" required><div class="hint">不存在时自动创建，字符集 utf8mb4</div></div>
      <div><label>数据库用户</label><input name="db_user" value="<?= h($old['db_user']) ?>" required></div>
      <div><label>数据库密码</label><input name="db_pass" type="password" value="<?= h($old['db_pass']) ?>"></div>
      <div class="full"><label>表前缀</label><input name="db_prefix" value="<?= h($old['db_prefix']) ?>"></div>
      <div class="full"><label>站点名称</label><input name="site_name" value="<?= h($old['site_name']) ?>"></div>
    </div>

    <div class="chk">
      <h3>环境检测</h3>
      <ul>
        <li><span>PHP 版本</span><span class="pass <?= PHP_VERSION_ID >= 80100 ? 'y' : 'n' ?>"><?= PHP_VERSION ?> <?= PHP_VERSION_ID >= 80100 ? '✓' : '（建议 8.1+）' ?></span></li>
        <li><span>PDO / pdo_mysql</span><span class="pass <?= extension_loaded('pdo_mysql') ? 'y' : 'n' ?>"><?= extension_loaded('pdo_mysql') ? '已安装' : '未安装' ?></span></li>
        <li><span>mbstring</span><span class="pass <?= extension_loaded('mbstring') ? 'y' : 'n' ?>"><?= extension_loaded('mbstring') ? '已安装' : '未安装' ?></span></li>
        <li><span>GD（图片处理，可选）</span><span class="pass"><?= extension_loaded('gd') ? '已安装' : '未安装（不影响使用）' ?></span></li>
        <li><span>mail() 邮件函数</span><span class="pass <?= function_exists('mail') ? 'y' : 'n' ?>"><?= function_exists('mail') ? '可用' : '不可用' ?></span></li>
        <li><span>config 目录可写</span><span class="pass <?= is_writable($root . '/config') ? 'y' : 'n' ?>"><?= is_writable($root . '/config') ? '可写' : '不可写' ?></span></li>
        <li><span>uploads 目录</span><span class="pass <?= is_dir($root . '/uploads') && is_writable($root . '/uploads') ? 'y' : 'n' ?>"><?= is_dir($root . '/uploads') && is_writable($root . '/uploads') ? '就绪' : '不存在（将自动创建）' ?></span></li>
      </ul>
    </div>

    <h2 class="s" style="margin-top:30px">② 管理员账号</h2>
    <p class="d">用于登录后台管理系统工单、FAQ 与邮件设置。</p>
    <div class="grid">
      <div><label>登录账号</label><input name="admin_user" value="<?= h($old['admin_user']) ?>" required></div>
      <div><label>管理员昵称</label><input name="admin_name" value="<?= h($old['admin_name'] ?? '') ?>"></div>
      <div><label>登录密码</label><input name="admin_pass" type="password" required></div>
      <div><label>确认密码</label><input name="admin_pass2" type="password" required></div>
      <div class="full"><label>管理员邮箱</label><input name="admin_email" type="email" value="<?= h($old['admin_email'] ?? '') ?>"></div>
    </div>

    <button type="submit">开始安装</button>
  </form>
<?php endif; ?>

  </div>
</div>
<div class="foot">工单系统 v1.0 · PHP <?= PHP_VERSION ?></div>
</div>
</body>
</html>
