<?php
/**
 * 动态站点地图（XML）——供搜索引擎与 AI 爬虫发现全部文章与栏目。
 * 访问：/sitemap.php
 */
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(APP_URL, '/');
$urls = [];

// 首页
$urls[] = ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0'];

// 分类页
$cats = db_fetch_all("SELECT `slug` FROM `categories` ORDER BY `id` ASC");
foreach ($cats as $c) {
    $urls[] = [
        'loc'       => $base . '/category.php?slug=' . rawurlencode($c['slug']),
        'changefreq' => 'daily',
        'priority'  => '0.7',
    ];
}

// 文章（含最后更新时间）
$arts = db_fetch_all(
    "SELECT `slug`, COALESCE(`published_at`, `created_at`) AS dt
     FROM `articles` WHERE `status`='published' ORDER BY dt DESC"
);
foreach ($arts as $a) {
    $urls[] = [
        'loc'       => $base . '/article.php?slug=' . rawurlencode($a['slug']),
        'lastmod'   => iso8601($a['dt']),
        'changefreq' => 'weekly',
        'priority'  => '0.8',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . '</loc>' . "\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1, 'UTF-8') . '</lastmod>' . "\n";
    }
    echo '    <changefreq>' . $u['changefreq'] . '</changefreq>' . "\n";
    echo '    <priority>' . $u['priority'] . '</priority>' . "\n";
    echo '  </url>' . "\n";
}
echo '</urlset>';
