<?php
/**
 * 404
 */
?>
<div class="page">
  <div class="wrap wrap-narrow">
    <?php
      $icon = 'compass';
      $title = '页面不存在';
      $text = '这个地址可能已经变更或被删除。你可以从首页重新出发，或者搜索知识库。';
      $actions = [
          ['url' => '/', 'label' => '返回首页', 'primary' => true],
          ['url' => '/knowledge', 'label' => '浏览知识库'],
      ];
      require dirname(__DIR__) . '/partials/empty.php';
    ?>
  </div>
</div>
