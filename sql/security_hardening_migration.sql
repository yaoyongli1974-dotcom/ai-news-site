-- ============================================================
-- 安全加固迁移(2026-11) —— 已有站点升级用，可重复执行(幂等)
-- 新装站点直接导入 sql/schema.sql 即已包含这两张表，无需本文件。
--
-- 导入: mysql -u<user> -p <db> < sql/security_hardening_migration.sql
--
-- login_attempts: 后台登录失败限流/锁定
-- api_nonces:     推送 API 签名 nonce 去重(防重放)
--   注: 未导入本迁移时，登录限流与 nonce 防重放会自动降级跳过，
--       不影响站点正常运行，但建议尽快执行。
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(60)  NOT NULL COMMENT '尝试登录的用户名',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '来源 IP',
  `created_at` INT UNSIGNED NOT NULL COMMENT '失败时间(Unix 时间戳)',
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`username`, `created_at`),
  KEY `idx_ip_time` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录失败记录(限流)';

CREATE TABLE IF NOT EXISTS `api_nonces` (
  `nonce`      CHAR(64)     NOT NULL COMMENT 'sha256(api_key.ts.signature)',
  `expires_at` INT UNSIGNED NOT NULL COMMENT '签名过期时间(Unix 时间戳)，过期后可清理',
  PRIMARY KEY (`nonce`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='已使用的 API 签名 nonce(防重放)';
