<?php
/**
 * 后台视图渲染助手 —— 后台布局、导航、闪存消息。
 */

/** 设置一次性闪存消息 */
function set_flash(string $msg, string $type = 'info'): void
{
    admin_session_start();
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/** 读取并清除闪存消息 */
function get_flash(): ?array
{
    admin_session_start();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * 渲染后台页面。
 * @param string $active 当前高亮菜单键: dashboard|articles|uploads|categories|tags|tokens
 */
function admin_layout(string $title, string $body, string $active = ''): void
{
    $nav = [
        'dashboard'  => ['label' => '仪表盘',   'href' => '/admin/index.php'],
        'articles'   => ['label' => '文章管理', 'href' => '/admin/articles.php'],
        'uploads'    => ['label' => '媒体库',   'href' => '/admin/uploads.php'],
        'categories' => ['label' => '分类管理', 'href' => '/admin/categories.php'],
        'tags'       => ['label' => '标签管理', 'href' => '/admin/tags.php'],
        'settings'   => ['label' => '站点配置', 'href' => '/admin/settings.php'],
        'tokens'     => ['label' => '接口令牌', 'href' => '/admin/api_tokens.php'],
    ];
    $flash = get_flash();
    $flashHtml = '';
    if ($flash) {
        $cls = $flash['type'] === 'error' ? 'flash-error' : 'flash-ok';
        $flashHtml = '<div class="flash ' . $cls . '">' . e($flash['msg']) . '</div>';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>' . "\n";
    ?>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · 后台管理</title>
  <link rel="stylesheet" href="<?= asset('/assets/admin.css') ?>">
</head>
<body>
  <div class="admin-shell">
    <aside class="admin-side">
      <div class="admin-brand">
        <?php $adminLogo = get_option('site_logo', ''); ?>
        <?php if ($adminLogo !== ''): ?><img class="admin-brand-logo" src="<?= e($adminLogo) ?>" alt="logo"><?php endif; ?>
        <?= e(get_option('site_title', 'AI 资讯汇')) ?><small>后台</small>
      </div>
      <nav>
        <?php foreach ($nav as $k => $n): ?>
          <a class="<?= $k === $active ? 'active' : '' ?>" href="<?= url($n['href']) ?>"><?= e($n['label']) ?></a>
        <?php endforeach; ?>
        <a href="<?= url('/') ?>" target="_blank">查看前台 ↗</a>
        <a href="<?= url('/admin/logout.php') ?>">退出登录</a>
      </nav>
    </aside>
    <main class="admin-main">
      <div class="admin-topbar">
        <h1><?= e($title) ?></h1>
        <span class="admin-user">你好，<?= e($_SESSION['admin_disp'] ?? '管理员') ?></span>
      </div>
      <?= $flashHtml ?>
      <div class="admin-content"><?= $body ?></div>
    </main>
  </div>
</body>
</html>
<?php
}
