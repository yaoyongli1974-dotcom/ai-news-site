<?php
/**
 * 退出登录。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';
admin_logout();
redirect(url('/admin/login.php'));
