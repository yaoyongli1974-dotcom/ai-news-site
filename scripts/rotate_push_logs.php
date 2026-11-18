<?php
/**
 * push_logs 审计日志轮转 (CLI) —— 删除超过保留期的推送日志，防止无限增长。
 *
 * 用法:
 *   php scripts/rotate_push_logs.php              # 按 config.php 的 api.log_retention_days（默认 90 天）
 *   php scripts/rotate_push_logs.php --days=30    # 临时指定保留天数
 *   php scripts/rotate_push_logs.php --dry-run    # 只统计将删除的行数，不实际删除
 *
 * 建议每天由 cron 执行一次（见 deploy/crontab.example）。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../src/bootstrap.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

$cfg  = $GLOBALS['APP_CONFIG'];
$days = isset($args['days']) ? (int)$args['days'] : (int)($cfg['api']['log_retention_days'] ?? 90);
if ($days < 1) {
    fwrite(STDERR, "保留天数必须 >= 1\n");
    exit(1);
}
$dryRun = isset($args['dry-run']);

$cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
$count  = (int)db_fetch("SELECT COUNT(*) AS c FROM `push_logs` WHERE `created_at` < ?", [$cutoff])['c'];

if ($dryRun) {
    echo "[dry-run] 保留 {$days} 天，早于 {$cutoff} 的日志共 {$count} 条（未删除）\n";
    exit(0);
}

db_exec("DELETE FROM `push_logs` WHERE `created_at` < ?", [$cutoff]);
echo "已清理 {$count} 条早于 {$cutoff} 的推送日志（保留 {$days} 天）\n";
