<?php
/**
 * 空状态
 *
 * 旧版有 5 处各写一遍的空状态，文案与结构还各不相同。统一成一个组件后，
 * 新增页面不会再出现「这次忘了给空状态加操作按钮」这种情况。
 *
 * @var string $icon    图标名（见 Support\icons.php），例如 inbox / search / lock
 * @var string $title
 * @var string $text
 * @var array  $actions  形如 [['url'=>'/x','label'=>'前往','primary'=>true], ...]
 *
 * 注意 $icon 是**图标名**而不是字符：早期版本直接塞 emoji，
 * 于是每个调用点都要自己挑一个彩色字符，样式无法统一、也无法跟随主题调色。
 */
$icon = $icon ?? 'inbox';
$title = $title ?? '暂无内容';
$text = $text ?? '';
$actions = $actions ?? [];
?>
<div class="empty">
  <div class="empty-ico" aria-hidden="true"><?= icon((string)$icon, 30) ?></div>
  <h3><?= e($title) ?></h3>
  <?php if ($text !== ''): ?>
    <p><?= e($text) ?></p>
  <?php endif; ?>
  <?php if ($actions !== []): ?>
    <div class="empty-act row-wrap" style="justify-content:center">
      <?php foreach ($actions as $a): ?>
        <a class="btn <?= !empty($a['primary']) ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= e(url((string)($a['url'] ?? '/'))) ?>"><?= e((string)($a['label'] ?? '前往')) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
