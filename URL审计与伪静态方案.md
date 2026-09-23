# URL 结构审计与伪静态（漂亮 URL）重写方案

> 站点：ai.xalcy.cn（PHP8 + MySQL，1Panel/Docker，web 根 `public/`）  
> 审计范围：前台 7 类入口、URL 助手、canonical/OG、旧链 301、后台开关、Nginx/Apache 重写规则  
> 结论先行：URL 形态已由后台 `enable_pretty_url` 开关完整驱动；**重写规则与旧链 301 存在两处真实缺陷**，已在本方案中修复并落到代码与配置。

---

## 1. 当前 URL 结构审计

出站链接由 `pretty_url_enabled()`（读 `options.enable_pretty_url`）统一控制，涉及 `src/functions.php` 中的 6 个助手：

| 资源 | 伪静态【开】 | 伪静态【关】 | 命中脚本 | 读取参数 |
|------|--------------|--------------|----------|----------|
| 首页 | `/` | `/` | `index.php` | — |
| 文章 | `/article/<slug>` | `/article.php?slug=<slug>` | `article.php` | `slug` / `id` |
| 分类 | `/category/<slug>` | `/category.php?slug=<slug>` | `category.php` | `slug` |
| 标签 | `/tag/<slug>` | `/tag.php?slug=<slug>` | `tag.php` | `slug` |
| 搜索 | `/search?q=<q>` | `/search.php?q=<q>` | `search.php` | `q` |
| 专题列表 | `/topics` | `/topics.php` | `topics.php` | — |
| 专题详情 | `/topic/<slug>` | `/topic.php?slug=<slug>` | `topic.php` | `slug` / `id` |
| 资源/接口 | `assets/`、`/api/*`、`/admin/*`、`sitemap.php`、`llms.php`、`robots.txt` | 同左 | — | — |

**审计结论：**
- 形态切换点集中在 `functions.php`，单一开关即可全局切换，**设计正确**。
- `canonical` / `og:url` / `sitemap.xml` / `llms.txt` 全部调用上述助手，**已随开关同步**，无 canonical 与出站链接不一致的问题。
- 后台开关已落地：`public/admin/settings.php` 的「启用伪静态（漂亮 URL）」下拉直接写 `options.enable_pretty_url`，保存即时生效，**无需改服务器配置或重启**。

---

## 2. 发现的两处真实缺陷（已修复）

### 缺陷 A【高优先级】Nginx 重写丢弃原查询串（分页/搜索失效）
原规则：`rewrite ^/category/([^/]+)/?$ /category.php?slug=$1 last;`
- 替换串含 `?` 时，Nginx 默认**不再追加**原查询串。
- 后果：漂亮 URL 下 `/category/foo?page=2` → 实际只收到 `slug=foo`，**分页永远停在第 1 页**；`/search?q=关键词` 经 `^/search/?$ /search.php` 后 `q` 同样丢失。
- 修复：替换为 `...?slug=$1&__pu=1&$args`，用 `$args` 显式保留原查询串。Apache 侧 `[QSA]` 本就能保留，无需改动语义。

### 缺陷 B【高优先级】旧链 301 在 Apache 下会死循环
原判定：`if (strpos($_SERVER['REQUEST_URI'], 'article.php') !== false) redirect(...)`
- Nginx：`REQUEST_URI` 恒为客户端原始串（`/article/foo`），不含 `.php`，**侥幸不环**。
- Apache：`mod_rewrite` 内部重写后 `REQUEST_URI` 变为重写后的 `/article.php?slug=foo`，**再次命中判定 → 301 → 重写 → 301 … 死循环**。
- 根因：用「请求路径里是否含 `.php`」来识别旧链，在两种服务器上语义不一致。
- 修复：重写规则注入一次性标记 `__pu=1`；PHP 端用 `canonicalize_to_pretty()` 改为「`__pu` 存在即视为漂亮请求、不跳；否则直接访问 `.php` 才跳」。**任一服务器都不会成环**，且对 Nginx 行为完全不变。

---

## 3. 旧链 301 规范化方案（新）

新增助手 `src/functions.php::canonicalize_to_pretty(string $prettyUrl)`：

```php
function canonicalize_to_pretty(string $prettyUrl): void
{
    if (!pretty_url_enabled()) return;          // 关：不跳，canonical 走 .php 形态
    if (isset($_GET['__pu'])) return;           // 经重写进来的漂亮请求：不跳
    if (isset($_SERVER['REQUEST_URI'])
        && strpos((string)$_SERVER['REQUEST_URI'], '.php') !== false) {
        redirect($prettyUrl, 301);               // 直接访问旧 .php 链接：301 到漂亮 URL
    }
}
```

各控制器原先各自的 `if(pretty_url_enabled() && strpos(REQUEST_URI,'xxx.php')...)` 块已替换为对 `canonicalize_to_pretty(...)` 的调用（topic.php 因需先校验 slug 存在，保留原结构仅补 `!isset($_GET['__pu'])` 守卫）。

**行为矩阵（修复后）：**

| 场景 | 伪静态【开】 | 伪静态【关】 |
|------|--------------|--------------|
| 访问漂亮 URL `/article/foo` | 200（重写注入 `__pu`，不跳） | 200（canonical=`.php` 形态，不跳） |
| 访问旧链 `/article.php?slug=foo` | 301 → `/article/foo` | 200（不跳，canonical=`.php` 形态） |
| 分页 `/category/foo?page=2` | 200 且 `page=2` 生效 | 同左 |
| Apache 漂亮 URL | 200（无环） | 200（无环） |

---

## 4. Nginx 重写规则（最终版，落于 `deploy/nginx.conf`）

```nginx
# ---- 伪静态（漂亮 URL）重写：开启后台"启用伪静态"后生效 ----
# 重写规则始终启用；是否输出漂亮链接由后台 enable_pretty_url 决定（应用层控制，无需改本文件）。
# 注入 __pu=1 标记供 PHP 区分"重写后的漂亮请求"与"直接访问的旧链接(.php)"，避免 Nginx/Apache 下 301 规范化互跳成环。
# 末尾 &$args 保留原查询串（分页 ?page= / 搜索 ?q= 等），否则 Nginx 重写会丢弃这些参数。
rewrite ^/article/([^/]+)/?$  /article.php?slug=$1&__pu=1&$args  last;
rewrite ^/category/([^/]+)/?$ /category.php?slug=$1&__pu=1&$args last;
rewrite ^/tag/([^/]+)/?$      /tag.php?slug=$1&__pu=1&$args      last;
rewrite ^/search/?$           /search.php?__pu=1&$args           last;
rewrite ^/topics/?$           /topics.php?__pu=1&$args           last;
rewrite ^/topic/([^/]+)/?$    /topic.php?slug=$1&__pu=1&$args    last;
```

> 校验：`nginx -t && systemctl reload nginx`。规则位于 `server` 块内、`location /` 之前；`last` 触发内部重定向后由 `location ~ \.php$` 接管。  
> `assets/uploads|videos|branding` 等目录的禁脚本规则保持不变。

## 5. Apache 重写规则（最终版，落于 `deploy/apache.htaccess` 与 `public/.htaccess`）

```apache
# ---- 伪静态（漂亮 URL）重写：开启后台"启用伪静态"后生效 ----
RewriteEngine On
RewriteRule ^article/([^/]+)/?$ article.php?slug=$1&__pu=1 [QSA,L]
RewriteRule ^category/([^/]+)/?$ category.php?slug=$1&__pu=1 [QSA,L]
RewriteRule ^tag/([^/]+)/?$ tag.php?slug=$1&__pu=1 [QSA,L]
RewriteRule ^search/?$ search.php?__pu=1 [QSA,L]
RewriteRule ^topics/?$ topics.php?__pu=1 [QSA,L]
RewriteRule ^topic/([^/]+)/?$ topic.php?slug=$1&__pu=1 [QSA,L]
# 非真实文件/目录交由 index.php（兜底，置于上方规则之后）
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

> `[QSA]` 自动保留原查询串；`&__pu=1` 标记配合 PHP 端避免 301 成环。需 `mod_rewrite` 已启用、DocumentRoot 指向 `public/`。

---

## 6. 后台开关（已具备，无需新增）

- 位置：`后台 → 站点配置 → URL 与伪静态 → 启用伪静态（漂亮 URL）`。
- 存储：`options.enable_pretty_url`（0/1），`set_option()` 写入后立即生效。
- 联动：开关切到【开】→ 全站出站链接变漂亮形态 + canonical/OG/sitemap 同步 + 旧链自动 301；切到【关】→ 全部回退 `.php` 形态，已部署的重写规则仍在但不产生漂亮链接，**无需改动任何服务器配置**。

---

## 7. 已落地的代码/配置改动清单

| 文件 | 改动 |
|------|------|
| `src/functions.php` | 新增 `canonicalize_to_pretty()` 助手（统一旧链 301 逻辑） |
| `public/article.php` | 旧链 301 块替换为 `canonicalize_to_pretty(article_url(...))` |
| `public/category.php` | 同上 |
| `public/tag.php` | 同上 |
| `public/search.php` | 同上 |
| `public/topics.php` | 同上 |
| `public/topic.php` | 补 `!isset($_GET['__pu'])` 守卫（保留 slug 校验） |
| `deploy/nginx.conf` | 重写规则加 `__pu=1` 标记与 `&$args`（修缺陷 A/B） |
| `deploy/apache.htaccess` | 重写规则加 `&__pu=1` 标记（修缺陷 B） |
| `public/.htaccess` | 同步补上伪静态重写规则（此前缺失） |

---

## 8. 验证步骤（部署后）

1. **关→开冒烟**：后台关伪静态，访问 `/article.php?slug=xxx` → 200；开伪静态后同地址应 **301** 到 `/article/xxx`，且 `/article/xxx` 本身 **200**。
2. **分页**：`/category/<slug>?page=2` 在漂亮 URL 下应停在第 2 页（修缺陷 A 验证）。
3. **搜索**：`/search?q=关键词` 应返回结果（修缺陷 A 验证）。
4. **Apache 无环**：若用 Apache，直接访问 `/article.php?slug=xxx` 应 301 到 `/article/xxx` 且**不再循环**（修缺陷 B 验证）。
5. **canonical 一致**：查看源码 `<link rel="canonical">` 与当前访问形态一致；`/sitemap.php`、`/llms.php` 输出与开关同步。
6. **运行时语法**：`docker exec php8-fpm php -l <各改动文件>`。

---

## 9. 可选增强（非必须，按需采纳）

- **反向 301（伪静态关时）**：若希望关掉伪静态后 `/article/xxx` 也 301 回 `/article.php?slug=xxx`（彻底消除双 URL），可在 `canonicalize_to_pretty()` 内增加「伪静态关且命中漂亮路径」分支，但需控制器能识别自己是被重写进来的（同样用 `__pu` 反向判断）。
- **www / 非 www 统一**：在 Nginx `server` 块加 `if ($host = 'www.ai.xalcy.cn') { return 301 https://ai.xalcy.cn$request_uri; }`，与伪静态无关但建议一并规范。
- **末尾斜杠**：当前 `([^/]+)/?$` 已兼容 `/article/xxx/` 与 `/article/xxx`，无需额外处理。
