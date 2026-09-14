<?php
/**
 * 后台仪表盘。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_admin();

$stats = [
    'articles'   => (int)db_fetch("SELECT COUNT(*) c FROM `articles`")['c'],
    'published'  => (int)db_fetch("SELECT COUNT(*) c FROM `articles` WHERE `status`='published'")['c'],
    'drafts'     => (int)db_fetch("SELECT COUNT(*) c FROM `articles` WHERE `status`='draft'")['c'],
    'categories' => (int)db_fetch("SELECT COUNT(*) c FROM `categories`")['c'],
    'tags'       => (int)db_fetch("SELECT COUNT(*) c FROM `tags`")['c'],
    'tokens'     => (int)db_fetch("SELECT COUNT(*) c FROM `api_tokens` WHERE `status`='active'")['c'],
];
$recent = db_fetch_all(
    "SELECT a.*, c.`name` AS category_name FROM `articles` a
     LEFT JOIN `categories` c ON c.`id`=a.`category_id`
     ORDER BY a.`created_at` DESC LIMIT 8"
);
$latestPush = db_fetch("SELECT * FROM `push_logs` ORDER BY `created_at` DESC LIMIT 1");

$cards = '';
foreach ([
    ['articles','文章总数'], ['published','已发布'], ['drafts','草稿'],
    ['categories','分类'], ['tags','标签'], ['tokens','有效令牌'],
] as [$k,$lbl]) {
    $cards .= '<div class="stat-card"><div class="num">' . $stats[$k] . '</div><div class="lbl">' . e($lbl) . '</div></div>';
}

$rows = '';
foreach ($recent as $r) {
    $rows .= '<tr>'
        . '<td>' . e($r['title']) . '</td>'
        . '<td>' . e($r['category_name'] ?: '—') . '</td>'
        . '<td><span class="badge ' . ($r['status']==='published'?'badge-pub':'badge-draft') . '">' . e($r['status']==='published'?'已发布':'草稿') . '</span></td>'
        . '<td>' . e(date('Y-m-d', strtotime($r['created_at']))) . '</td>'
        . '<td><a href="' . url('/admin/article_form.php?id=' . $r['id']) . '">编辑</a></td>'
        . '</tr>';
}

$body = '<div class="stat-cards">' . $cards . '</div>';
$body .= '<h3 style="margin-top:24px">最近文章</h3>';
$body .= '<table class="tbl"><thead><tr><th>标题</th><th>分类</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';
if ($latestPush) {
    $body .= '<p class="muted" style="margin-top:16px">最近一次推送：' . e($latestPush['created_at']) . ' · '
        . e($latestPush['status']==='success'?'成功':'失败') . ' · '
        . e($latestPush['message'] ?: '') . '</p>';
}
$body .= '<p style="margin-top:18px"><a class="btn btn-primary" href="' . url('/admin/articles.php') . '">管理文章</a>
          <a class="btn" href="' . url('/admin/api_tokens.php') . '">管理推送令牌</a></p>';

admin_layout('仪表盘', $body, 'dashboard');
