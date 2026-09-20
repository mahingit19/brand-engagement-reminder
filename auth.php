<?php
// Authentication & User Activity Tracking Module

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

function currentUser(): ?array
{
    if (!empty($_SESSION['auth_user'])) {
        return $_SESSION['auth_user'];
    }
    return null;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function isAdmin(): bool
{
    $user = currentUser();
    return $user && ($user['role'] ?? '') === 'admin';
}

function getClientIp(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function isAjaxOrApiRequest(): bool
{
    $isJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', 'api_') !== false;
    return $isJson || $isAjax || $isApi;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        if (isAjaxOrApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Unauthorized. Please log in.']);
            exit;
        }

        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header("Location: login.php?redirect={$redirect}");
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        if (isAjaxOrApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Forbidden. Admin access required.']);
            exit;
        }

        header('Location: index.php?error=forbidden');
        exit;
    }
}

function attemptLogin(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'message' => 'Please enter username and password.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :uname LIMIT 1");
    $stmt->execute(['uname' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    if ((int)$user['status'] !== 1) {
        return ['success' => false, 'message' => 'Your account has been deactivated. Please contact an administrator.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    // Update last login
    $updateStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
    $updateStmt->execute(['id' => $user['id']]);

    // Store in session (exclude password hash)
    unset($user['password_hash']);
    $_SESSION['auth_user'] = $user;

    // Log login activity
    logUserActivity($pdo, (int)$user['id'], 'login', null, null, null, 'User logged into system');

    return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
}

function logoutUser(PDO $pdo): void
{
    $user = currentUser();
    if ($user) {
        logUserActivity($pdo, (int)$user['id'], 'logout', null, null, null, 'User logged out');
    }
    unset($_SESSION['auth_user']);
    session_destroy();
}

function logUserActivity(
    PDO $pdo,
    int $userId,
    string $actionType,
    ?int $brandId = null,
    ?int $socialLinkId = null,
    ?int $postId = null,
    ?string $details = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs (user_id, brand_id, social_link_id, post_id, action_type, details, ip_address, created_at)
            VALUES (:user_id, :brand_id, :social_link_id, :post_id, :action_type, :details, :ip, NOW())
        ");
        $stmt->execute([
            'user_id' => $userId,
            'brand_id' => $brandId ?: null,
            'social_link_id' => $socialLinkId ?: null,
            'post_id' => $postId ?: null,
            'action_type' => $actionType,
            'details' => $details,
            'ip' => getClientIp()
        ]);
    } catch (\Throwable $e) {
        // Silently capture logging exceptions so core user operations do not break
    }
}

function ensureAuthSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) return;

    // 1. Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            status TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_users_role_status (role, status)
        ) ENGINE=InnoDB;
    ");

    // 2. Ensure last_reminder_at column exists in users
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_reminder_at'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_reminder_at DATETIME NULL AFTER last_login_at");
        }
    } catch (\Throwable $e) {}

    // 3. Create user_post_engagements table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_post_engagements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            is_notified TINYINT(1) NOT NULL DEFAULT 0,
            is_engaged TINYINT(1) NOT NULL DEFAULT 0,
            engaged_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_post (user_id, post_id),
            INDEX idx_user_post_status (user_id, is_engaged)
        ) ENGINE=InnoDB;
    ");

    // 4. Create user_activity_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            brand_id INT UNSIGNED NULL,
            social_link_id INT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(50) NOT NULL,
            details VARCHAR(500) NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_act_user_date (user_id, created_at),
            INDEX idx_act_created (created_at),
            INDEX idx_act_brand (brand_id),
            INDEX idx_act_action (action_type)
        ) ENGINE=InnoDB;
    ");

    // 5. Update daily_engagements to be user-centric
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM daily_engagements LIKE 'user_id'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE daily_engagements ADD COLUMN user_id INT UNSIGNED NULL AFTER brand_id");
            $pdo->exec("ALTER TABLE daily_engagements ADD INDEX idx_daily_user (user_id)");
        }
        $pdo->exec("UPDATE daily_engagements SET user_id = 1 WHERE user_id IS NULL OR user_id = 0");

        // Drop old single-tenant unique key if present
        $idxOld = $pdo->query("SHOW INDEX FROM daily_engagements WHERE Key_name = 'uq_brand_day'")->fetch();
        if ($idxOld) {
            $pdo->exec("ALTER TABLE daily_engagements DROP INDEX uq_brand_day");
        }

        // Add user-centric unique key if missing
        $idxNew = $pdo->query("SHOW INDEX FROM daily_engagements WHERE Key_name = 'uq_user_brand_day'")->fetch();
        if (!$idxNew) {
            $pdo->exec("ALTER TABLE daily_engagements ADD UNIQUE KEY uq_user_brand_day (user_id, brand_id, engagement_date)");
        }
    } catch (\Throwable $e) {}

    // 6. Update daily_social_engagements to be user-centric
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM daily_social_engagements LIKE 'user_id'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE daily_social_engagements ADD COLUMN user_id INT UNSIGNED NULL AFTER brand_id");
            $pdo->exec("ALTER TABLE daily_social_engagements ADD INDEX idx_dse_user (user_id)");
        }
        $pdo->exec("UPDATE daily_social_engagements SET user_id = 1 WHERE user_id IS NULL OR user_id = 0");

        // Ensure standalone index for social_link_id exists so FK constraint is satisfied
        $idxSocial = $pdo->query("SHOW INDEX FROM daily_social_engagements WHERE Key_name = 'idx_dse_social'")->fetch();
        if (!$idxSocial) {
            $pdo->exec("ALTER TABLE daily_social_engagements ADD INDEX idx_dse_social (social_link_id)");
        }

        // Drop old single-tenant unique key if present
        $idxOld = $pdo->query("SHOW INDEX FROM daily_social_engagements WHERE Key_name = 'uq_link_day'")->fetch();
        if ($idxOld) {
            $pdo->exec("ALTER TABLE daily_social_engagements DROP INDEX uq_link_day");
        }

        // Add user-centric unique key if missing
        $idxNew = $pdo->query("SHOW INDEX FROM daily_social_engagements WHERE Key_name = 'uq_user_link_day'")->fetch();
        if (!$idxNew) {
            $pdo->exec("ALTER TABLE daily_social_engagements ADD UNIQUE KEY uq_user_link_day (user_id, social_link_id, engagement_date)");
        }
    } catch (\Throwable $e) {}

    // 7. Seed default admin if no users exist
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0) {
        $defaultAdminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $seedStmt = $pdo->prepare("
            INSERT INTO users (name, username, password_hash, role, status)
            VALUES ('Administrator', 'admin', :pass, 'admin', 1)
        ");
        $seedStmt->execute(['pass' => $defaultAdminPass]);
    }

    $initialized = true;
}

