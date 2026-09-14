<?php
/**
 * 文章详情页。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';

$slug = $_GET['slug'] ?? '';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($slug !== '') {
    $article = db_fetch("SELECT * FROM `articles` WHERE `slug`=? AND `status`='published'", [$slug]);
} elseif ($id > 0) {
    $article = db_fetch("SELECT * FROM `articles` WHERE `id`=? AND `status`='published'", [$id]);
} else {
    $article = null;
}

if (!$article) {
    http_response_code(404);
    render_page('文章未找到', '<p class="empty">抱歉，未找到该文章。</p>');
    exit;
}

// 阅读量 +1
db_exec("UPDATE `articles` SET `views`=`views`+1 WHERE `id`=?", [$article['id']]);
$article['views'] = (int)$article['views'] + 1;

$category = $article['category_id']
    ? db_fetch("SELECT * FROM `categories` WHERE `id`=?", [$article['category_id']])
    : null;
$tags = article_tag_names((int)$article['id']);
$related = related_articles((int)$article['id'], (int)($article['category_id'] ?? 0), 5);

$catHtml = $category
    ? '<a href="' . url('/category.php?slug=' . rawurlencode($category['slug'])) . '">' . e($category['name']) . '</a>'
    : '<span>未分类</span>';

$tagHtml = '';
foreach ($tags as $t) {
    $slugT = slugify($t['name']);
    // 标签 slug 可能在库里，这里用名称 slugify 兜底
    $tagRow = get_tag_by_slug($slugT);
    $href = $tagRow ? url('/tag.php?slug=' . rawurlencode($tagRow['slug'])) : url('/search.php?q=' . rawurlencode($t['name']));
    $tagHtml .= '<a class="tag" href="' . $href . '">' . e($t['name']) . '</a>';
}

$body = '<article class="post-detail">';
$body .= '<div class="post-meta">' . $catHtml
      . '<span>' . e($article['author'] ?: '佚名') . '</span>'
      . '<span>' . e(date('Y-m-d', strtotime($article['published_at'] ?? $article['created_at']))) . '</span>'
      . '<span>' . (int)$article['views'] . ' 阅读</span></div>';
$body .= '<h1 class="post-title">' . e($article['title']) . '</h1>';
if ($article['cover_image']) {
    $body .= '<img class="post-cover-lg" src="' . attr($article['cover_image']) . '" alt="' . attr($article['title']) . '" loading="lazy">';
}
if ($tagHtml !== '') {
    $body .= '<div class="tag-cloud">' . $tagHtml . '</div>';
}
$body .= '<div class="post-content">' . $article['content'] . '</div>';
if ($article['source_url']) {
    $body .= '<p class="source">原文链接：<a href="' . attr($article['source_url']) . '" target="_blank" rel="noopener">' . e($article['source_url']) . '</a></p>';
}
$body .= '</article>';

if ($related) {
    $body .= '<section class="related"><h3>相关推荐</h3>' . article_cards($related) . '</section>';
}

render_page($article['title'], $body, [
    'description' => $article['summary'] ?: make_excerpt($article['content'], 160),
    'keywords'    => implode(',', array_column($tags, 'name')),
    'og_image'    => $article['cover_image'],
    'canonical'   => url('/article.php?slug=' . rawurlencode($article['slug'])),
]);
