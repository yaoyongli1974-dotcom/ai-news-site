<?php
/**
 * 冒烟测试入口 (CLI)。
 *
 * 用法:
 *   php tests/run.php            # 阶段1: 全量 php -l 语法检查
 *   php tests/run.php --db       # 阶段1 + 阶段2: MySQL 集成冒烟(会重建测试库全部表!)
 *
 * 阶段2 依赖:
 *   - 项目根存在 config.php（指向一个可清空的测试库，切勿指向生产库）
 *   - 本机有 mysql 客户端（用于导入 schema/迁移）
 *
 * 阶段2 覆盖: schema 导入(12 表) / 迁移幂等 / FULLTEXT 索引 / save_article 幂等与事务 /
 *             登录限流 / nonce 唯一键 / push_logs 轮转脚本。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
$withDb = in_array('--db', array_slice($argv, 1), true);
$failures = 0;

function ok(string $name): void { echo "  [PASS] {$name}\n"; }
function fail(string $name, string $why = ''): void
{
    global $failures;
    $failures++;
    echo "  [FAIL] {$name}" . ($why !== '' ? " —— {$why}" : '') . "\n";
}
function check(string $name, bool $cond, string $why = ''): void
{
    $cond ? ok($name) : fail($name, $why);
}

// ---------- 阶段 1: 语法检查 ----------
echo "== 阶段 1: php -l 全量语法检查 ==\n";
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) === '.php' && strpos($p, '/.git/') === false) $files[] = $p;
}
sort($files);
$lintBad = 0;
foreach ($files as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $lintBad++;
        fail('lint ' . substr($f, strlen($root) + 1), implode(' ', $out));
    }
    $out = [];
}
check('php -l 共 ' . count($files) . ' 个文件', $lintBad === 0, "{$lintBad} 个文件语法错误");

if (!$withDb) {
    echo $failures === 0 ? "\n全部通过。\n" : "\n存在失败项。\n";
    exit($failures === 0 ? 0 : 1);
}

// ---------- 阶段 2: MySQL 集成冒烟 ----------
echo "\n== 阶段 2: MySQL 集成冒烟（重建测试库表） ==\n";
if (!is_file($root . '/config.php')) {
    fail('config.php 存在', '阶段2 需要 config.php 指向测试库');
    exit(1);
}
exec('command -v mysql', $tmp, $hasMysql);
if ($hasMysql !== 0) {
    fail('mysql 客户端可用', '阶段2 需要 mysql CLI 导入 schema');
    exit(1);
}

require_once $root . '/src/bootstrap.php';
require_once SRC_DIR . '/article_service.php';
require_once SRC_DIR . '/auth.php';

$db = $GLOBALS['APP_CONFIG']['db'];

function import_sql(string $file): void
{
    global $db;
    $cmd = sprintf(
        'mysql -h%s -P%d -u%s %s %s < %s 2>&1',
        escapeshellarg($db['host']),
        (int)$db['port'],
        escapeshellarg($db['user']),
        $db['pass'] !== '' ? '-p' . escapeshellarg($db['pass']) : '',
        escapeshellarg($db['name']),
        escapeshellarg($file)
    );
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new RuntimeException(basename($file) . " 导入失败:\n" . implode("\n", $out));
    }
}

try {
    // 1) schema 导入 + 表数量
    import_sql(SRC_DIR . '/../sql/schema.sql');
    $tables = db_fetch_all('SHOW TABLES');
    $names = [];
    foreach ($tables as $t) $names[] = array_values($t)[0];
    check('schema 导入后共 12 张表', count($names) === 12, '实际 ' . count($names) . ': ' . implode(',', $names));
    foreach (['login_attempts', 'api_nonces', 'topics', 'topic_articles'] as $t) {
        check("表 {$t} 存在", in_array($t, $names, true));
    }

    // 2) 迁移幂等（连跑两次不报错）
    import_sql(SRC_DIR . '/../sql/security_hardening_migration.sql');
    import_sql(SRC_DIR . '/../sql/security_hardening_migration.sql');
    ok('security_hardening_migration 幂等（执行两次）');

    $isMaria = stripos(db_fetch('SELECT VERSION() AS v')['v'], 'mariadb') !== false;
    if (!$isMaria) {
        import_sql(SRC_DIR . '/../sql/search_fulltext_migration.sql');
        $idx = db_fetch_all("SHOW INDEX FROM `articles` WHERE `Key_name`='ft_search'");
        check('FULLTEXT ft_search 索引已建立', !empty($idx));
    } else {
        echo "  [SKIP] MariaDB 不支持 ngram FULLTEXT，search.php 将回退 LIKE\n";
    }

    // 3) save_article 幂等（source_id）
    $r1 = save_article(['title' => '冒烟测试文章', 'content' => '<p>hello</p>', 'source_id' => 'smoke-1', 'status' => 'published']);
    $r2 = save_article(['title' => '冒烟测试文章(改)', 'content' => '<p>hello2</p>', 'source_id' => 'smoke-1', 'status' => 'published']);
    check('source_id 首次创建', $r1['action'] === 'created');
    check('source_id 重复推送为更新', $r2['action'] === 'updated' && $r2['id'] === $r1['id']);
    $cnt = (int)db_fetch("SELECT COUNT(*) c FROM articles WHERE source_id='smoke-1'")['c'];
    check('source_id 幂等不产生重复行', $cnt === 1);

    // 4) 无 source_id 同标题 → slug 自动加后缀，不覆盖
    $a = save_article(['title' => '同名文章', 'content' => '<p>a</p>', 'status' => 'published']);
    $b = save_article(['title' => '同名文章', 'content' => '<p>b</p>', 'status' => 'published']);
    check('同标题新增不覆盖（slug 后缀）', $a['id'] !== $b['id'] && $a['slug'] !== $b['slug']);

    // 5) FULLTEXT 中文检索（有索引时）
    if (!$isMaria) {
        save_article(['title' => '人工智能大模型观察', 'content' => '<p>深度学习与自然语言处理进展。</p>', 'source_id' => 'smoke-ft', 'status' => 'published']);
        $hit = db_fetch_all("SELECT id FROM articles WHERE MATCH(title,summary,content) AGAINST (? IN NATURAL LANGUAGE MODE)", ['大模型']);
        check('FULLTEXT ngram 中文检索命中', !empty($hit));
    }

    // 6) 登录限流
    login_clear_failures('smoke-user');
    for ($i = 0; $i < 5; $i++) login_record_failure('smoke-user');
    check('失败 5 次后触发锁定', login_lockout_remaining('smoke-user') > 0);
    login_clear_failures('smoke-user');
    check('清除失败记录后解锁', login_lockout_remaining('smoke-user') === 0);

    // 7) nonce 唯一键（重放拦截的底层机制）
    db_exec("DELETE FROM api_nonces WHERE nonce='smoke-nonce'");
    db_exec("INSERT INTO api_nonces (nonce, expires_at) VALUES ('smoke-nonce', ?)", [time() + 300]);
    $dup = false;
    try {
        db_exec("INSERT INTO api_nonces (nonce, expires_at) VALUES ('smoke-nonce', ?)", [time() + 300]);
    } catch (PDOException $e) {
        $dup = ($e->getCode() === '23000');
    }
    check('重复 nonce 被唯一键拒绝(23000)', $dup);

    // 8) push_logs 轮转脚本
    db_exec("INSERT INTO push_logs (action, ip, message, created_at) VALUES ('push', '127.0.0.1', 'smoke-old', ?)",
        [date('Y-m-d H:i:s', time() - 100 * 86400)]);
    exec('php ' . escapeshellarg(SRC_DIR . '/../scripts/rotate_push_logs.php') . ' --days=90 2>&1', $rotOut, $rotCode);
    $left = (int)db_fetch("SELECT COUNT(*) c FROM push_logs WHERE message='smoke-old'")['c'];
    check('rotate_push_logs 清理过期日志', $rotCode === 0 && $left === 0, implode(' ', $rotOut));
} catch (Throwable $e) {
    fail('集成冒烟异常', $e->getMessage());
}

echo $failures === 0 ? "\n全部通过。\n" : "\n存在失败项。\n";
exit($failures === 0 ? 0 : 1);
