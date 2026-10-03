<?php
/**
 * 提交工单
 */
require __DIR__ . '/includes/bootstrap.php';

$cats    = category_list(true, false);
$user    = current_user();
$errs    = [];
$formKey = 'submit';

// 内部人员（客服/工程师/主管/技术管理员/超管）不得以自己的身份创建工单。
// 前后台会话互斥（登录一方会清掉另一方），因此这里拦住后台登录态即可。
if (is_staff()) {
    flash('error', '后台工作人员不能以自己的身份提交工单，请使用「工单列表」处理用户提交的工单');
    redirect('admin/index.php');
}

if (is_post()) {
    csrf_guard();

    $title   = post('title');
    $content = post('content');
    $catId   = (int)post('category_id');
    $prio    = post('priority', 'normal');
    $email   = post('email');
    $qq      = post('qq');
    $guest   = post('guest_name');
    $rawKey  = post('access_key');

    // ---- 校验 ----
    if (mb_strlen($title) < 4 || mb_strlen($title) > 200) {
        $errs[] = '标题长度需在 4 - 200 字之间';
    }
    if (mb_strlen($content) < 10) {
        $errs[] = '问题描述至少 10 字，请把问题现象、执行的命令、返回结果写清楚';
    }
    if ($catId <= 0) {
        $errs[] = '请选择问题分类';
    } else {
        $ok = false;
        foreach ($cats as $c) {
            if ((int)$c['id'] === $catId) { $ok = true; break; }
        }
        if (!$ok) $errs[] = '问题分类无效';
    }
    if (!array_key_exists($prio, TICKET_PRIORITY)) {
        $prio = 'normal';
    }
    // 联系邮箱：可由后台设为必填
    $needEmail = (int)setting('ticket_require_email', '1') === 1;
    if ($email === '') {
        if ($needEmail) {
            $errs[] = '请填写联系邮箱，工单进展将通过邮件通知你';
        }
    } elseif (!is_valid_email($email)) {
        $errs[] = '邮箱格式不正确';
    }

    // 游客提交：开关关闭时要求先登录
    $allowGuest = (int)setting('ticket_allow_guest', '1') === 1;
    if (!$user && !$allowGuest) {
        flash('error', '本站仅支持登录用户提交工单，请先登录');
        redirect('login.php?back=' . urlencode('submit.php'));
    }
    if (!$user && $guest === '') {
        $errs[] = '请填写你的昵称';
    }

    // 访问密钥：游客必填（用于日后凭密钥查看工单），登录用户凭账号身份即可，跳过
    $rawKey  = trim((string)$rawKey);
    $keyHash = '';
    if (!$user) {
        $keyLen = mb_strlen($rawKey);
        if ($keyLen < 6) {
            $errs[] = '请设置至少 6 位的访问密钥，用于日后凭工单号 + 密钥查看进展';
        } elseif ($keyLen > 64) {
            $errs[] = '访问密钥最多 64 个字符';
        } elseif (preg_match('/[\s\x00-\x1F\x7F]/', $rawKey)) {
            $errs[] = '访问密钥不能包含空格或特殊控制字符';
        } else {
            $keyHash = ticket_key_hash($rawKey);
        }
    }

    // ---- 防机器人 ----
    if (!$errs) {
        $ab = antibot_check($formKey);
        if (!$ab['ok']) {
            $errs[] = $ab['msg'];
        }
    }

    if (!$errs) {
        // 附件：上传失败不阻断工单提交，仅在提交成功后提示
        $attErrs = [];
        $att = save_uploads($_FILES['files'] ?? [], '', $attErrs);

        // 列 15 个：ticket_no, user_id, guest_name, contact_email, qq,
        //            category_id, title, content, attachments, access_key,
        //            priority, status, source, ip, user_agent
        // 值 15 个：11 个 ? + "pending"(status) + "web"(source) + 2 个 ?(ip, user_agent)
        // 参数数组同样 11 项，逐项对应上面 11 个 ?，顺序不能变。
        $insertSql = 'INSERT INTO ' . DB_PRE . 'ticket
             (ticket_no, user_id, guest_name, contact_email, qq, category_id, title, content, attachments, access_key, priority, status, source, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,"pending","web",?,?)';
        $insertArgs = [
            '',
            (int)($user['id'] ?? 0),
            $user ? '' : mb_substr($guest, 0, 50),
            $email,
            mb_substr($qq, 0, 20),
            $catId,
            $title,
            $content,
            $att ? json_encode($att, JSON_UNESCAPED_UNICODE) : null,
            $keyHash,
            $prio,
            client_ip(),
            user_agent(),
        ];

        // 并发提交时工单号可能撞车，仅对唯一键冲突重试
        $tid = 0;
        $no  = '';
        for ($i = 0; $i < 5; $i++) {
            $insertArgs[0] = $no = ticket_make_no();
            try {
                $tid = db_insert($insertSql, $insertArgs);
                break;
            } catch (PDOException $ex) {
                // 23000/23001 = 唯一键冲突（工单号撞车），可重试
                $dup = in_array($ex->getCode(), ['23000', '23001'], true)
                    || (isset($ex->errorInfo[1]) && in_array((int)$ex->errorInfo[1], [1062, 1586], true));
                if (!$dup) {
                    // 非撞车错误：必须记日志。原实现只给一句「请稍后重试」，
                    // 数据库出错时既无线索也无痕迹，无法排查。
                    app_error_log('[Submit] 工单插入失败: ' . $ex->getMessage()
                        . ' | SQLSTATE=' . ($ex->getCode() ?? '')
                        . ' | errno=' . ($ex->errorInfo[1] ?? '?'));
                    $errs[] = '工单提交失败，请稍后重试';
                    break;
                }
                if ($i === 4) {
                    // 连续 5 次撞车，概率极低，通常意味着唯一键生成逻辑有 bug
                    app_error_log('[Submit] 工单号连续 5 次冲突，最后工单号: ' . $no);
                    $errs[] = '工单提交失败，请稍后重试';
                    break;
                }
            }
        }

        // 插入失败时中止，避免后续以 id=0 写入脏日志
        if ($tid <= 0) {
            $errs = $errs ?: ['工单提交失败，请稍后重试'];
        } else {
            $ticket = db_row('SELECT * FROM ' . DB_PRE . 'ticket WHERE id = ?', [$tid]);
            ticket_add_log($tid, 'create', '用户提交工单');

            // 自动确认邮件
            if ((int)setting('ticket_auto_reply', '1') === 1) {
                mail_notify_ticket((array)$ticket, 'created');
            }
            mail_notify_admins((array)$ticket);

            old_clear();
            flash('ok', '工单提交成功，编号 ' . $no . '。请留意邮箱通知。');
            if ($attErrs) {
                flash('error', '部分附件未能上传：' . implode('；', $attErrs));
            }
            // 游客需要靠自设密钥查看后续进展，登录用户凭身份即可
            $back = $user ? '' : '&key=' . urlencode($rawKey);
            redirect('ticket-view.php?no=' . urlencode($no) . $back . '&new=1');
        }
    }

    old_keep($_POST);
    antibot_mark_open($formKey);
}

$pre = [
    'title'        => old('title'),
    'content'      => old('content'),
    'category_id'  => (int)old('category_id'),
    'priority'     => old('priority', 'normal'),
    'email'        => old('email', $user['email'] ?? ''),
    'qq'           => old('qq', $user['qq'] ?? ''),
    'guest_name'   => old('guest_name'),
    'access_key'   => old('access_key'),
];

if (!$pre['email'] && $user && !empty($user['email'])) {
    $pre['email'] = $user['email'];
}
if (!$pre['qq'] && $user) {
    $pre['qq'] = $user['qq'];
}
if (!$pre['guest_name'] && $user) {
    $pre['guest_name'] = $user['username'];
}

antibot_mark_open($formKey);

$pageTitle = '提交工单';
$activeNav = 'submit';
require TPL_PATH . '/header.php';
?>
<main>

<section class="page-hd">
  <div class="container">
    <div class="crumb"><a href="<?= e(site_url('index.php')) ?>">首页</a> / 提交工单</div>
    <h1>提交工单</h1>
    <p>请如实填写问题信息，这能显著加快处理速度</p>
  </div>
</section>

<section class="sec" style="padding-top:34px">
  <div class="container with-side">

    <div class="card card-p">
      <?php if ($errs): ?>
        <div class="alert alert-err">
          <span class="alert-ic">!</span>
          <div>
            <?php foreach ($errs as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" data-oneshot novalidate>
        <?= csrf_field() ?>
        <!-- 蜜罐字段：正常用户不可见 -->
        <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden" aria-hidden="true">
          <label>Website<input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label>
          <label>Email<input type="text" name="hp_email" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="field">
          <label>问题分类 <span class="req">*</span></label>
          <select name="category_id" class="select" required>
            <option value="">请选择最接近的分类</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $pre['category_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                <?= e($c['icon'] . ' ' . $c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="tip">选错分类会大幅增加转达时间，拿不准就选「常见报错」</div>
        </div>

        <div class="field">
          <label>问题标题 <span class="req">*</span></label>
          <input type="text" name="title" class="input" maxlength="200" required
                 placeholder="一句话概括问题，例如：签到提示已签到但流水无记录"
                 value="<?= e($pre['title']) ?>">
        </div>

        <div class="field">
          <label>问题描述 <span class="req">*</span></label>
          <textarea name="content" class="textarea" data-count="3000" required
                    placeholder="请按以下格式描述，描述越具体处理越快：

1. 你执行的完整命令（例如 /用户名搜索 xxx）
2. 机器人的实际返回结果
3. 你期望的结果
4. 其他补充说明"><?= e($pre['content']) ?></textarea>
          <div class="tip" style="display:flex;justify-content:space-between">
            <span>支持直接粘贴命令文本，会自动高亮显示</span>
            <span data-count-out>0 / 3000</span>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label>优先级 <span class="req">*</span></label>
            <select name="priority" class="select">
              <?php foreach (TICKET_PRIORITY as $k => $m): ?>
                <option value="<?= e($k) ?>" <?= $pre['priority'] === $k ? ' selected' : '' ?>>
                  <?= e($m['label']) ?> — <?= e(['low' => '不影响使用', 'normal' => '一般问题', 'high' => '影响正常使用', 'urgent' => '完全无法使用'][$k] ?? '') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>QQ 号</label>
            <input type="text" name="qq" class="input" maxlength="20" placeholder="选填，方便我们联系你" value="<?= e($pre['qq']) ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label>联系邮箱 <?php if ((int)setting('ticket_require_email', '1') === 1): ?><span class="req">*</span><?php endif; ?></label>
            <input type="email" name="email" class="input" <?= (int)setting('ticket_require_email', '1') === 1 ? 'required' : '' ?>
                   placeholder="工单回复与进展会发送到该邮箱" value="<?= e($pre['email']) ?>">
            <div class="tip">用于接收工单进展通知，建议填写可正常收信的邮箱</div>
          </div>
          <div class="field">
            <label><?= $user ? '昵称' : '你的昵称' ?></label>
            <input type="text" name="guest_name" class="input" maxlength="50"
                   placeholder="<?= $user ? '自动获取' : '便于客服称呼你' ?>"
                   value="<?= e($pre['guest_name']) ?>" <?= $user ? 'readonly' : '' ?>>
          </div>
        </div>

        <?php if (!$user): ?>
          <div class="field">
            <label>访问密钥 <span class="req">*</span></label>
            <input type="text" name="access_key" class="input" required minlength="6" maxlength="64"
                   autocomplete="new-password" placeholder="自己设置，至少 6 位，例如 k9m2x7qp"
                   value="<?= e($pre['access_key']) ?>">
            <div class="tip">
              提交后可凭「工单编号 + 该密钥」随时查看工单进展，无需登录账号。
              建议使用字母数字组合并自行妥善保存——<strong>密钥一旦遗失无法找回</strong>，我们也无法查看。
            </div>
          </div>
        <?php endif; ?>

        <div class="field">
          <label>附件</label>
          <div class="uploader" data-picker role="button" tabindex="0"
               aria-label="点击选择文件，或将文件拖拽到此处">
            <div class="ic">📎</div>
            <p><strong style="color:var(--ink-2)">点击选择文件</strong> 或拖拽到此处</p>
            <p style="font-size:12.5px;margin-top:5px">
              支持图片、文档、压缩包，单个不超过 <?= e((string)setting_int('upload_max_mb', 10)) ?>MB，
              最多 <?= e((string)setting_int('upload_max_count', 5)) ?> 个
            </p>
            <input type="file" name="files[]" multiple hidden>
          </div>
          <div class="up-list" data-filelist></div>
        </div>

        <div class="field">
          <label>安全验证 <span class="req">*</span></label>
          <?php if (turnstile_enabled()): ?>
            <?= turnstile_html($formKey) ?>
            <div class="tip">请完成人机验证以证明您不是自动脚本</div>
          <?php else: ?>
            <?= captcha_html() ?>
            <div class="tip">请计算题目中的算式并填写结果，这是防止机器人自动刷单的必要步骤</div>
          <?php endif; ?>
        </div>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:26px;padding-top:22px;border-top:1px solid var(--line)">
          <button type="submit" class="btn btn-p btn-lg">提交工单</button>
          <a class="btn btn-g" href="<?= e(site_url('knowledge.php')) ?>">先去知识库看看？</a>
          <span style="font-size:12.5px;color:var(--muted);margin-left:auto">已提交 <strong><?= e((string)ticket_today_count()) ?></strong> 个工单（今日）</span>
        </div>
      </form>
    </div>

    <aside>
      <div class="side-box" style="background:linear-gradient(135deg,#eef2ff,#faf5ff);border-color:#ddd6fe">
        <h3 style="border:0;padding:0;margin-bottom:12px">✨ 快速解决建议</h3>
        <ul style="list-style:none;font-size:13.5px;color:var(--ink-2);line-height:2">
          <li>① 先到<a href="<?= e(site_url('knowledge.php')) ?>">知识库</a>搜索你的问题</li>
          <li>② 检查命令是否以英文 <code>/</code> 开头</li>
          <li>③ 确认是否触发了查询限流</li>
          <li>④ 以上都没有，提交工单并附上截图</li>
        </ul>
      </div>

      <div class="side-box">
        <h3>📮 邮件通知</h3>
        <p style="font-size:13.5px;color:var(--ink-2);margin-bottom:10px">填写邮箱后，工单被回复、状态变更或关闭时，我们会立即发信通知你，无需反复刷新页面。</p>
        <p style="font-size:12.5px;color:var(--muted)">当前邮件通知：<?= (int)setting('mail_enabled', '0') === 1 ? '<span class="badge badge-green">已开启</span>' : '<span class="badge badge-gray">未开启</span>' ?></p>
      </div>

      <div class="side-box">
        <h3>🔒 隐私说明</h3>
        <p style="font-size:13px;color:var(--muted)">你的联系方式仅用于本次工单处理，不会用于其他用途，也不会对外公开。</p>
      </div>
    </aside>

  </div>
</section>

<?php require TPL_PATH . '/footer.php'; ?>
