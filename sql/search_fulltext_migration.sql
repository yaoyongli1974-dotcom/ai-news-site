-- ============================================================
-- 搜索 FULLTEXT 索引迁移(可选，仅 MySQL)
--
-- 适用: MySQL 5.7.6+ / 8.0（内置 ngram 解析器，支持中文分词）
-- 不适用: MariaDB —— 不支持 WITH PARSER ngram，请跳过本文件；
--         public/search.php 检测不到 ft_search 索引时会自动回退 LIKE 查询。
--
-- 导入: mysql -u<user> -p <db> < sql/search_fulltext_migration.sql
-- 重复执行会报 Duplicate key name 'ft_search'，属正常(说明已建过)。
--
-- 说明: content 为 LONGTEXT，大表上建立 FULLTEXT 索引耗时较长，
--       建议在低峰期执行；索引建成后搜索走 MATCH...AGAINST，性能远优于三列 LIKE。
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `articles`
  ADD FULLTEXT KEY `ft_search` (`title`, `summary`, `content`) WITH PARSER ngram;
