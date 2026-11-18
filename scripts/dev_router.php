<?php
/**
 * 本地开发路由（PHP 内置服务器用），模拟 deploy/nginx.conf 的重写规则：
 * 伪静态 URL、/llms.txt → llms.php、未知路径真 404。
 *
 * 用法（项目根目录）:
 *   php -S 127.0.0.1:8080 -t public scripts/dev_router.php
 */
$pub   = dirname(__DIR__) . '/public';
$path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';

// llms.txt 由动态脚本生成
if ($path === '/llms.txt') {
    $_SERVER['SCRIPT_NAME']     = '/llms.php';
    $_SERVER['SCRIPT_FILENAME'] = $pub . '/llms.php';
    require $pub . '/llms.php';
    return true;
}

// 伪静态重写（与 deploy/nginx.conf 一致；注入 __pu=1 标记）
$rules = [
    '#^/article/([^/]+)/?$#'  => ['/article.php',  'slug'],
    '#^/category/([^/]+)/?$#' => ['/category.php', 'slug'],
    '#^/tag/([^/]+)/?$#'      => ['/tag.php',      'slug'],
    '#^/search/?$#'           => ['/search.php',   null],
    '#^/topics/?$#'           => ['/topics.php',   null],
    '#^/topic/([^/]+)/?$#'    => ['/topic.php',    'slug'],
];
foreach ($rules as $re => $r) {
    if (preg_match($re, $path, $m)) {
        parse_str($query, $q);
        if ($r[1] !== null) $q[$r[1]] = $m[1];
        $q['__pu'] = '1';
        $_GET = $q;
        $_SERVER['QUERY_STRING']    = http_build_query($q);
        $_SERVER['SCRIPT_NAME']     = $r[0];
        $_SERVER['SCRIPT_FILENAME'] = $pub . $r[0];
        require $pub . $r[0];
        return true;
    }
}

// 真实存在的文件（静态资源 / 直接访问 .php）交给内置服务器
if ($path !== '/' && is_file($pub . $path)) {
    return false;
}

// 目录索引（如 /admin/ → /admin/index.php，与 nginx index 指令一致）
if (is_dir($pub . $path) && is_file($pub . rtrim($path, '/') . '/index.php')) {
    $target = rtrim($path, '/') . '/index.php';
    $_SERVER['SCRIPT_NAME']     = $target;
    $_SERVER['SCRIPT_FILENAME'] = $pub . $target;
    require $pub . $target;
    return true;
}

// 首页
if ($path === '/' || $path === '/index.php') {
    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $pub . '/index.php';
    require $pub . '/index.php';
    return true;
}

// 其余路径：真 404
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
readfile($pub . '/404.html');
return true;
