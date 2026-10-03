<?php
/**
 * 提交工单
 *
 * @var list<array> $categories
 * @var object|null $user
 * @var array $captcha
 * @var int $uploadMaxMb
 * @var int $uploadMaxCount
 * @var int $formOpenedAt
 * @var bool $ticketEnabled
 */
?>
<div class="page">
  <div class="wrap">
    <div class="detail-hd">
      <nav class="crumb" aria-label="面包屑">
        <a href="<?= e(url('/')) ?>">首页</a>
        <span class="sep" aria-hidden="true">/</span>
        <span>提交工单</span>
      </nav>
      <h1 class="detail-title">提交工单</h1>
      <p class="muted small mb-0">
        描述越具体，处理越快。附上报错原文或截图通常能省掉一轮来回。
      </p>
    </div>

    <div class="with-side">
      <!-- 表单 -->
      <form data-guard method="post" action="<?= e(url('/submit')) ?>" enctype="multipart/form-data" data-busy>
        <?= csrf_field() ?>
        <input type="hidden" name="form_opened_at" value="<?= e((string)$formOpenedAt) ?>">

        <?php /* 蜜罐：真人看不到也不会填；自动填表的机器人会填 */ ?>
        <div class="hp" aria-hidden="true">
          <label for="hpWebsite">请勿填写此项</label>
          <input id="hpWebsite" type="text" name="hp_website" tabindex="-1" autocomplete="off">
          <input type="email" name="hp_email" tabindex="-1" autocomplete="off">
        </div>

        <div class="card">
          <div class="card-hd"><h2>问题信息</h2></div>
          <div class="card-bd">
            <div class="field">
              <label class="field-label" for="fCategory">问题分类</label>
              <select class="select" id="fCategory" name="category_id">
                <option value="0">请选择分类（不确定可以留空）</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= e((string)(int)$c['id']) ?>"<?= (string)old('category_id') === (string)$c['id'] ? ' selected' : '' ?>>
                    <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-tip">选对分类能让对应方向的客服更快看到你的工单。</div>
            </div>

            <div class="field">
              <label class="field-label" for="fTitle">问题标题 <span class="req" aria-hidden="true">*</span></label>
              <input class="input" id="fTitle" type="text" name="title" required maxlength="200"
                     value="<?= old('title') ?>" placeholder="一句话概括问题，例如：查询玩家时提示查不到该用户">
              <div class="field-tip">4–200 字。避免只写「求助」「出问题了」这类无法判断的标题。</div>
            </div>

            <div class="field">
              <label class="field-label" for="fContent">详细描述 <span class="req" aria-hidden="true">*</span></label>
              <textarea class="textarea textarea-lg" id="fContent" name="content" required
                        placeholder="请说明：&#10;1. 你执行了什么操作（完整命令）&#10;2. 期望看到什么结果&#10;3. 实际出现了什么（把报错原文复制过来）"><?= old('content') ?></textarea>
              <div class="field-tip row-between">
                <span>10 字以上。包含完整命令与报错原文，能显著加快处理。</span>
                <span class="counter" data-count-for="content" data-count-max="20000">0 / 20000</span>
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="fPriority">优先级</label>
              <select class="select" id="fPriority" name="priority">
                <?php foreach (\App\Domain\Ticket\TicketPriority::options() as $value => $label): ?>
                  <option value="<?= e($value) ?>"<?= old('priority', 'normal') === $value ? ' selected' : '' ?>>
                    <?= e($label) ?><?= $value === 'urgent' ? '（仅在服务完全不可用时选择）' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-tip">紧急工单请务必说明影响范围，否则会被当作普通工单处理。</div>
            </div>
          </div>
        </div>

        <div class="card mt-2">
          <div class="card-hd"><h2>联系方式</h2></div>
          <div class="card-bd">
            <?php if ($user !== null): ?>
              <div class="alert alert-info mb-2">
                <span class="alert-ico" aria-hidden="true"><?= icon('info', 17) ?></span>
                <div class="alert-body">
                  你已登录为 <strong><?= e($user->displayName()) ?></strong>，
                  回复会直接显示在「我的工单」里。邮箱用于额外通知。
                </div>
              </div>
            <?php endif; ?>

            <div class="form-row">
              <div class="field">
                <label class="field-label" for="fEmail">
                  联系邮箱 <?= $user === null ? '<span class="req" aria-hidden="true">*</span>' : '<span class="opt">选填</span>' ?>
                </label>
                <input class="input" id="fEmail" type="email" name="email"
                       value="<?= old('email', $user !== null ? (string)$user->email : '') ?>"
                       maxlength="120" placeholder="you@example.com">
                <div class="field-tip">
                  <?= $user === null
                      ? '用于接收处理进展通知，请填常用邮箱。'
                      : '填写后处理进展也会发到这个邮箱。' ?>
                </div>
              </div>

              <div class="field">
                <label class="field-label" for="fQq">QQ 号 <span class="opt">选填</span></label>
                <input class="input" id="fQq" type="text" name="qq" inputmode="numeric"
                       value="<?= old('qq', $user !== null ? (string)$user->qq : '') ?>"
                       maxlength="12" placeholder="便于进一步沟通">
                <div class="field-tip">只在需要补充信息时使用，不会公开展示。</div>
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="fName">昵称 <span class="opt">选填</span></label>
              <input class="input" id="fName" type="text" name="guest_name" maxlength="50"
                     value="<?= old('guest_name', $user !== null ? $user->displayName() : '') ?>"
                     placeholder="客服对你的称呼">
            </div>

            <?php if ($user === null): ?>
              <div class="field">
                <label class="field-label" for="fKey">访问密钥 <span class="opt">建议设置</span></label>
                <input class="input" id="fKey" type="text" name="access_key"
                       value="" autocomplete="new-password" maxlength="64"
                       placeholder="至少 8 位，含字母、数字或符号中的两类">
                <div class="field-tip">
                  未登录的情况下，凭「工单编号 + 此密钥」查看进度。密钥只保存哈希值，
                  <strong>遗失后我们无法帮你找回</strong>，请自行记录。
                  不设密钥则只能通过注册账号后登录查看。
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card mt-2">
          <div class="card-hd"><h2>附件与验证</h2></div>
          <div class="card-bd">
            <div class="field">
              <span class="field-label" id="uploadLabel">截图或日志文件 <span class="opt">选填</span></span>
              <label class="uploader" data-uploader for="fFiles" role="button" tabindex="0"
                     aria-labelledby="uploadLabel">
                <div class="uploader-ico" aria-hidden="true"><?= icon('clip', 24) ?></div>
                <div class="uploader-t">点击选择文件，或把文件拖到这里</div>
                <div class="uploader-s">
                  最多 <?= e((string)$uploadMaxCount) ?> 个，单个不超过 <?= e((string)$uploadMaxMb) ?>MB
                </div>
                <input id="fFiles" type="file" name="files[]" multiple
                       accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.txt,.log,.json,.csv,.zip,.rar,.7z,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.mp3">
              </label>
              <div class="files" data-filelist hidden></div>
            </div>

            <div class="field">
              <label class="field-label" for="fCaptcha">
                人机验证 <span class="req" aria-hidden="true">*</span>
              </label>
              <div class="row-wrap">
                <span class="badge badge-slate mono" style="font-size:15px;padding:7px 14px">
                  <?= e((string)$captcha['question']) ?> = ?
                </span>
                <label class="sr-only" for="fCaptcha">请输入计算结果</label>
                <input class="input" id="fCaptcha" type="text" name="captcha" inputmode="numeric"
                       required autocomplete="off" style="width:110px" placeholder="答案">
              </div>
              <input type="hidden" name="captcha_sig" value="<?= e((string)$captcha['sig']) ?>">
              <input type="hidden" name="captcha_a" value="<?= e((string)$captcha['a']) ?>">
              <input type="hidden" name="captcha_b" value="<?= e((string)$captcha['b']) ?>">
              <input type="hidden" name="captcha_op" value="<?= e((string)$captcha['op']) ?>">
              <input type="hidden" name="captcha_ts" value="<?= e((string)$captcha['ts']) ?>">
              <div class="field-tip">用于拦截自动提交，答错可以刷新页面重新获取题目。</div>
            </div>
          </div>

          <div class="card-ft">
            <div class="row-between">
              <span class="tiny muted" data-busy-hint hidden>正在提交，请稍候…</span>
              <div class="row-wrap">
                <a class="btn btn-secondary" href="<?= e(url('/knowledge')) ?>">先去知识库找找</a>
                <button class="btn btn-primary" type="submit">提交工单</button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- 侧栏 -->
      <aside class="side">
        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('check-circle', 17) ?></span>提交前自查</div>
          <ul class="side-list" style="display:block">
            <li style="display:block;border:0;padding:5px 0">· 已到<a href="<?= e(url('/knowledge')) ?>">知识库</a>搜过关键词？</li>
            <li style="display:block;border:0;padding:5px 0">· 命令是否完整复制（含斜杠）？</li>
            <li style="display:block;border:0;padding:5px 0">· 报错原文是否已附上？</li>
            <li style="display:block;border:0;padding:5px 0">· 是否说明了期望结果与实际结果？</li>
          </ul>
        </div>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('lock', 17) ?></span>隐私说明</div>
          <p class="tiny muted mb-0">
            我们只记录与机器人交互所必需的信息（命令内容、时间、发起者标识），
            用于排障与审计。<strong>本系统从不需要你的 Roblox 密码</strong>，
            任何索要密码的都不是本站。
          </p>
        </div>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('clock', 17) ?></span>处理时长</div>
          <p class="tiny muted mb-0">
            工作时段内一般在数小时内响应；复杂问题可能需要更长时间。
            提交后请耐心等待，重复提交不会加快处理。
          </p>
        </div>
      </aside>
    </div>
  </div>
</div>
