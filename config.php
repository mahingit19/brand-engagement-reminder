<?php
// XAMPP-friendly configuration.
// Update these values if your MySQL username/password/database are different.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'brand_engagement');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_TIMEZONE', 'Asia/Dhaka');

date_default_timezone_set(APP_TIMEZONE);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function today(): string
{
    return date('Y-m-d');
}

function nowSql(): string
{
    return date('Y-m-d H:i:s');
}

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

function ensureTodayTasks(PDO $pdo, ?int $userId = null): void
{
    if ($userId !== null && $userId > 0) {
        $sql = "INSERT INTO daily_engagements (user_id, brand_id, engagement_date, status, created_at)
                SELECT :user_id, b.id, :today, 'pending', NOW()
                FROM brands b
                WHERE b.status = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM daily_engagements d
                      WHERE d.user_id = :user_id2 AND d.brand_id = b.id AND d.engagement_date = :today2
                  )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'today' => today(),
            'user_id2' => $userId,
            'today2' => today()
        ]);
        return;
    }

    $uStmt = $pdo->query("SELECT id FROM users WHERE status = 1");
    $activeUsers = $uStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($activeUsers as $uid) {
        ensureTodayTasks($pdo, (int)$uid);
    }
}


function getSettings(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch();
    if (!$settings) {
        $pdo->exec("INSERT INTO settings (id, window_start, window_end, reminder_interval_minutes, browser_poll_seconds)
                    VALUES (1, '10:00:00', '17:30:00', 45, 60)");
        $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch();
    }
    return $settings;
}

function recordSocialLinkEngagement(PDO $pdo, int $userId, int $socialLinkId, ?int $brandId = null): array
{
    if ($socialLinkId <= 0) {
        return ['ok' => false, 'error' => 'invalid_link_id'];
    }

    if ($brandId === null || $brandId <= 0) {
        $slStmt = $pdo->prepare("SELECT brand_id, platform FROM social_links WHERE id = :sid");
        $slStmt->execute(['sid' => $socialLinkId]);
        $slRow = $slStmt->fetch();
        if (!$slRow) {
            return ['ok' => false, 'error' => 'social_link_not_found'];
        }
        $brandId = (int)$slRow['brand_id'];
    }

    // Ensure daily_engagement exists for this user and brand today
    $tStmt = $pdo->prepare("SELECT id, status FROM daily_engagements WHERE brand_id = :bid AND user_id = :uid AND engagement_date = :today");
    $tStmt->execute(['bid' => $brandId, 'uid' => $userId, 'today' => today()]);
    $taskRow = $tStmt->fetch();
    if (!$taskRow) {
        ensureTodayTasks($pdo, $userId);
        $tStmt->execute(['bid' => $brandId, 'uid' => $userId, 'today' => today()]);
        $taskRow = $tStmt->fetch();
    }
    if (!$taskRow) {
        return ['ok' => false, 'error' => 'task_not_found'];
    }

    $taskId = (int)$taskRow['id'];

    // Insert or update daily_social_engagements
    $ins = $pdo->prepare("
        INSERT INTO daily_social_engagements (daily_engagement_id, brand_id, user_id, social_link_id, engagement_date, is_done, done_at)
        VALUES (:daily_id, :brand_id, :user_id, :social_id, :today, 1, NOW())
        ON DUPLICATE KEY UPDATE is_done = 1, done_at = NOW()
    ");
    $ins->execute([
        'daily_id' => $taskId,
        'brand_id' => $brandId,
        'user_id' => $userId,
        'social_id' => $socialLinkId,
        'today' => today(),
    ]);

    // Count total active social links for this brand
    $totStmt = $pdo->prepare("SELECT COUNT(*) FROM social_links WHERE brand_id = :bid AND status = 1");
    $totStmt->execute(['bid' => $brandId]);
    $totalActive = (int)$totStmt->fetchColumn();

    // Count distinct active links completed today for THIS user
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
        // ALL active social links are completed today!
        $pdo->prepare("
            UPDATE daily_engagements 
            SET like_done = 1, comment_done = 1, share_done = 1, status = 'completed', completed_at = NOW(), snoozed_until = NULL 
            WHERE id = :id AND user_id = :uid AND engagement_date = :today
        ")->execute(['id' => $taskId, 'uid' => $userId, 'today' => today()]);
        $brandCompleted = true;
    } else {
        // Not all links completed yet: keep brand as pending
        $pdo->prepare("
            UPDATE daily_engagements 
            SET status = 'pending', completed_at = NULL, snoozed_until = NULL 
            WHERE id = :id AND user_id = :uid AND engagement_date = :today
        ")->execute(['id' => $taskId, 'uid' => $userId, 'today' => today()]);
    }

    return [
        'ok' => true,
        'brand_id' => $brandId,
        'task_id' => $taskId,
        'social_link_id' => $socialLinkId,
        'done_count' => $doneCount,
        'total_count' => $totalActive,
        'brand_completed' => $brandCompleted
    ];
}

require_once __DIR__ . '/auth.php';
ensureAuthSchema(db());


