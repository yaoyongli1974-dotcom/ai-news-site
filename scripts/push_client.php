<?php
/**
 * 文章推送客户端 (CLI) —— 供 AI / 定时任务(cron)调用，将写好的公众号文章推送到后台。
 *
 * 用法:
 *   php scripts/push_client.php                 # 推送内置示例文章
 *   php scripts/push_client.php payload.json    # 推送 JSON 文件(结构见 api/push.php 说明)
 *
 * 配置(按优先级): 环境变量 > scripts/push_client.config.php > 本文件默认值
 *   AIXALCY_API_URL     接口地址, 如 https://ai.xalcy.cn/api/push.php
 *   AIXALCY_API_KEY     API Key
 *   AIXALCY_API_SECRET  API Secret
 *
 * 也可复制 scripts/push_client.config.example.php 为 push_client.config.php 填写。
 */

// ---- 读取配置 ----
$configFile = __DIR__ . '/push_client.config.php';
if (is_file($configFile)) { $cfg = require $configFile; } else { $cfg = []; }
$apiUrl   = getenv('AIXALCY_API_URL')   ?: ($cfg['api_url']   ?? 'https://ai.xalcy.cn/api/push.php');
$apiKey   = getenv('AIXALCY_API_KEY')   ?: ($cfg['api_key']   ?? '');
$apiSecret= getenv('AIXALCY_API_SECRET')?: ($cfg['api_secret']?? '');

if ($apiKey === '' || $apiSecret === '') {
    fwrite(STDERR, "缺少 API Key / Secret，请通过环境变量或 push_client.config.php 配置。\n");
    exit(2);
}

// ---- 构造 payload ----
if (isset($argv[1]) && is_file($argv[1])) {
    $raw = file_get_contents($argv[1]);
    if (json_decode($raw, true) === null) {
        fwrite(STDERR, "payload 文件不是合法 JSON: {$argv[1]}\n");
        exit(3);
    }
} else {
    $raw = json_encode([
        'articles' => [[
            'title'   => '示例推送文章（请替换为真实内容）',
            'summary' => '由定时任务自动推送的示例。',
            'content' => '<p>这是通过推送接口自动入库的文章正文。</p>',
            'category'=> 'news',
            'tags'    => ['大模型', 'AIGC'],
            'author'  => 'AI 助手',
            'source_url'  => 'https://mp.weixin.qq.com/',
            'source_id'   => 'demo-' . date('YmdHis'),
            'status'  => 'published',
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

// ---- 签名 ----
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $raw, $apiSecret);

$headers = [
    'Content-Type: application/json',
    'X-Api-Key: ' . $apiKey,
    'X-Timestamp: ' . $timestamp,
    'X-Signature: ' . $signature,
];

// ---- 发送(带一次重试) ----
$attempt = 0;
$maxAttempts = 2;
$response = null;
$httpCode = 0;
while ($attempt < $maxAttempts) {
    $attempt++;
    [$response, $httpCode] = http_post($apiUrl, $raw, $headers);
    if ($httpCode >= 200 && $httpCode < 300) break;
    if ($attempt < $maxAttempts) sleep(2);
}

echo "HTTP {$httpCode}\n";
echo $response . "\n";
exit($httpCode >= 200 && $httpCode < 300 ? 0 : 1);

// ---------------- HTTP 助手 ----------------
function http_post(string $url, string $body, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) { $resp = 'curl error: ' . curl_error($ch); $code = 0; }
        curl_close($ch);
        return [$resp, $code];
    }
    // 回退: file_get_contents
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 30,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    return [($resp === false ? 'request failed' : $resp), $code];
}
