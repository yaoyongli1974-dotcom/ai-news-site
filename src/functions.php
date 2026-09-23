<?php
/**
 * 通用函数库 —— 转义、slug、URL、CSRF、分页、选项读取等。
 */
declare(strict_types=1);

// ---------------- 输出转义 ----------------
/** HTML 上下文转义 */
function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** 属性上下文转义(用于 value="") */
function attr(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---------------- URL ----------------
function base_url(): string
{
    return APP_URL;
}

/** 站内相对路径拼成完整 URL，如 url('/article.php?slug=xx') */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return APP_URL . ($path !== '' ? '/' . $path : '/');
}

/**
 * 静态资源 URL，附带文件修改时间作为版本号。
 * 用途：样式/脚本一改 URL 就变，浏览器不会再用旧缓存（否则用户看不到更新）。
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_DIR . '/' . $path;
    $ver  = is_file($file) ? filemtime($file) : false;
    return url('/' . $path) . ($ver ? '?v=' . $ver : '');
}

function redirect(string $location, int $code = 302): void
{
    if (headers_sent()) {
        echo '<meta http-equiv="refresh" content="0;url=' . attr($location) . '">';
        exit;
    }
    header('Location: ' . $location, true, $code);
    exit;
}

// ---------------- 伪静态 / 漂亮 URL ----------------
/**
 * 是否启用伪静态（漂亮 URL）。由后台"启用伪静态"开关控制（options 表 enable_pretty_url）。
 * 开启后出站链接变为 /article/<slug> 等形态，需服务器部署对应重写规则。
 */
function pretty_url_enabled(): bool
{
    return get_option('enable_pretty_url', '0') === '1';
}

/** 文章 URL：伪静态返回 /article/<slug>，否则 /article.php?slug=<slug> */
function article_url(string $slug): string
{
    return pretty_url_enabled()
        ? url('/article/' . rawurlencode($slug))
        : url('/article.php?slug=' . rawurlencode($slug));
}

/** 分类 URL */
function category_url(string $slug): string
{
    return pretty_url_enabled()
        ? url('/category/' . rawurlencode($slug))
        : url('/category.php?slug=' . rawurlencode($slug));
}

/** 标签 URL */
function tag_url(string $slug): string
{
    return pretty_url_enabled()
        ? url('/tag/' . rawurlencode($slug))
        : url('/tag.php?slug=' . rawurlencode($slug));
}

/** 搜索 URL：伪静态仅去掉 .php，查询词仍以 ?q= 传递 */
function search_url(string $q = ''): string
{
    $q = trim($q);
    $base = pretty_url_enabled() ? '/search' : '/search.php';
    if ($q === '') {
        return url($base);
    }
    return url($base . '?q=' . rawurlencode($q));
}

/** 专题列表 URL：/topics 或 /topics.php */
function topics_url(): string
{
    return pretty_url_enabled() ? url('/topics') : url('/topics.php');
}

/** 专题 URL：伪静态返回 /topic/<slug>，否则 /topic.php?slug=<slug> */
function topic_url(string $slug): string
{
    return pretty_url_enabled()
        ? url('/topic/' . rawurlencode($slug))
        : url('/topic.php?slug=' . rawurlencode($slug));
}

// ---------------- 旧链 301 规范化 ----------------
/**
 * 伪静态开启时，将"直接访问的旧链接"(.php?slug= / .php?q= / .php) 301 跳转到漂亮 URL，
 * 集中权重、避免重复内容。
 *
 * 重写规则在内部重写时会注入 __pu=1 标记。据此可区分两种到达 .php 脚本的情形：
 *   1) 经重写规则进来的漂亮请求（/article/<slug> -> article.php?...&__pu=1）：不跳转；
 *   2) 用户/爬虫直接访问旧链接（/article.php?slug=<slug>）：跳转。
 * 如此在 Nginx 与 Apache 下都不会与重写形成重定向环。
 *
 * @param string $prettyUrl 目标漂亮 URL（由 article_url()/category_url() 等生成）
 */
function canonicalize_to_pretty(string $prettyUrl): void
{
    if (!pretty_url_enabled()) {
        return;
    }
    if (isset($_GET['__pu'])) {
        return;
    }
    if (isset($_SERVER['REQUEST_URI'])
        && strpos((string)$_SERVER['REQUEST_URI'], '.php') !== false) {
        redirect($prettyUrl, 301);
    }
}

// ---------------- slug ----------------
/**
 * 生成 URL 友好的 slug。中文会被转写为拼音？(保持简单)这里仅做安全过滤：
 * 中文保留，空格与特殊字符转连字符；长度截断。
 */
function slugify(string $text, int $max = 80): string
{
    $text = trim($text);
    $text = preg_replace('/\s+/u', '-', $text);
    $text = preg_replace('/[^\p{L}\p{N}\-_]+/u', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    $text = mb_strtolower($text, 'UTF-8');
    return mb_substr($text === '' ? 'item' : $text, 0, $max, 'UTF-8');
}

// ---------------- 时间 ----------------
function time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) return e($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
    if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
    if ($diff < 86400 * 30) return floor($diff / 86400) . ' 天前';
    return date('Y-m-d', $ts);
}

/** 把 datetime-local 输入或任意格式规范化为 MySQL DATETIME，空则返回 null */
function to_mysql_datetime(?string $value): ?string
{
    if ($value === null || $value === '') return null;
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

// ---------------- 文本 ----------------
/** 从正文(可能含 HTML/Markdown)生成纯文本摘要 */
function make_excerpt(string $content, int $len = 160): string
{
    $text = preg_replace('#<[^>]+>#', ' ', $content);
    $text = preg_replace('#\s+#', ' ', trim($text));
    $text = mb_substr($text, 0, $len, 'UTF-8');
    return $text;
}

// ---------------- 站点选项 ----------------
/** @var array<string,string>|null $__options_cache */
$__options_cache = null;

function get_option(string $key, string $default = ''): string
{
    global $__options_cache;
    if ($__options_cache === null) {
        $rows = db_fetch_all("SELECT `key`, `value` FROM `options`");
        $__options_cache = [];
        foreach ($rows as $r) {
            $__options_cache[$r['key']] = (string)$r['value'];
        }
    }
    return $__options_cache[$key] ?? $default;
}

function set_option(string $key, string $value): void
{
    global $__options_cache;
    db_exec(
        "INSERT INTO `options` (`key`,`value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        [$key, $value]
    );
    $__options_cache[$key] = $value;
}

function posts_per_page(): int
{
    return max(1, (int)get_option('posts_per_page', '10'));
}

// ---------------- 分类 / 标签 读取 ----------------
function get_categories(): array
{
    return db_fetch_all("SELECT * FROM `categories` ORDER BY `sort_order` ASC, `id` ASC");
}

function get_tags(int $limit = 30): array
{
    return db_fetch_all(
        "SELECT t.*, COUNT(at.article_id) AS cnt
         FROM `tags` t LEFT JOIN `article_tags` at ON at.tag_id=t.id
         GROUP BY t.id ORDER BY cnt DESC, t.id ASC LIMIT ?",
        [$limit]
    );
}

function get_category_by_slug(string $slug): ?array
{
    return db_fetch("SELECT * FROM `categories` WHERE `slug`=?", [$slug]);
}

function get_tag_by_slug(string $slug): ?array
{
    return db_fetch("SELECT * FROM `tags` WHERE `slug`=?", [$slug]);
}

// ---------------- 分页 ----------------
/**
 * 计算分页参数。返回 ['page','per_page','offset','total_pages']。
 */
function paginate(int $total, int $per_page, int $page): array
{
    $page = max(1, $page);
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $total_pages);
    return [
        'page'         => $page,
        'per_page'     => $per_page,
        'offset'       => ($page - 1) * $per_page,
        'total_pages'  => $total_pages,
        'total'        => $total,
    ];
}

/**
 * 生成分页 HTML (保留当前 query string 中除 page 外的参数)。
 */
function render_pagination(array $p, string $base_query = ''): string
{
    if ($p['total_pages'] <= 1) return '';
    // 基础路径取当前请求路径（去掉查询串），使伪静态下分页链接形态正确（/category/x?page=2）
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    if ($path === '' || $path === false) {
        $path = '/';
    }
    $qs = $_GET;
    unset($qs['page']);
    // 伪静态下路由参数(slug/id)已写入路径，分页仅保留其他数量型 query
    if (pretty_url_enabled()) {
        unset($qs['slug'], $qs['id']);
    }
    $extra = $base_query !== '' ? '&' . ltrim($base_query, '&') : '';
    $qstr = http_build_query($qs);
    $sep = ($qstr !== '' || $extra !== '') ? '?' : '';
    $base = $path . $sep . $qstr . $extra;
    $base .= (strpos($base, '?') === false ? '?' : '&') . 'page=';
    $html = '<nav class="pagination" aria-label="分页">';
    if ($p['page'] > 1) {
        $html .= '<a href="' . attr($base . ($p['page'] - 1)) . '">« 上一页</a>';
    }
    $start = max(1, $p['page'] - 2);
    $end = min($p['total_pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $p['page'] ? ' class="active"' : '';
        $html .= '<a' . $active . ' href="' . attr($base . $i) . '">' . $i . '</a>';
    }
    if ($p['page'] < $p['total_pages']) {
        $html .= '<a href="' . attr($base . ($p['page'] + 1)) . '">下一页 »</a>';
    }
    $html .= '</nav>';
    return $html;
}

// ---------------- 文章工具 ----------------
/**
 * 取得文章携带的标签名数组。
 */
function article_tag_names(int $articleId): array
{
    return db_fetch_all(
        "SELECT t.`name` FROM `tags` t
         JOIN `article_tags` at ON at.tag_id=t.id
         WHERE at.article_id=? ORDER BY t.`name` ASC",
        [$articleId]
    );
}

/**
 * 取得相关文章(同分类或共享标签)，排除自身。
 */
function related_articles(int $articleId, int $categoryId, int $limit = 5): array
{
    $sql = "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug FROM `articles` a
            LEFT JOIN `categories` c ON c.`id`=a.`category_id`
            WHERE a.`status`='published' AND a.`id`<>?
            AND (a.`category_id`<=>? ";
    $params = [$articleId, $categoryId];
    // 共享标签
    $sql .= " OR a.`id` IN (
                SELECT at2.article_id FROM `article_tags` at2
                JOIN `article_tags` at1 ON at1.tag_id=at2.tag_id
                WHERE at1.article_id=?
             ))";
    $params[] = $articleId;
    $sql .= " ORDER BY a.`published_at` DESC LIMIT ?";
    $params[] = $limit;
    return db_fetch_all($sql, $params);
}

// ---------------- 结构化数据 (Schema.org / JSON-LD) ----------------

/**
 * 输出 JSON-LD 结构化数据块，便于搜索引擎与 AI 爬虫理解页面语义。
 * @param array $data Schema.org 关联数组
 * @param bool  $wrap 是否包裹 <script> 标签（默认 true）
 */
function json_ld(array $data, bool $wrap = true): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return '';
    }
    // 防止内容中的 </script> 提前闭合脚本块
    $json = str_replace('<', '\\u003c', $json);
    if (!$wrap) {
        return $json;
    }
    return '<script type="application/ld+json">' . $json . '</script>';
}

/** 日期转 ISO8601（机器可读），失败或为空返回 '' */
function iso8601(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $ts = strtotime($dt);
    return $ts === false ? '' : date('c', $ts);
}

/**
 * 生成面包屑导航（机器可读的有序列表）。
 * @param array $items [['name'=>, 'url'=>], ...] 末项视为当前页
 */
function breadcrumb_html(array $items): string
{
    if (empty($items)) {
        return '';
    }
    $html = '<nav class="breadcrumb" aria-label="面包屑"><ol>';
    $last = count($items) - 1;
    foreach ($items as $i => $it) {
        $name = e($it['name'] ?? '');
        if ($i === $last) {
            $html .= '<li><span aria-current="page">' . $name . '</span></li>';
        } else {
            $html .= '<li><a href="' . attr($it['url'] ?? '#') . '">' . $name . '</a></li>';
        }
    }
    $html .= '</ol></nav>';
    return $html;
}

/** 生成 BreadcrumbList 结构化数据(Schema.org)，与 breadcrumb_html 共用 $items 结构 */
function breadcrumb_ld(array $items): array
{
    $els = [];
    foreach ($items as $i => $it) {
        $els[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $it['name'] ?? '',
            'item'     => $it['url'] ?? '',
        ];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $els];
}
