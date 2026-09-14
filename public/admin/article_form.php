<?php
/**
 * 文章新建 / 编辑。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_once SRC_DIR . '/article_service.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$article = $id > 0 ? db_fetch("SELECT * FROM `articles` WHERE `id`=?", [$id]) : null;
if ($id > 0 && !$article) {
    set_flash('文章不存在', 'error');
    redirect(url('/admin/articles.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('CSRF 校验失败', 'error');
    } else {
        try {
            $tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))));
            $data = [
                'id'           => $id > 0 ? $id : null,
                'title'        => $_POST['title'] ?? '',
                'slug'         => $_POST['slug'] ?? '',
                'summary'      => $_POST['summary'] ?? '',
                'content'      => $_POST['content'] ?? '',
                'cover_image'  => $_POST['cover_image'] ?? '',
                'author'       => $_POST['author'] ?? '',
                'source_url'   => $_POST['source_url'] ?? '',
                'source_id'    => $_POST['source_id'] ?? '',
                'category'     => $_POST['category_id'] ?? '',
                'tags'         => $tags,
                'status'       => $_POST['status'] ?? 'published',
                'published_at' => $_POST['published_at'] ?? null,
            ];
            $res = save_article($data);
            set_flash($res['action'] === 'created' ? '文章已创建' : '文章已更新', 'ok');
            redirect(url('/admin/article_form.php?id=' . $res['id']));
        } catch (Throwable $e) {
            set_flash('保存失败：' . $e->getMessage(), 'error');
        }
    }
}

$cats = get_categories();
$currentTags = $article ? implode(', ', array_column(article_tag_names((int)$article['id']), 'name')) : '';

// 表单回填
$v = $article ?? [];
$v['published_at'] = !empty($v['published_at'])
    ? date('Y-m-d\TH:i', strtotime($v['published_at']))
    : date('Y-m-d\TH:i');

$catOpts = '<option value="">未分类</option>';
foreach ($cats as $c) {
    $sel = ($v['category_id'] ?? '') == $c['id'] ? ' selected' : '';
    $catOpts .= '<option value="' . $c['id'] . '"' . $sel . '>' . e($c['name']) . '</option>';
}

$body = '<form method="post">';
$body .= '<input type="hidden" name="csrf" value="' . attr(csrf_token()) . '">';
$body .= '<div class="form-row"><label>标题 *</label><input type="text" name="title" value="' . attr($v['title'] ?? '') . '" required></div>';
$body .= '<div class="form-grid">';
$body .= '<div class="form-row"><label>URL 别名(slug，留空自动生成)</label><input type="text" name="slug" value="' . attr($v['slug'] ?? '') . '"></div>';
$body .= '<div class="form-row"><label>分类</label><select name="category_id">' . $catOpts . '</select></div>';
$body .= '</div>';
$body .= '<div class="form-grid">';
$body .= '<div class="form-row"><label>作者</label><input type="text" name="author" value="' . attr($v['author'] ?? '') . '"></div>';
$body .= '<div class="form-row"><label>状态</label><select name="status"><option value="published"' . ((($v['status']??'published')==='published')?' selected':'') . '>已发布</option><option value="draft"' . ((($v['status']??'')==='draft')?' selected':'') . '>草稿</option></select></div>';
$body .= '</div>';
$body .= '<div class="form-grid">';
$body .= '<div class="form-row"><label>发布时间</label><input type="datetime-local" name="published_at" value="' . attr($v['published_at']) . '"></div>';
$body .= '<div class="form-row"><label>封面图 URL</label><input type="url" name="cover_image" value="' . attr($v['cover_image'] ?? '') . '"></div>';
$body .= '</div>';
$body .= '<div class="form-grid">';
$body .= '<div class="form-row"><label>标签(逗号分隔)</label><input type="text" name="tags" value="' . attr($currentTags) . '" placeholder="大模型, AIGC"></div>';
$body .= '<div class="form-row"><label>原文链接</label><input type="url" name="source_url" value="' . attr($v['source_url'] ?? '') . '"></div>';
$body .= '</div>';
$body .= '<div class="form-row"><label>外部幂等 ID(推送去重用，可选)</label><input type="text" name="source_id" value="' . attr($v['source_id'] ?? '') . '"></div>';
$body .= '<div class="form-row"><label>摘要</label><textarea name="summary" style="min-height:80px">' . e($v['summary'] ?? '') . '</textarea></div>';
$body .= '<div class="form-row"><label>正文 * (支持 HTML)</label><textarea name="content" required>' . e($v['content'] ?? '') . '</textarea></div>';
$body .= '<div class="toolbar"><button class="btn btn-primary" type="submit">保存</button> <a class="btn" href="' . url('/admin/articles.php') . '">返回列表</a></div>';
$body .= '</form>';

admin_layout($id > 0 ? '编辑文章' : '新建文章', $body, 'articles');
