<?php
/**
 * 专题管理 (CRUD + 文章关联)。
 * 列表与表单合并：?edit=<id> 进入编辑，否则展示「新建专题」表单 + 列表。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_once SRC_DIR . '/topic_service.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('CSRF 校验失败', 'error'); redirect(url('/admin/topics.php')); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new InvalidArgumentException('标题不能为空');
            $articleIds = $_POST['article_ids'] ?? [];
            if (!is_array($articleIds)) $articleIds = [];
            $res = save_topic([
                'id'           => $id,
                'title'        => $title,
                'slug'         => $_POST['slug'] ?? '',
                'summary'      => $_POST['summary'] ?? '',
                'description'  => $_POST['description'] ?? '',
                'cover_image'  => $_POST['cover_image'] ?? '',
                'status'       => $_POST['status'] ?? 'published',
                'sort_order'   => (int)($_POST['sort_order'] ?? 0),
                'article_ids'  => $articleIds,
            ]);
            set_flash($res['action'] === 'created' ? '专题已创建' : '专题已更新', 'ok');
            redirect(url('/admin/topics.php?edit=' . $res['id']));
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            delete_topic($id);
            set_flash('专题已删除（关联同步移除）', 'ok');
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }
    redirect(url('/admin/topics.php'));
}

// 编辑回填
$edit = null;
$editArticleIds = [];
if (isset($_GET['edit'])) {
    $edit = get_topic_by_id((int)$_GET['edit']);
    if ($edit) {
        $rows = db_fetch_all("SELECT `article_id` FROM `topic_articles` WHERE `topic_id`=? ORDER BY `sort_order` ASC", [$edit['id']]);
        $editArticleIds = array_column($rows, 'article_id');
    }
}

$list = get_all_topics(false);

// 已发布文章（供关联多选）
$articlesOpts = db_fetch_all(
    "SELECT `id`, `title`, COALESCE(`published_at`,`created_at`) AS dt
     FROM `articles` WHERE `status`='published' ORDER BY dt DESC, `id` DESC"
);
$selSet = array_flip($editArticleIds);
$articleOptions = '';
foreach ($articlesOpts as $a) {
    $s = isset($selSet[$a['id']]) ? ' selected' : '';
    $articleOptions .= '<option value="' . $a['id'] . '"' . $s . '>' . e($a['title']) . '</option>';
}

$rows = '';
foreach ($list as $t) {
    $rows .= '<tr>'
        . '<td><a href="' . topic_url($t['slug']) . '" target="_blank">' . e($t['title']) . '</a></td>'
        . '<td class="mono">' . e($t['slug']) . '</td>'
        . '<td>' . (int)$t['article_count'] . '</td>'
        . '<td><span class="badge ' . ($t['status']==='published'?'badge-pub':'badge-draft') . '">' . e($t['status']==='published'?'已发布':'草稿') . '</span></td>'
        . '<td>' . (int)$t['sort_order'] . '</td>'
        . '<td class="ops">'
          . '<a class="btn btn-sm" href="' . url('/admin/topics.php?edit=' . $t['id']) . '">编辑</a> '
          . '<form method="post" style="display:inline" data-confirm="确定删除该专题？关联文章不会被删除，仅解除关联。">'
          . '<input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
          . '<input type="hidden" name="action" value="delete">'
          . '<input type="hidden" name="id" value="' . $t['id'] . '">'
          . '<button class="btn btn-sm btn-danger" type="submit">删除</button>'
          . '</form>'
        . '</td></tr>';
}

$coverPreview = '';
if (!empty($edit['cover_image'])) {
    $coverPreview = '<div class="brand-preview"><img src="' . e($edit['cover_image']) . '" alt="封面预览"></div>';
}

$body = '<form method="post" class="admin-content" style="margin-bottom:22px"><input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="save">'
    . '<input type="hidden" name="id" value="' . ($edit['id'] ?? 0) . '">'
    . '<h3>' . ($edit ? '编辑专题' : '新建专题') . '</h3>'
    . '<div class="form-row"><label>标题 *</label><input type="text" name="title" value="' . attr($edit['title'] ?? '') . '" required></div>'
    . '<div class="form-grid">'
      . '<div class="form-row"><label>URL 别名(slug，留空自动生成)</label><input type="text" name="slug" value="' . attr($edit['slug'] ?? '') . '"></div>'
      . '<div class="form-row"><label>排序(越小越靠前)</label><input type="number" name="sort_order" value="' . attr($edit['sort_order'] ?? 0) . '"></div>'
    . '</div>'
    . '<div class="form-row"><label>简介(列表卡片展示，≤500 字)</label><textarea name="summary" style="min-height:70px">' . e($edit['summary'] ?? '') . '</textarea></div>'
    . '<div class="form-row"><label>详细描述(详情页正文，可空，支持 HTML)</label><textarea name="description" style="min-height:120px">' . e($edit['description'] ?? '') . '</textarea></div>'
    . '<div class="form-grid">'
      . '<div class="form-row"><label>封面图 URL</label><input type="url" name="cover_image" value="' . attr($edit['cover_image'] ?? '') . '">' . $coverPreview . '</div>'
      . '<div class="form-row"><label>状态</label><select name="status"><option value="published"' . ((($edit['status']??'published')==='published')?' selected':'') . '>已发布</option><option value="draft"' . ((($edit['status']??'')==='draft')?' selected':'') . '>草稿</option></select></div>'
    . '</div>'
    . '<div class="form-row"><label>关联文章(按住 Ctrl/Cmd 多选；顺序即专题内展示顺序)</label>'
      . '<select name="article_ids[]" multiple class="topic-article-select">' . $articleOptions . '</select>'
      . '<div class="hint">仅显示已发布文章。未在此列出的文章不会出现在该专题下。</div></div>'
    . '<button class="btn btn-primary" type="submit">保存</button>'
    . ($edit ? ' <a class="btn" href="' . url('/admin/topics.php') . '">取消编辑</a>' : '')
    . '</form>';

$body .= '<table class="tbl"><thead><tr><th>标题</th><th>slug</th><th>文章数</th><th>状态</th><th>排序</th><th>操作</th></tr></thead><tbody>'
    . ($rows ?: '<tr><td colspan="6" class="muted">暂无专题</td></tr>')
    . '</tbody></table>';

admin_layout('专题管理', $body, 'topics');
