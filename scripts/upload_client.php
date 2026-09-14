<?php
/**
 * 图片上传客户端 (CLI) —— 供 AI / 定时任务把配图上传到站点，拿到可访问 URL。
 *
 * 用法:
 *   php scripts/upload_client.php 图片.jpg
 *   php scripts/upload_client.php 图1.jpg 图2.jpg --name=10-person-team-ai-efficiency
 *   php scripts/upload_client.php 图.jpg --alt="AI 团队协作" --json
 *
 * 选项:
 *   --name=主干名    文件名主干(不含扩展名)。多张时自动追加 -01/-02 序号，
 *                    最终形如 10-person-team-ai-efficiency-01.jpg
 *   --alt=替代文本   生成 <img alt> / Markdown 时使用
 *   --url=接口地址   默认由 api_url 推导(push.php → upload.php)
 *   --json           只输出 JSON，便于脚本/AI 直接解析
 *   --dry-run        只打印将要发送的签名与文件清单，不实际请求
 *
 * 配置(按优先级): 环境变量 > scripts/push_client.config.php > 本文件默认值
 *   AIXALCY_UPLOAD_URL / AIXALCY_API_KEY / AIXALCY_API_SECRET
 *
 * 签名规则(与 /api/upload.php 一致):
 *   X-Signature = HMAC-SHA256(
 *       timestamp + "." + sha256(文件1内容) [+ "." + sha256(文件2内容) ...],
 *       API_SECRET
 *   ) hex
 *   摘要顺序必须与请求中文件的顺序一致。
 */

// ---- 读取配置 ----
$configFile = __DIR__ . '/push_client.config.php';
$cfg = is_file($configFile) ? require $configFile : [];
if (!is_array($cfg)) { $cfg = []; }

$apiUrl    = (string)(getenv('AIXALCY_UPLOAD_URL') ?: ($cfg['upload_url'] ?? ''));
$apiKey    = (string)(getenv('AIXALCY_API_KEY')    ?: ($cfg['api_key'] ?? ''));
$apiSecret = (string)(getenv('AIXALCY_API_SECRET') ?: ($cfg['api_secret'] ?? ''));
if ($apiUrl === '') {
    // 未显式配置时，由 push 接口地址推导，避免用户重复填写
    $pushUrl = (string)(getenv('AIXALCY_API_URL') ?: ($cfg['api_url'] ?? 'https://ai.xalcy.cn/api/push.php'));
    $apiUrl  = preg_replace('#/push\.php$#', '/upload.php', $pushUrl) ?: $pushUrl;
}

if ($apiKey === '' || $apiSecret === '') {
    fwrite(STDERR, "缺少 API Key / Secret，请通过环境变量或 scripts/push_client.config.php 配置。\n");
    exit(2);
}

// ---- 解析参数 ----
$files    = [];
$nameHint = '';
$alt      = '';
$asJson   = false;
$dryRun   = false;
$urlGiven = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--name=(.*)$/s', $arg, $m))        { $nameHint = $m[1]; continue; }
    if (preg_match('/^--alt=(.*)$/s', $arg, $m))         { $alt = $m[1]; continue; }
    if (preg_match('/^--url=(.*)$/s', $arg, $m))         { $apiUrl = $m[1]; $urlGiven = true; continue; }
    if ($arg === '--json')    { $asJson = true; continue; }
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "用法: php scripts/upload_client.php 图片1.jpg [图片2.jpg ...] [--name=主干名] [--alt=说明] [--json]\n");
        exit(0);
    }
    $files[] = $arg;
}

if (!$files) {
    fwrite(STDERR, "请指定至少一个图片文件。用法: php scripts/upload_client.php 图片.jpg [--name=主干名]\n");
    exit(3);
}

// ---- 校验本地文件并计算摘要 ----
$hashes = [];
foreach ($files as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "文件不存在或不可读: {$path}\n");
        exit(3);
    }
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        fwrite(STDERR, "无法读取文件内容: {$path}\n");
        exit(3);
    }
    $hashes[] = $hash;
}

// ---- 签名 ----
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . implode('.', $hashes), $apiSecret);

if ($dryRun) {
    echo "接口: {$apiUrl}" . ($urlGiven ? " (手动指定)\n" : " (由 push 地址推导)\n");
    echo "时间戳: {$timestamp}\n";
    echo "摘要顺序:\n";
    foreach ($files as $i => $p) {
        echo '  [' . $i . '] ' . basename($p) . '  sha256=' . $hashes[$i] . "\n";
    }
    echo "签名: {$signature}\n";
    exit(0);
}

// ---- 发送 ----
if (!function_exists('curl_init')) {
    fwrite(STDERR, "缺少 curl 扩展，无法发送 multipart 请求。请安装 php-curl 后在容器内执行：\n");
    fwrite(STDERR, "  docker exec -it php8-fpm php /www/sites/ai.xalcy.cn/index/scripts/upload_client.php ...\n");
    exit(4);
}

$headers = [
    'X-Api-Key: ' . $apiKey,
    'X-Timestamp: ' . $timestamp,
    'X-Signature: ' . $signature,
];

$attempt = 0;
$maxAttempts = 2;
$response = null;
$httpCode = 0;
while ($attempt < $maxAttempts) {
    $attempt++;
    [$response, $httpCode] = upload_post($apiUrl, $files, $nameHint, $alt, $headers);
    if ($httpCode >= 200 && $httpCode < 300) { break; }
    // 4xx 属调用方问题，重试无意义
    if ($httpCode >= 400 && $httpCode < 500) { break; }
    if ($attempt < $maxAttempts) { sleep(2); }
}

if ($asJson) {
    echo (string)$response . "\n";
} else {
    echo "HTTP {$httpCode}\n";
    $decoded = json_decode((string)$response, true);
    if (is_array($decoded) && !empty($decoded['success']) && !empty($decoded['files'])) {
        foreach ($decoded['files'] as $f) {
            echo "已上传: {$f['name']}  ({$f['width']}×{$f['height']}, {$f['size']} B)\n";
            echo "  URL:      {$f['url']}\n";
            echo "  站内路径: {$f['path']}\n";
            echo "  HTML:     {$f['html']}\n";
        }
        if (!empty($decoded['errors'])) {
            echo "部分失败:\n";
            foreach ($decoded['errors'] as $e) {
                echo "  - {$e['name']}: {$e['error']}\n";
            }
        }
    } else {
        echo (string)$response . "\n";
    }
}

exit($httpCode >= 200 && $httpCode < 300 ? 0 : 1);

// ---------------- HTTP 助手 ----------------
/**
 * 发送 multipart 上传。字段名 files[]，与接口约定一致。
 * @return array{0:string,1:int} [响应体, HTTP 状态码]
 */
function upload_post(string $url, array $files, string $nameHint, string $alt, array $headers): array
{
    // PHP 数组不能有重复键，故用 files[0] files[1] 形式让 PHP 端还原成 $_FILES['files']
    $post = [];
    foreach (array_values($files) as $i => $path) {
        $post['files[' . $i . ']'] = new CURLFile($path);
    }
    if ($nameHint !== '') { $post['name'] = $nameHint; }
    if ($alt !== '')      { $post['alt']  = $alt; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,   // 不要手写 Content-Type，交给 curl 生成 boundary
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        $resp = 'curl error: ' . curl_error($ch);
        $code = 0;
    }
    curl_close($ch);
    return [(string)$resp, $code];
}
