<?php
/**
 * 分类列表页。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$slug = $_GET['slug'] ?? '';
$cat  = $slug !== '' ? get_category_by_slug($slug) : null;
if (!$cat) {
    http_response_code(404);
    render_page('分类未找到', '<p class="empty">抱歉，未找到该分类。</p>');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();
$p = paginate(
    (int)db_fetch("SELECT COUNT(*) AS c FROM `articles` WHERE `category_id`=? AND `status`='published'", [$cat['id']])['c'],
    $per, $page
);

$articles = db_fetch_all(
    "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
     FROM `articles` a LEFT JOIN `categories` c ON c.`id`=a.`category_id`
     WHERE a.`category_id`=? AND a.`status`='published'
     ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
     LIMIT ? OFFSET ?",
    [$cat['id'], $p['per_page'], $p['offset']]
);

$body = '<h1 class="page-title">' . e($cat['name']) . '</h1>';
if ($cat['description']) $body .= '<p class="muted">' . e($cat['description']) . '</p>';
$body .= article_cards($articles);
$body .= render_pagination($p);

render_page($cat['name'], $body, [
    'description' => $cat['description'] ?: get_option('site_description', ''),
    'canonical'   => url('/category.php?slug=' . rawurlencode($cat['slug'])),
]);
