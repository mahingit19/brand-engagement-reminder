<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fetch_posts.php';

$pdo = db();

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

function getLatestUnseenPosts(PDO $pdo, int $limit = 50, ?int $userId = null): array
{
    if ($userId === null || $userId <= 0) {
        $u = currentUser();
        $userId = $u ? (int)$u['id'] : 1;
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.brand_id, p.social_link_id, b.name AS brand_name,
               COALESCE(s.platform, 'Social') AS platform,
               p.title, p.post_url, p.content_snippet, p.published_at, p.created_at
        FROM brand_posts p
        INNER JOIN brands b ON b.id = p.brand_id AND b.status = 1
        INNER JOIN users u ON u.id = :user_id
        LEFT JOIN social_links s ON s.id = p.social_link_id
        LEFT JOIN user_post_engagements upe ON upe.post_id = p.id AND upe.user_id = :user_id2
        WHERE (upe.id IS NULL OR upe.is_engaged = 0)
          AND p.created_at >= u.created_at
          AND (p.published_at IS NULL OR p.published_at >= u.created_at)
        ORDER BY p.published_at DESC, p.id DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();

    foreach ($posts as &$post) {
        $post['id'] = (int)$post['id'];
        $post['brand_id'] = (int)$post['brand_id'];
        $post['social_link_id'] = (int)($post['social_link_id'] ?? 0);
        $post['relative_time'] = formatRelativeTime($post['published_at']);
        $post['snippet'] = !empty($post['content_snippet']) ? mb_substr(strip_tags($post['content_snippet']), 0, 120) . '...' : '';
    }
    unset($post);

    return $posts;
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    requireLogin();
    header('Content-Type: application/json; charset=utf-8');

    $currentUser = currentUser();
    $userId = (int)($currentUser['id'] ?? 0);
    $action = $_REQUEST['action'] ?? 'get';

    if ($action === 'mark_seen') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $ins = $pdo->prepare("
                INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged, engaged_at)
                VALUES (:uid, :pid, 1, 1, NOW())
                ON DUPLICATE KEY UPDATE is_notified = 1, is_engaged = 1, engaged_at = NOW()
            ");
            $ins->execute(['uid' => $userId, 'pid' => $postId]);

            $pInfo = $pdo->query("SELECT brand_id, social_link_id, title FROM brand_posts WHERE id = {$postId}")->fetch();
            logUserActivity(
                $pdo,
                $userId,
                'post_seen',
                $pInfo ? (int)$pInfo['brand_id'] : null,
                $pInfo ? (int)$pInfo['social_link_id'] : null,
                $postId,
                $pInfo ? "Marked post seen: {$pInfo['title']}" : 'Marked post seen'
            );
        }
        $unseen = getLatestUnseenPosts($pdo, 50, $userId);
        echo json_encode(['ok' => true, 'count' => count($unseen)]);
        exit;
    }

    if ($action === 'mark_all_seen') {
        $unseen = getLatestUnseenPosts($pdo, 200, $userId);
        $ins = $pdo->prepare("
            INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged, engaged_at)
            VALUES (:uid, :pid, 1, 1, NOW())
            ON DUPLICATE KEY UPDATE is_notified = 1, is_engaged = 1, engaged_at = NOW()
        ");
        $cnt = 0;
        foreach ($unseen as $p) {
            $ins->execute(['uid' => $userId, 'pid' => $p['id']]);
            $cnt++;
        }

        logUserActivity($pdo, $userId, 'batch_posts_seen', null, null, null, "Marked all active posts as seen ({$cnt} posts)");
        echo json_encode(['ok' => true, 'updated' => $cnt, 'count' => 0]);
        exit;
    }

    if ($action === 'mark_seen_batch') {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        $postIds = $input['post_ids'] ?? (isset($_POST['post_ids']) ? (array)$_POST['post_ids'] : []);
        if (!empty($postIds)) {
            $cleanIds = array_filter(array_map('intval', (array)$postIds));
            if (!empty($cleanIds)) {
                $ins = $pdo->prepare("
                    INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged, engaged_at)
                    VALUES (:uid, :pid, 1, 1, NOW())
                    ON DUPLICATE KEY UPDATE is_notified = 1, is_engaged = 1, engaged_at = NOW()
                ");
                foreach ($cleanIds as $cid) {
                    $ins->execute(['uid' => $userId, 'pid' => $cid]);
                }

                // Mark daily_engagements for these brands as completed for THIS user today
                $inClause = implode(',', $cleanIds);
                $bStmt = $pdo->query("SELECT DISTINCT brand_id FROM brand_posts WHERE id IN ($inClause)");
                $bIds = $bStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($bIds)) {
                    $inBrands = implode(',', array_map('intval', $bIds));
                    $pdo->exec("UPDATE daily_engagements SET like_done = 1, comment_done = 1, share_done = 1, status = 'completed', completed_at = NOW() WHERE user_id = {$userId} AND brand_id IN ($inBrands) AND engagement_date = '" . today() . "'");
                }

                logUserActivity($pdo, $userId, 'batch_posts_seen', null, null, null, "Opened batch posts (" . count($cleanIds) . " posts)");
            }
        }
        $unseen = getLatestUnseenPosts($pdo, 50, $userId);
        echo json_encode(['ok' => true, 'count' => count($unseen)]);
        exit;
    }

    if ($action === 'refresh') {
        $scanRes = [
            'checked_feeds' => 0,
            'new_posts' => 0,
        ];
        try {
            $scanRes = fetchBrandPosts($pdo, null, true);
        } catch (\Throwable $e) {
            // Continue even if a feed error occurred
        }
        $unseen = getLatestUnseenPosts($pdo, 50, $userId);
        echo json_encode([
            'ok' => true,
            'new_posts' => $scanRes['new_posts'] ?? 0,
            'checked_feeds' => $scanRes['checked_feeds'] ?? 0,
            'count' => count($unseen),
            'posts' => $unseen
        ]);
        exit;
    }

    // Default: get latest unseen posts for this user
    $unseen = getLatestUnseenPosts($pdo, 50, $userId);
    echo json_encode([
        'ok' => true,
        'count' => count($unseen),
        'posts' => $unseen
    ]);
    exit;
}
