<?php
/**
 * 图标组件
 *
 * 为什么不用 emoji 当图标：
 *  - emoji 的渲染依赖操作系统字体，同一个字符在 Windows / macOS / Android
 *    上长得完全不同，甚至有的平台会用彩色位图覆盖掉 CSS 颜色，
 *    因此无法跟随主题色、无法统一线宽、也无法在深色模式下调色；
 *  - 一排彩色 emoji 并列时，视觉重量彼此接近又会互相抢，
 *    读者抓不到重点——这正是「模板感」最主要的来源之一。
 *
 * 这套图标统一为：24×24 视口、1.7 线宽、圆头圆角、纯描边、
 * 颜色继承 currentColor（因此自动适配深浅主题与所在区域的文字色）。
 *
 * 用法：
 *   <?= icon('ticket') ?>                     默认 18px
 *   <?= icon('search', 16) ?>                 指定尺寸
 *   <?= icon('check', 14, 'icon-accent') ?>   附加 class
 */

if (!function_exists('icon')) {
    /**
     * 取一个图标的 SVG。
     *
     * 全部路径集中在这一个函数里：图标散落在各模板里时，
     * 线宽和风格会慢慢漂移，最后又变成一堆风格不一的贴图。
     */
    function icon(string $name, int $size = 18, string $class = ''): string
    {
        // 每个条目是 SVG 内部路径，统一 24×24 视口
        $paths = [
            // 工单：带签章的票据轮廓
            'ticket' => '<path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v2a2.5 2.5 0 0 0 0 5v2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-2a2.5 2.5 0 0 0 0-5v-2Z"/><path d="M14 6v12"/>',
            // 对话气泡
            'chat' => '<path d="M20 12a7.5 7.5 0 0 1-10.9 6.7L5 20l1.3-4.1A7.5 7.5 0 1 1 20 12Z"/>',
            // 书 / 知识库
            'book' => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10a2 2 0 0 1 2 2v13a1.6 1.6 0 0 0-1.6-1.6H4V5.5Z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H14a2 2 0 0 0-2 2v13a1.6 1.6 0 0 1 1.6-1.6H20V5.5Z"/>',
            // 提交 / 发送
            'send' => '<path d="M4.5 12 20 5l-7 15-2.2-6.1L4.5 12Z"/>',
            // 搜索
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
            // 对勾
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            // 圆形对勾
            'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.2 2.5 2.5 4.5-5"/>',
            // 感叹号三角（警告）
            'warn' => '<path d="M12 4.8 3.6 19h16.8L12 4.8Z"/><path d="M12 10v4"/><path d="M12 16.6h.01"/>',
            // 信息
            'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 8.2h.01"/>',
            // 感叹号圆
            'bang' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v4.5"/><path d="M12 15.8h.01"/>',
            // 时钟
            'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            // 闪电 / 快速
            'bolt' => '<path d="M13.5 3 6 13.5h5L10.5 21 18 10.5h-5L13.5 3Z"/>',
            // 铃铛 / 通知
            'bell' => '<path d="M6.5 16V11a5.5 5.5 0 0 1 11 0v5l1.5 2.5h-14L6.5 16Z"/><path d="M10.5 19a1.8 1.8 0 0 0 3 0"/>',
            // 盾牌 / 防护
            'shield' => '<path d="M12 3.5 5 6.2v5c0 4.3 2.9 7.6 7 9.3 4.1-1.7 7-5 7-9.3v-5l-7-2.7Z"/>',
            // 锁
            'lock' => '<rect x="5.5" y="10.5" width="13" height="9" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
            // 文件夹 / 分类
            'folder' => '<path d="M4 7a1.5 1.5 0 0 1 1.5-1.5h3.2l1.8 2.2H18.5A1.5 1.5 0 0 1 20 9.2v8.3a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5V7Z"/>',
            // 用户
            'user' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5a7 7 0 0 1 14 0"/>',
            // 多人 / 成员
            'users' => '<circle cx="9.5" cy="9" r="3"/><path d="M4 18.5a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6"/><path d="M17.5 18.5a5.5 5.5 0 0 0-1.4-3.7"/>',
            // 信封 / 邮件
            'mail' => '<rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="m4.5 7.5 7.5 5.5 7.5-5.5"/>',
            // 图表 / 概览
            'chart' => '<path d="M4 20h16"/><path d="M7 20V11"/><path d="M12 20V5"/><path d="M17 20v-6"/>',
            // 列表 / 日志
            'list' => '<path d="M8 7h11"/><path d="M8 12h11"/><path d="M8 17h11"/><path d="M4.5 7h.01"/><path d="M4.5 12h.01"/><path d="M4.5 17h.01"/>',
            // 齿轮 / 设置
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M4.9 7.8l1.9 1.1M17.2 15.1l1.9 1.1M4.9 16.2l1.9-1.1M17.2 8.9l1.9-1.1"/>',
            // 剪贴板 / 工单信息
            'clipboard' => '<rect x="6" y="4.5" width="12" height="15" rx="2"/><path d="M9.5 4.5V3.8A1.3 1.3 0 0 1 10.8 2.5h2.4A1.3 1.3 0 0 1 14.5 3.8v.7"/><path d="M9.5 10h5M9.5 13.5h3"/>',
            // 钥匙
            'key' => '<circle cx="8" cy="12" r="3.2"/><path d="M11.2 12H20"/><path d="M17 12v3"/><path d="M14 12v2.2"/>',
            // 回形针 / 附件
            'clip' => '<path d="M16.5 7.5 9 15a3 3 0 1 0 4.2 4.2l7.3-7.3a5 5 0 0 0-7-7L6 12.2a7 7 0 0 0 9.9 9.9"/>',
            // 文档 / 文件
            'file' => '<path d="M6 4.5A1.5 1.5 0 0 1 7.5 3h6L19 8.5v11A1.5 1.5 0 0 1 17.5 21h-10A1.5 1.5 0 0 1 6 19.5v-15Z"/><path d="M13.5 3v5.5H19"/>',
            // 图片
            'image' => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m5.5 17 4.2-4.2 3 3 3-2.4 3.3 3.6"/>',
            // 下载
            'download' => '<path d="M12 4v10"/><path d="m8 10.5 4 4 4-4"/><path d="M4.5 19h15"/>',
            // 加号
            'plus' => '<path d="M12 5.5v13"/><path d="M5.5 12h13"/>',
            // 关闭
            'close' => '<path d="m6.5 6.5 11 11"/><path d="m17.5 6.5-11 11"/>',
            // 箭头右
            'arrow-right' => '<path d="M5 12h13"/><path d="m12.5 6.5 5.5 5.5-5.5 5.5"/>',
            // 外部链接
            'external' => '<path d="M14 5h5v5"/><path d="M19 5l-7.5 7.5"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
            // 刷新
            'refresh' => '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.8 4.5v4.2h-4.2"/>',
            // 垃圾桶
            'trash' => '<path d="M5.5 7h13"/><path d="M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0 0 1 1.2 1.2V7"/><path d="M7.5 7l.8 11.3A1.5 1.5 0 0 0 9.8 19.7h4.4a1.5 1.5 0 0 0 1.5-1.4L16.5 7"/>',
            // 太阳（浅色主题）
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.8v2.4M12 18.8v2.4M4.6 4.6l1.7 1.7M17.7 17.7l1.7 1.7M2.8 12h2.4M18.8 12h2.4M4.6 19.4l1.7-1.7M17.7 6.3l1.7-1.7"/>',
            // 月亮（深色主题）
            'moon' => '<path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.2 8.2 0 1 0 10.2 10.2Z"/>',
            // 退出
            'logout' => '<path d="M14.5 5.5h-8A1.5 1.5 0 0 0 5 7v10a1.5 1.5 0 0 0 1.5 1.5h8"/><path d="M13 12h7.5"/><path d="m17.5 8.5 3.5 3.5-3.5 3.5"/>',
            // 空状态：信箱
            'inbox' => '<path d="M4 13.5 6.2 6A1.5 1.5 0 0 1 7.6 5h8.8A1.5 1.5 0 0 1 17.8 6L20 13.5V18a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18v-4.5Z"/><path d="M4 13.5h4l1 2.2h6l1-2.2h4"/>',
            // 指南针（404）
            'compass' => '<circle cx="12" cy="12" r="8.5"/><path d="m15 9-2 4-4 2 2-4 4-2Z"/>',
            // 星（评分）
            'star' => '<path d="m12 4.5 2.3 4.9 5.2.7-3.8 3.7.9 5.3L12 16.6 7.4 19l.9-5.3L4.5 10l5.2-.7L12 4.5Z"/>',
            // 火箭（快速入门）
            'rocket' => '<path d="M13.5 4.5c3 0 6 3 6 6 0 3.5-3.5 6.5-7.5 8.5L9 16l-2.5-3.5C8.5 8.5 11 4.5 13.5 4.5Z"/><path d="M7.5 16.5 5 19"/><circle cx="14.5" cy="9.5" r="1.6"/>',
            // 手柄（功能使用）
            'gamepad' => '<path d="M7.5 8h9a4 4 0 0 1 3.9 3.1l.7 3.4A2.6 2.6 0 0 1 18.6 17c-.9 0-1.7-.5-2.1-1.3L16 15H8l-.5.7c-.4.8-1.2 1.3-2.1 1.3a2.6 2.6 0 0 1-2.5-2.5l.7-3.4A4 4 0 0 1 7.5 8Z"/><path d="M7 11.5h2M8 10.5v2"/><path d="M15.5 11.5h.01M17 13h.01"/>',
            // 钻石（积分等级）
            'diamond' => '<path d="m12 3.5 5 5-5 12-5-12 5-5Z"/><path d="M7 8.5h10"/>',
            // 钥匙孔账号
            'account' => '<circle cx="12" cy="10" r="3.2"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
            // 服务器（部署运维）
            'server' => '<rect x="4" y="4.5" width="16" height="6" rx="1.5"/><rect x="4" y="13.5" width="16" height="6" rx="1.5"/><path d="M8 7.5h.01M8 16.5h.01"/>',
            // 扳手（维护 / 暂时关闭）
            'wrench' => '<path d="M15.2 4.5a4.6 4.6 0 0 0-6 5.9L4.6 15a2 2 0 0 0 0 2.8l1.6 1.6a2 2 0 0 0 2.8 0l4.6-4.6a4.6 4.6 0 0 0 5.9-6l-2.8 2.8-2.4-.7-.7-2.4 2.8-2.8Z"/>',
            // 问号圆（常见报错）
            'help' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.6a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .8-1 1.6"/><path d="M12 16.6h.01"/>',
            // 调色板（主题）
            'palette' => '<path d="M12 3.5a8.5 8.5 0 0 0 0 17c1.4 0 2-.9 2-1.8 0-1.6-1.4-1.8-1.4-3 0-.8.7-1.4 1.6-1.4h1.4A4.9 4.9 0 0 0 20.5 9c0-3.1-3.7-5.5-8.5-5.5Z"/><circle cx="8" cy="10" r="1.1"/><circle cx="12" cy="7.5" r="1.1"/><circle cx="16" cy="10" r="1.1"/>',
        ];

        $body = $paths[$name] ?? $paths['info'];
        $cls = trim('icon ' . $class);

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'),
            $size,
            $size,
            $body
        );
    }
}

if (!function_exists('brand_mark')) {
    /**
     * 品牌标识：圆角方块 + 票据轮廓 + 对勾。
     *
     * 用几何图形而不是 emoji：emoji 在不同系统上字形不同、颜色也无法控制，
     * 而品牌标识恰恰是最需要保持一致的地方。
     */
    function brand_mark(int $size = 30): string
    {
        return sprintf(
            '<svg class="brand-svg" width="%d" height="%d" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
            . '<rect width="32" height="32" rx="9" fill="currentColor" class="brand-bg"/>'
            . '<path d="M9 12.4A1.4 1.4 0 0 1 10.4 11h11.2a1.4 1.4 0 0 1 1.4 1.4v1.7a2.1 2.1 0 0 0 0 4.2v1.3A1.4 1.4 0 0 1 21.6 21H10.4A1.4 1.4 0 0 1 9 19.6v-1.3a2.1 2.1 0 0 0 0-4.2v-1.7Z" '
            . 'fill="none" stroke="#fff" stroke-width="1.6"/>'
            . '<path d="m13.4 16.1 1.7 1.7 3.5-3.9" fill="none" stroke="#fff" '
            . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>',
            $size,
            $size
        );
    }
}

if (!function_exists('category_icon_name')) {
    /**
     * 分类对应的图标名。
     *
     * 分类原先用 emoji（🚀🎮💎…）当图标，存在两个问题：
     *  - 颜色由系统字体决定，无法跟随分类在后台配置的主题色，
     *    也无法在深色模式下调色；
     *  - 七个彩色贴图并列时视觉重量彼此接近，读者抓不到重点。
     *
     * 改为「图标名」之后，渲染交给 icon()，颜色走 category 的主题色令牌。
     * 先用 slug 判断，老数据的 slug 为空时用中文名兜底。
     *
     * @param string $slug 分类别名（优先）
     * @param string $name 分类名称（兜底）
     */
    function category_icon_name(string $slug = '', string $name = ''): string
    {
        $key = $slug !== '' ? $slug : $name;

        $bySlug = [
            'start' => 'rocket',
            'usage' => 'gamepad',
            'points' => 'diamond',
            'account' => 'account',
            'risk' => 'shield',
            'deploy' => 'server',
            'error' => 'help',
        ];
        $byName = [
            '快速入门' => 'rocket',
            '功能使用' => 'gamepad',
            '积分与等级' => 'diamond',
            '账号与权限' => 'account',
            '风控与审核' => 'shield',
            '部署与运维' => 'server',
            '常见报错' => 'help',
        ];

        return $bySlug[$key] ?? $byName[$name] ?? 'folder';
    }
}

if (!function_exists('category_glyph')) {
    /**
     * 分类图标（含主题色容器）。
     *
     * @param string $slug  分类别名
     * @param string $color 分类主题色
     */
    function category_glyph(string $slug = '', string $color = '#4051d4', int $size = 20, string $name = ''): string
    {
        return sprintf(
            '<span class="cat-glyph" style="--cat:%s">%s</span>',
            htmlspecialchars($color !== '' ? $color : '#4051d4', ENT_QUOTES, 'UTF-8'),
            icon(category_icon_name($slug, $name), $size)
        );
    }
}
