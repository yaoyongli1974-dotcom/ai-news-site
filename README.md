# AI 资讯汇 (ai.xalcy.cn)

基于 **PHP 8 + MySQL** 的微信公众号文章聚合展示系统。汇集并展示 AI 相关的公众号文章，包含：

- **前台**：文章列表（分页）、分类页、标签页、搜索、文章详情（含相关推荐、阅读量）。
- **后台**：管理员登录、文章增删改查、分类管理、标签管理、推送接口令牌管理。
- **推送接口**：提供安全 API，AI 可通过 **API 调用或定时任务(cron)** 把写好的文章自动推送入库并在前台发布。支持 HMAC-SHA256 签名鉴权与时间戳防重放，按 `source_id`/`slug` 幂等去重。

> 技术特点：零第三方依赖（纯 PHP + PDO），可直接部署到任意 PHP8 + MySQL 主机（含共享主机）。所有 SQL 均使用预处理语句，杜绝 SQL 注入。

---

## 目录结构

```
ai.xalcy.cn/
├── config.example.php        # 配置样例(复制为 config.php 使用)
├── sql/schema.sql           # 数据库结构 + 初始化数据
├── src/                     # 核心代码(位于 web 根之外,不可被直接访问)
│   ├── bootstrap.php        # 引导: 配置/DB/自动加载/错误处理
│   ├── db.php               # PDO 连接与查询助手
│   ├── functions.php        # 通用函数(转义/slug/分页/CSRF/选项)
│   ├── auth.php             # 后台会话鉴权 + 推送/上传 API 签名鉴权
│   ├── view.php             # 前台布局渲染
│   ├── admin_view.php       # 后台布局渲染
│   ├── article_service.php  # 文章写服务(后台与 API 共用)
│   └── upload_service.php   # 图片/视频上传服务(校验/命名/落盘,后台与 API 共用)
├── public/                  # ← 网站根目录(DocumentRoot 指向这里)
│   ├── index.php            # 首页(列表+分页)
│   ├── article.php          # 文章详情
│   ├── category.php         # 分类页
│   ├── tag.php              # 标签页
│   ├── search.php           # 搜索
│   ├── assets/              # 前台/后台 CSS
│   │   ├── uploads/         # ← 图片上传目录(自动创建;内含 .htaccess 禁脚本)
│   │   └── videos/          # ← 视频上传目录(自动创建;内含 .htaccess 禁脚本)
│   ├── admin/               # 后台模块(login/logout/index/articles/uploads/categories/tags/api_tokens)
│   └── api/                 # push.php(推文章) / upload.php(传图片) / upload_video.php(传视频)
├── scripts/                 # 运维脚本
│   ├── setup.php            # 初始化管理员 + 首个令牌(CLI)
│   ├── check_db.php         # 数据库连通性自检(容器环境排查 db.host 用)
│   ├── push_client.php      # 推送客户端(CLI,供 cron 调用)
│   ├── upload_client.php    # 上传客户端(CLI,自动算摘要签名;支持 --type=video)
│   └── sample_payload.json  # 推送请求示例
└── deploy/                  # 部署配置样例
    ├── nginx.conf           # 裸装 Nginx 用(含上传目录禁执行规则)
    ├── nginx-uploads-snippet.conf # 上传目录安全片段(1Panel 粘贴用)
    ├── apache.htaccess
    ├── crontab.example
    └── 1panel.sh            # 1Panel(Docker) 运维脚本: doctor/db-import/setup
```

---

## 一、安装步骤

### 1. 导入数据库
```bash
mysql -u root -p
CREATE DATABASE ai_xalcy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ai_xalcy;
SOURCE /var/www/ai.xalcy.cn/sql/schema.sql;
# 或单行: mysql -u<user> -p ai_xalcy < sql/schema.sql
```

### 2. 配置
```bash
cp config.example.php config.php
# 编辑 config.php，填入 db 账号、app_url、api 安全项、pepper
```
> `config.php` 位于项目根（web 根之外），不会被公网直接访问。

### 3. 初始化管理员与推送令牌
```bash
php scripts/setup.php --user=admin --pass=你的强密码
# 也可不带参数，进入交互式输入
```
脚本会创建管理员账号，并生成首个推送凭据（**API Key / Secret 仅显示一次**）。
> 若服务器是 **1Panel 面板（PHP 跑在 Docker 容器里）**，宿主机没有 `php` 命令，
> 请改看 **第七章**，用 `bash deploy/1panel.sh doctor` 一键完成自检与初始化。

### 4. 配置 Web 服务器
- **Nginx**：参考 `deploy/nginx.conf`，关键是 `root` 指向 `public/`。
- **Apache**：将项目放入 `public_html`，并把 `deploy/apache.htaccess` 内容放到 `public/.htaccess`。若无法修改 DocumentRoot，请确保 `config.php`/`src/`/`sql/` 不被 Web 访问（用 `.htaccess` 拒绝）。

### 5. 访问
- 前台： `https://ai.xalcy.cn/`
- 后台： `https://ai.xalcy.cn/admin/`
- 推送接口： `https://ai.xalcy.cn/api/push.php`

---

## 二、推送接口(AI 自动入库)

接口地址：`POST https://ai.xalcy.cn/api/push.php`，`Content-Type: application/json`

### 鉴权（二选一）

**方式一 · HMAC 签名（推荐）**
```
X-Api-Key:    <API_KEY>
X-Timestamp:  <Unix 秒级时间戳>
X-Signature:  <HMAC-SHA256( timestamp + "." + 原始请求体 , API_SECRET ) 的十六进制>
```
- `X-Signature` 需对 **原始请求体字节** 计算，签名串格式为 `时间戳.请求体`。
- 服务器校验时间戳与当前时间差 ≤ `api.signature_ttl`（默认 300 秒），防重放。

**方式二 · Bearer 令牌**
```
Authorization: Bearer <API_SECRET>
```

### 请求体（支持批量）
```json
{
  "articles": [
    {
      "title": "文章标题",
      "content": "<p>正文(支持 HTML)</p>",
      "summary": "摘要(可选,留空自动生成)",
      "category": "tutorial",
      "tags": ["大模型", "AIGC"],
      "author": "作者",
      "cover_image": "https://...",
      "source_url": "https://...",
      "source_id": "wx-2026-0001",
      "status": "published",
      "published_at": "2026-09-10 09:30:00"
    }
  ]
}
```
> 单篇可直接 POST 一个对象。强烈建议提供 `source_id`，重复推送（含失败重试）不会重复入库。

### 响应
```json
{
  "success": true,
  "received": 1, "created": 1, "updated": 0,
  "items": [{"index":0,"id":12,"action":"created","slug":"...","url":"..."}],
  "errors": []
}
```

### 调用示例

**PHP 客户端（推荐用于定时任务）**
```bash
# 推送示例
php scripts/push_client.php scripts/sample_payload.json
# 真实场景: 将 AI 生成的文章写入 payload.json 后定时推送
```
配置见 `scripts/push_client.config.example.php`（或环境变量 `AIXALCY_API_URL/KEY/SECRET`）。

**curl**
```bash
TS=$(date +%s)
BODY='{"articles":[{"title":"测试","content":"<p>hi</p>","source_id":"t1"}]}'
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" | awk '{print $2}')
curl -X POST https://ai.xalcy.cn/api/push.php \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $API_KEY" \
  -H "X-Timestamp: $TS" \
  -H "X-Signature: $SIG" \
  -d "$BODY"
```

**Python**
```python
import time, hmac, hashlib, requests
api_url, key, secret = "...", "...", "..."
body = '{"articles":[{"title":"测试","content":"<p>hi</p>","source_id":"t1"}]}'
ts = str(int(time.time()))
sig = hmac.new(secret.encode(), (ts + "." + body).encode(), hashlib.sha256).hexdigest()
r = requests.post(api_url, data=body, headers={
    "Content-Type":"application/json",
    "X-Api-Key":key, "X-Timestamp":ts, "X-Signature":sig})
print(r.status_code, r.json())
```

### 定时任务(cron)
参考 `deploy/crontab.example`：
```cron
0 9,21 * * * /usr/bin/php /var/www/ai.xalcy.cn/scripts/push_client.php /data/wx_articles/today.json >> /var/log/ai_xalcy_push.log 2>&1
```
> AI 也可自行调度，无需系统 cron——只要能发出带签名的 HTTPS 请求即可。

---

## 三、图片上传接口（配图自托管 / AI 自动入库的前置步骤）

公众号图片有防盗链，直接引用微信 CDN 地址在前台会显示裂图；因此 AI 生成文章时应先把配图落到自己域名下。
本接口负责「上传图片 → 返回可访问 URL」，在调用 `/api/push.php` **之前**执行。

### 1. 请求方式

| 项目 | 值 |
|------|-----|
| 地址 | `POST https://ai.xalcy.cn/api/upload.php` |
| Content-Type | `multipart/form-data` |
| 单张上限 | 默认 **5 MB**（`config.php` → `upload.max_size`） |
| 单次张数 | 默认 **10 张**（`upload.max_files`） |
| 像素上限 | 默认 4000 万像素（`upload.max_pixels`，防像素炸弹） |
| 支持类型 | `image/jpeg`、`image/png`、`image/gif`、`image/webp` |
| 存放目录 | `public/assets/uploads/`（**不存在时自动创建**，权限 0755） |
| URL 前缀 | `/assets/uploads/`（`upload.url_path`） |

> 只需 `config.example.php` 中的 `upload` 段可选配；**不配置也能用**，代码内置了上述默认值，
> 已有站点的 `config.php` 无需改动。

### 2. 表单字段

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `files[]` | ✅ | file | 图片文件，可多张 |
| `file` | ✅ | file | `files[]` 的等价单文件写法（与上者二选一） |
| `name` | ✖ | string | 文件名主干（不含扩展名）。多张时自动追加 `-01`/`-02` 序号；非 ASCII 字符会被剔除；留空则用「日期-随机串」 |
| `alt` | ✖ | string | 生成 `<img alt>` 与 Markdown 片段时的替代文本 |

### 3. 鉴权（与推送接口的唯一差异：签名对象不同）

```
X-Api-Key:    <API_KEY>
X-Timestamp:  <Unix 秒级时间戳>
X-Signature:  HMAC-SHA256( timestamp + "." + sha256(文件1) [+ "." + sha256(文件2) ...], API_SECRET )
```

> ⚠️ **为什么不能照抄推送接口的签名？**
> `multipart/form-data` 的请求体会被 PHP 直接消费进 `$_FILES`/`$_POST`，`php://input` 为空，
> **拿不到可签名的原始请求体**。所以这里改为对「文件内容摘要」签名：
> 按请求中文件的先后顺序，把每个文件的 sha256 用 `.` 拼在时间戳之后。
> 每个 sha256 固定 64 位 hex，拼接结果无歧义。

- 时间戳有效期与推送接口一致（默认 300 秒，防重放）。
- 也可用 `Authorization: Bearer <API_SECRET>` 免签名，便于联调，成功响应完全一致。

### 4. 成功响应（HTTP 200）

```json
{
  "success": true,
  "count": 1,
  "failed": 0,
  "files": [
    {
      "name":   "10-person-team-ai-efficiency-01.jpg",
      "path":   "/assets/uploads/10-person-team-ai-efficiency-01.jpg",
      "url":    "https://ai.xalcy.cn/assets/uploads/10-person-team-ai-efficiency-01.jpg",
      "html":   "<img src=\"https://ai.xalcy.cn/assets/uploads/...jpg\" alt=\"\" width=\"1200\" height=\"675\" loading=\"lazy\">",
      "markdown": "![](https://ai.xalcy.cn/assets/uploads/...jpg)",
      "size":   184320,
      "mime":   "image/jpeg",
      "width":  1200,
      "height": 675,
      "sha256": "9f2c1b..."
    }
  ],
  "errors": []
}
```

| 字段 | 用途 |
|------|------|
| `url` | **绝对地址**，直接写进 `cover_image` 或正文 `<img src>` |
| `path` | 站内相对路径，适合存库/做替换 |
| `html` / `markdown` | 可直接粘贴的片段（已带 `width`/`height`，避免 CLS） |
| `sha256` | 内容指纹，可用于去重（重复上传同一张图前先比对） |

部分失败时（如 2 张中 1 张超限）仍返回 200，`failed` 与 `errors` 给出失败明细。

### 5. 错误响应

统一格式：`{ "success": false, "error": "<code>", "message": "<中文说明>" }`

| HTTP | error | 含义与处理 |
|------|-------|-----------|
| 405 | `method_not_allowed` | 用了 GET，改为 POST |
| 403 | `https_required` | 走 HTTPS 调用（`api.require_https` 为 true） |
| 401 | `unauthorized` / `invalid_token` | 缺少或无效的 Key/Secret |
| 401 | `bad_signature` / `expired` / `missing_signature` | 签名错 / 时间戳过期 / 只传了 Key 没传签名 |
| 413 | `payload_too_large` | 超过 `post_max_size`，响应里附带实际配置值与调整建议 |
| 400 | `no_file` | 未收到文件（字段名须为 `files[]` 或 `file`） |
| 400 | `too_many_files` | 超过 `max_files` |
| 400 | `upload_failed` | 逐张校验全失败，`errors` 里是每张的原因 |

单张文件的具体失败原因（出现在 `errors[].error` 或 400 的 `message`）：
超过大小上限、不支持的文件类型、不是有效的图片文件、图片尺寸过大、文件内容为空、
上传目录创建失败、上传目录不可写、文件写入失败、超过 `upload_max_filesize`。

### 6. 文件命名与冲突处理

- 主干取自 `name`，只保留 `[A-Za-z0-9-_]`，长度上限 `filename_max`（默认 60），中文等会被剔除。
- **扩展名由服务端检测到的真实 MIME 决定**，绝不采信客户端文件名 —— 因此 `shell.php.jpg` 这类双扩展名攻击天然无效。
- 同名冲突：自动追加 4 位随机后缀，如 `ai-tools-a1b2.jpg`。
- 兼容站内既有约定（`<slug>-01.jpg`）：
  `--name=10-person-team-ai-efficiency` + 3 张图 → `10-person-team-ai-efficiency-01.jpg / -02 / -03`。

### 7. 调用示例

**curl（Bearer 方式，最快验证）**
```bash
curl -X POST https://ai.xalcy.cn/api/upload.php \
  -H "Authorization: Bearer $API_SECRET" \
  -F "files[]=@cover.jpg" \
  -F "name=10-person-team-ai-efficiency-01" \
  -F "alt=AI 团队协作"
```

**PHP（用配套 CLI，自动算摘要签名）**
```bash
# 先复制配置：cp scripts/push_client.config.example.php scripts/push_client.config.php 并填 Key/Secret
php scripts/upload_client.php cover.jpg detail-01.jpg --name=10-person-team-ai-efficiency --alt="AI 团队协作"
php scripts/upload_client.php cover.jpg --json     # 只输出 JSON，便于脚本解析
php scripts/upload_client.php cover.jpg --dry-run  # 只打印签名与文件清单，不发送
```

**Python（推荐给 AI 自动化流程）**
```python
import os, hashlib, hmac, time, requests

API_URL    = "https://ai.xalcy.cn/api/upload.php"
API_KEY    = os.environ["AIXALCY_API_KEY"]
API_SECRET = os.environ["AIXALCY_API_SECRET"]


def upload_images(paths, name="", alt=""):
    ts = str(int(time.time()))
    digests = [hashlib.sha256(open(p, "rb").read()).hexdigest() for p in paths]
    # 签名对象 = 时间戳 + "." + 各文件内容 sha256（顺序须与上传顺序一致）
    base = ts + "." + ".".join(digests)
    sig  = hmac.new(API_SECRET.encode(), base.encode(), hashlib.sha256).hexdigest()

    handles = [open(p, "rb") for p in paths]
    try:
        files = [("files[]", (os.path.basename(p), h, "image/jpeg"))
                 for p, h in zip(paths, handles)]
        data = {}
        if name:
            data["name"] = name
        if alt:
            data["alt"] = alt
        resp = requests.post(API_URL, timeout=120, files=files, data=data,
                             headers={"X-Api-Key": API_KEY,
                                      "X-Timestamp": ts,
                                      "X-Signature": sig})
        resp.raise_for_status()
        return resp.json()
    finally:
        for h in handles:
            h.close()


res = upload_images(["cover.jpg", "detail-01.jpg"], name="ai-tools-2026", alt="AI 工具")
for f in res["files"]:
    print(f["url"])   # 可直接写进文章正文与 cover_image
    print(f["html"])  # 或直接粘贴这个 <img> 片段
```

### 8. 与 AI 自动入库流程的配合

```
① AI 写完文章（Markdown / HTML）
      ↓
② 配图落到本地：把微信 CDN 图、AI 生图逐张下载/保存
      ↓
③ POST /api/upload.php            ← 本接口
      ↓  返回 url / html / markdown
④ 把 url 写进文章：
     · 正文内嵌 <img src="https://ai.xalcy.cn/assets/uploads/xxx.jpg">
     · 封面字段 cover_image = 该 url
      ↓
⑤ POST /api/push.php              ← 文章入库，同结构见第二章
      ↓
⑥ 前台 https://ai.xalcy.cn/ 立即可见；后台「媒体库」可查看/删除图片
```

**注意事项**

| 事项 | 说明 |
|------|------|
| 顺序 | 推荐「先传图 → 再推文」。若先推文，正文里的图片地址必须等上传后才能确定，容易产生脏数据 |
| 幂等 | 上传接口**不做幂等**：同一张图重复上传会得到不同文件名。需要去重时先比对返回的 `sha256` |
| 文章幂等 | 文章幂等仍由 `/api/push.php` 的 `source_id` 保证，重试不会重复建文章 |
| 体积建议 | 单张压到 1600px 宽以内、200KB 左右，兼顾 LCP 与流量 |
| 失败重试 | 上传失败可直接重试；`name` 相同也不会覆盖旧文件（会自动加随机后缀） |

### 9. 服务器参数与安全

**① 三层体积限制必须都放大**（任一不足都会被提前拦掉）

| 层 | 参数 | 建议值 | 位置 |
|----|------|--------|------|
| Nginx | `client_max_body_size` | `120m` | 站点配置（1Panel → 网站 → 配置文件）；视频单文件 100MB，需 ≥ 此值 |
| PHP | `post_max_size` | `128M` | 1Panel → 运行环境 → PHP → 配置 |
| PHP | `upload_max_filesize` | `110M` | 同上；改完重启容器 `docker restart php8-fpm` |

**② 目录权限**：上传目录需对 PHP 进程可写。接口会明确报「上传目录不可写：assets/uploads」，
调整属主/权限即可（容器内 PHP 用户常见为 `www`、`www-data` 或 `nginx`）：
```bash
ls -ld public/assets/uploads
chmod 755 public/assets/uploads && chown -R www:www public/assets/uploads   # 按实际用户调整
```

**③ 禁止上传目录执行脚本**（重要）
```bash
# 1Panel / OpenResty：把 deploy/nginx-uploads-snippet.conf 的内容贴进站点配置
# Apache：public/assets/uploads/.htaccess 已内置（禁止脚本、关闭目录列表、nosniff）
```
> 备份参考：`deploy/nginx.conf` 已包含同等规则。

**④ 其他**：`strip_exif`（默认开）会对 JPEG 重新编码以剥离 EXIF/GPS 与潜在载荷；
关闭 `require_https` 仅建议本地调试；上传审计记录在 `push_logs` 表，`action = upload`。

### 10. 视频上传接口（/api/upload_video.php）

与图片接口同源、同一套鉴权，区别在于「接受 mp4/webm、单文件 100MB、返回 `<video>` 片段」。
视频不做像素/EXIF 校验，也**不做服务端转码** —— 请上传浏览器原生支持的封装
（H.264/AAC 的 mp4，或 VP9/Opus 的 webm），否则部分浏览器可能播放不了。

| 项目 | 值 |
|------|-----|
| 地址 | `POST https://ai.xalcy.cn/api/upload_video.php` |
| Content-Type | `multipart/form-data` |
| 单文件上限 | 默认 **100 MB**（`config.php` → `video.max_size`） |
| 单次个数 | 默认 **5 个**（`video.max_files`） |
| 支持类型 | `video/mp4`、`video/webm` |
| 存放目录 | `public/assets/videos/`（**不存在时自动创建**，权限 0755） |
| URL 前缀 | `/assets/videos/`（`video.url_path`） |

> 仅需 `video` 段可选配；**不配置也能用**，代码内置了上述默认值。

**鉴权**：与图片接口完全一致（X-Api-Key + X-Timestamp + X-Signature，或 Bearer）。
签名对象同样是「文件内容 sha256 摘要」（multipart 下 php://input 为空，无法签原始请求体）。

**成功响应（HTTP 200）**

```json
{
  "success": true,
  "count": 1,
  "failed": 0,
  "files": [
    {
      "name": "ai-demo-2026-01.mp4",
      "path": "/assets/videos/ai-demo-2026-01.mp4",
      "url":  "https://ai.xalcy.cn/assets/videos/ai-demo-2026-01.mp4",
      "html": "<video controls preload=\"metadata\" src=\"https://ai.xalcy.cn/assets/videos/ai-demo-2026-01.mp4\"></video>",
      "markdown": "<video controls preload=\"metadata\" src=\"...\"></video>",
      "size": 18342912,
      "mime": "video/mp4",
      "sha256": "9f2c1b..."
    }
  ],
  "errors": []
}
```

返回里 `html` / `markdown` 都是可直接粘贴进文章正文（内容字段支持 HTML）的 `<video>` 片段。

**错误码**：与图片接口一致（`405/403/401/413/400`，`no_file` / `too_many_files` / `upload_failed` 等），
单文件失败常见原因：超过大小上限、不支持的视频格式、文件内容为空、上传目录不可写。

**CLI 上传（图片/视频共用一个脚本，用 `--type=video` 切换）**

```bash
php scripts/upload_client.php demo.mp4 --type=video --name=ai-demo-2026
php scripts/upload_client.php demo.mp4 --type=video --json
```

**Python（AI 自动化流程）**

```python
import os, hashlib, hmac, time, requests
API_URL    = "https://ai.xalcy.cn/api/upload_video.php"
API_KEY    = os.environ["AIXALCY_API_KEY"]
API_SECRET = os.environ["AIXALCY_API_SECRET"]

def upload_videos(paths, name=""):
    ts = str(int(time.time()))
    digests = [hashlib.sha256(open(p, "rb").read()).hexdigest() for p in paths]
    base = ts + "." + ".".join(digests)
    sig  = hmac.new(API_SECRET.encode(), base.encode(), hashlib.sha256).hexdigest()
    files = [("files[]", (os.path.basename(p), open(p, "rb"), "video/mp4")) for p in paths]
    data = {"name": name} if name else {}
    resp = requests.post(API_URL, timeout=300, files=files, data=data,
                         headers={"X-Api-Key": API_KEY, "X-Timestamp": ts, "X-Signature": sig})
    resp.raise_for_status()
    return resp.json()

res = upload_videos(["demo.mp4"], name="ai-demo-2026")
for f in res["files"]:
    print(f["html"])   # 直接写进文章正文
```

**与 AI 自动入库的配合**：① 上传视频拿 `<video>` 片段 → ② 写进文章 `content`
→ ③ `POST /api/push.php` 入库（同套 Key/Secret）→ ④ 前台即可播放。
后台「媒体库」顶部可切换「图片 / 视频」标签查看或删除已传视频。

---

## 四、后台功能

| 模块 | 路径 | 说明 |
|------|------|------|
| 登录 | `/admin/login.php` | 会话登录，密码 `password_hash` 存储 |
| 仪表盘 | `/admin/index.php` | 统计概览 + 最近文章 + 最近推送 |
| 文章 | `/admin/articles.php` | 列表/搜索/筛选/删除（CSRF 保护） |
| 编辑 | `/admin/article_form.php` | 新建/编辑，支持分类、标签、状态、发布时间、`source_id` |
| 媒体库 | `/admin/uploads.php` | 图片/视频浏览（缩略图/体积）、复制链接、直接上传、删除；与上传接口共用同一目录 |
| 分类 | `/admin/categories.php` | 增删改查 |
| 标签 | `/admin/tags.php` | 增删改查（含文章计数） |
| 令牌 | `/admin/api_tokens.php` | 生成/启用/停用/删除推送令牌，密钥仅显示一次 |

---

## 五、安全建议

1. **HTTPS 强制**：生产在 `config.php` 将 `api.require_https` 设为 `true`（默认已开），并配置全站 HTTPS。
2. **密钥保管**：`API_SECRET` 等同写权限，仅存于调用方与数据库；泄露立即在后台吊销令牌。
3. **最小权限**：MySQL 账号仅授予该库 `SELECT/INSERT/UPDATE/DELETE`；Web 进程不可写 `config.php`。
4. **目录隔离**：确保 `DocumentRoot` 为 `public/`，`src/`、`config.php`、`sql/` 不在 web 可访问范围。
5. **改默认口令**：首次登录后立即修改管理员密码，并删除/停用测试令牌。
6. **审计**：所有推送调用记录在 `push_logs` 表，可定期巡查异常 IP/频率。
7. **内容可信**：文章正文按可信 HTML 处理（后台与接口写入），若接受第三方内容请增加净化（如 HTMLPurifier）。

---

## 六、本地联调（无服务器时）

```bash
# 1. 启动内置 PHP 服务(需本机装 PHP 8)
cd public && php -S 127.0.0.1:8080
# 2. 浏览器访问 http://127.0.0.1:8080/
# 3. 语法自检
find . -name '*.php' -exec php -l {} \;
```
> 本地用 HTTP 测试推送接口时，请把 `config.php` 中 `api.require_https` 临时设为 `false`，否则会返回 403（生产环境务必保持 `true`）。

---

## 七、1Panel 面板部署（PHP 跑在 Docker 容器中）

> 适用：服务器用 1Panel 运维、PHP 通过「运行环境」以容器方式提供（例如容器名 `php8-fpm`）。
> **宿主机上没有 `php` 命令**，所以 `php scripts/setup.php` 会报 `Command 'php' not found`，
> 必须在 PHP 容器内执行。

### 先理解三条路径的差异

| 位置 | 实际路径 |
|------|----------|
| 宿主机项目目录 | `/opt/1panel/www/sites/ai.xalcy.cn/index` |
| PHP 容器内同一目录 | `/www/sites/ai.xalcy.cn/index`（1Panel 把 `/opt/1panel/www` 挂载为 `/www`） |
| 数据库 host | 容器内的 `127.0.0.1` 指的是**容器自己**，不是宿主机，必须换成数据库容器名或网关 IP |

### 方式一：用配套脚本（推荐）

```bash
cd /opt/1panel/www/sites/ai.xalcy.cn/index

bash deploy/1panel.sh doctor     # ① 自检：容器 / 路径映射 / 数据库连通性 / 目录暴露
bash deploy/1panel.sh db-import  # ② 建库并导入 sql/schema.sql（会提示输入 MySQL root 密码）
bash deploy/1panel.sh setup --user=admin --pass='你的强密码'   # ③ 建管理员 + 首个推送令牌
```

其它子命令：

```bash
bash deploy/1panel.sh php -l src/auth.php   # 在容器内做语法检查
bash deploy/1panel.sh php -m                # 查看容器内 PHP 扩展
bash deploy/1panel.sh sh                    # 进入 PHP 容器
bash deploy/1panel.sh list                  # 查看相关容器
```

脚本会自动：优先挑选 `php8*` 容器、从 `docker inspect` 的实际挂载推导容器内路径。
需要手工指定时：

```bash
PHP_CONTAINER=php8-fpm MYSQL_CONTAINER=mysql \
PROJECT_DIR=/opt/1panel/www/sites/ai.xalcy.cn/index \
bash deploy/1panel.sh doctor
```

### 方式二：手工命令

```bash
# 1) 找到容器与真实挂载映射
docker ps --format '{{.Names}}\t{{.Image}}' | grep -iE 'php|mysql'
docker inspect php8-fpm --format '{{range .Mounts}}{{.Source}} => {{.Destination}}{{"\n"}}{{end}}'

# 2) 确认容器内能看到项目（应列出 config.php / src / sql 等）
docker exec php8-fpm ls /www/sites/ai.xalcy.cn/index

# 3) 执行初始化
docker exec -it php8-fpm php /www/sites/ai.xalcy.cn/index/scripts/setup.php --user=admin --pass='你的强密码'

#    交互式输入（密码不留 shell 历史，更安全）
docker exec -it php8-fpm php /www/sites/ai.xalcy.cn/index/scripts/setup.php

#    重置已有管理员密码
docker exec -it php8-fpm php /www/sites/ai.xalcy.cn/index/scripts/setup.php --user=admin --pass='新密码' --reset
```

### 数据库 host 怎么填（最容易踩的坑）

```bash
docker exec php8-fpm php /www/sites/ai.xalcy.cn/index/scripts/check_db.php mysql
```

输出示例：

```
  [成功] mysql          连接正常
  [失败] 127.0.0.1      Connection refused
>>> 请把 config.php 的 db.host 改为: mysql
```

把结论填回 `config.php` 的 `db.host`，再跑一次直到显示「配置正确」。该脚本同时会校验 8 张表是否齐全、列出管理员/令牌/文章数量。

> `scripts/check_db.php` 会自动追加候选 host：`config.php` 当前值、命令参数、`mysql`、`mariadb`、
> `host.docker.internal`，以及从 `/proc/net/route` 解析出的 Docker 网关（如 `172.18.0.1`）。

### 方式三：在宿主机装 PHP CLI（仅为跑脚本）

```bash
apt update && apt install -y php8.3-cli php8.3-mysql
cd /opt/1panel/www/sites/ai.xalcy.cn/index
php scripts/setup.php --user=admin --pass='你的强密码'
```
宿主机上 `127.0.0.1:3306` 可通（1Panel 会把 MySQL 端口映射到宿主机），配置通常无需改动。
Web 请求仍由容器内的 `php8-fpm` 处理，与本次安装互不影响。

### 必做：把「运行目录」改为 /public

`/opt/1panel/www/sites/ai.xalcy.cn/index` 就是该站点的**网站根目录**。项目若直接放在这里，
`https://ai.xalcy.cn/sql/schema.sql`、`/README.md` 等会被公网直接下载。

**修复**：1Panel → 网站 → ai.xalcy.cn → 设置 → 网站目录 → **运行目录选 `/public`** → 保存并重载。

验证（期望全部为 403 / 404）：

```bash
for u in /sql/schema.sql /README.md /config.php /src/db.php; do
  printf '%s -> %s\n' "$u" "$(curl -sk -o /dev/null -w '%{http_code}' https://ai.xalcy.cn$u)"
done
```

说明两点：
- 本项目 `public/*` 通过 `../src/bootstrap.php` 相对定位，`APP_ROOT` 会自动等于项目根，
  因此把运行目录设为 `public` **不需要改动任何代码**，URL 结构也保持不变。
- `deploy/nginx.conf` 是给**裸装 Nginx 的服务器**用的（含 `fastcgi_pass unix:...sock` 写法）；
  1Panel 由面板自动生成 OpenResty 配置，**不要**手工套用该文件。
  `public/.htaccess` 仅对 Apache 生效，OpenResty 下不读取。
