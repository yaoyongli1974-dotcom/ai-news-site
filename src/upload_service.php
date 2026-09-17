<?php
/**
 * 图片上传服务层 —— 校验、安全命名、落盘。
 * 后台表单(admin/uploads.php)与推送接口(api/upload.php)共用，逻辑只有一份。
 *
 * 安全要点：
 *  1. 扩展名由「服务端检测出的真实 MIME」决定，绝不采信客户端文件名
 *     —— 因此 `shell.php.jpg` 这类双扩展名攻击天然无效。
 *  2. finfo + getimagesize 双重校验，拒绝「改了后缀的非图片」与 PHP 混写文件。
 *  3. 文件名只保留 [A-Za-z0-9-_]，且与已有文件冲突时自动追加随机后缀。
 *  4. 像素总量上限，防「小体积、超大解压尺寸」的像素炸弹。
 *  5. 可选 JPEG 重编码，剥离 EXIF/GPS 及藏在元数据里的载荷（先写临时文件再原子替换）。
 */
declare(strict_types=1);

/**
 * 读取上传配置（与默认值合并）。
 */
function upload_config(): array
{
    $cfg = $GLOBALS['APP_CONFIG']['upload'] ?? [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    return array_merge([
        'dir'          => 'assets/uploads',
        'url_path'     => '/assets/uploads',
        'max_size'     => 5 * 1024 * 1024,
        'max_files'    => 10,
        'max_pixels'   => 40000000,
        'filename_max' => 60,
        'strip_exif'   => true,
        'jpeg_quality' => 90,
        'allowed_mime' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ],
    ], $cfg);
}

/**
 * 上传目录绝对路径；不存在自动创建。
 * @throws RuntimeException 目录无法创建或不可写
 */
function upload_dir(array $cfg = null): string
{
    $cfg = $cfg ?? upload_config();
    $dir = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
    if (!is_dir($dir)) {
        // 递归创建；并发下 mkdir 可能失败但目录已被别的进程建好，故再判一次
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('上传目录创建失败：' . $cfg['dir'] . '（请检查 public/ 是否可写）');
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('上传目录不可写：' . $cfg['dir'] . '（请检查目录权限，建议 755 且属主为容器内 www 用户）');
    }
    return $dir;
}

/**
 * 把 $_FILES 中的单文件/多文件字段统一规整为「文件数组的列表」。
 * 支持 `file`（单）与 `files[]`（多，PHP 会把结构转置，这里还原）。
 *
 * @param array    $files  $_FILES
 * @param string[] $fields 需要收集的字段名
 */
function upload_collect_files(array $files, array $fields = ['file', 'files']): array
{
    $out = [];
    foreach ($fields as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            continue;
        }
        $f = $files[$field];

        // 单文件：$f['name'] 是字符串
        if (!is_array($f['name'] ?? null)) {
            $out[] = $f;
            continue;
        }

        // 多文件：各键都是等长数组，按索引还原
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name'     => $f['name'][$i]     ?? '',
                'type'     => $f['type'][$i]     ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error'    => $f['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $f['size'][$i]     ?? 0,
            ];
        }
    }
    return $out;
}

/**
 * 检测文件真实 MIME（不信任客户端）。
 */
function upload_detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $mime = finfo_file($fi, $path);
            finfo_close($fi);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    // 兜底：用 getimagesize 反推
    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return strtolower((string)$info['mime']);
        }
    }
    return 'application/octet-stream';
}

/**
 * 文件名主干安全化：只保留 [A-Za-z0-9-_]，中文等非 ASCII 会被剔除。
 * 为空时用「日期-随机」兜底。
 */
function upload_safe_stem(string $name, array $cfg = null): string
{
    $cfg  = $cfg ?? upload_config();
    $name = trim($name);

    // 去掉用户给的扩展名——落盘扩展名一律由真实 MIME 决定
    $name = (string)preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name);
    // 非 ASCII-safe 字符统一折叠为连字符
    $name = (string)preg_replace('/[^A-Za-z0-9]+/', '-', $name);
    $name = trim($name, '-');

    $max = (int)$cfg['filename_max'];
    if ($max > 0 && strlen($name) > $max) {
        $name = rtrim(substr($name, 0, $max), '-');
    }
    if ($name === '') {
        $name = date('Ymd') . '-' . bin2hex(random_bytes(6));
    }
    return $name;
}

/**
 * 生成不冲突的目标路径。
 */
function upload_unique_path(string $dir, string $stem, string $ext): string
{
    $path = $dir . '/' . $stem . '.' . $ext;
    if (!file_exists($path)) {
        return $path;
    }
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . '/' . $stem . '-' . substr(bin2hex(random_bytes(4)), 0, 4) . '.' . $ext;
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }
    return $dir . '/' . $stem . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/**
 * PHP 上传错误码 → 中文提示。
 */
function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return '文件超过服务器上限 upload_max_filesize（当前 ' . ini_get('upload_max_filesize') . '）';
        case UPLOAD_ERR_FORM_SIZE:
            return '文件超过表单 max_file_size 限制';
        case UPLOAD_ERR_PARTIAL:
            return '文件只上传了一部分，请重试';
        case UPLOAD_ERR_NO_FILE:
            return '没有收到文件（字段名应为 file 或 files[]）';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '服务器缺少临时目录，无法接收上传';
        case UPLOAD_ERR_CANT_WRITE:
            return '服务器磁盘写入失败';
        case UPLOAD_ERR_EXTENSION:
            return '上传被 PHP 扩展中止';
        default:
            return '上传失败（错误码 ' . $code . '）';
    }
}

/**
 * 字节数转可读文本。
 */
function upload_human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * 读取 php.ini 的容量型配置(如 8M / 512K)并转为字节。
 */
function upload_ini_bytes(string $key): int
{
    $val = (string)ini_get($key);
    if ($val === '') {
        return 0;
    }
    $unit = strtolower(substr($val, -1));
    $num  = (int)$val;
    switch ($unit) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default:  return $num;
    }
}

/**
 * JPEG 重编码以剥离 EXIF/GPS 等元数据。
 * 先写临时文件、成功后再原子替换，避免中途失败把原图写坏；失败则保留原文件。
 */
function upload_strip_jpeg_metadata(string $path, string $mime, int $quality): void
{
    if ($mime !== 'image/jpeg' || !function_exists('imagecreatefromjpeg')) {
        return;
    }
    $img = @imagecreatefromjpeg($path);
    if ($img === false) {
        return;
    }
    $tmp = $path . '.tmp';
    $ok  = @imagejpeg($img, $tmp, max(1, min(100, $quality)));
    imagedestroy($img);
    if ($ok && is_file($tmp) && (int)filesize($tmp) > 0) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

/**
 * 处理并保存一个上传文件。
 *
 * @param array  $file     $_FILES 中的单个文件项
 * @param string $nameHint 文件名主干（可选，不含扩展名）
 * @return array 文件元数据：name/path/url/size/mime/width/height/sha256
 * @throws RuntimeException 任一校验或落盘环节失败
 */
function upload_store_file(array $file, string $nameHint = ''): array
{
    $cfg = upload_config();

    // 1) PHP 层错误
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($err));
    }

    // 2) 必须是本次请求真正上传的临时文件（防伪造路径）
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('无效的上传文件来源');
    }

    // 3) 大小
    $size = (int)filesize($tmp);
    if ($size <= 0) {
        throw new RuntimeException('文件内容为空');
    }
    if ($size > (int)$cfg['max_size']) {
        throw new RuntimeException('图片超过大小上限 ' . upload_human_size((int)$cfg['max_size'])
            . '（当前 ' . upload_human_size($size) . '）');
    }

    // 4) 真实类型白名单
    $mime = upload_detect_mime($tmp);
    $map  = (array)$cfg['allowed_mime'];
    if (!isset($map[$mime])) {
        throw new RuntimeException('不支持的文件类型：' . $mime . '（仅允许 ' . implode(', ', array_keys($map)) . '）');
    }

    // 5) 图片结构校验 + 像素上限
    $info = @getimagesize($tmp);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('不是有效的图片文件（可能已损坏或被改名）');
    }
    $width  = (int)$info[0];
    $height = (int)$info[1];
    if ($width * $height > (int)$cfg['max_pixels']) {
        throw new RuntimeException('图片尺寸过大：' . $width . '×' . $height
            . '（上限 ' . (int)$cfg['max_pixels'] . ' 像素）');
    }

    // 6) 落盘
    $ext    = (string)$map[$mime];
    $dir    = upload_dir();
    $target = upload_unique_path($dir, upload_safe_stem($nameHint), $ext);
    if (!@move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('文件写入失败，请检查上传目录权限');
    }
    @chmod($target, 0644);

    // 7) 可选：剥离 EXIF
    if (!empty($cfg['strip_exif'])) {
        upload_strip_jpeg_metadata($target, $mime, (int)$cfg['jpeg_quality']);
    }

    $fileName = basename($target);
    $relPath  = '/' . trim((string)$cfg['url_path'], '/') . '/' . $fileName;

    return [
        'name'   => $fileName,
        'path'   => $relPath,      // 站内路径，写进文章正文即可
        'url'    => url($relPath), // 绝对地址（APP_URL 拼接）
        'size'   => (int)filesize($target),
        'mime'   => $mime,
        'width'  => $width,
        'height' => $height,
        'sha256' => (string)hash_file('sha256', $target),
    ];
}

/**
 * 扫描上传目录中的图片，按修改时间倒序返回。
 * @return array<int, array{name:string,path:string,url:string,size:int,mtime:int}>
 */
function upload_list_files(): array
{
    $cfg = upload_config();
    $dir = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
    if (!is_dir($dir)) {
        return [];
    }
    $allowedExt = array_values((array)$cfg['allowed_mime']);
    $items = [];
    $paths = glob($dir . '/*');
    if (!is_array($paths)) {
        return [];
    }
    foreach ($paths as $p) {
        if (!is_file($p)) {
            continue;
        }
        $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }
        $name = basename($p);
        $items[] = [
            'name'  => $name,
            'path'  => '/' . trim((string)$cfg['url_path'], '/') . '/' . $name,
            'url'   => url('/' . trim((string)$cfg['url_path'], '/') . '/' . $name),
            'size'  => (int)filesize($p),
            'mtime' => (int)filemtime($p),
        ];
    }
    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });
    return $items;
}

/**
 * 删除上传目录中的一张图片（仅允许目录内的合法文件名）。
 * @throws RuntimeException
 */
function upload_delete_file(string $name): void
{
    $name = basename(trim($name));
    if ($name === '' || $name === '.' || $name === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        throw new RuntimeException('文件名不合法');
    }
    $cfg  = upload_config();
    $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array_values((array)$cfg['allowed_mime']), true)) {
        throw new RuntimeException('只允许删除图片文件');
    }
    $dir  = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
    $path = $dir . '/' . $name;

    // 双保险：解析真实路径后必须仍在上传目录内
    $real = realpath($path);
    $base = realpath($dir);
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('文件不存在或不在上传目录内');
    }
    if (!@unlink($real)) {
        throw new RuntimeException('删除失败，请检查目录权限');
    }
}

/**
 * 读取视频上传配置（与默认值合并）。
 */
function upload_video_config(): array
{
    $cfg = $GLOBALS['APP_CONFIG']['video'] ?? [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    return array_merge([
        'dir'          => 'assets/videos',
        'url_path'     => '/assets/videos',
        'max_size'     => 100 * 1024 * 1024,
        'max_files'    => 5,
        'filename_max' => 80,
        'allowed_mime' => [
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
        ],
    ], $cfg);
}

/**
 * 处理并保存一个上传的视频文件。
 *
 * 与图片上传的区别：视频**不做** getimagesize / 像素上限 / EXIF 剥离，
 * 仅做 MIME 白名单 + 大小上限 + 安全命名 + 落盘。
 *
 * @param array  $file     $_FILES 中的单个文件项
 * @param string $nameHint 文件名主干（可选，不含扩展名）
 * @return array 文件元数据：name/path/url/size/mime/sha256
 * @throws RuntimeException 任一校验或落盘环节失败
 */
function upload_store_video(array $file, string $nameHint = ''): array
{
    $cfg = upload_video_config();

    // 1) PHP 层错误
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($err));
    }

    // 2) 必须是本次请求真正上传的临时文件（防伪造路径）
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('无效的上传文件来源');
    }

    // 3) 大小
    $size = (int)filesize($tmp);
    if ($size <= 0) {
        throw new RuntimeException('文件内容为空');
    }
    if ($size > (int)$cfg['max_size']) {
        throw new RuntimeException('视频超过大小上限 ' . upload_human_size((int)$cfg['max_size'])
            . '（当前 ' . upload_human_size($size) . '）');
    }

    // 4) 真实类型白名单（视频不做像素/EXIF 校验）
    $mime = upload_detect_mime($tmp);
    $map  = (array)$cfg['allowed_mime'];
    if (!isset($map[$mime])) {
        throw new RuntimeException('不支持的视频格式：' . $mime . '（仅允许 ' . implode(', ', array_keys($map)) . '）');
    }

    // 5) 落盘
    $ext    = (string)$map[$mime];
    $dir    = upload_dir($cfg);
    $target = upload_unique_path($dir, upload_safe_stem($nameHint, $cfg), $ext);
    if (!@move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('文件写入失败，请检查上传目录权限');
    }
    @chmod($target, 0644);

    $fileName = basename($target);
    $relPath  = '/' . trim((string)$cfg['url_path'], '/') . '/' . $fileName;

    return [
        'name'   => $fileName,
        'path'   => $relPath,      // 站内路径，写进文章正文即可
        'url'    => url($relPath), // 绝对地址（APP_URL 拼接）
        'size'   => (int)filesize($target),
        'mime'   => $mime,
        'sha256' => (string)hash_file('sha256', $target),
    ];
}

/**
 * 扫描视频上传目录中的文件，按修改时间倒序返回。
 * @return array<int, array{name:string,path:string,url:string,size:int,mtime:int}>
 */
function upload_list_videos(): array
{
    $cfg = upload_video_config();
    $dir = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
    if (!is_dir($dir)) {
        return [];
    }
    $allowedExt = array_values((array)$cfg['allowed_mime']);
    $items = [];
    $paths = glob($dir . '/*');
    if (!is_array($paths)) {
        return [];
    }
    foreach ($paths as $p) {
        if (!is_file($p)) {
            continue;
        }
        $ext = strtolower((string)pathinfo($p, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }
        $name = basename($p);
        $items[] = [
            'name'  => $name,
            'path'  => '/' . trim((string)$cfg['url_path'], '/') . '/' . $name,
            'url'   => url('/' . trim((string)$cfg['url_path'], '/') . '/' . $name),
            'size'  => (int)filesize($p),
            'mtime' => (int)filemtime($p),
        ];
    }
    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });
    return $items;
}

/**
 * 删除视频上传目录中的文件（仅允许目录内的合法文件名）。
 * @throws RuntimeException
 */
function upload_delete_video(string $name): void
{
    $name = basename(trim($name));
    if ($name === '' || $name === '.' || $name === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        throw new RuntimeException('文件名不合法');
    }
    $cfg  = upload_video_config();
    $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array_values((array)$cfg['allowed_mime']), true)) {
        throw new RuntimeException('只允许删除视频文件');
    }
    $dir  = rtrim(PUBLIC_DIR, '/\\') . '/' . trim((string)$cfg['dir'], '/\\');
    $path = $dir . '/' . $name;

    // 双保险：解析真实路径后必须仍在上传目录内
    $real = realpath($path);
    $base = realpath($dir);
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('文件不存在或不在上传目录内');
    }
    if (!@unlink($real)) {
        throw new RuntimeException('删除失败，请检查目录权限');
    }
}

/**
 * 读取品牌图(站点 LOGO / 浏览器图标)上传配置。
 */
function upload_brand_config(): array
{
    $cfg = $GLOBALS['APP_CONFIG']['brand'] ?? [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    return array_merge([
        'dir'          => 'assets/branding',   // 相对 public/ 的存放目录(不存在会自动创建)
        'url_path'     => '/assets/branding',  // 对外 URL 前缀
        'max_size'     => 2 * 1024 * 1024,     // 品牌图较小，2MB 足够
        'filename_max' => 60,
        // 允许的类型: MIME => 落盘扩展名(扩展名由服务端按真实 MIME 决定)
        'allowed_mime' => [
            'image/jpeg'              => 'jpg',
            'image/png'               => 'png',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'image/x-icon'            => 'ico',            // 浏览器图标常用封装
            'image/vnd.microsoft.icon' => 'ico',
        ],
    ], $cfg);
}

/**
 * 处理并保存一个品牌图(站点 LOGO / 浏览器图标)。
 *
 * 与图片上传(upload_store_file)的区别：不强制 getimagesize / 像素上限 / EXIF 剥离，
 * 以保留透明背景(PNG)与 .ico 的多分辨率格式；仅做 MIME 白名单 + 大小上限 + 安全命名 + 落盘。
 * 扩展名一律由服务端检测出的真实 MIME 决定，双扩展名攻击天然无效。
 *
 * @param array  $file     $_FILES 中的单个文件项
 * @param string $nameHint 文件名主干（可选，不含扩展名）
 * @return array 文件元数据：name/path/url/size/mime
 * @throws RuntimeException 任一校验或落盘环节失败
 */
function upload_store_brand(array $file, string $nameHint = ''): array
{
    $cfg = upload_brand_config();

    // 1) PHP 层错误
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($err));
    }

    // 2) 必须是本次请求真正上传的临时文件（防伪造路径）
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('无效的上传文件来源');
    }

    // 3) 大小
    $size = (int)filesize($tmp);
    if ($size <= 0) {
        throw new RuntimeException('文件内容为空');
    }
    if ($size > (int)$cfg['max_size']) {
        throw new RuntimeException('图片超过大小上限 ' . upload_human_size((int)$cfg['max_size'])
            . '（当前 ' . upload_human_size($size) . '）');
    }

    // 4) 真实类型白名单（不依赖 getimagesize，兼容 .ico）
    $mime = upload_detect_mime($tmp);
    $map  = (array)$cfg['allowed_mime'];
    if (!isset($map[$mime])) {
        throw new RuntimeException('不支持的文件类型：' . $mime . '（仅允许 ' . implode(', ', array_keys($map)) . '）');
    }

    // 5) 落盘（保留原图，不做重编码/裁切）
    $ext    = (string)$map[$mime];
    $dir    = upload_dir($cfg);
    $target = upload_unique_path($dir, upload_safe_stem($nameHint, $cfg), $ext);
    if (!@move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('文件写入失败，请检查上传目录权限');
    }
    @chmod($target, 0644);

    $fileName = basename($target);
    $relPath  = '/' . trim((string)$cfg['url_path'], '/') . '/' . $fileName;

    return [
        'name' => $fileName,
        'path' => $relPath,      // 站内路径
        'url'  => url($relPath), // 绝对地址（APP_URL 拼接）
        'size' => (int)filesize($target),
        'mime' => $mime,
    ];
}
