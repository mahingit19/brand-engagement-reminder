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

// 2. Priority 1: Check for any unnotified newly published post
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
            'brand_id' => (int)$newPost['brand_id'],
            'name' => $newPost['brand_name'],
            'platform' => $platformName,
            'post_title' => $newPost['post_title'] ?: 'New Post',
            'content_snippet' => $newPost['content_snippet'],
            'open_url' => $newPost['post_url'],
            'links' => [$newPost['post_url']],
            'published_at' => $newPost['published_at'],
        ],
        'last_reminded_name' => $newPost['brand_name'] . " ({$platformName})",
        'last_reminded_time' => date('h:i A'),
        'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
    ]);
    exit;
}

// 3. Priority 2: Standard routine engagement reminder
$nowTime = date('H:i:s');
if ($nowTime < $settings['window_start'] || $nowTime > $settings['window_end']) {
    echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'outside_window']);
    exit;
}

$interval = (int)$settings['reminder_interval_minutes'];

// Keep a global gap between reminders so different brands are spread across the day
// instead of appearing one after another on every browser poll.
if (!empty($settings['last_global_reminder_at'])) {
    $nextAllowed = strtotime($settings['last_global_reminder_at'] . " +{$interval} minutes");
    if (time() < $nextAllowed) {
        echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'global_interval']);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT d.id, d.brand_id, d.last_reminded_at, b.name, b.latest_post_url,
        (SELECT url FROM social_links s WHERE s.brand_id=b.id AND s.status=1 ORDER BY s.id LIMIT 1) AS first_social_url
    FROM daily_engagements d
    INNER JOIN brands b ON b.id=d.brand_id AND b.status=1
    WHERE d.engagement_date=:today
      AND d.status='pending'
      AND (d.snoozed_until IS NULL OR d.snoozed_until <= NOW())
    ORDER BY (d.last_reminded_at IS NULL) DESC, d.last_reminded_at ASC, RAND()
    LIMIT 1");
$stmt->execute(['today'=>today()]);
$due = $stmt->fetch();

if (!$due) {
    echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'nothing_due']);
    exit;
}

$pdo->beginTransaction();
$pdo->prepare("UPDATE daily_engagements SET last_reminded_at=NOW() WHERE id=:id")->execute(['id'=>$due['id']]);
$pdo->exec("UPDATE settings SET last_global_reminder_at=NOW() WHERE id=1");
$pdo->commit();

// Fetch all active social links for this brand
$linkStmt = $pdo->prepare("SELECT url FROM social_links WHERE brand_id = :brand_id AND status = 1 ORDER BY id ASC");
$linkStmt->execute(['brand_id' => $due['brand_id']]);
$socialUrls = $linkStmt->fetchAll(PDO::FETCH_COLUMN);

$links = [];
if (!empty($due['latest_post_url'])) {
    $links[] = $due['latest_post_url'];
}
foreach ($socialUrls as $url) {
    $url = trim((string)$url);
    if ($url !== '' && !in_array($url, $links, true)) {
        $links[] = $url;
    }
}

$due['links'] = $links;
$due['open_url'] = $links[0] ?? ($due['latest_post_url'] ?: $due['first_social_url']);
echo json_encode([
    'ok' => true,
    'type' => 'standard',
    'due' => $due,
    'last_reminded_name' => $due['name'],
    'last_reminded_time' => date('h:i A'),
    'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
]);
