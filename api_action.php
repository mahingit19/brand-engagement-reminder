<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'message'=>'POST required']); exit;
}
$pdo = db();
$taskId = (int)($_POST['task_id'] ?? 0);
$postId = (int)($_POST['post_id'] ?? 0);
$type = $_POST['type'] ?? '';

$socialLinkId = (int)($_POST['social_link_id'] ?? $_POST['link_id'] ?? 0);

if ($postId > 0) {
    $pdo->prepare("UPDATE brand_posts SET is_notified=1, is_engaged=1 WHERE id=:id")->execute(['id' => $postId]);
}

if ($taskId <= 0 && $postId > 0 && $socialLinkId <= 0) {
    echo json_encode(['ok'=>true]);
    exit;
}

if ($type === 'link_done' || $type === 'link_toggle') {
    if ($socialLinkId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Invalid social link']);
        exit;
    }

    $taskRow = null;
    if ($taskId > 0) {
        $tStmt = $pdo->prepare("SELECT id, brand_id, status FROM daily_engagements WHERE id = :id");
        $tStmt->execute(['id' => $taskId]);
        $taskRow = $tStmt->fetch();
    }
    if (!$taskRow) {
        $slStmt = $pdo->prepare("SELECT brand_id FROM social_links WHERE id = :sid");
        $slStmt->execute(['sid' => $socialLinkId]);
        $slBrandId = $slStmt->fetchColumn();
        if ($slBrandId) {
            $tStmt = $pdo->prepare("SELECT id, brand_id, status FROM daily_engagements WHERE brand_id = :bid AND engagement_date = :today");
            $tStmt->execute(['bid' => $slBrandId, 'today' => today()]);
            $taskRow = $tStmt->fetch();
        }
    }

    if (!$taskRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Task not found for this social link']);
        exit;
    }

    $brandId = (int)$taskRow['brand_id'];
    $taskId = (int)$taskRow['id'];

    $newDone = 1;
    if ($type === 'link_toggle') {
        $chk = $pdo->prepare("SELECT is_done FROM daily_social_engagements WHERE social_link_id = :sid AND engagement_date = :today");
        $chk->execute(['sid' => $socialLinkId, 'today' => today()]);
        $curr = $chk->fetchColumn();
        $newDone = ($curr && (int)$curr === 1) ? 0 : 1;
    }

    $ins = $pdo->prepare("
        INSERT INTO daily_social_engagements (daily_engagement_id, brand_id, social_link_id, engagement_date, is_done, done_at)
        VALUES (:daily_id, :brand_id, :social_id, :today, :is_done, NOW())
        ON DUPLICATE KEY UPDATE is_done = :is_done2, done_at = NOW()
    ");
    $ins->execute([
        'daily_id' => $taskId,
        'brand_id' => $brandId,
        'social_id' => $socialLinkId,
        'today' => today(),
        'is_done' => $newDone,
        'is_done2' => $newDone
    ]);

    // Count total active social links for this brand
    $totStmt = $pdo->prepare("SELECT COUNT(*) FROM social_links WHERE brand_id = :bid AND status = 1");
    $totStmt->execute(['bid' => $brandId]);
    $totalActive = (int)$totStmt->fetchColumn();

    // Count done links today
    $doneStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) 
        FROM social_links s
        INNER JOIN daily_social_engagements dse ON dse.social_link_id = s.id AND dse.engagement_date = :today
        WHERE s.brand_id = :bid AND s.status = 1 AND dse.is_done = 1
    ");
    $doneStmt->execute(['bid' => $brandId, 'today' => today()]);
    $doneCount = (int)$doneStmt->fetchColumn();

    $brandCompleted = false;
    if ($totalActive > 0 && $doneCount >= $totalActive) {
        $pdo->prepare("
            UPDATE daily_engagements 
            SET like_done = 1, comment_done = 1, share_done = 1, status = 'completed', completed_at = NOW(), snoozed_until = NULL 
            WHERE id = :id AND engagement_date = :today
        ")->execute(['id' => $taskId, 'today' => today()]);
        $brandCompleted = true;
    } else {
        $pdo->prepare("
            UPDATE daily_engagements 
            SET status = 'pending', completed_at = NULL, snoozed_until = NULL 
            WHERE id = :id AND engagement_date = :today
        ")->execute(['id' => $taskId, 'today' => today()]);
    }

    echo json_encode([
        'ok' => true,
        'is_done' => $newDone === 1,
        'brand_completed' => $brandCompleted,
        'done_count' => $doneCount,
        'total_count' => $totalActive,
        'brand_id' => $brandId,
        'social_link_id' => $socialLinkId
    ]);
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

    $pdo->prepare("
        INSERT INTO daily_social_engagements (daily_engagement_id, brand_id, social_link_id, engagement_date, is_done, done_at)
        SELECT :daily_id, s.brand_id, s.id, :today, 1, NOW()
        FROM social_links s
        WHERE s.brand_id = (SELECT brand_id FROM daily_engagements WHERE id = :daily_id2)
          AND s.status = 1
        ON DUPLICATE KEY UPDATE is_done = 1, done_at = NOW()
    ")->execute(['daily_id' => $taskId, 'today' => today(), 'daily_id2' => $taskId]);
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

