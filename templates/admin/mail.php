<?php
/**
 * 邮件设置
 *
 * @var array $status
 * @var list<array> $recent
 * @var array{total:int,ok:int,fail:int} $stats
 * @var bool $mailFunction
 * @var array $settings 站点配置（已带默认值）
 */
?>
<?php if (!$status['configured']): ?>
  <div class="alert alert-warn mb-3" role="alert">
    <span class="alert-ico" aria-hidden="true"><?= icon('warn', 17) ?></span>
    <div class="alert-body">
      <div class="alert-title">邮件通道当前不可用</div>
      <?= e((string)$status['note']) ?>。工单的回复与状态变更通知不会发出，
      用户只能自己回站点查看进度。
    </div>
  </div>
<?php endif; ?>

<?php if (!$mailFunction): ?>
  <div class="alert alert-info mb-3">
    <span class="alert-ico" aria-hidden="true"><?= icon('info', 17) ?></span>
    <div class="alert-body">
      本服务器未安装本地邮件服务（<code>sendmail</code>），因此请使用 <strong>SMTP</strong> 通道，
      不要选择「本地 mail()」。
    </div>
  </div>
<?php endif; ?>

<!-- 关键指标 -->
<div class="grid grid-3 mb-3">
  <div class="stat">
    <div class="stat-k">近 7 天发送</div>
    <div class="stat-v"><?= e((string)$stats['total']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-k">成功</div>
    <div class="stat-v" style="color:var(--ok-fg)"><?= e((string)$stats['ok']) ?></div>
  </div>
  <div class="stat">
    <div class="stat-k">失败</div>
    <div class="stat-v" style="color:<?= $stats['fail'] > 0 ? 'var(--err-fg)' : 'var(--text-4)' ?>">
      <?= e((string)$stats['fail']) ?>
    </div>
  </div>
</div>

<form data-guard method="post" action="<?= e(url('/admin/mail/save')) ?>">
  <?= csrf_field() ?>

  <div class="a-card">
    <div class="a-card-hd"><h2>通知开关与发件人</h2></div>
    <div class="a-card-bd">
      <div class="switch-row">
        <div class="sw-txt">
          <strong>开启邮件通知</strong>
          <span>关闭后所有通知邮件都不会发送（推荐在配置完成并测试通过后再开启）。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="mail_enabled" value="1"<?= $settings['mail_enabled'] === '1' ? ' checked' : '' ?>>
          <span>开启</span>
        </label>
      </div>

      <div class="form-row mt-2">
        <div class="field">
          <label class="field-label" for="mFrom">发件人邮箱</label>
          <input class="input" id="mFrom" type="email" name="mail_from" maxlength="120"
                 value="<?= e($settings['mail_from']) ?>" placeholder="noreply@example.com">
          <div class="field-tip">多数邮箱服务要求发件人与登录账号一致，否则会被拒信。</div>
        </div>
        <div class="field">
          <label class="field-label" for="mFromName">发件人名称</label>
          <input class="input" id="mFromName" type="text" name="mail_from_name" maxlength="60"
                 value="<?= e($settings['mail_from_name']) ?>" placeholder="例如：Roblox 帮助中心">
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="mAdminTo">管理员通知邮箱 <span class="opt">选填</span></label>
          <input class="input" id="mAdminTo" type="email" name="mail_admin_to" maxlength="120"
                 value="<?= e($settings['mail_admin_to']) ?>" placeholder="有新工单时通知到这里">
          <div class="field-tip">填你自己的邮箱，可以在用户提交工单时立刻收到提醒。</div>
        </div>
        <div class="field">
          <label class="field-label" for="mSubject">主题前缀 <span class="opt">选填</span></label>
          <input class="input" id="mSubject" type="text" name="mail_subject_prefix" maxlength="30"
                 value="<?= e($settings['mail_subject_prefix']) ?>" placeholder="例如：[工单]">
          <div class="field-tip">便于在邮箱里用过滤器归档。</div>
        </div>
      </div>
    </div>
  </div>

  <div class="a-card">
    <div class="a-card-hd"><h2>发送通道</h2></div>
    <div class="a-card-bd">
      <div class="form-row">
        <div class="field">
          <label class="field-label" for="mTransport">通道类型</label>
          <select class="select" id="mTransport" name="mail_transport">
            <option value="smtp"<?= $settings['mail_transport'] === 'smtp' ? ' selected' : '' ?>>SMTP 服务器（推荐）</option>
            <option value="mail"<?= $settings['mail_transport'] === 'mail' ? ' selected' : '' ?>>本地 mail()（需要服务器装有 MTA）</option>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="mSecure">加密方式</label>
          <select class="select" id="mSecure" name="mail_secure">
            <option value="ssl"<?= $settings['mail_secure'] === 'ssl' ? ' selected' : '' ?>>SSL（通常端口 465）</option>
            <option value="tls"<?= $settings['mail_secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS（通常端口 587）</option>
            <option value="none"<?= $settings['mail_secure'] === 'none' ? ' selected' : '' ?>>不加密（仅内网可用）</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="mHost">SMTP 服务器</label>
          <input class="input" id="mHost" type="text" name="mail_host" maxlength="120"
                 value="<?= e($settings['mail_host']) ?>" placeholder="smtp.qq.com">
        </div>
        <div class="field">
          <label class="field-label" for="mPort">端口</label>
          <input class="input" id="mPort" type="number" name="mail_port" min="1" max="65535"
                 value="<?= e($settings['mail_port']) ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="mUser">SMTP 账号</label>
          <input class="input" id="mUser" type="text" name="mail_user" maxlength="120"
                 value="<?= e($settings['mail_user']) ?>">
          <div class="field-tip">一般就是你的邮箱地址。</div>
        </div>
        <div class="field">
          <label class="field-label" for="mPass">SMTP 密码 / 授权码</label>
          <?php /* 敏感字段：不回显、留空即保持原值。
                 旧版对 SMTP 密码这样处理，却把功能开关密钥用
                 type="text" 直接回显到页面上，同一类凭据两套做法。 */ ?>
          <input class="input" id="mPass" type="password" name="mail_pass" maxlength="200"
                 autocomplete="new-password"
                 placeholder="<?= $settings['mail_pass'] !== '' ? '已保存，留空则不修改' : '填写授权码（不是登录密码）' ?>">
          <div class="field-tip">
            QQ / 163 邮箱需要使用「授权码」，不是网页登录密码。
            <?= $settings['mail_pass'] !== '' ? '当前已保存一个密码。' : '' ?>
          </div>
        </div>
      </div>

      <div class="switch-row">
        <div class="sw-txt">
          <strong>校验证书</strong>
          <span>自建邮件服务器使用自签证书时，可以关闭该项。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="mail_verify_peer" value="1"<?= $settings['mail_verify_peer'] === '1' ? ' checked' : '' ?>>
          <span>校验证书</span>
        </label>
      </div>
    </div>
    <div class="a-card-ft">
      <button class="btn btn-primary" type="submit">保存设置</button>
    </div>
  </div>
</form>

<div class="a-card">
  <div class="a-card-hd"><h2>发送测试邮件</h2></div>
  <form method="post" action="<?= e(url('/admin/mail/test')) ?>">
    <?= csrf_field() ?>
    <div class="a-card-bd">
      <div class="form-row">
        <div class="field mb-0">
          <label class="field-label" for="mTest">收件邮箱</label>
          <input class="input" id="mTest" type="email" name="test_email" required
                 value="<?= e($settings['mail_admin_to'] !== '' ? $settings['mail_admin_to'] : $settings['mail_from']) ?>"
                 placeholder="收件地址">
          <div class="field-tip">建议先保存设置，再发送测试。发送是同步执行的，可能需要几秒钟。</div>
        </div>
        <div class="field mb-0">
          <span class="field-label" aria-hidden="true">&nbsp;</span>
          <button class="btn btn-secondary btn-block" type="submit">发送测试邮件</button>
        </div>
      </div>

      <div class="alert alert-plain mt-2 mb-0">
        <span class="alert-ico" aria-hidden="true"><?= icon('info', 17) ?></span>
        <div class="alert-body">
          当前通道：<strong><?= e((string)$status['transport']) ?></strong> ·
          发件人：<strong><?= e($status['from'] !== '' ? (string)$status['from'] : '未配置') ?></strong>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="a-card">
  <div class="a-card-hd">
    <h2>最近发送记录</h2>
    <span class="faint tiny">最多显示 30 条</span>
  </div>
  <?php if ($recent === []): ?>
    <div class="a-card-bd">
      <p class="muted small mb-0">还没有发送记录。系统发出的每一封通知（含失败）都会记录在这里。</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th class="nowrap">时间</th>
            <th>收件人</th>
            <th>主题</th>
            <th class="nowrap">结果</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $m): ?>
            <tr>
              <td class="nowrap faint tiny"><?= e(\App\Support\Str::datetime((string)$m['created_at'], 'm-d H:i:s')) ?></td>
              <td class="tiny mono"><?= e((string)$m['to_email']) ?></td>
              <td class="tiny"><?= e(\App\Support\Str::limit((string)$m['subject'], 50)) ?></td>
              <td class="nowrap">
                <?php if ((int)$m['ok'] === 1): ?>
                  <span class="badge badge-green">已发送</span>
                <?php else: ?>
                  <span class="badge badge-red">失败</span>
                  <?php if ((string)$m['error'] !== ''): ?>
                    <span class="cell-sub" style="color:var(--err-fg)"><?= e(\App\Support\Str::limit((string)$m['error'], 60)) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
