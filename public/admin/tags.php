<?php
/**
 * 标签管理 (CRUD)。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('CSRF 校验失败', 'error'); redirect(url('/admin/tags.php')); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('名称不能为空');
            $slug = slugify((string)($_POST['slug'] ?? $name));
            if ($id > 0) {
                if (db_exists('tags', '`slug`=? AND `id`<>?', [$slug, $id])) throw new InvalidArgumentException('slug 已存在');
                db_exec("UPDATE `tags` SET `name`=?,`slug`=? WHERE `id`=?", [$name, $slug, $id]);
                set_flash('标签已更新', 'ok');
            } else {
                if (db_exists('tags', '`slug`=?', [$slug])) throw new InvalidArgumentException('slug 已存在');
                db_insert("INSERT INTO `tags` (`name`,`slug`) VALUES (?,?)", [$name, $slug]);
                set_flash('标签已添加', 'ok');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db_exec("DELETE FROM `tags` WHERE `id`=?", [$id]);
            set_flash('标签已删除', 'ok');
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }
    redirect(url('/admin/tags.php'));
}

$edit = isset($_GET['edit']) ? db_fetch("SELECT * FROM `tags` WHERE `id`=?", [(int)$_GET['edit']]) : null;
$list = db_fetch_all(
    "SELECT t.*, COUNT(at.article_id) cnt FROM `tags` t
     LEFT JOIN `article_tags` at ON at.tag_id=t.id GROUP BY t.id ORDER BY cnt DESC, t.id ASC"
);

$rows = '';
foreach ($list as $t) {
    $rows .= '<tr><td>' . e($t['name']) . '</td><td class="mono">' . e($t['slug']) . '</td><td>' . (int)$t['cnt'] . '</td>'
        . '<td class="ops"><a class="btn btn-sm" href="' . url('/admin/tags.php?edit=' . $t['id']) . '">编辑</a> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'确定删除？关联将解除\');"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $t['id'] . '"><button class="btn btn-sm btn-danger" type="submit">删除</button></form></td></tr>';
}

$body = '<form method="post" class="admin-content" style="margin-bottom:22px"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . ($edit['id'] ?? 0) . '">'
    . '<h3>' . ($edit ? '编辑标签' : '新增标签') . '</h3>'
    . '<div class="form-grid">'
    . '<div class="form-row"><label>名称 *</label><input type="text" name="name" value="' . attr($edit['name'] ?? '') . '" required></div>'
    . '<div class="form-row"><label>slug</label><input type="text" name="slug" value="' . attr($edit['slug'] ?? '') . '"></div>'
    . '</div><button class="btn btn-primary" type="submit">保存</button></form>';

$body .= '<table class="tbl"><thead><tr><th>名称</th><th>slug</th><th>文章数</th><th>操作</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4" class="muted">暂无标签</td></tr>') . '</tbody></table>';

admin_layout('标签管理', $body, 'tags');
