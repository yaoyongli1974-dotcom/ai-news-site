<?php
/**
 * 站点配置管理 —— 可视化编辑站点基础信息，修改即时生效（存 options 表，无需改动代码）。
 * 支持: 站点名称/副标题/关键词/描述/每页条数/页脚文字/ICP 备案号/站点 LOGO/浏览器图标。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
require_once SRC_DIR . '/functions.php';
require_once SRC_DIR . '/upload_service.php';
require_once SRC_DIR . '/admin_view.php';

require_admin();

// ---- 文本类配置字段（label / 输入类型）----
$textFields = [
    'site_title'     => ['label' => '站点名称',        'type' => 'text'],
    'site_subtitle'  => ['label' => '站点副标题',      'type' => 'text'],
    'site_keywords'  => ['label' => '站点关键词(逗号分隔)', 'type' => 'text'],
    'posts_per_page' => ['label' => '每页文章数',      'type' => 'number'],
    'site_description' => ['label' => '站点描述(Meta Description)', 'type' => 'textarea'],
    'footer_text'    => ['label' => '页脚文字',        'type' => 'text'],
    'site_icp'       => ['label' => 'ICP 备案号',      'type' => 'text'],
];

// ---- 品牌图字段（URL 可手填外链，也可上传覆盖）----
$brandFields = [
    'site_logo' => ['label' => '站点 LOGO', 'hint' => '建议 PNG（透明背景）或 JPG，高度 ≤ 64px 观感最佳。可粘贴外链，或上传本地图片。'],
    'site_icon' => ['label' => '浏览器图标 (favicon)', 'hint' => '建议 .ico 或 .png，正方形。可粘贴外链，或上传本地图片。'],
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = '表单已过期或校验失败，请重试（请勿重复提交）';
    } else {
        try {
            // 1) 文本字段
            foreach ($textFields as $key => $def) {
                $val = trim((string)($_POST[$key] ?? ''));
                if ($key === 'posts_per_page') {
                    $val = (string)max(1, (int)$val);
                }
                set_option($key, $val);
            }

            // 2) 品牌图：上传优先；其次手填 URL；可勾选清除
            foreach ($brandFields as $key => $def) {
                $clear   = !empty($_POST['clear_' . $key]);
                $urlInput = trim((string)($_POST[$key] ?? ''));
                $file    = $_FILES[$key] ?? null;

                if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                    $saved = upload_store_brand($file, str_replace('site_', '', $key));
                    set_option($key, $saved['url']);
                } elseif ($clear) {
                    set_option($key, '');
                } elseif ($urlInput !== '') {
                    // 仅允许 http(s) 或站内根路径，避免 javascript: 等伪协议
                    if (preg_match('#^https?://#i', $urlInput) || str_starts_with($urlInput, '/')) {
                        set_option($key, $urlInput);
                    } else {
                        throw new RuntimeException('「' . $def['label'] . '」链接需以 http(s):// 或 / 开头');
                    }
                }
                // 三者皆无：保留原值
            }

            set_flash('站点配置已保存，已即时生效', 'ok');
            redirect(url('/admin/settings.php'));
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

// ---- 当前值（GET 或 POST 失败后回填）----
$values = [];
foreach ($textFields as $key => $def) {
    $values[$key] = (string)($_POST[$key] ?? get_option($key, ''));
}
foreach ($brandFields as $key => $def) {
    $values[$key] = (string)($_POST[$key] ?? get_option($key, ''));
}

// ---- 渲染 ----
$body = '';
if ($error !== '') {
    $body .= '<div class="flash flash-error">' . e($error) . '</div>';
}

$body .= '<form method="post" enctype="multipart/form-data" class="settings-form">';
$body .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';

// 分组：基础信息
$body .= '<fieldset class="set-group"><legend>基础信息</legend><div class="form-grid">';
foreach (['site_title', 'site_subtitle', 'site_keywords', 'posts_per_page'] as $k) {
    $def = $textFields[$k];
    $body .= '<div class="form-row">'
        . '<label for="' . $k . '">' . e($def['label']) . '</label>'
        . '<input type="' . $def['type'] . '" id="' . $k . '" name="' . $k . '" value="' . attr($values[$k]) . '">'
        . '</div>';
}
$body .= '</div></fieldset>';

// 分组：SEO 与备案
$body .= '<fieldset class="set-group"><legend>SEO 与页脚 / 备案</legend>';
$body .= '<div class="form-row">'
    . '<label for="site_description">' . e($textFields['site_description']['label']) . '</label>'
    . '<textarea id="site_description" name="site_description" style="min-height:90px">' . e($values['site_description']) . '</textarea>'
    . '</div>';
$body .= '<div class="form-grid">';
foreach (['footer_text', 'site_icp'] as $k) {
    $def = $textFields[$k];
    $body .= '<div class="form-row">'
        . '<label for="' . $k . '">' . e($def['label']) . '</label>'
        . '<input type="text" id="' . $k . '" name="' . $k . '" value="' . attr($values[$k]) . '">'
        . '</div>';
}
$body .= '</div></fieldset>';

// 分组：品牌视觉
$body .= '<fieldset class="set-group"><legend>品牌视觉（LOGO / 图标）</legend><div class="form-grid">';
foreach ($brandFields as $k => $def) {
    $cur = $values[$k];
    $body .= '<div class="form-row">'
        . '<label>' . e($def['label']) . '</label>';
    if ($cur !== '') {
        $body .= '<div class="brand-preview"><img src="' . e($cur) . '" alt="' . e($def['label']) . '"></div>'
            . '<div class="brand-current">当前：' . e($cur) . '</div>';
    }
    $body .= '<input type="url" name="' . $k . '" placeholder="可粘贴外链 URL" value="' . attr($cur) . '">'
        . '<input type="file" name="' . $k . '" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon">'
        . '<label class="chk"><input type="checkbox" name="clear_' . $k . '"> 清除（使用文字/默认图标）</label>'
        . '<p class="hint">' . e($def['hint']) . '</p>'
        . '</div>';
}
$body .= '</div></fieldset>';

$body .= '<div class="form-actions"><button type="submit" class="btn btn-primary">保存配置</button>'
    . '<span class="hint">保存后前台立即生效，无需改动代码或重启。</span></div>';
$body .= '</form>';

admin_layout('站点配置', $body, 'settings');
