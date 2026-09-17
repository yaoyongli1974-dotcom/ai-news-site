<?php
/**
 * 动态 llms.txt（纯文本）——供 LLM / AI Agent 快速获取站点信息与最新文章清单。
 * 访问：/llms.php
 * 格式遵循 llms.txt 约定（Markdown 风格纯文本）。
 */
require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$site = get_option('site_title', 'AI 资讯汇');
$desc = get_option('site_description', '');
$base = rtrim(APP_URL, '/');

echo "# {$site}\n\n";
echo ($desc !== '' ? $desc : '人工智能领域微信公众号文章聚合站') . "\n\n";
echo "- 站内搜索：{$base}/search.php?q=关键词\n";
echo "- 站点地图：{$base}/sitemap.php\n\n";
echo "## 最新文章\n\n";

$arts = db_fetch_all(
    "SELECT a.`title`, a.`slug`, a.`summary`, a.`author`, COALESCE(a.`published_at`, a.`created_at`) AS dt
     FROM `articles` a WHERE a.`status`='published' ORDER BY dt DESC LIMIT 30"
);

if (empty($arts)) {
    echo "(暂无已发布文章)\n";
} else {
    foreach ($arts as $a) {
        $url  = $base . '/article.php?slug=' . rawurlencode($a['slug']);
        $date = date('Y-m-d', strtotime($a['dt']));
        $name = $a['author'] ?: '佚名';
        echo "- [{$a['title']}]({$url}) — {$name} · {$date}\n";
        if ($a['summary'] !== '') {
            echo "  {$a['summary']}\n";
        }
    }
}
