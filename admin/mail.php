<?php
/** 邮件设置 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_role('admin');

$keys = [
    'mail_enabled', 'mail_mode',
    'mail_admin_to', 'mail_from', 'mail_from_name',
    'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_pass', 'mail_smtp_secure',
    'mail_subject_created', 'mail_tpl_created',
    'mail_subject_reply', 'mail_tpl_reply',
    'mail_subject_status', 'mail_tpl_status',
    'mail_subject_closed', 'mail_tpl_closed',
];

/** 密码类字段：留空表示不修改，保留已保存的值 */
$secretKeys = ['mail_smtp_pass'];

if (is_post()) {
    csrf_guard();
    $act = post('act');

    if ($act === 'save') {
        foreach ($keys as $k) {
            if ($k === 'mail_enabled') {
                // checkbox 未勾选时不会提交该字段，必须显式置 0，否则无法关闭
                set_setting($k, isset($_POST['mail_enabled']) ? '1' : '0');
                continue;
            }
            if (array_key_exists($k, $_POST)) {
                $v = trim((string)$_POST[$k]);
                // 密码留空则不覆盖，避免每次编辑其他项都要重填
                if (in_array($k, $secretKeys, true) && $v === '') {
                    continue;
                }
                set_setting($k, $v);
            }
        }

        // 开启邮件前校验必要配置，避免通知静默失效
        if ((int)setting('mail_enabled', '0') === 1) {
            $from = trim((string)setting('mail_from', ''));
            if ($from === '' || !is_valid_email($from)) {
                set_setting('mail_enabled', '0');
                flash('error', '发件人邮箱填写无效，邮件通知已保持关闭');
                redirect('mail.php');
            }
            if (mail_mode() === 'smtp' && trim((string)setting('mail_smtp_host', '')) === '') {
                set_setting('mail_enabled', '0');
                flash('error', '已选择 SMTP 通道但未填写 SMTP 服务器地址，邮件通知已保持关闭');
                redirect('mail.php');
            }
        }

        flash('ok', '邮件设置已保存');
        redirect('mail.php');
    }

    if ($act === 'probe') {
        $r = smtp_probe();
        flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'SMTP 连通性正常：' . $r['msg'] : '连接失败：' . $r['msg']);
        redirect('mail.php');
    }

    if ($act === 'test') {
        $to = post('test_to');
        if (!is_valid_email($to)) {
            flash('error', '请输入正确的测试邮箱地址');
            redirect('mail.php');
        }
        $r = mail_send($to, '【测试】' . setting('site_name', '工单中心') . ' 邮件通知配置成功',
            '<p>恭喜！这封邮件由 ' . e(setting('site_name', '工单中心')) . ' 发出。</p>'
            . '<p>说明你的邮件通知功能已配置正确，用户的工单进展将能正常收到邮件。</p>'
            . '<p style="color:#9ca3af;font-size:13px">发送时间：' . date('Y-m-d H:i:s') . '</p>');
        if ($r['ok']) {
            flash('ok', '测试邮件已发送至 ' . $to . '，请检查收件箱与垃圾箱');
        } else {
            flash('error', '发送失败：' . $r['msg']);
        }
        redirect('mail.php');
    }
}

$logs = db_all('SELECT * FROM ' . DB_PRE . 'mail_log ORDER BY id DESC LIMIT 30');
$okCount  = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'mail_log WHERE status = 1', [], 0);
$failCount = (int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'mail_log WHERE status = 0', [], 0);
$envMail   = function_exists('mail');
$envSock   = function_exists('fsockopen');
$mode      = mail_mode();

$pageTitle = '邮件通知设置';
$pageActions = '<button class="btn btn-o btn-sm" data-modal="mTest">发送测试邮件</button>'
    . '<form method="post" style="display:inline">' . csrf_field()
    . '<input type="hidden" name="act" value="probe">'
    . '<button class="btn btn-o btn-sm" type="submit">测试 SMTP 连通性</button></form>';
require __DIR__ . '/_head.php';
?>

<div class="stats">
  <div class="stat" style="color:<?= (int)setting('mail_enabled','0')===1 ? '#10b981' : '#94a3b8' ?>">
    <div class="lb">通知状态</div>
    <div class="vl" style="font-size:22px"><?= (int)setting('mail_enabled','0') === 1 ? '已开启' : '已关闭' ?></div>
    <div class="sub">用户填写邮箱后生效</div>
  </div>
  <div class="stat" style="color:#10b981">
    <div class="lb">发送成功</div>
    <div class="vl"><?= e((string)$okCount) ?></div>
    <div class="sub">累计</div>
  </div>
  <div class="stat" style="color:#ef4444">
    <div class="lb">发送失败</div>
    <div class="vl"><?= e((string)$failCount) ?></div>
    <div class="sub">请检查配置</div>
  </div>
  <div class="stat" style="color:<?= $envSock ? '#4f46e5' : '#ef4444' ?>">
    <div class="lb">发送通道</div>
    <div class="vl" style="font-size:22px"><?= $mode === 'smtp' ? 'SMTP' : 'mail()' ?></div>
    <div class="sub"><?= $mode === 'smtp' ? ($envSock ? '原生实现' : 'fsockopen 不可用') : ($envMail ? '依赖本机 MTA' : 'mail() 不可用') ?></div>
  </div>
</div>

<?php if (!$envSock): ?>
  <div class="alert alert-warn">
    <span class="ic">⚠</span>
    <div>
      当前 PHP 未启用 <code>fsockopen</code> / <code>openssl</code> 相关能力，SMTP 通道将无法工作。
      请检查 <code>disable_functions</code> 是否禁用了 <code>fsockopen</code>，并确认已启用 <code>openssl</code> 扩展。
    </div>
  </div>
<?php endif; ?>

<?php if ($mode === 'mail' && !$envMail): ?>
  <div class="alert alert-warn">
    <span class="ic">⚠</span>
    <div>
      当前使用 <code>mail()</code> 通道，但服务器未启用该函数或未配置本机 MTA，邮件将无法发出。
      <strong>绝大多数云服务器/宝塔环境都属于这种情况</strong>，建议切换到上方 <strong>SMTP 通道</strong>。
    </div>
  </div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="save">

  <div class="card mb">
    <div class="card-hd"><h2>📮 基础配置</h2></div>
    <div class="card-bd">
      <div class="field">
        <label class="switch">
          <input type="checkbox" name="mail_enabled" value="1" <?= (int)setting('mail_enabled','0') === 1 ? 'checked' : '' ?>>
          <span class="sl"></span>
          <strong>开启邮件通知</strong>
        </label>
        <div class="tip">关闭后系统不再发送任何邮件，用户仍可正常提交工单</div>
      </div>

      <div class="frow-3">
        <div class="field">
          <label>发件人邮箱 <span class="req">*</span></label>
          <input type="email" name="mail_from" class="input" placeholder="noreply@example.com" value="<?= e((string)setting('mail_from','')) ?>">
          <div class="tip">用户回复工单时也会发到这个地址</div>
        </div>
        <div class="field">
          <label>发件人名称</label>
          <input type="text" name="mail_from_name" class="input" placeholder="工单中心" value="<?= e((string)setting('mail_from_name','')) ?>">
        </div>
        <div class="field">
          <label>接收新工单提醒的邮箱</label>
          <input type="email" name="mail_admin_to" class="input" placeholder="admin@example.com" value="<?= e((string)setting('mail_admin_to','')) ?>">
          <div class="tip">留空则不向管理员发送新工单提醒</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb">
    <div class="card-hd"><h2>🔌 发送通道</h2></div>
    <div class="card-bd">
      <div class="field">
        <label>通道选择 <span class="req">*</span></label>
        <div class="radio-row">
          <label class="radio">
            <input type="radio" name="mail_mode" value="smtp" <?= $mode === 'smtp' ? 'checked' : '' ?>>
            <span class="ri"></span>
            <span class="rt"><strong>SMTP 通道（推荐）</strong><em>原生实现，无需扩展，云服务器可用</em></span>
          </label>
          <label class="radio">
            <input type="radio" name="mail_mode" value="mail" <?= $mode === 'mail' ? 'checked' : '' ?>>
            <span class="ri"></span>
            <span class="rt"><strong>mail() 通道（兜底）</strong><em>依赖本机 sendmail，虚拟主机通常不可用</em></span>
          </label>
        </div>
        <div class="tip">
          SMTP 通道由 PHP 原生实现，无需任何扩展或第三方库，是云服务器上唯一可靠的方式。<br>
          mail() 通道依赖服务器本机 sendmail 服务，虚拟主机与容器环境通常不可用。
        </div>
      </div>
    </div>
  </div>

  <div class="card mb">
    <div class="card-hd"><h2>🔐 SMTP 服务器配置</h2><span class="sp"></span>
      <span class="muted small">仅 SMTP 通道需要</span></div>
    <div class="card-bd">
      <div class="alert alert-info" style="margin-bottom:18px">
        <span class="ic">ℹ</span>
        <div>
          <strong>QQ / 163 邮箱请使用「授权码」而非登录密码</strong>，获取方式：<br>
          QQ 邮箱 → 设置 → 账户 → 开启 SMTP 服务 → 生成授权码<br>
          163 邮箱 → 设置 → POP3/SMTP → 开启客户端授权密码
        </div>
      </div>

      <div class="frow-3">
        <div class="field">
          <label>SMTP 服务器</label>
          <input type="text" name="mail_smtp_host" class="input mono" placeholder="smtp.qq.com" value="<?= e((string)setting('mail_smtp_host','')) ?>">
        </div>
        <div class="field">
          <label>端口</label>
          <input type="number" name="mail_smtp_port" class="input mono" placeholder="465" value="<?= e((string)setting('mail_smtp_port','465')) ?>">
        </div>
        <div class="field">
          <label>加密方式</label>
          <select name="mail_smtp_secure" class="input">
            <option value="ssl"  <?= setting('mail_smtp_secure','ssl') === 'ssl'  ? 'selected' : '' ?>>SSL（465，隐式加密）</option>
            <option value="tls"  <?= setting('mail_smtp_secure','ssl') === 'tls'  ? 'selected' : '' ?>>STARTTLS（587，登录后升级）</option>
            <option value="none" <?= setting('mail_smtp_secure','ssl') === 'none' ? 'selected' : '' ?>>不加密（25，仅内网）</option>
          </select>
        </div>
      </div>

      <div class="frow-2">
        <div class="field">
          <label>SMTP 账号</label>
          <input type="text" name="mail_smtp_user" class="input mono" placeholder="noreply@qq.com" value="<?= e((string)setting('mail_smtp_user','')) ?>">
          <div class="tip">通常与发件人邮箱一致</div>
        </div>
        <div class="field">
          <label>SMTP 密码 / 授权码</label>
          <input type="password" name="mail_smtp_pass" class="input mono" placeholder="<?= setting('mail_smtp_pass','') !== '' ? '已保存，留空则不修改' : '填写邮箱授权码' ?>">
          <div class="tip">出于安全考虑不会回显，留空表示保持原值不变</div>
        </div>
      </div>

      <div class="tip" style="margin-top:14px">
        填写后先点下方「保存全部设置」，再点右上角「测试 SMTP 连通性」验证端口是否可达。
      </div>
    </div>
  </div>

  <div class="card mb">
    <div class="card-hd"><h2>✉️ 通知模板</h2><span class="sp"></span>
      <span class="muted small">可用变量：{no} {title} {link} {time} {site} {status} {content}</span></div>
    <div class="card-bd">

      <div class="field">
        <label>① 工单提交成功（发给用户）</label>
        <input type="text" name="mail_subject_created" class="input mono" style="font-size:13px"
               value="<?= e((string)setting('mail_subject_created','')) ?>">
        <textarea name="mail_tpl_created" class="textarea mono" style="min-height:120px;font-size:13px;margin-top:8px"><?= e((string)setting('mail_tpl_created','')) ?></textarea>
      </div>

      <div class="field">
        <label>② 客服回复（发给用户）</label>
        <input type="text" name="mail_subject_reply" class="input mono" style="font-size:13px"
               value="<?= e((string)setting('mail_subject_reply','')) ?>">
        <textarea name="mail_tpl_reply" class="textarea mono" style="min-height:120px;font-size:13px;margin-top:8px"><?= e((string)setting('mail_tpl_reply','')) ?></textarea>
        <div class="tip">{content} 会自动填入客服的回复正文</div>
      </div>

      <div class="field">
        <label>③ 状态变更（发给用户）</label>
        <input type="text" name="mail_subject_status" class="input mono" style="font-size:13px"
               value="<?= e((string)setting('mail_subject_status','')) ?>">
        <textarea name="mail_tpl_status" class="textarea mono" style="min-height:100px;font-size:13px;margin-top:8px"><?= e((string)setting('mail_tpl_status','')) ?></textarea>
      </div>

      <div class="field">
        <label>④ 工单关闭（发给用户）</label>
        <input type="text" name="mail_subject_closed" class="input mono" style="font-size:13px"
               value="<?= e((string)setting('mail_subject_closed','')) ?>">
        <textarea name="mail_tpl_closed" class="textarea mono" style="min-height:100px;font-size:13px;margin-top:8px"><?= e((string)setting('mail_tpl_closed','')) ?></textarea>
      </div>
    </div>
  </div>

  <button class="btn btn-p" type="submit">保存全部设置</button>
</form>

<div class="card mt">
  <div class="card-hd"><h2>📋 发送记录</h2><span class="sp"></span>
    <span class="muted small">最近 30 条</span></div>
  <div class="card-bd np">
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>时间</th><th>收件人</th><th>主题</th><th>结果</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td class="muted small nowrap"><?= e(fmt_date($l['created_at'], 'm-d H:i:s')) ?></td>
              <td class="small"><?= e($l['to_email']) ?></td>
              <td class="small" style="max-width:340px"><?= e($l['subject']) ?>
                <?php if (!empty($l['error'])): ?>
                  <div class="t-sub" style="color:var(--err)"><?= e($l['error']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$l['status'] === 1): ?>
                  <span class="badge badge-green">成功</span>
                <?php else: ?>
                  <span class="badge badge-red">失败</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
            <tr><td colspan="4"><div class="empty" style="padding:38px"><div class="ic">📭</div><p>暂无发送记录</p></div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal" id="mTest">
  <div class="modal-box">
    <div class="modal-hd"><h3>发送测试邮件</h3><button type="button" data-close>×</button></div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="test">
      <div class="modal-bd">
        <div class="field">
          <label>测试收件邮箱 <span class="req">*</span></label>
          <input type="email" name="test_to" class="input" required placeholder="your@qq.com" value="<?= e((string)setting('mail_admin_to','')) ?>">
          <div class="tip">需已保存 SMTP 配置；若失败请查看页面下方「发送记录」中的错误详情</div>
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-o" data-close>取消</button>
        <button class="btn btn-p" type="submit">立即发送</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
