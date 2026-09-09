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

$tasksStmt = $pdo->prepare("SELECT d.*, b.name, b.latest_post_url, b.notes
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id
    WHERE d.engagement_date = :today AND b.status = 1
    ORDER BY FIELD(d.status, 'pending','completed','skipped'), b.name ASC");
$tasksStmt->execute(['today' => today()]);
$tasks = $tasksStmt->fetchAll();

$linkStmt = $pdo->prepare("SELECT platform, url FROM social_links WHERE brand_id = :brand_id AND status = 1 ORDER BY id ASC");
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
        <div class="search-box">
            <input type="search" id="dashboardSearch" placeholder="🔍 Search brands..." autocomplete="off">
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

            $allCardLinks = [];
            if (!empty($task['latest_post_url'])) {
                $allCardLinks[] = $task['latest_post_url'];
            }
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

                <div class="links">
                    <?php if (count($allCardLinks) > 1): ?>
                        <button type="button" class="link-chip open-all-card-links" data-urls="<?= e(json_encode($allCardLinks)) ?>" style="cursor:pointer;background:#eef2ff;border-color:#c7d7fe;color:#1e40af;font-weight:600;">⚡ Open All (<?= count($allCardLinks) ?>) ↗</button>
                    <?php endif; ?>
                    <?php if ($task['latest_post_url']): ?>
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

<div id="toast" class="toast" role="status"></div>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
