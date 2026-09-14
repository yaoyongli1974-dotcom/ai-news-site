<?php
/**
 * 接口令牌管理 (推送鉴权)。
 * 创建后密钥仅展示一次，请妥善保存。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_admin();

// 创建后一次性展示的令牌
admin_session_start();
$created = $_SESSION['created_token'] ?? null; unset($_SESSION['created_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('CSRF 校验失败', 'error'); redirect(url('/admin/api_tokens.php')); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('请填写用途说明');
            $apiKey  = bin2hex(random_bytes(16));   // 32 hex
            $apiSecret = bin2hex(random_bytes(32));  // 64 hex
            db_insert(
                "INSERT INTO `api_tokens` (`name`,`api_key`,`api_secret`,`created_by`) VALUES (?,?,?,?)",
                [$name, $apiKey, $apiSecret, $_SESSION['admin_id'] ?? null]
            );
            $_SESSION['created_token'] = ['name' => $name, 'api_key' => $apiKey, 'api_secret' => $apiSecret];
            set_flash('令牌已创建，请立即保存密钥', 'ok');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $row = db_fetch("SELECT `status` FROM `api_tokens` WHERE `id`=?", [$id]);
            if ($row) {
                $next = $row['status'] === 'active' ? 'disabled' : 'active';
                db_exec("UPDATE `api_tokens` SET `status`=? WHERE `id`=?", [$next, $id]);
                set_flash($next === 'active' ? '已启用' : '已停用', 'ok');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db_exec("DELETE FROM `api_tokens` WHERE `id`=?", [$id]);
            set_flash('令牌已删除', 'ok');
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }
    redirect(url('/admin/api_tokens.php'));
}

$list = db_fetch_all(
    "SELECT t.*, a.`username` AS creator FROM `api_tokens` t
     LEFT JOIN `admins` a ON a.`id`=t.`created_by`
     ORDER BY t.`created_at` DESC"
);

$rows = '';
foreach ($list as $t) {
    $statusBadge = $t['status'] === 'active'
        ? '<span class="badge badge-pub">启用</span>'
        : '<span class="badge badge-draft">停用</span>';
    $rows .= '<tr>'
        . '<td>' . e($t['name']) . '</td>'
        . '<td class="mono">' . e($t['api_key']) . '</td>'
        . '<td>' . $statusBadge . '</td>'
        . '<td>' . e($t['last_used_at'] ?: '从未') . '</td>'
        . '<td>' . e($t['created_at']) . '</td>'
        . '<td class="ops">'
          . '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . $t['id'] . '"><button class="btn btn-sm" type="submit">' . ($t['status']==='active'?'停用':'启用') . '</button></form> '
          . '<form method="post" style="display:inline" onsubmit="return confirm(\'确定删除该令牌？\');"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $t['id'] . '"><button class="btn btn-sm btn-danger" type="submit">删除</button></form>'
        . '</td></tr>';
}

$secretBox = '';
if ($created) {
    $secretBox = '<div class="flash flash-ok" style="white-space:pre-wrap">'
        . '新令牌「' . e($created['name']) . '」已生成，密钥仅显示这一次：' . "\n\n"
        . 'API Key:    ' . e($created['api_key']) . "\n"
        . 'API Secret: ' . e($created['api_secret']) . "\n\n"
        . '请将其配置到调用方(AI 定时任务/推送客户端)。</div>';
}

$body = $secretBox;
$body .= '<form method="post" class="admin-content" style="margin-bottom:22px"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="create">'
    . '<div class="form-row" style="max-width:360px"><label>用途说明 *</label><input type="text" name="name" placeholder="如：AI 定时推送" required></div>'
    . '<button class="btn btn-primary" type="submit">生成令牌</button></form>';

$body .= '<table class="tbl"><thead><tr><th>用途</th><th>API Key</th><th>状态</th><th>最近调用</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6" class="muted">暂无令牌，请先生成</td></tr>') . '</tbody></table>';

$body .= '<p class="muted" style="margin-top:16px">调用方需在请求头携带 X-Api-Key 与 X-Timestamp、X-Signature(对 <code>timestamp.body</code> 用 API Secret 做 HMAC-SHA256)。详见 README。</p>';

admin_layout('接口令牌', $body, 'tokens');
