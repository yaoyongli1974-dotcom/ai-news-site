<?php
/**
 * 标签列表页。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$slug = $_GET['slug'] ?? '';
$tag  = $slug !== '' ? get_tag_by_slug($slug) : null;
if (!$tag) {
    http_response_code(404);
    render_page('标签未找到', '<p class="empty">抱歉，未找到该标签。</p>');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();
$total = (int)db_fetch(
    "SELECT COUNT(*) AS c FROM `article_tags` at
     JOIN `articles` a ON a.`id`=at.`article_id`
     WHERE at.`tag_id`=? AND a.`status`='published'",
    [$tag['id']]
)['c'];
$p = paginate($total, $per, $page);

$articles = db_fetch_all(
    "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
     FROM `article_tags` at
     JOIN `articles` a ON a.`id`=at.`article_id`
     LEFT JOIN `categories` c ON c.`id`=a.`category_id`
     WHERE at.`tag_id`=? AND a.`status`='published'
     ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
     LIMIT ? OFFSET ?",
    [$tag['id'], $p['per_page'], $p['offset']]
);

$body = '<h1 class="page-title">标签：' . e($tag['name']) . '</h1>';
$body .= article_cards($articles);
$body .= render_pagination($p);

render_page('标签：' . $tag['name'], $body, [
    'description' => '标签「' . $tag['name'] . '」下的相关文章',
    'canonical'   => url('/tag.php?slug=' . rawurlencode($tag['slug'])),
]);
