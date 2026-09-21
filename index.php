<?php
require_once __DIR__ . '/config.php';
requireLogin();

$currentUser = currentUser();
$currentUserId = (int)$currentUser['id'];
$pdo = db();
ensureTodayTasks($pdo, $currentUserId);
$settings = getSettings($pdo);

// Fetch fresh user record for user-specific last_reminder_at
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$userStmt->execute(['id' => $currentUserId]);
$userRow = $userStmt->fetch() ?: $currentUser;

$summaryStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(d.status = 'completed'), 0) AS completed,
    COALESCE(SUM(d.status = 'pending'), 0) AS pending,
    COALESCE(SUM(d.status = 'skipped'), 0) AS skipped
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    WHERE d.engagement_date = :today AND d.user_id = :uid AND b.status = 1");
$summaryStmt->execute(['today' => today(), 'uid' => $currentUserId]);
$summary = $summaryStmt->fetch();

if (!function_exists('formatRelativeTime')) {
    function formatRelativeTime(?string $datetime): string
    {
        if (!$datetime) return '';
        $ts = strtotime($datetime);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 86400 * 7) return floor($diff / 86400) . 'd ago';
        return date('d M Y', $ts);
    }
}

require_once __DIR__ . '/api_latest_posts.php';
$unseenPosts = getLatestUnseenPosts($pdo, 30, $currentUserId);

$tasksStmt = $pdo->prepare("SELECT d.*, b.name, b.latest_post_url, b.rss_feed_url, b.notes,
        p.title AS tracked_post_title, p.post_url AS tracked_post_url, p.published_at AS tracked_post_published_at,
        s.platform AS tracked_post_platform
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    LEFT JOIN brand_posts p ON p.id = (
        SELECT bp.id 
        FROM brand_posts bp 
        INNER JOIN users u ON u.id = :uid_sub
        WHERE bp.brand_id = b.id 
          AND bp.created_at >= u.created_at
          AND (bp.published_at IS NULL OR bp.published_at >= u.created_at)
        ORDER BY bp.published_at DESC, bp.id DESC 
        LIMIT 1
    )
    LEFT JOIN social_links s ON s.id = p.social_link_id
    WHERE d.engagement_date = :today AND d.user_id = :uid AND b.status = 1
    ORDER BY FIELD(d.status, 'pending','completed','skipped'), b.name ASC");
$tasksStmt->execute(['today' => today(), 'uid' => $currentUserId, 'uid_sub' => $currentUserId]);
$tasks = $tasksStmt->fetchAll();

$linkStmt = $pdo->prepare("
    SELECT s.id, s.platform, s.url,
           IF(dse.is_done = 1, 1, 0) AS is_done
    FROM social_links s
    LEFT JOIN daily_social_engagements dse 
        ON dse.social_link_id = s.id AND dse.engagement_date = :today AND dse.user_id = :uid
    WHERE s.brand_id = :brand_id AND s.status = 1 
    ORDER BY s.id ASC
");

// --- LAST REMINDER CALCULATION (PER USER) ---
$lastRemindedStmt = $pdo->prepare("SELECT d.last_reminded_at, b.name
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    WHERE d.engagement_date = :today AND d.user_id = :uid AND b.status = 1 AND d.last_reminded_at IS NOT NULL
    ORDER BY d.last_reminded_at DESC
    LIMIT 1");
$lastRemindedStmt->execute(['today' => today(), 'uid' => $currentUserId]);
$lastReminded = $lastRemindedStmt->fetch();

if ($lastReminded) {
    $lastRemindedTs = strtotime($lastReminded['last_reminded_at']);
    $lastReminderTitle = date('h:i A', $lastRemindedTs) . ' · ' . $lastReminded['name'];
    $minsAgo = max(0, round((time() - $lastRemindedTs) / 60));
    $lastReminderSubtitle = ($minsAgo === 0 ? 'Just now' : ($minsAgo < 60 ? $minsAgo . ' min ago' : round($minsAgo / 60, 1) . ' hr ago'));
} elseif (!empty($userRow['last_reminder_at']) && date('Y-m-d', strtotime($userRow['last_reminder_at'])) === today()) {
    $lastRemindedTs = strtotime($userRow['last_reminder_at']);
    $lastReminderTitle = date('h:i A', $lastRemindedTs);
    $lastReminderSubtitle = 'Earlier today';
} else {
    $lastReminderTitle = 'None yet today';
    $lastReminderSubtitle = 'Waiting for first reminder';
}

// --- NEXT REMINDER CALCULATION (PER USER) ---
$nowTime = date('H:i:s');
$windowStart = $settings['window_start'];
$windowEnd = $settings['window_end'];
$intervalMinutes = (int)$settings['reminder_interval_minutes'];
$pendingCount = (int)$summary['pending'];

$nextTargetTimestamp = null;
$nextStatus = 'active';

if ($pendingCount === 0) {
    if ((int)$summary['total'] === 0) {
        $nextReminderTitle = 'No active brands';
        $nextReminderSubtitle = 'Add brands to start reminders';
    } else {
        $nextReminderTitle = 'All completed! 🎉';
        $nextReminderSubtitle = 'All your pending tasks are done for today';
    }
    $nextStatus = 'completed';
} elseif ($nowTime < $windowStart) {
    $nextTargetTimestamp = strtotime(today() . ' ' . $windowStart);
    $nextReminderTitle = date('h:i A', $nextTargetTimestamp);
    $nextReminderSubtitle = 'Window starts at ' . date('h:i A', $nextTargetTimestamp);
    $nextStatus = 'window_pending';
} elseif ($nowTime > $windowEnd) {
    $nextTargetTimestamp = strtotime('+1 day ' . $windowStart);
    $nextReminderTitle = 'Tomorrow ' . date('h:i A', strtotime($windowStart));
    $nextReminderSubtitle = 'Today\'s window closed at ' . date('h:i A', strtotime($windowEnd));
    $nextStatus = 'window_closed';
} else {
    $snoozeCheckStmt = $pdo->prepare("SELECT
        COUNT(*) AS total_pending,
        SUM(d.snoozed_until IS NOT NULL AND d.snoozed_until > NOW()) AS snoozed_count,
        MIN(CASE WHEN d.snoozed_until IS NOT NULL AND d.snoozed_until > NOW() THEN d.snoozed_until END) AS earliest_snooze
        FROM daily_engagements d
        INNER JOIN brands b ON b.id = d.brand_id
        WHERE d.engagement_date = :today AND d.user_id = :uid AND b.status = 1 AND d.status = 'pending'");
    $snoozeCheckStmt->execute(['today' => today(), 'uid' => $currentUserId]);
    $snoozeInfo = $snoozeCheckStmt->fetch();

    $allSnoozed = ($snoozeInfo && $snoozeInfo['total_pending'] > 0 && $snoozeInfo['total_pending'] == $snoozeInfo['snoozed_count']);
    $lastUserTs = !empty($userRow['last_reminder_at']) ? strtotime($userRow['last_reminder_at']) : 0;
    $intervalSec = $intervalMinutes * 60;
    $nextAllowed = $lastUserTs ? ($lastUserTs + $intervalSec) : time();

    if ($allSnoozed && !empty($snoozeInfo['earliest_snooze'])) {
        $snoozeEndTs = strtotime($snoozeInfo['earliest_snooze']);
        $nextTargetTimestamp = max($nextAllowed, $snoozeEndTs);
        $nextReminderTitle = date('h:i A', $nextTargetTimestamp);
        $nextReminderSubtitle = 'All your pending brands snoozed';
        $nextStatus = 'snoozed';
    } else {
        if (time() >= $nextAllowed) {
            $nextTargetTimestamp = time();
            $nextReminderTitle = 'Due Now ⚡';
            $nextReminderSubtitle = 'Queue ready for engagement';
            $nextStatus = 'due';
        } else {
            $nextTargetTimestamp = $nextAllowed;
            $remainingMins = max(1, ceil(($nextAllowed - time()) / 60));
            $nextReminderTitle = 'In ' . $remainingMins . ' min (' . date('h:i A', $nextAllowed) . ')';
            $nextReminderSubtitle = 'Every ' . $intervalMinutes . ' min interval';
            $nextStatus = 'countdown';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brand Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body data-poll-seconds="<?= (int)$settings['browser_poll_seconds'] ?>">
<header class="topbar">
    <div>
        <h1>Brand Engagement Reminder</h1>
        <p><?= e(date('l, d M Y')) ?> · Window <?= e(substr($settings['window_start'],0,5)) ?>–<?= e(substr($settings['window_end'],0,5)) ?> · Every <?= (int)$settings['reminder_interval_minutes'] ?> min</p>
    </div>
    <div class="topbar-right">
        <nav>
            <a href="index.php" class="active">Dashboard</a>
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
                <a href="brands.php">Brands</a>
                <a href="settings.php">Settings</a>
                <a href="users.php">Users</a>
            <?php endif; ?>
        </nav>
        <div class="user-menu">
            <span class="user-badge" title="Logged in as <?= e($currentUser['username']) ?>">
                <span class="user-avatar">👤</span>
                <span class="user-name"><?= e($currentUser['name']) ?></span>
                <span class="role-badge role-<?= e($currentUser['role']) ?>"><?= e(strtoupper($currentUser['role'])) ?></span>
            </span>
            <a href="logout.php" class="btn-logout" title="Sign out">Logout</a>
        </div>
    </div>
</header>

<main class="container">
    <?php if (!empty($_GET['error']) && $_GET['error'] === 'forbidden'): ?>
        <div class="alert error" style="margin-bottom:16px;">
            ⚠️ অ্যাক্সেস নিষিদ্ধ: আপনার এই পেজে প্রবেশের অনুমতি নেই। শুধুমাত্র অ্যাডমিন সিস্টেম সেটিংস ও ব্র্যান্ড কনফিগারেশন দেখতে পারেন।
        </div>
    <?php endif; ?>

    <section class="notice-bar">
        <div>
            <strong>Browser notifications:</strong> Keep this tab open during office hours. Click Enable Notifications once.
        </div>
        <button id="enableNotifications" class="btn btn-dark">Enable Notifications</button>
    </section>

    <section class="reminder-cards-grid">
        <div class="reminder-card last">
            <div class="reminder-card-icon">🕒</div>
            <div class="reminder-card-content">
                <span class="reminder-card-label">Last Reminder</span>
                <strong class="reminder-card-title" id="lastReminderValue"><?= e($lastReminderTitle) ?></strong>
                <span class="reminder-card-sub" id="lastReminderSub"><?= e($lastReminderSubtitle) ?></span>
            </div>
        </div>
        <div class="reminder-card next" id="nextReminderCard" data-target-ts="<?= (int)($nextTargetTimestamp ?? 0) ?>" data-status="<?= e($nextStatus) ?>">
            <div class="reminder-card-icon">⏳</div>
            <div class="reminder-card-content">
                <span class="reminder-card-label">Next Reminder</span>
                <strong class="reminder-card-title" id="nextReminderValue"><?= e($nextReminderTitle) ?></strong>
                <span class="reminder-card-sub" id="nextReminderSub"><?= e($nextReminderSubtitle) ?></span>
            </div>
        </div>
    </section>

    <section class="stats-grid">
        <div class="stat"><span>Total</span><strong><?= (int)$summary['total'] ?></strong></div>
        <div class="stat"><span>Pending</span><strong><?= (int)$summary['pending'] ?></strong></div>
        <div class="stat"><span>Completed</span><strong><?= (int)$summary['completed'] ?></strong></div>
        <div class="stat"><span>Skipped</span><strong><?= (int)$summary['skipped'] ?></strong></div>
    </section>

    <section class="latest-posts-section" id="latestPostsSection">
        <div class="section-head" style="margin-bottom:12px;">
            <div>
                <h2>📢 Latest Posts <span class="counter-badge" id="unseenPostsBadge" style="background:#e0e7ff;color:#3730a3;"><?= count($unseenPosts) ?></span></h2>
                <p>নতুন পাবলিশ হওয়া যেসব পোস্ট এখনও দেখা বা এনগেজ করা হয়নি।</p>
            </div>
            <div class="section-actions">
                <button type="button" id="refreshLatestPostsBtn" class="btn btn-light" title="Check RSS feeds and refresh latest posts">
                    🔄 Refresh Posts
                </button>
                <?php if (!empty($unseenPosts)): ?>
                    <button type="button" id="markAllPostsSeenBtn" class="btn btn-ghost" title="Mark all listed posts as seen">
                        ✓ Mark All as Seen
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="latest-posts-grid" id="latestPostsGrid" <?= empty($unseenPosts) ? 'style="display:none;"' : '' ?>>
            <?php foreach ($unseenPosts as $up):
                $platformKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $up['platform']));
            ?>
                <div class="latest-post-card" id="unseen-post-<?= (int)$up['id'] ?>" data-post-id="<?= (int)$up['id'] ?>">
                    <div>
                        <div class="latest-post-header">
                            <div class="latest-post-brand">
                                <span><?= e($up['brand_name']) ?></span>
                                <span class="platform-pill platform-<?= e($platformKey) ?>"><?= e($up['platform']) ?></span>
                            </div>
                            <span class="latest-post-time"><?= e($up['relative_time']) ?></span>
                        </div>
                        <h4 class="latest-post-title">
                            <a href="<?= e($up['post_url']) ?>" target="_blank" rel="noopener" class="open-unseen-post" data-post-id="<?= (int)$up['id'] ?>">
                                <?= e($up['title']) ?>
                            </a>
                        </h4>
                        <?php if (!empty($up['snippet'])): ?>
                            <p class="latest-post-snippet"><?= e($up['snippet']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="latest-post-actions">
                        <a href="<?= e($up['post_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary open-unseen-post" data-post-id="<?= (int)$up['id'] ?>">
                            ⚡ Open Post ↗
                        </a>
                        <button type="button" class="btn btn-sm btn-ghost mark-post-seen" data-post-id="<?= (int)$up['id'] ?>" title="Mark as seen without opening">
                            ✓ Mark Seen
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="empty-state-compact" id="latestPostsEmptyState" <?= !empty($unseenPosts) ? 'style="display:none;"' : '' ?>>
            <div style="font-size:32px;margin-bottom:8px;">🎉</div>
            <h4>সব নতুন পোস্ট দেখা শেষ!</h4>
            <p class="muted">কোনো নতুন অদেখা পোস্ট নেই। ফিডে নতুন পোস্ট আসলে স্বয়ংক্রিয়ভাবে এখানে তালিকাভুক্ত হবে।</p>
        </div>
    </section>

    <section class="section-head">
        <div>
            <h2>Today's Engagement Queue</h2>
            <p>Open a post/page, interact manually, then mark the action here.</p>
        </div>
        <div class="section-actions">
            <button type="button" id="checkFeedsBtn" class="btn btn-light" title="Check all RSS feeds for new posts right now">📡 Check Feeds Now</button>
            <div class="search-box">
                <input type="search" id="dashboardSearch" placeholder="🔍 Search brands..." autocomplete="off">
            </div>
        </div>
    </section>

    <?php if (!$tasks): ?>
        <div class="empty-state">
            <h3>No brands yet</h3>
            <p>Add your first brand and social links to start.</p>
            <a class="btn btn-primary" href="brands.php">Add Brand</a>
        </div>
    <?php else: ?>
        <div id="searchEmptyState" class="empty-state" style="display:none;margin-bottom:16px;">
            <h3>No matching brands found</h3>
            <p>Try searching with another keyword.</p>
        </div>
        <div class="task-grid">
        <?php foreach ($tasks as $task):
            $linkStmt->execute(['brand_id' => $task['brand_id'], 'today' => today(), 'uid' => $currentUserId]);
            $links = $linkStmt->fetchAll();
            $isDone = $task['status'] === 'completed';
            $totalLinksCount = count($links);
            $doneLinksCount = 0;
            foreach ($links as $l) {
                if (!empty($l['is_done'])) $doneLinksCount++;
            }

            // Social profile links only for "Open All" (excluding latest post)
            $allCardLinks = [];
            foreach ($links as $link) {
                if (!empty($link['url']) && !in_array($link['url'], $allCardLinks, true)) {
                    $allCardLinks[] = $link['url'];
                }
            }
        ?>
            <article class="task-card <?= $isDone ? 'done' : '' ?>" id="task-<?= (int)$task['id'] ?>" data-name="<?= e(mb_strtolower($task['name'])) ?>">
                <div class="task-title-row">
                    <div>
                        <span class="status-pill status-<?= e($task['status']) ?>"><?= e(ucfirst($task['status'])) ?></span>
                        <?php if ($totalLinksCount > 1): ?>
                            <span class="links-count-pill <?= $doneLinksCount === $totalLinksCount ? 'all-done' : '' ?>" title="<?= $doneLinksCount ?> of <?= $totalLinksCount ?> links engaged today">
                                <?= $doneLinksCount ?>/<?= $totalLinksCount ?> links
                            </span>
                        <?php endif; ?>
                        <h3><?= e($task['name']) ?></h3>
                    </div>
                    <?php if ($task['last_reminded_at']): ?>
                        <small>Last reminder: <?= e(date('h:i A', strtotime($task['last_reminded_at']))) ?></small>
                    <?php endif; ?>
                </div>

                <?php if ($task['notes']): ?><p class="muted"><?= e($task['notes']) ?></p><?php endif; ?>

                <?php if (!empty($task['tracked_post_title']) || !empty($task['tracked_post_url'])): ?>
                    <div class="post-preview-box">
                        <div class="post-preview-header">
                            <span class="post-preview-badge">📢 Latest Post<?= !empty($task['tracked_post_platform']) ? ' · ' . e($task['tracked_post_platform']) : '' ?></span>
                            <?php if (!empty($task['tracked_post_published_at'])): ?>
                                <span class="post-preview-time"><?= e(formatRelativeTime($task['tracked_post_published_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="post-preview-title"><?= e($task['tracked_post_title'] ?: 'New Post Published') ?></div>
                        <a class="post-direct-link" href="<?= e($task['tracked_post_url']) ?>" target="_blank" rel="noopener">⚡ সরাসরি পোস্টটি দেখুন ↗</a>
                    </div>
                <?php endif; ?>

                <div class="links">
                    <?php if (count($allCardLinks) > 1): ?>
                        <button type="button" class="link-chip open-all-card-links" data-urls="<?= e(json_encode($allCardLinks)) ?>" style="cursor:pointer;background:#eef2ff;border-color:#c7d7fe;color:#1e40af;font-weight:600;">⚡ Open All (<?= count($allCardLinks) ?>) ↗</button>
                    <?php endif; ?>
                    <?php if ($task['latest_post_url'] && $task['latest_post_url'] !== ($task['tracked_post_url'] ?? '')): ?>
                        <a class="link-chip primary" href="<?= e($task['latest_post_url']) ?>" target="_blank" rel="noopener">Open Latest Post ↗</a>
                    <?php endif; ?>
                    <?php foreach ($links as $link): ?>
                        <a class="link-chip social-card-link <?= $link['is_done'] ? 'is-done' : '' ?>" 
                           href="<?= e($link['url']) ?>" 
                           target="_blank" 
                           rel="noopener"
                           data-link-id="<?= (int)$link['id'] ?>"
                           data-task-id="<?= (int)$task['id'] ?>"
                           data-brand-name="<?= e($task['name']) ?>"
                           data-platform="<?= e($link['platform']) ?>"
                           title="<?= $link['is_done'] ? 'আজ সম্পন্ন হয়েছে' : 'ক্লিক করে লিঙ্ক ওপেন করুন ও Mark Done হবে' ?>">
                            <?= e($link['platform']) ?> <span><?= $link['is_done'] ? '✓' : '↗' ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="actions-row">
                    <button class="action-toggle <?= $task['like_done'] ? 'checked' : '' ?>" data-task="<?= (int)$task['id'] ?>" data-action="like">👍 Like <span><?= $task['like_done'] ? '✓' : '' ?></span></button>
                    <button class="action-toggle <?= $task['comment_done'] ? 'checked' : '' ?>" data-task="<?= (int)$task['id'] ?>" data-action="comment">💬 Comment <span><?= $task['comment_done'] ? '✓' : '' ?></span></button>
                    <button class="action-toggle <?= $task['share_done'] ? 'checked' : '' ?>" data-task="<?= (int)$task['id'] ?>" data-action="share">↗ Share <span><?= $task['share_done'] ? '✓' : '' ?></span></button>
                </div>

                <?php if ($task['status'] === 'pending'): ?>
                <div class="secondary-actions">
                    <button class="btn btn-success mark-all" data-task="<?= (int)$task['id'] ?>">Mark All Done</button>
                    <button class="btn btn-light snooze" data-task="<?= (int)$task['id'] ?>" data-minutes="30">Snooze 30m</button>
                    <button class="btn btn-light snooze" data-task="<?= (int)$task['id'] ?>" data-minutes="60">Snooze 1h</button>
                    <button class="btn btn-ghost skip" data-task="<?= (int)$task['id'] ?>">Skip Today</button>
                </div>
                <?php endif; ?>

                <?php if ($task['snoozed_until'] && $task['status'] === 'pending'): ?>
                    <div class="snooze-note">Snoozed until <?= e(date('h:i A', strtotime($task['snoozed_until']))) ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div id="popupModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3>⚠️ ব্রাউজারে Pop-up ব্লক করা আছে</h3>
            <button type="button" class="close-modal" id="closePopupModal">&times;</button>
        </div>
        <div class="modal-body">
            <p>ব্রাউজারের ডিফল্ট সিকিউরিটির কারণে ১টি ক্লিকে একাধিক ট্যাব ওপেন হতে পারছে না (শুধুমাত্র ১ম ট্যাবটি খুলে বাকিগুলো ব্লক হয়েছে)।</p>
            <div class="popup-steps">
                <strong>সব লিঙ্ক একসাথে খুলতে মাত্র ১ বার এই কাজটি করুন:</strong>
                <ol>
                    <li>আপনার ব্রাউজারের উপরের <strong>Address Bar (URL bar)</strong>-এর ডানপাশে <strong>Pop-up blocked (🚫)</strong> আইকনটিতে ক্লিক করুন।</li>
                    <li><strong>"Always allow pop-ups and redirects from http://localhost..."</strong> সিলেক্ট করুন।</li>
                    <li><strong>"Done"</strong> বাটনে ক্লিক করুন।</li>
                </ol>
            </div>
            <p class="muted" style="margin-top:12px;font-size:13px;">💡 এটি ব্রাউজারের ১-বারের সেটিং। এরপর থেকে Open All বা নোটিফিকেশনে ক্লিক করলেই সব লিঙ্ক একসাথে খুলে যাবে।</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="popupModalOk">ঠিক আছে, বুঝেছি</button>
        </div>
    </div>
</div>

<div id="toast" class="toast" role="status"></div>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
