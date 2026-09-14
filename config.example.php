<?php
/**
 * 应用配置 —— 复制本文件为 config.php 并填入真实值
 *   cp config.example.php config.php
 * config.php 位于 web 根目录之外(项目根)，不会被直接访问。
 */
return [
    // ---- 运行环境 ----
    'debug'       => false,          // 生产环境务必设为 false
    'app_url'     => 'https://ai.xalcy.cn',
    'timezone'    => 'Asia/Shanghai',

    // ---- 数据库 (MySQL) ----
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'ai_xalcy',
        'user'     => 'ai_xalcy',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // ---- 推送接口安全 ----
    'api' => [
        'require_https' => true,     // 非 HTTPS 直接拒绝(建议生产开启)
        'signature_ttl' => 300,     // 签名有效期(秒)，防重放，默认 5 分钟
    ],

    // ---- 图片上传 ----
    'upload' => [
        'dir'          => 'assets/uploads', // 相对 public/ 的存放目录(不存在会自动创建)
        'url_path'     => '/assets/uploads',// 对外 URL 前缀，返回给调用方
        'max_size'     => 5 * 1024 * 1024,  // 单张图片上限(字节)，默认 5MB
        'max_files'    => 10,               // 单次请求最多张数
        'max_pixels'   => 40000000,         // 宽×高 上限(防像素炸弹)，默认 4000 万像素
        'filename_max' => 60,               // 文件名主干最大长度
        'strip_exif'   => true,             // JPEG 重新编码以剥离 EXIF/GPS 与潜在载荷
        'jpeg_quality' => 90,               // 重编码质量(1-100)
        // 允许的类型: MIME => 落盘扩展名(扩展名由服务端按真实 MIME 决定)
        'allowed_mime' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ],
    ],

    // ---- 视频上传 ----
    'video' => [
        'dir'          => 'assets/videos', // 相对 public/ 的存放目录(不存在会自动创建)
        'url_path'     => '/assets/videos',// 对外 URL 前缀，返回给调用方
        'max_size'     => 100 * 1024 * 1024, // 单文件上限(字节)，默认 100MB
        'max_files'    => 5,               // 单次请求最多个数
        'filename_max' => 80,              // 文件名主干最大长度
        // 允许的类型: MIME => 落盘扩展名(扩展名由服务端按真实 MIME 决定)
        // 仅接受网页原生支持的封装：H.264/AAC 的 mp4、VP9/Opus 的 webm。
        'allowed_mime' => [
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
        ],
    ],

    // ---- 后台 ----
    'admin' => [
        'session_name' => 'AIXALCY_ADMIN',
        'route_prefix' => '/admin',  // 后台访问前缀(配合 rewrite 时使用)
    ],

    // 安全相关：用于在 cookie 之外增加一层盐(可选)
    'pepper' => 'CHANGE_ME_PEPPER_32CHARS_MIN',
];
