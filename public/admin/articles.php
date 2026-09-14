<?php
/**
 * 文章列表 + 删除。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_admin();

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        set_flash('CSRF 校验失败', 'error');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        db_exec("DELETE FROM `articles` WHERE `id`=?", [$id]);
        set_flash('文章已删除', 'ok');
    }
    redirect(url('/admin/articles.php'));
}

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 15;
$where = '1';
$params = [];
if ($status === 'published' || $status === 'draft') { $where .= ' AND a.`status`=?'; $params[] = $status; }
if ($q !== '') { $where .= ' AND a.`title` LIKE ?'; $params[] = '%' . $q . '%'; }

$total = (int)db_fetch(
    "SELECT COUNT(*) c FROM `articles` a WHERE {$where}", $params
)['c'];
$p = paginate($total, $per, $page);

$listParams = array_merge($params, [$p['per_page'], $p['offset']]);
$articles = db_fetch_all(
    "SELECT a.*, c.`name` AS category_name FROM `articles` a
     LEFT JOIN `categories` c ON c.`id`=a.`category_id`
     WHERE {$where} ORDER BY COALESCE(a.`published_at`, a.`created_at`) DESC, a.`id` DESC
     LIMIT ? OFFSET ?",
    $listParams
);

$rows = '';
foreach ($articles as $r) {
    $rows .= '<tr>'
        . '<td><a href="' . url('/article.php?slug=' . rawurlencode($r['slug'])) . '" target="_blank">' . e($r['title']) . '</a></td>'
        . '<td>' . e($r['category_name'] ?: '—') . '</td>'
        . '<td><span class="badge ' . ($r['status']==='published'?'badge-pub':'badge-draft') . '">' . e($r['status']==='published'?'已发布':'草稿') . '</span></td>'
        . '<td>' . (int)$r['views'] . '</td>'
        . '<td>' . e(date('Y-m-d', strtotime($r['published_at'] ?? $r['created_at']))) . '</td>'
        . '<td class="ops">'
          . '<a class="btn btn-sm" href="' . url('/admin/article_form.php?id=' . $r['id']) . '">编辑</a> '
          . '<form method="post" style="display:inline" onsubmit="return confirm(\'确定删除该文章？\');">'
          . '<input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
          . '<input type="hidden" name="action" value="delete">'
          . '<input type="hidden" name="id" value="' . $r['id'] . '">'
          . '<button class="btn btn-sm btn-danger" type="submit">删除</button>'
          . '</form>'
        . '</td></tr>';
}

$filter = '<a class="' . ($status===''?'active':'') . '" href="' . url('/admin/articles.php') . '">全部</a> '
        . '<a class="' . ($status==='published'?'active':'') . '" href="' . url('/admin/articles.php?status=published') . '">已发布</a> '
        . '<a class="' . ($status==='draft'?'active':'') . '" href="' . url('/admin/articles.php?status=draft') . '">草稿</a>';

$body = '<div class="toolbar"><a class="btn btn-primary" href="' . url('/admin/article_form.php') . '">+ 新建文章</a></div>';
$body .= '<form class="toolbar" method="get"><input type="text" name="q" placeholder="搜索标题" value="' . attr($q) . '" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px"> <button class="btn" type="submit">搜索</button> <span class="muted">' . $filter . '</span></form>';
$body .= '<table class="tbl"><thead><tr><th>标题</th><th>分类</th><th>状态</th><th>阅读</th><th>日期</th><th>操作</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6" class="muted">暂无文章</td></tr>') . '</tbody></table>';
$body .= render_pagination($p);

admin_layout('文章管理', $body, 'articles');
