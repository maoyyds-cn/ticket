<?php
/**
 * 账号设置
 *
 * @var object $user
 * @var int $ticketCount
 * @var int $openCount
 */
?>
<div class="page">
  <div class="wrap">
    <div class="detail-hd">
      <nav class="crumb" aria-label="面包屑">
        <a href="<?= e(url('/')) ?>">首页</a>
        <span class="sep" aria-hidden="true">/</span>
        <span>账号设置</span>
      </nav>
      <h1 class="detail-title">账号设置</h1>
      <p class="muted small mb-0">修改联系方式与登录密码。</p>
    </div>

    <div class="with-side">
      <div class="stack">
        <div class="card">
          <div class="card-hd"><h2>基本资料</h2></div>
          <form method="post" action="<?= e(url('/account/profile')) ?>">
            <?= csrf_field() ?>
            <div class="card-bd">
              <div class="field">
                <label class="field-label" for="aName">昵称</label>
                <input class="input" id="aName" type="text" name="realname" maxlength="50"
                       value="<?= e($user->displayName()) ?>">
                <div class="field-tip">显示在工单与回复中。</div>
              </div>
              <div class="form-row">
                <div class="field">
                  <label class="field-label" for="aEmail">邮箱</label>
                  <input class="input" id="aEmail" type="email" name="email" maxlength="120"
                         value="<?= e((string)$user->email) ?>">
                  <div class="field-tip">工单通知与身份找回都依赖它，请保持有效。</div>
                </div>
                <div class="field">
                  <label class="field-label" for="aQq">QQ 号 <span class="opt">选填</span></label>
                  <input class="input" id="aQq" type="text" name="qq" inputmode="numeric"
                         maxlength="12" value="<?= e((string)$user->qq) ?>">
                </div>
              </div>
              <div class="field mb-0">
                <span class="field-label">用户名</span>
                <input class="input" type="text" value="<?= e($user->username) ?>" disabled>
                <div class="field-tip">用户名创建后不可修改。</div>
              </div>
            </div>
            <div class="card-ft">
              <button class="btn btn-primary" type="submit">保存资料</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="card-hd"><h2>修改密码</h2></div>
          <form method="post" action="<?= e(url('/account/password')) ?>">
            <?= csrf_field() ?>
            <div class="card-bd">
              <div class="field">
                <label class="field-label" for="pCur">当前密码</label>
                <input class="input" id="pCur" type="password" name="current_password"
                       required autocomplete="current-password">
              </div>
              <div class="form-row">
                <div class="field">
                  <label class="field-label" for="pNew">新密码</label>
                  <input class="input" id="pNew" type="password" name="new_password"
                         required minlength="8" autocomplete="new-password">
                  <div class="field-tip">至少 8 位。</div>
                </div>
                <div class="field">
                  <label class="field-label" for="pNew2">确认新密码</label>
                  <input class="input" id="pNew2" type="password" name="new_password2"
                         required minlength="8" autocomplete="new-password">
                </div>
              </div>
            </div>
            <div class="card-ft">
              <button class="btn btn-primary" type="submit">更新密码</button>
            </div>
          </form>
        </div>
      </div>

      <aside class="side">
        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('chart', 17) ?></span>我的数据</div>
          <ul class="side-list">
            <li><span>提交的工单</span><span class="n"><?= e((string)$ticketCount) ?></span></li>
            <li><span>未完结</span><span class="n"><?= e((string)$openCount) ?></span></li>
            <li><span>注册时间</span><span class="n"><?= e(\App\Support\Str::datetime((string)$user->row()['created_at'], 'Y-m-d')) ?></span></li>
            <li><span>上次登录</span><span class="n"><?= e(\App\Support\Str::timeAgo((string)($user->row()['last_login_at'] ?? ''))) ?></span></li>
          </ul>
        </div>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('ticket', 17) ?></span>快捷入口</div>
          <div class="stack-sm">
            <a class="btn btn-secondary btn-block btn-sm" href="<?= e(url('/my-tickets')) ?>">我的工单</a>
            <a class="btn btn-secondary btn-block btn-sm" href="<?= e(url('/knowledge')) ?>">知识库</a>
          </div>
        </div>
      </aside>
    </div>
  </div>
</div>
