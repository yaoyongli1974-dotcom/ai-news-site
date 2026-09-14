<?php
/**
 * 引导文件 —— 所有入口(index / admin/* / api/*)都先 require 本文件。
 * 负责: 定义路径常量、加载配置、连接数据库、注册自动加载与错误处理。
 */
declare(strict_types=1);

// ---- 路径常量 ----
define('APP_ROOT', dirname(__DIR__));   // 项目根目录 (config.php / src / sql 所在层)
define('SRC_DIR',  __DIR__);            // src 目录
define('PUBLIC_DIR', APP_ROOT . '/public');

// ---- 载入配置 ----
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("配置文件缺失：请复制 config.example.php 为 config.php 并填写数据库与密钥信息。\n");
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("配置文件格式错误：config.php 必须 return 一个数组。\n");
}
define('APP_DEBUG', (bool)($config['debug'] ?? false));
define('APP_URL', rtrim($config['app_url'] ?? '', '/'));
date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');

// ---- 错误处理 ----
error_reporting(APP_DEBUG ? E_ALL : E_ERROR | E_PARSE);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---- 自动加载 (按命名约定: src/Foo.php => function/class) ----
spl_autoload_register(function ($class) {
    $file = SRC_DIR . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) { require $file; }
});

// ---- 通用函数(所有页面/接口共用) ----
require_once SRC_DIR . '/functions.php';

// ---- 数据库 ----
require_once SRC_DIR . '/db.php';

// 把 $config 放进全局便于函数取用
$GLOBALS['APP_CONFIG'] = $config;
