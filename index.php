<?php
require __DIR__ . '/config.php';
$pdo = db();
ensureTodayTasks($pdo);
$settings = getSettings($pdo);

$summaryStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(d.status = 'completed'), 0) AS completed,
    COALESCE(SUM(d.status = 'pending'), 0) AS pending,
    COALESCE(SUM(d.status = 'skipped'), 0) AS skipped
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    WHERE d.engagement_date = :today AND b.status = 1");
$summaryStmt->execute(['today' => today()]);
$summary = $summaryStmt->fetch();

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

$tasksStmt = $pdo->prepare("SELECT d.*, b.name, b.latest_post_url, b.rss_feed_url, b.notes,
        p.title AS tracked_post_title, p.post_url AS tracked_post_url, p.published_at AS tracked_post_published_at,
        s.platform AS tracked_post_platform
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    LEFT JOIN brand_posts p ON p.id = (
        SELECT bp.id FROM brand_posts bp WHERE bp.brand_id = b.id ORDER BY bp.published_at DESC, bp.id DESC LIMIT 1
    )
    LEFT JOIN social_links s ON s.id = p.social_link_id
    WHERE d.engagement_date = :today AND b.status = 1
    ORDER BY FIELD(d.status, 'pending','completed','skipped'), b.name ASC");
$tasksStmt->execute(['today' => today()]);
$tasks = $tasksStmt->fetchAll();

$linkStmt = $pdo->prepare("SELECT platform, url FROM social_links WHERE brand_id = :brand_id AND status = 1 ORDER BY id ASC");

// --- LAST REMINDER CALCULATION ---
$lastRemindedStmt = $pdo->prepare("SELECT d.last_reminded_at, b.name
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    WHERE d.engagement_date = :today AND b.status = 1 AND d.last_reminded_at IS NOT NULL
    ORDER BY d.last_reminded_at DESC
    LIMIT 1");
$lastRemindedStmt->execute(['today' => today()]);
$lastReminded = $lastRemindedStmt->fetch();

if ($lastReminded) {
    $lastRemindedTs = strtotime($lastReminded['last_reminded_at']);
    $lastReminderTitle = date('h:i A', $lastRemindedTs) . ' · ' . $lastReminded['name'];
    $minsAgo = max(0, round((time() - $lastRemindedTs) / 60));
    $lastReminderSubtitle = ($minsAgo === 0 ? 'Just now' : ($minsAgo < 60 ? $minsAgo . ' min ago' : round($minsAgo / 60, 1) . ' hr ago'));
} elseif (!empty($settings['last_global_reminder_at']) && date('Y-m-d', strtotime($settings['last_global_reminder_at'])) === today()) {
    $lastRemindedTs = strtotime($settings['last_global_reminder_at']);
    $lastReminderTitle = date('h:i A', $lastRemindedTs);
    $lastReminderSubtitle = 'Earlier today';
} else {
    $lastReminderTitle = 'None yet today';
    $lastReminderSubtitle = 'Waiting for first reminder';
}

// --- NEXT REMINDER CALCULATION ---
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
        $nextReminderSubtitle = 'All pending tasks done for today';
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
        WHERE d.engagement_date = :today AND b.status = 1 AND d.status = 'pending'");
    $snoozeCheckStmt->execute(['today' => today()]);
    $snoozeInfo = $snoozeCheckStmt->fetch();

    $allSnoozed = ($snoozeInfo && $snoozeInfo['total_pending'] > 0 && $snoozeInfo['total_pending'] == $snoozeInfo['snoozed_count']);
    $lastGlobalTs = !empty($settings['last_global_reminder_at']) ? strtotime($settings['last_global_reminder_at']) : 0;
    $intervalSec = $intervalMinutes * 60;
    $nextAllowed = $lastGlobalTs ? ($lastGlobalTs + $intervalSec) : time();

    if ($allSnoozed && !empty($snoozeInfo['earliest_snooze'])) {
        $snoozeEndTs = strtotime($snoozeInfo['earliest_snooze']);
        $nextTargetTimestamp = max($nextAllowed, $snoozeEndTs);
        $nextReminderTitle = date('h:i A', $nextTargetTimestamp);
        $nextReminderSubtitle = 'All pending brands snoozed';
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
    <nav>
        <a href="index.php" class="active">Dashboard</a>
        <a href="brands.php">Brands</a>
        <a href="settings.php">Settings</a>
    </nav>
</header>

<main class="container">
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
            $linkStmt->execute(['brand_id' => $task['brand_id']]);
            $links = $linkStmt->fetchAll();
            $isDone = $task['status'] === 'completed';

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
                        <a class="link-chip" href="<?= e($link['url']) ?>" target="_blank" rel="noopener"><?= e($link['platform']) ?> ↗</a>
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
