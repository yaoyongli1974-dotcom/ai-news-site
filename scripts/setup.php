<?php
/**
 * 初始化脚本 (CLI) —— 创建后台管理员账号与首个推送令牌。
 * 幂等：账号/令牌已存在时仅提示，不重复创建。
 *
 * 用法:
 *   php scripts/setup.php --user=admin --pass=你的密码
 *   php scripts/setup.php                      # 交互式输入
 *   php scripts/setup.php --user=admin --pass=新密码 --reset   # 强制重置已有管理员密码
 */
require_once __DIR__ . '/../src/bootstrap.php';

// 解析简单参数
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
}

$user = $args['user'] ?? '';
$pass = $args['pass'] ?? '';

if ($user === '' && defined('STDIN')) {
    echo "管理员用户名 [admin]: ";
    $user = trim(fgets(STDIN)) ?: 'admin';
    echo "管理员密码: ";
    $pass = trim(fgets(STDIN));
}

if ($user === '' || $pass === '') {
    fwrite(STDERR, "用法: php scripts/setup.php --user=admin --pass=密码\n");
    exit(1);
}

$reset = isset($args['reset']);

// 1) 管理员
if (db_exists('admins', '`username`=?', [$user])) {
    if ($reset) {
        db_exec("UPDATE `admins` SET `password_hash`=? WHERE `username`=?",
            [password_hash($pass, PASSWORD_DEFAULT), $user]);
        echo "已重置管理员 {$user} 的密码。\n";
    } else {
        echo "管理员 {$user} 已存在，跳过创建(如需重置密码请加 --reset)。\n";
    }
} else {
    db_insert("INSERT INTO `admins` (`username`,`password_hash`,`display_name`) VALUES (?,?,?)",
        [$user, password_hash($pass, PASSWORD_DEFAULT), $user]);
    echo "已创建管理员: {$user}\n";
}

// 2) 首个推送令牌(便于立即使用 API)
if (db_exists('api_tokens', '1')) {
    echo "已存在推送令牌，跳过创建(可在后台继续生成)。\n";
} else {
    $apiKey = bin2hex(random_bytes(16));
    $apiSecret = bin2hex(random_bytes(32));
    $admin = db_fetch("SELECT `id` FROM `admins` WHERE `username`=? ORDER BY `id` ASC LIMIT 1", [$user]);
    db_insert("INSERT INTO `api_tokens` (`name`,`api_key`,`api_secret`,`created_by`) VALUES (?,?,?,?)",
        ['初始令牌(setup)', $apiKey, $apiSecret, $admin['id'] ?? null]);
    echo "\n=== 推送接口凭据(仅显示一次，请保存) ===\n";
    echo "API_URL:    " . APP_URL . "/api/push.php\n";
    echo "API_KEY:    " . $apiKey . "\n";
    echo "API_SECRET: " . $apiSecret . "\n";
}

echo "\n完成。访问 " . APP_URL . "/admin/ 用 {$user} 登录。\n";
exit(0);
