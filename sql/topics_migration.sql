-- ============================================================
-- ai.xalcy.cn 专题模块 —— 增量迁移脚本（仅新增表，不破坏已有数据）
-- 适用：已执行过原 schema.sql 的线上库。
-- 用法：
--   mysql -u<user> -p <db> < sql/topics_migration.sql
-- 或 MySQL 客户端中：
--   USE ai_xalcy;  SOURCE sql/topics_migration.sql;
-- 全新部署无需本文件，直接 SOURCE sql/schema.sql 即可（已含专题表）。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `topics` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(200) NOT NULL COMMENT '专题名称',
  `slug`        VARCHAR(200) NOT NULL COMMENT 'URL 别名',
  `summary`     VARCHAR(500) DEFAULT '' COMMENT '专题简介(列表卡片展示)',
  `description` TEXT         NULL COMMENT '专题详细描述(详情页正文，可空)',
  `cover_image` VARCHAR(512) DEFAULT '' COMMENT '封面图 URL',
  `status`      ENUM('draft','published') NOT NULL DEFAULT 'published' COMMENT '状态',
  `sort_order`  INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='专题';

CREATE TABLE IF NOT EXISTS `topic_articles` (
  `topic_id`   INT UNSIGNED NOT NULL,
  `article_id` INT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT '专题内排序(越小越靠前)',
  PRIMARY KEY (`topic_id`, `article_id`),
  KEY `idx_article` (`article_id`),
  CONSTRAINT `fk_ta_topic` FOREIGN KEY (`topic_id`)
    REFERENCES `topics` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ta_article` FOREIGN KEY (`article_id`)
    REFERENCES `articles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='专题文章关联';

SET FOREIGN_KEY_CHECKS = 1;
