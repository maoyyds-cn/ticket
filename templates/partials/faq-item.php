<?php
/**
 * 知识库条目（原生 details 手风琴）
 *
 * 为什么改用 details/summary：
 * 旧版是给一个 <div> 绑 click 实现的展开，键盘用户完全无法操作
 * （没有 tabindex、没有 role、没有 Enter/Space 处理、也没有 aria-expanded）。
 * details 天生可聚焦、可用 Enter/Space 切换、并把展开状态暴露给辅助技术，
 * 而且不依赖 JavaScript——JS 加载失败时内容依然能看。
 *
 * @var array $faq
 * @var int   $index
 * @var bool  $open
 * @var bool  $showVote
 * @var bool|null $voted
 * @var bool  $showLink
 */
$index = (int)($index ?? 1);
$open = (bool)($open ?? false);
$showVote = (bool)($showVote ?? true);
$showLink = (bool)($showLink ?? false);
$voted = $voted ?? null;
$f = $faq;
?>
<details class="acc" id="faq-<?= e((string)(int)$f['id']) ?>"<?= $open ? ' open' : '' ?>>
  <summary>
    <span class="acc-idx"><?= e((string)$index) ?></span>
    <span class="acc-q"><?= e((string)$f['question']) ?></span>
    <span class="acc-arw" aria-hidden="true">
      <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.5 4.5 6 8l3.5-3.5"/>
      </svg>
    </span>
  </summary>
  <div class="acc-body">
    <div>
      <div class="acc-inner">
        <?php if (!empty($f['is_top']) || !empty($f['is_hot'])): ?>
          <div class="row-wrap" style="margin-bottom:10px">
            <?php if (!empty($f['is_top'])): ?>
              <span class="badge badge-red">置顶</span>
            <?php endif; ?>
            <?php if (!empty($f['is_hot'])): ?>
              <span class="badge badge-amber">热门</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="prose"><?= \App\Support\Str::linkify((string)$f['answer']) ?></div>

        <div class="row-wrap" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border-2);font-size:12.5px">
          <?php if (!empty($f['category_name'])): ?>
            <a class="muted" href="<?= e(url('/knowledge?cat=' . (int)$f['category_id'])) ?>">
              <?= icon((string)$f['category_icon'], 15) ?> <?= e((string)$f['category_name']) ?>
            </a>
          <?php endif; ?>
          <span class="faint">浏览 <?= e((string)(int)$f['views']) ?></span>
          <span class="faint">更新于 <?= e(\App\Support\Str::datetime((string)$f['updated_at'], 'Y-m-d')) ?></span>

          <?php if ($showLink): ?>
            <a class="tiny" style="margin-left:auto"
               href="<?= e(url('/knowledge#faq-' . (int)$f['id'])) ?>">查看完整解答 →</a>
          <?php endif; ?>
        </div>

        <?php if ($showVote): ?>
          <div class="row-wrap" style="margin-top:12px">
            <?php if ($voted === null): ?>
              <span class="faint tiny">这条内容对你有帮助吗？</span>
              <form method="post" action="<?= e(url('/knowledge/vote')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string)(int)$f['id']) ?>">
                <input type="hidden" name="back" value="<?= e($currentPath ?? '/knowledge') ?>">
                <button class="chip" type="submit" name="v" value="1">
                  <?= icon('check', 15) ?> 有帮助 <span class="n"><?= e((string)(int)$f['helpful']) ?></span>
                </button>
                <button class="chip" type="submit" name="v" value="0">
                  <?= icon('close', 15) ?> 没帮助 <span class="n"><?= e((string)(int)$f['unhelpful']) ?></span>
                </button>
              </form>
            <?php else: ?>
              <span class="alert alert-ok tiny" style="padding:6px 12px">
                <span class="alert-ico" aria-hidden="true"><?= icon('check', 16) ?></span>
                <span class="alert-body">
                  你已评价「<?= $voted ? '有帮助' : '没帮助' ?>」 ·
                  共 <?= e((string)(int)$f['helpful']) ?> 人觉得有帮助
                </span>
              </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</details>
