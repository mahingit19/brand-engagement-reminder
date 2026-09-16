<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fetch_posts.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
ensureTodayTasks($pdo);
$settings = getSettings($pdo);

// 1. Auto feed refresh: If any active social link's feed hasn't been checked in 3+ minutes, scan feeds now
try {
    $staleCheck = $pdo->query("
        SELECT s.id FROM social_links s
        INNER JOIN brands b ON b.id = s.brand_id
        WHERE s.status = 1 AND b.status = 1 AND s.rss_feed_url IS NOT NULL AND TRIM(s.rss_feed_url) != ''
          AND (s.last_feed_check_at IS NULL OR s.last_feed_check_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE))
        LIMIT 1
    ")->fetch();
    if ($staleCheck) {
        fetchBrandPosts($pdo);
    }
} catch (\Throwable $e) {
    // Continue even if feed scan encounters an error
}

// 2. Priority 1: Check for any unnotified newly published post from working feeds
$newPostStmt = $pdo->prepare("
    SELECT p.id AS post_id, p.brand_id, p.social_link_id, p.post_url, p.title AS post_title, p.content_snippet, p.published_at,
           b.name AS brand_name,
           COALESCE(s.platform, 'Social') AS platform_name,
           d.id AS task_id
    FROM brand_posts p
    INNER JOIN brands b ON b.id = p.brand_id AND b.status = 1
    LEFT JOIN social_links s ON s.id = p.social_link_id
    LEFT JOIN daily_engagements d ON d.brand_id = b.id AND d.engagement_date = :today
    WHERE p.is_notified = 0
    ORDER BY p.published_at DESC, p.id DESC
    LIMIT 1
");
$newPostStmt->execute(['today' => today()]);
$newPost = $newPostStmt->fetch();

if ($newPost) {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE brand_posts SET is_notified = 1 WHERE id = :post_id")->execute(['post_id' => $newPost['post_id']]);
    if (!empty($newPost['social_link_id'])) {
        $pdo->prepare("UPDATE social_links SET last_reminded_at = NOW() WHERE id = :s_id")->execute(['s_id' => $newPost['social_link_id']]);
    }
    if (!empty($newPost['task_id'])) {
        $pdo->prepare("UPDATE daily_engagements SET last_reminded_at = NOW() WHERE id = :task_id")->execute(['task_id' => $newPost['task_id']]);
    }
    $pdo->exec("UPDATE settings SET last_global_reminder_at = NOW() WHERE id = 1");
    $pdo->commit();

    $platformName = $newPost['platform_name'];
    echo json_encode([
        'ok' => true,
        'type' => 'new_post',
        'due' => [
            'id' => (int)($newPost['task_id'] ?? 0),
            'post_id' => (int)$newPost['post_id'],
            'social_link_id' => (int)($newPost['social_link_id'] ?? 0),
            'brand_id' => (int)$newPost['brand_id'],
            'name' => $newPost['brand_name'],
            'platform' => $platformName,
            'post_title' => $newPost['post_title'] ?: 'New Post',
            'content_snippet' => $newPost['content_snippet'],
            'open_url' => $newPost['post_url'],
            'published_at' => $newPost['published_at'],
        ],
        'last_reminded_name' => $newPost['brand_name'] . " ({$platformName})",
        'last_reminded_time' => date('h:i A'),
        'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
    ]);
    exit;
}

// 3. Priority 2: Routine per-social-link reminder queue
$nowTime = date('H:i:s');
if ($nowTime < $settings['window_start'] || $nowTime > $settings['window_end']) {
    echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'outside_window']);
    exit;
}

$interval = (int)$settings['reminder_interval_minutes'];

// Respect global gap between reminders
if (!empty($settings['last_global_reminder_at'])) {
    $nextAllowed = strtotime($settings['last_global_reminder_at'] . " +{$interval} minutes");
    if (time() < $nextAllowed) {
        echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'global_interval']);
        exit;
    }
}

// Find next due social link from pending brands
$dueStmt = $pdo->prepare("
    SELECT s.id AS social_link_id, s.brand_id, s.platform, s.url AS social_url, s.rss_feed_url,
           s.last_feed_check_at, s.last_feed_status, s.last_reminded_at AS link_last_reminded,
           b.name AS brand_name, b.latest_post_url AS brand_latest_post_url,
           d.id AS task_id, d.last_reminded_at AS task_last_reminded
    FROM social_links s
    INNER JOIN brands b ON b.id = s.brand_id AND b.status = 1
    INNER JOIN daily_engagements d ON d.brand_id = b.id AND d.engagement_date = :today
    WHERE s.status = 1
      AND d.status = 'pending'
      AND (d.snoozed_until IS NULL OR d.snoozed_until <= NOW())
    ORDER BY (s.last_reminded_at IS NULL) DESC, s.last_reminded_at ASC, (d.last_reminded_at IS NULL) DESC, d.last_reminded_at ASC
    LIMIT 1
");
$dueStmt->execute(['today' => today()]);
$dueLink = $dueStmt->fetch();

// Fallback for brands that have pending tasks but no social_links rows
if (!$dueLink) {
    $fallbackStmt = $pdo->prepare("
        SELECT 0 AS social_link_id, b.id AS brand_id, 'Brand' AS platform,
               COALESCE(b.latest_post_url, '') AS social_url, '' AS rss_feed_url,
               b.name AS brand_name, b.latest_post_url AS brand_latest_post_url,
               d.id AS task_id, 'none' AS last_feed_status
        FROM daily_engagements d
        INNER JOIN brands b ON b.id = d.brand_id AND b.status = 1
        WHERE d.engagement_date = :today
          AND d.status = 'pending'
          AND (d.snoozed_until IS NULL OR d.snoozed_until <= NOW())
          AND NOT EXISTS (SELECT 1 FROM social_links s WHERE s.brand_id = b.id AND s.status = 1)
        ORDER BY (d.last_reminded_at IS NULL) DESC, d.last_reminded_at ASC
        LIMIT 1
    ");
    $fallbackStmt->execute(['today' => today()]);
    $dueLink = $fallbackStmt->fetch();
}

if (!$dueLink) {
    echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'nothing_due']);
    exit;
}

$hasRss = !empty(trim($dueLink['rss_feed_url'] ?? ''));
$feedStatus = $dueLink['last_feed_status'] ?? 'none';
$targetUrl = $dueLink['social_url'];
$postTitle = null;
$reminderType = 'social_reminder';

if ($hasRss && $dueLink['social_link_id'] > 0) {
    // If feed is stale or never checked, refresh it now
    $isStale = empty($dueLink['last_feed_check_at']) || (strtotime($dueLink['last_feed_check_at']) < strtotime('-3 minutes'));
    if ($isStale) {
        fetchBrandPosts($pdo, (int)$dueLink['brand_id'], false, (int)$dueLink['social_link_id']);
        $recheck = $pdo->prepare("SELECT last_feed_status FROM social_links WHERE id = :id");
        $recheck->execute(['id' => $dueLink['social_link_id']]);
        $refreshedStatus = $recheck->fetchColumn();
        if ($refreshedStatus) {
            $feedStatus = $refreshedStatus;
        }
    }

    // If feed works without bridge error, check if a latest post exists
    if ($feedStatus === 'ok') {
        $postStmt = $pdo->prepare("
            SELECT post_url, title FROM brand_posts
            WHERE social_link_id = :s_id
            ORDER BY published_at DESC, id DESC
            LIMIT 1
        ");
        $postStmt->execute(['s_id' => $dueLink['social_link_id']]);
        $latestPost = $postStmt->fetch();
        if ($latestPost && !empty($latestPost['post_url'])) {
            $targetUrl = $latestPost['post_url'];
            $postTitle = $latestPost['title'];
            $reminderType = 'latest_post';
        }
    }
}

// Update last reminded timestamps
$pdo->beginTransaction();
if (!empty($dueLink['social_link_id'])) {
    $pdo->prepare("UPDATE social_links SET last_reminded_at = NOW() WHERE id = :s_id")->execute(['s_id' => $dueLink['social_link_id']]);
}
if (!empty($dueLink['task_id'])) {
    $pdo->prepare("UPDATE daily_engagements SET last_reminded_at = NOW() WHERE id = :id")->execute(['id' => $dueLink['task_id']]);
}
$pdo->exec("UPDATE settings SET last_global_reminder_at = NOW() WHERE id = 1");
$pdo->commit();

$platformName = $dueLink['platform'];
echo json_encode([
    'ok' => true,
    'type' => $reminderType,
    'feed_status' => $feedStatus,
    'due' => [
        'id' => (int)($dueLink['task_id'] ?? 0),
        'social_link_id' => (int)($dueLink['social_link_id'] ?? 0),
        'brand_id' => (int)$dueLink['brand_id'],
        'name' => $dueLink['brand_name'],
        'platform' => $platformName,
        'post_title' => $postTitle,
        'open_url' => $targetUrl,
    ],
    'last_reminded_name' => $dueLink['brand_name'] . " ({$platformName})",
    'last_reminded_time' => date('h:i A'),
    'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
]);
