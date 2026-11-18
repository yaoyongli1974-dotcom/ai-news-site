<?php
/**
 * 搜索页。支持标题/摘要/正文关键词搜索。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$q = trim($_GET['q'] ?? '');

// 伪静态开启时，旧链接 /search.php?q= 301 到 /search?q=（__pu 标记避免与重写互跳成环）
canonicalize_to_pretty(search_url($q));

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();

$articles = [];
$total = 0;
if ($q !== '') {
    // 优先 FULLTEXT（ngram 分词，支持中文，见 sql/search_fulltext_migration.sql）；
    // 索引不存在（MariaDB 或未跑迁移）时自动回退 LIKE。
    $useFulltext = false;
    try {
        $useFulltext = !empty(db_fetch_all("SHOW INDEX FROM `articles` WHERE `Key_name`='ft_search'"));
    } catch (PDOException $e) {
        $useFulltext = false;
    }

    if ($useFulltext) {
        $match = "MATCH(a.`title`, a.`summary`, a.`content`) AGAINST (? IN NATURAL LANGUAGE MODE)";
        $total = (int)db_fetch(
            "SELECT COUNT(*) AS c FROM `articles` a WHERE a.`status`='published' AND {$match}",
            [$q]
        )['c'];
        $p = paginate($total, $per, $page);
        $articles = db_fetch_all(
            "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
             FROM `articles` a LEFT JOIN `categories` c ON c.`id`=a.`category_id`
             WHERE a.`status`='published' AND {$match}
             ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
             LIMIT ? OFFSET ?",
            [$q, $p['per_page'], $p['offset']]
        );
    } else {
        // 转义 LIKE 通配符，避免用户输入中的 %/_ 扩大匹配范围
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $total = (int)db_fetch(
            "SELECT COUNT(*) AS c FROM `articles`
             WHERE `status`='published' AND (`title` LIKE ? ESCAPE '\\\\' OR `summary` LIKE ? ESCAPE '\\\\' OR `content` LIKE ? ESCAPE '\\\\')",
            [$like, $like, $like]
        )['c'];
        $p = paginate($total, $per, $page);
        $articles = db_fetch_all(
            "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
             FROM `articles` a LEFT JOIN `categories` c ON c.`id`=a.`category_id`
             WHERE a.`status`='published' AND (a.`title` LIKE ? ESCAPE '\\\\' OR a.`summary` LIKE ? ESCAPE '\\\\' OR a.`content` LIKE ? ESCAPE '\\\\')
             ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
             LIMIT ? OFFSET ?",
            [$like, $like, $like, $p['per_page'], $p['offset']]
        );
    }
} else {
    $p = paginate(0, $per, 1);
}

$body = '<h1 class="page-title">搜索' . ($q !== '' ? '：' . e($q) : '') . '</h1>';
if ($q !== '') {
    $body .= '<p class="muted">共找到 ' . $total . ' 篇相关文章</p>';
    $body .= article_cards($articles);
    $body .= render_pagination($p);
} else {
    $body .= '<p class="muted">请输入关键词进行搜索。</p>';
}

$crumbs = [
    ['name' => '首页', 'url' => url('/')],
    [
        'name' => $q !== '' ? '搜索：' . $q : '搜索',
        'url'  => search_url($q),
    ],
];

$jsonLd = [];
if ($q !== '') {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type'    => 'SearchResultsPage',
        'url'      => search_url($q),
    ];
}

render_page($q !== '' ? '搜索：' . $q : '搜索', $body, [
    'description' => $q !== '' ? '关于「' . $q . '」的搜索结果' : get_option('site_description', ''),
    'robots'      => 'noindex,follow',
    'breadcrumb'  => $crumbs,
    'json_ld'     => $jsonLd,
]);
