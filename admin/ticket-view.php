<?php
/** 后台工单处理 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$id = get_int('id');
$t = $id > 0 ? db_row('SELECT t.*, c.name category_name, c.icon category_icon FROM ' . DB_PRE . 'ticket t
                       LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id WHERE t.id = ?', [$id]) : null;
if (!$t) {
    flash('error', '工单不存在');
    redirect('tickets.php');
}

// 标记已读
if ((int)$t['is_read'] === 0) {
    db_query('UPDATE ' . DB_PRE . 'ticket SET is_read = 1 WHERE id = ?', [$id]);
}

$admins = db_all('SELECT id, username, realname FROM ' . DB_PRE . 'admin WHERE status = 1 ORDER BY id');

if (is_post()) {
    csrf_guard();
    $act = post('act');

    // 回复
    if ($act === 'reply') {
        $content  = post('content');
        $internal = (int)post('is_internal') === 1;
        // 状态值白名单归一，非法值退回工单当前状态
        $rawStatus = post('new_status', $t['status']);
        $newStatus = array_key_exists($rawStatus, TICKET_STATUS) ? $rawStatus : (string)$t['status'];

        if (mb_strlen($content) < 2) {
            flash('error', '回复内容不能为空');
            redirect('ticket-view.php?id=' . (int)$t['id']);
        }

        // 人机验证：客服回复同样需要，防止会话被劫持后恶意回复
        $ts = turnstile_verify('admin_reply');
        if (!$ts['ok']) {
            flash('error', $ts['msg']);
            redirect('ticket-view.php?id=' . (int)$t['id']);
        }

        {
            $attErrs = [];
            $att = save_uploads($_FILES['files'] ?? [], '', $attErrs);
            try {
                // author_role 存回复当时的角色。后续该账号升职，其历史回复
                // 仍显示原头衔，不会被改写成当时不存在的身份。
                db_insert('INSERT INTO ' . DB_PRE . 'ticket_reply (ticket_id, admin_id, author_name, author_role, content, attachments, is_internal, new_status, ip)
                       VALUES (?,?,?,?,?,?,?,?,?)', [
                    $id, (int)$admin['id'], $admin['realname'] ?: $admin['username'], (string)$admin['role'], $content,
                    $att ? json_encode($att, JSON_UNESCAPED_UNICODE) : null,
                    $internal ? 1 : 0,
                    // 内部备注不改变工单状态，故不记录状态，避免数据自相矛盾
                    $internal ? '' : $newStatus,
                    client_ip(),
                ]);
            } catch (PDOException $ex) {
                app_error_log('[AdminReply] 插入失败: ' . $ex->getMessage()
                    . ' | SQLSTATE=' . ($ex->getCode() ?? '')
                    . ' | errno=' . ($ex->errorInfo[1] ?? '?'));
                flash('error', '回复提交失败，请稍后重试');
                redirect('ticket-view.php?id=' . (int)$t['id']);
            }

            if (!$internal) {
                // 注意：$set 直接跟在 "SET " 之后，首个片段不能带前导逗号，否则 SQL 变成 "SET , ..."
                $set  = 'reply_count = reply_count + 1, is_read = 1';
                $args = [];
                if (empty($t['first_reply_at'])) {
                    $set .= ', first_reply_at = NOW()';
                }
                if ($newStatus !== (string)$t['status']) {
                    $set .= ', status = ?';
                    $args[] = $newStatus;
                }
                if ($newStatus === 'resolved') $set .= ', resolved_at = NOW()';
                if ($newStatus === 'closed')   $set .= ', closed_at = NOW()';
                $args[] = $id;
                db_query('UPDATE ' . DB_PRE . 'ticket SET ' . $set . ' WHERE id = ?', $args);
                $fresh = (array)db_row('SELECT * FROM ' . DB_PRE . 'ticket WHERE id = ?', [$id]);
                mail_notify_ticket($fresh, 'reply', '<p>' . nl2br(e($content)) . '</p>');
            }

            ticket_add_log($id, 'reply', ($internal ? '添加内部备注：' : '回复用户：') . mb_strimwidth($content, 0, 100, '…'));
            flash('ok', $internal ? '内部备注已添加' : '回复已发送，用户已收到邮件通知');
            if ($attErrs) {
                flash('error', '部分附件未上传：' . implode('；', $attErrs));
            }
            redirect('ticket-view.php?id=' . $id);
        }
    }

    // 快速状态
    if ($act === 'status') {
        $new = post('status');
        if (!array_key_exists($new, TICKET_STATUS)) {
            flash('error', '状态无效');
        } else {
            // 注意：$set 直接跟在 "SET " 之后，首个片段不能带前导逗号
            $set = 'status = ?';
            $p = [$new];
            if ($new === 'resolved') $set .= ', resolved_at = NOW()';
            if ($new === 'closed')   $set .= ', closed_at = NOW()';
            if ($new === 'pending' || $new === 'processing') $set .= ', closed_at = NULL, resolved_at = NULL';
            db_query('UPDATE ' . DB_PRE . 'ticket SET ' . $set . ' WHERE id = ?', array_merge($p, [$id]));
            $fresh = (array)db_row('SELECT * FROM ' . DB_PRE . 'ticket WHERE id = ?', [$id]);
            ticket_status_changed($t, $fresh, $fresh);
            if ($new === 'closed') mail_notify_ticket($fresh, 'closed');
            flash('ok', '状态已更新为「' . TICKET_STATUS[$new]['label'] . '」');
            redirect('ticket-view.php?id=' . $id);
        }
    }

    // 重置访客访问密钥：用户遗失密钥时由客服代为清除
    // 站点不保存明文，因此只能作废（清空后仅登录用户可访问），无法还原原值
    if ($act === 'reset_key') {
        if ((string)($t['access_key'] ?? '') === '') {
            flash('error', '该工单未设置访客访问密钥，无需重置');
        } else {
            db_query('UPDATE ' . DB_PRE . 'ticket SET access_key = \'\' WHERE id = ?', [$id]);
            ticket_add_log($id, 'reset_key', '客服重置访客访问密钥');
            flash('ok', '已重置访问密钥：该工单现仅登录用户与管理员可查看');
        }
        redirect('ticket-view.php?id=' . $id);
    }

    // 字段更新
    if ($act === 'update') {
        $prio = post('priority');
        if (!array_key_exists($prio, TICKET_PRIORITY)) $prio = $t['priority'];
        $assignee = (int)post('assignee_id');
        $catId    = (int)post('category_id');

        // 分类必须真实存在，避免伪造 id 造成孤儿数据
        if (!(int)db_one('SELECT COUNT(*) FROM ' . DB_PRE . 'category WHERE id = ?', [$catId], 0)) {
            flash('error', '所选分类不存在');
            redirect('ticket-view.php?id=' . $id);
        }

        $changes = [];
        if ($prio !== $t['priority'])              $changes[] = '优先级 ' . priority_meta($t['priority'])['label'] . ' → ' . priority_meta($prio)['label'];
        if ((int)$t['assignee_id'] !== $assignee)   $changes[] = '处理人变更';
        if ((int)$t['category_id'] !== $catId)      $changes[] = '分类变更';

        db_query('UPDATE ' . DB_PRE . 'ticket SET priority = ?, assignee_id = ?, category_id = ? WHERE id = ?', [$prio, $assignee, $catId, $id]);
        if ($changes) {
            ticket_add_log($id, 'update', implode('；', $changes));
        }
        flash('ok', $changes ? '已更新：' . implode('；', $changes) : '无变化');
        redirect('ticket-view.php?id=' . $id);
    }

    // 删除
    if ($act === 'delete') {
        // 删除不可恢复，仅超级管理员可执行
        if ((string)$admin['role'] !== 'super') {
            flash('error', '删除工单需要超级管理员权限');
            redirect('ticket-view.php?id=' . $id);
        }

        // 收集所有附件路径（含历史回复中的），删除记录后清理磁盘文件
        $paths = [];
        if (!empty($t['attachments'])) {
            $paths = array_merge($paths, (array)json_decode((string)$t['attachments'], true));
        }
        $attRows = db_all('SELECT attachments FROM ' . DB_PRE . 'ticket_reply WHERE ticket_id = ?', [$id]);
        foreach ($attRows as $r) {
            if (!empty($r['attachments'])) {
                $paths = array_merge($paths, (array)json_decode((string)$r['attachments'], true));
            }
        }
        delete_attachments($paths);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_query('DELETE FROM ' . DB_PRE . 'ticket_reply WHERE ticket_id = ?', [$id]);
            db_query('DELETE FROM ' . DB_PRE . 'ticket_log WHERE ticket_id = ?', [$id]);
            db_query('DELETE FROM ' . DB_PRE . 'ticket WHERE id = ?', [$id]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', '删除失败：' . $ex->getMessage());
            redirect('ticket-view.php?id=' . $id);
        }

        flash('ok', '工单 ' . $t['ticket_no'] . ' 已删除');
        redirect('tickets.php');
    }
}

$t       = (array)db_row('SELECT t.*, c.name category_name, c.icon category_icon FROM ' . DB_PRE . 'ticket t
                          LEFT JOIN ' . DB_PRE . 'category c ON c.id = t.category_id WHERE t.id = ?', [$id]);
$replies = ticket_replies($id, true);
$logs    = ticket_logs($id);
$cats    = category_list(true, false);

$pageTitle = '工单处理';
$pageDesc  = $t['ticket_no'];
$pageActions = '<a class="btn btn-o btn-sm" href="../ticket-view.php?no=' . e(urlencode($t['ticket_no'])) . '" target="_blank">前台视图</a>'
    . '<a class="btn btn-o btn-sm" href="tickets.php">← 返回列表</a>';
require __DIR__ . '/_head.php';
?>

<form method="post" class="tools">
  <?= csrf_field() ?>
  <select name="status" class="select" style="width:auto;min-width:130px">
    <?php foreach (TICKET_STATUS as $k => $m): ?>
      <option value="<?= e($k) ?>" <?= $t['status'] === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-p btn-sm" name="act" value="status" type="submit">更新状态</button>
  <?php if ((string)$admin['role'] === 'super'): ?>
    <span class="sp"></span>
    <button class="btn btn-o btn-sm" name="act" value="delete" type="submit"
            data-confirm="确定删除该工单？此操作不可恢复，附件将一并删除！">🗑 删除工单</button>
  <?php endif; ?>
</form>

<div class="tk-detail">
  <div>
    <!-- 原始内容 -->
    <div class="card mb">
      <div class="card-hd">
        <span class="badge badge-blue">用户提交</span>
        <strong style="font-size:15.5px"><?= e($t['title']) ?></strong>
        <span class="sp"></span>
        <span class="muted small"><?= e(fmt_date($t['created_at'])) ?></span>
      </div>
      <div class="card-bd">
        <?= ticket_render_body($t['content']) ?>
        <?php $att = ticket_attachments($t['attachments']); if ($att): ?>
          <?php
          // 与前台一致：图片直接内联，列表只留非图片附件。
          $imgs = array_values(array_filter($att, fn($a) => is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
          $docs = array_values(array_filter($att, fn($a) => !is_image_file(strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)))));
          ?>
          <?php if ($imgs): ?>
            <div class="thumbs">
              <?php foreach ($imgs as $a): ?>
                <a class="thumb" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                  <img src="<?= e(upload_url($a['path'])) ?>" alt="" loading="lazy">
                  <span class="ov">查看</span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($docs): ?>
            <div class="files">
              <?php foreach ($docs as $a): ?>
                <a class="file-item" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                  <span class="fi">📄</span>
                  <span class="nm"><?= e($a['name']) ?></span>
                  <span class="sz"><?= e(human_filesize((int)$a['size'])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- 会话 -->
    <div class="card mb">
      <div class="card-hd"><h2>💬 会话记录</h2><span class="sp"></span>
        <span class="muted small"><?= count($replies) ?> 条</span></div>
      <div class="card-bd">
        <?php if (!$replies): ?>
          <div class="empty" style="padding:34px"><div class="ic">💭</div><p>还没有任何回复</p></div>
        <?php endif; ?>
        <?php foreach ($replies as $r):
          $authorLabel = reply_author_label($r); ?>
          <div class="conv-item <?= (int)$r['is_internal'] === 1 ? 'internal' : '' ?>">
            <div class="conv-hd">
              <span class="av <?= (int)$r['admin_id'] > 0 ? '' : 'u' ?>">
                <?= e(mb_substr($authorLabel, 0, 1)) ?>
              </span>
              <span class="nm"><?= e($authorLabel) ?></span>
              <?= reply_author_badge($r) ?>
              <?php if ((int)$r['is_internal'] === 1): ?><span class="badge badge-amber">内部备注</span><?php endif; ?>
              <span class="tm"><?= e(fmt_date($r['created_at'])) ?></span>
            </div>
            <div class="conv-bd">
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
                        <span class="ov">查看</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($rDocs): ?>
                  <div class="files">
                    <?php foreach ($rDocs as $a): ?>
                      <a class="file-item" href="<?= e(upload_url($a['path'])) ?>" target="_blank" rel="noopener">
                        <span class="fi">📄</span>
                        <span class="nm"><?= e($a['name']) ?></span>
                        <span class="sz"><?= e(human_filesize((int)$a['size'])) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 回复框 -->
    <div class="card">
      <div class="card-hd"><h2>✍️ 回复工单</h2></div>
      <div class="card-bd">
        <form method="post" enctype="multipart/form-data" data-oneshot>
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="reply">

          <div class="ed-tool" data-ed-wrap>
            <button type="button" data-ed="<b>加粗</b>"><b>B</b></button>
            <button type="button" data-ed="`代码`">代码</button>
            <button type="button" data-ed="\n- ">• 列表</button>
            <button type="button" data-ed="\n\n---\n\n">分隔线</button>
            <button type="button" data-ed="\n">换行</button>
          </div>
          <textarea name="content" class="textarea ed" required placeholder="输入回复内容…支持 Markdown 风格的基础语法"></textarea>

          <div class="field mt">
            <label>附件</label>
            <input type="file" name="files[]" multiple class="input" style="padding:8px">
          </div>

          <div class="field mt">
            <label>人机验证 <span style="color:#dc2626">*</span></label>
            <?= turnstile_html('admin_reply') ?>
          </div>

          <div class="flex-between" style="margin-top:14px;gap:14px;flex-wrap:wrap">
            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
              <label class="check"><input type="checkbox" name="is_internal" value="1"> 仅内部备注（用户不可见）</label>
              <span class="muted small">回复后将邮件通知 <?= e($t['contact_email'] ?: '（用户未填邮箱）') ?></span>
            </div>
          </div>

          <div class="flex-between" style="margin-top:16px;padding-top:15px;border-top:1px solid var(--line);gap:12px;flex-wrap:wrap">
            <div class="muted small">回复后自动变更工单状态：</div>
            <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
              <select name="new_status" class="select" style="width:auto;min-width:130px">
                <?php foreach (TICKET_STATUS as $k => $m): ?>
                  <option value="<?= e($k) ?>" <?= $t['status'] === $k ? ' selected' : '' ?>>
                    <?= e($m['label']) ?><?= $k === $t['status'] ? '（不变）' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-p" type="submit">发送回复</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 侧栏 -->
  <div>
    <div class="card mb">
      <div class="card-hd"><h2>属性设置</h2></div>
      <div class="card-bd">
        <form method="post" data-oneshot>
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="update">
          <div class="field">
            <label>优先级</label>
            <select name="priority" class="select">
              <?php foreach (TICKET_PRIORITY as $k => $m): ?>
                <option value="<?= e($k) ?>" <?= $t['priority'] === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>处理人</label>
            <select name="assignee_id" class="select">
              <option value="0">未指派</option>
              <?php foreach ($admins as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)$t['assignee_id'] === (int)$a['id'] ? ' selected' : '' ?>>
                  <?= e($a['realname'] ?: $a['username']) ?><?= (int)$a['id'] === (int)$admin['id'] ? '（我）' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>问题分类</label>
            <select name="category_id" class="select">
              <option value="0">未分类</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$t['category_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                  <?= e($c['icon'] . ' ' . $c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-p btn-block btn-sm" type="submit">保存属性</button>
        </form>

        <?php if ((string)($t['access_key'] ?? '') !== ''): ?>
          <form method="post" style="margin-top:12px;padding-top:14px;border-top:1px solid var(--line)"
                data-confirm="重置后该工单的访客访问密钥将立即失效，用户需重新提交工单才能凭密钥查看。确认重置？">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="reset_key">
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:9px">
              该工单设置了访客访问密钥。用户遗失密钥时可在此作废（站点不保存明文，无法还原原值）。
            </div>
            <button class="btn btn-o btn-block btn-sm" type="submit">重置访客访问密钥</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb">
      <div class="card-hd"><h2>用户信息</h2></div>
      <div class="meta-grid">
        <div class="m"><div class="k">提交者</div><div class="v"><?= e($t['guest_name'] ?: '访客') ?></div></div>
        <div class="m"><div class="k">邮箱</div><div class="v"><?= e($t['contact_email'] ?: '—') ?></div></div>
        <div class="m"><div class="k">QQ</div><div class="v"><?= e($t['qq'] ?: '—') ?></div></div>
        <div class="m"><div class="k">注册用户</div><div class="v"><?= $t['user_id'] ? '是 (#' . (int)$t['user_id'] . ')' : '否' ?></div></div>
        <div class="m"><div class="k">来源 IP</div><div class="v"><?= e($t['ip']) ?></div></div>
        <div class="m"><div class="k">浏览次数</div><div class="v"><?= e((string)$t['view_count']) ?></div></div>
        <div class="m"><div class="k">回复数</div><div class="v"><?= e((string)$t['reply_count']) ?></div></div>
        <div class="m"><div class="k">用户评分</div><div class="v"><?= (int)$t['rating'] > 0 ? str_repeat('⭐', (int)$t['rating']) : '未评价' ?></div></div>
        <div class="m" style="grid-column:1/-1"><div class="k">首次响应</div>
          <div class="v"><?= e($t['first_reply_at'] ? fmt_date($t['first_reply_at']) : '未响应') ?></div></div>
        <div class="m" style="grid-column:1/-1"><div class="k">评价留言</div>
          <div class="v"><?= e($t['rating_note'] ?: '—') ?></div></div>
      </div>
    </div>

    <div class="card mb">
      <div class="card-hd"><h2>操作日志</h2></div>
      <div class="card-bd">
        <ul class="logs">
          <?php foreach (array_slice($logs, 0, 20) as $l): ?>
            <li>
              <span class="tm"><?= e(fmt_date($l['created_at'], 'm-d H:i')) ?></span>
              <span class="who"><?= e($l['admin_name']) ?></span>
              <span class="dt"><?= e($l['detail'] ?: $l['action']) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (!$logs): ?><li style="color:var(--muted)">暂无日志</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="card">
      <div class="card-bd">
        <div class="muted small" style="line-height:2">
          <div>设备：<span style="word-break:break-all"><?= e(mb_substr($t['user_agent'], 0, 80)) ?></span></div>
          <div>创建：<?= e(fmt_date($t['created_at'], 'Y-m-d H:i:s')) ?></div>
          <div>更新：<?= e(fmt_date($t['updated_at'], 'Y-m-d H:i:s')) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_foot.php'; ?>
