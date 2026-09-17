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

$dateRaw = $article['published_at'] ?? $article['created_at'];

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
      . '<time datetime="' . attr(iso8601($dateRaw)) . '">' . e(date('Y-m-d', strtotime($dateRaw))) . '</time>'
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

$canonical = url('/article.php?slug=' . rawurlencode($article['slug']));
$datePublished = iso8601($dateRaw);
$dateModified  = isset($article['updated_at']) && $article['updated_at'] !== ''
    ? iso8601($article['updated_at'])
    : $datePublished;
$siteName = get_option('site_title', 'AI 资讯汇');
$siteLogo = get_option('site_logo', '');
$authorName = $article['author'] ?: '佚名';

$coverUrl = '';
if ($article['cover_image']) {
    $coverUrl = str_starts_with($article['cover_image'], 'http')
        ? $article['cover_image']
        : url($article['cover_image']);
}

$articleLd = [
    '@type'           => 'BlogPosting',
    'headline'        => $article['title'],
    'description'     => $article['summary'] ?: make_excerpt($article['content'], 160),
    'inLanguage'      => 'zh-CN',
    'datePublished'   => $datePublished,
    'dateModified'    => $dateModified,
    'author'          => ['@type' => 'Person', 'name' => $authorName],
    'publisher'       => ['@type' => 'Organization', 'name' => $siteName],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
    'articleSection'  => $category ? [$category['name']] : [],
    'keywords'        => array_column($tags, 'name'),
];
if ($coverUrl !== '') {
    $articleLd['image'] = $coverUrl;
}
if ($siteLogo !== '') {
    $articleLd['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => $siteLogo];
}

// 面包屑：首页 > 分类(可选) > 文章
$crumbs = [['name' => '首页', 'url' => url('/')]];
if ($category) {
    $crumbs[] = [
        'name' => $category['name'],
        'url'  => url('/category.php?slug=' . rawurlencode($category['slug'])),
    ];
}
$crumbs[] = ['name' => $article['title'], 'url' => $canonical];
$breadcrumbItems = [];
foreach ($crumbs as $i => $c) {
    $breadcrumbItems[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'name'     => $c['name'],
        'item'     => $c['url'],
    ];
}
$breadcrumbLd = ['@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbItems];

$ld = ['@context' => 'https://schema.org', '@graph' => [$articleLd, $breadcrumbLd]];

render_page($article['title'], $body, [
    'description'    => $article['summary'] ?: make_excerpt($article['content'], 160),
    'keywords'       => implode(',', array_column($tags, 'name')),
    'og_image'       => $article['cover_image'],
    'og_type'        => 'article',
    'og_description' => $article['summary'] ?: make_excerpt($article['content'], 160),
    'canonical'      => $canonical,
    'breadcrumb'     => $crumbs,
    'json_ld'        => $ld,
]);
