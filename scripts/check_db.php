<?php
/**
 * 数据库连通性自检 (CLI)
 *
 * 为什么要这个脚本：
 *   在 Docker / 1Panel 环境里，「容器内的 127.0.0.1」指的是容器自己，而不是宿主机，
 *   所以 config.php 里写的 127.0.0.1 在容器内往往连不上数据库。
 *   本脚本会依次测试多个候选 host，帮你找出真正可用的那一个，并给出应填的值。
 *
 * 用法（在 PHP 容器内执行）：
 *   php scripts/check_db.php                      # 只测 config.php 里的 host + 常见候选
 *   php scripts/check_db.php mysql 172.18.0.1     # 额外追加候选 host
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * 读取容器默认网关（容器内访问宿主机端口映射的常用地址）。
 * 纯 PHP 解析 /proc/net/route，不依赖 ip/awk 命令。
 */
function docker_default_gateway(): ?string
{
    $lines = @file('/proc/net/route');
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $line) {
        $cols = preg_split('/\s+/', trim((string)$line));
        // 目标地址 00000000 即默认路由，第 3 列是网关（小端序十六进制）
        if (is_array($cols) && count($cols) >= 3 && $cols[1] === '00000000') {
            $hex   = str_pad($cols[2], 8, '0', STR_PAD_LEFT);
            $parts = array_reverse(str_split($hex, 2));
            return implode('.', array_map('hexdec', $parts));
        }
    }
    return null;
}

$cfg = $GLOBALS['APP_CONFIG']['db'] ?? [];
$port = (int)($cfg['port'] ?? 3306);

// ---- 组装候选 host（去重，保持顺序）----
$candidates = [];
$add = static function (string $host) use (&$candidates): void {
    $host = trim($host);
    if ($host !== '' && !in_array($host, $candidates, true)) {
        $candidates[] = $host;
    }
};

$add((string)($cfg['host'] ?? '127.0.0.1'));  // config.php 中当前配置
foreach (array_slice($argv, 1) as $arg) {     // 命令行追加
    $add($arg);
}
$add('mysql');                 // 1Panel 数据库容器常见名
$add('mariadb');
$add('host.docker.internal');
$gw = docker_default_gateway();
if ($gw !== null) {
    $add($gw);                 // 例如 172.18.0.1
}

$isContainer = is_file('/.dockerenv') || $gw !== null;

echo "环境: " . ($isContainer ? "容器内" : "宿主机/物理机") . "\n";
echo "目标: dbname={$cfg['name']}  user={$cfg['user']}  port={$port}\n";
echo str_repeat('-', 64) . "\n";

$working = null;
foreach ($candidates as $host) {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, (string)$cfg['name']);
    try {
        new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
            PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT  => 3,
        ]);
        printf("  [成功] %-24s 连接正常\n", $host);
        $working = $host;
    } catch (Throwable $e) {
        printf("  [失败] %-24s %s\n", $host, preg_replace('/\s+/', ' ', $e->getMessage()));
    }
}

echo str_repeat('-', 64) . "\n";

if ($working === null) {
    echo "结论: 所有候选 host 均连接失败。请依次检查：\n";
    echo "  1) 数据库名/账号/密码是否与 1Panel → 数据库 页面一致；\n";
    echo "  2) 账号是否已授权访问该库（1Panel 建库时会同时建同名用户并授权）；\n";
    echo "  3) 若数据库在另一台机器，用其真实 IP；\n";
    echo "  4) 提示 Access denied 说明网络通了、只是账号密码错。\n";
    exit(1);
}

// ---- 连接成功：顺手检查表是否已导入 ----
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $working, $port, (string)$cfg['name']),
    (string)$cfg['user'],
    (string)$cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$required = ['admins', 'api_tokens', 'articles', 'article_tags', 'categories', 'tags', 'push_logs', 'options'];
$missing  = [];
foreach ($required as $t) {
    $row = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
    if ($row === false) {
        $missing[] = $t;
    }
}

if ($missing) {
    echo "数据表: 缺少 " . implode(', ', $missing) . " —— 请先导入 sql/schema.sql\n";
} else {
    $admins = (int)$pdo->query('SELECT COUNT(*) AS c FROM `admins`')->fetch()['c'];
    $tokens = (int)$pdo->query('SELECT COUNT(*) AS c FROM `api_tokens`')->fetch()['c'];
    $arts   = (int)$pdo->query('SELECT COUNT(*) AS c FROM `articles`')->fetch()['c'];
    printf("数据表: 全部就绪（管理员 %d 个 / 推送令牌 %d 个 / 文章 %d 篇）\n", $admins, $tokens, $arts);
    if ($admins === 0) {
        echo "提示: 还没有管理员账号，接着执行 scripts/setup.php 完成初始化。\n";
    }
}

// ---- 给出结论 ----
if ($working !== (string)($cfg['host'] ?? '')) {
    echo "\n>>> 请把 config.php 的 db.host 改为: {$working}\n";
} else {
    echo "\n>>> config.php 的 db.host 配置正确，无需修改。\n";
}
exit(0);
