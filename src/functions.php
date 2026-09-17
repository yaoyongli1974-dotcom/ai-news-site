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
    $qs = $_GET;
    unset($qs['page']);
    $extra = $base_query !== '' ? '&' . ltrim($base_query, '&') : '';
    $base = '?' . http_build_query($qs) . $extra;
    if ($base === '?') $base = '?';
    $base .= '&';
    $html = '<nav class="pagination" aria-label="分页">';
    if ($p['page'] > 1) {
        $html .= '<a href="' . attr($base . '&page=' . ($p['page'] - 1)) . '">« 上一页</a>';
    }
    $start = max(1, $p['page'] - 2);
    $end = min($p['total_pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $p['page'] ? ' class="active"' : '';
        $html .= '<a' . $active . ' href="' . attr($base . '&page=' . $i) . '">' . $i . '</a>';
    }
    if ($p['page'] < $p['total_pages']) {
        $html .= '<a href="' . attr($base . '&page=' . ($p['page'] + 1)) . '">下一页 »</a>';
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
