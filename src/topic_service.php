<?php
/**
 * 专题写/读服务 —— 后台表单与推送 API 共用，保证入库逻辑一致。
 *
 * save_topic(array $data): 创建或更新专题，返回 ['id'=>int,'action'=>'created'|'updated','slug'=>string]
 * 幂等规则(用于更新): 提供 id 且存在 -> 更新；否则按 slug 唯一性插入。
 */

/**
 * 生成唯一 slug（专题表）。
 */
function topic_unique_slug(string $slug, ?int $ignoreId = null): string
{
    $base = $slug === '' ? 'topic' : $slug;
    $candidate = $base;
    $i = 2;
    while (true) {
        $where  = "`slug`=?";
        $params = [$candidate];
        if ($ignoreId !== null) { $where .= " AND `id`<>?"; $params[] = $ignoreId; }
        if (!db_exists("topics", $where, $params)) {
            break;
        }
        $candidate = $base . '-' . $i++;
    }
    return $candidate;
}

/** 按 slug 取专题 */
function get_topic_by_slug(string $slug): ?array
{
    return db_fetch("SELECT * FROM `topics` WHERE `slug`=?", [$slug]);
}

/** 按 id 取专题 */
function get_topic_by_id(int $id): ?array
{
    return db_fetch("SELECT * FROM `topics` WHERE `id`=?", [$id]);
}

/**
 * 取全部专题。
 * @param bool $publishedOnly 仅返回已发布
 */
function get_all_topics(bool $publishedOnly = false): array
{
    $sql = "SELECT t.*, (SELECT COUNT(*) FROM `topic_articles` ta WHERE ta.`topic_id`=t.`id`) AS article_count
            FROM `topics` t";
    if ($publishedOnly) {
        $sql .= " WHERE t.`status`='published'";
    }
    $sql .= " ORDER BY t.`sort_order` ASC, t.`id` ASC";
    return db_fetch_all($sql);
}

/** 专题下文章数（不区分状态，用于后台计数） */
function get_topic_article_count(int $topicId): int
{
    $row = db_fetch("SELECT COUNT(*) c FROM `topic_articles` WHERE `topic_id`=?", [$topicId]);
    return $row ? (int)$row['c'] : 0;
}

/**
 * 取专题下关联文章（前台仅 published），附带分类名/别名。
 * @param int $per    每页条数
 * @param int $offset 偏移
 * @param bool $onlyPublished 是否仅已发布（前台 true）
 */
function get_topic_articles(int $topicId, int $per, int $offset, bool $onlyPublished = true): array
{
    $sql = "SELECT a.`id`, a.`title`, a.`slug`, a.`summary`, a.`content`, a.`cover_image`,
                   a.`author`, a.`views`, a.`published_at`, a.`created_at`,
                   c.`name` AS category_name, c.`slug` AS category_slug
            FROM `topic_articles` ta
            JOIN `articles` a ON a.`id`=ta.`article_id`
            LEFT JOIN `categories` c ON c.`id`=a.`category_id`
            WHERE ta.`topic_id`=?";
    if ($onlyPublished) {
        $sql .= " AND a.`status`='published'";
    }
    $sql .= " ORDER BY ta.`sort_order` ASC, COALESCE(a.`published_at`, a.`created_at`) DESC
             LIMIT ? OFFSET ?";
    return db_fetch_all($sql, [$topicId, $per, $offset]);
}

/**
 * 整体替换专题下的文章关联。
 * @param int   $topicId
 * @param int[] $articleIds 文章 id 数组（按传入顺序作为专题内排序）
 */
function set_topic_articles(int $topicId, array $articleIds): void
{
    db_exec("DELETE FROM `topic_articles` WHERE `topic_id`=?", [$topicId]);
    $ids = [];
    foreach ($articleIds as $aid) {
        $aid = (int)$aid;
        if ($aid > 0 && !in_array($aid, $ids, true)) {
            $ids[] = $aid;
        }
    }
    foreach ($ids as $i => $aid) {
        db_exec(
            "INSERT IGNORE INTO `topic_articles` (`topic_id`,`article_id`,`sort_order`) VALUES (?,?,?)",
            [$topicId, $aid, $i]
        );
    }
}

/**
 * 创建或更新专题。
 * @param array $data 字段: id,title,slug,summary,description,cover_image,status,sort_order,article_ids
 * @return array ['id'=>int,'action'=>string,'slug'=>string]
 */
function save_topic(array $data): array
{
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('title 不能为空');
    }
    $id          = isset($data['id']) ? (int)$data['id'] : 0;
    $rawSlug     = trim((string)($data['slug'] ?? ''));
    // 空 slug 传空串，交给 topic_unique_slug 生成 topic / topic-2… 基准；
    // 不要用 slugify 的 'item' 默认值，否则每次新建都会拿到相同 slug 而误判为更新、覆盖历史专题。
    $slug        = $rawSlug === '' ? '' : slugify($rawSlug, 200);
    $summary     = mb_substr(trim((string)($data['summary'] ?? '')), 0, 500, 'UTF-8');
    $description = (string)($data['description'] ?? '');
    $cover       = (string)($data['cover_image'] ?? '');
    $status      = in_array($data['status'] ?? '', ['published','draft'], true) ? $data['status'] : 'published';
    $sortOrder   = (int)($data['sort_order'] ?? 0);

    // 决定目标行：
    // - 编辑(id>0)按主键定位；
    // - 新增(id=0)一律插入新行，绝不按 slug 反查已存在行。
    //   这避免「空 slug / 重复 slug」被误判为"更新"而覆盖历史专题（数据丢失）。
    //   slug 唯一性由 topic_unique_slug() 保证。
    $existingId = 0;
    if ($id > 0) {
        $row = db_fetch("SELECT `id` FROM `topics` WHERE `id`=?", [$id]);
        if ($row) $existingId = (int)$row['id'];
    }

    if ($existingId > 0) {
        $finalSlug = $slug !== '' ? topic_unique_slug($slug, $existingId)
                                  : db_fetch("SELECT `slug` FROM `topics` WHERE `id`=?", [$existingId])['slug'];
        db_exec(
            "UPDATE `topics` SET `title`=?,`slug`=?,`summary`=?,`description`=?,`cover_image`=?,`status`=?,`sort_order`=? WHERE `id`=?",
            [$title, $finalSlug, $summary, $description, $cover, $status, $sortOrder, $existingId]
        );
        $topicId = $existingId;
        $action  = 'updated';
    } else {
        $finalSlug = topic_unique_slug($slug);
        $topicId = db_insert(
            "INSERT INTO `topics` (`title`,`slug`,`summary`,`description`,`cover_image`,`status`,`sort_order`)
             VALUES (?,?,?,?,?,?,?)",
            [$title, $finalSlug, $summary, $description, $cover, $status, $sortOrder]
        );
        $action = 'created';
    }

    // 同步关联文章
    $raw = $data['article_ids'] ?? [];
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
    }
    set_topic_articles($topicId, $raw);

    return ['id' => $topicId, 'action' => $action, 'slug' => $finalSlug];
}

/** 删除专题（关联由外键级联删除） */
function delete_topic(int $topicId): void
{
    db_exec("DELETE FROM `topics` WHERE `id`=?", [$topicId]);
}
