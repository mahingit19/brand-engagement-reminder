<?php
require __DIR__ . '/config.php';
requireLogin();
$currentUser = currentUser();
$pdo = db();

// Filter inputs
$datePreset = $_GET['preset'] ?? 'today';
$customFrom = $_GET['from'] ?? '';
$customTo = $_GET['to'] ?? '';

$todayDate = today();
$fromDate = $todayDate;
$toDate = $todayDate;

if ($datePreset === 'yesterday') {
    $fromDate = date('Y-m-d', strtotime('-1 day'));
    $toDate = $fromDate;
} elseif ($datePreset === '7days') {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
    $toDate = $todayDate;
} elseif ($datePreset === 'month') {
    $fromDate = date('Y-m-01');
    $toDate = $todayDate;
} elseif ($datePreset === 'custom' && !empty($customFrom) && !empty($customTo)) {
    $fromDate = date('Y-m-d', strtotime($customFrom));
    $toDate = date('Y-m-d', strtotime($customTo));
} else {
    $datePreset = 'today';
    $fromDate = $todayDate;
    $toDate = $todayDate;
}

// User filter
$selectedUserId = 0;
if (isAdmin()) {
    $selectedUserId = (int)($_GET['user_id'] ?? 0);
} else {
    // Non-admin can only view their own logs
    $selectedUserId = (int)$currentUser['id'];
}

$selectedBrandId = (int)($_GET['brand_id'] ?? 0);
$selectedAction = trim($_GET['action_type'] ?? '');

// Build query conditions for logs
$where = ["DATE(l.created_at) BETWEEN :from_date AND :to_date"];
$params = [
    'from_date' => $fromDate,
    'to_date' => $toDate
];

if ($selectedUserId > 0) {
    $where[] = "l.user_id = :user_id";
    $params['user_id'] = $selectedUserId;
}

if ($selectedBrandId > 0) {
    $where[] = "l.brand_id = :brand_id";
    $params['brand_id'] = $selectedBrandId;
}

if ($selectedAction !== '' && $selectedAction !== 'all') {
    $where[] = "l.action_type = :action_type";
    $params['action_type'] = $selectedAction;
}

$whereSql = implode(' AND ', $where);

// Handle CSV Export
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT l.created_at, u.name AS user_name, u.username, b.name AS brand_name,
               l.action_type, l.details, l.ip_address
        FROM user_activity_logs l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN brands b ON b.id = l.brand_id
        WHERE {$whereSql}
        ORDER BY l.created_at DESC
    ");
    $exportStmt->execute($params);
    $rows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="engagement_report_' . $fromDate . '_to_' . $toDate . '.csv"');

    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Date & Time', 'User Name', 'Username', 'Brand', 'Action Type', 'Details', 'IP Address']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['created_at'],
            $row['user_name'] ?? 'System/Deleted',
            $row['username'] ?? '',
            $row['brand_name'] ?? 'N/A',
            $row['action_type'],
            $row['details'] ?? '',
            $row['ip_address'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// KPI Statistics
$kpiParams = ['from_date' => $fromDate, 'to_date' => $toDate];
$userKpiWhere = "";
if ($selectedUserId > 0) {
    $userKpiWhere = " AND l.user_id = :user_id ";
    $kpiParams['user_id'] = $selectedUserId;
}

$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_actions,
        SUM(CASE WHEN l.action_type IN ('link_done', 'all_done') THEN 1 ELSE 0 END) AS links_engaged,
        SUM(CASE WHEN l.action_type = 'all_done' THEN 1 ELSE 0 END) AS brands_completed,
        SUM(CASE WHEN l.action_type IN ('post_seen', 'batch_posts_seen') THEN 1 ELSE 0 END) AS posts_seen
    FROM user_activity_logs l
    WHERE DATE(l.created_at) BETWEEN :from_date AND :to_date {$userKpiWhere}
");
$kpiStmt->execute($kpiParams);
$kpi = $kpiStmt->fetch() ?: ['total_actions' => 0, 'links_engaged' => 0, 'brands_completed' => 0, 'posts_seen' => 0];

// User Leaderboard (for Admin)
$userSummary = [];
if (isAdmin()) {
    $userSummaryStmt = $pdo->prepare("
        SELECT u.id, u.name, u.username, u.role,
               COUNT(l.id) AS total_actions,
               SUM(CASE WHEN l.action_type IN ('link_done', 'all_done') THEN 1 ELSE 0 END) AS links_engaged,
               SUM(CASE WHEN l.action_type = 'all_done' THEN 1 ELSE 0 END) AS brands_completed,
               SUM(CASE WHEN l.action_type IN ('post_seen', 'batch_posts_seen') THEN 1 ELSE 0 END) AS posts_seen,
               MAX(l.created_at) AS last_action_at
        FROM users u
        LEFT JOIN user_activity_logs l 
            ON l.user_id = u.id AND DATE(l.created_at) BETWEEN :from_date AND :to_date
        GROUP BY u.id
        ORDER BY total_actions DESC, u.name ASC
    ");
    $userSummaryStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
    $userSummary = $userSummaryStmt->fetchAll();
}

// Detailed logs query (limit 150)
$logsStmt = $pdo->prepare("
    SELECT l.*, u.name AS user_name, u.username, b.name AS brand_name
    FROM user_activity_logs l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN brands b ON b.id = l.brand_id
    WHERE {$whereSql}
    ORDER BY l.created_at DESC
    LIMIT 150
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

// Fetch filter options
$allUsers = $pdo->query("SELECT id, name, username, role FROM users ORDER BY name ASC")->fetchAll();
$allBrands = $pdo->query("SELECT id, name FROM brands ORDER BY name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Engagement Reports · Brand Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div>
        <h1>Engagement Reports &amp; Tracking</h1>
        <p>ইউজারদের সোশ্যাল এনগেজমেন্ট, অ্যাক্টিভিটি ট্র্যাকিং ও পারফরম্যান্স অ্যানালিটিক্স।</p>
    </div>
    <div class="topbar-right">
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="reports.php" class="active">Reports</a>
            <?php if (isAdmin()): ?>
                <a href="brands.php">Brands</a>
                <a href="settings.php">Settings</a>
                <a href="users.php">Users</a>
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

<main class="container">
    <!-- Filter Panel -->
    <section class="panel filter-panel" style="margin-bottom:22px;">
        <form method="get" action="reports.php" class="reports-filter-form">
            <div class="filter-row">
                <!-- Preset buttons -->
                <div class="filter-group">
                    <label style="margin:0 0 6px;">সময়কাল (Date Period)</label>
                    <div class="btn-group-segmented">
                        <a href="reports.php?preset=today<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?>" class="btn-segment <?= $datePreset === 'today' ? 'active' : '' ?>">আজ (Today)</a>
                        <a href="reports.php?preset=yesterday<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?>" class="btn-segment <?= $datePreset === 'yesterday' ? 'active' : '' ?>">গতকাল</a>
                        <a href="reports.php?preset=7days<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?>" class="btn-segment <?= $datePreset === '7days' ? 'active' : '' ?>">গত ৭ দিন</a>
                        <a href="reports.php?preset=month<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?>" class="btn-segment <?= $datePreset === 'month' ? 'active' : '' ?>">এই মাস</a>
                    </div>
                </div>

                <!-- Custom Range -->
                <div class="filter-group date-inputs">
                    <label style="margin:0 0 6px;">কাস্টম রেঞ্জ</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="preset" value="custom">
                        <input type="date" name="from" value="<?= e($fromDate) ?>" style="margin:0;padding:8px 10px;font-size:13px;">
                        <span class="muted">থেকে</span>
                        <input type="date" name="to" value="<?= e($toDate) ?>" style="margin:0;padding:8px 10px;font-size:13px;">
                    </div>
                </div>

                <?php if (isAdmin()): ?>
                    <div class="filter-group">
                        <label style="margin:0 0 6px;">ইউজার (User)</label>
                        <select name="user_id" style="margin:0;padding:8px 12px;font-size:13px;">
                            <option value="0">সকল ইউজার (All Users)</option>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $selectedUserId === (int)$u['id'] ? 'selected' : '' ?>>
                                    <?= e($u['name']) ?> (<?= e($u['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="filter-group">
                    <label style="margin:0 0 6px;">ব্র্যান্ড (Brand)</label>
                    <select name="brand_id" style="margin:0;padding:8px 12px;font-size:13px;">
                        <option value="0">সকল ব্র্যান্ড (All Brands)</option>
                        <?php foreach ($allBrands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $selectedBrandId === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= e($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:9px 16px;">🔍 ফিল্টার করুন</button>
                    <?php
                        $exportUrl = 'reports.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']));
                    ?>
                    <a href="<?= e($exportUrl) ?>" class="btn btn-light btn-sm" style="padding:9px 14px;" title="Export filtered logs to CSV file">
                        📥 Export CSV
                    </a>
                </div>
            </div>
        </form>
    </section>

    <!-- KPI Summary Grid -->
    <section class="stats-grid" style="margin-bottom:24px;">
        <div class="stat">
            <span>মোট অ্যাক্টিভিটি (Total Actions)</span>
            <strong><?= (int)$kpi['total_actions'] ?></strong>
            <small class="muted" style="font-size:12px;"><?= e(date('d M', strtotime($fromDate))) ?> – <?= e(date('d M Y', strtotime($toDate))) ?></small>
        </div>
        <div class="stat">
            <span>লিঙ্ক এনগেজড (Links Engaged)</span>
            <strong style="color:var(--primary);"><?= (int)$kpi['links_engaged'] ?></strong>
            <small class="muted" style="font-size:12px;">সোশ্যাল লিঙ্ক ভিজিট / সম্পন্ন</small>
        </div>
        <div class="stat">
            <span>ব্র্যান্ড সম্পন্ন (Brands Completed)</span>
            <strong style="color:var(--success);"><?= (int)$kpi['brands_completed'] ?></strong>
            <small class="muted" style="font-size:12px;">অল-ডান করা ব্র্যান্ডসমূহ</small>
        </div>
        <div class="stat">
            <span>নতুন পোস্ট দেখা হয়েছে (Posts Seen)</span>
            <strong style="color:#d97706;"><?= (int)$kpi['posts_seen'] ?></strong>
            <small class="muted" style="font-size:12px;">অদেখা পোস্ট দেখে Seen মার্ক করা</small>
        </div>
    </section>

    <?php if (isAdmin() && !empty($userSummary) && $selectedUserId === 0): ?>
        <!-- Team Performance Leaderboard -->
        <section class="panel" style="margin-bottom:24px;">
            <div class="panel-header-row">
                <div>
                    <h2 style="margin:0;">👥 User Performance Summary</h2>
                    <p class="muted" style="margin:4px 0 0;font-size:13px;">নির্বাচিত সময়সীমায় প্রত্যেক ইউজারের কাজের সংক্ষিপ্ত হিসাব</p>
                </div>
            </div>
            <div class="table-responsive" style="overflow-x:auto;margin-top:12px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ব্যবহারকারী (User)</th>
                            <th>Role</th>
                            <th style="text-align:center;">মোট অ্যাকশন</th>
                            <th style="text-align:center;">লিঙ্ক এনগেজড</th>
                            <th style="text-align:center;">ব্র্যান্ড সম্পন্ন</th>
                            <th style="text-align:center;">পোস্ট দেখা</th>
                            <th>সর্বশেষ কাজ</th>
                            <th style="text-align:right;">ডিটেইলস</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userSummary as $us): ?>
                            <tr>
                                <td>
                                    <strong><?= e($us['name']) ?></strong>
                                    <div class="muted" style="font-size:12px;">@<?= e($us['username']) ?></div>
                                </td>
                                <td>
                                    <span class="role-badge role-<?= e($us['role']) ?>"><?= e(strtoupper($us['role'])) ?></span>
                                </td>
                                <td style="text-align:center;font-weight:700;font-size:16px;">
                                    <?= (int)$us['total_actions'] ?>
                                </td>
                                <td style="text-align:center;color:var(--primary);font-weight:600;">
                                    <?= (int)$us['links_engaged'] ?>
                                </td>
                                <td style="text-align:center;color:var(--success);font-weight:600;">
                                    <?= (int)$us['brands_completed'] ?>
                                </td>
                                <td style="text-align:center;color:#d97706;font-weight:600;">
                                    <?= (int)$us['posts_seen'] ?>
                                </td>
                                <td style="font-size:12.5px;color:var(--muted);">
                                    <?= $us['last_action_at'] ? e(date('d M, h:i A', strtotime($us['last_action_at']))) : 'কোনো অ্যাকশন নেই' ?>
                                </td>
                                <td style="text-align:right;">
                                    <a class="btn btn-light btn-sm" href="reports.php?preset=<?= e($datePreset) ?>&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?>&user_id=<?= (int)$us['id'] ?>">
                                        View Logs ➔
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <!-- Detailed Activity Logs -->
    <section class="panel">
        <div class="panel-header-row">
            <div>
                <h2 style="margin:0;">📜 Activity Log History <span class="counter-badge"><?= count($logs) ?></span></h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">
                    <?php if ($selectedUserId > 0): ?>
                        নির্দিষ্ট ইউজারের কার্যক্রম দেখা হচ্ছে
                    <?php else: ?>
                        সকল ইউজারের সাম্প্রতিক অ্যাক্টিভিটি টাইমলাইন (সর্বোচ্চ ১৫০টি)
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state" style="padding:40px 20px;margin-top:14px;">
                <h3>কোনো অ্যাক্টিভিটি রেকর্ড পাওয়া যায়নি</h3>
                <p class="muted">নির্বাচিত সময় ও ফিল্টারে কোনো ট্র্যাকিং লগ সংরক্ষিত হয়নি।</p>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="overflow-x:auto;margin-top:14px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:160px;">Date &amp; Time</th>
                            <?php if (isAdmin()): ?><th>User</th><?php endif; ?>
                            <th>Brand</th>
                            <th>Action Type</th>
                            <th>Details</th>
                            <th style="width:100px;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $act = $log['action_type'];
                            $badgeClass = 'status-pending';
                            $label = $act;

                            switch ($act) {
                                case 'link_done':
                                    $badgeClass = 'status-completed';
                                    $label = '✓ Link Done';
                                    break;
                                case 'all_done':
                                    $badgeClass = 'status-completed';
                                    $label = '🎉 All Done';
                                    break;
                                case 'post_seen':
                                case 'batch_posts_seen':
                                    $badgeClass = 'badge-post-seen';
                                    $label = '📢 Post Seen';
                                    break;
                                case 'like':
                                case 'comment':
                                case 'share':
                                    $badgeClass = 'badge-interaction';
                                    $label = ucfirst($act);
                                    break;
                                case 'snooze':
                                    $badgeClass = 'status-pending';
                                    $label = '⏳ Snoozed';
                                    break;
                                case 'skip':
                                    $badgeClass = 'status-skipped';
                                    $label = '⏭ Skipped';
                                    break;
                                case 'login':
                                    $badgeClass = 'badge-auth';
                                    $label = '🔑 Login';
                                    break;
                                case 'logout':
                                    $badgeClass = 'badge-auth';
                                    $label = '🚪 Logout';
                                    break;
                            }
                        ?>
                            <tr>
                                <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
                                    <strong><?= e(date('d M Y', strtotime($log['created_at']))) ?></strong><br>
                                    <span class="muted"><?= e(date('h:i:s A', strtotime($log['created_at']))) ?></span>
                                </td>
                                <?php if (isAdmin()): ?>
                                    <td>
                                        <strong><?= e($log['user_name'] ?? 'System') ?></strong>
                                        <?php if (!empty($log['username'])): ?>
                                            <div class="muted" style="font-size:11px;">@<?= e($log['username']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php if (!empty($log['brand_name'])): ?>
                                        <strong><?= e($log['brand_name']) ?></strong>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-pill <?= e($badgeClass) ?>">
                                        <?= e($label) ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;line-height:1.4;">
                                    <?= e($log['details'] ?? '—') ?>
                                </td>
                                <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                                    <code><?= e($log['ip_address'] ?? '127.0.0.1') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

