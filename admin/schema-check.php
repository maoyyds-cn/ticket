<?php
/**
 * 数据库结构自检 + 一键升级
 *
 * 为什么要做这个页面：本次新增了 role 枚举值与 ticket_reply.author_role 列。
 * 若用户没执行 install/upgrade-roles.sql，后台工单详情页的回复 INSERT 会直接 500
 * （Unknown column 'author_role'），而错误只在日志里，现场很难定位。
 * 前台因走 SELECT r.* 加 ?? 兜底不受影响，只有后台会白屏。
 * 这里在进入依赖页面之前先比对结构，缺什么就当场报出来，并提供一键补齐。
 *
 * 表前缀从 config 读取，不写死 tk_，避免用户手工改 SQL 时踩空。
 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_super();

/** 把秒数格式化成「8 小时」/「5 小时 30 分」，用于时区偏移的展示 */
function tz_text(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0 && $m > 0) {
        return $h . ' 小时 ' . $m . ' 分';
    }
    return $h > 0 ? $h . ' 小时' : $m . ' 分钟';
}

/** 需要的结构：表 => 必需列 */
function schema_required(): array
{
    return [
        'admin'       => ['id', 'username', 'password', 'realname', 'email', 'role', 'status'],
        'admin_session' => ['id', 'admin_id'],
        'ticket'      => ['id', 'ticket_no', 'status', 'assignee_id'],
        // author_role 是本次新增；缺它工单会话页会直接 500
        'ticket_reply' => ['id', 'ticket_id', 'admin_id', 'author_name', 'author_role', 'content'],
        'category'    => ['id', 'name', 'is_ticket'],
        'user'        => ['id', 'username', 'password'],
        'settings'    => ['skey', 'svalue'],
        // 发信日志：通知邮件改为异步发送后，失败与否只能看这张表，
        // 缺表等于完全没有发信可观测性
        'mail_log'    => ['id', 'to_email', 'subject', 'status', 'error', 'created_at'],
    ];
}

/** role 枚举是否已含全部五种角色 */
function schema_role_ready(): array
{
    $want = ['super', 'admin', 'supervisor', 'engineer', 'operator'];
    $type = (string)db_one(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB_PRE . 'admin', 'role'],
        ''
    );
    if ($type === '') {
        return ['ok' => false, 'missing' => $want, 'msg' => '未找到 admin.role 列'];
    }
    $missing = [];
    foreach ($want as $r) {
        if (!preg_match("/'" . preg_quote($r, '/') . "'/", $type)) {
            $missing[] = $r;
        }
    }
    return ['ok' => !$missing, 'missing' => $missing, 'msg' => $type];
}

function schema_audit(): array
{
    $problems = [];
    $have = [];
    foreach (db_all(
        'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
        [DB_PRE . '%']
    ) as $r) {
        $have[strtolower((string)$r['TABLE_NAME'])][] = strtolower((string)$r['COLUMN_NAME']);
    }

    foreach (schema_required() as $table => $cols) {
        $key = strtolower(DB_PRE . $table);
        if (!isset($have[$key])) {
            $problems[] = '缺少数据表 ' . DB_PRE . $table;
            continue;
        }
        foreach ($cols as $c) {
            if (!in_array(strtolower($c), $have[$key], true)) {
                $problems[] = '表 ' . DB_PRE . $table . ' 缺少字段 ' . $c;
            }
        }
    }

    $role = schema_role_ready();
    if (!$role['ok']) {
        $problems[] = 'admin.role 未包含角色值：' . implode('、', $role['missing']);
    }
    return ['problems' => $problems, 'roleType' => $role['msg']];
}

// ---------------- 执行升级 ----------------

if (is_post()) {
    csrf_guard();
    $pdo = db();
    $done = [];
    $fail = [];

    // 1) 扩展 role 枚举。MODIFY 是幂等的，重复执行无副作用。
    try {
        $pdo->exec('ALTER TABLE `' . DB_PRE . 'admin`
            MODIFY COLUMN `role` ENUM(\'super\',\'admin\',\'supervisor\',\'engineer\',\'operator\')
            NOT NULL DEFAULT \'operator\'
            COMMENT \'super=超管 admin=技术管理员 supervisor=主管 engineer=工程师 operator=客服\'');
        $done[] = 'admin.role 枚举已扩展为 5 种角色';
    } catch (PDOException $e) {
        $fail[] = '扩展 role 枚举失败：' . $e->getMessage();
    }

    // 1.5) 补建 mail_log 表。这张表只在 install/schema.sql 里出现过，
    //      老站点后来升级代码的路径上不会建它，而通知邮件改成异步发送后
    //      发信成败只落在这张表上——缺表就等于完全没有可观测性。
    //      结构与 install/schema.sql 保持一致，CREATE TABLE IF NOT EXISTS
    //      重复执行无副作用。
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . DB_PRE . 'mail_log` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `to_email`   VARCHAR(120) NOT NULL,
            `subject`    VARCHAR(200) NOT NULL DEFAULT \'\',
            `status`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1成功 0失败\',
            `error`      VARCHAR(500) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mail_time` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'邮件发送记录\'');
        $done[] = DB_PRE . 'mail_log 表已就绪';
    } catch (PDOException $e) {
        $fail[] = '创建 mail_log 表失败：' . $e->getMessage();
    }

    // 2) 补 author_role 列。先查 INFORMATION_SCHEMA 再 ALTER，
    //    因为重复 ADD COLUMN 会报 1060 错误，看着像失败其实是已做过。
    $hasCol = (int)db_one(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB_PRE . 'ticket_reply', 'author_role'],
        0
    );
    if ($hasCol > 0) {
        $done[] = 'ticket_reply.author_role 字段已存在';
    } else {
        try {
            $pdo->exec('ALTER TABLE `' . DB_PRE . 'ticket_reply`
                ADD COLUMN `author_role` VARCHAR(20) NOT NULL DEFAULT \'\'
                COMMENT \'回复时的角色快照，避免角色变动后历史头衔被改写\' AFTER `author_name`');
            $done[] = 'ticket_reply.author_role 字段已添加';
        } catch (PDOException $e) {
            $fail[] = '添加 author_role 失败：' . $e->getMessage();
        }
    }

    // 3) 回填存量回复的角色，避免历史记录头衔空白。
    //    取该账号「当前」角色而不是一律写 operator —— 否则超管、主管写的
    //    历史回复会被永久固化成「【客服】」。账号已删除的才退回 operator。
    try {
        $n = (int)db_query(
            'UPDATE `' . DB_PRE . 'ticket_reply` r
               LEFT JOIN `' . DB_PRE . 'admin` a ON a.id = r.admin_id
               SET r.author_role = COALESCE(NULLIF(a.role, \'\'), \'operator\')
             WHERE r.admin_id > 0 AND r.author_role = \'\'',
            []
        )->rowCount();
        $done[] = $n > 0 ? ('已回填 ' . $n . ' 条历史回复的角色标记') : '历史回复无需回填';
    } catch (PDOException $e) {
        $fail[] = '回填历史角色失败：' . $e->getMessage();
    }

    // 4) 补复合索引（可选，失败不影响功能）
    try {
        $pdo->exec('ALTER TABLE `' . DB_PRE . 'ticket` ADD KEY `idx_ticket_assignee_status` (`assignee_id`, `status`)');
        $done[] = '工单表复合索引已添加';
    } catch (PDOException $e) {
        // 只有 1061（索引已存在）算正常。权限不足(1142)、表不存在(1051) 等
        // 必须报出来，否则页面显示「已存在」，实际结构问题仍在。
        $code = (int)($e->errorInfo[1] ?? 0);
        if ($code === 1061) {
            $done[] = '工单表复合索引已存在';
        } else {
            $fail[] = '添加复合索引失败：' . $e->getMessage();
        }
    }

    // 5) 修正时区对齐之前写入的存量时间戳。
    //    db.php 现在会在连接时 SET time_zone，新数据不再有问题，
    //    但历史数据是按 MySQL 原时区（通常是 UTC）写的，比正确值少一个时区偏移。
    //
    //    只在检测到「最新一条记录的时间与当前时间相差接近时区偏移」时才动手，
    //    否则重复点会把已经正确的数据再推后 8 小时。
    //
    //    用秒而非小时：部分时区（如印度 +05:30）不是整小时，截断会留下 30 分钟误差。
    $tzShift = abs((new DateTime('now', new DateTimeZone(date_default_timezone_get())))->getOffset());

    $drift = db_tz_drift();
    if ($drift === null) {
        $done[] = '工单表为空，无需修正历史时间';
    } elseif ($tzShift > 0 && $drift >= $tzShift - 300) {
        // 需要修正的表与时间列。列名逐一对照 install/schema.sql 核过，
        // 不含 ticket_view（不存在该表）。这些都是「写入即固定」的日期时间，
        // 没有由 CURRENT_TIMESTAMP 派生出来的冗余列，一并平移即可。
        $tzTables = [
            'ticket'        => ['created_at', 'updated_at', 'first_reply_at', 'resolved_at', 'closed_at'],
            'ticket_reply'  => ['created_at'],
            'ticket_log'    => ['created_at'],
            'admin'         => ['created_at', 'updated_at', 'last_login_at', 'locked_until'],
            'user'          => ['created_at', 'updated_at', 'last_login_at'],
            'category'      => ['created_at', 'updated_at'],
            'faq'           => ['created_at', 'updated_at'],
            'admin_session' => ['created_at'],
            'mail_log'      => ['created_at'],
        ];
        $affected = 0;
        foreach ($tzTables as $tb => $cols) {
            foreach ($cols as $col) {
                try {
                    $affected += (int)db_query(
                        'UPDATE `' . DB_PRE . $tb . '` SET `' . $col . '` = DATE_ADD(`' . $col . '`, INTERVAL ' . $tzShift . ' SECOND)'
                        . ' WHERE `' . $col . '` IS NOT NULL AND `' . $col . '` > \'2000-01-01 00:00:00\''
                    )->rowCount();
                } catch (PDOException $e) {
                    $fail[] = '修正 ' . $tb . '.' . $col . ' 失败：' . $e->getMessage();
                }
            }
        }
        $done[] = '已将 ' . $affected . ' 个历史时间戳前移 ' . tz_text($tzShift);
    } else {
        $done[] = '历史时间戳与当前时间相差 ' . intdiv($drift, 60) . ' 分钟，属正常范围，未做修正';
    }

    app_error_log('[Upgrade] 完成=' . implode('; ', $done) . ' | 失败=' . implode('; ', $fail));

    if ($fail) {
        foreach ($fail as $f) {
            flash('error', $f);
        }
    } else {
        flash('ok', '数据库结构升级完成');
    }
    redirect('schema-check.php');
}

$audit  = schema_audit();
$ok     = !$audit['problems'];

// 时区状态独立于结构：结构可能已就绪，但存量时间戳仍带着时区偏移
$tzOffset  = abs((new DateTime('now', new DateTimeZone(date_default_timezone_get())))->getOffset());
$drift     = db_tz_drift();
$dbTz      = (string)db_one('SELECT @@session.time_zone', [], '');
$tzNeedFix = $drift !== null && $tzOffset > 0 && $drift >= $tzOffset - 300;
$ok        = $ok && !$tzNeedFix;

$pageTitle = '数据库结构';
// 拼字符串而不是 int + string。PHP 8 下 count() 的 int 与字符串相加直接抛
// TypeError（PHP 7 会静默当 0，产出「 项待修复」这种漏掉数字的文案）
$pageDesc  = $ok
    ? '结构完整'
    : count($audit['problems']) . ' 项待修复' . ($tzNeedFix ? ' · 时间戳待校正' : '');
require __DIR__ . '/_head.php';
?>

<?php if ($tzNeedFix): ?>
  <div class="alert alert-warn">
    <span class="ic">⚠</span>
    <div>
      检测到时间戳时区偏移：最新工单的提交时间与当前时间相差
      <strong><?= e(tz_text($drift)) ?></strong>，页面上的「N 小时前」全部失真。
      原因是写入时 MySQL 会话时区（<code class="mono"><?= e($dbTz) ?></code>）与 PHP 时区
      （<code class="mono"><?= e(date_default_timezone_get()) ?></code>）不一致。
      连接时区现已自动对齐，存量数据需要一次性平移。
    </div>
  </div>
<?php endif; ?>

<?php if ($ok): ?>
  <div class="alert alert-ok">
    <span class="ic">✓</span>
    <div>数据库结构完整，五种角色与角色快照字段均已就绪，无需升级。</div>
  </div>
<?php else: ?>
  <div class="alert alert-warn">
    <span class="ic">⚠</span>
    <div>
      检测到 <strong><?= count($audit['problems']) ?></strong> 项结构问题。
      未修复前，角色管理页与工单会话页会直接报错白屏，请立即执行升级。
    </div>
  </div>

  <div class="card mb">
    <div class="card-hd"><h2>待修复项</h2></div>
    <div class="card-bd">
      <ul style="margin:0;padding-left:20px;line-height:2">
        <?php foreach ($audit['problems'] as $p): ?>
          <li><?= e($p) ?></li>
        <?php endforeach; ?>
        <?php if ($tzNeedFix): ?>
          <li>存量时间戳带 <?= e(tz_text($tzOffset)) ?> 时区偏移（最新工单显示为 <?= e(tz_text($drift)) ?>前）</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <form method="post" data-confirm="确定执行数据库结构升级？期间会短暂锁定相关表。<?= $tzNeedFix ? '存量时间戳将被整体前移 ' . e(tz_text($tzOffset)) . '。' : '' ?>">
    <?= csrf_field() ?>
    <button class="btn btn-p" type="submit">一键升级数据库结构</button>
    <span class="muted small" style="margin-left:12px">幂等操作，可重复执行</span>
  </form>

  <div class="card mt">
    <div class="card-hd"><h2>手工执行方式</h2></div>
    <div class="card-bd">
      <p class="muted small">若服务器无法执行 ALTER（权限不足或磁盘满），可下载脚本后在 phpMyAdmin 中运行：</p>
      <p><a class="btn btn-o btn-sm" href="../install/upgrade-roles.sql" target="_blank" rel="noopener">查看 upgrade-roles.sql</a></p>
      <p class="muted small" style="margin-top:10px">当前表前缀：<code class="mono"><?= e(DB_PRE) ?></code>，请把脚本中的 <code class="mono">tk_</code> 替换为该前缀。</p>
    </div>
  </div>
<?php endif; ?>

<div class="card mt">
  <div class="card-hd"><h2>角色权限矩阵</h2></div>
  <div class="card-bd np">
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr>
          <th>角色</th><th>处理工单</th><th>配置邮件/用户/日志</th><th>可任免的角色</th><th>说明</th>
        </tr></thead>
        <tbody>
          <?php foreach (admin_roles() as $rk => $rm): ?>
            <tr>
              <td><span class="badge badge-<?= e($rm['color']) ?>"><?= e($rm['label']) ?></span></td>
              <td><?= admin_can_handle_ticket($rk) ? '<span class="badge badge-green">✓</span>' : '<span class="muted">—</span>' ?></td>
              <td><?= admin_role_level($rk) >= admin_role_level('admin')
                    ? '<span class="badge badge-green">✓</span>' : '<span class="muted">—</span>' ?></td>
              <td>
                <?php $mg = (array)$rm['manage']; ?>
                <?= $mg ? e(implode('、', array_map('admin_role_label', $mg))) : '<span class="muted">—</span>' ?>
              </td>
              <td class="small muted"><?= e($rm['desc']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
