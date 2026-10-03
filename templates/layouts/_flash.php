<?php
/**
 * 提示消息渲染
 *
 * 旧版把 flash 渲染在粘性导航**上方**，滚动后提示就看不见了；而且有三个
 * 各自独立的提示渲染器（布局里的 flash、提交页的错误清单、详情页的行内提示）。
 * 这里统一成一套 .alert，错误清单作为 alert 的列表内容传入。
 *
 * @var list<array{type:string,msg:string}> $flashes
 */
$flashes = $flashes ?? [];
if ($flashes === []) {
    return;
}

// 每类提示对应的图标名（由 icon() 渲染），与全站同一套线性图标
$map = [
    'ok' => ['ok', 'check-circle', 'alert-ok'],
    'error' => ['err', 'bang', 'alert-err'],
    'warn' => ['warn', 'warn', 'alert-warn'],
    'info' => ['info', 'info', 'alert-info'],
];
?>
<div class="wrap" style="padding-top:18px">
  <div class="stack-sm">
    <?php foreach ($flashes as $f):
        $type = (string)($f['type'] ?? 'info');
        [$cls, $ico] = $map[$type] ?? $map['info']; ?>
      <div class="alert alert-<?= e($cls) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <span class="alert-ico" aria-hidden="true"><?= icon($ico, 18) ?></span>
        <div class="alert-body"><?= e($f['msg'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
