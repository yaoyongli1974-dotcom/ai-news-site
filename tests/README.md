# 冒烟测试

零依赖，不需要 PHPUnit / Composer。

```bash
# 阶段1：全量 php -l 语法检查（任何环境可跑）
php tests/run.php

# 阶段1+2：MySQL 集成冒烟（会 DROP 并重建 config.php 所指库的全部表，切勿指向生产库！）
php tests/run.php --db
```

阶段2 前置条件：
- 项目根有 `config.php`，`db` 段指向一个**专用测试库**；
- 本机有 `mysql` 客户端（导入 schema 与迁移用）。

阶段2 覆盖：
1. `schema.sql` 导入后断言 12 张表齐全；
2. `security_hardening_migration.sql` 连跑两次验证幂等；
3. MySQL 下建立 FULLTEXT(ngram) 索引并验证中文检索命中（MariaDB 自动跳过）；
4. `save_article`：`source_id` 幂等 upsert、同标题不覆盖（slug 后缀）；
5. 登录失败限流锁定/解锁；
6. `api_nonces` 唯一键拒绝重复 nonce（防重放底层机制）；
7. `rotate_push_logs.php` 清理过期审计日志。

HTTP 层行为（安全头、Cookie 标志、404、登录 CSRF、推送签名/重放/批量上限）
建议部署后按 README 用 curl 实测，或在本地用 `php -S` + 路由脚本验证。

CI：`.github/workflows/ci.yml` 在 PHP 8.4 + MySQL 8.0 服务上自动执行两个阶段。
