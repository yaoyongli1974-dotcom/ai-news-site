<?php
/**
 * 后台登录。
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_DIR . '/auth.php';

admin_session_start();
if (is_admin_logged_in()) {
    redirect(url('/admin/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($user === '' || $pass === '') {
        $error = '请输入用户名与密码';
    } elseif (admin_login($user, $pass)) {
        redirect(url('/admin/index.php'));
    } else {
        $error = '用户名或密码错误';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>登录 · 后台管理</title>
  <link rel="stylesheet" href="<?= asset('/assets/admin.css') ?>">
  <style>
    .login-wrap { max-width: 380px; margin: 8vh auto; background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 30px; box-shadow: 0 10px 30px rgba(16,24,40,.08); }
    .login-wrap h1 { font-size: 20px; margin: 0 0 4px; }
    .login-wrap .sub { color: var(--muted); font-size: 13px; margin-bottom: 18px; }
    .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:9px 12px; border-radius:8px; margin-bottom:14px; font-size:13px; }
  </style>
</head>
<body>
  <div class="login-wrap">
    <h1><?= e(get_option('site_title', 'AI 资讯汇')) ?></h1>
    <p class="sub">后台管理系统登录</p>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="form-row">
        <label>用户名</label>
        <input type="text" name="username" autofocus required>
      </div>
      <div class="form-row">
        <label>密码</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn btn-primary" style="width:100%" type="submit">登录</button>
    </form>
  </div>
</body>
</html>
