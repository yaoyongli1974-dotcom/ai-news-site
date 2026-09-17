-- ============================================================
-- AI 资讯汇 (ai.xalcy.cn) 数据库结构
-- 引擎: MySQL 5.7+ / 8.0   字符集: utf8mb4
-- 导入: mysql -u<user> -p <db> < sql/schema.sql
-- 或先在 MySQL 客户端执行: CREATE DATABASE ai_xalcy CHARACTER SET utf8mb4;
--                      USE ai_xalcy;  SOURCE sql/schema.sql;
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `push_logs`;
DROP TABLE IF EXISTS `article_tags`;
DROP TABLE IF EXISTS `articles`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `api_tokens`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `options`;

-- ---------- 分类 ----------
CREATE TABLE `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL COMMENT '分类名称',
  `slug`        VARCHAR(120) NOT NULL COMMENT 'URL 别名',
  `description` VARCHAR(255) DEFAULT '' COMMENT '分类描述',
  `sort_order`  INT NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章分类';

-- ---------- 标签 ----------
CREATE TABLE `tags` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(80) NOT NULL COMMENT '标签名称',
  `slug`       VARCHAR(100) NOT NULL COMMENT 'URL 别名',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章标签';

-- ---------- 文章 ----------
CREATE TABLE `articles` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(255) NOT NULL COMMENT '标题',
  `slug`         VARCHAR(255) NOT NULL COMMENT 'URL 别名',
  `summary`      TEXT         NULL COMMENT '摘要',
  `content`      LONGTEXT     NOT NULL COMMENT '正文(Markdown/HTML)',
  `cover_image`  VARCHAR(512) DEFAULT '' COMMENT '封面图 URL',
  `author`       VARCHAR(120) DEFAULT '' COMMENT '作者',
  `source_url`   VARCHAR(512) DEFAULT '' COMMENT '原文链接',
  `source_id`    VARCHAR(255) DEFAULT NULL COMMENT '外部幂等ID(推送去重用)',
  `category_id`  INT UNSIGNED DEFAULT NULL COMMENT '分类ID',
  `status`       ENUM('draft','published') NOT NULL DEFAULT 'published' COMMENT '状态',
  `views`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '阅读量',
  `published_at` DATETIME     DEFAULT NULL COMMENT '发布时间',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  UNIQUE KEY `uk_source_id` (`source_id`),
  KEY `idx_status_published` (`status`, `published_at`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_article_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章';

-- ---------- 文章-标签 关联 ----------
CREATE TABLE `article_tags` (
  `article_id` INT UNSIGNED NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  PRIMARY KEY (`article_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`),
  CONSTRAINT `fk_at_article` FOREIGN KEY (`article_id`)
    REFERENCES `articles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_at_tag` FOREIGN KEY (`tag_id`)
    REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章标签关联';

-- ---------- 后台管理员 ----------
CREATE TABLE `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL COMMENT '登录名',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash()',
  `display_name`  VARCHAR(120) DEFAULT '' COMMENT '显示名',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理员';

-- ---------- 推送接口令牌 ----------
CREATE TABLE `api_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL COMMENT '用途说明',
  `api_key`      VARCHAR(64)  NOT NULL COMMENT '公钥(查询用)',
  `api_secret`   VARCHAR(128) NOT NULL COMMENT 'HMAC 密钥(仅创建时展示一次)',
  `status`       ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `last_used_at` DATETIME     DEFAULT NULL COMMENT '最近调用时间',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`   INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_key` (`api_key`),
  CONSTRAINT `fk_token_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='推送接口鉴权令牌';

-- ---------- 推送审计日志 ----------
CREATE TABLE `push_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_token_id` INT UNSIGNED   DEFAULT NULL,
  `api_key`     VARCHAR(64)     DEFAULT '' COMMENT '请求携带的 key',
  `action`      VARCHAR(40)     NOT NULL DEFAULT 'push' COMMENT 'push/auth_fail/validation',
  `status`      ENUM('success','error') NOT NULL DEFAULT 'success',
  `message`     TEXT            DEFAULT NULL,
  `items`       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '处理文章数',
  `ip`          VARCHAR(45)     DEFAULT '' COMMENT '调用方 IP',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_token` (`api_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='推送接口审计';

-- ---------- 站点配置 ----------
CREATE TABLE `options` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`   VARCHAR(64)  NOT NULL,
  `value` TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站点配置';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 初始数据 (分类 / 标签 / 站点配置)
-- 管理员账号与首个 API 令牌请运行: php scripts/setup.php
-- ============================================================

INSERT INTO `categories` (`name`, `slug`, `description`, `sort_order`) VALUES
('行业资讯',   'news',      'AI 行业动态与新闻',   10),
('技术教程',   'tutorial',  '实操教程与开发指南',   20),
('产品评测',   'review',    'AI 产品与工具评测',   30),
('研究前沿',   'research',  '论文与前沿研究解读',   40),
('观点洞察',   'opinion',   '行业观点与深度分析',   50);

INSERT INTO `tags` (`name`, `slug`) VALUES
('大模型',   'llm'),
('AIGC',     'aigc'),
('智能体',   'agent'),
('开源',     'open-source'),
('提示词',   'prompt'),
('向量数据库','vector-db');

INSERT INTO `options` (`key`, `value`) VALUES
('site_title',       'AI 资讯汇'),
('site_subtitle',    '汇集 AI 领域优质微信公众号文章'),
('site_description', 'AI 资讯汇聚合展示人工智能相关的微信公众号文章，涵盖行业资讯、技术教程、产品评测与研究前沿。'),
('site_keywords',    'AI,人工智能,大模型,AIGC,智能体,公众号'),
('posts_per_page',   '10'),
('footer_text',      '© 2026 AI 资讯汇 · Powered by PHP & MySQL'),
('site_icp',         ''),
('site_logo',        ''),
('site_icon',        '');
