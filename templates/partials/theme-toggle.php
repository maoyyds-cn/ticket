<?php
/**
 * 主题切换控件
 *
 * 三态而不是两态：直接在两态之间切换会丢失「跟随系统」这个默认状态，
 * 用户一旦点过就再也回不回去了。用 radio 实现，因此键盘可操作、
 * 状态对辅助技术可见，JS 只负责把选择写进 localStorage 与 <html data-theme>。
 *
 * 注意：**不含任何脚本**。防闪烁必须在 <head> 里尽早执行
 * （见 layouts 里的 themeHeadScript），而控件接线放在页面脚本里
 * （见 app.js 的 initThemeToggle）。这样才不会出现「深色下一帧白闪」。
 */
$themeChoices = [
    'light'  => ['label' => '浅色', 'icon' => 'sun'],
    'dark'   => ['label' => '深色', 'icon' => 'moon'],
    'system' => ['label' => '跟随系统', 'icon' => 'palette'],
];
?>
<div class="theme-toggle" role="radiogroup" aria-label="主题外观" data-theme-toggle>
  <?php foreach ($themeChoices as $value => $meta): ?>
    <label class="theme-opt" title="<?= e($meta['label']) ?>">
      <input type="radio" name="theme-choice" value="<?= e($value) ?>">
      <span class="theme-opt-in">
        <?= icon($meta['icon'], 15) ?>
        <span class="sr-only"><?= e($meta['label']) ?></span>
      </span>
    </label>
  <?php endforeach; ?>
</div>
