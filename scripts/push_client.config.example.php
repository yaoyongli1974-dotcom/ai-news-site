<?php
/**
 * 推送 / 上传客户端配置样例 —— 复制为 push_client.config.php 并填写真实值。
 */
return [
    'api_url'    => 'https://ai.xalcy.cn/api/push.php',
    // 图片上传接口；留空会自动由 api_url 推导（把 push.php 换成 upload.php）
    'upload_url' => 'https://ai.xalcy.cn/api/upload.php',
    // 视频上传接口；留空会自动由 api_url 推导（把 push.php 换成 upload_video.php）
    // 也可通过环境变量 AIXALCY_VIDEO_UPLOAD_URL 单独指定
    'video_upload_url' => 'https://ai.xalcy.cn/api/upload_video.php',
    'api_key'    => '在此填写后台生成的 API Key',
    'api_secret' => '在此填写后台生成的 API Secret',
];
