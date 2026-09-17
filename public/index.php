<?php
/**
 * 前台首页 —— 文章列表 + 分页。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();
$offset = ($page - 1) * $per;

$total = (int)db_fetch("SELECT COUNT(*) AS c FROM `articles` WHERE `status`='published'")['c'];
$p = paginate($total, $per, $page);

$articles = db_fetch_all(
    "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
     FROM `articles` a
     LEFT JOIN `categories` c ON c.`id`=a.`category_id`
     WHERE a.`status`='published'
     ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
     LIMIT ? OFFSET ?",
    [$p['per_page'], $p['offset']]
);

$body = '<h1 class="page-title">最新文章</h1>';
$body .= article_cards($articles);
$body .= render_pagination($p);

$listEls = [];
foreach ($articles as $i => $a) {
    $listEls[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'url'      => url('/article.php?slug=' . rawurlencode($a['slug'])),
        'name'     => $a['title'],
    ];
}
$itemList = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $listEls];

render_page('', $body, [
    'description' => get_option('site_description', ''),
    'canonical'   => url('/'),
    'json_ld'     => $itemList,
]);
