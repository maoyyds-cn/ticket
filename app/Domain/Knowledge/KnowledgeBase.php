<?php

/**
 * 知识库服务
 *
 * 中文检索的取舍（这是本项目里最容易被做错的一处）：
 *
 * v1 对 (question, answer, keywords) 建了 FULLTEXT 索引，但全项目没有一处
 * MATCH ... AGAINST —— 索引白建。更关键的是，InnoDB 默认分词器按空格/标点
 * 切词，中文整句会被当成一个 token，且 innodb_ft_min_token_size 默认为 3，
 * 「签到」「限流」这类两字词根本进不了索引。也就是说，即便把查询改成
 * MATCH AGAINST，中文搜索依然是坏的。
 *
 * 所以这里不建 FULLTEXT：用 LIKE + 应用层相关性打分。数据规模（几百到
 * 几千条 FAQ）下 LIKE 完全够用，而打分留在 PHP 里可以让「标题命中」优先于
 * 「正文命中」，结果排序比 MATCH AGAINST 的自然排序更符合直觉。
 * 真到了需要全文检索的量级，正确做法是加 ngram 解析器或外接搜索引擎，
 * 那是另一件事，不该用「建了个用不上的索引」来假装解决了。
 */
declare(strict_types=1);

namespace App\Domain\Knowledge;

use App\Core\App;
use App\Core\Database;
use App\Core\Session;
use App\Support\Str;

final class KnowledgeBase
{
    /** 同一会话内，同一条 FAQ 的浏览量在多少秒内只计一次 */
    private const VIEW_DEDUPE_SECONDS = 1800;

    /** FAQ 浏览量去重表的会话键最多保留多少个条目 */
    private const VIEW_MEMORY_MAX = 300;

    /**
     * 浏览量去重用的 Cookie 名。
     *
     * 用 Cookie 而不是会话是有意为之：写会话会让 PHP 下发 Set-Cookie，
     * 页面因此无法被边缘缓存，每次翻页都要跨国回源。
     * 详见 recordView() 的说明。
     */
    private const VIEW_COOKIE = 'tk_faq_seen';

    /** 本次请求累计的浏览记录（id => 时间戳），null 表示尚未读取 Cookie */
    private ?array $viewSeen = null;

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
    ) {
    }

    // ---------------------------------------------------------------
    // 查询
    // ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return $this->db->row(
            'SELECT f.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
             FROM `faq` f
             LEFT JOIN `category` c ON c.id = f.category_id
             WHERE f.id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * 知识库检索。
     *
     * status 的三种语义（前台只用到第一种，管理端需要后两种）：
     *   1  = 只看已发布（前台）
     *   0  = 只看草稿
     *   null = 全部状态（管理端列表）
     *
     * @param array{category_id?:int,q?:string,sort?:string,status?:int|null} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $keyword = trim((string)($filters['q'] ?? ''));
        $categoryId = (int)($filters['category_id'] ?? 0);
        $sort = (string)($filters['sort'] ?? 'default');

        $where = [];
        $params = [];

        if (array_key_exists('status', $filters) && $filters['status'] !== null) {
            $where[] = 'f.status = ?';
            $params[] = (int)$filters['status'];
        } else {
            // 默认（未指定 status）按前台语义只看已发布；
            // 管理端显式传 null 才表示「全部状态」。
            if (!array_key_exists('status', $filters)) {
                $where[] = 'f.status = 1';
            }
        }

        if ($categoryId > 0) {
            $where[] = 'f.category_id = ?';
            $params[] = $categoryId;
        }
        if ($keyword !== '') {
            $like = '%' . $this->escapeLike($keyword) . '%';
            $where[] = '(f.question LIKE ? ESCAPE \'\\\\\' OR f.keywords LIKE ? ESCAPE \'\\\\\' OR f.answer LIKE ? ESCAPE \'\\\\\')';
            array_push($params, $like, $like, $like);
        }

        // 管理端可以明确要求「不限状态」，此时 where 可能为空，
        // 必须补一个恒真条件，否则会拼出 "WHERE " 这样的语法错误
        if ($where === []) {
            $where[] = '1 = 1';
        }

        $whereSql = implode(' AND ', $where);

        $total = $this->db->int($this->joinFrom() . ' WHERE ' . $whereSql, $params, 0);

        // 排序：有关键词时按相关性在 PHP 侧重排，因此 SQL 先取全部候选。
        // 有关键词的结果集通常很小（LIKE 已经筛过一轮），这个代价可以接受；
        // 无关键词时才真正走数据库分页。
        if ($keyword !== '') {
            $rows = $this->db->all(
                $this->joinFrom() . ' WHERE ' . $whereSql . ' ORDER BY ' . $this->orderSql($sort) . ' LIMIT 400',
                $params
            );
            $ranked = $this->rank($rows, $keyword);
            $total = count($ranked);
            $items = array_slice($ranked, ($page - 1) * $perPage, $perPage);
        } else {
            $items = $this->db->all(
                $this->joinFrom() . ' WHERE ' . $whereSql . ' ORDER BY ' . $this->orderSql($sort)
                . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
                $params
            );
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private function joinFrom(): string
    {
        return 'SELECT f.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
                FROM `faq` f
                LEFT JOIN `category` c ON c.id = f.category_id';
    }

    /** 排序字段白名单：这部分会被拼进 SQL，绝不接受用户原样输入 */
    private function orderSql(string $sort): string
    {
        return match ($sort) {
            'hot' => 'f.views DESC, f.helpful DESC, f.id DESC',
            'new' => 'f.updated_at DESC, f.id DESC',
            'helpful' => 'f.helpful DESC, f.views DESC',
            // 推荐：置顶 > 热门 > 有用度 > 手动排序
            default => 'f.is_top DESC, f.is_hot DESC, f.sort DESC, f.helpful DESC, f.id DESC',
        };
    }

    /**
     * 相关性打分。
     *
     * 权重设计：标题命中 10 分、关键词命中 6 分、正文命中 1 分；
     * 「完全等于标题」额外加 50 分。这样搜「签到」时，标题就叫「签到」的
     * 那条一定排第一，而正文里顺带提到签到的条目排在后面。
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function rank(array $rows, string $keyword): array
    {
        $needle = mb_strtolower($keyword, 'UTF-8');
        $scored = [];
        foreach ($rows as $row) {
            $question = mb_strtolower((string)$row['question'], 'UTF-8');
            $keywords = mb_strtolower((string)$row['keywords'], 'UTF-8');
            $answer = mb_strtolower((string)$row['answer'], 'UTF-8');

            $score = 0;
            if ($question === $needle) {
                $score += 50;
            }
            if (str_contains($question, $needle)) {
                $score += 10;
            }
            if (str_contains($keywords, $needle)) {
                $score += 6;
            }
            if (str_contains($answer, $needle)) {
                $score += 1;
            }
            // 置顶内容无论如何都排在前面
            if ((int)$row['is_top'] === 1) {
                $score += 3;
            }
            if ((int)$row['is_hot'] === 1) {
                $score += 1;
            }

            $scored[] = ['__score' => $score, 'row' => $row];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['__score'] !== $b['__score']) {
                return $b['__score'] <=> $a['__score'];
            }
            // 同分时按浏览量降序，保证顺序稳定
            return ((int)$b['row']['views']) <=> ((int)$a['row']['views']);
        });

        return array_map(static fn(array $x): array => $x['row'], $scored);
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * 前台展示用的列表（首页热门、最新）。
     *
     * @return list<array<string,mixed>>
     */
    public function hot(int $limit = 8): array
    {
        return $this->db->all(
            $this->joinFrom() . ' WHERE f.status = 1 AND (f.is_hot = 1 OR f.is_top = 1)
             ORDER BY f.is_top DESC, f.views DESC, f.helpful DESC LIMIT ' . max(1, $limit)
        );
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 6): array
    {
        return $this->db->all(
            $this->joinFrom() . ' WHERE f.status = 1
             ORDER BY f.is_top DESC, f.updated_at DESC, f.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * 各分类下的已发布条目数。
     *
     * @return array<int,int> category_id => 数量
     */
    public function countsByCategory(): array
    {
        $rows = $this->db->all(
            'SELECT category_id, COUNT(*) AS n FROM `faq` WHERE status = 1 GROUP BY category_id'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['category_id']] = (int)$r['n'];
        }
        return $out;
    }

    public function countPublished(): int
    {
        return $this->db->int('SELECT COUNT(*) FROM `faq` WHERE status = 1');
    }

    // ---------------------------------------------------------------
    // 浏览量
    // ---------------------------------------------------------------

    /**
     * 记录一次浏览。
     *
     * v1 在 GET 里无脑 +1，爬虫与链接预取都会计数。这里做去重，
     * 但**刻意不用会话**去重——这一点很关键：
     *
     * 写会话会触发 PHP 下发 Set-Cookie，响应随即被标记为「因人而异」，
     * Cloudflare 之类的边缘节点就不再缓存它，于是每次翻页都要跨国回源。
     * 浏览量统计这种可有可无的功能，不值得让全站页面失去缓存资格。
     *
     * 改用 Cookie 去重。注意这里**只累加到内存**，真正的写入推迟到
     * flushViewCookie()，在请求末尾统一写一次：一个知识库列表页会渲染
     * 十几条条目，如果每条都 setcookie，就会发出十几个同名 Cookie，
     * 只有最后一个生效（且响应头被灌满垃圾）。
     *
     * 代价是用户清掉 Cookie 后会重复计数——对「浏览量」这个指标完全可以接受。
     */
    public function recordView(int $faqId): void
    {
        if ($faqId <= 0) {
            return;
        }
        if ($this->viewSeen === null) {
            $this->viewSeen = $this->readViewCookie();
        }

        $now = time();
        if (isset($this->viewSeen[$faqId]) && $now - (int)$this->viewSeen[$faqId] < self::VIEW_DEDUPE_SECONDS) {
            return;
        }
        $this->viewSeen[$faqId] = $now;

        $this->db->query('UPDATE `faq` SET `views` = `views` + 1 WHERE `id` = ?', [$faqId]);
    }

    /**
     * 把本次请求累计的浏览记录写回 Cookie。
     *
     * 由 Application 在控制器执行完之后调用一次，这样：
     *   - 每个响应最多一个 Set-Cookie；
     *   - 此时缓存的判断已经做出，可以直接采信。
     *
     * @param bool $cacheable 本次响应是否将被边缘缓存
     */
    public function flushViewCookie(bool $cacheable = false): void
    {
        if ($this->viewSeen === null || $this->viewSeen === []) {
            return;
        }

        // 可被边缘缓存的页面不设这个 Cookie。
        //
        // 原因：带 Set-Cookie 的响应即使声明了 s-maxage，CDN 通常也会拒绝缓存它
        // （Cloudflare 就是如此）。浏览量去重只是「少算几次」的小事，不值得让
        // 公开页面失去缓存、把「每次翻页跨国回源」的代价重新加回来。
        // 缓存命中时源站根本不会被访问，本来也就不会记这一次浏览。
        if ($cacheable) {
            return;
        }

        $now = time();

        // 只保留未过期的项，否则长期使用后这个 Cookie 会无限增长
        //（超过约 4KB 会被浏览器直接丢弃）
        $fresh = [];
        foreach ($this->viewSeen as $id => $ts) {
            if ($now - (int)$ts < self::VIEW_DEDUPE_SECONDS) {
                $fresh[$id] = $ts;
            }
        }
        if (count($fresh) > self::VIEW_MEMORY_MAX) {
            $fresh = array_slice($fresh, -self::VIEW_MEMORY_MAX, null, true);
        }

        $parts = [];
        foreach ($fresh as $id => $ts) {
            $parts[] = $id . ':' . $ts;
        }
        $value = implode(',', $parts);

        if (headers_sent() || strlen($value) > 3500) {
            return;
        }
        setcookie(self::VIEW_COOKIE, $value, [
            'expires' => $now + self::VIEW_DEDUPE_SECONDS,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 只在确实是 HTTPS 时加 Secure。
            //
            // 不能用 `!empty($_SERVER['HTTPS'])`：这个变量的约定是「开启了就非空」，
            // 但实际部署里有服务器把它设成字符串 "off"（IIS 以及不少 nginx/FPM
            // 配置都会），此时 !empty 为真，于是给纯 HTTP 站点发了一个带 Secure 的
            // Cookie——浏览器直接丢弃，去重永远失效，浏览量退化成每次刷新都 +1。
            'secure' => $this->isHttps(),
        ]);
    }

    /** 当前请求是否走 HTTPS（同时认代理转发头） */
    private function isHttps(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1' || $https === 'true') {
            return true;
        }
        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https';
    }

    /** @return array<int,int> 条目 id => 上次浏览时间戳 */
    private function readViewCookie(): array
    {
        $raw = (string)($_COOKIE[self::VIEW_COOKIE] ?? '');
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $bits = explode(':', $part, 2);
            if (count($bits) !== 2 || !ctype_digit($bits[0]) || !ctype_digit($bits[1])) {
                continue;
            }
            $out[(int)$bits[0]] = (int)$bits[1];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // 有用 / 没用 投票
    // ---------------------------------------------------------------

    /**
     * 投票。
     *
     * v1 的投票接口读的是 $_GET['id']，而唯一的调用方把它放在 POST 里，
     * 于是 id 恒为 0，投票功能 100% 失效——而且它是静默失效：页面照常
     * 跳转回去，只是数字永远不变。
     *
     * 这里从参数直接取值，并把「已投过」明确返回给页面提示，
     * 而不是悄悄什么都不做。
     *
     * @return array{ok:bool,message:string}
     */
    public function vote(int $faqId, bool $helpful): array
    {
        if ($faqId <= 0) {
            return ['ok' => false, 'message' => '参数不正确'];
        }

        $voted = $this->session->get('faq_votes', []);
        if (!is_array($voted)) {
            $voted = [];
        }
        if (isset($voted[$faqId])) {
            return ['ok' => false, 'message' => '你已经评价过这条内容了'];
        }

        $exists = $this->db->int('SELECT COUNT(*) FROM `faq` WHERE `id` = ? AND `status` = 1', [$faqId]);
        if ($exists === 0) {
            return ['ok' => false, 'message' => '内容不存在'];
        }

        $column = $helpful ? 'helpful' : 'unhelpful';
        $this->db->query('UPDATE `faq` SET `' . $column . '` = `' . $column . '` + 1 WHERE `id` = ?', [$faqId]);

        $voted[$faqId] = $helpful ? 1 : 0;
        $this->session->set('faq_votes', $voted);

        return ['ok' => true, 'message' => $helpful ? '感谢反馈，很高兴帮到你' : '感谢反馈，我们会继续改进'];
    }

    /** 当前会话是否已对某条投过票，用于页面禁用按钮 */
    public function hasVoted(int $faqId): ?bool
    {
        $voted = $this->session->get('faq_votes', []);
        if (!is_array($voted) || !isset($voted[$faqId])) {
            return null;
        }
        return (bool)$voted[$faqId];
    }

    // ---------------------------------------------------------------
    // 分类
    // ---------------------------------------------------------------

    /**
     * 分类列表。
     *
     * @param bool $forTicket 只取可用于工单的分类
     * @param bool $forFaq    只取可用于知识库的分类
     * @return list<array<string,mixed>>
     */
    public function categories(bool $forTicket = false, bool $forFaq = false, bool $onlyEnabled = true): array
    {
        $where = [];
        $params = [];
        if ($onlyEnabled) {
            $where[] = '`status` = 1';
        }
        if ($forTicket) {
            $where[] = '`is_ticket` = 1';
        }
        if ($forFaq) {
            $where[] = '`is_faq` = 1';
        }
        $sql = 'SELECT * FROM `category`'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY `sort` ASC, `id` ASC';
        return $this->db->all($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public function findCategory(int $id): ?array
    {
        return $id > 0 ? $this->db->row('SELECT * FROM `category` WHERE `id` = ?', [$id]) : null;
    }

    /**
     * 保存分类（新增或更新）。
     *
     * 颜色与图标在这里就做白名单化处理。v1 把 color 原样存进库，再直接
     * 拼进 style="background:..." —— 五个后台角色里任何一个都能存一段
     * CSS，进而影响超管面板和整个前台页面。这不构成脚本执行，但属于
     * 存储型样式注入，代价很低就该堵掉。
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function saveCategory(array $data, int $id = 0): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if (mb_strlen($name, 'UTF-8') < 1 || mb_strlen($name, 'UTF-8') > 50) {
            return ['ok' => false, 'error' => '分类名称需为 1–50 个字符'];
        }

        $icon = $this->normalizeIcon((string)($data['icon'] ?? ''));
        $color = $this->normalizeColor((string)($data['color'] ?? ''));
        $description = Str::limit(trim((string)($data['description'] ?? '')), 255, '');
        $slug = Str::limit(trim((string)($data['slug'] ?? '')), 60, '');
        $sort = (int)($data['sort'] ?? 0);
        $isTicket = !empty($data['is_ticket']) ? 1 : 0;
        $isFaq = !empty($data['is_faq']) ? 1 : 0;
        $status = isset($data['status']) && !$data['status'] ? 0 : 1;

        // 两个开关都不勾的分类在前台完全不可见，属于配置错误，直接拒绝
        if ($isTicket === 0 && $isFaq === 0) {
            return ['ok' => false, 'error' => '分类至少要用于「工单分类」或「知识库分类」其中之一'];
        }
        if ($slug === '') {
            $slug = Str::slug($name);
            // 中文名 slug 化后会变成空串，用一个稳定回退值，避免出现空 slug
            if ($slug === '') {
                $slug = 'cat-' . ($id > 0 ? $id : substr(Str::randomHex(4), 0, 6));
            }
        }

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'icon' => $icon,
            'color' => $color,
            'description' => $description,
            'is_ticket' => $isTicket,
            'is_faq' => $isFaq,
            'sort' => $sort,
            'status' => $status,
        ];

        if ($id > 0) {
            $this->db->query(
                'UPDATE `category` SET `name`=?, `slug`=?, `icon`=?, `color`=?, `description`=?,
                    `is_ticket`=?, `is_faq`=?, `sort`=?, `status`=? WHERE `id`=?',
                [...array_values($payload), $id]
            );
            return ['ok' => true, 'id' => $id];
        }

        $newId = $this->db->insert(
            'INSERT INTO `category` (`name`,`slug`,`icon`,`color`,`description`,`is_ticket`,`is_faq`,`sort`,`status`)
             VALUES (?,?,?,?,?,?,?,?,?)',
            array_values($payload)
        );
        return ['ok' => true, 'id' => $newId];
    }

    /**
     * 删除分类。
     *
     * 拒绝删除仍被引用的分类：v1 直接删掉，留下 category_id 指向不存在行的
     * 工单与 FAQ，页面上显示为空白分类。这里明确告知引用数量，
     * 让管理员先把内容挪走——比静默产生脏数据好得多。
     *
     * @return array{ok:bool,error?:string}
     */
    public function deleteCategory(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'error' => '分类不存在'];
        }
        $tickets = $this->db->int('SELECT COUNT(*) FROM `ticket` WHERE `category_id` = ?', [$id]);
        $faqs = $this->db->int('SELECT COUNT(*) FROM `faq` WHERE `category_id` = ?', [$id]);
        if ($tickets > 0 || $faqs > 0) {
            return [
                'ok' => false,
                'error' => sprintf('该分类下还有 %d 个工单、%d 条知识库内容，请先转移后再删除。', $tickets, $faqs),
            ];
        }
        $this->db->query('DELETE FROM `category` WHERE `id` = ?', [$id]);
        return ['ok' => true];
    }

    /** 颜色只接受 #rgb / #rrggbb，其它一律回退到品牌色 */
    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1) {
            return strtolower($color);
        }
        return '#4f46e5';
    }

    /**
     * 图标名白名单。
     *
     * 原来是「接受一个 emoji 或一两个字符」的自由文本。改成白名单有三个好处：
     *  - 图标渲染走同一套线性 SVG，线宽与风格不会漂移；
     *  - 颜色统一由分类的主题色令牌控制，深色模式下自动调色；
     *  - 数据库里不可能再出现任意字符串（这也是一个直接输出到页面的位置）。
     *
     * 白名单必须与 Support\icons.php 里的图标集合保持一致。
     */
    private const ICON_WHITELIST = [
        'ticket', 'chat', 'book', 'send', 'search', 'check', 'check-circle',
        'warn', 'info', 'bang', 'clock', 'bolt', 'bell', 'shield', 'lock',
        'folder', 'user', 'users', 'mail', 'chart', 'list', 'gear',
        'clipboard', 'key', 'clip', 'file', 'image', 'download', 'plus',
        'close', 'arrow-right', 'external', 'refresh', 'trash', 'sun',
        'moon', 'logout', 'inbox', 'compass', 'star', 'rocket', 'gamepad',
        'diamond', 'account', 'server', 'help', 'palette', 'wrench',
    ];

    /**
     * 归一化图标名：不在白名单内的一律回退到 folder。
     *
     * 不回退成「空」而是回退成 folder，是为了避免页面上出现一个渲染不出来的
     * 空白图标——一个空方块比一个通用图标更难解释。
     */
    private function normalizeIcon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        return in_array($icon, self::ICON_WHITELIST, true) ? $icon : 'folder';
    }

    // ---------------------------------------------------------------
    // 后台维护
    // ---------------------------------------------------------------

    /**
     * 保存 FAQ。
     *
     * 答案以纯文本 + 换行存储，渲染时统一转义再处理换行。
     * v1 允许后台存任意 HTML，再用一个正则白名单去清洗，
     * 而那个清洗器挡不住实体编码的 javascript: 协议——一条被存进来的
     * <a href="java&#115;cript:..."> 就能在知识库页面上形成点击 XSS，
     * 且任何后台角色都能写。放弃「存 HTML」这条路之后，这个攻击面整类消失。
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,error?:string,id?:int}
     */
    public function saveFaq(array $data, int $id = 0): array
    {
        $question = trim((string)($data['question'] ?? ''));
        $answer = trim((string)($data['answer'] ?? ''));
        $keywords = trim((string)($data['keywords'] ?? ''));

        if (mb_strlen($question, 'UTF-8') < 2 || mb_strlen($question, 'UTF-8') > 255) {
            return ['ok' => false, 'error' => '问题需为 2–255 个字符'];
        }
        if (mb_strlen($answer, 'UTF-8') < 2) {
            return ['ok' => false, 'error' => '解答内容不能为空'];
        }
        if (mb_strlen($answer, 'UTF-8') > 60000) {
            return ['ok' => false, 'error' => '解答内容过长（上限 60000 字）'];
        }
        // 关键词列是 VARCHAR(500)，服务端必须自己卡长度：
        // v1 只靠表单 maxlength，构造请求就能触发 1406 错误页面
        if (mb_strlen($keywords, 'UTF-8') > 500) {
            return ['ok' => false, 'error' => '关键词不能超过 500 个字符'];
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        if ($categoryId > 0 && $this->findCategory($categoryId) === null) {
            return ['ok' => false, 'error' => '所选分类不存在'];
        }

        $payload = [
            'category_id' => $categoryId,
            'question' => $question,
            'answer' => $answer,
            'keywords' => $this->normalizeKeywords($keywords, $question),
            'is_hot' => !empty($data['is_hot']) ? 1 : 0,
            'is_top' => !empty($data['is_top']) ? 1 : 0,
            'sort' => (int)($data['sort'] ?? 0),
            'status' => !empty($data['status']) ? 1 : 0,
        ];

        if ($id > 0) {
            $this->db->query(
                'UPDATE `faq` SET `category_id`=?, `question`=?, `answer`=?, `keywords`=?,
                    `is_hot`=?, `is_top`=?, `sort`=?, `status`=? WHERE `id`=?',
                [...array_values($payload), $id]
            );
            return ['ok' => true, 'id' => $id];
        }

        $newId = $this->db->insert(
            'INSERT INTO `faq` (`category_id`,`question`,`answer`,`keywords`,`is_hot`,`is_top`,`sort`,`status`)
             VALUES (?,?,?,?,?,?,?,?)',
            array_values($payload)
        );
        return ['ok' => true, 'id' => $newId];
    }

    /**
     * 关键词补全：管理员没填关键词时，从问题里自动提取几段，
     * 让新条目一建好就能被搜到。v1 的做法是留空，结果是「内容在库里但搜不到」。
     */
    private function normalizeKeywords(string $keywords, string $question): string
    {
        $keywords = preg_replace('/\s+/u', ' ', $keywords) ?? $keywords;
        if ($keywords !== '') {
            return Str::limit($keywords, 500, '');
        }

        // 没有关键词：取问题里的中文片段与英文词作为候选
        $candidates = [];
        if (preg_match_all('/[\x{4e00}-\x{9fa5}]{2,6}/u', $question, $m) > 0) {
            $candidates = array_merge($candidates, $m[0]);
        }
        if (preg_match_all('/[A-Za-z][A-Za-z0-9_\/]{1,20}/', $question, $m2) > 0) {
            $candidates = array_merge($candidates, $m2[0]);
        }
        $candidates = array_values(array_unique(array_filter($candidates, static fn(string $s): bool => $s !== '')));
        return Str::limit(implode(' ', array_slice($candidates, 0, 12)), 500, '');
    }

    public function deleteFaq(int $id): void
    {
        $this->db->query('DELETE FROM `faq` WHERE `id` = ?', [$id]);
    }

    /** 切换发布状态，返回切换后的状态；0 表示记录不存在 */
    public function toggleFaqStatus(int $id): int
    {
        $current = $this->db->value('SELECT `status` FROM `faq` WHERE `id` = ?', [$id]);
        if ($current === null) {
            return -1;
        }
        $next = ((int)$current) === 1 ? 0 : 1;
        $this->db->query('UPDATE `faq` SET `status` = ? WHERE `id` = ?', [$next, $id]);
        return $next;
    }

    /** 批量设置发布状态 */
    public function setFaqStatus(array $ids, int $status): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->affected(
            'UPDATE `faq` SET `status` = ? WHERE `id` IN (' . $placeholders . ')',
            [$status, ...$ids]
        );
    }

    public function deleteFaqs(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->affected('DELETE FROM `faq` WHERE `id` IN (' . $placeholders . ')', $ids);
    }

    /**
     * 后台分类统计（每个分类下有多少工单与 FAQ）。
     *
     * @return array<int,array{tickets:int,faqs:int}>
     */
    public function categoryUsage(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT `category_id`, COUNT(*) AS n FROM `faq` GROUP BY `category_id`') as $r) {
            $cid = (int)$r['category_id'];
            $out[$cid]['faqs'] = (int)$r['n'];
        }
        foreach ($this->db->all('SELECT `category_id`, COUNT(*) AS n FROM `ticket` GROUP BY `category_id`') as $r) {
            $cid = (int)$r['category_id'];
            $out[$cid]['tickets'] = (int)$r['n'];
        }
        foreach ($out as $cid => $v) {
            $out[$cid] = [
                'tickets' => $v['tickets'] ?? 0,
                'faqs' => $v['faqs'] ?? 0,
            ];
        }
        return $out;
    }
}
