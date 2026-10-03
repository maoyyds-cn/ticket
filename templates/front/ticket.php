<?php
/**
 * 工单详情
 *
 * @var array  $ticket
 * @var list<array> $replies
 * @var list<array> $attachments
 * @var bool   $hasKey
 * @var bool   $isNew
 * @var bool   $canReply
 * @var bool   $canRate
 * @var bool   $canClose
 * @var bool   $canReopen
 * @var object|null $staff
 * @var array  $captcha
 * @var int    $uploadMaxMb
 * @var int    $uploadMaxCount
 * @var int    $formOpenedAt
 * @var list<array> $logs
 * @var bool   $isStaff
 */
$status = (string)$ticket['status'];
$priority = (string)$ticket['priority'];
$statusTone = \App\Domain\Ticket\TicketStatus::tone($status);
$priorityTone = \App\Domain\Ticket\TicketPriority::tone($priority);
$baseUrl = url('/ticket/' . rawurlencode((string)$ticket['ticket_no']));
// 三个展示小工具由视图层统一注入（见 bootstrap.php），
// 模板不再自己解析附件 JSON
?> 
<div class="page">
  <div class="wrap">
    <!-- 页头 -->
    <div class="detail-hd">
      <nav class="crumb" aria-label="面包屑">
        <a href="<?= e(url('/')) ?>">首页</a>
        <span class="sep" aria-hidden="true">/</span>
        <a href="<?= e(url('/my-tickets')) ?>">我的工单</a>
        <span class="sep" aria-hidden="true">/</span>
        <span class="mono"><?= e((string)$ticket['ticket_no']) ?></span>
      </nav>

      <?php if ($isNew): ?>
        <div class="alert alert-ok mb-2" role="status">
          <span class="alert-ico" aria-hidden="true"><?= icon('check', 17) ?></span>
          <div class="alert-body">
            <div class="alert-title">工单提交成功，编号 <?= e((string)$ticket['ticket_no']) ?></div>
            <?php if ($hasKey): ?>
              访问密钥已记在本页面，请把这条链接收藏起来——建议现在就记录编号与密钥，
              <strong>密钥遗失后无法找回</strong>。
            <?php else: ?>
              你已登录，之后可以在「我的工单」里随时查看这条工单。
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <h1 class="detail-title"><?= e((string)$ticket['title']) ?></h1>
      <div class="row-wrap">
        <span class="badge badge-<?= e($statusTone) ?>">
          <span class="dot"></span><?= e(\App\Domain\Ticket\TicketStatus::label($status)) ?>
        </span>
        <span class="badge badge-<?= e($priorityTone) ?>">
          <?= e(\App\Domain\Ticket\TicketPriority::label($priority)) ?>优先级
        </span>
        <?php if (!empty($ticket['category_name'])): ?>
          <span class="badge badge-slate">
            <?= icon((string)$ticket['category_icon'], 15) ?> <?= e((string)$ticket['category_name']) ?>
          </span>
        <?php endif; ?>
        <span class="faint tiny">
          提交于 <?= e(\App\Support\Str::datetime((string)$ticket['created_at'])) ?> ·
          <?= e(\App\Support\Str::timeAgo((string)$ticket['updated_at'])) ?>更新
        </span>
      </div>
    </div>

    <div class="with-side">
      <div class="stack">
        <!-- 正文 -->
        <div class="card">
          <div class="card-hd">
            <h2>问题描述</h2>
            <span class="faint tiny"><?= e((string)$ticket['guest_name']) ?></span>
          </div>
          <div class="card-bd">
            <div class="prose">
              <?php
                // 先转义，再高亮 /xxx 形式的命令。顺序不能反：
                // 先高亮会把用户输入当 HTML 处理，形成存储型 XSS。
                $escaped = nl2br(e((string)$ticket['content']), false);
                echo \App\Support\Str::highlightCommands($escaped);
              ?>
            </div>
            <?php if ($attachments !== []): ?>
              <?php
                $attachments = $attachments;
                $compact = false;
                require dirname(__DIR__) . '/partials/attachments.php';
              ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- 会话时间线 -->
        <div class="card">
          <div class="card-hd">
            <h2>处理记录</h2>
            <span class="faint tiny"><?= e((string)count($replies)) ?> 条</span>
          </div>
          <div class="card-bd">
            <?php if ($replies === []): ?>
              <div class="alert alert-info">
                <span class="alert-ico" aria-hidden="true"><?= icon('clock', 17) ?></span>
                <div class="alert-body">
                  还没有人回复。客服收到后会在工作时段内处理，你也可以在下方补充信息。
                </div>
              </div>
            <?php else: ?>
              <div class="tl">
                <?php foreach ($replies as $reply):
                    $author = $ticketsAuthor($reply);
                    $isInternal = (int)$reply['is_internal'] === 1;
                    $replyAtt = $ticketsAttachments($reply['attachments'] ?? null);
                    $cls = 'tl-item' . ($author['is_staff'] ? ' is-staff' : '') . ($isInternal ? ' is-internal' : ''); ?>
                  <div class="<?= e($cls) ?>">
                    <div class="tl-dot" aria-hidden="true"><?= icon($author['is_staff'] ? 'shield' : 'user', 15) ?></div>
                    <div class="tl-card">
                      <div class="tl-hd">
                        <span class="tl-who"><?= e($author['name']) ?></span>
                        <?php if ($author['is_staff'] && $author['role_label'] !== ''): ?>
                          <span class="badge badge-<?= e($author['role_color']) ?>"><?= e($author['role_label']) ?></span>
                        <?php endif; ?>
                        <?php if ($isInternal): ?>
                          <span class="note-tag">内部备注 · 用户不可见</span>
                        <?php endif; ?>
                        <span class="tl-time"><?= e(\App\Support\Str::datetime((string)$reply['created_at'])) ?></span>
                      </div>
                      <div class="tl-bd">
                        <div class="prose">
                          <?php
                            $escaped = nl2br(e((string)$reply['content']), false);
                            echo \App\Support\Str::highlightCommands($escaped);
                          ?>
                        </div>
                        <?php if ($replyAtt !== []): ?>
                          <?php
                            $attachments = $replyAtt;
                            $compact = true;
                            require dirname(__DIR__) . '/partials/attachments.php';
                          ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- 评分 -->
        <?php if ($canRate): ?>
          <div class="card">
            <div class="card-hd"><h2>问题解决了吗？</h2></div>
            <div class="card-bd">
              <form data-guard method="post" action="<?= e($baseUrl . '/rate') ?>">
                <?= csrf_field() ?>
                <div class="field">
                  <span class="field-label" id="rateLabel">请选择满意度 <span class="req" aria-hidden="true">*</span></span>
                  <?php
                    /*
                     * 用真正的 radio，而不是「隐藏字段 + 几个 button 靠 JS 改写」。
                     * 后者在 JS 未加载/被拦时永远提交 rating=0，
                     * 于是每次评分都得到「请先选择 1–5 星的评价」——
                     * 而 app.js 开头明确写着「所有功能都必须没有 JS 也能用」。
                     * radio 天然支持键盘、天然随表单提交，样式用 CSS 做即可。
                     */
                  ?>
                  <div class="rate-row" data-rate role="radiogroup" aria-labelledby="rateLabel">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <input class="rate-input" type="radio" name="rating" id="rate<?= $i ?>"
                             value="<?= $i ?>" required>
                      <label class="rate-star" for="rate<?= $i ?>"
                             title="<?= e((string)$i) ?> 星">
                        <span class="sr-only"><?= e((string)$i) ?> 星</span>
                        <svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true">
                          <path d="m12 3.6 2.6 5.5 5.9.8-4.3 4.1 1.1 5.9L12 17.1 6.7 19.9l1.1-5.9-4.3-4.1 5.9-.8L12 3.6Z"
                                fill="none" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                      </label>
                    <?php endfor; ?>
                    <span class="muted tiny" data-rate-text>尚未评分</span>
                  </div>
                  <div class="field-tip">评分后会同时把工单标记为「已解决」。如果问题仍然存在，请先在下方回复说明。</div>
                </div>

                <div class="field">
                  <label class="field-label" for="rateNote">补充说明 <span class="opt">选填</span></label>
                  <input class="input" id="rateNote" type="text" name="rating_note" maxlength="255"
                         placeholder="例如：响应很快，问题解决了">
                </div>

                <button class="btn btn-primary" type="submit">提交评价</button>
              </form>
            </div>
          </div>
        <?php elseif ((int)$ticket['rating'] > 0): ?>
          <div class="card">
            <div class="card-hd"><h2>你的评价</h2></div>
            <div class="card-bd">
              <div class="row-wrap">
                <span class="rate-row" aria-label="<?= e((string)(int)$ticket['rating']) ?> 星评价">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="rate-star<?= $i <= (int)$ticket['rating'] ? ' is-on' : '' ?>">
                      <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m12 3.6 2.6 5.5 5.9.8-4.3 4.1 1.1 5.9L12 17.1 6.7 19.9l1.1-5.9-4.3-4.1 5.9-.8L12 3.6Z"
                              fill="<?= $i <= (int)$ticket['rating'] ? 'currentColor' : 'none' ?>"
                              stroke="currentColor" stroke-width="1.7"
                              stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </span>
                  <?php endfor; ?>
                </span>
                <span class="badge badge-amber"><?= e((string)(int)$ticket['rating']) ?> / 5</span>
              </div>
              <?php if ((string)$ticket['rating_note'] !== ''): ?>
                <p class="muted small mt-2 mb-0">「<?= e((string)$ticket['rating_note']) ?>」</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- 回复 -->
        <?php if ($canReply): ?>
          <div class="card">
            <div class="card-hd"><h2>补充信息 / 回复</h2></div>
            <form data-guard method="post" action="<?= e($baseUrl . '/reply') ?>" enctype="multipart/form-data" data-busy>
              <?= csrf_field() ?>
              <div class="card-bd">
                <div class="hp" aria-hidden="true">
                  <input type="text" name="hp_website" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                  <label class="field-label" for="replyContent">内容 <span class="req" aria-hidden="true">*</span></label>
                  <textarea class="textarea" id="replyContent" name="content" required
                            placeholder="补充说明、提供新的报错原文，或回复客服的提问"><?= old('content') ?></textarea>
                  <div class="field-tip row-between">
                    <span>不需要人机验证，直接发送即可。</span>
                    <span class="counter" data-count-for="content" data-count-max="20000">0 / 20000</span>
                  </div>
                </div>

                <div class="field">
                  <span class="field-label" id="replyUploadLabel">附件 <span class="opt">选填</span></span>
                  <label class="uploader" data-uploader for="replyFiles" role="button" tabindex="0"
                         aria-labelledby="replyUploadLabel">
                    <div class="uploader-ico" aria-hidden="true"><?= icon('clip', 24) ?></div>
                    <div class="uploader-t">点击选择文件</div>
                    <div class="uploader-s">最多 <?= e((string)$uploadMaxCount) ?> 个，单个不超过 <?= e((string)$uploadMaxMb) ?>MB</div>
                    <input id="replyFiles" type="file" name="files[]" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.txt,.log,.json,.csv,.zip,.rar,.7z,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.mp3">
                  </label>
                  <div class="files" data-filelist hidden></div>
                </div>
              </div>

              <div class="card-ft">
                <div class="row-between">
                  <span class="tiny muted" data-busy-hint hidden>正在发送…</span>
                  <div class="row-wrap">
                    <?php if ($canClose): ?>
                      <button class="btn btn-secondary" type="submit"
                              form="closeForm" data-confirm="确定要关闭这条工单吗？关闭后仍可重新打开。">关闭工单</button>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">发送回复</button>
                  </div>
                </div>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <!-- 关闭 / 重开 -->
        <?php if ($canClose): ?>
          <form id="closeForm" method="post" action="<?= e($baseUrl . '/close') ?>">
            <?= csrf_field() ?>
          </form>
        <?php endif; ?>

        <?php if ($canReopen): ?>
          <div class="card">
            <div class="card-bd">
              <div class="row-between">
                <div>
                  <strong class="small">问题又出现了？</strong>
                  <p class="tiny muted mb-0">重新打开后客服会收到通知并继续跟进。</p>
                </div>
                <form method="post" action="<?= e($baseUrl . '/reopen') ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-secondary" type="submit">重新打开工单</button>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($staff !== null): ?>
          <div class="alert alert-info">
            <span class="alert-ico" aria-hidden="true"><?= icon('shield', 17) ?></span>
            <div class="alert-body">
              你正以工作人员身份查看该工单。
              <a href="<?= e(url('/admin/tickets/' . (int)$ticket['id'])) ?>">前往后台处理 →</a>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- 侧栏 -->
      <aside class="side">
        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('clipboard', 17) ?></span>工单信息</div>
          <dl class="dl">
            <div><dt>编号</dt><dd class="mono"><?= e((string)$ticket['ticket_no']) ?></dd></div>
            <div><dt>状态</dt><dd><?= e(\App\Domain\Ticket\TicketStatus::label($status)) ?></dd></div>
            <div><dt>优先级</dt><dd><?= e(\App\Domain\Ticket\TicketPriority::label($priority)) ?></dd></div>
            <div><dt>分类</dt><dd><?= e((string)($ticket['category_name'] ?? '') ?: '未分类') ?></dd></div>
            <div>
              <dt>处理人</dt>
              <dd><?= e((string)($ticket['assignee_realname'] ?? '') ?: ((string)($ticket['assignee_name'] ?? '') ?: '待指派')) ?></dd>
            </div>
            <div><dt>回复数</dt><dd><?= e((string)(int)$ticket['reply_count']) ?></dd></div>
            <?php if (!empty($ticket['first_reply_at'])): ?>
              <div>
                <dt>首次响应</dt>
                <dd>
                  <?php
                    $mins = (int)round((strtotime((string)$ticket['first_reply_at']) - strtotime((string)$ticket['created_at'])) / 60);
                    echo e($mins < 60 ? $mins . ' 分钟' : round($mins / 60, 1) . ' 小时');
                  ?>
                </dd>
              </div>
            <?php endif; ?>
            <?php if (!empty($ticket['resolved_at'])): ?>
              <div><dt>解决时间</dt><dd><?= e(\App\Support\Str::datetime((string)$ticket['resolved_at'])) ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('user', 17) ?></span>提交者</div>
          <dl class="dl">
            <div><dt>称呼</dt><dd><?= e((string)$ticket['guest_name']) ?></dd></div>
            <div>
              <dt>类型</dt>
              <dd><?= (int)$ticket['user_id'] > 0 ? '注册用户' : '访客' ?></dd>
            </div>
            <?php if ((string)$ticket['contact_email'] !== ''): ?>
              <div>
                <dt>邮箱</dt>
                <dd class="mono">
                  <?= e($staff !== null
                        ? (string)$ticket['contact_email']
                        : \App\Support\Str::maskEmail((string)$ticket['contact_email'])) ?>
                </dd>
              </div>
            <?php endif; ?>
            <?php if ((string)$ticket['qq'] !== ''): ?>
              <div><dt>QQ</dt><dd class="mono"><?= e((string)$ticket['qq']) ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>

        <div class="panel">
          <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('key', 17) ?></span>访问方式</div>
          <?php if ($hasKey): ?>
            <p class="tiny muted">
              你正在使用访问密钥查看。密钥保存在本次会话中，页面地址里不含密钥，
              因此收藏当前链接后仍可继续访问（会话有效期内）。
            </p>
            <p class="tiny muted mb-0">
              <strong>建议同时记录工单编号与密钥</strong>。清除浏览器数据或更换设备后，
              需要重新输入密钥。
            </p>
          <?php elseif ($staff !== null): ?>
            <p class="tiny muted mb-0">工作人员不受访问密钥限制。</p>
          <?php elseif ($currentUser !== null): ?>
            <p class="tiny muted mb-0">这条工单关联在你的账号下，登录后即可查看。</p>
          <?php else: ?>
            <p class="tiny muted mb-0">
              这条工单未设置访问密钥，只有提交它的登录账号或工作人员可以查看。
            </p>
          <?php endif; ?>
        </div>

        <?php if ($logs !== []): ?>
          <div class="panel">
            <div class="panel-title"><span class="ico" aria-hidden="true"><?= icon('list', 17) ?></span>最近操作（仅内部可见）</div>
            <ul class="side-list">
              <?php foreach ($logs as $log): ?>
                <li style="display:block;border-bottom:1px solid var(--border-2)">
                  <div class="tiny" style="color:var(--text-2)"><?= e((string)$log['detail']) ?></div>
                  <div class="faint" style="font-size:11.5px">
                    <?= e((string)$log['staff_name']) ?> · <?= e(\App\Support\Str::timeAgo((string)$log['created_at'])) ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>
