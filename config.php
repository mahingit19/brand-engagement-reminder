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

function ensureTodayTasks(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_social_engagements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        daily_engagement_id BIGINT UNSIGNED NOT NULL,
        brand_id INT UNSIGNED NOT NULL,
        social_link_id INT UNSIGNED NOT NULL,
        engagement_date DATE NOT NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 1,
        done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_dse_daily FOREIGN KEY (daily_engagement_id) REFERENCES daily_engagements(id) ON DELETE CASCADE,
        CONSTRAINT fk_dse_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
        CONSTRAINT fk_dse_social FOREIGN KEY (social_link_id) REFERENCES social_links(id) ON DELETE CASCADE,
        UNIQUE KEY uq_link_day (social_link_id, engagement_date),
        INDEX idx_brand_day (brand_id, engagement_date)
    ) ENGINE=InnoDB;");

    $sql = "INSERT INTO daily_engagements (brand_id, engagement_date, status, created_at)
            SELECT b.id, :today, 'pending', NOW()
            FROM brands b
            WHERE b.status = 1
              AND NOT EXISTS (
                  SELECT 1 FROM daily_engagements d
                  WHERE d.brand_id = b.id AND d.engagement_date = :today2
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['today' => today(), 'today2' => today()]);
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
