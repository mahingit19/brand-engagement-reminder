<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'message'=>'POST required']); exit;
}
$pdo = db();
$taskId = (int)($_POST['task_id'] ?? 0);
$postId = (int)($_POST['post_id'] ?? 0);
$type = $_POST['type'] ?? '';

if ($postId > 0) {
    $pdo->prepare("UPDATE brand_posts SET is_engaged=1 WHERE id=:id")->execute(['id' => $postId]);
}

if ($taskId <= 0 && $postId > 0) {
    echo json_encode(['ok'=>true]);
    exit;
}

if ($taskId <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Invalid task']); exit; }

if (in_array($type, ['like','comment','share'], true)) {
    $column = $type . '_done';
    $pdo->prepare("UPDATE daily_engagements SET {$column} = IF({$column}=1,0,1), snoozed_until=NULL WHERE id=:id AND engagement_date=:today")
        ->execute(['id'=>$taskId,'today'=>today()]);
} elseif ($type === 'all_done') {
    $pdo->prepare("UPDATE daily_engagements SET like_done=1, comment_done=1, share_done=1, status='completed', completed_at=NOW(), snoozed_until=NULL WHERE id=:id AND engagement_date=:today")
        ->execute(['id'=>$taskId,'today'=>today()]);
} elseif ($type === 'snooze') {
    $minutes = max(5, min(240, (int)($_POST['minutes'] ?? 30)));
    $stmt = $pdo->prepare("UPDATE daily_engagements SET snoozed_until=DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE) WHERE id=:id AND engagement_date=:today AND status='pending'");
    $stmt->execute(['id'=>$taskId,'today'=>today()]);
} elseif ($type === 'skip') {
    $pdo->prepare("UPDATE daily_engagements SET status='skipped', snoozed_until=NULL WHERE id=:id AND engagement_date=:today")
        ->execute(['id'=>$taskId,'today'=>today()]);
} else {
    http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Unknown action']); exit;
}

if (in_array($type, ['like','comment','share'], true)) {
    $stmt = $pdo->prepare("SELECT like_done, comment_done, share_done FROM daily_engagements WHERE id=:id");
    $stmt->execute(['id'=>$taskId]);
    $row = $stmt->fetch();
    if ($row && $row['like_done'] && $row['comment_done'] && $row['share_done']) {
        $pdo->prepare("UPDATE daily_engagements SET status='completed', completed_at=NOW(), snoozed_until=NULL WHERE id=:id")->execute(['id'=>$taskId]);
    } else {
        $pdo->prepare("UPDATE daily_engagements SET status='pending', completed_at=NULL WHERE id=:id")->execute(['id'=>$taskId]);
    }
}

echo json_encode(['ok'=>true]);
