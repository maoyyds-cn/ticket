<?php
/**
 * 系统设置（仅超级管理员）
 *
 * @var array<string,string> $settings
 * @var array<string,string|bool> $system
 */
?>
<form data-guard method="post" action="<?= e(url('/admin/settings/save')) ?>">
  <?= csrf_field() ?>

  <div class="a-card">
    <div class="a-card-hd"><h2>站点信息</h2></div>
    <div class="a-card-bd">
      <div class="form-row">
        <div class="field">
          <label class="field-label" for="sName">站点名称</label>
          <input class="input" id="sName" type="text" name="site_name" required maxlength="60"
                 value="<?= e($settings['site_name']) ?>">
          <div class="field-tip">显示在导航、页脚与邮件里。</div>
        </div>
        <div class="field">
          <label class="field-label" for="sUrl">站点地址</label>
          <input class="input" id="sUrl" type="url" name="site_url" maxlength="120"
                 value="<?= e($settings['site_url']) ?>" placeholder="https://t.ili.ink">
          <div class="field-tip">
            <strong>建议填写。</strong>邮件里的工单链接以此为基准；留空时会根据访问者请求头推断，
            而请求头是客户端可控的。
          </div>
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="sDesc">站点描述</label>
        <textarea class="textarea" id="sDesc" name="site_desc" maxlength="300"
                  style="min-height:78px"><?= e($settings['site_desc']) ?></textarea>
        <div class="field-tip">用于搜索引擎摘要与首页首屏文案。</div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="sKeywords">SEO 关键词 <span class="opt">选填</span></label>
          <input class="input" id="sKeywords" type="text" name="site_keywords" maxlength="200"
                 value="<?= e($settings['site_keywords']) ?>">
        </div>
        <div class="field">
          <label class="field-label" for="sIcp">备案号 <span class="opt">选填</span></label>
          <input class="input" id="sIcp" type="text" name="site_icp" maxlength="60"
                 value="<?= e($settings['site_icp']) ?>">
        </div>
      </div>

      <div class="field mb-0">
        <label class="field-label" for="sAnnounce">首页公告 <span class="opt">选填</span></label>
        <textarea class="textarea" id="sAnnounce" name="home_announce" maxlength="1000"
                  style="min-height:88px"
                  placeholder="例如：本周六凌晨进行数据库维护，期间可能短暂不可用。"><?= e($settings['home_announce']) ?></textarea>
        <div class="field-tip row-between">
          <span>纯文本，空行分段。留空则首页不显示公告。</span>
          <span class="counter" data-count-for="home_announce" data-count-max="1000">0 / 1000</span>
        </div>
      </div>
    </div>
  </div>

  <div class="a-card">
    <div class="a-card-hd"><h2>工单规则</h2></div>
    <div class="a-card-bd">
      <div class="switch-row">
        <div class="sw-txt">
          <strong>允许提交工单</strong>
          <span>关闭后前台不再显示提交入口，已存在的工单仍可查看与回复。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="ticket_enabled" value="1"<?= $settings['ticket_enabled'] === '1' ? ' checked' : '' ?>>
          <span>开启</span>
        </label>
      </div>

      <div class="field mt-2">
        <label class="field-label" for="sClosed">关闭工单通道时的提示</label>
        <input class="input" id="sClosed" type="text" name="ticket_closed_notice" maxlength="300"
               value="<?= e($settings['ticket_closed_notice']) ?>">
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="sPrefix">工单编号前缀</label>
          <input class="input" id="sPrefix" type="text" name="ticket_prefix" maxlength="6"
                 value="<?= e($settings['ticket_prefix']) ?>">
          <div class="field-tip">
            只保留字母与数字。编号形如 <span class="mono"><?= e($settings['ticket_prefix']) ?>20261003-7F3A2C</span>。
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="sRate">每小时提交上限</label>
          <input class="input" id="sRate" type="number" name="rate_limit_per_hour" min="0" max="200"
                 value="<?= e($settings['rate_limit_per_hour']) ?>">
          <div class="field-tip">按 IP 统计，0 表示不限制。</div>
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="sUpMb">单个附件上限（MB）</label>
          <input class="input" id="sUpMb" type="number" name="upload_max_mb" min="1" max="100"
                 value="<?= e($settings['upload_max_mb']) ?>">
          <div class="field-tip">必须不超过 PHP 的实际上限，否则用户上传会失败。</div>
        </div>
        <div class="field">
          <label class="field-label" for="sUpCnt">附件数量上限</label>
          <input class="input" id="sUpCnt" type="number" name="upload_max_count" min="1" max="20"
                 value="<?= e($settings['upload_max_count']) ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label class="field-label" for="sFaqPage">知识库每页条数</label>
          <input class="input" id="sFaqPage" type="number" name="faq_page_size" min="4" max="50"
                 value="<?= e($settings['faq_page_size']) ?>">
        </div>
        <div class="field">
          <label class="field-label" for="sTkPage">我的工单每页条数</label>
          <input class="input" id="sTkPage" type="number" name="ticket_page_size" min="5" max="50"
                 value="<?= e($settings['ticket_page_size']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="a-card">
    <div class="a-card-hd"><h2>用户注册</h2></div>
    <div class="a-card-bd">
      <div class="switch-row">
        <div class="sw-txt">
          <strong>开放注册</strong>
          <span>关闭后已有账号仍可登录，但不能再注册新账号。</span>
        </div>
        <label class="check">
          <input type="checkbox" name="reg_enabled" value="1"<?= $settings['reg_enabled'] === '1' ? ' checked' : '' ?>>
          <span>开启</span>
        </label>
      </div>

      <div class="switch-row">
        <div class="sw-txt">
          <strong>注册需要邮箱验证码</strong>
          <span>
            开启后必须配置好邮件通道，否则任何人都无法完成注册
            （保存时会做检查并阻止这种组合）。
          </span>
        </div>
        <label class="check">
          <input type="checkbox" name="reg_require_email_code" value="1"<?= $settings['reg_require_email_code'] === '1' ? ' checked' : '' ?>>
          <span>开启</span>
        </label>
      </div>
    </div>
    <div class="a-card-ft">
      <button class="btn btn-primary" type="submit">保存全部设置</button>
    </div>
  </div>
</form>

<div class="a-card">
  <div class="a-card-hd"><h2>系统信息</h2></div>
  <div class="a-card-bd">
    <dl class="kv">
      <?php foreach ($system as $label => $value): ?>
        <div>
          <dt><?= e((string)$label) ?></dt>
          <dd class="mono">
            <?php if (is_bool($value)): ?>
              <span style="color:<?= $value ? 'var(--ok-fg)' : 'var(--err-fg)' ?>"><?= $value ? '是' : '否' ?></span>
            <?php else: ?>
              <?= e((string)$value) ?>
            <?php endif; ?>
          </dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>
</div>

<div class="a-card">
  <div class="a-card-hd"><h2>数据库结构升级</h2></div>
  <div class="a-card-bd">
    <p class="small muted">
      本系统不在网页端提供「一键升级结构」的功能。原因：旧版那个页面把时间戳纠偏
      做成了一次单向判断，站点空闲一夜就会被误判为「时区错误」，随后把九张表的
      时间戳整体前移 8 小时；偏移之后判断条件反而更容易成立，于是每提交一次就再偏移一次，
      <strong>没有上界</strong>，数据被持续破坏。
    </p>
    <p class="small muted mb-0">
      结构升级请在服务器上执行命令行脚本，它会在改动前校验连接、逐条报告执行结果，
      并且可以重复运行：
    </p>
    <pre style="margin-top:12px;padding:13px 15px;background:var(--surface-2);border:1px solid var(--border-1);border-radius:var(--r,10px);overflow-x:auto;font-size:13px">cd <?= e(dirname((string)\App\Core\Config::string('app.root', ''), 1) ?: '/path/to/ticket-app') ?>

php bin/setup.php --force</pre>
  </div>
</div>
