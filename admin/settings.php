<?php
/** 系统设置 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_super();

if (is_post()) {
    csrf_guard();
    $act = post('act');

    if ($act === 'save') {
        $text = [
            'site_name', 'site_desc', 'site_keywords', 'site_url', 'site_icp',
            'home_announce', 'ticket_prefix',
            'turnstile_site_key', 'turnstile_secret_key',
        ];
        foreach ($text as $k) {
            if (array_key_exists($k, $_POST)) {
                set_setting($k, trim((string)$_POST[$k]));
            }
        }
        $nums = [
            'upload_max_mb' => [1, 100],
            'upload_max_count' => [1, 20],
            'antibot_rate_limit' => [0, 100],
        ];
        foreach ($nums as $k => [$min, $max]) {
            if (array_key_exists($k, $_POST)) {
                set_setting($k, (string)max($min, min($max, (int)$_POST[$k])));
            }
        }
        // 以下为 checkbox，未勾选时浏览器不提交该字段，必须显式置 0，否则无法关闭
        foreach (['ticket_auto_reply', 'ticket_require_email', 'ticket_allow_guest', 'reg_require_email_code'] as $k) {
            set_setting($k, isset($_POST[$k]) ? '1' : '0');
        }
        flash('ok', '系统设置已保存');
        redirect('settings.php');
    }

    if ($act === 'clear_logs') {
        $days = (int)post('days');
        if ($days > 0) {
            $n = db_query('DELETE FROM ' . DB_PRE . 'ticket_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)')->rowCount();
            flash('ok', '已清理 ' . $n . ' 条 ' . $days . ' 天前的工单日志');
        } else {
            $n = db_query('DELETE FROM ' . DB_PRE . 'ticket_log')->rowCount();
            db_query('ALTER TABLE ' . DB_PRE . 'ticket_log AUTO_INCREMENT = 1');
            flash('ok', '已清空全部工单日志');
        }
        redirect('settings.php');
    }
}

$stats = ticket_stats();
$dbSize = (float)db_one('SELECT SUM(data_length + index_length) / 1024 / 1024 FROM information_schema.TABLES WHERE table_schema = DATABASE()', [], 0);
$phpVer = PHP_VERSION;
$logCount = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'ticket_log', [], 0);

$pageTitle = '系统设置';
require __DIR__ . '/_head.php';
?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="save">

  <div class="two-col">
    <div>
      <div class="card mb">
        <div class="card-hd"><h2>🌐 站点信息</h2></div>
        <div class="card-bd">
          <div class="field">
            <label>站点名称</label>
            <input type="text" name="site_name" class="input" value="<?= e((string)setting('site_name','')) ?>">
          </div>
          <div class="field">
            <label>站点描述</label>
            <textarea name="site_desc" class="textarea" style="min-height:70px"><?= e((string)setting('site_desc','')) ?></textarea>
          </div>
          <div class="field">
            <label>关键词</label>
            <input type="text" name="site_keywords" class="input" value="<?= e((string)setting('site_keywords','')) ?>">
          </div>
          <div class="frow">
            <div class="field">
              <label>站点完整地址</label>
              <input type="url" name="site_url" class="input" placeholder="https://example.com" value="<?= e((string)setting('site_url','')) ?>">
              <div class="tip">邮件中的工单链接会使用此地址，留空自动识别</div>
            </div>
            <div class="field">
              <label>备案号 / 页脚信息</label>
              <input type="text" name="site_icp" class="input" value="<?= e((string)setting('site_icp','')) ?>">
            </div>
          </div>
          <div class="field">
            <label>首页公告</label>
            <textarea name="home_announce" class="textarea" style="min-height:90px" placeholder="留空则不显示公告；支持换行"><?= e((string)setting('home_announce','')) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="card mb">
        <div class="card-hd"><h2>⚙️ 工单设置</h2></div>
        <div class="card-bd">
          <div class="field">
            <label>工单编号前缀</label>
            <input type="text" name="ticket_prefix" class="input" maxlength="6" value="<?= e((string)setting('ticket_prefix','TK')) ?>">
            <div class="tip">当前编号示例：<?= e(ticket_make_no()) ?></div>
          </div>
          <div class="frow">
            <div class="field">
              <label>单文件大小上限（MB）</label>
              <input type="number" name="upload_max_mb" class="input" min="1" max="100" value="<?= setting_int('upload_max_mb', 10) ?>">
            </div>
            <div class="field">
              <label>附件数量上限</label>
              <input type="number" name="upload_max_count" class="input" min="1" max="20" value="<?= setting_int('upload_max_count', 5) ?>">
            </div>
          </div>
          <div class="field">
            <label>反机器人：每小时提交上限</label>
            <input type="number" name="antibot_rate_limit" class="input" min="0" max="100" value="<?= setting_int('antibot_rate_limit', 5) ?>">
            <div class="tip">同一 IP 每小时最多提交多少个工单，0 表示不限制</div>
          </div>

          <div class="sub-title">Cloudflare Turnstile 人机验证</div>
          <div class="form-row">
            <div class="field">
              <label>站点密钥（Site Key）</label>
              <input type="text" name="turnstile_site_key" class="input"
                     value="<?= e((string)setting('turnstile_site_key', '')) ?>" placeholder="0x4AAAA...">
              <div class="tip">可公开，用于前端渲染验证组件</div>
            </div>
            <div class="field">
              <label>私密密钥（Secret Key）</label>
              <input type="text" name="turnstile_secret_key" class="input"
                     value="<?= e((string)setting('turnstile_secret_key', '')) ?>" placeholder="0x4AAAA...">
              <div class="tip">绝不可公开，仅服务端用于校验令牌</div>
            </div>
          </div>
          <div class="field">
            <div class="tip" style="padding:11px 13px;background:var(--bg-soft,#f8fafc);border-radius:9px;border:1px solid var(--line)">
              <?php if (turnstile_enabled()): ?>
                <strong style="color:#059669">✓ 已启用</strong> — 游客提交工单与注册页均使用 Turnstile。
                任意一项密钥留空即自动退回算术验证码，不会导致游客无法提交。
              <?php else: ?>
                <strong style="color:#d97706">未启用</strong> — 填写完整的站点密钥与私密密钥后生效。
              <?php endif; ?>
            </div>
          </div>
          <div class="field">
            <label class="switch"><input type="checkbox" name="reg_require_email_code" value="1" <?= setting_int('reg_require_email_code',1) === 1 ? 'checked' : '' ?>><span class="sl"></span> 注册需邮箱验证码</label>
            <div class="tip">关闭后注册不再需要邮箱验证；需先在「邮件通知设置」中开启总开关并配好 SMTP，否则验证码发不出去</div>
          </div>
          <div class="field">
            <label class="switch"><input type="checkbox" name="ticket_require_email" value="1" <?= setting_int('ticket_require_email',1) === 1 ? 'checked' : '' ?>><span class="sl"></span> 必填联系邮箱</label>
            <div class="tip">关闭后邮箱可留空，但将无法收到任何邮件通知</div>
          </div>
          <div class="field">
            <label class="switch"><input type="checkbox" name="ticket_allow_guest" value="1" <?= setting_int('ticket_allow_guest',1) === 1 ? 'checked' : '' ?>><span class="sl"></span> 允许游客提交工单</label>
            <div class="tip">关闭后未登录用户无法提交，需先登录前台账号</div>
          </div>
          <div class="field">
            <label class="switch"><input type="checkbox" name="ticket_auto_reply" value="1" <?= setting_int('ticket_auto_reply',1) === 1 ? 'checked' : '' ?>><span class="sl"></span> 提交后自动发送确认邮件</label>
            <div class="tip">仅控制「提交成功」这一封；需先在「邮件通知设置」中开启总开关并配好 SMTP 才会实际发送</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <button class="btn btn-p" type="submit">保存设置</button>
</form>

<div class="two-col mt">
  <div class="card">
    <div class="card-hd"><h2>💾 系统信息</h2></div>
    <div class="card-bd">
      <ul class="logs" style="font-size:13.5px">
        <li><span class="tm">PHP 版本</span><span class="dt"><?= e($phpVer) ?></span></li>
        <li><span class="tm">系统版本</span><span class="dt">工单系统 v<?= e(APP_VER) ?></span></li>
        <li><span class="tm">数据库</span><span class="dt">MySQL · 表前缀 <?= e(DB_PRE) ?></span></li>
        <li><span class="tm">数据库大小</span><span class="dt"><?= e(number_format($dbSize, 2)) ?> MB</span></li>
        <li><span class="tm">工单总数</span><span class="dt"><?= e((string)$stats['total']) ?></span></li>
        <li><span class="tm">操作日志</span><span class="dt"><?= e((string)$logCount) ?> 条</span></li>
        <li><span class="tm">上传目录</span><span class="dt">uploads/ <?= is_writable(UPLOAD_PATH) ? '<span class="badge badge-green">可写</span>' : '<span class="badge badge-red">不可写</span>' ?></span></li>
        <li><span class="tm">当前时间</span><span class="dt"><?= e(date('Y-m-d H:i:s')) ?></span></li>
      </ul>
    </div>
  </div>

  <div class="card">
    <div class="card-hd"><h2>🧹 数据维护</h2></div>
    <div class="card-bd">
      <div class="alert alert-warn">
        <span class="ic">⚠</span>
        <div>清理操作不可撤销，请谨慎执行。</div>
      </div>
      <form method="post" class="flex-between" style="margin-bottom:11px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="clear_logs">
        <span>清理 30 天前的工单日志</span>
        <button class="btn btn-o btn-sm" name="days" value="30" type="submit" data-confirm="确定清理 30 天前的工单日志？">执行</button>
      </form>
      <form method="post" class="flex-between" style="margin-bottom:11px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="clear_logs">
        <span>清理 90 天前的工单日志</span>
        <button class="btn btn-o btn-sm" name="days" value="90" type="submit" data-confirm="确定清理 90 天前的工单日志？">执行</button>
      </form>
      <form method="post" class="flex-between">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="clear_logs">
        <span style="color:var(--err);font-weight:600">清空全部工单日志</span>
        <button class="btn btn-d btn-sm" name="days" value="0" type="submit" data-confirm="确定清空全部工单日志？此操作不可恢复！">清空</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
