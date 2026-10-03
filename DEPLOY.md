# 部署说明

本文档说明部署流程与运维要点。**具体凭据不写入版本库**——
它们属于运行环境的信息，请通过自己的密码管理器或服务器上的配置文件传递。

---

## 一、部署方式

### 1.1 目录结构

把项目上传到站点目录，**Web 根目录必须指向项目下的 `public/`**：

```
/www/wwwroot/你的站点/
├── public/          ← Web 根目录（index.php + assets + favicon）
├── app/             应用代码（不通过 Web 暴露）
├── config/          配置（不通过 Web 暴露）
├── database/        结构、种子数据与迁移脚本
├── storage/         日志与附件（需可写，不通过 Web 暴露）
├── templates/       视图模板
├── bin/             命令行脚本
├── routes/          路由表
├── deploy/          nginx 配置范例
├── bootstrap.php    组合根
└── README.md        项目文档
```

这样布局的用意：`app/`、`config/`、`database/`、`storage/`、`bin/` 全部位于
Web 根之外，因此**不依赖 Web 服务器的访问控制配置**就不可被直接访问。
靠 `.htaccess` 屏蔽是 Apache 专有的做法，在 nginx 上完全无效。

### 1.2 安装步骤

```bash
# 1) 准备数据库
mysql -uroot -p -e "
CREATE DATABASE \`ticket\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ticket'@'localhost' IDENTIFIED BY '换成你自己的口令';
GRANT ALL PRIVILEGES ON \`ticket\`.* TO 'ticket'@'localhost';
FLUSH PRIVILEGES;"

# 2) 配置数据库连接（不要提交这个文件）
cp config/local.example.php config/local.php
chmod 640 config/local.php
# 编辑 config/local.php 填入上面的库名/用户/口令

# 3) 建表与初始化（生成 app_key、分类、知识库、管理员）
php bin/setup.php --admin=admin --pass='你的强密码' --name='站点管理员'
```

安装脚本是**幂等**的：重复运行不会重复插入数据，已有管理员时不会覆盖。

```bash
php bin/setup.php --help          # 全部参数
php bin/setup.php --seed-only     # 只补结构，不创建管理员
php bin/setup.php --force         # 结构已存在时仍重跑
```

> `app_key` 在首次安装时生成并写入 `config/local.php`。
> **不要更换它**：更换会立即使所有访客工单链接失效，也会让已发出的表单令牌作废。

### 1.3 增量升级（已部署过的站点）

```bash
mysql -uticket -p ticket < database/migrate-2.0.2.sql        # 结构修复
mysql -uticket -p ticket < database/migrate-2.0.3-icons.sql  # 分类图标改为图标名
```

两个脚本都是幂等的，重复执行不会报错也不会重复改数据。

### 1.4 nginx 配置

参考 `deploy/nginx.conf.example`。三个必须确认的点：

1. `root` 指向 `public/` 目录；
2. `include enable-php-84.conf;` 的版本与服务器实际安装一致；
3. `client_max_body_size` 不小于后台上传上限（默认 10MB × 5 个附件）。

第 3 点特别容易漏：nginx 默认只有 1MB，不设置的话任何真实附件都会以 **413**
失败，而用户在页面上只会看到「上传失败」。

### 1.5 定时维护

```cron
0 4 * * * cd /www/wwwroot/你的站点 && php bin/cron.php >> storage/logs/cron.log 2>&1
```

---

## 二、PHP 环境配置（影响性能，务必检查）

以下两项决定了站点的响应速度，且与业务代码无关。

### 2.1 启用 OPcache

不少面板生成的 `php.ini` 里 `zend_extension=opcache` 这一行是**被注释掉的**，
此时 opcache 实际处于关闭状态——每个请求都要把全部 PHP 源文件重新编译一遍。

确认与修改：

```bash
php -i | grep -E 'opcache.enable|opcache_get_status'
```

```ini
zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
```

`validate_timestamps=1` + `revalidate_freq=60` 让「改完文件最多 60 秒生效」，
既保留部署便利性，稳态下又几乎不再 stat 文件。

### 2.2 会话配置（决定匿名页面能否被 CDN 缓存）

```ini
session.use_strict_mode = 1
session.cache_limiter = ''
session.gc_probability = 0
```

- `use_strict_mode=1`：只在确实没有有效会话时才新建会话。开启前，**每个**不带
  Cookie 的请求都会拿到一个新的会话 ID，响应因此永远无法被缓存命中。
- `cache_limiter=''`：关掉 PHP 自动输出的 `Cache-Control: no-store`，
  由应用按「是否匿名」自行决定。开着它等于全站禁止缓存。
- `gc_probability=0`：访客会话没什么可回收的，降低无谓的抖动。

改完需要重启 php-fpm。**注意**：面板的「PHP 设置」界面可能把这些值改回去；
站点变慢时先回来检查这几项。

### 2.3 验证是否生效

```bash
php -r '$s=opcache_get_status(false); printf("命中率 %.1f%%\n", $s["opcache_statistics"]["opcache_hit_rate"]);'
```

---

## 三、CDN / 边缘缓存（可选，但对跨境访问提升明显）

应用会在满足**全部**下列条件时发出可缓存头：

- 请求方法为 GET
- 访问者未登录（没有会话 Cookie）
- 本次请求没有写过会话（无 flash、无表单回填、无访客密钥）
- 响应状态为 2xx
- 页面**不含 POST 表单**（避免把隐藏的 CSRF 令牌与表单时间共享给所有访问者）

满足时：

```
Cache-Control: public, max-age=0, s-maxage=60, stale-while-revalidate=300, stale-if-error=600
```

**Cloudflare 免费版默认不缓存 HTML**，因此还需要加一条 Cache Rule：

1. Caching → Cache Rules → Create rule
2. 匹配：`Hostname equals 你的域名` **And** `Request Method equals GET`
3. 动作：`Cache eligibility = Eligible for cache`，
   `Edge TTL = Use cache-control header if present, bypass cache if not`
4. 保存后验证：

```bash
curl -sI https://你的域名/knowledge | grep -i cf-cache-status
# 首次 MISS，再次请求应为 HIT
```

**为什么不会串号**：只要访问者已登录，或本次请求涉及写操作、或页面含表单，
应用返回的就是 `Cache-Control: no-store, no-cache, must-revalidate, private`，
CDN 不会缓存它。这一点有自动化验证覆盖。

时长默认 60 秒，可在 `config/app.php` 的 `app.edge_cache_seconds` 调整；
设为 `0` 完全关闭。

---

## 四、验收自测

```bash
# 端到端功能测试（需要站点在线）
python tools/e2e.py
```

覆盖：访客提交（错误验证码 / 提交过快 / 蜜罐逐一被拒）、工单访问控制
（无密钥 404、错误密钥 403、跳转地址不含密钥）、后台处理（回复、内部备注、
状态机、评分、删除含子表清理）、安全（无令牌 POST 返回 419、开放重定向被拦、
GET 登出返回 405、未登录访问后台被拦、敏感路径全部 404）。

---

## 五、排查问题

| 内容 | 位置 |
|---|---|
| 运行时错误与异常 | `storage/logs/app.log` |
| PHP 解析 / 致命错误 | `storage/logs/php-error.log` |
| 邮件发送问题 | `storage/logs/mail.log` + 后台「邮件设置」页 |
| 安全事件（权限拒绝、删除、导出、改密） | `storage/logs/security.log` |
| Web 服务器访问与错误日志 | 由部署环境决定 |

### 邮件延迟排查

后台「邮件设置」页有「发送测试邮件」，会显示 SMTP 往返耗时。
如果测试发送很快但收件人很久才收到，问题在投递链路的**收件方**，不在应用：

- 检查域名的 SPF 是否覆盖实际发信服务器的 IP（发信 IP 是 SMTP 主机的 IP，
  不是 Web 服务器的 IP）；
- 检查 DKIM 是否在发信服务器上真正启用了签名（DNS 里有公钥不代表会签）；
- 检查发信 IP 是否有反向解析（PTR）。缺少 PTR 是被延迟或判为垃圾的常见原因；
- 收件方（尤其是国内邮箱）对陌生发信源有灰名单机制，
  首次投递延迟数分钟属于正常现象，稳定发信后会改善。

应用侧已做到：把手交给 SMTP 服务器后立即返回（`fastcgi_finish_request`），
用户不会对着加载动画等 SMTP 往返；每封信无论成败都写入 `mail_log`。

---

## 六、安全清单

- [ ] `config/local.php` 权限 640，且**不在版本库里**
- [ ] 管理员使用强密码，并在首次登录后修改
- [ ] 后台「系统设置 → 站点地址」填写正式地址（邮件里的链接以此为基准）
- [ ] 开启 HTTPS，并把 `app.session_secure` 设为 `true`
- [ ] `storage/`、`config/`、`app/`、`database/`、`bin/` 不在 Web 根之下
- [ ] 上传目录禁止脚本执行（nginx 配置范例里已包含）
- [ ] 定期备份数据库与 `config/local.php`
- [ ] 如需 CDN，按第三节配置，不要对已登录页面开启缓存
