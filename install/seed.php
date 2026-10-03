<?php
/**
 * 初始种子数据：分类 + FAQ + 系统设置
 * 内容依据 koishi-plugin-robloxsearch 插件文档整理
 */
declare(strict_types=1);

return [

// ============ 分类 ============
'categories' => [
    ['name' => '快速入门', 'slug' => 'start',     'icon' => '🚀', 'color' => '#6366f1', 'description' => '首次使用机器人必看的入门指引', 'sort' => 10],
    ['name' => '功能使用', 'slug' => 'usage',     'icon' => '🎮', 'color' => '#0ea5e9', 'description' => '各类查询命令的用法与参数说明', 'sort' => 20],
    ['name' => '积分与等级', 'slug' => 'points',   'icon' => '💎', 'color' => '#f59e0b', 'description' => 'R 点、经验、签到、兑换码相关问题', 'sort' => 30],
    ['name' => '账号与权限', 'slug' => 'account', 'icon' => '🔐', 'color' => '#8b5cf6', 'description' => '账号绑定、管理员权限、赞助名单', 'sort' => 40],
    ['name' => '风控与审核', 'slug' => 'risk',     'icon' => '🛡️', 'color' => '#ef4444', 'description' => '封禁、违规库、审核流程说明', 'sort' => 50],
    ['name' => '部署与运维', 'slug' => 'deploy',   'icon' => '🖥️', 'color' => '#10b981', 'description' => '安装配置、图床代理、服务器状态', 'sort' => 60],
    ['name' => '常见报错', 'slug' => 'error',    'icon' => '❓', 'color' => '#64748b', 'description' => '指令无响应、图片不显示等异常排查', 'sort' => 70],
],

// ============ FAQ ============
'faqs' => [
    // ---- 快速入门 ----
    [
        'cat' => 'start', 'hot' => 1, 'top' => 1,
        'q' => '机器人怎么用？第一次使用需要做什么？',
        'a' => "在聊天窗口直接发送 <code>/菜单</code> 即可打开服务中心菜单，从菜单中选择需要的功能即可。\n\n首次使用建议先完成以下两步：\n1. 发送 <code>/绑定Roblox账号 你的用户ID</code>，把 QQ 账号与 Roblox 账号绑定，这样查询、积分、兑换码等功能都会归属于你；\n2. 每日发送 <code>/签到</code> 领取 R 点与经验，积分可用于兑换经验。\n\n所有命令都支持简写，例如 <code>/菜单</code> 等同于 <code>/roblox/菜单</code>。",
        'kw' => '入门 新手 怎么用 菜单 第一次',
    ],
    [
        'cat' => 'start', 'hot' => 1, 'top' => 1,
        'q' => '命令前面的斜杠「/」是必须的吗？打完字机器人没反应？',
        'a' => "是的，命令必须以英文斜杠 <code>/</code> 开头，否则机器人不会识别。\n\n常见错误：\n- 用中文「／」全角斜杠 → 请切换为英文「/」\n- 前后带了多余空格 → 请删除空格\n- 漏掉参数 → 例如 <code>/游戏ID搜索</code> 必须带 Place ID\n\n若确认格式无误仍无响应，可尝试发送 <code>/roblox/菜单</code> 完整命令，排除简写被其他插件拦截的可能。",
        'kw' => '斜杠 命令 无反应 没反应 不回复',
    ],
    [
        'cat' => 'start',
        'q' => '如何让查询结果自动翻译成中文？',
        'a' => "默认已开启自动翻译（配置项 <code>useTranslate</code>，默认为 true）。\n\n如果查询结果仍是英文，可以发送：\n- <code>/开启翻译</code> 手动打开\n- <code>/关闭翻译</code> 手动关闭\n\n部分游戏简介内容较长时翻译需要几秒钟，请耐心等待。若长时间无结果，多半是上游 API 请求超时，可稍后重试。",
        'kw' => '翻译 英文 中文 翻译开关',
    ],
    [
        'cat' => 'start',
        'q' => '查询太频繁被限制了怎么办？',
        'a' => "为保证服务稳定，插件默认开启了限流：\n- 每分钟最多 <strong>20</strong> 次查询\n- 每小时最多 <strong>200</strong> 次查询\n\n超出后会收到限流提示。等待 1 分钟或 1 小时后自动恢复。建议一次查询只发一条命令，不要连续刷屏。\n\n如果限流过于严格影响正常使用，可联系管理员在插件配置中调整 <code>rateLimitPerMin</code> 与 <code>rateLimitPerHour</code>。",
        'kw' => '限流 频繁 太多 被限制 rate limit',
    ],

    // ---- 功能使用 ----
    [
        'cat' => 'usage', 'hot' => 1, 'top' => 1,
        'q' => '如何查询 Roblox 玩家信息？',
        'a' => "支持两种方式：\n\n<strong>按用户名：</strong>\n<code>/用户名搜索 玩家名</code>\n\n<strong>按用户 ID：</strong>\n<code>/用户ID搜索 12345678</code>\n\n查询结果包含：头像、虚拟形象、个人简介、好友 / 关注 / 粉丝列表、曾用名等。\n\n相关命令：\n- <code>/获取用户头像 玩家名</code> 只看头像\n- <code>/查询用户曾用名 玩家名</code> 查曾用名\n- <code>/获取好友列表 用户ID</code>\n- <code>/获取关注列表 用户ID</code>\n- <code>/获取粉丝列表 用户ID</code>",
        'kw' => '玩家 查用户 用户名 用户ID 头像 好友 粉丝',
    ],
    [
        'cat' => 'usage', 'hot' => 1,
        'q' => '如何搜索游戏和游戏服务器？',
        'a' => "<strong>搜索游戏：</strong>\n- <code>/游戏名搜索 关键词</code> 按名称模糊搜索\n- <code>/游戏名精确搜索 完整名称</code> 精确匹配，结果更准\n- <code>/游戏ID搜索 PlaceID</code> 按 Place ID 查询\n\n<strong>公开服务器搜索：</strong>\n在游戏查询结果中可进入「服务器」页签，按 Place ID 过滤并对公开服务器排序、分页浏览。\n\n商店物品（Asset / Bundle）搜索同样通过查询结果的按钮进入，可按作者、价格、类别、类型过滤，支持 Limited 收藏品与价格趋势图。",
        'kw' => '游戏 搜索 服务器 place id 游戏名 商店 物品',
    ],
    [
        'cat' => 'usage',
        'q' => '如何查询群组信息？',
        'a' => "<strong>按群组名：</strong>\n<code>/群组名搜索 群组名称</code>\n\n<strong>按群组 ID：</strong>\n<code>/群组ID搜索 群组ID</code>\n\n<strong>获取群组图标：</strong>\n<code>/获取群组图标 群组名称</code>\n\n查询结果会展示群组图标及相关信息。",
        'kw' => '群组 group 群组名 群组id 图标',
    ],
    [
        'cat' => 'usage',
        'q' => '所有查询命令一览表',
        'a' => "所有命令均以父命名空间 <code>roblox/</code> 注册，可用完整路径或短名调用。\n\n<strong>查询类</strong>\n<code>/菜单</code> <code>/用户名搜索</code> <code>/用户ID搜索</code> <code>/查询用户曾用名</code> <code>/获取用户头像</code> <code>/游戏名搜索</code> <code>/游戏名精确搜索</code> <code>/游戏ID搜索</code> <code>/群组名搜索</code> <code>/群组ID搜索</code> <code>/获取群组图标</code> <code>/获取好友列表</code> <code>/获取关注列表</code> <code>/获取粉丝列表</code>\n\n<strong>账号绑定</strong>\n<code>/绑定Roblox账号</code> <code>/查看绑定</code> <code>/解除绑定</code> <code>/绑定统计</code>\n\n<strong>积分体系</strong>\n<code>/签到</code> <code>/积分</code> <code>/等级</code> <code>/积分排行榜</code> <code>/流水</code> <code>/兑换经验</code>\n\n<strong>兑换码</strong>\n<code>/兑换码</code> <code>/查询兑换码</code>\n\n<strong>其他</strong>\n<code>/随机TOP音乐ID</code> <code>/开启翻译</code> <code>/关闭翻译</code> <code>/我的信息</code> <code>/ROBLOX社群列表</code> <code>/社群推荐</code> <code>/报名推荐社群</code> <code>/刷新社群列表</code>",
        'kw' => '命令 一览 列表 全部命令 合集 命令表',
    ],
    [
        'cat' => 'usage',
        'q' => '搜索结果里的按钮（上一页 / 下一页）怎么用？',
        'a' => "商店物品搜索、Limited 搜索、游戏服务器搜索、物品价格趋势等命令属于<strong>交互式搜索</strong>，机器人会返回内联按钮。\n\n直接在 QQ 中点击按钮即可翻页或切换筛选条件，无需重新输入命令。按钮下方会显示当前页码与总数。\n\n这类命令不支持用文字直接翻页，请使用按钮操作。",
        'kw' => '按钮 翻页 下一页 上一页 内联键盘',
    ],
    [
        'cat' => 'usage',
        'q' => '怎么查看和报名推荐社群？',
        'a' => "需先在插件配置中开启社群推荐（<code>useCommunityList</code>，默认关闭）。\n\n开启后可使用：\n- <code>/ROBLOX社群列表</code> 查看社群列表\n- <code>/社群推荐</code> 获取社群推荐\n- <code>/报名推荐社群</code> 报名\n- <code>/刷新社群列表</code> 重新拉取\n\n社群数据来自 JSON 文件，默认路径 <code>data/communities.json</code>，也可通过 <code>communityDataPath</code> 自定义。",
        'kw' => '社群 社群列表 推荐 报名 communities',
    ],
    [
        'cat' => 'usage',
        'q' => '怎么获取随机 TOP 音乐 ID？',
        'a' => "发送 <code>/随机TOP音乐ID</code>，机器人会随机返回一条 TOP 音乐 ID。该功能无需额外配置，适合做社群互动或抽奖用途。",
        'kw' => '音乐 随机 top 音乐id',
    ],

    // ---- 积分与等级 ----
    [
        'cat' => 'points', 'hot' => 1, 'top' => 1,
        'q' => '积分（R 点）和经验（等级）有什么区别？',
        'a' => "<strong>R 点（积分）</strong>：可通过签到、兑换码、管理员发放获得，用于<strong>兑换经验</strong>。\n\n<strong>经验（等级）</strong>：用于提升等级，等级越高身份标识越明显。\n\n<strong>获取途径：</strong>\n- 每日签到：获得 10~20 R 点（区间可配置）+ 5 经验\n- 日常查询：每日前 <strong>5</strong> 次查询计经验（超出不计）\n- 兑换码兑换\n- 管理员发放\n\n<strong>兑换比例：</strong> 默认 <strong>5 R 点 = 1 经验</strong>（<code>pointsPerExp</code>）。\n发送 <code>/兑换经验 数量</code> 即可兑换，例如 <code>/兑换经验 10</code> 需 50 R 点。\n\n<strong>升级奖励：</strong> 每提升 1 级奖励 <strong>100 R 点</strong>。",
        'kw' => '积分 r点 经验 等级 区别 兑换 签到',
    ],
    [
        'cat' => 'points', 'hot' => 1,
        'q' => '每日签到怎么获得积分？',
        'a' => "发送 <code>/签到</code> 即可完成每日签到。\n\n签到可获得：\n- <strong>10 ~ 20 R 点</strong>（区间由配置 <code>signinGetMin</code> / <code>signinGetMax</code> 决定）\n- <strong>5 经验</strong>\n\n签到功能需在插件配置中保持开启（<code>useSignin</code>，默认开启）。每天只能签到一次，重复发送会提示今日已签到。\n\n签到后可用 <code>/积分</code> 查看当前积分，<code>/等级</code> 查看当前等级。",
        'kw' => '签到 每日 积分 领积分 签到奖励',
    ],
    [
        'cat' => 'points',
        'q' => '积分排行榜、流水怎么看？',
        'a' => "<strong>积分排行榜：</strong> 发送 <code>/积分排行榜</code> 查看 R 点排行。\n\n<strong>积分流水：</strong> 发送 <code>/流水</code> 查看全部流水记录；发送 <code>/流水 ledger</code> 可指定账本类型查看分类流水。\n\n<strong>我的积分：</strong> 发送 <code>/积分</code>；等级用 <code>/等级</code>。\n\n排行榜数据基于已绑定账号的用户，未绑定 QQ 账号的用户不参与排行统计。",
        'kw' => '排行榜 流水 ledger 排行 积分榜',
    ],
    [
        'cat' => 'points',
        'q' => '兑换码怎么使用？兑换码从哪来？',
        'a' => "<strong>使用兑换码：</strong> 发送 <code>/兑换码 兑换码字符串</code>，成功后自动发放对应数量的 R 点。\n\n<strong>查询兑换码：</strong> 发送 <code>/查询兑换码 兑换码字符串</code> 可查看该码是否存在、剩余数量与有效期。\n\n<strong>兑换码来源：</strong> 由管理员在后台通过 <code>/添加兑换码</code> 创建，参数为：\n<code>/添加兑换码 兑换码 R点数量 总数量 有效天数 备注</code>\n\n活动、公告或社群中会不定期发放，请留意。兑换码通常有数量与有效期限制，过期或已领完则无法使用。",
        'kw' => '兑换码 giftcode 兑换 使用 领取',
    ],
    [
        'cat' => 'points',
        'q' => '为什么我的查询没有增加经验？',
        'a' => "每日计经验的查询次数上限为 <strong>5 次</strong>（配置 <code>dailyQueryExpCap</code>），第 6 次起不再计经验。\n\n另外需满足：\n- 已开启经验体系（<code>useExpSystem</code>，默认开启）\n- 已绑定 QQ / Roblox 账号\n- 查询成功返回结果（失败、超时的查询不计经验）\n\n如果你当天查询已超过 5 次，可以次日再试，或使用 <code>/兑换经验</code> 用 R 点直接兑换经验。",
        'kw' => '经验 不增加 没经验 上限 5次 查询经验',
    ],
    [
        'cat' => 'points',
        'q' => '每天兑换经验有上限吗？',
        'a' => "有。<code>dailyConvertExpCap</code> 控制每日兑换经验的次数上限，<strong>默认 0 表示不限制</strong>。\n\n如果管理员设置了上限（例如 5 次），当天超出后 <code>/兑换经验</code> 会提示已达上限，次日 0 点重置。\n\n注意：签到与查询获得的经验不受此限制，该限制仅针对 R 点兑换。",
        'kw' => '兑换经验 上限 每天 限制 dailyConvertExpCap',
    ],

    // ---- 账号与权限 ----
    [
        'cat' => 'account', 'hot' => 1,
        'q' => '如何绑定、解绑 QQ 与 Roblox 账号？',
        'a' => "<strong>绑定：</strong> 发送 <code>/绑定Roblox账号 你的Roblox用户ID</code>，绑定后积分、等级、兑换码都会归属到该账号。\n\n<strong>查看绑定：</strong> 发送 <code>/查看绑定</code>\n\n<strong>解绑：</strong> 发送 <code>/解除绑定</code>\n\n<strong>绑定统计：</strong> 发送 <code>/绑定统计</code>\n\n<strong>注意：</strong> 绑定后该 Roblox 账号的积分与等级将跟随绑定关系转移，请勿随意解绑后重新绑定到其他 QQ。\n\n查看自己的 ID 可通过 <code>/我的信息</code> 获取。",
        'kw' => '绑定 解绑 roblox账号 qq 绑定统计',
    ],
    [
        'cat' => 'account',
        'q' => '机器人有哪些权限等级？我是什么级别？',
        'a' => "插件共设 <strong>4 级权限</strong>：\n\n1. <strong>开发者</strong>（<code>developerList</code>）— 最高权限，可增减 R 点 / 经验、设定等级\n2. <strong>管理员</strong>（<code>adminList</code>）— 权限管理、风控、违规库、兑换码添加\n3. <strong>协助管理员</strong>（<code>assistAdminList</code>）— 审核处理与风控等级调整\n4. <strong>赞助用户</strong>（<code>sponsorList</code>）— 身份外显，属于荣誉标识，不含管理权限\n\n查询自己的权限与个人信息请发送 <code>/我的信息</code>。\n\n如需提升权限，请通过本页「提交工单」联系我们，说明你的 QQ 号与使用场景。",
        'kw' => '权限 等级 管理员 开发者 赞助 身份',
    ],
    [
        'cat' => 'account',
        'q' => '我的信息在哪里看？',
        'a' => "发送 <code>/我的信息</code> 即可查看当前账号的绑定情况、积分、等级、权限身份等信息汇总。\n\n也可分别使用：\n- <code>/积分</code> — 当前 R 点\n- <code>/等级</code> — 当前等级与经验\n- <code>/查看绑定</code> — 绑定信息",
        'kw' => '我的信息 个人信息 查看信息',
    ],
    [
        'cat' => 'account',
        'q' => '被禁止查找某个用户 ID 是什么意思？',
        'a' => "这是管理员设置的<strong>屏蔽名单</strong>。配置 <code>delGetUserIdList</code> 中的用户 ID 将无法被任何人查询，通常用于保护隐私或防止骚扰。\n\n同样地，<code>foreverBanList</code> 是<strong>永久禁止使用</strong>名单，名单内的用户无法使用任何机器人功能。\n\n如果你认为自己被误封或被误屏蔽，请通过本页提交工单联系我们，并提供你的 QQ 号、Roblox 用户 ID 与相关截图，我们会人工核实处理。",
        'kw' => '被禁止 屏蔽 delGetUserIdList 黑名单 无法查询 误封',
    ],

    // ---- 风控与审核 ----
    [
        'cat' => 'risk', 'hot' => 1,
        'q' => '我被风控/封禁了怎么办？',
        'a' => "机器人内置风控系统（<code>useRiskControl</code>，默认开启），用于拦截违规内容与恶意刷屏。\n\n<strong>常见限制类型：</strong>\n- 短期风控限制 — 触发了敏感词或高频操作\n- 长期风控（永封）— 多次严重违规\n\n<strong>处理方式：</strong>\n1. 先阅读封禁时收到的提示文案（<code>banMsg</code> 可自定义）；\n2. 确认自己是否确实发送了违规内容；\n3. 通过本页「提交工单」申诉，注明：QQ 号、Roblox 用户 ID、被限制时间、触发时的提示截图。\n\n管理员核实后可用 <code>/解除风控</code> 或 <code>/解除风控限制</code> 解封，也可用 <code>/调整风控等级</code> 调整限制级别与天数。",
        'kw' => '封禁 永封 风控 限制 违规 申诉 解封',
    ],
    [
        'cat' => 'risk',
        'q' => '什么内容会触发风控？',
        'a' => "以下行为最容易被风控拦截：\n\n<strong>文本层面：</strong>\n- 触发敏感词库（可配置 <code>riskKeywords</code> 补充词库）\n- 大量刷屏、连续高频发送命令\n\n<strong>图片层面：</strong>\n- 图片审核（<code>useImageAudit</code>）未通过，包含违规内容\n\n<strong>系统层面：</strong>\n- 使用未授权的自动化脚本、外挂工具批量调用接口\n- 恶意遍历、批量查询他人信息\n\n违规记录会进入<strong>违规库</strong>，默认有效期 <strong>365 天</strong>（<code>violationLibTTL</code>）。管理员可用 <code>/违规添加</code> / <code>/违规移除</code> 管理。\n\n请文明使用查询功能，共同维护机器人环境。",
        'kw' => '风控 敏感词 违规 触发 刷屏 图片审核',
    ],
    [
        'cat' => 'risk',
        'q' => '审核流程是怎样的？我提交的内容为什么需要审核？',
        'a' => "开启内容审核（<code>useTextAudit</code> / <code>useImageAudit</code>，默认均开启）后，机器人发送的部分内容会进入审核队列。\n\n<strong>审核通道（可任选其一或组合）：</strong>\n- UApiPro 敏感词检测（需配置 <code>textAuditToken</code>）\n- 免费内容审核（<code>useFreeExamine</code>）\n- 腾讯云不良内容审核（<code>isExamine</code>，需配置 SecretId / SecretKey / COS 存储桶）\n\n<strong>流程：</strong> 管理员发送 <code>/审核列表</code> 查看待审核项 → 使用 <code>/审核处理 审核ID 判定</code> 进行通过或拦截。\n\n如果你的正常发言被误判，请提交工单并附上原始内容截图。",
        'kw' => '审核 流程 审核列表 审核处理 敏感词检测 腾讯云',
    ],
    [
        'cat' => 'risk',
        'q' => '机器人会保存我的查询记录和操作日志吗？',
        'a' => "会。插件内置操作日志功能：\n\n- 发送 <code>/操作日志 数量</code> 可查看最近的操作记录\n- 管理员在控制台也可查看完整日志\n\n日志记录用于风控追溯与问题排查，属于正常运维需要。数据存储位置取决于配置：\n- <code>useDatabase</code> = false（默认）→ 本地文件存储在 <code>basePath</code> 指定目录（默认 <code>roblox</code>）\n- <code>useDatabase</code> = true → 存储于数据库\n\n如需清除本地数据，可使用 <code>/数据导出</code> 备份后再处理。",
        'kw' => '日志 操作日志 记录 隐私 数据存储',
    ],
    [
        'cat' => 'risk',
        'q' => '可以删除我的数据吗？',
        'a' => "可以。请通过本页「提交工单」联系我们，注明你的 QQ 号与需要删除的数据范围，我们会在核实后处理。\n\n<strong>请注意：</strong>\n数据删除后，你的积分、等级、绑定关系与历史工单将无法恢复。涉及违规库与风控记录的删除需满足平台规则，不能因个人请求随意清除。",
        'kw' => '删除数据 隐私 注销 清除记录',
    ],

    // ---- 部署与运维 ----
    [
        'cat' => 'deploy', 'hot' => 1,
        'q' => '插件安装了哪些必需服务？',
        'a' => "插件运行依赖以下 Koishi 服务，请确保已启用对应插件：\n\n<strong>必需：</strong>\n- <code>database</code> — 任意数据库插件（database-sqlite / database-mysql 等）\n- <code>localstorage</code> — koishi-plugin-smmcat-localstorage\n- <code>monetary</code> — koishi-plugin-monetary\n\n<strong>依赖版本：</strong>\n- koishi ^4.18.2（必装）\n- @koishijs/console ^5.30.4\n- koishi-plugin-monetary ^0.1.3\n- koishi-plugin-smmcat-localstorage ^0.0.2\n\n缺少任一必需服务会导致插件无法正常启动。",
        'kw' => '依赖 必需服务 安装 配置 koishi 启动失败',
    ],
    [
        'cat' => 'deploy',
        'q' => '怎么安装这个机器人插件？',
        'a' => "<strong>方式一：插件市场（推荐）</strong>\n在 Koishi 控制台的「插件市场」中搜索 <code>robloxsearch</code> 或 <code>roblox</code>，点击安装即可。\n\n<strong>方式二：npm</strong>\n<code>npm install koishi-plugin-robloxsearch</code>\n然后到 Koishi 控制台配置插件。\n\n<strong>方式三：源码安装</strong>\n<code>git clone https://github.com/maoyyds-cn/koishi-plugin-robloxsearch.git</code>\n将目录拷贝到 Koishi 的 plugins 目录，或在控制台手动添加本地插件。\n\n插件为纯 JavaScript 编译产物，<code>main</code> 指向 <code>lib/index.js</code>，无需二次构建。",
        'kw' => '安装 部署 插件市场 npm 源码 安装方法',
    ],
    [
        'cat' => 'deploy', 'hot' => 1, 'top' => 1,
        'q' => '图片显示不出来 / 加载失败怎么办？',
        'a' => "国内网络环境下 Roblox 官方 CDN 图片可能加载失败或触发防盗链。解决方案是部署<strong>图床代理服务</strong>。\n\n<strong>服务端接口：</strong>\n<code>POST /proxy/image</code>，请求体 <code>{ \"url\": \"原始图片URL\" }</code>\n成功返回 <code>{ \"code\": 0, \"localUrl\": \"...\" }</code>，失败返回 <code>{ \"code\": 1, \"error\": \"...\" }</code>。\n健康检查：<code>GET /healthz</code>\n\n<strong>部署后插件配置：</strong>\n- <code>imageProxyUrl</code> — 代理服务基础地址，如 <code>http://127.0.0.1:3682/</code>（<strong>不要</strong>带 <code>/proxy/image</code>，插件会自动拼接）\n- <code>imageProxyToken</code> — 若服务端设置了 <code>AUTH_TOKEN</code>，此处填相同 token；留空则不鉴权\n\n<strong>临时方案：</strong> 若暂不部署代理，可在插件配置中把 <code>imageProxyUrl</code> 留空（直连原图），并检查 <code>useProxyServer</code> 设置。\n\n如果仍有问题，请提交工单并附上出错截图。",
        'kw' => '图片 加载失败 显示不出来 图床 代理 proxy 防盗链',
    ],
    [
        'cat' => 'deploy',
        'q' => '网络访问相关配置怎么调？',
        'a' => "插件提供以下网络配置项：\n\n- <code>apiServer</code> — 自定义 API 服务器地址，<strong>留空使用官方 Roblox 接口</strong>\n- <code>useProxyServer</code> — 经 rotunnel.com 代理访问 Roblox API（关闭则直连），默认 true\n- <code>bffAccessToken</code> — BFF 后端 <code>x-bff-token</code> 鉴权，后端未启用鉴权时留空\n\n<strong>排查思路：</strong> 关闭代理仍无法查询 → 可能是本地网络无法直连 Roblox API，此时保持代理开启；查询超时 → 可尝试更换 <code>apiServer</code>。\n\n如需协助，请提交工单并说明具体报错。",
        'kw' => '网络 api 代理 apiserver 超时 配置',
    ],
    [
        'cat' => 'deploy',
        'q' => '如何迁移或备份机器人数据？',
        'a' => "插件提供完整的数据管理命令：\n\n- <code>/数据预览</code> — 查看当前数据概况\n- <code>/数据导出</code> — 导出全部数据用于备份\n- <code>/数据导入 文件</code> — 导入数据，加 <code>--overwrite</code> 可覆盖已有数据\n- <code>/迁移至数据库</code> — 将本地数据迁移到数据库（顶层命令，无需 roblox/ 前缀）\n\n<strong>建议：</strong> 重大配置变更前先执行 <code>/数据导出</code> 备份。本地数据默认存放在 <code>basePath</code> 指定目录（默认 <code>roblox</code>）。",
        'kw' => '数据 迁移 备份 导出 导入 迁移至数据库',
    ],
    [
        'cat' => 'deploy',
        'q' => '怎么查看机器人运行状态和公告？',
        'a' => "<strong>运行状态：</strong> 发送 <code>/服务器状态</code> 查看服务运行情况。\n\n<strong>公告：</strong> 公告系统默认开启（<code>useAnnouncement</code>）。管理员在 Koishi 控制台维护公告内容，公告会在群内/私聊中推送。\n\n<strong>全局小广告：</strong> 通过 <code>globalAdv</code> 配置，可设置全局小广告文案。\n\n如发现机器人响应异常，建议先自查：\n1. 发送 <code>/服务器状态</code>\n2. 确认是否触发限流\n3. 确认网络能正常访问（见网络配置 FAQ）\n4. 仍异常则提交工单",
        'kw' => '服务器状态 状态 公告 异常 无响应',
    ],
    [
        'cat' => 'deploy',
        'q' => '开启欢迎和公告功能后怎么用？',
        'a' => "<strong>进群欢迎：</strong> 配置 <code>useWelcome</code>（默认开启）后，新成员进群时机器人会自动发送欢迎语。\n\n<strong>公告系统：</strong> 配置 <code>useAnnouncement</code>（默认开启）后，管理员在控制台发布公告。\n\n<strong>内容审核：</strong> <code>useTextAudit</code>（文本）与 <code>useImageAudit</code>（图像）默认开启，用于过滤违规内容。\n\n<strong>调试模式：</strong> 遇到问题可临时开启 <code>deBug</code>，日志会输出更多信息，便于定位，但会增加日志量。",
        'kw' => '欢迎 公告 调试 debug 开启 配置说明',
    ],

    // ---- 常见报错 ----
    [
        'cat' => 'error', 'hot' => 1, 'top' => 1,
        'q' => '查询结果显示「用户不存在」或「未找到」',
        'a' => "可能原因：\n\n1. <strong>用户名拼写错误</strong> — Roblox 用户名区分大小写，请核对后重试\n2. <strong>用户已改名</strong> — 发送 <code>/查询用户曾用名 旧用户名</code> 查询历史名称\n3. <strong>ID 输入错误</strong> — 用户 ID 必须是纯数字\n4. <strong>API 临时异常</strong> — 稍等片刻重试\n\n若确认名称无误仍查不到，可发送 <code>/用户ID搜索</code> 交叉验证。持续异常请提交工单。",
        'kw' => '不存在 未找到 查不到 用户 查询失败',
    ],
    [
        'cat' => 'error', 'hot' => 1,
        'q' => '提示「查询过于频繁」怎么办？',
        'a' => "触发了限流保护。默认规则：\n- 每分钟 20 次\n- 每小时 200 次\n\n<strong>解决办法：</strong>\n1. 停止操作，等待 1 分钟后再试\n2. 避免短时间内连续发送大量查询命令\n3. 批量查询时在命令之间适当间隔\n\n如果你确实需要更高的查询额度（例如社群活动期间），可提交工单申请，由管理员调整 <code>rateLimitPerMin</code> / <code>rateLimitPerHour</code>。",
        'kw' => '频繁 限流 太多 太多次 超出',
    ],
    [
        'cat' => 'error',
        'q' => '提示「权限不足」是什么意思？',
        'a' => "该命令需要更高权限才能使用。\n\n<strong>需要权限的命令：</strong>\n- 管理员及以上：<code>/添加管理员</code> <code>/删除管理员</code> <code>/给予风控限制</code> <code>/解除风控</code> <code>/违规添加</code> <code>/审核处理</code> <code>/添加兑换码</code> <code>/数据导出</code> 等\n- 开发者专属：<code>/增减R点</code> <code>/增减经验</code> <code>/设定等级</code>\n- 协助管理员：审核处理与风控等级调整\n\n如果你确信自己应有权限，请发送 <code>/我的信息</code> 核实当前身份，并提交工单说明。",
        'kw' => '权限不足 无权限 权限 报错',
    ],
    [
        'cat' => 'error',
        'q' => '结果里的图片是裂的/一直转圈',
        'a' => "这是国内网络访问 Roblox 官方 CDN 受阻的常见问题。\n\n<strong>临时解决：</strong> 稍后刷新，或重新发送一次查询命令。\n\n<strong>根本解决：</strong> 部署图床代理服务，然后在插件配置中填写 <code>imageProxyUrl</code>，图片会经代理转存到本地稳定展示。\n\n<strong>如果连文字结果也查不出来：</strong>\n- 检查 <code>useProxyServer</code> 是否保持开启（默认 true，经 rotunnel.com 代理）\n- 确认 <code>apiServer</code> 留空使用官方接口\n\n详细部署步骤见「部署与运维」分类的图床代理 FAQ。",
        'kw' => '图片 裂图 加载 转圈 空白 显示异常',
    ],
    [
        'cat' => 'error',
        'q' => '兑换码提示无效或已过期',
        'a' => "可能原因：\n\n1. <strong>兑换码不存在</strong> — 发送 <code>/查询兑换码 兑换码</code> 验证\n2. <strong>已过期</strong> — 兑换码创建时设定了有效天数（参数 <code>validityDay</code>）\n3. <strong>数量已领完</strong> — 兑换码有总数量限制（参数 <code>total</code>）\n4. <strong>复制时带空格或大小写错误</strong> — 兑换码区分大小写，请复制完整字符串\n\n确认兑换码无误仍无法使用，请提交工单并附上兑换码截图。",
        'kw' => '兑换码 无效 过期 已领完 错误',
    ],
    [
        'cat' => 'error',
        'q' => '签到提示今日已签到，但我不记得签过',
        'a' => "每日签到每天只能获得一次 R 点与经验。\n\n<strong>可能情况：</strong>\n- 之前已经签到过，R 点与经验已发放（发送 <code>/积分</code> 和 <code>/流水</code> 确认发放记录）\n- 换了 QQ 账号或解绑后重新绑定了另一个 Roblox 账号 —— 签到记录按账号存储，不同账号需分别签到\n\n如果流水里确实没有签到记录，请提交工单说明。",
        'kw' => '签到 已签到 重复 签到不了',
    ],
    [
        'cat' => 'error',
        'q' => '绑定账号时提示已被绑定',
        'a' => "同一个 Roblox 账号在同一时间只能绑定一个 QQ 账号。\n\n<strong>解决办法：</strong>\n1. 先在原 QQ 上发送 <code>/解除绑定</code>，再在当前 QQ 重新绑定\n2. 若原 QQ 已不在你手中，请提交工单联系我们，说明：QQ 号、Roblox 用户 ID、机器人 ID，说明情况后由管理员协助解绑\n\n<strong>注意：</strong> 解绑并重新绑定会转移该 Roblox 账号的积分与等级，请确认操作无误。",
        'kw' => '绑定 已被绑定 绑定失败 冲突',
    ],
    [
        'cat' => 'error',
        'q' => '结果内容很简略 / 少了某些信息',
        'a' => "不同查询类型返回的信息不同：\n\n- <strong>用户名搜索</strong> 返回完整资料（头像、简介、好友等）\n- <strong>游戏搜索</strong> 返回游戏基本信息与入口按钮\n- <strong>群组搜索</strong> 返回群组图标与基础信息\n\n如果某项信息显示为空，通常是该目标本身未公开该字段（例如用户关闭了好友列表可见性、游戏未开放公开服务器）。\n\n若确定应有信息却缺失，请提交工单并附上查询的命令与返回结果截图。",
        'kw' => '信息缺失 少了 简略 不完整',
    ],
],

// ============ 系统设置 ============
'settings' => [
    'site_name'        => 'Roblox 查询机器人 · 工单中心',
    'site_desc'        => 'koishi-plugin-robloxsearch 配套服务门户 — 知识库自助排查，问题一键提交',
    'site_keywords'    => 'Roblox,查询机器人,工单,FAQ,知识库,koishi',
    'site_url'         => '',
    'site_icp'         => '',
    'home_announce'    => '',
    'ticket_prefix'    => 'RB',
    'ticket_auto_reply'=> '1',
    'ticket_allow_guest' => '1',
    'ticket_require_email' => '1',
    'upload_max_mb'    => '10',
    'upload_max_count' => '5',
    'antibot_rate_limit' => '5',
    // Cloudflare Turnstile：两处站点密钥留空则自动退回算术验证码，
    // 不会因为忘配而让游客无法提交工单。
    'turnstile_site_key'   => '0x4AAAAAAFLyRh01wP_T51-y',
    'turnstile_secret_key' => '0x4AAAAAAFLyRj-voMFpbc2_lmVbN804O-A',
    'reg_require_email_code' => '1',
    'mail_enabled'     => '0',
    'mail_mode'        => 'smtp',
    'mail_from'        => '',
    'mail_from_name'   => '工单中心',
    'mail_admin_to'    => '',
    'mail_smtp_host'   => '',
    'mail_smtp_port'   => '465',
    'mail_smtp_user'   => '',
    'mail_smtp_pass'   => '',
    'mail_smtp_secure' => 'ssl',
    'mail_subject_created' => '【{site}】您的工单 {no} 已提交',
    'mail_subject_reply'   => '【{site}】工单 {no} 有新回复',
    'mail_subject_status'  => '【{site}】工单 {no} 状态更新为「{status}」',
    'mail_subject_closed'  => '【{site}】工单 {no} 已关闭',
    'mail_tpl_created' => "<p>您好，</p><p>您的工单 <strong>{no}</strong> 已成功提交，我们已收到您的问题。</p><p><strong>问题标题：</strong>{title}<br><strong>提交时间：</strong>{time}<br><strong>当前状态：</strong>{status}</p><p>您可以随时点击下方按钮查看进展或补充说明：</p><p style=\"margin:24px 0\">{link}</p><p style=\"color:#6b7280;font-size:13px\">{hint}</p><p>如问题紧急，可在工单详情页直接追加回复，客服会尽快跟进。</p>",
    'mail_tpl_reply'   => "<p>您好，</p><p>您的工单 <strong>{no}</strong> 有新的客服回复，请及时查看：</p><p><strong>问题标题：</strong>{title}<br><strong>回复时间：</strong>{time}</p><div style=\"background:#f9fafb;border-left:3px solid #4f46e5;padding:14px 16px;margin:16px 0;border-radius:0 8px 8px 0\">{content}</div><p style=\"margin:24px 0\">{link}</p><p style=\"color:#6b7280;font-size:13px\">{hint}</p><p>如果本次回复已解决您的问题，可以在工单详情页点「标记为已解决」并给出评价，帮助我们持续改进。</p>",
    'mail_tpl_status'  => "<p>您好，</p><p>您的工单 <strong>{no}</strong> 状态已更新：</p><div style=\"background:#f9fafb;padding:14px 16px;margin:16px 0;border-radius:8px\">{content}</div><p style=\"margin:24px 0\">{link}</p><p style=\"color:#6b7280;font-size:13px\">{hint}</p><p>感谢您的耐心等待。</p>",
    'mail_tpl_closed'  => "<p>您好，</p><p>您的工单 <strong>{no}</strong> 已被关闭。</p><p><strong>问题标题：</strong>{title}<br><strong>关闭时间：</strong>{time}</p><p>如果您的问题仍未解决，欢迎随时回复本邮件或重新提交工单。</p><p style=\"margin:24px 0\">{link}</p><p style=\"color:#6b7280;font-size:13px\">{hint}</p>",
],
];
