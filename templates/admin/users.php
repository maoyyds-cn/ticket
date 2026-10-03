<?php
/**
 * 前台用户管理
 *
 * @var list<array> $rows
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var string $keyword
 * @var string $statusRaw
 * @var int $enabledCount
 */
$currentUrl = '/admin/users' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<div class="a-card">
  <form data-guard class="a-card-bd" method="get" action="<?= e(url('/admin/users')) ?>">
    <div class="filters">
      <div class="f-item grow">
        <label for="uq">搜索</label>
        <input class="input" id="uq" type="search" name="q" value="<?= e($keyword) ?>"
               placeholder="用户名、邮箱或 QQ">
      </div>
      <div class="f-item">
        <label for="ustatus">状态</label>
        <select class="select select-sm" id="ustatus" name="status" data-autosubmit>
          <option value=""<?= $statusRaw === '' ? ' selected' : '' ?>>全部状态</option>
          <option value="1"<?= $statusRaw === '1' ? ' selected' : '' ?>>正常</option>
          <option value="0"<?= $statusRaw === '0' ? ' selected' : '' ?>>已停用</option>
        </select>
      </div>
      <div class="f-item">
        <span aria-hidden="true">&nbsp;</span>
        <div class="row-wrap">
          <button class="btn btn-primary btn-sm" type="submit">筛选</button>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/users')) ?>">重置</a>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="a-card">
  <div class="a-card-hd">
    <h2>用户列表</h2>
    <span class="faint tiny">共 <?= e((string)$total) ?> 个账号，其中启用 <?= e((string)$enabledCount) ?> 个</span>
  </div>

  <?php if ($rows === []): ?>
    <div class="a-card-bd">
      <?php
        $icon = 'users';
        $title = $keyword !== '' || $statusRaw !== '' ? '没有符合条件的用户' : '还没有注册用户';
        $text = $keyword !== '' || $statusRaw !== ''
            ? '试着换一个关键词，或清除筛选条件。'
            : '用户可以注册账号来集中查看自己提交的工单；不注册也可以作为访客提交。';
        $actions = $keyword !== '' || $statusRaw !== '' ? [['url' => '/admin/users', 'label' => '清除筛选']] : [];
        require dirname(__DIR__) . '/partials/empty.php';
      ?>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th>用户</th>
            <th class="nowrap">联系方式</th>
            <th class="nowrap">工单</th>
            <th class="nowrap">注册时间</th>
            <th class="nowrap">最近登录</th>
            <th class="nowrap">状态</th>
            <th class="nowrap">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $u): ?>
            <tr>
              <td>
                <strong style="color:var(--text-1)"><?= e((string)($u['realname'] ?: $u['username'])) ?></strong>
                <span class="cell-sub mono"><?= e((string)$u['username']) ?> · #<?= e((string)(int)$u['id']) ?></span>
              </td>
              <td class="nowrap tiny">
                <?php if ((string)$u['email'] !== ''): ?>
                  <?= e((string)$u['email']) ?>
                <?php else: ?>
                  <span class="faint">无邮箱</span>
                <?php endif; ?>
                <?php if ((string)$u['qq'] !== ''): ?>
                  <span class="cell-sub">QQ <?= e((string)$u['qq']) ?></span>
                <?php endif; ?>
              </td>
              <td class="nowrap tiny">
                <a href="<?= e(url('/admin/tickets?q=' . rawurlencode((string)$u['email']))) ?>">
                  <?= e((string)(int)$u['ticket_count']) ?> 条
                </a>
                <?php if ((int)$u['open_count'] > 0): ?>
                  <span class="cell-sub">未完结 <?= e((string)(int)$u['open_count']) ?></span>
                <?php endif; ?>
              </td>
              <td class="nowrap faint tiny"><?= e(\App\Support\Str::datetime((string)$u['created_at'], 'Y-m-d')) ?></td>
              <td class="nowrap faint tiny"><?= e(\App\Support\Str::timeAgo((string)($u['last_login_at'] ?? ''))) ?></td>
              <td class="nowrap">
                <?php if ((int)$u['status'] === 1): ?>
                  <span class="badge badge-green">正常</span>
                <?php else: ?>
                  <span class="badge badge-slate">已停用</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <div class="tbl-act">
                  <form data-guard method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/toggle')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                    <button type="submit"><?= (int)$u['status'] === 1 ? '停用' : '启用' ?></button>
                  </form>
                  <button type="button" data-modal="mUpass<?= (int)$u['id'] ?>">改密码</button>
                  <form data-guard method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/delete')) ?>" style="display:inline"
                        data-confirm-form="确定删除用户「<?= e((string)$u['username']) ?>」吗？其名下工单会转为访客工单并保留。">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
                    <button class="danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="a-card-bd">
        <?php
          $pages = $totalPages;
          $params = [];
          if ($keyword !== '') { $params['q'] = $keyword; }
          if ($statusRaw !== '') { $params['status'] = $statusRaw; }
          $base = '/admin/users';
          $label = '用户列表分页';
          require dirname(__DIR__) . '/partials/pager.php';
        ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($rows as $u): ?>
  <div class="modal" id="mUpass<?= (int)$u['id'] ?>" role="dialog" aria-modal="true"
       aria-labelledby="mUpassTitle<?= (int)$u['id'] ?>">
    <div class="modal-box">
      <form data-guard method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/password')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($currentUrl) ?>">
        <div class="modal-hd">
          <h3 id="mUpassTitle<?= (int)$u['id'] ?>">重置「<?= e((string)$u['username']) ?>」的密码</h3>
          <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="关闭">✕</button>
        </div>
        <div class="modal-bd">
          <div class="field mb-0">
            <label class="field-label" for="up<?= (int)$u['id'] ?>">新密码</label>
            <input class="input" id="up<?= (int)$u['id'] ?>" type="text" name="password" required
                   minlength="8" maxlength="72" autocomplete="off" placeholder="至少 8 位">
            <div class="field-tip">请通过安全渠道把新密码转告该用户。</div>
          </div>
        </div>
        <div class="modal-ft">
          <button class="btn btn-secondary" type="button" data-modal-close>取消</button>
          <button class="btn btn-primary" type="submit">确认重置</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
