<?php
require __DIR__ . '/config.php';
$pdo = db();

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';
// Prevent open redirect
if (preg_match('/^[a-zA-Z0-9_\-\.\/\?\=\&]+$/', $redirect) !== 1 || strpos($redirect, ':') !== false || strpos($redirect, '//') !== false) {
    $redirect = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $res = attemptLogin($pdo, $username, $password);
    if ($res['success']) {
        header("Location: {$redirect}");
        exit;
    } else {
        $error = $res['message'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · Brand Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">🔔</div>
            <h2>Brand Engagement</h2>
            <p>সাইন ইন করে ড্যাশবোর্ডে প্রবেশ করুন</p>
        </div>

        <?php if (!empty($_GET['error']) && $_GET['error'] === 'forbidden'): ?>
            <div class="alert error" style="margin-bottom:16px;">
                ⚠️ আপনার এই পেজে প্রবেশের অনুমতি নেই।
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert error" style="margin-bottom:16px;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['logged_out'])): ?>
            <div class="alert success" style="margin-bottom:16px;">
                ✓ সফলভাবে লগআউট সম্পন্ন হয়েছে।
            </div>
        <?php endif; ?>

        <form method="post" action="login.php?redirect=<?= e($redirect) ?>" class="auth-form">
            <label>
                ইউজারনেম (Username)
                <input type="text" name="username" required autofocus placeholder="e.g. admin" value="<?= e($_POST['username'] ?? '') ?>">
            </label>

            <label>
                পাসওয়ার্ড (Password)
                <input type="password" name="password" required placeholder="••••••••">
            </label>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;width:100%;padding:12px;">
                লগইন করুন (Sign In) ➔
            </button>
        </form>

        <div class="auth-footer-note">
            <strong>ডিফল্ট অ্যাডমিন একাউন্ট:</strong><br>
            Username: <code>admin</code> &nbsp;|&nbsp; Password: <code>admin123</code>
        </div>
    </div>
</body>
</html>

