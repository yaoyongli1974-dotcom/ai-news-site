<?php
/**
 * 视频上传接口 —— 把 AI / 定时任务产出的视频上传到站点，返回可直接引用的 URL。
 *
 * 方法: POST   Content-Type: multipart/form-data
 * 鉴权: 同 /api/push.php、/api/upload.php —— 见 src/auth.php
 *       方式一(推荐): 头 X-Api-Key + X-Timestamp + X-Signature
 *                     X-Signature = HMAC-SHA256(
 *                         timestamp + "." + sha256(文件1内容) [+ "." + sha256(文件2内容) ...],
 *                         API_SECRET
 *                     ) hex
 *                     ⚠ multipart 请求的 php://input 为空，无法对请求体签名，
 *                       所以这里签的是「文件内容摘要」，而不是 api/push.php 的原始请求体。
 *       方式二:        Authorization: Bearer <API_SECRET>（无需签名，便于联调）
 *
 * 表单字段:
 *   files[]  string  必填。视频文件，可多个（上限见 config.php video.max_files）
 *   file     string  files[] 的等价单文件写法
 *   name     string  可选。文件名主干，如 ai-demo-2026
 *                    非 ASCII 字符会被剔除；多个同时上传时自动追加 -01/-02 序号；
 *                    留空则用「日期-随机串」
 *
 * 成功响应(200):
 * {
 *   "success": true,
 *   "count": 1,
 *   "failed": 0,
 *   "files": [
 *     {
 *       "name":   "ai-demo-2026-01.mp4",
 *       "path":   "/assets/videos/ai-demo-2026-01.mp4",
 *       "url":    "https://ai.xalcy.cn/assets/videos/ai-demo-2026-01.mp4",
 *       "html":   "<video controls preload=\"metadata\" src=\"https://ai.xalcy.cn/assets/videos/ai-demo-2026-01.mp4\"></video>",
 *       "markdown":"<video controls preload=\"metadata\" src=\"...\"></video>",
 *       "size":   18342912,
 *       "mime":   "video/mp4",
 *       "sha256": "9f2c..."
 *     }
 *   ],
 *   "errors": []
 * }
 *
 * 失败响应: HTTP 400/401/403/405/413 + { "success":false, "error":"<code>", "message":"<中文说明>" }
 *   error 取值: method_not_allowed | https_required | unauthorized | invalid_token |
 *              missing_signature | bad_signature | expired | payload_too_large |
 *              no_file | too_many_files | upload_failed
 *
 * 与 AI 自动入库的配合：
 *   ① 本接口先上传视频 → 拿到 <video> 片段 / url
 *   ② 把 <video> 片段（或 url）写进文章的 content
 *   ③ 调用 /api/push.php 推文章入库（同一套 Key/Secret）
 *
 * 注意：本站不做服务端转码，请上传浏览器原生支持的格式（H.264/AAC 的 mp4，或 VP9/Opus 的 webm）。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/upload_service.php';

$cfg = upload_video_config();

// ---------- 1) 方法校验 ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(405, [
        'success' => false,
        'error'   => 'method_not_allowed',
        'message' => '请使用 POST 上传视频（multipart/form-data）',
    ]);
}

// ---------- 2) 令牌校验 ----------
// 放在最前面：请求头不受请求体大小影响，超限时也能正常完成鉴权，
// 同时避免向未鉴权的探测请求暴露服务器配置。
$ctx   = api_auth_context();
$token = $ctx['token'];

// ---------- 3) 请求体超限保护 ----------
// 当 multipart 体积超过 php.ini 的 post_max_size 时，PHP 会丢弃整个请求体，
// 表现为 $_FILES / $_POST 全为空但 CONTENT_LENGTH 有值 —— 给出可操作的提示。
// 注意：此时内容已被丢弃，无法校验内容签名，但令牌已完成校验。
if (empty($_FILES) && empty($_POST)) {
    $len       = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax   = upload_ini_bytes('post_max_size');
    $uploadMax = upload_ini_bytes('upload_max_filesize');
    if ($len > 0 && $postMax > 0 && $len > $postMax) {
        push_log($token['id'], $token['api_key'], 'upload_video', 'error',
            '请求体 ' . upload_human_size($len) . ' 超过 post_max_size', 0);
        api_json(413, [
            'success' => false,
            'error'   => 'payload_too_large',
            'message' => '请求体 ' . upload_human_size($len) . ' 已超过服务器 post_max_size（'
                . upload_human_size($postMax) . '）',
            'post_max_size'       => upload_human_size($postMax),
            'upload_max_filesize' => upload_human_size($uploadMax),
            'hint'    => '视频较大，请调大 PHP 的 post_max_size / upload_max_filesize（及 Nginx client_max_body_size），或减少单次上传个数/单文件体积',
        ]);
    }
}

// ---------- 4) 收集并初筛文件 ----------
$files = upload_collect_files($_FILES, ['file', 'files']);
if (!$files) {
    push_log($token['id'], $token['api_key'], 'upload_video', 'error', '未收到文件', 0);
    api_json(400, [
        'success' => false,
        'error'   => 'no_file',
        'message' => '未收到文件。请用 multipart/form-data 提交，字段名为 files[]（或 file）',
        'limits'  => [
            'max_size'  => upload_human_size((int)$cfg['max_size']),
            'max_files' => (int)$cfg['max_files'],
            'allowed'   => array_keys((array)$cfg['allowed_mime']),
        ],
    ]);
}
if (count($files) > (int)$cfg['max_files']) {
    push_log($token['id'], $token['api_key'], 'upload_video', 'error', '文件数超限 ' . count($files), 0);
    api_json(400, [
        'success' => false,
        'error'   => 'too_many_files',
        'message' => '单次最多上传 ' . (int)$cfg['max_files'] . ' 个，本次收到 ' . count($files) . ' 个',
    ]);
}

// ---------- 5) 签名校验（对文件内容摘要签名） ----------
$hashes = [];
if ($ctx['sig'] !== '') {
    foreach ($files as $f) {
        $tmp      = (string)($f['tmp_name'] ?? '');
        $hashes[] = ($tmp !== '' && is_file($tmp)) ? (string)hash_file('sha256', $tmp) : '';
    }
}
api_require_auth_uploads($hashes, $ctx);

// ---------- 6) 逐個保存 ----------
$nameHint = trim((string)($_POST['name'] ?? ''));
$total    = count($files);
$stored   = [];
$errors   = [];

foreach ($files as $i => $f) {
    try {
        // 多个共用同一 name 时，按现有约定自动追加 -01/-02 序号
        $hint = ($nameHint !== '' && $total > 1)
            ? rtrim($nameHint, '-') . '-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)
            : $nameHint;

        $meta = upload_store_video($f, $hint);

        // 视频没有 width/height；直接给出可粘贴的 <video> 片段（内容字段支持 HTML）
        $meta['html']     = '<video controls preload="metadata" src="' . attr($meta['url']) . '"></video>';
        $meta['markdown'] = $meta['html'];

        $stored[] = $meta;
    } catch (Throwable $e) {
        $errors[] = [
            'index' => $i,
            'name'  => basename((string)($f['name'] ?? '')),
            'error' => $e->getMessage(),
        ];
    }
}

// ---------- 7) 响应 ----------
if (!$stored) {
    $first = $errors[0]['error'] ?? '上传失败';
    push_log($token['id'], $token['api_key'], 'upload_video', 'error', '全部失败：' . $first, 0);
    api_json(400, [
        'success' => false,
        'error'   => 'upload_failed',
        'message' => $first,
        'errors'  => $errors,
    ]);
}

$partial = count($errors) > 0;
push_log($token['id'], $token['api_key'], 'upload_video', $partial ? 'error' : 'success',
    '上传 ' . count($stored) . ' 个视频' . ($partial ? '，失败 ' . count($errors) . ' 个' : ''),
    count($stored));

api_json(200, [
    'success' => true,
    'count'   => count($stored),
    'failed'  => count($errors),
    'files'   => $stored,
    'errors'  => $errors,
]);
