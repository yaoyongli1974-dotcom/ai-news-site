<?php
/**
 * 前台视图渲染助手 —— 布局、侧边栏、文章卡片。
 * 内容视为可信 HTML(后台/接口写入)，前端直接输出。
 */

/**
 * 渲染整页。
 * @param string $title   页面标题
 * @param string $body    <main> 内的主体 HTML
 * @param array  $meta    [
 *   'description'=>, 'keywords'=>, 'og_image'=>, 'og_description'=>,
 *   'canonical'=>, 'og_type'=>, 'robots'=>,
 *   'breadcrumb'=> [['name','url'], ...] (末项为当前页),
 *   'json_ld'=> array|array[] (Schema.org 数据块，注入 <head>)
 * ]
 */
function render_page(string $title, string $body, array $meta = []): void
{
    $siteTitle = get_option('site_title', 'AI 资讯汇');
    $fullTitle = $title !== '' ? $title . ' · ' . $siteTitle : $siteTitle;
    $desc = e($meta['description'] ?? get_option('site_description', ''));
    $kw   = e($meta['keywords'] ?? get_option('site_keywords', ''));
    $og   = e($meta['og_image'] ?? '');
    $canonicalRaw = $meta['canonical'] ?? '';
    $canonical = e($canonicalRaw);
    $robots   = $meta['robots'] ?? 'index,follow';
    $ogType   = $meta['og_type'] ?? 'website';
    $ogDesc   = $meta['og_description'] ?? get_option('site_description', '');
    $ogUrl    = e($canonicalRaw !== '' ? $canonicalRaw : APP_URL);
    $breadcrumbHtml = breadcrumb_html($meta['breadcrumb'] ?? []);
    $subtitle = e(get_option('site_subtitle', ''));
    $siteLogo = get_option('site_logo', '');
    $siteIcon = get_option('site_icon', '');
    $icp      = get_option('site_icp', '');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>' . "\n";
    ?>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($fullTitle) ?></title>
  <meta name="description" content="<?= $desc ?>">
  <meta name="keywords" content="<?= $kw ?>">
  <meta name="robots" content="<?= e($robots) ?>">
  <link rel="canonical" href="<?= $canonical ?>">
  <meta property="og:type" content="<?= e($ogType) ?>">
  <meta property="og:title" content="<?= e($fullTitle) ?>">
  <meta property="og:description" content="<?= e($ogDesc) ?>">
  <meta property="og:url" content="<?= $ogUrl ?>">
  <meta property="og:site_name" content="<?= e($siteTitle) ?>">
  <meta property="og:locale" content="zh_CN">
  <?php if ($og !== ''): ?><meta property="og:image" content="<?= $og ?>"><?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($fullTitle) ?>">
  <meta name="twitter:description" content="<?= e($ogDesc) ?>">
  <?php if ($og !== ''): ?><meta name="twitter:image" content="<?= $og ?>"><?php endif; ?>
  <?php if ($siteIcon !== ''): ?><link rel="icon" href="<?= e($siteIcon) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= asset('/assets/style.css') ?>">
  <?php
    // 全局结构化数据：站点信息 + 站内搜索动作
    $graph = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'       => 'WebSite',
                'name'        => $siteTitle,
                'url'         => APP_URL,
                'description' => get_option('site_description', ''),
                'inLanguage'  => 'zh-CN',
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => APP_URL . '/search.php?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type' => 'Organization',
                'name'  => $siteTitle,
                'url'   => APP_URL,
            ],
        ],
    ];
    if ($siteLogo !== '') {
        $graph['@graph'][1]['logo'] = $siteLogo;
        $graph['@graph'][1]['image'] = $siteLogo;
    }
    echo json_ld($graph);

    // 页面级结构化数据（由调用方注入，如 Article / BreadcrumbList）
    $pgLd = $meta['json_ld'] ?? null;
    if (is_array($pgLd)) {
        if (array_is_list($pgLd)) {
            foreach ($pgLd as $blk) {
                if (is_array($blk)) echo json_ld($blk);
            }
        } else {
            echo json_ld($pgLd);
        }
    }
    ?>
</head>
<body>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="<?= url('/') ?>">
        <?php if ($siteLogo !== ''): ?><img class="brand-logo" src="<?= e($siteLogo) ?>" alt="<?= e($siteTitle) ?>">
        <?php endif; ?><?= e($siteTitle) ?>
      </a>
      <span class="brand-sub"><?= $subtitle ?></span>
      <form class="search-box" action="<?= url('/search.php') ?>" method="get" role="search">
        <input type="search" name="q" placeholder="搜索文章…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="搜索">
        <button type="submit">搜索</button>
      </form>
    </div>
    <nav class="site-nav" aria-label="主导航">
      <div class="container">
        <a href="<?= url('/') ?>">首页</a>
        <?php foreach (get_categories() as $c): ?>
          <a href="<?= url('/category.php?slug=' . rawurlencode($c['slug'])) ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
  </header>

  <main class="container main-grid">
    <div class="content">
      <?= $breadcrumbHtml ?>
      <?= $body ?>
    </div>
    <?= sidebar_html() ?>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p><?= e(get_option('footer_text', '© ' . date('Y') . ' AI 资讯汇')) ?><?php if ($icp !== ''): ?> · <span class="icp"><?= e($icp) ?></span><?php endif; ?></p>
    </div>
  </footer>
</body>
</html>
<?php
}

/** 侧边栏：分类 + 热门标签 */
function sidebar_html(): string
{
    $cats = get_categories();
    $tags = get_tags(20);
    $html = '<aside class="sidebar">';

    $html .= '<section class="widget"><h3>分类</h3><ul class="cat-list">';
    foreach ($cats as $c) {
        $html .= '<li><a href="' . url('/category.php?slug=' . rawurlencode($c['slug'])) . '">' . e($c['name']) . '</a></li>';
    }
    $html .= '</ul></section>';

    if ($tags) {
        $html .= '<section class="widget"><h3>热门标签</h3><div class="tag-cloud">';
        foreach ($tags as $t) {
            $html .= '<a class="tag" href="' . url('/tag.php?slug=' . rawurlencode($t['slug'])) . '">' . e($t['name']) . '</a>';
        }
        $html .= '</div></section>';
    }

    $html .= '<section class="widget"><h3>关于</h3><p class="muted">' . e(get_option('site_description', '')) . '</p></section>';
    $html .= '</aside>';
    return $html;
}

/**
 * 文章卡片列表 HTML。
 * @param array $articles
 */
function article_cards(array $articles): string
{
    if (empty($articles)) {
        return '<p class="empty">暂无文章。</p>';
    }
    $html = '<div class="post-list">';
    foreach ($articles as $a) {
        $link = url('/article.php?slug=' . rawurlencode($a['slug']));
        $cover = $a['cover_image'] ? '<img class="post-cover" src="' . attr($a['cover_image']) . '" alt="' . attr($a['title']) . '" loading="lazy">' : '';
        $cat = $a['category_name'] ? '<a class="post-cat" href="' . url('/category.php?slug=' . rawurlencode($a['category_slug'])) . '">' . e($a['category_name']) . '</a>' : '';
        $dateRaw = $a['published_at'] ?? $a['created_at'] ?? '';
        $iso = iso8601($dateRaw);
        $timeHtml = $iso !== '' ? '<time datetime="' . attr($iso) . '" pubdate>' . e(time_ago($dateRaw)) . '</time>' : '<span>' . e(time_ago($dateRaw)) . '</span>';
        $html .= '<article class="post-card">'
            . $cover
            . '<div class="post-body">'
            . '<h2 class="post-title"><a href="' . $link . '">' . e($a['title']) . '</a></h2>'
            . '<div class="post-meta">' . $cat . '<span>' . e($a['author'] ?: '佚名') . '</span>' . $timeHtml . '<span>' . (int)$a['views'] . ' 阅读</span></div>'
            . '<p class="post-summary">' . e($a['summary'] ?: make_excerpt($a['content'], 140)) . '</p>'
            . '</div></article>';
    }
    $html .= '</div>';
    return $html;
}
