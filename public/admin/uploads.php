<?php
/**
 * 媒体库 —— 管理 public/<upload.dir> 下的图片与 public/<video.dir> 下的视频：
 * 浏览、复制链接、直接上传、删除。与上传接口共用同一个存放目录。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/admin_view.php';
require_once SRC_DIR . '/upload_service.php';
require_admin();

$imgCfg = upload_config();
$vidCfg = upload_video_config();

$type = (string)($_GET['type'] ?? 'image');
if ($type !== 'video') {
    $type = 'image';
}

// ---------------- 写操作 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('CSRF 校验失败，请重试', 'error');
        redirect(url('/admin/uploads.php?type=' . $type));
    }
    $action = (string)($_POST['action'] ?? '');
    $media  = (string)($_POST['media'] ?? 'image'); // image | video
    try {
        if ($action === 'upload') {
            $cfg     = $media === 'video' ? $vidCfg : $imgCfg;
            $storeFn = $media === 'video' ? 'upload_store_video' : 'upload_store_file';
            $kind    = $media === 'video' ? '视频' : '图片';
            $files   = upload_collect_files($_FILES, ['file', 'files']);
            if (!$files) {
                throw new InvalidArgumentException('请选择要上传的' . $kind);
            }
            if (count($files) > (int)$cfg['max_files']) {
                throw new InvalidArgumentException('单次最多上传 ' . (int)$cfg['max_files'] . ' 个');
            }
            $okCount = 0;
            $errs    = [];
            foreach ($files as $f) {
                try {
                    // 以后台上传时的原始文件名为主干（自动剔除非法字符），便于辨认
                    $hint = (string)pathinfo((string)($f['name'] ?? ''), PATHINFO_FILENAME);
                    $storeFn($f, $hint);
                    $okCount++;
                } catch (Throwable $e) {
                    $errs[] = basename((string)($f['name'] ?? '未命名')) . '：' . $e->getMessage();
                }
            }
            if ($okCount === 0) {
                throw new RuntimeException(implode('；', $errs) ?: '上传失败');
            }
            set_flash(
                '成功上传 ' . $okCount . ' 个' . $kind . ($errs ? '；失败 ' . count($errs) . ' 个 — ' . implode('；', $errs) : ''),
                $errs ? 'error' : 'ok'
            );
            $type = $media; // 上传后跳到对应标签页
        } elseif ($action === 'delete') {
            $name = (string)($_POST['name'] ?? '');
            if ((string)($_POST['media'] ?? 'image') === 'video') {
                upload_delete_video($name);
                set_flash('视频已删除', 'ok');
            } else {
                upload_delete_file($name);
                set_flash('图片已删除', 'ok');
            }
        }
    } catch (Throwable $e) {
        set_flash('操作失败：' . $e->getMessage(), 'error');
    }

    redirect(url('/admin/uploads.php?type=' . $type));
}

// ---------------- 列表 ----------------
if ($type === 'video') {
    $all     = upload_list_videos();
    $dir     = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$vidCfg['dir'], '/\\');
    $accept  = 'video/mp4,video/webm';
    $maxSize = (int)$vidCfg['max_size'];
    $maxFiles = (int)$vidCfg['max_files'];
    $allowed = array_keys((array)$vidCfg['allowed_mime']);
    $dirLabel = (string)$vidCfg['dir'];
} else {
    $all     = upload_list_files();
    $dir     = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$imgCfg['dir'], '/\\');
    $accept  = 'image/jpeg,image/png,image/gif,image/webp';
    $maxSize = (int)$imgCfg['max_size'];
    $maxFiles = (int)$imgCfg['max_files'];
    $allowed = array_keys((array)$imgCfg['allowed_mime']);
    $dirLabel = (string)$imgCfg['dir'];
}

$totalBytes = 0;
foreach ($all as $f) {
    $totalBytes += (int)$f['size'];
}
$pg        = paginate(count($all), 24, (int)($_GET['page'] ?? 1));
$pageItems = array_slice($all, $pg['offset'], $pg['per_page']);
$csrf      = csrf_token();
$isVideo   = $type === 'video';

ob_start();
?>
<div class="admin-content">
  <div class="toolbar" style="gap:10px;margin-bottom:14px">
    <a class="btn <?= $type === 'image' ? 'btn-primary' : 'btn-sm' ?>" href="<?= attr(url('/admin/uploads.php?type=image')) ?>">图片</a>
    <a class="btn <?= $type === 'video' ? 'btn-primary' : 'btn-sm' ?>" href="<?= attr(url('/admin/uploads.php?type=video')) ?>">视频</a>
  </div>
</div>

<div class="admin-content" style="margin-bottom:18px">
  <h3 style="margin-top:0">上传<?= $isVideo ? '视频' : '图片' ?></h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= attr($csrf) ?>">
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="media" value="<?= $type ?>">
    <div class="form-row">
      <label for="up-files">选择<?= $isVideo ? '视频' : '图片' ?>（可多选）</label>
      <input id="up-files" type="file" name="files[]" accept="<?= attr($accept) ?>" multiple>
      <div class="hint">
        单文件上限 <?= e(upload_human_size($maxSize)) ?>，单次最多 <?= $maxFiles ?> 个；
        支持 <?= e(implode(' / ', $allowed)) ?>；
        存放于 <span class="mono">public/<?= e($dirLabel) ?></span>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">开始上传</button>
  </form>
</div>

<div class="admin-content">
  <div class="toolbar" style="justify-content:space-between;align-items:center">
    <h3 style="margin:0"><?= $isVideo ? '视频库' : '图片库' ?></h3>
    <span class="hint">共 <?= (int)count($all) ?> 个 · <?= e(upload_human_size($totalBytes)) ?></span>
  </div>
  <?php if (!$pageItems): ?>
    <p class="muted">还没有<?= $isVideo ? '视频' : '图片' ?>。上传后这里会显示缩略图与可复制的链接。</p>
  <?php else: ?>
    <div class="media-grid">
      <?php foreach ($pageItems as $f): ?>
        <div class="media-card">
          <div class="media-thumb">
            <?php if ($isVideo): ?>
              <video src="<?= attr($f['url']) ?>" controls preload="metadata"
                     style="width:100%;height:100%;object-fit:cover;background:#000"></video>
            <?php else: ?>
              <?php
              $dim = '';
              $info = @getimagesize($dir . '/' . $f['name']);
              if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
                  $dim = (int)$info[0] . '×' . (int)$info[1];
              }
              ?>
              <img src="<?= attr($f['path']) ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
          </div>
          <div class="media-meta">
            <div class="media-name mono" title="<?= attr($f['name']) ?>"><?= e($f['name']) ?></div>
            <div class="media-sub">
              <?= e(upload_human_size((int)$f['size'])) ?><?= isset($dim) && $dim !== '' ? ' · ' . e($dim) : '' ?>
              · <?= e(date('Y-m-d H:i', (int)$f['mtime'])) ?>
            </div>
            <div class="media-ops">
              <button type="button" class="btn btn-sm" data-copy="<?= attr($f['url']) ?>">复制链接</button>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('确定删除这个<?= $isVideo ? '视频' : '图片' ?>？已引用它的文章会显示裂图/失效。');">
                <input type="hidden" name="csrf" value="<?= attr($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="media" value="<?= $type ?>">
                <input type="hidden" name="name" value="<?= attr($f['name']) ?>">
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
