<?php
require __DIR__ . '/config.php';
requireAdmin();
$currentUser = currentUser();
$pdo = db();
$settings = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start = $_POST['window_start'] ?? '10:00';
    $end = $_POST['window_end'] ?? '17:30';
    $interval = max(5, min(240, (int)($_POST['reminder_interval_minutes'] ?? 45)));
    $poll = max(30, min(300, (int)($_POST['browser_poll_seconds'] ?? 60)));
    $stmt = $pdo->prepare("UPDATE settings SET window_start=:start, window_end=:end, reminder_interval_minutes=:interval, browser_poll_seconds=:poll WHERE id=1");
    $stmt->execute(['start'=>$start, 'end'=>$end, 'interval'=>$interval, 'poll'=>$poll]);
    
    logUserActivity(
        $pdo,
        (int)$currentUser['id'],
        'update_settings',
        null,
        null,
        null,
        "Settings updated: Window {$start}-{$end}, Interval {$interval} min, Poll {$poll}s"
    );

    header('Location: settings.php?saved=1');
    exit;
}
$settings = getSettings($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Settings · Brand Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div>
        <h1>Reminder Settings</h1>
        <p>Control the daily window and reminder frequency.</p>
    </div>
    <div class="topbar-right">
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <a href="leaderboard.php">Leaderboard</a>
            <?php if (isAdmin()): ?>
                <a href="brands.php">Brands</a>
                <a href="settings.php" class="active">Settings</a>
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
<main class="container narrow">
    <section class="panel">
        <h2>Daily Reminder Window</h2>
        <?php if (!empty($_GET['saved'])): ?><div class="alert success">Settings saved.</div><?php endif; ?>
        <form method="post">
            <div class="form-grid">
                <label>Start Time<input type="time" name="window_start" value="<?= e(substr($settings['window_start'],0,5)) ?>" required></label>
                <label>End Time<input type="time" name="window_end" value="<?= e(substr($settings['window_end'],0,5)) ?>" required></label>
            </div>
            <label>Reminder interval (minutes)<input type="number" min="5" max="240" name="reminder_interval_minutes" value="<?= (int)$settings['reminder_interval_minutes'] ?>" required></label>
            <label>Browser check interval (seconds)<input type="number" min="30" max="300" name="browser_poll_seconds" value="<?= (int)$settings['browser_poll_seconds'] ?>" required><small>The dashboard checks for a due reminder this often.</small></label>
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </form>
        <div class="info-box">
            <strong>How it behaves</strong>
            <p>Only pending brands are eligible. A completed/skipped brand stops reminding for that day. Snoozed brands wait until their snooze time. Due brands are rotated so you do not get the same brand repeatedly.</p>
        </div>
    </section>
</main>
</body>
</html>
