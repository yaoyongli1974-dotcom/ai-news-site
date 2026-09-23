<?php
/**
 * 专题详情页 —— 展示专题头部 + 关联文章列表。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';
require_once SRC_DIR . '/topic_service.php';

// 伪静态开启时，将直接访问的 topic.php?slug= 301 到 /topic/<slug>
// （用 __pu 标记判断，避免与 Nginx/Apache 内部重写形成环）
if (pretty_url_enabled()
    && !isset($_GET['__pu'])
    && isset($_SERVER['REQUEST_URI'])
    && strpos($_SERVER['REQUEST_URI'], 'topic.php') !== false) {
    $s = trim($_GET['slug'] ?? '');
    if ($s !== '') {
        $tk = get_topic_by_slug($s);
        if ($tk) {
            redirect(topic_url($tk['slug']), 301);
        }
    }
}

$slug = trim($_GET['slug'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$topic = $slug !== '' ? get_topic_by_slug($slug) : ($id > 0 ? get_topic_by_id($id) : null);

if (!$topic || $topic['status'] !== 'published') {
    http_response_code(404);
    render_page('专题未找到', '<p class="empty">抱歉，未找到该专题或该专题尚未发布。</p>');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();
// 仅统计已发布条目用于分页（与列表一致）
$totalPublished = (int)db_fetch(
    "SELECT COUNT(*) c FROM `topic_articles` ta JOIN `articles` a ON a.`id`=ta.`article_id`
     WHERE ta.`topic_id`=? AND a.`status`='published'",
    [$topic['id']]
)['c'];
$p = paginate($totalPublished, $per, $page);

$articles = get_topic_articles((int)$topic['id'], $p['per_page'], $p['offset'], true);

// 结构化数据：CollectionPage + 内嵌 ItemList（专题文章）+ BreadcrumbList
$itemEls = [];
foreach ($articles as $i => $a) {
    $itemEls[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'url'      => article_url($a['slug']),
        'name'     => $a['title'],
    ];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => $topic['title'],
    'url'      => topic_url($topic['slug']),
    'description' => $topic['summary'] ?: '',
    'mainEntity' => [
        '@type'           => 'ItemList',
        'itemListElement' => $itemEls,
    ],
    'breadcrumb' => [
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '专题', 'item' => topics_url()],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $topic['title'], 'item' => topic_url($topic['slug'])],
        ],
    ],
];

$cover = $topic['cover_image']
    ? '<img class="post-cover-lg" src="' . attr($topic['cover_image']) . '" alt="' . attr($topic['title']) . '">'
    : '';

$body = '<article class="post-detail topic-detail">'
    . $cover
    . '<h1 class="post-title">' . e($topic['title']) . '</h1>'
    . ($topic['summary'] ? '<p class="topic-lead">' . e($topic['summary']) . '</p>' : '')
    . ($topic['description'] ? '<div class="post-content">' . $topic['description'] . '</div>' : '')
    . '<p class="muted topic-stat">本专题共 ' . $totalPublished . ' 篇内容</p>'
    . '</article>';

$body .= article_cards($articles);
$body .= render_pagination($p);

render_page($topic['title'], $body, [
    'description' => $topic['summary'] ?: ('AI 资讯汇专题：' . $topic['title']),
    'og_image'    => $topic['cover_image'],
    'og_type'     => 'website',
    'canonical'   => topic_url($topic['slug']),
    'breadcrumb'  => [
        ['name' => '首页', 'url' => url('/')],
        ['name' => '专题', 'url' => topics_url()],
        ['name' => $topic['title'], 'url' => topic_url($topic['slug'])],
    ],
    'json_ld'     => $jsonLd,
]);
