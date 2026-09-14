<?php
/**
 * 分类管理 (CRUD)。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('CSRF 校验失败', 'error'); redirect(url('/admin/categories.php')); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('名称不能为空');
            $slug = slugify((string)($_POST['slug'] ?? $name));
            $desc = (string)($_POST['description'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($id > 0) {
                // slug 唯一检查(排除自身)
                if (db_exists('categories', '`slug`=? AND `id`<>?', [$slug, $id])) throw new InvalidArgumentException('slug 已存在');
                db_exec("UPDATE `categories` SET `name`=?,`slug`=?,`description`=?,`sort_order`=? WHERE `id`=?", [$name, $slug, $desc, $sort, $id]);
                set_flash('分类已更新', 'ok');
            } else {
                if (db_exists('categories', '`slug`=?', [$slug])) throw new InvalidArgumentException('slug 已存在');
                db_insert("INSERT INTO `categories` (`name`,`slug`,`description`,`sort_order`) VALUES (?,?,?,?)", [$name, $slug, $desc, $sort]);
                set_flash('分类已添加', 'ok');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db_exec("DELETE FROM `categories` WHERE `id`=?", [$id]);
            set_flash('分类已删除(文章分类置空)', 'ok');
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }
    redirect(url('/admin/categories.php'));
}

$edit = null;
if (isset($_GET['edit'])) {
    $edit = db_fetch("SELECT * FROM `categories` WHERE `id`=?", [(int)$_GET['edit']]);
}
$list = get_categories();

$rows = '';
foreach ($list as $c) {
    $rows .= '<tr><td>' . e($c['name']) . '</td><td class="mono">' . e($c['slug']) . '</td><td>' . e($c['description']) . '</td><td>' . (int)$c['sort_order'] . '</td>'
        . '<td class="ops"><a class="btn btn-sm" href="' . url('/admin/categories.php?edit=' . $c['id']) . '">编辑</a> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'确定删除？\');"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $c['id'] . '"><button class="btn btn-sm btn-danger" type="submit">删除</button></form></td></tr>';
}

$body = '<form method="post" class="admin-content" style="margin-bottom:22px"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="save">'
    . '<input type="hidden" name="id" value="' . ($edit['id'] ?? 0) . '">'
    . '<h3>' . ($edit ? '编辑分类' : '新增分类') . '</h3>'
    . '<div class="form-grid">'
    . '<div class="form-row"><label>名称 *</label><input type="text" name="name" value="' . attr($edit['name'] ?? '') . '" required></div>'
    . '<div class="form-row"><label>slug</label><input type="text" name="slug" value="' . attr($edit['slug'] ?? '') . '"></div>'
    . '<div class="form-row"><label>描述</label><input type="text" name="description" value="' . attr($edit['description'] ?? '') . '"></div>'
    . '<div class="form-row"><label>排序</label><input type="number" name="sort_order" value="' . attr($edit['sort_order'] ?? 0) . '"></div>'
    . '</div>'
    . '<button class="btn btn-primary" type="submit">保存</button></form>';

$body .= '<table class="tbl"><thead><tr><th>名称</th><th>slug</th><th>描述</th><th>排序</th><th>操作</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="muted">暂无分类</td></tr>') . '</tbody></table>';

admin_layout('分类管理', $body, 'categories');
