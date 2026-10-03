<?php

/**
 * 附件上传
 *
 * v1 的 save_uploads() 有 100 多行，里面混着三种 $_FILES 形态的兼容代码、
 * 一段「无条件把整个 $_FILES 写进日志」的诊断逻辑，还有一个真实的坑：
 * 它对每个 slot 都记录诊断，日志里塞满了 tmp_name 之类的敏感路径。
 *
 * 这里只做该做的事，并且把「为什么拒绝」结构化返回，让页面能逐条提示。
 */
declare(strict_types=1);

namespace App\Support;

final class Uploader
{
    /**
     * 允许的扩展名。
     * 刻意不含 svg / html / htm：这两类文件可以被当作脚本在浏览器里执行，
     * 在附件预览场景里属于典型存储型 XSS 载体。
     */
    private const ALLOWED = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'txt', 'log', 'json', 'csv',
        'zip', 'rar', '7z',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp4', 'webm', 'mp3',
    ];

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** @var list<string> 本次被拒绝的文件及原因 */
    private array $errors = [];

    public function __construct(private readonly string $uploadDir)
    {
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function isImage(string $ext): bool
    {
        return in_array(strtolower($ext), self::IMAGE_EXT, true);
    }

    /**
     * 保存一组上传文件。
     *
     * @param list<array{name:string,tmp_name:string,error:int,size:int}> $files
     * @param int $maxCount 本次最多接收几个
     * @param int $maxMb    单个文件大小上限（MB）
     * @return list<array{name:string,path:string,size:int}>
     */
    public function save(array $files, int $maxCount, int $maxMb): array
    {
        $saved = [];
        if ($files === []) {
            return $saved;
        }

        $maxBytes = max(1, $maxMb) * 1024 * 1024;
        $sub = date('Ym');
        $dir = rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR . $sub;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->errors[] = '附件目录不可写，请联系管理员';
            return [];
        }

        foreach ($files as $file) {
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

            // 浏览器会为未选文件的 input 也提交一个空 slot，静默跳过
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $displayName = Str::limit((string)($file['name'] ?? '未命名文件'), 80);

            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $this->errors[] = $displayName . ' 超过服务器上传限制';
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $this->errors[] = $displayName . ' 上传失败（错误码 ' . $error . '）';
                continue;
            }
            if (count($saved) >= $maxCount) {
                $this->errors[] = '附件最多 ' . $maxCount . ' 个，多余的已忽略';
                break;
            }
            if ((int)($file['size'] ?? 0) > $maxBytes) {
                $this->errors[] = $displayName . ' 超过 ' . $maxMb . 'MB 限制';
                continue;
            }

            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $this->errors[] = $displayName . ' 无效的上传临时文件';
                continue;
            }

            $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, self::ALLOWED, true)) {
                $this->errors[] = $displayName . ' 的格式（.' . $ext . '）不被允许';
                continue;
            }

            // 随机文件名：既避免重名覆盖，也避免原始文件名里的路径片段被带入磁盘
            $base = Str::randomHex(8);
            $stored = $base . '.' . $ext;
            $target = $dir . DIRECTORY_SEPARATOR . $stored;

            if (!@move_uploaded_file($tmp, $target)) {
                $this->errors[] = $displayName . ' 保存失败';
                continue;
            }
            @chmod($target, 0644);

            $saved[] = [
                'name' => $displayName,
                'path' => $sub . '/' . $stored,
                'size' => (int)($file['size'] ?? 0),
            ];
        }

        return $saved;
    }

    /**
     * 删除附件物理文件。
     *
     * 路径来自数据库，但仍然要校验最终落点在 uploads 目录内：
     * 一旦库里的 path 被写入 ../ 之类的内容，直接 unlink 会删到站外文件。
     *
     * @param list<array{path?:string}|string> $attachments
     */
    public function delete(array $attachments): void
    {
        $root = realpath($this->uploadDir);
        if ($root === false) {
            return;
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

        foreach ($attachments as $item) {
            $rel = is_array($item) ? (string)($item['path'] ?? '') : (string)$item;
            if ($rel === '' || str_contains($rel, "\0")) {
                continue;
            }
            $full = realpath(rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
            if ($full === false) {
                continue;
            }
            $norm = str_replace('\\', '/', $full);
            if (!str_starts_with($norm, $rootPrefix)) {
                continue; // 越界，拒绝删除
            }
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}
