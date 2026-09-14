<?php
/**
 * 鉴权模块
 *  - 后台: 基于 Session 的管理员登录
 *  - 推送 API: 基于 HMAC-SHA256 签名 或 Bearer 令牌 的接口鉴权
 */
declare(strict_types=1);

use PDO;

// =========================================================
// 后台管理员鉴权 (Session)
// =========================================================

/** 启动后台会话(仅在后台页面/登录流程调用) */
function admin_session_start(): void
{
    $name = $GLOBALS['APP_CONFIG']['admin']['session_name'] ?? 'AIXALCY_ADMIN';
    if (session_status() === PHP_SESSION_NONE) {
        session_name($name);
        session_start();
    }
}

/** 验证管理员凭据，成功写入 session 并返回 true */
function admin_login(string $username, string $password): bool
{
    $admin = db_fetch("SELECT * FROM `admins` WHERE `username`=?", [$username]);
    if (!$admin) return false;
    if (!password_verify($password, $admin['password_hash'])) return false;
    admin_session_start();
    $_SESSION['admin_id']   = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['username'];
    $_SESSION['admin_disp'] = $admin['display_name'] ?: $admin['username'];
    return true;
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

/** 未登录则跳转到登录页(仅用于 HTML 后台页面) */
function require_admin(): void
{
    admin_session_start();
    if (!is_admin_logged_in()) {
        redirect(url('/admin/login.php'));
    }
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ---------------- CSRF ----------------
function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** 校验请求中的 CSRF token(POST 表单字段或 X-CSRF-Token 头) */
function csrf_verify(): bool
{
    admin_session_start();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

// =========================================================
// 推送 API 鉴权 (HMAC 签名 / Bearer 令牌)
// =========================================================

function get_request_ip(): string
{
    // 生产建议在可信反代后使用 X-Forwarded-For 第一个地址；此处优先 REMOTE_ADDR
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return trim($ip);
}

/**
 * 记录推送审计日志。
 */
function push_log(
    ?int $tokenId,
    string $apiKey,
    string $action,
    string $status,
    ?string $message,
    int $items = 0
): void {
    db_exec(
        "INSERT INTO `push_logs`
         (`api_token_id`,`api_key`,`action`,`status`,`message`,`items`,`ip`)
         VALUES (?,?,?,?,?,?,?)",
        [$tokenId, $apiKey, $action, $status, $message, $items, get_request_ip()]
    );
}

/**
 * 令牌校验（不含签名校验）。
 * 返回 ['token'=>记录, 'key'=>请求key, 'sig'=>签名, 'ts'=>时间戳, 'bearer'=>Bearer令牌]。
 * 失败直接输出 JSON 并 exit。
 *
 * 适用于「需要先读取请求载荷、再拼出待签名字符串」的场景（如 multipart 文件上传，
 * 其 php://input 为空，签名对象是文件内容摘要而非原始请求体）。
 */
function api_auth_context(): array
{
    $cfg = $GLOBALS['APP_CONFIG']['api'] ?? [];

    // 强制 HTTPS(生产建议开启)
    if (!empty($cfg['require_https']) && !is_https()) {
        api_json(403, ['error' => 'https_required', 'message' => '接口仅允许 HTTPS 访问']);
    }

    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    $ts  = trim((string)($_SERVER['HTTP_X_TIMESTAMP'] ?? ''));
    $sig = strtolower(trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? '')));

    $bearer = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $bearer = trim($m[1]);
        }
    }

    if ($key === '' && $bearer === '') {
        push_log(null, '', 'auth_fail', 'error', '缺少 X-Api-Key 或 Authorization 头', 0);
        api_json(401, ['error' => 'unauthorized', 'message' => '缺少鉴权信息']);
    }

    // 按 key 查令牌(签名方式)，或按 secret 查(Bearer 方式)
    if ($key !== '') {
        $token = db_fetch("SELECT * FROM `api_tokens` WHERE `api_key`=?", [$key]);
    } else {
        $token = null;
        foreach (db_fetch_all("SELECT * FROM `api_tokens` WHERE `status`='active'") as $t) {
            if (hash_equals($t['api_secret'], $bearer)) { $token = $t; break; }
        }
    }

    if (!$token || $token['status'] !== 'active') {
        push_log($token['id'] ?? null, $key, 'auth_fail', 'error', '令牌无效或已停用', 0);
        api_json(401, ['error' => 'invalid_token', 'message' => '令牌无效或已停用']);
    }

    // 更新最近使用时间(不阻塞主流程)
    db_exec("UPDATE `api_tokens` SET `last_used_at`=NOW() WHERE `id`=?", [$token['id']]);

    return ['token' => $token, 'key' => $key, 'sig' => $sig, 'ts' => $ts, 'bearer' => $bearer];
}

/**
 * 校验签名：要求 hash_hmac('sha256', $base, api_secret) === X-Signature。
 * 使用 Bearer 方式(无 X-Signature)时直接放行；两者都没有则 401。
 */
function api_verify_signature(array $ctx, string $base): void
{
    $cfg = $GLOBALS['APP_CONFIG']['api'] ?? [];

    if ($ctx['sig'] === '') {
        if ($ctx['bearer'] !== '') {
            return;   // Bearer 方式无需签名
        }
        push_log($ctx['token']['id'], $ctx['key'], 'auth_fail', 'error', '未提供签名', 0);
        api_json(401, ['error' => 'missing_signature', 'message' => '缺少 X-Signature 签名']);
    }

    if (!ctype_digit($ctx['ts'])) {
        push_log($ctx['token']['id'], $ctx['key'], 'auth_fail', 'error', '时间戳格式错误', 0);
        api_json(401, ['error' => 'bad_timestamp', 'message' => '时间戳格式错误']);
    }
    $ttl = (int)($cfg['signature_ttl'] ?? 300);
    if (abs((int)time() - (int)$ctx['ts']) > $ttl) {
        push_log($ctx['token']['id'], $ctx['key'], 'auth_fail', 'error', '签名已过期(重放防护)', 0);
        api_json(401, ['error' => 'expired', 'message' => '签名已过期，请重新生成']);
    }

    $expected = hash_hmac('sha256', $base, $ctx['token']['api_secret']);
    if (!hash_equals($expected, $ctx['sig'])) {
        push_log($ctx['token']['id'], $ctx['key'], 'auth_fail', 'error', '签名校验失败', 0);
        api_json(401, ['error' => 'bad_signature', 'message' => '签名校验失败']);
    }
}

/**
 * 接口鉴权核心（JSON 请求体场景）。
 * 返回令牌记录数组；失败则直接输出 JSON 并 exit。
 *
 * 鉴权方式(任选其一):
 *  1) 签名方式(推荐): 头 X-Api-Key + X-Timestamp + X-Signature
 *     X-Signature = HMAC-SHA256( timestamp + "." + raw_body , api_secret ) 的 hex
 *  2) Bearer 方式:    Authorization: Bearer <api_secret>
 */
function api_require_auth(string $rawBody): array
{
    $ctx = api_auth_context();
    api_verify_signature($ctx, $ctx['ts'] . '.' . $rawBody);
    return $ctx['token'];
}

/**
 * 接口鉴权核心（multipart 文件上传场景）。
 *
 * 为什么不能复用 api_require_auth()：
 *   multipart/form-data 请求体被 PHP 直接消费进 $_FILES/$_POST，php://input 为空，
 *   拿不到可以签名的原始请求体。因此改为对「文件内容摘要」签名：
 *
 *     X-Signature = HMAC-SHA256(
 *         timestamp + "." + sha256(文件1内容) [+ "." + sha256(文件2内容) ...],
 *         api_secret
 *     ) 的 hex
 *
 *   摘要按 $_FILES 中的先后顺序拼接；每个摘要固定 64 位 hex，拼接无歧义。
 *
 * @param string[]   $fileHashes 各上传文件的 sha256（与请求中文件顺序一致）
 * @param array|null $ctx        已取得的鉴权上下文（可复用 api_auth_context() 的结果，避免重复查库）
 */
function api_require_auth_uploads(array $fileHashes, ?array $ctx = null): array
{
    $ctx ??= api_auth_context();
    api_verify_signature($ctx, $ctx['ts'] . '.' . implode('.', $fileHashes));
    return $ctx['token'];
}

/** 判定当前是否为 HTTPS */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    return false;
}

/** 统一的 API JSON 输出并退出 */
function api_json(int $code, array $data): void
{
    if (headers_sent() === false) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
