<?php
/**
 * 后台「我的账号」
 *
 * @var object $staff
 * @var list<array> $logins
 * @var int $todo
 */
?>
<div class="a-split">
  <div>
    <div class="a-card">
      <div class="a-card-hd"><h2>基本资料</h2></div>
      <form method="post" action="<?= e(url('/admin/account/profile')) ?>">
        <?= csrf_field() ?>
        <div class="a-card-bd">
          <div class="form-row">
            <div class="field">
              <label class="field-label" for="meName">姓名 / 昵称</label>
              <input class="input" id="meName" type="text" name="realname" maxlength="50"
                     value="<?= e($staff->realname) ?>">
              <div class="field-tip">会显示在工单回复的头衔后面。</div>
            </div>
            <div class="field">
              <label class="field-label" for="meMail">邮箱 <span class="opt">选填</span></label>
              <input class="input" id="meMail" type="email" name="email" maxlength="120"
                     value="<?= e($staff->email) ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="field mb-0">
              <span class="field-label">登录名</span>
              <input class="input" type="text" value="<?= e($staff->username) ?>" disabled>
              <div class="field-tip">登录名不可修改。</div>
            </div>
            <div class="field mb-0">
              <span class="field-label">角色</span>
              <input class="input" type="text"
                     value="<?= e(\App\Domain\Staff\Role::label($staff->role)) ?>" disabled>
              <div class="field-tip">
                角色由其他管理员分配；你无法修改自己的角色，以免把自己锁在权限之外。
              </div>
            </div>
          </div>
        </div>
        <div class="a-card-ft">
          <button class="btn btn-primary" type="submit">保存资料</button>
        </div>
      </form>
    </div>

    <div class="a-card">
      <div class="a-card-hd"><h2>修改密码</h2></div>
      <form method="post" action="<?= e(url('/admin/account/password')) ?>">
        <?= csrf_field() ?>
        <div class="a-card-bd">
          <div class="field">
            <label class="field-label" for="meCur">当前密码</label>
            <input class="input" id="meCur" type="password" name="current_password"
                   required autocomplete="current-password">
            <div class="field-tip">修改密码需要验证当前密码，避免会话被他人短暂使用时被永久接管账号。</div>
          </div>
          <div class="form-row">
            <div class="field mb-0">
              <label class="field-label" for="meNew">新密码</label>
              <input class="input" id="meNew" type="password" name="new_password"
                     required minlength="8" maxlength="72" autocomplete="new-password">
              <div class="field-tip">至少 8 位。</div>
            </div>
            <div class="field mb-0">
              <label class="field-label" for="meNew2">确认新密码</label>
              <input class="input" id="meNew2" type="password" name="new_password2"
                     required minlength="8" maxlength="72" autocomplete="new-password">
            </div>
          </div>
        </div>
        <div class="a-card-ft">
          <button class="btn btn-primary" type="submit">更新密码</button>
        </div>
      </form>
    </div>
  </div>

  <div class="a-side">
    <div class="a-card">
      <div class="a-card-hd"><h2>我的权限</h2></div>
      <div class="a-card-bd">
        <div class="row-wrap mb-2">
          <span class="badge badge-<?= e(\App\Domain\Staff\Role::color($staff->role)) ?>">
            <?= e(\App\Domain\Staff\Role::label($staff->role)) ?>
          </span>
        </div>
        <p class="tiny muted">
          <?= e(\App\Domain\Staff\Role::description($staff->role)) ?>
        </p>
        <?php $manageable = \App\Domain\Staff\Role::manageableRoles($staff->role); ?>
        <dl class="kv">
          <div>
            <dt>可任免角色</dt>
            <dd><?= $manageable === [] ? '<span class="faint">无</span>' : e(implode('、', $manageable)) ?></dd>
          </div>
          <div>
            <dt>我的待办工单</dt>
            <dd>
              <?php if ($todo > 0): ?>
                <a href="<?= e(url('/admin/tickets?assignee=' . $staff?->id)) ?>"><?= e((string)$todo) ?> 条</a>
              <?php else: ?>
                <span class="faint">无</span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>上次登录</dt>
            <dd><?= e(\App\Support\Str::timeAgo((string)($staff->row()['last_login_at'] ?? ''))) ?></dd>
          </div>
          <div>
            <dt>上次登录 IP</dt>
            <dd class="mono"><?= e((string)($staff->row()['last_login_ip'] ?? '') ?: '—') ?></dd>
          </div>
        </dl>
      </div>
    </div>

    <div class="a-card">
      <div class="a-card-hd"><h2>最近登录记录</h2></div>
      <div class="a-card-bd">
        <?php if ($logins === []): ?>
          <p class="tiny muted mb-0">暂无记录。</p>
        <?php else: ?>
          <ul class="side-list">
            <?php foreach ($logins as $l): ?>
              <li style="display:block">
                <div class="tiny mono" style="color:var(--text-2)"><?= e((string)$l['ip']) ?></div>
                <div class="faint" style="font-size:11.5px">
                  <?= e(\App\Support\Str::datetime((string)$l['created_at'], 'm-d H:i')) ?>
                  <?php if ((string)$l['user_agent'] !== ''): ?>
                    · <?= e(\App\Support\Str::limit((string)$l['user_agent'], 30)) ?>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="a-card">
      <div class="a-card-hd"><h2>安全说明</h2></div>
      <div class="a-card-bd">
        <p class="tiny muted mb-0">
          后台会话在 30 分钟无操作后自动失效。登录连续失败会按次数递增锁定时间。
          修改密码后当前会话会重新生成，其他设备上的登录状态不受影响，
          如怀疑账号异常请联系超级管理员。
        </p>
      </div>
    </div>
  </div>
</div>
