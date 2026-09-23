<?php
/**
 * 专题列表页 —— 展示所有已发布专题（封面 / 标题 / 简介 / 文章数）。
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_DIR . '/view.php';
require_once SRC_DIR . '/topic_service.php';

// 伪静态开启时，将直接访问的 topics.php 301 到 /topics（__pu 标记由重写规则注入，避免与重写互跳成环）
canonicalize_to_pretty(topics_url());

$topics = get_all_topics(true);

// ItemList 结构化数据（便于 AI 爬虫理解专题集合）
$listEls = [];
foreach ($topics as $i => $t) {
    $listEls[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'name'     => $t['title'],
        'url'      => topic_url($t['slug']),
    ];
}
$jsonLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'itemListElement' => $listEls,
];

$crumbs = [
    ['name' => '首页', 'url' => url('/')],
    ['name' => '专题', 'url' => topics_url()],
];

$html = '<h1 class="page-title">专题</h1>';
if (empty($topics)) {
    $html .= '<p class="empty">暂无专题，敬请期待。</p>';
} else {
    $html .= '<div class="topic-grid">';
    foreach ($topics as $t) {
        $cover = $t['cover_image']
            ? '<img class="topic-cover" src="' . attr($t['cover_image']) . '" alt="' . attr($t['title']) . '" loading="lazy">'
            : '<div class="topic-cover topic-cover--empty"></div>';
        $html .= '<a class="topic-card" href="' . topic_url($t['slug']) . '">'
            . $cover
            . '<div class="topic-body">'
            . '<h2 class="topic-title">' . e($t['title']) . '</h2>'
            . '<p class="topic-summary">' . e($t['summary'] ?: '查看本专题下的精选内容合集。') . '</p>'
            . '<span class="topic-count">' . (int)$t['article_count'] . ' 篇文章</span>'
            . '</div></a>';
    }
    $html .= '</div>';
}

render_page('专题', $html, [
    'description' => 'AI 资讯汇专题合集，围绕特定主题策划的优质内容集合。',
    'canonical'   => topics_url(),
    'breadcrumb'  => $crumbs,
    'json_ld'     => $jsonLd,
]);
