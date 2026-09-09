<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
ensureTodayTasks($pdo);
$settings = getSettings($pdo);

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
    'due' => $due,
    'last_reminded_name' => $due['name'],
    'last_reminded_time' => date('h:i A'),
    'next_target_ts' => time() + ((int)$settings['reminder_interval_minutes'] * 60)
]);

