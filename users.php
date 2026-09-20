<?php
require __DIR__ . '/config.php';
requireAdmin();
$currentUser = currentUser();
$pdo = db();

$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create User
    if ($action === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'user'], true) ? $_POST['role'] : 'user';

        if ($name === '' || $username === '' || $password === '') {
            $error = 'অনুগ্রহ করে সকল ফিল্ড সঠিকভাবে পূরণ করুন।';
        } elseif (strlen($username) < 3) {
            $error = 'ইউজারনেম কমপক্ষে ৩ অক্ষরের হতে হবে।';
        } elseif (strlen($password) < 4) {
            $error = 'পাসওয়ার্ড কমপক্ষে ৪ অক্ষরের হতে হবে।';
        } else {
            // Check username duplicate
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :uname LIMIT 1");
            $checkStmt->execute(['uname' => $username]);
            if ($checkStmt->fetch()) {
                $error = 'এই ইউজারনেমটি ইতিমধ্যে ব্যবহৃত হয়েছে। অনুগ্রহ করে অন্য ইউজারনেম দিন।';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("
                    INSERT INTO users (name, username, password_hash, role, status, created_at)
                    VALUES (:name, :username, :hash, :role, 1, NOW())
                ");
                $ins->execute([
                    'name' => $name,
                    'username' => $username,
                    'hash' => $hash,
                    'role' => $role
                ]);
                $newId = (int)$pdo->lastInsertId();

                // 1. Initialize today's tasks for the newly created user
                ensureTodayTasks($pdo, $newId);

                // 2. Pre-mark all existing posts as seen/engaged so historical posts are never shown as new
                $pdo->prepare("
                    INSERT INTO user_post_engagements (user_id, post_id, is_notified, is_engaged, engaged_at)
                    SELECT :new_uid, id, 1, 1, NOW()
                    FROM brand_posts
                ")->execute(['new_uid' => $newId]);

                logUserActivity($pdo, (int)$currentUser['id'], 'create_user', null, null, null, "Created user '{$username}' ({$name}) as {$role}");
                header('Location: users.php?msg=created');
                exit;
            }
        }
    }

    // Update User
    if ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'user'], true) ? $_POST['role'] : 'user';
        $status = isset($_POST['status']) && $_POST['status'] == '1' ? 1 : 0;
        $password = trim($_POST['password'] ?? '');

        if ($userId <= 0 || $name === '') {
            $error = 'ব্যবহারকারীর নাম ও তথ্য প্রদান করুন।';
        } else {
            // If editing self, prevent changing role or deactivating
            if ($userId === (int)$currentUser['id']) {
                $role = 'admin';
                $status = 1;
            }

            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("
                    UPDATE users 
                    SET name = :name, role = :role, status = :status, password_hash = :hash 
                    WHERE id = :id
                ");
                $upd->execute(['name' => $name, 'role' => $role, 'status' => $status, 'hash' => $hash, 'id' => $userId]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE users 
                    SET name = :name, role = :role, status = :status 
                    WHERE id = :id
                ");
                $upd->execute(['name' => $name, 'role' => $role, 'status' => $status, 'id' => $userId]);
            }

            logUserActivity($pdo, (int)$currentUser['id'], 'update_user', null, null, null, "Updated user ID #{$userId} ({$name})");
            header('Location: users.php?msg=updated');
            exit;
        }
    }

    // Toggle Status
    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$currentUser['id']) {
            $error = 'আপনি নিজের অ্যাকাউন্ট নিষ্ক্রিয় করতে পারবেন না!';
        } elseif ($userId > 0) {
            $upd = $pdo->prepare("UPDATE users SET status = IF(status=1, 0, 1) WHERE id = :id");
            $upd->execute(['id' => $userId]);
            logUserActivity($pdo, (int)$currentUser['id'], 'toggle_user_status', null, null, null, "Toggled status for user ID #{$userId}");
            header('Location: users.php?msg=status_changed');
            exit;
        }
    }

    // Delete User
    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$currentUser['id']) {
            $error = 'আপনি নিজের অ্যাকাউন্ট ডিলিট করতে পারবেন না!';
        } elseif ($userId > 0) {
            $del = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $del->execute(['id' => $userId]);
            logUserActivity($pdo, (int)$currentUser['id'], 'delete_user', null, null, null, "Deleted user ID #{$userId}");
            header('Location: users.php?msg=deleted');
            exit;
        }
    }
}

// Fetch edit user if requested
$editUser = null;
if (!empty($_GET['edit'])) {
    $eStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $eStmt->execute(['id' => (int)$_GET['edit']]);
    $editUser = $eStmt->fetch();
}

// Fetch all users
$users = $pdo->query("SELECT * FROM users ORDER BY role ASC, name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management · Brand Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div>
        <h1>User Management</h1>
        <p>Manage system users, roles (Admin vs User), and access permissions.</p>
    </div>
    <div class="topbar-right">
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
                <a href="brands.php">Brands</a>
                <a href="settings.php">Settings</a>
                <a href="users.php" class="active">Users</a>
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

<main class="container brands-layout">
    <!-- User Form Panel (Add / Edit) -->
    <section class="panel sticky-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h2 style="margin:0;"><?= $editUser ? 'Edit User' : 'Add New User' ?></h2>
            <?php if ($editUser): ?>
                <a class="btn btn-light btn-sm" href="users.php">+ Add New</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert success">
                <?php
                switch ($_GET['msg']) {
                    case 'created': echo 'নতুন ব্যবহারকারী সফলভাবে যুক্ত হয়েছে।'; break;
                    case 'updated': echo 'ব্যবহারকারীর তথ্য সফলভাবে আপডেট হয়েছে।'; break;
                    case 'status_changed': echo 'ব্যবহারকারীর স্ট্যাটাস পরিবর্তন করা হয়েছে।'; break;
                    case 'deleted': echo 'ব্যবহারকারী সফলভাবে ডিলিট করা হয়েছে।'; break;
                    default: echo 'অ্যাকশন সফল হয়েছে।';
                }
                ?>
            </div>
        <?php endif; ?>

        <form method="post" action="users.php">
            <?php if ($editUser): ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="create_user">
            <?php endif; ?>

            <label>
                নাম (Full Name)
                <input type="text" name="name" required placeholder="e.g. Rahim Ahmed" value="<?= e($editUser['name'] ?? '') ?>">
            </label>

            <label>
                ইউজারনেম (Username)
                <input type="text" name="username" <?= $editUser ? 'readonly style="background:#f8fafc;"' : 'required' ?> placeholder="e.g. rahim" value="<?= e($editUser['username'] ?? '') ?>">
                <?php if ($editUser): ?><small>ইউজারনেম পরিবর্তনযোগ্য নয়।</small><?php endif; ?>
            </label>

            <label>
                <?= $editUser ? 'নতুন পাসওয়ার্ড (New Password - খালি রাখলে আগেরটি থাকবে)' : 'পাসওয়ার্ড (Password)' ?>
                <input type="password" name="password" <?= $editUser ? '' : 'required' ?> placeholder="••••••••">
            </label>

            <div class="form-grid">
                <label>
                    রোল (Role / Permission)
                    <select name="role" required <?= ($editUser && (int)$editUser['id'] === (int)$currentUser['id']) ? 'disabled' : '' ?>>
                        <option value="user" <?= ($editUser['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User (Reminder &amp; Reports only)</option>
                        <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin (Full System Access)</option>
                    </select>
                    <?php if ($editUser && (int)$editUser['id'] === (int)$currentUser['id']): ?>
                        <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                </label>

                <?php if ($editUser): ?>
                    <label>
                        স্ট্যাটাস (Status)
                        <select name="status" <?= ((int)$editUser['id'] === (int)$currentUser['id']) ? 'disabled' : '' ?>>
                            <option value="1" <?= $editUser['status'] ? 'selected' : '' ?>>Active (সক্রিয়)</option>
                            <option value="0" <?= !$editUser['status'] ? 'selected' : '' ?>>Inactive (নিষ্ক্রিয়)</option>
                        </select>
                        <?php if ((int)$editUser['id'] === (int)$currentUser['id']): ?>
                            <input type="hidden" name="status" value="1">
                        <?php endif; ?>
                    </label>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $editUser ? 'Update User' : 'Create User' ?>
                </button>
                <?php if ($editUser): ?>
                    <a class="btn btn-light" href="users.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="info-box" style="margin-top:20px;">
            <strong>রোল ও পারমিশন নির্দেশিকা:</strong>
            <p style="margin:4px 0;">• <strong>User:</strong> শুধুমাত্র রিমাইন্ডার ড্যাশবোর্ড (Dashboard) এবং পারফরম্যান্স রিপোর্ট (Reports) পাবেন। সেটিংস বা ব্র্যান্ড কনফিগারেশনের কোনো অ্যাক্সেস থাকবে না।</p>
            <p style="margin:4px 0;">• <strong>Admin:</strong> ড্যাশবোর্ড, রিপোর্টস, ব্র্যান্ড ম্যানেজমেন্ট, সিস্টেম সেটিংস এবং ইউজার ম্যানেজমেন্টের সম্পূর্ণ নিয়ন্ত্রণ পাবেন।</p>
        </div>
    </section>

    <!-- Users List Panel -->
    <section class="panel">
        <div class="panel-header-row">
            <div>
                <h2 style="margin:0;">System Users <span class="counter-badge"><?= count($users) ?></span></h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">তালিকায় থাকা ব্যবহারকারী ও তাদের পারমিশন লেভেল</p>
            </div>
        </div>

        <div class="table-responsive" style="overflow-x:auto;margin-top:14px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                    ?>
                        <tr>
                            <td>
                                <strong><?= e($u['name']) ?></strong>
                                <?php if ($isSelf): ?>
                                    <span style="font-size:11px;background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;margin-left:4px;">You</span>
                                <?php endif; ?>
                                <div class="muted" style="font-size:12px;">@<?= e($u['username']) ?></div>
                            </td>
                            <td>
                                <span class="role-badge role-<?= e($u['role']) ?>">
                                    <?= e(strtoupper($u['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $u['status'] ? 'active' : 'inactive' ?>">
                                    <?= $u['status'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="font-size:13px;color:var(--muted);">
                                <?= $u['last_login_at'] ? e(date('d M Y, h:i A', strtotime($u['last_login_at']))) : 'Never' ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="inline-actions" style="justify-content:flex-end;">
                                    <a class="btn btn-light btn-sm" href="users.php?edit=<?= (int)$u['id'] ?>">Edit</a>
                                    
                                    <?php if (!$isSelf): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Change user active status?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">
                                                <?= $u['status'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>

                                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" style="color:var(--danger);" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

