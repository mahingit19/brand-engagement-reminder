<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fetch_posts.php';
require_once __DIR__ . '/api_latest_posts.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$currentUser = currentUser();
$userId = (int)$currentUser['id'];

ensureTodayTasks($pdo, $userId);
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

// 2. Fetch user's own last_reminder_at
$userStmt = $pdo->prepare("SELECT last_reminder_at FROM users WHERE id = :uid");
$userStmt->execute(['uid' => $userId]);
$userLastReminded = $userStmt->fetchColumn();

$force = !empty($_REQUEST['force']);

if (!$force) {
    // 3. Check window hours
    $nowTime = date('H:i:s');
    if ($nowTime < $settings['window_start'] || $nowTime > $settings['window_end']) {
        echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'outside_window']);
        exit;
    }

    $interval = (int)$settings['reminder_interval_minutes'];

    // 4. Respect user's personal interval gap between reminders
    if (!empty($userLastReminded)) {
        $nextAllowed = strtotime($userLastReminded . " +{$interval} minutes");
        if (time() < $nextAllowed) {
            echo json_encode(['ok'=>true, 'due'=>null, 'reason'=>'user_interval']);
            exit;
        }
    }
} else {
    $interval = (int)$settings['reminder_interval_minutes'];
}

// 5. Priority 1: Check for unseen latest posts for THIS user
$unseenPosts = getLatestUnseenPosts($pdo, 15, $userId);

if (!empty($unseenPosts)) {
    $count = count($unseenPosts);
    $postIds = array_column($unseenPosts, 'id');
    $urls = array_values(array_filter(array_column($unseenPosts, 'post_url')));
    $brandNames = array_values(array_unique(array_column($unseenPosts, 'brand_name')));

    // Update user's personal last_reminder_at
    $pdo->prepare("UPDATE users SET last_reminder_at = NOW() WHERE id = :uid")->execute(['uid' => $userId]);

    // Mark these posts as notified for THIS user in user_post_engagements
    if (!empty($postIds)) {
        $insNotified = $pdo->prepare("
            INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged)
            VALUES (:uid, :pid, 1, 0)
            ON DUPLICATE KEY UPDATE is_notified = 1
        ");
        foreach ($postIds as $pid) {
            $insNotified->execute(['uid' => $userId, 'pid' => (int)$pid]);
        }
    }

    // Update last_reminded_at for these brands in THIS user's daily_engagements
    $brandIds = array_unique(array_column($unseenPosts, 'brand_id'));
    if (!empty($brandIds)) {
        $inBrands = implode(',', array_map('intval', $brandIds));
        $pdo->exec("UPDATE daily_engagements SET last_reminded_at = NOW() WHERE user_id = {$userId} AND brand_id IN ($inBrands) AND engagement_date = '" . today() . "'");
    }

    if ($count === 1) {
        $first = $unseenPosts[0];
        $title = "📢 নতুন পোস্ট: {$first['brand_name']} ({$first['platform']})";
        $body = "{$first['title']}\n👉 ক্লিক করে সরাসরি পোস্টটি দেখুন (Mark Seen হবে)";
        $reminderLabel = "{$first['brand_name']} ({$first['platform']})";
    } else {
        $brandListStr = implode(', ', array_slice($brandNames, 0, 4));
        if (count($brandNames) > 4) {
            $brandListStr .= '... এবং আরও ' . (count($brandNames) - 4) . 'টি';
        }
        $title = "📢 {$count}টি নতুন পোস্ট রয়েছে!";
        $body = "ব্র্যান্ড: {$brandListStr}\n👉 ক্লিক করলে সব পোস্ট একসাথে ওপেন হবে ও Mark Seen হবে";
        $reminderLabel = "{$count} New Posts ({$brandListStr})";
    }

    echo json_encode([
        'ok' => true,
        'type' => 'batch_latest_posts',
        'due' => [
            'count' => $count,
            'post_ids' => $postIds,
            'urls' => $urls,
            'brand_names' => $brandNames,
            'title' => $title,
            'body' => $body,
            'posts' => $unseenPosts,
        ],
        'last_reminded_name' => $reminderLabel,
        'last_reminded_time' => date('h:i A'),
        'next_target_ts' => time() + ($interval * 60)
    ]);
    exit;
}

// 6. Priority 2: Routine per-social-link reminder queue for THIS user
$dueStmt = $pdo->prepare("
    SELECT s.id AS social_link_id, s.brand_id, s.platform, s.url AS social_url, s.rss_feed_url,
           s.last_feed_check_at, s.last_feed_status, s.last_reminded_at AS link_last_reminded,
           b.name AS brand_name, b.latest_post_url AS brand_latest_post_url,
           d.id AS task_id, d.last_reminded_at AS task_last_reminded
    FROM social_links s
    INNER JOIN brands b ON b.id = s.brand_id AND b.status = 1
    INNER JOIN daily_engagements d ON d.brand_id = b.id AND d.user_id = :uid AND d.engagement_date = :today
    LEFT JOIN daily_social_engagements dse ON dse.social_link_id = s.id AND dse.engagement_date = :today2 AND dse.user_id = :uid2
    WHERE s.status = 1
      AND d.status = 'pending'
      AND (d.snoozed_until IS NULL OR d.snoozed_until <= NOW())
      AND (dse.id IS NULL OR dse.is_done = 0)
    ORDER BY (d.last_reminded_at IS NULL) DESC, d.last_reminded_at ASC, (s.last_reminded_at IS NULL) DESC, s.last_reminded_at ASC
    LIMIT 1
");
$dueStmt->execute([
    'today' => today(),
    'today2' => today(),
    'uid' => $userId,
    'uid2' => $userId
]);
$dueLink = $dueStmt->fetch();

// Fallback for brands that have pending tasks for THIS user but no social_links rows
if (!$dueLink) {
    $fallbackStmt = $pdo->prepare("
        SELECT 0 AS social_link_id, b.id AS brand_id, 'Brand' AS platform,
               COALESCE(b.latest_post_url, '') AS social_url, '' AS rss_feed_url,
               b.name AS brand_name, b.latest_post_url AS brand_latest_post_url,
               d.id AS task_id, 'none' AS last_feed_status
        FROM daily_engagements d
        INNER JOIN brands b ON b.id = d.brand_id AND b.status = 1
        WHERE d.engagement_date = :today
          AND d.user_id = :uid
          AND d.status = 'pending'
          AND (d.snoozed_until IS NULL OR d.snoozed_until <= NOW())
          AND NOT EXISTS (SELECT 1 FROM social_links s WHERE s.brand_id = b.id AND s.status = 1)
        ORDER BY (d.last_reminded_at IS NULL) DESC, d.last_reminded_at ASC
        LIMIT 1
    ");
    $fallbackStmt->execute(['today' => today(), 'uid' => $userId]);
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
}

// Calculate brand's total links and completed links today for THIS user
$brandTotalLinks = 0;
$brandDoneLinks = 0;
if (!empty($dueLink['brand_id'])) {
    $totStmt = $pdo->prepare("SELECT COUNT(*) FROM social_links WHERE brand_id = :bid AND status = 1");
    $totStmt->execute(['bid' => $dueLink['brand_id']]);
    $brandTotalLinks = (int)$totStmt->fetchColumn();

    $dnStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) 
        FROM social_links s
        INNER JOIN daily_social_engagements dse ON dse.social_link_id = s.id AND dse.engagement_date = :today AND dse.user_id = :uid
        WHERE s.brand_id = :bid AND s.status = 1 AND dse.is_done = 1
    ");
    $dnStmt->execute(['bid' => $dueLink['brand_id'], 'today' => today(), 'uid' => $userId]);
    $brandDoneLinks = (int)$dnStmt->fetchColumn();
}

// Update last reminded timestamps for THIS user
$pdo->beginTransaction();
if (!empty($dueLink['social_link_id'])) {
    $pdo->prepare("UPDATE social_links SET last_reminded_at = NOW() WHERE id = :s_id")->execute(['s_id' => $dueLink['social_link_id']]);
}
if (!empty($dueLink['task_id'])) {
    $pdo->prepare("UPDATE daily_engagements SET last_reminded_at = NOW() WHERE id = :id AND user_id = :uid")->execute([
        'id' => $dueLink['task_id'],
        'uid' => $userId
    ]);
}
$pdo->prepare("UPDATE users SET last_reminder_at = NOW() WHERE id = :uid")->execute(['uid' => $userId]);
$pdo->commit();

$platformName = $dueLink['platform'];
$progressText = $brandTotalLinks > 1 ? " (" . ($brandDoneLinks + 1) . "/{$brandTotalLinks})" : "";

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
        'done_count' => $brandDoneLinks,
        'total_count' => $brandTotalLinks,
    ],
    'last_reminded_name' => $dueLink['brand_name'] . " ({$platformName})" . $progressText,
    'last_reminded_time' => date('h:i A'),
    'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
]);
