<?php
/**
 * 文章推送接口 (接收 AI / 定时任务推送的公众号文章)
 *
 * 方法: POST   Content-Type: application/json
 * 鉴权: 见 src/auth.php api_require_auth()
 *       方式一(推荐): 头 X-Api-Key + X-Timestamp + X-Signature
 *                     X-Signature = HMAC-SHA256( timestamp + "." + raw_body , API_SECRET ) hex
 *       方式二:        Authorization: Bearer <API_SECRET>
 *
 * 请求体(批量或单篇):
 * {
 *   "articles": [
 *     {
 *       "title": "文章标题",
 *       "content": "<p>正文(HTML/Markdown)</p>",
 *       "summary": "摘要(可选,留空自动生成)",
 *       "category": "tutorial",        // 分类 slug 或名称,可选
 *       "tags": ["大模型","AIGC"],      // 标签名数组,可选
 *       "author": "作者",              // 可选
 *       "cover_image": "https://...",  // 可选
 *       "source_url": "https://...",   // 原文链接,可选
 *       "source_id": "wx-article-123", // 外部幂等ID,可选(强烈建议,用于重试去重)
 *       "status": "published",         // published|draft,默认 published
 *       "published_at": "2026-01-02 10:00:00" // 可选,默认当前时间
 *     }
 *   ]
 * }
 * 单篇也可直接 POST 一个对象(不带 articles 包裹)。
 *
 * 响应: 200 { "success":true, "received":N, "created":N, "updated":N, "items":[...], "errors":[...] }
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/article_service.php';

// 读取原始请求体(供签名校验)
$rawBody = (string)file_get_contents('php://input');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(405, ['error' => 'method_not_allowed', 'message' => '请使用 POST 推送文章']);
}

// 鉴权(失败会直接输出 401/403 并 exit)
$token = api_require_auth($rawBody);

// 解析 JSON
$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    push_log($token['id'], $token['api_key'], 'validation', 'error', '请求体不是合法 JSON', 0);
    api_json(400, ['error' => 'invalid_json', 'message' => '请求体必须是合法 JSON']);
}

// 规整为文章数组
if (isset($data['articles']) && is_array($data['articles'])) {
    $articles = $data['articles'];
} elseif (isset($data['article']) && is_array($data['article'])) {
    $articles = [$data['article']];
} else {
    // 单篇对象(要求至少含 title/content)
    $articles = [$data];
}

if (empty($articles) || !is_array($articles)) {
    push_log($token['id'], $token['api_key'], 'validation', 'error', 'articles 为空', 0);
    api_json(400, ['error' => 'empty', 'message' => '未提供任何文章']);
}

$results = [];
$errors  = [];
$created = 0;
$updated = 0;

foreach ($articles as $i => $item) {
    if (!is_array($item)) {
        $errors[] = ['index' => $i, 'error' => '文章项必须是对象'];
        continue;
    }
    // 字段兜底
    $item['tags'] = $item['tags'] ?? ($item['tag'] ?? []);
    try {
        $res = save_article($item);
        if ($res['action'] === 'created') $created++; else $updated++;
        $results[] = [
            'index'   => $i,
            'id'      => $res['id'],
            'action'  => $res['action'],
            'slug'    => $res['slug'],
            'url'     => url('/article.php?slug=' . rawurlencode($res['slug'])),
        ];
    } catch (Throwable $e) {
        $errors[] = ['index' => $i, 'error' => $e->getMessage(), 'title' => $item['title'] ?? null];
    }
}

$ok = $created + $updated;
push_log($token['id'], $token['api_key'], 'push', $ok > 0 ? 'success' : 'error',
    $ok > 0 ? "处理 {$ok} 篇(新增{$created}/更新{$updated})" : '全部失败',
    $ok);

api_json(200, [
    'success'  => $ok > 0,
    'received' => count($articles),
    'created'  => $created,
    'updated'  => $updated,
    'items'    => $results,
    'errors'   => $errors,
]);
