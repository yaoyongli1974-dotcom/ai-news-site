<?php
/**
 * 前台视图渲染助手 —— 布局、侧边栏、文章卡片。
 * 内容视为可信 HTML(后台/接口写入)，前端直接输出。
 */

/**
 * 渲染整页。
 * @param string $title   页面标题
 * @param string $body    <main> 内的主体 HTML
 * @param array  $meta    ['description'=>,'keywords'=>,'og_image'=>]
 */
function render_page(string $title, string $body, array $meta = []): void
{
    $siteTitle = get_option('site_title', 'AI 资讯汇');
    $fullTitle = $title !== '' ? $title . ' · ' . $siteTitle : $siteTitle;
    $desc = e($meta['description'] ?? get_option('site_description', ''));
    $kw   = e($meta['keywords'] ?? get_option('site_keywords', ''));
    $og   = e($meta['og_image'] ?? '');
    $canonical = e($meta['canonical'] ?? '');
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
  <meta property="og:title" content="<?= e($fullTitle) ?>">
  <meta property="og:type" content="website">
  <?php if ($og !== ''): ?><meta property="og:image" content="<?= $og ?>"><?php endif; ?>
  <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $canonical ?>"><?php endif; ?>
  <?php if ($siteIcon !== ''): ?><link rel="icon" href="<?= e($siteIcon) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= asset('/assets/style.css') ?>">
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
    <nav class="site-nav">
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
        $html .= '<article class="post-card">'
            . $cover
            . '<div class="post-body">'
            . '<h2 class="post-title"><a href="' . $link . '">' . e($a['title']) . '</a></h2>'
            . '<div class="post-meta">' . $cat . '<span>' . e($a['author'] ?: '佚名') . '</span><span>' . e(time_ago($a['published_at'] ?? $a['created_at'])) . '</span><span>' . (int)$a['views'] . ' 阅读</span></div>'
            . '<p class="post-summary">' . e($a['summary'] ?: make_excerpt($a['content'], 140)) . '</p>'
            . '</div></article>';
    }
    $html .= '</div>';
    return $html;
}
