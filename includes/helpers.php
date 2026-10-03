<?php
/**
 * 通用辅助函数
 */
declare(strict_types=1);

// ---------------- 基础 ----------------

function cfg(string $key, $default = null)
{
    global $CFG;
    $parts = explode('.', $key);
    $cur   = $CFG;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function setting(string $key, $default = null)
{
    global $SETTINGS;
    return array_key_exists($key, $SETTINGS) && $SETTINGS[$key] !== '' ? $SETTINGS[$key] : $default;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, null);
    return $v === null || $v === '' ? $default : (int)$v;
}

function set_setting(string $key, string $value): void
{
    db_query(
        'INSERT INTO ' . DB_PRE . 'settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
        [$key, $value]
    );
    $GLOBALS['SETTINGS'][$key] = $value;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 富文本渲染时允许的标签白名单 */
const RICH_TAGS = '<p><br><hr><strong><b><em><i><u><s><del><code><pre><blockquote>'
    . '<ul><ol><li><dl><dt><dd>'
    . '<h3><h4><h5><h6><a><span><div><table><thead><tbody><tfoot><tr><th><td>';

/**
 * 净化富文本：保留白名单排版标签，剥离脚本、事件属性与危险协议。
 * 供 FAQ 答案、站内公告等由后台维护的内容使用。
 */
function rich(?string $html): string
{
    $html = (string)$html;
    if ($html === '') {
        return '';
    }

    // 移除注释、CDATA、style 与 script 整块内容
    $html = preg_replace('#<!--.*?-->#s', '', $html);
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    $html = preg_replace('#<!\[CDATA\[.*?\]\]>#s', '', $html);

    // 移除白名单之外的标签（保留其内容）
    $html = strip_tags($html, RICH_TAGS);

    // 清除所有 on* 事件属性
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

    // 清除危险协议（javascript: / data: / vbscript:）
    $html = preg_replace('#\s(href|src)\s*=\s*("|\')\s*(javascript|data|vbscript):[^"\']*\2#i', '', $html);
    $html = preg_replace('#\s(href|src)\s*=\s*(javascript|data|vbscript):[^\s>]+#i', '', $html);

    // 清除 style 属性中的 expression / url 等可执行写法
    $html = preg_replace('#\sstyle\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html);

    // 移除因属性被剥离而残留的孤立闭合标签
    $html = preg_replace('#</\s*(?:a|span|div|p|code|strong|b|em|i|u|s)\s*>#i', '', $html);

    return $html;
}

/**
 * FAQ 答案渲染：净化 HTML + 处理 \n\n 分段与 \n 换行
 */
function rich_answer(?string $text): string
{
    $html = rich($text);
    if ($html === '') {
        return '';
    }
    // 去掉首尾空白，规范化空行
    $html = trim($html);
    $html = preg_replace("/\r\n?/", "\n", $html);
    // 连续空行分段，单个换行转 <br>
    $html = preg_replace("/\n{2,}/", "\n\n", $html);
    return nl2br($html, false);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
}

function post(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $k, $default = '')
{
    return $_GET[$k] ?? $default;
}

function get_int(string $k, int $default = 0): int
{
    $v = $_GET[$k] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

/**
 * 应用在 Web 根目录下的相对前缀（用于子目录部署）
 *
 * 通过「当前脚本在项目内的相对路径」从 SCRIPT_NAME 中剥离，
 * 这样在 admin/ 等子目录页面下也能得到正确的前缀（空串）。
 */
function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($scriptName === '' || $scriptFile === '') {
        return '';
    }

    // 当前脚本在项目内的相对路径，如 /admin/login.php
    $appRoot  = rtrim(str_replace('\\', '/', APP_ROOT), '/');
    $filePath = rtrim(str_replace('\\', '/', $scriptFile), '/');
    if ($appRoot === '' || stripos($filePath, $appRoot) !== 0) {
        return '';
    }
    $rel = substr($filePath, strlen($appRoot));   // /admin/login.php
    $rel = ltrim($rel, '/');                     // admin/login.php

    // 从 URL 路径中去掉这段相对层级
    $url  = rtrim($scriptName, '/');             // /admin/login.php
    $need = '/' . $rel;
    if ($rel !== '' && strlen($url) >= strlen($need) && strcasecmp(substr($url, -strlen($need)), $need) === 0) {
        $url = substr($url, 0, strlen($url) - strlen($need));
    }

    return rtrim($url, '/');
}

function site_url(string $path = ''): string
{
    $base = rtrim((string)setting('site_url', ''), '/');
    if ($base === '') {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = $https ? 'https' : 'http';
        $base   = $scheme . '://' . $host . app_base_path();
    }
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

// ---------------- CSRF ----------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $t = $_POST['_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($t) && hash_equals($_SESSION['csrf_token'], $t);
}

function csrf_guard(): void
{
    if (!csrf_check()) {
        http_response_code(400);
        exit('CSRF 校验失败，请返回重试。');
    }
}

// ---------------- 消息提示 ----------------

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function old_keep(array $data): void
{
    unset($data['_token'], $data['password'], $data['password2'], $data['captcha']);
    $_SESSION['old'] = $data;
}

function old_clear(): void
{
    unset($_SESSION['old']);
}

// ---------------- 分页 ----------------

function paginate(int $total, int $perPage, int $current): array
{
    $pages   = max(1, (int)ceil($total / max(1, $perPage)));
    $current = max(1, min($current, $pages));
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'current'  => $current,
        'pages'    => $pages,
        'offset'   => ($current - 1) * $perPage,
        'has_prev' => $current > 1,
        'has_next' => $current < $pages,
    ];
}

function page_link(int $page, string $extra = ''): string
{
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q) . ($extra !== '' ? '&' . $extra : '');
}

// ---------------- 格式化 ----------------

function fmt_date(?string $t, string $fmt = 'Y-m-d H:i'): string
{
    if (empty($t) || $t === '0000-00-00 00:00:00') {
        return '—';
    }
    return date($fmt, strtotime($t));
}

function time_ago(?string $t): string
{
    if (empty($t)) {
        return '—';
    }
    $diff = time() - strtotime($t);
    if ($diff < 0)    $diff = 0;
    if ($diff < 60)   return '刚刚';
    if ($diff < 3600) return intdiv($diff, 60) . ' 分钟前';
    if ($diff < 86400) return intdiv($diff, 3600) . ' 小时前';
    if ($diff < 2592000) return intdiv($diff, 86400) . ' 天前';
    return date('Y-m-d', strtotime($t));
}

function fmt_num(int $n): string
{
    return $n >= 10000 ? round($n / 10000, 1) . 'w' : (string)$n;
}

// ---------------- 业务字典 ----------------

const TICKET_STATUS = [
    'pending'    => ['label' => '待处理', 'color' => 'amber'],
    'processing' => ['label' => '处理中', 'color' => 'blue'],
    'replied'    => ['label' => '已回复', 'color' => 'violet'],
    'resolved'   => ['label' => '已解决', 'color' => 'green'],
    'closed'     => ['label' => '已关闭', 'color' => 'gray'],
    'spam'       => ['label' => '垃圾工单', 'color' => 'red'],
];

const TICKET_PRIORITY = [
    'low'    => ['label' => '低', 'color' => 'gray'],
    'normal' => ['label' => '普通', 'color' => 'blue'],
    'high'   => ['label' => '高', 'color' => 'orange'],
    'urgent' => ['label' => '紧急', 'color' => 'red'],
];

function status_meta(string $s): array
{
    return TICKET_STATUS[$s] ?? ['label' => $s, 'color' => 'gray'];
}

function priority_meta(string $p): array
{
    return TICKET_PRIORITY[$p] ?? TICKET_PRIORITY['normal'];
}

function status_badge(string $s): string
{
    $m = status_meta($s);
    return '<span class="badge badge-' . $m['color'] . '">' . e($m['label']) . '</span>';
}

function priority_badge(string $p): string
{
    $m = priority_meta($p);
    return '<span class="badge badge-' . $m['color'] . '">' . e($m['label']) . '</span>';
}

function mask_email(string $e): string
{
    if (!str_contains($e, '@')) {
        return $e;
    }
    [$u, $d] = explode('@', $e, 2);
    if (mb_strlen($u) <= 2) {
        return $u . '***@' . $d;
    }
    return mb_substr($u, 0, 2) . '***' . mb_substr($u, -1) . '@' . $d;
}

function is_valid_email(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

// ---------------- 附件 ----------------

const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'txt', 'log', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'mp4', 'webm', 'mp3', 'json'];

function is_image_file(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function human_filesize(int $size): string
{
    if ($size < 1024)      return $size . ' B';
    if ($size < 1048576)   return round($size / 1024, 1) . ' KB';
    if ($size < 1073741824) return round($size / 1048576, 2) . ' MB';
    return round($size / 1073741824, 2) . ' GB';
}

/**
 * 保存上传文件
 * @param array    $files $_FILES 中的一组
 * @param string   $sub   子目录，默认按年月
 * @param string[] $errs  回传被拒原因，避免静默丢弃让用户困惑
 * @return array<int,array{name:string,path:string,size:int}>
 */
function save_uploads(array $files, string $sub = '', array &$errs = []): array
{
    $maxSize = setting_int('upload_max_mb', 10) * 1024 * 1024;
    $maxCnt  = max(1, setting_int('upload_max_count', 5));
    $sub     = $sub !== '' ? trim($sub, '/') : date('Ym');
    $dir     = UPLOAD_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errs[] = '附件目录不可写，请联系管理员';
        return [];
    }

    // 归一化：$_FILES 存在两种形态。
    // ① 列表态：['0'=>['name'=>..,'tmp_name'=>..], '1'=>[...]] —— 常规 multiple 表单
    // ② 列式态：['name'=>[0=>..], 'tmp_name'=>[0=>..], 'size'=>[0=>..], ...]
    //    键名是文件的 6 个字段、值仍是数组。某些浏览器 / 某些 input name 写法
    //    （如 files[name][]）会得到这种形态，此前按「非数组」逐个丢弃，
    //    导致用户选好附件却静默丢失。这里统一还原成列表态。
    if ($files && is_array($files)) {
        $firstKey = array_key_first($files);
        if ($firstKey !== null && !is_int($firstKey)
            && is_array($files[$firstKey])
            && array_key_exists('tmp_name', $files)) {
            $n = 0;
            foreach ($files['tmp_name'] as $_) { $n++; }
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $row = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size', 'full_path'] as $field) {
                    $row[$field] = $files[$field][$i] ?? ($field === 'error' ? UPLOAD_ERR_NO_FILE : '');
                }
                $rows[] = $row;
            }
            $files = $rows;
        } elseif (array_key_exists('tmp_name', $files) && !is_array($files['tmp_name'])) {
            $files = [$files];
        }
    }

    $list = [];
    // 诊断：无条件记录 $_FILES 全貌。此前只在「完全为空」时记日志，
    // 导致「传了但每个 slot 都是 NO_FILE」这类情况完全无迹可寻。
    $diag = '[Upload] sub=' . $sub
        . ' | slots=' . (is_array($files) ? count($files) : 0)
        . ' | upload_max=' . ini_get('upload_max_filesize')
        . ' post_max=' . ini_get('post_max_size')
        . ' file_uploads=' . ini_get('file_uploads')
        . ' | 明细=';
    if (!$files) {
        $diag .= '(空)';
    } else {
        foreach ($files as $k => $f) {
            if (is_array($f)) {
                $inner = [];
                foreach ($f as $ik => $iv) {
                    $inner[] = $ik . '=' . (is_scalar($iv) ? (string)$iv : gettype($iv));
                }
                $diag .= "[$k]{" . implode(',', $inner) . '} ';
            } else {
                $diag .= "[$k]=" . (is_scalar($f) ? (string)$f : gettype($f)) . ' ';
            }
        }
    }
    app_error_log(rtrim($diag));

    foreach (array_keys($files) as $k) {
        $f = $files[$k];
        if (!is_array($f)) {
            $errs[] = '文件数据异常，请重试';
            continue;
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            // 浏览器会为未选文件的 input 也提交一个占位 slot。
            // 仅当用户确实没有选择任何文件时才提示，避免噪音。
            $hadName = trim((string)($f['name'] ?? '')) !== '';
            if ($hadName) {
                $errs[] = mb_substr($f['name'], 0, 40) . ' 未能上传，请重新选择';
            }
            continue;
        }
        $fname = mb_substr((string)($f['name'] ?? '文件'), 0, 80);

        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errs[] = $fname . ' 超过服务器上传大小限制';
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errs[] = $fname . ' 上传失败（错误码 ' . (int)$f['error'] . '）';
            continue;
        }
        if (count($list) >= $maxCnt) {
            $errs[] = '附件最多上传 ' . $maxCnt . ' 个，其余已忽略';
            break;
        }
        if ((int)$f['size'] > $maxSize) {
            $errs[] = $fname . ' 超过 ' . setting_int('upload_max_mb', 10) . 'MB 限制';
            continue;
        }
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) {
            $errs[] = $fname . ' 的格式（.' . $ext . '）不被允许';
            continue;
        }
        $base = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', pathinfo($fname, PATHINFO_FILENAME));
        $name = $base . '.' . $ext;
        if (@move_uploaded_file($f['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
            $list[] = ['name' => $fname, 'path' => $sub . '/' . $name, 'size' => (int)$f['size']];
        } else {
            $errs[] = $fname . ' 保存失败';
        }
    }
    return $list;
}

/**
 * 删除附件物理文件
 * @param array<int,mixed> $attachments 附件记录数组
 */
function delete_attachments(array $attachments): void
{
    $root = realpath(UPLOAD_PATH);
    if ($root === false) {
        return;
    }
    foreach ($attachments as $a) {
        $rel = is_array($a) ? (string)($a['path'] ?? '') : (string)$a;
        if ($rel === '') {
            continue;
        }
        // 拼接后必须仍位于 uploads 目录内，防止路径穿越删到外部文件
        $full = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
        if ($full === false || str_starts_with($full, $root . DIRECTORY_SEPARATOR) === false) {
            continue;
        }
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

function upload_url(string $rel): string
{
    return site_url('uploads/' . ltrim($rel, '/'));
}
