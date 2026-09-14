<?php
/**
 * 搜索页。支持标题/摘要/正文关键词搜索。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = posts_per_page();

$articles = [];
$total = 0;
if ($q !== '') {
    $like = '%' . $q . '%';
    $total = (int)db_fetch(
        "SELECT COUNT(*) AS c FROM `articles`
         WHERE `status`='published' AND (`title` LIKE ? OR `summary` LIKE ? OR `content` LIKE ?)",
        [$like, $like, $like]
    )['c'];
    $p = paginate($total, $per, $page);
    $articles = db_fetch_all(
        "SELECT a.*, c.`name` AS category_name, c.`slug` AS category_slug
         FROM `articles` a LEFT JOIN `categories` c ON c.`id`=a.`category_id`
         WHERE a.`status`='published' AND (a.`title` LIKE ? OR a.`summary` LIKE ? OR a.`content` LIKE ?)
         ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
         LIMIT ? OFFSET ?",
        [$like, $like, $like, $p['per_page'], $p['offset']]
    );
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

render_page($q !== '' ? '搜索：' . $q : '搜索', $body, [
    'description' => $q !== '' ? '关于「' . $q . '」的搜索结果' : get_option('site_description', ''),
]);
