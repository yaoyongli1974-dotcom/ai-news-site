<?php
/**
 * 媒体库 —— 管理 public/<upload.dir> 下的图片：浏览、复制链接、直接上传、删除。
 * 与推送接口共用同一个存放目录，因此 AI 上传的图片也会出现在这里。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_once SRC_DIR . '/upload_service.php';
require_admin();

$cfg = upload_config();

// ---------------- 写操作 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('CSRF 校验失败，请重试', 'error');
        redirect(url('/admin/uploads.php'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            $files = upload_collect_files($_FILES, ['file', 'files']);
            if (!$files) {
                throw new InvalidArgumentException('请选择要上传的图片');
            }
            if (count($files) > (int)$cfg['max_files']) {
                throw new InvalidArgumentException('单次最多上传 ' . (int)$cfg['max_files'] . ' 张');
            }
            $okCount = 0;
            $errs    = [];
            foreach ($files as $f) {
                try {
                    // 以后台上传时的原始文件名为主干（自动剔除非法字符），便于辨认
                    $hint = (string)pathinfo((string)($f['name'] ?? ''), PATHINFO_FILENAME);
                    upload_store_file($f, $hint);
                    $okCount++;
                } catch (Throwable $e) {
                    $errs[] = basename((string)($f['name'] ?? '未命名')) . '：' . $e->getMessage();
                }
            }
            if ($okCount === 0) {
                throw new RuntimeException(implode('；', $errs) ?: '上传失败');
            }
            set_flash(
                '成功上传 ' . $okCount . ' 张图片' . ($errs ? '；失败 ' . count($errs) . ' 张 — ' . implode('；', $errs) : ''),
                $errs ? 'error' : 'ok'
            );
        } elseif ($action === 'delete') {
            upload_delete_file((string)($_POST['name'] ?? ''));
            set_flash('图片已删除', 'ok');
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }

    $back   = '/admin/uploads.php';
    $pageNo = (int)($_POST['page'] ?? 0);
    if ($pageNo > 1) {
        $back .= '?page=' . $pageNo;
    }
    redirect(url($back));
}

// ---------------- 列表 ----------------
$all        = upload_list_files();
$totalBytes = 0;
foreach ($all as $f) {
    $totalBytes += (int)$f['size'];
}
$pg        = paginate(count($all), 24, (int)($_GET['page'] ?? 1));
$pageItems = array_slice($all, $pg['offset'], $pg['per_page']);
$uploadDir = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
$csrf      = csrf_token();

ob_start();
?>
<div class="admin-content" style="margin-bottom:18px">
  <h3 style="margin-top:0">上传图片</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= attr($csrf) ?>">
    <input type="hidden" name="action" value="upload">
    <div class="form-row">
      <label for="up-files">选择图片（可多选）</label>
      <input id="up-files" type="file" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
      <div class="hint">
        单张上限 <?= e(upload_human_size((int)$cfg['max_size'])) ?>，单次最多 <?= (int)$cfg['max_files'] ?> 张；
        支持 <?= e(implode(' / ', array_values((array)$cfg['allowed_mime']))) ?>；
        存放于 <span class="mono">public/<?= e((string)$cfg['dir']) ?></span>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">开始上传</button>
  </form>
</div>

<div class="admin-content">
  <div class="toolbar" style="justify-content:space-between;align-items:center">
    <h3 style="margin:0">图片库</h3>
    <span class="hint">共 <?= (int)count($all) ?> 张 · <?= e(upload_human_size($totalBytes)) ?></span>
  </div>
  <?php if (!$pageItems): ?>
    <p class="muted">还没有图片。上传后这里会显示缩略图与可复制的链接。</p>
  <?php else: ?>
    <div class="media-grid">
      <?php foreach ($pageItems as $f): ?>
        <?php
        $dim  = '';
        $info = @getimagesize($uploadDir . '/' . $f['name']);
        if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
            $dim = (int)$info[0] . '×' . (int)$info[1];
        }
        ?>
        <div class="media-card">
          <div class="media-thumb">
            <img src="<?= attr($f['path']) ?>" alt="" loading="lazy" decoding="async">
          </div>
          <div class="media-meta">
            <div class="media-name mono" title="<?= attr($f['name']) ?>"><?= e($f['name']) ?></div>
            <div class="media-sub">
              <?= e(upload_human_size((int)$f['size'])) ?><?= $dim !== '' ? ' · ' . e($dim) : '' ?>
              · <?= e(date('Y-m-d H:i', (int)$f['mtime'])) ?>
            </div>
            <div class="media-ops">
              <button type="button" class="btn btn-sm" data-copy="<?= attr($f['url']) ?>">复制链接</button>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('确定删除这张图片？已引用它的文章会显示裂图。');">
                <input type="hidden" name="csrf" value="<?= attr($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="name" value="<?= attr($f['name']) ?>">
                <input type="hidden" name="page" value="<?= (int)$pg['page'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">删除</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?= render_pagination($pg) ?>
  <?php endif; ?>
</div>

<script>
// 复制链接：优先 Clipboard API，非安全上下文退回 execCommand
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest('[data-copy]');
  if (!btn) { return; }
  var text  = btn.getAttribute('data-copy');
  var label = btn.textContent;
  var done  = function () {
    btn.textContent = '已复制';
    setTimeout(function () { btn.textContent = label; }, 1200);
  };
  var fallback = function () {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); }
    catch (e) { window.prompt('请手动复制链接', text); }
    document.body.removeChild(ta);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done, fallback);
  } else {
    fallback();
  }
});
</script>
<?php
$body = (string)ob_get_clean();
admin_layout('媒体库', $body, 'uploads');
