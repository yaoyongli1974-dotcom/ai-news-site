# AI 资讯汇 (ai.xalcy.cn) — 交付概览

基于 **PHP 8 + MySQL** 的微信公众号文章聚合展示系统，含前台浏览、后台管理、文章推送接口与**图片上传接口**。
零第三方依赖，可直接部署到任意 PHP8+MySQL 主机（含 1Panel 这类 PHP 跑在 Docker 容器里的面板）。

## 已完成模块

| 模块 | 文件 | 说明 |
|------|------|------|
| 数据库 | `sql/schema.sql` | 8 张表：articles / categories / tags / article_tags / admins / api_tokens / push_logs / options，utf8mb4，含索引、初始分类标签与站点配置 |
| 核心框架 | `src/{bootstrap,db,functions,auth,view,admin_view,article_service,upload_service}.php` | PDO 连接、转义/slug/分页/CSRF、会话鉴权、HMAC 鉴权（推送 + 上传）、前后台布局、文章写服务、图片上传服务 |
| 前台 | `public/{index,article,category,tag,search}.php` + `assets/style.css` | 列表分页、分类/标签筛选、搜索、详情页（阅读量+相关推荐）、响应式+SEO meta |
| 后台 | `public/admin/*.php` + `assets/admin.css` | 登录/登出、仪表盘、文章增删改查、**媒体库**、分类/标签管理、推送令牌管理，全程 CSRF 与会话鉴权 |
| 推送 API | `public/api/push.php` | 接收单篇/批量 JSON，HMAC-SHA256 签名或 Bearer 鉴权、时间戳防重放、字段校验、`source_id`/`slug` 幂等 upsert、审计日志 |
| **上传 API** | `public/api/upload.php` + `src/upload_service.php` | multipart 多图上传，落盘 `public/assets/uploads/`，返回可直接引用的 URL / HTML / Markdown；类型白名单、体积与像素上限、安全命名与去冲突、目录自动创建 |
| 客户端/脚本 | `scripts/{push_client,upload_client,setup,check_db}.php` + `sample_payload.json` + 配置样例 | 推送与上传客户端（带签名+重试）、初始化管理员与令牌、数据库连通性自检 |
| 部署 | `deploy/{nginx.conf,nginx-uploads-snippet.conf,apache.htaccess,crontab.example,1panel.sh}`、`public/.htaccess`、`public/assets/uploads/.htaccess` | Nginx/Apache 配置（web 根指向 `public/`）、上传目录禁脚本、cron 示例、1Panel 运维脚本、完整文档 |

## 鉴权机制（核心需求）

**两个接口共用同一套 Key/Secret，但签名对象不同：**

| 接口 | 签名对象 | 原因 |
|------|----------|------|
| `/api/push.php` | `timestamp + "." + 原始请求体` | `application/json`，`php://input` 可取到完整 body |
| `/api/upload.php` | `timestamp + "." + sha256(文件1)[ + "." + sha256(文件2)...]` | `multipart/form-data` 的 body 被 PHP 消费进 `$_FILES`，`php://input` 为空，无法对 body 签名 |

- 二者都支持 `Authorization: Bearer <API_SECRET>` 免签名方式。
- 时间戳有效期默认 300s 防重放；密钥仅后台生成时展示一次，可随时吊销；调用记入 `push_logs`（`action = upload|push`）。

## 图片上传接口要点

| 项目 | 值 |
|------|-----|
| 方法 / 类型 | `POST` / `multipart/form-data` |
| 字段 | `files[]`（或 `file`）、`name`（文件名主干，可选）、`alt`（可选） |
| 类型白名单 | jpeg / png / gif / webp（**扩展名由服务端按真实 MIME 决定**，`shell.php.jpg` 无效） |
| 默认限制 | 单张 5MB、单次 10 张、4000 万像素（`config.php` → `upload` 段可改，不配置也能用默认值） |
| 存储 | `public/assets/uploads/`，不存在自动创建；同名冲突自动加 4 位随机后缀 |
| 返回 | `url` / `path` / `html` / `markdown` / `size` / `mime` / `width` / `height` / `sha256` |

**与 AI 自动入库的配合**：① AI 写文章 → ② 配图落地（微信 CDN 图必须自托管）→ ③ `POST /api/upload.php` 取 URL →
④ 把 URL 写进 `content` 的 `<img>` 与 `cover_image` → ⑤ `POST /api/push.php` 入库 → ⑥ 前台可见，后台「媒体库」可管理。

## 验证情况

- 全部 PHP 文件通过括号配平静态检查；`bash -n` 校验 `1panel.sh` 通过。
- 本机（Windows）无 PHP/MySQL，**未做运行时验证**；部署后建议依次执行：
  `docker exec php8-fpm php -l <文件>`、导入 `sql/schema.sql`、`scripts/setup.php` 初始化、
  然后按 README 第三章用 curl 或 `scripts/upload_client.php` 实测一次上传。
- 已修正的逻辑问题：① `bootstrap` 统一加载 `functions.php`；② `resolve_category_id` 同时支持 ID/slug/名称；
  ③ 分页 `q` 参数去重；④ 相关推荐补全分类字段；⑤ `auth.php` 抽出 `api_auth_context()`/`api_verify_signature()`，
  在保持 `api_require_auth()` 行为不变的前提下新增文件摘要签名校验。

## 正文可读性修复（2026-09-11）

用户截图反馈「文章页深色代码块里的文字看不见」。实测定位为**站点样式表缺陷**，已在源码层根治：

| 项 | 内容 |
|----|------|
| 病因 | `.post-content code` 只设了浅灰底 `#eef1f6`、**没设文字色**；嵌在 `.post-content pre{background:#0f172a;color:#e2e8f0}` 里时继承浅色文字 → 浅底浅字，**对比度 1.09:1**（表现为一条条白底横条） |
| 根治 | `public/assets/style.css` 拆开定义：`.post-content pre`（深底浅字+等宽字体+`pre-wrap`）、`.post-content code`（行内：浅底 **深字 `#101828`**）、`.post-content pre code`（**透明底 + `color:inherit`**，特异性高于行内 code 规则） |
| 顺带 | `.post-content th/td` 兜底 `border:1px + padding:8px 10px`、`th` 浅底、`blockquote` 左侧强调条（微信来源表格实测只有 `table` 级样式，单元格 `padding:1px / border:0`） |
| 防缓存 | 新增 `asset()` 助手（`src/functions.php`），前台/后台 CSS 均输出 `?v=<filemtime>`，改样式后 URL 即变 |
| 验证 | Playwright 实测：裸 `pre>code` 修复前 **1.09:1 → 修复后 14.48:1**；剥离内联样式后 5 篇文章 **26 个代码块 0 处不可读**；线上 6 篇文章全页对比度 0 处低于 4.5:1 |
| 部署动作 | **把 `public/assets/style.css` 上传覆盖服务器同名文件即可**，无需重推文章 |

## 下一步（部署清单）

0. **上传 `public/assets/style.css`**（本次修复的文件）覆盖服务器
   `/opt/1panel/www/sites/ai.xalcy.cn/index/public/assets/style.css`；
   核验：`curl -s https://ai.xalcy.cn/assets/style.css | grep 'pre code'` 应有输出。
1. **目录隔离（重要）**：1Panel → 网站 → 设置 → 网站目录 → **运行目录选 `/public`**，
   避免 `config.php` / `sql/` / `密码信息.md` 等被公网下载。
2. 导入数据库（`bash deploy/1panel.sh db-import`）、复制 `config.example.php` 为 `config.php` 并填好连接与密钥。
3. 运行 `bash deploy/1panel.sh setup --user=admin --pass=强密码` 创建管理员与首个推送令牌。
4. **上传相关**：调大 `client_max_body_size`(Nginx 60m) 与 `post_max_size`/`upload_max_filesize`(PHP)；
   把 `deploy/nginx-uploads-snippet.conf` 贴进站点配置以禁止上传目录执行脚本；确认 `public/assets/uploads` 对 PHP 进程可写。
5. AI 侧用 `scripts/upload_client.php` 或 README 的 Python 示例上传配图，再用 `push_client.php` / curl 推送文章；
   定时任务可参考 `deploy/crontab.example`。
