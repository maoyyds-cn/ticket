<?php
/**
 * 后台工单处理
 *
 * @var array  $ticket
 * @var list<array> $replies
 * @var list<array> $attachments
 * @var list<array> $logs
 * @var list<array> $assignees
 * @var list<array> $categories
 * @var list<string> $nextStates
 * @var bool   $canDelete
 * @var bool   $canAssign
 * @var object $staff
 * @var int    $uploadMaxMb
 * @var int    $uploadMaxCount
 */
$status = (string)$ticket['status'];
$priority = (string)$ticket['priority'];
$baseUrl = url('/admin/tickets/' . (int)$ticket['id']);
$back = '/admin/tickets/' . (int)$ticket['id'];
?>
<div class="a-card">
  <div class="a-card-hd">
    <div>
      <div class="row-wrap">
        <span class="badge badge-<?= e(\App\Domain\Ticket\TicketStatus::tone($status)) ?>">
          <span class="dot"></span><?= e(\App\Domain\Ticket\TicketStatus::label($status)) ?>
        </span>
        <span class="badge badge-<?= e(\App\Domain\Ticket\TicketPriority::tone($priority)) ?>">
          <?= e(\App\Domain\Ticket\TicketPriority::label($priority)) ?>
        </span>
        <span class="mono tiny faint"><?= e((string)$ticket['ticket_no']) ?></span>
      </div>
    </div>
    <div class="row-wrap">
      <a class="btn btn-secondary btn-sm"
         href="<?= e(url('/ticket/' . rawurlencode((string)$ticket['ticket_no']))) ?>"
         target="_blank" rel="noopener">用户视角 ↗</a>
      <?php if ($canDelete): ?>
        <form method="post" action="<?= e($baseUrl . '/delete') ?>"
              data-confirm-form="确定要删除这条工单吗？此操作不可撤销，工单及其回复与附件都会被永久删除。">
          <?= csrf_field() ?>
          <button class="btn btn-danger-soft btn-sm" type="submit">删除工单</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- 状态快捷切换 -->
  <div class="a-card-bd" style="padding-top:14px;padding-bottom:14px;border-bottom:1px solid var(--border-2)">
    <div class="row-wrap">
      <span class="faint tiny">改为状态：</span>
      <?php if ($nextStates === []): ?>
        <span class="faint tiny">当前状态没有可切换的目标</span>
      <?php else: ?>
        <?php foreach ($nextStates as $s): ?>
          <form method="post" action="<?= e($baseUrl . '/status') ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= e($s) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="chip" type="submit"
                    <?= $s === 'spam' ? 'data-confirm="标记为垃圾工单？用户将无法再查看。"' : '' ?>>
              <?= e(\App\Domain\Ticket\TicketStatus::label($s)) ?>
            </button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="a-split">
  <div>
    <!-- 原始内容 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>问题描述</h2></div>
      <div class="a-card-bd">
        <div class="origin">
          <div class="prose">
            <?php
              $escaped = nl2br(e((string)$ticket['content']), false);
              echo \App\Support\Str::highlightCommands($escaped);
            ?>
          </div>
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

    <!-- 会话 -->
    <div class="a-card">
      <div class="a-card-hd">
        <h2>处理记录</h2>
        <span class="faint tiny"><?= e((string)count($replies)) ?> 条</span>
      </div>
      <div class="a-card-bd">
        <?php if ($replies === []): ?>
          <p class="muted small mb-0">还没有回复记录。</p>
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
                    <?php else: ?>
                      <span class="badge badge-slate">用户</span>
                    <?php endif; ?>
                    <?php if ($isInternal): ?>
                      <span class="note-tag">内部备注</span>
                    <?php endif; ?>
                    <?php if ((string)$reply['new_status'] !== ''): ?>
                      <span class="badge badge-plain">状态 → <?= e(\App\Domain\Ticket\TicketStatus::label((string)$reply['new_status'])) ?></span>
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

                    <?php if ($canDelete): ?>
                      <form data-guard method="post"
                            action="<?= e($baseUrl . '/reply/' . (int)$reply['id'] . '/delete') ?>"
                            style="margin-top:12px"
                            data-confirm-form="确定删除这条<?= $isInternal ? '内部备注' : '回复' ?>吗？此操作不可撤销。">
                        <?= csrf_field() ?>
                        <input type="hidden" name="back" value="<?= e($back) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">删除这条</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 回复表单 -->
    <div class="a-card">
      <form data-guard method="post" action="<?= e($baseUrl . '/reply') ?>" enctype="multipart/form-data" data-busy>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">

        <div class="a-card-hd"><h2>回复用户 / 添加内部备注</h2></div>
        <div class="a-card-bd">
          <div class="field">
            <label class="field-label" for="replyContent">内容</label>
            <div class="ed-bar" data-ed-wrap="#replyContent">
              <button type="button" data-ed="bold">强调</button>
              <button type="button" data-ed="bullet">项目符号</button>
              <button type="button" data-ed="number">编号步骤</button>
              <button type="button" data-ed="code">命令</button>
              <button type="button" data-ed="divider">分隔线</button>
            </div>
            <textarea class="textarea textarea-lg" id="replyContent" name="content" required
                      placeholder="回复内容会通过邮件通知用户；内部备注则只有工作人员可见。"><?= old('content') ?></textarea>
            <div class="field-tip row-between">
              <span>支持换行与列表符号。以 / 开头的命令会在用户侧高亮显示。</span>
              <span class="counter" data-count-for="content" data-count-max="20000">0 / 20000</span>
            </div>
          </div>

          <div class="field">
            <span class="field-label" id="admUploadLabel">附件 <span class="opt">选填</span></span>
            <label class="uploader" data-uploader for="admFiles" role="button" tabindex="0"
                   aria-labelledby="admUploadLabel">
              <div class="uploader-ico" aria-hidden="true"><?= icon('clip', 24) ?></div>
              <div class="uploader-t">点击选择文件</div>
              <div class="uploader-s">
                最多 <?= e((string)$uploadMaxCount) ?> 个，单个不超过 <?= e((string)$uploadMaxMb) ?>MB
              </div>
              <input id="admFiles" type="file" name="files[]" multiple
                     accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.txt,.log,.json,.csv,.zip,.rar,.7z,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.mp3">
            </label>
            <div class="files" data-filelist hidden></div>
          </div>

          <div class="form-row">
            <div class="field">
              <label class="field-label" for="newStatus">回复后状态</label>
              <select class="select" id="newStatus" name="new_status">
                <option value="">保持不变</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <?php if ($value === $status) { continue; } ?>
                  <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="field-tip">只能选择从当前状态可达的状态，否则会被拒绝。</div>
            </div>
            <?php if ($staff?->can('ticket.internal_note')): ?>
              <div class="field">
                <span class="field-label">可见性</span>
                <label class="check">
                  <input type="checkbox" name="is_internal" value="1">
                  <span>作为内部备注（用户不可见，不发送通知，不改变状态）</span>
                </label>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="a-card-ft">
          <div class="row-between">
            <span class="tiny muted" data-busy-hint hidden>正在发送…</span>
            <button class="btn btn-primary" type="submit">发送</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- 侧栏 -->
  <div class="a-side">
    <!-- 属性 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>工单属性</h2></div>
      <form method="post" action="<?= e($baseUrl . '/attributes') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="a-card-bd">
          <div class="field">
            <label class="field-label" for="aPriority">优先级</label>
            <select class="select" id="aPriority" name="priority">
              <?php foreach ($priorityOptions as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $value === $priority ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field-label" for="aAssignee">处理人</label>
            <select class="select" id="aAssignee" name="assignee_id"<?= $canAssign ? '' : ' disabled' ?>>
              <?php /* 0 是可选项：旧版的表单里有「未分类」却会被后端拒绝，
                     这里把「取消指派」也做成合法选择 */ ?>
              <option value="0"<?= (int)$ticket['assignee_id'] === 0 ? ' selected' : '' ?>>未指派</option>
              <?php foreach ($assignees as $a): ?>
                <option value="<?= e((string)(int)$a['id']) ?>"
                        <?= (int)$ticket['assignee_id'] === (int)$a['id'] ? ' selected' : '' ?>>
                  <?= e((string)($a['realname'] ?: $a['username'])) ?>
                  （<?= e(\App\Domain\Staff\Role::label((string)$a['role'])) ?>）
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field-label" for="aCategory">分类</label>
            <select class="select" id="aCategory" name="category_id">
              <option value="0"<?= (int)$ticket['category_id'] === 0 ? ' selected' : '' ?>>未分类</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= e((string)(int)$c['id']) ?>"
                        <?= (int)$ticket['category_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                  <?= e((string)$c['icon'] . ' ' . (string)$c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="a-card-ft">
          <button class="btn btn-primary btn-block btn-sm" type="submit">保存属性</button>
        </div>
      </form>
    </div>

    <!-- 提交者 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>提交者信息</h2></div>
      <div class="a-card-bd">
        <dl class="kv">
          <div><dt>称呼</dt><dd><?= e((string)$ticket['guest_name']) ?></dd></div>
          <div>
            <dt>类型</dt>
            <dd><?= (int)$ticket['user_id'] > 0 ? '注册用户 #' . (int)$ticket['user_id'] : '访客' ?></dd>
          </div>
          <div>
            <dt>邮箱</dt>
            <dd class="mono"><?= e((string)$ticket['contact_email'] ?: '—') ?></dd>
          </div>
          <div><dt>QQ</dt><dd class="mono"><?= e((string)$ticket['qq'] ?: '—') ?></dd></div>
          <div><dt>提交时间</dt><dd><?= e(\App\Support\Str::datetime((string)$ticket['created_at'])) ?></dd></div>
          <div><dt>最后更新</dt><dd><?= e(\App\Support\Str::datetime((string)$ticket['updated_at'])) ?></dd></div>
          <div><dt>浏览数</dt><dd><?= e((string)(int)$ticket['view_count']) ?></dd></div>
          <?php if ((int)$ticket['rating'] > 0): ?>
            <div>
              <dt>用户评分</dt>
              <dd><?= e((string)(int)$ticket['rating']) ?> / 5
                <?php if ((string)$ticket['rating_note'] !== ''): ?>
                  <span class="faint">（<?= e((string)$ticket['rating_note']) ?>）</span>
                <?php endif; ?>
              </dd>
            </div>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <!-- 访问密钥 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>访客访问密钥</h2></div>
      <div class="a-card-bd">
        <?php if ((string)$ticket['access_hash'] !== ''): ?>
          <p class="tiny muted">
            该工单设置了访问密钥，访客可凭「编号 + 密钥」查看。
            数据库中只保存哈希值，无法查看原文。
          </p>
          <form method="post" action="<?= e($baseUrl . '/revoke-key') ?>"
                data-confirm-form="确定撤销访问密钥吗？撤销后持旧密钥的访客将无法再查看该工单，且此操作不可恢复。">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="btn btn-danger-soft btn-block btn-sm" type="submit">撤销访问密钥</button>
          </form>
        <?php else: ?>
          <p class="tiny muted mb-0">
            该工单没有访问密钥，只有提交它的登录账号和工作人员可以查看。
          </p>
        <?php endif; ?>
      </div>
    </div>

    <!-- 操作日志 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>操作日志</h2></div>
      <div class="a-card-bd">
        <?php if ($logs === []): ?>
          <p class="tiny muted mb-0">暂无操作记录。</p>
        <?php else: ?>
          <ul class="side-list">
            <?php foreach ($logs as $log): ?>
              <li style="display:block">
                <div class="tiny" style="color:var(--text-2)"><?= e((string)$log['detail']) ?></div>
                <div class="faint" style="font-size:11.5px">
                  <?= e((string)$log['staff_name']) ?> ·
                  <?= e(\App\Support\Str::datetime((string)$log['created_at'], 'm-d H:i')) ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- 设备信息 -->
    <div class="a-card">
      <div class="a-card-hd"><h2>提交环境</h2></div>
      <div class="a-card-bd">
        <dl class="kv">
          <div><dt>IP</dt><dd class="mono"><?= e((string)$ticket['ip'] ?: '—') ?></dd></div>
          <div><dt>来源</dt><dd><?= e((string)$ticket['source']) ?></dd></div>
        </dl>
        <?php if ((string)$ticket['user_agent'] !== ''): ?>
          <p class="tiny faint mt-2 mb-0 mono" style="word-break:break-all">
            <?= e((string)$ticket['user_agent']) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
