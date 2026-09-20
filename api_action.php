<?php
require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'message'=>'POST required']); exit;
}

$pdo = db();
$currentUser = currentUser();
$userId = (int)($currentUser['id'] ?? 0);

$taskId = (int)($_POST['task_id'] ?? 0);
$postId = (int)($_POST['post_id'] ?? 0);
$type = $_POST['type'] ?? '';
$socialLinkId = (int)($_POST['social_link_id'] ?? $_POST['link_id'] ?? 0);

if ($postId > 0) {
    // Record post engagement in user_post_engagements for THIS user
    $ins = $pdo->prepare("
        INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged, engaged_at)
        VALUES (:uid, :pid, 1, 1, NOW())
        ON DUPLICATE KEY UPDATE is_notified = 1, is_engaged = 1, engaged_at = NOW()
    ");
    $ins->execute(['uid' => $userId, 'pid' => $postId]);
    
    // Get post details for activity log
    $pStmt = $pdo->prepare("SELECT brand_id, social_link_id, title FROM brand_posts WHERE id = :id");
    $pStmt->execute(['id' => $postId]);
    $postInfo = $pStmt->fetch();
    logUserActivity(
        $pdo,
        $userId,
        'post_seen',
        $postInfo ? (int)$postInfo['brand_id'] : null,
        $postInfo ? (int)$postInfo['social_link_id'] : null,
        $postId,
        $postInfo ? "Post seen: {$postInfo['title']}" : 'Post seen'
    );
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
        $tStmt = $pdo->prepare("SELECT id, brand_id, status FROM daily_engagements WHERE id = :id AND user_id = :uid");
        $tStmt->execute(['id' => $taskId, 'uid' => $userId]);
        $taskRow = $tStmt->fetch();
    }
    if (!$taskRow) {
        $slStmt = $pdo->prepare("SELECT brand_id, platform FROM social_links WHERE id = :sid");
        $slStmt->execute(['sid' => $socialLinkId]);
        $slRow = $slStmt->fetch();
        if ($slRow) {
            $slBrandId = (int)$slRow['brand_id'];
            $tStmt = $pdo->prepare("SELECT id, brand_id, status FROM daily_engagements WHERE brand_id = :bid AND user_id = :uid AND engagement_date = :today");
            $tStmt->execute(['bid' => $slBrandId, 'uid' => $userId, 'today' => today()]);
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

    // Platform info for logging
    $pStmt = $pdo->prepare("SELECT platform FROM social_links WHERE id = :sid");
    $pStmt->execute(['sid' => $socialLinkId]);
    $platform = $pStmt->fetchColumn() ?: 'Social Link';

    $newDone = 1;
    if ($type === 'link_toggle') {
        $chk = $pdo->prepare("SELECT is_done FROM daily_social_engagements WHERE social_link_id = :sid AND user_id = :uid AND engagement_date = :today");
        $chk->execute(['sid' => $socialLinkId, 'uid' => $userId, 'today' => today()]);
        $curr = $chk->fetchColumn();
        $newDone = ($curr && (int)$curr === 1) ? 0 : 1;
    }

    $ins = $pdo->prepare("
        INSERT INTO daily_social_engagements (daily_engagement_id, brand_id, user_id, social_link_id, engagement_date, is_done, done_at)
        VALUES (:daily_id, :brand_id, :user_id, :social_id, :today, :is_done, NOW())
        ON DUPLICATE KEY UPDATE is_done = :is_done2, done_at = NOW()
    ");
    $ins->execute([
        'daily_id' => $taskId,
        'brand_id' => $brandId,
        'user_id' => $userId,
        'social_id' => $socialLinkId,
        'today' => today(),
        'is_done' => $newDone,
        'is_done2' => $newDone
    ]);

    // Count total active social links for this brand
    $totStmt = $pdo->prepare("SELECT COUNT(*) FROM social_links WHERE brand_id = :bid AND status = 1");
    $totStmt->execute(['bid' => $brandId]);
    $totalActive = (int)$totStmt->fetchColumn();

    // Count done links today for THIS user
    $doneStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) 
        FROM social_links s
        INNER JOIN daily_social_engagements dse ON dse.social_link_id = s.id AND dse.engagement_date = :today AND dse.user_id = :uid
        WHERE s.brand_id = :bid AND s.status = 1 AND dse.is_done = 1
    ");
    $doneStmt->execute(['bid' => $brandId, 'today' => today(), 'uid' => $userId]);
    $doneCount = (int)$doneStmt->fetchColumn();

    $brandCompleted = false;
    if ($totalActive > 0 && $doneCount >= $totalActive) {
        $pdo->prepare("
            UPDATE daily_engagements 
            SET like_done = 1, comment_done = 1, share_done = 1, status = 'completed', completed_at = NOW(), snoozed_until = NULL 
            WHERE id = :id AND user_id = :uid AND engagement_date = :today
        ")->execute(['id' => $taskId, 'uid' => $userId, 'today' => today()]);
        $brandCompleted = true;
    } else {
        $pdo->prepare("
            UPDATE daily_engagements 
            SET status = 'pending', completed_at = NULL, snoozed_until = NULL 
            WHERE id = :id AND user_id = :uid AND engagement_date = :today
        ")->execute(['id' => $taskId, 'uid' => $userId, 'today' => today()]);
    }

    // Log the user action
    logUserActivity(
        $pdo,
        $userId,
        $newDone ? 'link_done' : 'link_undone',
        $brandId,
        $socialLinkId,
        null,
        $newDone ? "Completed {$platform} link ({$doneCount}/{$totalActive})" : "Unmarked {$platform} link"
    );

    if ($brandCompleted) {
        logUserActivity(
            $pdo,
            $userId,
            'all_done',
            $brandId,
            null,
            null,
            "Completed all social links for brand"
        );
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

if ($taskId <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>'Invalid task']);
    exit;
}

// Get brand info for this task and verify ownership
$tQuery = $pdo->prepare("SELECT brand_id FROM daily_engagements WHERE id = :id AND user_id = :uid");
$tQuery->execute(['id' => $taskId, 'uid' => $userId]);
$taskBrandId = (int)$tQuery->fetchColumn();

if ($taskBrandId <= 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Task not found for this user']);
    exit;
}

if (in_array($type, ['like','comment','share'], true)) {
    $column = $type . '_done';
    $pdo->prepare("UPDATE daily_engagements SET {$column} = IF({$column}=1,0,1), snoozed_until=NULL WHERE id=:id AND user_id=:uid AND engagement_date=:today")
        ->execute(['id'=>$taskId, 'uid'=>$userId, 'today'=>today()]);
    
    logUserActivity($pdo, $userId, $type, $taskBrandId, null, null, "Toggled {$type} action");
} elseif ($type === 'all_done') {
    $pdo->prepare("UPDATE daily_engagements SET like_done=1, comment_done=1, share_done=1, status='completed', completed_at=NOW(), snoozed_until=NULL WHERE id=:id AND user_id=:uid AND engagement_date=:today")
        ->execute(['id'=>$taskId, 'uid'=>$userId, 'today'=>today()]);

    $pdo->prepare("
        INSERT INTO daily_social_engagements (daily_engagement_id, brand_id, user_id, social_link_id, engagement_date, is_done, done_at)
        SELECT :daily_id, s.brand_id, :user_id, s.id, :today, 1, NOW()
        FROM social_links s
        WHERE s.brand_id = (SELECT brand_id FROM daily_engagements WHERE id = :daily_id2 AND user_id = :uid)
          AND s.status = 1
        ON DUPLICATE KEY UPDATE is_done = 1, done_at = NOW()
    ")->execute([
        'daily_id' => $taskId,
        'user_id' => $userId,
        'today' => today(),
        'daily_id2' => $taskId,
        'uid' => $userId
    ]);

    logUserActivity($pdo, $userId, 'all_done', $taskBrandId, null, null, "Marked all done for brand");
} elseif ($type === 'snooze') {
    $minutes = max(5, min(240, (int)($_POST['minutes'] ?? 30)));
    $stmt = $pdo->prepare("UPDATE daily_engagements SET snoozed_until=DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE) WHERE id=:id AND user_id=:uid AND engagement_date=:today AND status='pending'");
    $stmt->execute(['id'=>$taskId, 'uid'=>$userId, 'today'=>today()]);

    logUserActivity($pdo, $userId, 'snooze', $taskBrandId, null, null, "Snoozed brand reminder for {$minutes} min");
} elseif ($type === 'skip') {
    $pdo->prepare("UPDATE daily_engagements SET status='skipped', snoozed_until=NULL WHERE id=:id AND user_id=:uid AND engagement_date=:today")
        ->execute(['id'=>$taskId, 'uid'=>$userId, 'today'=>today()]);

    logUserActivity($pdo, $userId, 'skip', $taskBrandId, null, null, "Skipped brand engagement for today");
} else {
    http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Unknown action']); exit;
}

if (in_array($type, ['like','comment','share'], true)) {
    $stmt = $pdo->prepare("SELECT like_done, comment_done, share_done FROM daily_engagements WHERE id=:id AND user_id=:uid");
    $stmt->execute(['id'=>$taskId, 'uid'=>$userId]);
    $row = $stmt->fetch();
    if ($row && $row['like_done'] && $row['comment_done'] && $row['share_done']) {
        $pdo->prepare("UPDATE daily_engagements SET status='completed', completed_at=NOW(), snoozed_until=NULL WHERE id=:id AND user_id=:uid")->execute(['id'=>$taskId, 'uid'=>$userId]);
        logUserActivity($pdo, $userId, 'all_done', $taskBrandId, null, null, "All actions completed for brand");
    } else {
        $pdo->prepare("UPDATE daily_engagements SET status='pending', completed_at=NULL WHERE id=:id AND user_id=:uid")->execute(['id'=>$taskId, 'uid'=>$userId]);
    }
}

echo json_encode(['ok'=>true]);
