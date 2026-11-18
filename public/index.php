<?php
/**
 * 前台首页 —— 文章列表 + 分页。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

// 服务器会把未知路径兜底重写到本文件：只有首页本身应返回 200，其余一律真 404，
// 避免任意 URL 都输出首页内容(SEO 重复内容 / AI 爬虫抓到大量假页面)。
$reqPath   = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if (!in_array($reqPath, [$scriptDir . '/', $scriptDir . '/index.php'], true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    require PUBLIC_DIR . '/404.html';
    exit;
}

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
        'url'      => article_url($a['slug']),
        'name'     => $a['title'],
    ];
}
$itemList = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $listEls];

render_page('', $body, [
    'description' => get_option('site_description', ''),
    'canonical'   => url('/'),
    'json_ld'     => $itemList,
]);
