<?php
/**
 * 附件展示（图片缩略图 + 文档列表）
 *
 * 旧版在同一个文件里把这段逻辑复制了两遍（工单正文一次、每条回复一次），
 * 而且第二份用了 alt="" 让图片失去可访问名称。这里合并成一份，
 * alt 统一使用原始文件名。
 *
 * @var list<array{name:string,path:string,size:int}> $attachments
 * @var bool $compact 紧凑模式（用于时间线内的回复附件）
 */
$attachments = $attachments ?? [];
if ($attachments === []) {
    return;
}
$compact = $compact ?? false;

$images = [];
$files = [];
foreach ($attachments as $a) {
    $ext = strtolower((string)pathinfo((string)$a['path'], PATHINFO_EXTENSION));
    if (\App\Support\Uploader::isImage($ext)) {
        $images[] = $a;
    } else {
        $files[] = $a + ['ext' => $ext];
    }
}

/** 文档类型对应的图标名（由 icon() 渲染成同一套线性图标） */
$fileIcon = static function (string $ext): string {
    return match ($ext) {
        'pdf' => 'file',
        'zip', 'rar', '7z' => 'folder',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'log', 'json' => 'file',
        'mp4', 'webm', 'mp3' => 'image',
        default => 'clip',
    };
};
?>
<?php if ($images !== []): ?>
  <div class="thumbs">
    <?php foreach ($images as $img): ?>
      <a class="thumb" href="<?= e(upload_url((string)$img['path'])) ?>"
         target="_blank" rel="noopener noreferrer" title="<?= e((string)$img['name']) ?>">
        <img src="<?= e(upload_url((string)$img['path'])) ?>"
             alt="<?= e((string)$img['name']) ?>" loading="lazy" decoding="async">
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($files !== []): ?>
  <div class="files">
    <?php foreach ($files as $f):
        $ext = (string)($f['ext'] ?? ''); ?>
      <a class="file-item" href="<?= e(upload_url((string)$f['path'])) ?>"
         target="_blank" rel="noopener noreferrer">
        <span class="file-ico" aria-hidden="true"><?= icon($fileIcon($ext), 16) ?></span>
        <span class="file-name"><?= e((string)$f['name']) ?></span>
        <span class="file-size"><?= e(\App\Support\Str::filesize((int)$f['size'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
