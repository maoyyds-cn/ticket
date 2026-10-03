<?php
/**
 * 工单通道关闭
 *
 * @var string $notice
 */
?>
<div class="page">
  <div class="wrap wrap-narrow">
    <div class="alert alert-warn mb-2">
      <span class="alert-ico" aria-hidden="true"><?= icon('wrench', 17) ?></span>
      <div class="alert-body">
        <div class="alert-title">工单通道暂时关闭</div>
        <?= nl2br(e($notice)) ?>
      </div>
    </div>
    <?php
      $icon = 'book';
      $title = '先看看知识库';
      $text = '大部分常见问题在知识库里都有对应解答，也许能立刻解决你遇到的问题。';
      $actions = [['url' => '/knowledge', 'label' => '浏览知识库', 'primary' => true]];
      require dirname(__DIR__) . '/partials/empty.php';
    ?>
  </div>
</div>
