<?php
/**
 * 工单详情
 */
require __DIR__ . '/includes/bootstrap.php';

$no  = trim((string)get('no'));
$key = (string)get('key');
$t   = $no !== '' ? ticket_find($no) : null;

if (!$t || !ticket_can_access($t, $key)) {
    http_response_code(404);
    $pageTitle = '工单不存在';
    $activeNav = '';
    require TPL_PATH . '/header.php';
    echo '<main><div class="container"><div class="empty" style="padding:90px 20px">'
        . '<div class="ic">🔒</div><h3>无法查看该工单</h3>'
        . '<p>工单不存在、已删除，或你没有访问权限</p>'
        . '<a class="btn btn-p" href="' . e(site_url('my-tickets.php')) . '">查看我的工单</a>'
        . '</div></div></main>';
    require TPL_PATH . '/footer.php';
    exit;
}

$isNew    = get('new') === '1';
$isAdminV = is_admin();
$canReply = !in_array($t['status'], ['closed', 'spam'], true);

// 浏览计数（同一会话只计一次）
if (empty($_SESSION['viewed'][(int)$t['id']])) {
    db_query('UPDATE ' . DB_PRE . 'ticket SET view_count = view_count + 1 WHERE id = ?', [(int)$t['id']]);
    $_SESSION['viewed'][(int)$t['id']] = 1;
}

// ---- 提交新回复 ----
// CSRF 校验独立于状态，否则已关闭工单的 POST 会被静默跳过
if (is_post()) {
    csrf_guard();
    $act = post('action');
    $content = post('content');

    // 重新打开：仅对已关闭/已解决工单有效
    if ($act === 'reopen' && !$canReply) {
        db_query('UPDATE ' . DB_PRE . 'ticket SET status = "processing", closed_at = NULL WHERE id = ?', [(int)$t['id']]);
        flash('ok', '工单已重新打开');
        redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
    }

    if ($act === 'reply' && $canReply) {
        if (mb_strlen($content) < 2) {
            flash('error', '回复内容不能为空');
            return;
        }

        // 人机验证：登录用户、游客一视同仁。
        // 客服回复走后台 ticket-view.php，不经过这里。
        $ts = turnstile_verify('reply');
        if (!$ts['ok']) {
            flash('error', $ts['msg']);
            redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
        }

        if (!reply_rate_guard((int)$t['id'])) {
            // 频控拦截，reply_rate_guard 内部已提示
            redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
        }

        $att = save_uploads($_FILES['files'] ?? []);
        try {
            db_insert('INSERT INTO ' . DB_PRE . 'ticket_reply (ticket_id, admin_id, author_name, content, attachments, ip) VALUES (?,0,?,?,?,?)', [
                (int)$t['id'],
                mb_substr(post('author_name') ?: ($t['guest_name'] ?: '访客'), 0, 50),
                $content,
                $att ? json_encode($att, JSON_UNESCAPED_UNICODE) : null,
                client_ip(),
            ]);
        } catch (PDOException $ex) {
            // 原实现直接抛异常，用户只看到空白/500，日志里也没有痕迹
            app_error_log('[Reply] 插入失败: ' . $ex->getMessage()
                . ' | SQLSTATE=' . ($ex->getCode() ?? '')
                . ' | errno=' . ($ex->errorInfo[1] ?? '?'));
            flash('error', '回复提交失败，请稍后重试');
            redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
        }

        db_query('UPDATE ' . DB_PRE . 'ticket SET reply_count = reply_count + 1 WHERE id = ?', [(int)$t['id']]);
        if (empty($t['first_reply_at'])) {
            db_query('UPDATE ' . DB_PRE . 'ticket SET first_reply_at = NOW() WHERE id = ?', [(int)$t['id']]);
        }
        // 用户自己回复不发邮件：收件人就是他自己，内容刷新后就在页面上。
        // 状态也不改成 replied —— 那是「客服已回复」的语义，
        // 用户回复应保持原状态，客服侧才能正确看到工单仍待自己处理。
        flash('ok', '回复已提交');
        redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
    }

    if ($act === 'resolve' && $canReply) {
        db_query('UPDATE ' . DB_PRE . 'ticket SET status = "resolved", resolved_at = NOW(), rating = ?, rating_note = ? WHERE id = ?', [
            max(0, min(5, (int)post('rating'))),
            mb_substr(post('rating_note'), 0, 250),
            (int)$t['id'],
        ]);
        flash('ok', '感谢反馈，工单已标记为已解决');
        redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
    }

    if ($act === 'close' && ticket_user_can_close($t)) {
        db_query('UPDATE ' . DB_PRE . 'ticket SET status = "closed", closed_at = NOW() WHERE id = ?', [(int)$t['id']]);
        flash('ok', '工单已关闭');
        redirect('ticket-view.php?no=' . urlencode($no) . '&key=' . urlencode($key));
    }
}

$replies = ticket_replies((int)$t['id'], $isAdminV);
$hasKey  = (string)($t['access_key'] ?? '') !== '';
$logs    = $isAdminV ? ticket_logs((int)$t['id']) : [];
$visitor = current_user();

$pageTitle = '工单 ' . $t['ticket_no'];
$activeNav = 'my';
require TPL_PATH . '/header.php';
?>
<main>

<section class="page-hd">
  <div class="container">
    <div class="crumb">
      <a href="<?= e(site_url('index.php')) ?>">首页</a> /
      <?php if ($visitor !== null): ?>
        <a href="<?= e(site_url('my-tickets.php')) ?>">我的工单</a> /
      <?php endif; ?>
      <span class="mono"><?= e($t['ticket_no']) ?></span>
    </div>
    <h1><?= e($t['title']) ?></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px">
      <?= status_badge($t['status']) ?>
      <?= priority_badge($t['priority']) ?>
      <span class="badge badge-gray"><?= e($t['category_icon'] . ' ' . $t['category_name']) ?></span>
      <span style="font-size:13px;color:var(--muted)">提交于 <?= e(fmt_date($t['created_at'])) ?></span>
    </div>
  </div>
</section>

<section class="sec" style="padding-top:30px">
  <div class="container with-side">

    <div>
      <?php if ($isNew): ?>
        <div class="alert alert-ok">
          <span class="alert-ic">✓</span>
          <div>
            <strong>工单提交成功！</strong>编号 <span class="mono"><?= e($t['ticket_no']) ?></span>，
            进展通知将发送到 <strong><?= e($t['contact_email']) ?></strong>。
            <?php if ($hasKey): ?>
              <br>请<strong>收藏本页地址</strong>（其中含你的访问密钥），日后无需登录即可查看进展。
            <?php else: ?>
              <br>请<strong>登录</strong>后随时查看进展。
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card card-p" style="margin-bottom:24px">
        <div style="font-size:15.5px"><?= ticket_render_body($t['content']) ?></div>
        <?php $att = ticket_attachments($t['attachments']); if ($att): ?>
          <?php
          // 图片直接内联显示，不放进文件列表。
          // 原实现图片同时出现在列表和缩略图里，列表那行是多余的。
          $imgs = array_values(array_filter($att, fn($a) => is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
          $docs = array_values(array_filter($att, fn($a) => !is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
          ?>
          <?php if ($imgs): ?>
            <div class="thumbs">
              <?php foreach ($imgs as $a): ?>
                <a class="thumb" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                  <img src="<?= e(upload_url($a['path'])) ?>" alt="<?= e($a['name']) ?>" loading="lazy">
                  <span class="ov">查看大图</span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($docs): ?>
          <div class="files">
            <?php foreach ($docs as $a): ?>
              <a class="file-item" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                <span class="fi">📄</span>
                <span><?= e($a['name']) ?></span>
                <span class="sz"><?= e(human_filesize((int)$a['size'])) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="tl">
        <?php foreach ($replies as $r):
          $isStaff = (int)$r['admin_id'] > 0;
          $authorLabel = reply_author_label($r); ?>
          <div class="tl-item <?= $isStaff ? 'staff' : 'user' ?> <?= (int)$r['is_internal'] === 1 ? 'internal' : '' ?>">
            <div class="tl-dot"><?= (int)$r['is_internal'] === 1 ? '📌' : ($isStaff ? '🎧' : '👤') ?></div>
            <div class="tl-hd">
              <span class="tl-who">
                <?= e($authorLabel) ?>
                <?php if ($isStaff): ?><span class="badge badge-blue" style="margin-left:6px;font-size:11px">官方</span><?php endif; ?>
                <?php if ((int)$r['is_internal'] === 1): ?><span class="badge badge-amber" style="margin-left:4px;font-size:11px">内部备注</span><?php endif; ?>
              </span>
              <span class="tl-time"><?= e(fmt_date($r['created_at'])) ?></span>
            </div>
            <div class="tl-body">
              <?= ticket_render_body($r['content']) ?>
              <?php $ra = ticket_attachments($r['attachments']); if ($ra): ?>
                <?php
                $rImgs = array_values(array_filter($ra, fn($a) => is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
                $rDocs = array_values(array_filter($ra, fn($a) => !is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
                ?>
                <?php if ($rImgs): ?>
                  <div class="thumbs">
                    <?php foreach ($rImgs as $a): ?>
                      <a class="thumb" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(upload_url($a['path'])) ?>" alt="" loading="lazy">
                        <span class="ov">查看大图</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($rDocs): ?>
                  <div class="files">
                    <?php foreach ($rDocs as $a): ?>
                      <a class="file-item" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                        <span class="fi">📄</span>
                        <span><?= e($a['name']) ?></span>
                        <span class="sz"><?= e(human_filesize((int)$a['size'])) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (!$replies): ?>
          <div class="alert alert-info">
            <span class="alert-ic">ℹ</span>
            <div>客服尚未回复。工单已成功送达，我们会尽快处理；你也回复本工单补充更多信息可以加快处理。</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- 评价 -->
      <?php if (in_array($t['status'], ['resolved', 'replied', 'closed'], true) && (int)$t['rating'] === 0 && !$isAdminV): ?>
        <div class="card card-p" style="margin-top:24px;background:linear-gradient(135deg,#ecfdf5,#f0fdfa);border-color:#a7f3d0">
          <h3 style="font-size:16.5px;margin-bottom:6px">😊 问题解决了吗？</h3>
          <p style="font-size:13.5px;color:var(--muted);margin-bottom:14px">给个评价，帮助我们持续改进服务质量</p>
          <form method="post" data-oneshot>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resolve">
            <div data-rate class="rate">
              <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" data-v="<?= $i ?>">⭐</button><?php endfor; ?>
            </div>
            <input type="hidden" name="rating" value="">
            <input type="text" name="rating_note" class="input" placeholder="补充说明（选填）" style="margin-bottom:14px">
            <button class="btn btn-p" type="submit">提交评价并关闭工单</button>
          </form>
        </div>
      <?php elseif ((int)$t['rating'] > 0): ?>
        <div class="card card-p" style="margin-top:24px;text-align:center;background:#f8fafc">
          <div style="font-size:24px;margin-bottom:6px"><?= str_repeat('⭐', (int)$t['rating']) ?></div>
          <p style="font-size:13.5px;color:var(--muted)">你已评价：<?= e(str_repeat('⭐', (int)$t['rating'])) ?></p>
          <?php if (!empty($t['rating_note'])): ?>
            <p style="font-size:13px;color:var(--muted);margin-top:5px"><?= e($t['rating_note']) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- 回复框 -->
      <?php if ($canReply && !$isAdminV): ?>
        <div class="card card-p" style="margin-top:24px">
          <h3 style="font-size:16.5px;margin-bottom:16px">追加回复 / 补充信息</h3>
          <form method="post" enctype="multipart/form-data" data-oneshot>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reply">
            <div class="field">
              <textarea name="content" class="textarea" required placeholder="补充说明、补充截图，或回复客服的追问…"></textarea>
            </div>
            <div class="field">
              <div class="uploader" data-picker style="padding:18px">
                <div class="ic" style="font-size:24px;margin-bottom:5px">📎</div>
                <p><strong>添加附件</strong>（选填）</p>
                <input type="file" name="files[]" multiple hidden>
              </div>
              <div class="up-list" data-filelist></div>
            </div>
            <div class="field">
              <?= turnstile_html('reply') ?>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button class="btn btn-p" type="submit">发送回复</button>
              <?php if ($t['status'] === 'closed'): ?>
                <button class="btn btn-o" type="submit" name="action" value="reopen">重新打开</button>
              <?php elseif (ticket_user_can_close($t)): ?>
                <button class="btn btn-o" type="submit" name="action" value="close">关闭工单</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php elseif ($t['status'] === 'closed' && !$isAdminV): ?>
        <div class="card card-p" style="margin-top:24px;text-align:center">
          <p style="color:var(--muted);margin-bottom:16px">该工单已关闭。如问题仍未解决，可以重新打开或提交新工单。</p>
          <form method="post" data-oneshot style="display:inline">
            <?= csrf_field() ?>
            <button class="btn btn-o" type="submit" name="action" value="reopen">重新打开工单</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <aside>
      <div class="side-box">
        <h3>📋 工单信息</h3>
        <ul class="side-list">
          <li><span>工单编号</span><span class="n mono"><?= e($t['ticket_no']) ?></span></li>
          <li><span>当前状态</span><span class="n"><?= e(status_meta($t['status'])['label']) ?></span></li>
          <li><span>优先级</span><span class="n"><?= e(priority_meta($t['priority'])['label']) ?></span></li>
          <li><span>处理人</span><span class="n"><?= e($t['assignee_name'] ?: '待分配') ?></span></li>
          <li><span>回复次数</span><span class="n"><?= e((string)$t['reply_count']) ?></span></li>
          <li><span>浏览次数</span><span class="n"><?= e((string)$t['view_count']) ?></span></li>
        </ul>
      </div>

      <div class="side-box">
        <h3>👤 提交信息</h3>
        <ul class="side-list">
          <li><span>提交者</span><span class="n"><?= e($t['user_id'] ? '注册用户' : ($t['guest_name'] ?: '访客')) ?></span></li>
          <li><span>联系邮箱</span><span class="n"><?= e(mask_email($t['contact_email'])) ?></span></li>
          <?php if (!empty($t['qq'])): ?>
            <li><span>QQ</span><span class="n"><?= e($t['qq']) ?></span></li>
          <?php endif; ?>
          <li><span>提交时间</span><span class="n"><?= e(fmt_date($t['created_at'], 'm-d H:i')) ?></span></li>
          <?php if (!empty($t['first_reply_at'])): ?>
            <li><span>首次响应</span><span class="n"><?= e(time_ago($t['first_reply_at'])) ?></span></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="side-box">
        <h3>🔗 查看工单</h3>
        <?php if ($hasKey && !$isAdminV && !$visitor): ?>
          <p style="font-size:12.5px;color:var(--muted);margin-bottom:10px">
            当前页面地址中已带上你的访问密钥，直接收藏即可。地址形如
            <code class="mono" style="font-size:11.5px">?no=工单号&amp;key=你的密钥</code>，请勿分享给他人。
          </p>
          <div class="tip" style="font-size:12px">
            丢失地址时，可在「我的工单」页输入<b>工单编号 + 访问密钥</b>重新进入。密钥无法找回，请妥善保存。
          </div>
        <?php elseif (!$hasKey): ?>
          <p style="font-size:12.5px;color:var(--muted)">
            该工单未设置访客访问密钥，需<a href="<?= e(site_url('login.php?back=' . urlencode('ticket-view.php?no=' . $t['ticket_no']))) ?>">登录提交时的账号</a>查看。
          </p>
        <?php else: ?>
          <p style="font-size:12.5px;color:var(--muted)">
            凭工单编号 + 访问密钥可在未登录状态下查看该工单。
          </p>
        <?php endif; ?>
      </div>

      <?php if ($isAdminV): ?>
        <div class="side-box">
          <h3>🛠 后台操作</h3>
          <a class="btn btn-p btn-block btn-sm" href="<?= e(site_url('admin/ticket-view.php?id=' . (int)$t['id'])) ?>">在后台处理此工单 →</a>
        </div>
        <div class="side-box">
          <h3>📜 操作日志</h3>
          <ul class="side-list">
            <?php foreach (array_slice($logs, 0, 12) as $l): ?>
              <li style="display:block">
                <div style="font-size:13px;color:var(--ink-2)"><?= e($l['detail'] ?: $l['action']) ?></div>
                <div style="font-size:11.5px;color:var(--muted);margin-top:2px"><?= e($l['admin_name']) ?> · <?= e(fmt_date($l['created_at'], 'm-d H:i')) ?></div>
              </li>
            <?php endforeach; ?>
            <?php if (!$logs): ?><li style="color:var(--muted)">暂无</li><?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>
    </aside>

  </div>
</section>

<?php require TPL_PATH . '/footer.php'; ?>
