<?php
/**
 * 文章写服务 —— 前台推送 API 与后台表单共用，保证入库逻辑一致。
 *
 * save_article(array $data): 创建或更新文章，返回 ['id'=>int,'action'=>'created'|'updated']
 * 幂等规则(用于推送去重):
 *   1) 若提供 id 且存在 -> 更新
 *   2) 若提供 source_id 且存在 -> 更新
 *   3) 若 slug 已存在 -> 更新
 *   否则插入。
 */

/**
 * 按 slug 或名称解析分类 ID；不存在返回 null。
 */
function resolve_category_id($cat): ?int
{
    if ($cat === null || $cat === '') return null;
    if (is_numeric($cat)) {
        $row = db_fetch("SELECT `id` FROM `categories` WHERE `id`=?", [(int)$cat]);
        return $row ? (int)$row['id'] : null;
    }
    $row = db_fetch("SELECT `id` FROM `categories` WHERE `slug`=? OR `name`=?", [(string)$cat, (string)$cat]);
    return $row ? (int)$row['id'] : null;
}

/**
 * 取得或创建标签，返回标签 ID。
 */
function get_or_create_tag(string $name): int
{
    $name = trim($name);
    if ($name === '') return 0;
    $slug = slugify($name);
    $row = db_fetch("SELECT `id` FROM `tags` WHERE `slug`=? OR `name`=?", [$slug, $name]);
    if ($row) return (int)$row['id'];
    return db_insert("INSERT INTO `tags` (`name`,`slug`) VALUES (?,?)", [$name, $slug]);
}

/**
 * 生成唯一 slug。
 */
function unique_slug(string $slug, ?int $ignoreId = null): string
{
    $base = $slug === '' ? 'article' : $slug;
    $candidate = $base;
    $i = 2;
    while (true) {
        $where  = "`slug`=?";
        $params = [$candidate];
        if ($ignoreId !== null) { $where .= " AND `id`<>?"; $params[] = $ignoreId; }
        if (!db_exists("articles", $where, $params)) {
            break;
        }
        $candidate = $base . '-' . $i++;
    }
    return $candidate;
}

/**
 * @param array $data 文章字段
 * @return array ['id'=>int,'action'=>string]
 */
function save_article(array $data): array
{
    $title   = trim((string)($data['title'] ?? ''));
    $content = (string)($data['content'] ?? '');
    if ($title === '')   throw new InvalidArgumentException('title 不能为空');
    if ($content === '') throw new InvalidArgumentException('content 不能为空');

    $id         = isset($data['id']) ? (int)$data['id'] : 0;
    $slug       = slugify((string)($data['slug'] ?? ''), 200);
    $summary    = (string)($data['summary'] ?? '');
    if ($summary === '') $summary = make_excerpt($content, 200);
    $cover      = (string)($data['cover_image'] ?? '');
    $author     = (string)($data['author'] ?? '');
    $sourceUrl  = (string)($data['source_url'] ?? '');
    $sourceId   = !empty($data['source_id']) ? (string)$data['source_id'] : null;
    $status     = in_array($data['status'] ?? '', ['published','draft'], true) ? $data['status'] : 'published';
    $published  = to_mysql_datetime($data['published_at'] ?? null)
                  ?? date('Y-m-d H:i:s');
    $categoryId = resolve_category_id($data['category'] ?? ($data['category_id'] ?? null));

    // 决定目标行
    $existingId = 0;
    if ($id > 0) {
        $row = db_fetch("SELECT `id` FROM `articles` WHERE `id`=?", [$id]);
        if ($row) $existingId = (int)$row['id'];
    }
    if ($existingId === 0 && $sourceId !== null) {
        $row = db_fetch("SELECT `id` FROM `articles` WHERE `source_id`=?", [$sourceId]);
        if ($row) $existingId = (int)$row['id'];
    }
    if ($existingId === 0 && $slug !== '') {
        $row = db_fetch("SELECT `id` FROM `articles` WHERE `slug`=?", [$slug]);
        if ($row) $existingId = (int)$row['id'];
    }

    if ($existingId > 0) {
        // 更新：slug 仅在显式提供且不与他者冲突时更新
        $finalSlug = $slug !== '' ? unique_slug($slug, $existingId) : db_fetch("SELECT `slug` FROM `articles` WHERE `id`=?", [$existingId])['slug'];
        db_exec(
            "UPDATE `articles` SET `title`=?,`slug`=?,`summary`=?,`content`=?,`cover_image`=?,
             `author`=?,`source_url`=?,`source_id`=?,`category_id`=?,`status`=?,`published_at`=?
             WHERE `id`=?",
            [$title, $finalSlug, $summary, $content, $cover, $author, $sourceUrl, $sourceId, $categoryId, $status, $published, $existingId]
        );
        $articleId = $existingId;
        $action = 'updated';
    } else {
        $finalSlug = unique_slug($slug);
        $articleId = db_insert(
            "INSERT INTO `articles`
             (`title`,`slug`,`summary`,`content`,`cover_image`,`author`,`source_url`,`source_id`,`category_id`,`status`,`published_at`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$title, $finalSlug, $summary, $content, $cover, $author, $sourceUrl, $sourceId, $categoryId, $status, $published]
        );
        $action = 'created';
    }

    // 同步标签
    $tagIds = [];
    $rawTags = $data['tags'] ?? [];
    if (is_string($rawTags)) {
        $rawTags = array_filter(array_map('trim', explode(',', $rawTags)));
    }
    foreach ($rawTags as $t) {
        $tid = get_or_create_tag((string)$t);
        if ($tid > 0) $tagIds[] = $tid;
    }
    $tagIds = array_values(array_unique($tagIds));
    db_exec("DELETE FROM `article_tags` WHERE `article_id`=?", [$articleId]);
    foreach ($tagIds as $tid) {
        db_exec("INSERT IGNORE INTO `article_tags` (`article_id`,`tag_id`) VALUES (?,?)", [$articleId, $tid]);
    }

    return ['id' => $articleId, 'action' => $action, 'slug' => $finalSlug];
}
