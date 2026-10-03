<?php
/**
 * 工单不存在 / 无权访问
 *
 * 文案刻意不区分「不存在」「已删除」「密钥错误」三种情况：
 * 如果错误提示能区分它们，就成了一个枚举工单编号与试探密钥的接口。
 *
 * @var string $no
 */
?>
<div class="page">
  <div class="wrap wrap-narrow">
    <?php
      $icon = 'lock';
      $title = '无法访问这条工单';
      $text = '工单不存在、已被删除，或者你没有访问权限。请核对编号与访问密钥；'
          . '如果你登录了账号，也可以到「我的工单」里查看。';
      $actions = [
          ['url' => '/my-tickets', 'label' => '重新查询', 'primary' => true],
          ['url' => '/knowledge', 'label' => '浏览知识库'],
      ];
      require dirname(__DIR__) . '/partials/empty.php';
    ?>
    <?php if ($no !== ''): ?>
      <p class="center faint tiny mt-2">你查询的编号：<span class="mono"><?= e($no) ?></span></p>
    <?php endif; ?>
  </div>
</div>
