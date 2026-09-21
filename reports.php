<?php
require_once __DIR__ . '/config.php';
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
    // Non-admin can only view their own reports
    $selectedUserId = (int)$currentUser['id'];
}

$selectedBrandId = (int)($_GET['brand_id'] ?? 0);
$activeTab = in_array($_GET['tab'] ?? '', ['matrix', 'scorecard', 'timeline'], true) ? $_GET['tab'] : 'matrix';

// --- QUERY 1: EXECUTIVE KPI SUMMARY ---
$kpiParams = ['from_date' => $fromDate, 'to_date' => $toDate];
$kpiUserWhere = "";
if ($selectedUserId > 0) {
    $kpiUserWhere .= " AND d.user_id = :kpi_user ";
    $kpiParams['kpi_user'] = $selectedUserId;
}
if ($selectedBrandId > 0) {
    $kpiUserWhere .= " AND d.brand_id = :kpi_brand ";
    $kpiParams['kpi_brand'] = $selectedBrandId;
}

$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_tasks,
        COALESCE(SUM(d.status = 'completed'), 0) AS completed_tasks,
        COALESCE(SUM(d.status = 'pending'), 0) AS pending_tasks,
        COALESCE(SUM(d.status = 'skipped'), 0) AS skipped_tasks
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id AND b.status = 1
    WHERE d.engagement_date BETWEEN :from_date AND :to_date {$kpiUserWhere}
");
$kpiStmt->execute($kpiParams);
$kpi = $kpiStmt->fetch() ?: [
    'total_tasks' => 0,
    'completed_tasks' => 0,
    'pending_tasks' => 0,
    'skipped_tasks' => 0,
];

// Total social links engaged in this period
$dseParams = ['from_date' => $fromDate, 'to_date' => $toDate];
$dseUserWhere = "";
if ($selectedUserId > 0) {
    $dseUserWhere .= " AND dse.user_id = :dse_user ";
    $dseParams['dse_user'] = $selectedUserId;
}
if ($selectedBrandId > 0) {
    $dseUserWhere .= " AND dse.brand_id = :dse_brand ";
    $dseParams['dse_brand'] = $selectedBrandId;
}

$dseTotalStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM daily_social_engagements dse
    INNER JOIN brands b ON b.id = dse.brand_id AND b.status = 1
    WHERE dse.engagement_date BETWEEN :from_date AND :to_date AND dse.is_done = 1 {$dseUserWhere}
");
$dseTotalStmt->execute($dseParams);
$totalLinksEngaged = (int)$dseTotalStmt->fetchColumn();

// Platform breakdown
$platformStmt = $pdo->prepare("
    SELECT s.platform, COUNT(dse.id) AS done_count
    FROM daily_social_engagements dse
    INNER JOIN social_links s ON s.id = dse.social_link_id
    INNER JOIN brands b ON b.id = dse.brand_id AND b.status = 1
    WHERE dse.engagement_date BETWEEN :from_date AND :to_date AND dse.is_done = 1 {$dseUserWhere}
    GROUP BY s.platform
    ORDER BY done_count DESC
");
$platformStmt->execute($dseParams);
$platformStats = $platformStmt->fetchAll();

// Calculate completion percentage
$totalTasksCount = (int)$kpi['total_tasks'];
$completedTasksCount = (int)$kpi['completed_tasks'];
$completionRate = $totalTasksCount > 0 ? round(($completedTasksCount / $totalTasksCount) * 100, 1) : 0;
$progressClass = $completionRate >= 80 ? 'progress-high' : ($completionRate >= 50 ? 'progress-mid' : 'progress-low');

// --- QUERY 2: BRAND ENGAGEMENT MATRIX ---
$matrixParams = ['from_date' => $fromDate, 'to_date' => $toDate];
$matrixWhere = ["d.engagement_date BETWEEN :from_date AND :to_date"];
if ($selectedUserId > 0) {
    $matrixWhere[] = "d.user_id = :m_user";
    $matrixParams['m_user'] = $selectedUserId;
}
if ($selectedBrandId > 0) {
    $matrixWhere[] = "d.brand_id = :m_brand";
    $matrixParams['m_brand'] = $selectedBrandId;
}
$matrixWhereSql = implode(' AND ', $matrixWhere);

$matrixStmt = $pdo->prepare("
    SELECT d.id AS task_id, d.brand_id, d.user_id, d.engagement_date, d.status,
           d.snoozed_until, d.completed_at,
           b.name AS brand_name, b.latest_post_url, b.notes,
           u.name AS user_name, u.username
    FROM daily_engagements d
    INNER JOIN brands b ON b.id = d.brand_id AND b.status = 1
    INNER JOIN users u ON u.id = d.user_id
    WHERE {$matrixWhereSql}
    ORDER BY d.engagement_date DESC, FIELD(d.status, 'completed', 'pending', 'skipped'), b.name ASC
    LIMIT 300
");
$matrixStmt->execute($matrixParams);
$matrixRows = $matrixStmt->fetchAll();

// Pre-fetch social links for all brands
$allSocialLinksStmt = $pdo->query("SELECT id, brand_id, platform, url FROM social_links WHERE status = 1 ORDER BY id ASC");
$allSocialLinks = [];
foreach ($allSocialLinksStmt->fetchAll() as $sl) {
    $allSocialLinks[$sl['brand_id']][] = $sl;
}

// Pre-fetch user social engagements for the selected date range
$engLinksStmt = $pdo->prepare("
    SELECT user_id, brand_id, social_link_id, engagement_date, is_done, done_at
    FROM daily_social_engagements
    WHERE engagement_date BETWEEN :from_date AND :to_date AND is_done = 1
");
$engLinksStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$userDoneLinksMap = [];
foreach ($engLinksStmt->fetchAll() as $row) {
    $userDoneLinksMap[$row['user_id'] . '_' . $row['engagement_date'] . '_' . $row['social_link_id']] = $row;
}

// --- QUERY 3: TEAM PERFORMANCE SCORECARD ---
$scorecardStmt = $pdo->prepare("
    SELECT u.id, u.name, u.username, u.role,
           COUNT(DISTINCT d.id) AS total_brands,
           COUNT(DISTINCT CASE WHEN d.status = 'completed' THEN d.id END) AS completed_brands,
           COUNT(DISTINCT CASE WHEN d.status = 'pending' THEN d.id END) AS pending_brands,
           COUNT(DISTINCT CASE WHEN d.status = 'skipped' THEN d.id END) AS skipped_brands,
           COUNT(DISTINCT dse.id) AS total_links_done,
           COUNT(DISTINCT upe.id) AS total_posts_seen,
           MIN(l.created_at) AS first_active_at,
           MAX(l.created_at) AS last_active_at
    FROM users u
    LEFT JOIN daily_engagements d 
        ON d.user_id = u.id AND d.engagement_date BETWEEN :from_date AND :to_date
    LEFT JOIN daily_social_engagements dse 
        ON dse.user_id = u.id AND dse.engagement_date BETWEEN :from_date2 AND :to_date2 AND dse.is_done = 1
    LEFT JOIN user_post_engagements upe
        ON upe.user_id = u.id AND DATE(upe.engaged_at) BETWEEN :from_date3 AND :to_date3 AND upe.is_engaged = 1
    LEFT JOIN user_activity_logs l 
        ON l.user_id = u.id AND DATE(l.created_at) BETWEEN :from_date4 AND :to_date4
    WHERE u.status = 1 " . ($selectedUserId > 0 ? " AND u.id = {$selectedUserId} " : "") . "
    GROUP BY u.id
    ORDER BY completed_brands DESC, total_links_done DESC, u.name ASC
");
$scorecardStmt->execute([
    'from_date' => $fromDate, 'to_date' => $toDate,
    'from_date2' => $fromDate, 'to_date2' => $toDate,
    'from_date3' => $fromDate, 'to_date3' => $toDate,
    'from_date4' => $fromDate, 'to_date4' => $toDate
]);
$teamScorecard = $scorecardStmt->fetchAll();

// --- QUERY 4: HUMANIZED ACTIVITY TIMELINE ---
$timelineParams = ['from_date' => $fromDate, 'to_date' => $toDate];
$timelineWhere = ["DATE(l.created_at) BETWEEN :from_date AND :to_date"];
if ($selectedUserId > 0) {
    $timelineWhere[] = "l.user_id = :t_user";
    $timelineParams['t_user'] = $selectedUserId;
}
if ($selectedBrandId > 0) {
    $timelineWhere[] = "l.brand_id = :t_brand";
    $timelineParams['t_brand'] = $selectedBrandId;
}
$timelineWhereSql = implode(' AND ', $timelineWhere);

$timelineStmt = $pdo->prepare("
    SELECT l.*, u.name AS user_name, u.username, b.name AS brand_name, s.platform
    FROM user_activity_logs l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN brands b ON b.id = l.brand_id
    LEFT JOIN social_links s ON s.id = l.social_link_id
    WHERE {$timelineWhereSql}
    ORDER BY l.created_at DESC
    LIMIT 120
");
$timelineStmt->execute($timelineParams);
$timelineRows = $timelineStmt->fetchAll();

// --- CSV EXPORT LOGIC ---
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "brand_engagement_report_{$fromDate}_to_{$toDate}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    if ($activeTab === 'matrix') {
        fputcsv($out, ['Date', 'Brand Name', 'User', 'Status', 'Social Links Progress', 'Completed At']);
        foreach ($matrixRows as $r) {
            $brandLinks = $allSocialLinks[$r['brand_id']] ?? [];
            $doneCount = 0;
            foreach ($brandLinks as $bl) {
                $key = $r['user_id'] . '_' . $r['engagement_date'] . '_' . $bl['id'];
                if (isset($userDoneLinksMap[$key])) $doneCount++;
            }
            fputcsv($out, [
                $r['engagement_date'],
                $r['brand_name'],
                $r['user_name'],
                ucfirst($r['status']),
                "{$doneCount}/" . count($brandLinks) . " links done",
                $r['completed_at'] ?? 'Pending'
            ]);
        }
    } elseif ($activeTab === 'scorecard') {
        fputcsv($out, ['User Name', 'Username', 'Role', 'Assigned Brands', 'Completed Brands', 'Completion Rate %', 'Links Engaged', 'Posts Seen', 'First Active Time', 'Last Active Time']);
        foreach ($teamScorecard as $sc) {
            $tot = (int)$sc['total_brands'];
            $comp = (int)$sc['completed_brands'];
            $rate = $tot > 0 ? round(($comp / $tot) * 100, 1) : 0;
            fputcsv($out, [
                $sc['name'],
                $sc['username'],
                strtoupper($sc['role']),
                $tot,
                $comp,
                "{$rate}%",
                $sc['total_links_done'],
                $sc['total_posts_seen'],
                $sc['first_active_at'] ?? 'N/A',
                $sc['last_active_at'] ?? 'N/A'
            ]);
        }
    } else {
        fputcsv($out, ['Date & Time', 'User', 'Brand', 'Action Story', 'Details']);
        foreach ($timelineRows as $tl) {
            fputcsv($out, [
                $tl['created_at'],
                $tl['user_name'],
                $tl['brand_name'] ?? 'N/A',
                $tl['action_type'],
                $tl['details'] ?? ''
            ]);
        }
    }
    fclose($out);
    exit;
}

// Helpers
$allUsers = $pdo->query("SELECT id, name, username, role FROM users WHERE status = 1 ORDER BY name ASC")->fetchAll();
$allBrands = $pdo->query("SELECT id, name FROM brands WHERE status = 1 ORDER BY name ASC")->fetchAll();

if (!function_exists('humanizeTimeline')) {
    function humanizeTimeline(array $log): array
    {
        $act = $log['action_type'];
        $brand = e($log['brand_name'] ?? '');
        $user = e($log['user_name'] ?? 'ব্যবহারকারী');
        $platform = e($log['platform'] ?? '');

        $iconClass = 'timeline-icon-done';
        $emoji = '✓';
        $title = '';

        switch ($act) {
            case 'all_done':
                $iconClass = 'timeline-icon-done';
                $emoji = '✓✓';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর সমস্ত দৈনিক টাস্ক সম্পন্ন করেছেন।";
                break;
            case 'link_done':
                $iconClass = 'timeline-icon-social';
                $emoji = '🔗';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর <strong>{$platform}</strong> লিঙ্ক ভিজিট করেছেন।";
                break;
            case 'like':
                $iconClass = 'timeline-icon-social';
                $emoji = '👍';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এ লাইক করেছেন।";
                break;
            case 'comment':
                $iconClass = 'timeline-icon-social';
                $emoji = '💬';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এ মন্তব্য করেছেন।";
                break;
            case 'share':
                $iconClass = 'timeline-icon-social';
                $emoji = '🔄';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর কনটেন্ট শেয়ার করেছেন।";
                break;
            case 'snooze':
                $iconClass = 'timeline-icon-snooze';
                $emoji = '⏱';
                $mins = 15;
                if (!empty($log['details'])) {
                    $json = json_decode($log['details'], true);
                    if (isset($json['minutes'])) $mins = (int)$json['minutes'];
                }
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর রিমাইন্ডার {$mins} মিনিটের জন্য স্নুজ করেছেন।";
                break;
            case 'skip':
                $iconClass = 'timeline-icon-skip';
                $emoji = '⏭';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong> আজকের জন্য বাদ (Skip) দিয়েছেন।";
                break;
            case 'post_seen':
                $iconClass = 'timeline-icon-social';
                $emoji = '👀';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর নতুন পোস্ট দেখেছেন।";
                break;
            case 'post_engaged':
                $iconClass = 'timeline-icon-done';
                $emoji = '🚀';
                $title = "<strong>{$user}</strong> ব্র্যান্ড <strong>{$brand}</strong>-এর নতুন আপডেটে এনগেজ হয়েছেন।";
                break;
            case 'login':
                $iconClass = 'timeline-icon-auth';
                $emoji = '🔑';
                $title = "<strong>{$user}</strong> সিস্টেমে সফলভাবে সাইন ইন করেছেন।";
                break;
            case 'logout':
                $iconClass = 'timeline-icon-auth';
                $emoji = '🚪';
                $title = "<strong>{$user}</strong> সিস্টেম থেকে সাইন আউট করেছেন।";
                break;
            default:
                $iconClass = 'timeline-icon-social';
                $emoji = '•';
                $details = e($log['details'] ?? $act);
                $title = "<strong>{$user}</strong> <strong>{$brand}</strong>: {$details}";
                break;
        }

        return [
            'title' => $title,
            'icon_class' => $iconClass,
            'emoji' => $emoji
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brand Engagement Reports</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div>
        <h1>Brand Engagement Reports</h1>
        <p>ব্র্যান্ড এনগেজমেন্ট ম্যাট্রিক্স, টিম পারফরম্যান্স স্কোরকার্ড ও অ্যাক্টিভিটি রিপোর্ট</p>
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
    <section class="panel filter-panel" style="margin-bottom:20px;">
        <form method="get" action="reports.php" class="reports-filter-form">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <div class="filter-row">
                <!-- Preset buttons -->
                <div class="filter-group">
                    <label style="margin:0 0 6px;">সময়কাল (Period)</label>
                    <div class="btn-group-segmented">
                        <a href="reports.php?tab=<?= e($activeTab) ?>&preset=today<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>" class="btn-segment <?= $datePreset === 'today' ? 'active' : '' ?>">আজ (Today)</a>
                        <a href="reports.php?tab=<?= e($activeTab) ?>&preset=yesterday<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>" class="btn-segment <?= $datePreset === 'yesterday' ? 'active' : '' ?>">গতকাল</a>
                        <a href="reports.php?tab=<?= e($activeTab) ?>&preset=7days<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>" class="btn-segment <?= $datePreset === '7days' ? 'active' : '' ?>">গত ৭ দিন</a>
                        <a href="reports.php?tab=<?= e($activeTab) ?>&preset=month<?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>" class="btn-segment <?= $datePreset === 'month' ? 'active' : '' ?>">এই মাস</a>
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
                        <label style="margin:0 0 6px;">টিম মেম্বার (User)</label>
                        <select name="user_id" style="margin:0;padding:8px 12px;font-size:13px;">
                            <option value="0">সকল টিম মেম্বার (All Users)</option>
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
                    <a href="<?= e($exportUrl) ?>" class="btn btn-light btn-sm" style="padding:9px 14px;" title="Export current view to formatted CSV">
                        📥 Export CSV
                    </a>
                </div>
            </div>
        </form>
    </section>

    <!-- Executive KPI Cards -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon kpi-rate">📊</div>
            <div class="kpi-body">
                <div class="kpi-label">কাজের অগ্রগতি</div>
                <div class="kpi-value" style="color:var(--primary);"><?= $completionRate ?>%</div>
                <div class="progress-bar-container" style="margin:6px 0 4px;">
                    <div class="progress-bar-fill <?= $progressClass ?>" style="width: <?= min(100, $completionRate) ?>%;"></div>
                </div>
                <div class="kpi-sub"><?= $completedTasksCount ?> টি ব্র্যান্ড সম্পন্ন, মোট <?= $totalTasksCount ?> টি</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon kpi-links">🔗</div>
            <div class="kpi-body">
                <div class="kpi-label">সোশ্যাল লিঙ্ক ভিজিট</div>
                <div class="kpi-value" style="color:var(--success);"><?= $totalLinksEngaged ?></div>
                <div class="kpi-sub">সোশ্যাল পেজ ওপেন ও এনগেজ করা হয়েছে</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon kpi-done">✅</div>
            <div class="kpi-body">
                <div class="kpi-label">সম্পন্ন ব্র্যান্ড</div>
                <div class="kpi-value" style="color:var(--success);"><?= $completedTasksCount ?> <span style="font-size:16px;color:var(--muted);font-weight:600;">/ <?= $totalTasksCount ?></span></div>
                <div class="kpi-sub">মোট ব্র্যান্ডের মধ্যে সম্পন্ন হয়েছে</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon kpi-pending">⏳</div>
            <div class="kpi-body">
                <div class="kpi-label">অবশিষ্ট / পেন্ডিং</div>
                <div class="kpi-value" style="color:var(--warning);"><?= (int)$kpi['pending_tasks'] ?></div>
                <div class="kpi-sub"><?= (int)$kpi['skipped_tasks'] ?> টি ব্র্যান্ড স্কিপ করা হয়েছে</div>
            </div>
        </div>
    </section>

    <!-- Platform Breakdown Pills -->
    <?php if (!empty($platformStats)): ?>
        <div class="platform-stats-row">
            <span class="muted" style="font-size:13px;font-weight:600;">প্ল্যাটফর্ম ভিত্তিক ভিজিট:</span>
            <?php foreach ($platformStats as $ps): 
                $platKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $ps['platform']));
            ?>
                <div class="platform-stat-badge">
                    <span class="platform-pill platform-<?= e($platKey) ?>"><?= e($ps['platform']) ?></span>
                    <strong><?= (int)$ps['done_count'] ?></strong> টি লিঙ্ক
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- View Switcher Tabs -->
    <div class="report-tabs">
        <a href="reports.php?tab=matrix&preset=<?= e($datePreset) ?>&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?><?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>"
           class="report-tab <?= $activeTab === 'matrix' ? 'active' : '' ?>">
            📋 ব্র্যান্ড ম্যাট্রিক্স <span class="report-tab-badge"><?= count($matrixRows) ?></span>
        </a>

        <?php if (isAdmin()): ?>
            <a href="reports.php?tab=scorecard&preset=<?= e($datePreset) ?>&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?><?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>"
               class="report-tab <?= $activeTab === 'scorecard' ? 'active' : '' ?>">
                👥 টিম স্কোরকার্ড <span class="report-tab-badge"><?= count($teamScorecard) ?></span>
            </a>
        <?php endif; ?>

        <a href="reports.php?tab=timeline&preset=<?= e($datePreset) ?>&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?><?= $selectedUserId ? '&user_id='.$selectedUserId : '' ?><?= $selectedBrandId ? '&brand_id='.$selectedBrandId : '' ?>"
           class="report-tab <?= $activeTab === 'timeline' ? 'active' : '' ?>">
            🕒 অ্যাক্টিভিটি টাইমলাইন <span class="report-tab-badge"><?= count($timelineRows) ?></span>
        </a>
    </div>

    <!-- TAB 1: BRAND ENGAGEMENT MATRIX -->
    <?php if ($activeTab === 'matrix'): ?>
        <section class="panel">
            <div class="panel-header-row">
                <div>
                    <h2 style="margin:0;">📋 ব্র্যান্ড এনগেজমেন্ট ম্যাট্রিক্স</h2>
                    <p class="muted" style="margin:4px 0 0;font-size:13px;">
                        তারিখ ও ব্র্যান্ড অনুযায়ী প্রতিটি সোশ্যাল লিঙ্ক ও এনগেজমেন্টের সার্বিক অগ্রগতি
                    </p>
                </div>
            </div>

            <?php if (empty($matrixRows)): ?>
                <div class="empty-state" style="padding:40px 20px;margin-top:14px;">
                    <h3>📭 কোনো এনগেজমেন্ট ডাটা পাওয়া যায়নি</h3>
                    <p class="muted">নির্বাচিত সময় ও ফিল্টারে কোনো ব্র্যান্ডের কাজ রেকর্ড হয়নি। তারিখ বা ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন।</p>
                </div>
            <?php else: ?>
                <?php
                    // Group rows by date
                    $dateGroups = [];
                    foreach ($matrixRows as $r) {
                        $dateGroups[$r['engagement_date']][] = $r;
                    }
                ?>
                <?php foreach ($dateGroups as $groupDate => $groupRows): ?>
                    <div class="date-group">
                        <div class="date-group-header">
                            <div class="date-group-label">
                                <span class="date-icon">📅</span>
                                <?= e(date('d M Y (l)', strtotime($groupDate))) ?>
                                <span class="date-group-count"><?= count($groupRows) ?> টি ব্র্যান্ড</span>
                            </div>
                        </div>
                        <div class="brand-cards-grid">
                            <?php foreach ($groupRows as $r):
                                $brandLinks = $allSocialLinks[$r['brand_id']] ?? [];
                                $totalLinks = count($brandLinks);
                                $doneCount = 0;
                                foreach ($brandLinks as $bl) {
                                    $k = $r['user_id'] . '_' . $r['engagement_date'] . '_' . $bl['id'];
                                    if (isset($userDoneLinksMap[$k])) $doneCount++;
                                }

                                // Determine card class and status
                                $cardClass = 'card-pending';
                                $statusClass = 'st-pending';
                                $statusLabel = '⏳ পেন্ডিং';
                                if ($r['status'] === 'completed') {
                                    $cardClass = 'card-completed';
                                    $statusClass = 'st-completed';
                                    $statusLabel = '✅ সম্পন্ন';
                                } elseif ($r['status'] === 'skipped') {
                                    $cardClass = 'card-skipped';
                                    $statusClass = 'st-skipped';
                                    $statusLabel = '⏭ স্কিপ';
                                } elseif ($doneCount > 0) {
                                    $cardClass = 'card-inprogress';
                                    $statusClass = 'st-inprogress';
                                    $statusLabel = "🔄 চলমান ({$doneCount}/{$totalLinks})";
                                }
                            ?>
                                <div class="brand-eng-card <?= $cardClass ?>">
                                    <div class="brand-card-top">
                                        <div>
                                            <h3 class="brand-card-name"><?= e($r['brand_name']) ?></h3>
                                            <?php if (!empty($r['notes'])): ?>
                                                <div class="brand-card-notes"><?= e($r['notes']) ?></div>
                                            <?php endif; ?>
                                            <?php if (isAdmin() && $selectedUserId === 0): ?>
                                                <div class="brand-card-user">👤 <strong><?= e($r['user_name']) ?></strong> (@<?= e($r['username']) ?>)</div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="brand-card-status <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    </div>

                                    <?php if ($totalLinks > 0): ?>
                                        <div class="social-checklist">
                                            <div class="social-checklist-title">সোশ্যাল লিঙ্ক (<?= $doneCount ?>/<?= $totalLinks ?> সম্পন্ন)</div>
                                            <?php foreach ($brandLinks as $bl):
                                                $k = $r['user_id'] . '_' . $r['engagement_date'] . '_' . $bl['id'];
                                                $isDone = isset($userDoneLinksMap[$k]);
                                            ?>
                                                <div class="social-check-item <?= $isDone ? 'is-done' : 'is-pending' ?>">
                                                    <span class="check-icon"><?= $isDone ? '✓' : '' ?></span>
                                                    <span class="check-label"><?= e($bl['platform']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="muted" style="font-size:12px;padding:8px 0;">কোনো সোশ্যাল লিঙ্ক সেট করা নেই</div>
                                    <?php endif; ?>

                                    <div class="brand-card-footer">
                                        <span class="brand-card-progress"><?= $doneCount ?>/<?= $totalLinks ?> লিঙ্ক</span>
                                        <?php if ($r['completed_at']): ?>
                                            <span class="brand-card-time is-done">✅ <?= e(date('h:i A', strtotime($r['completed_at']))) ?> এ সম্পন্ন</span>
                                        <?php elseif ($r['snoozed_until']): ?>
                                            <span class="brand-card-time is-snoozed">⏱ <?= e(date('h:i A', strtotime($r['snoozed_until']))) ?> পর্যন্ত স্নুজ</span>
                                        <?php else: ?>
                                            <span class="brand-card-time">এখনো সম্পন্ন হয়নি</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- TAB 2: TEAM PERFORMANCE SCORECARD -->
    <?php if ($activeTab === 'scorecard' && isAdmin()): ?>
        <section class="panel">
            <div class="panel-header-row">
                <div>
                    <h2 style="margin:0;">👥 টিম পারফরম্যান্স স্কোরকার্ড</h2>
                    <p class="muted" style="margin:4px 0 0;font-size:13px;">
                        নির্বাচিত সময়সীমায় প্রত্যেক টিম মেম্বারের পারফরম্যান্স ও কাজের তুলনামূলক চিত্র
                    </p>
                </div>
            </div>

            <?php if (empty($teamScorecard)): ?>
                <div class="empty-state" style="padding:40px 20px;margin-top:14px;">
                    <h3>📭 কোনো টিম মেম্বারের ডাটা পাওয়া যায়নি</h3>
                    <p class="muted">নির্বাচিত সময়ে কোনো টিম মেম্বারের কাজ রেকর্ড হয়নি।</p>
                </div>
            <?php else: ?>
                <div class="scorecard-grid">
                    <?php foreach ($teamScorecard as $sc):
                        $tot = (int)$sc['total_brands'];
                        $comp = (int)$sc['completed_brands'];
                        $pend = (int)$sc['pending_brands'];
                        $skip = (int)$sc['skipped_brands'];
                        $rate = $tot > 0 ? round(($comp / $tot) * 100, 1) : 0;
                        $barClass = $rate >= 80 ? 'progress-high' : ($rate >= 50 ? 'progress-mid' : 'progress-low');
                        $initials = mb_strtoupper(mb_substr($sc['name'], 0, 1, 'UTF-8'), 'UTF-8');
                    ?>
                        <div class="scorecard-card">
                            <div class="scorecard-top">
                                <div class="scorecard-avatar"><?= e($initials) ?></div>
                                <div class="scorecard-info">
                                    <h3 class="scorecard-name"><?= e($sc['name']) ?></h3>
                                    <div class="scorecard-username">
                                        @<?= e($sc['username']) ?>
                                        <span class="role-badge role-<?= e($sc['role']) ?>" style="margin-left:6px;"><?= e(strtoupper($sc['role'])) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="scorecard-stats">
                                <div class="scorecard-stat stat-good">
                                    <div class="scorecard-stat-value"><?= $comp ?></div>
                                    <div class="scorecard-stat-label">সম্পন্ন</div>
                                </div>
                                <div class="scorecard-stat stat-info">
                                    <div class="scorecard-stat-value"><?= (int)$sc['total_links_done'] ?></div>
                                    <div class="scorecard-stat-label">লিঙ্ক ভিজিট</div>
                                </div>
                                <div class="scorecard-stat stat-warn">
                                    <div class="scorecard-stat-value"><?= (int)$sc['total_posts_seen'] ?></div>
                                    <div class="scorecard-stat-label">পোস্ট দেখেছেন</div>
                                </div>
                            </div>

                            <div class="scorecard-progress">
                                <div class="scorecard-progress-header">
                                    <span class="pct"><?= $rate ?>% সম্পন্ন</span>
                                    <span class="fraction"><?= $comp ?>/<?= $tot ?> ব্র্যান্ড</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-fill <?= $barClass ?>" style="width:<?= min(100, $rate) ?>%;"></div>
                                </div>
                                <?php if ($pend > 0 || $skip > 0): ?>
                                    <div style="display:flex;gap:10px;font-size:11px;margin-top:4px;">
                                        <?php if ($pend > 0): ?>
                                            <span style="color:var(--warning);font-weight:600;">⏳ <?= $pend ?> পেন্ডিং</span>
                                        <?php endif; ?>
                                        <?php if ($skip > 0): ?>
                                            <span style="color:var(--muted);font-weight:600;">⏭ <?= $skip ?> স্কিপ</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="scorecard-bottom">
                                <span>
                                    <?php if (!empty($sc['first_active_at'])): ?>
                                        🕒 <?= e(date('h:i A', strtotime($sc['first_active_at']))) ?> – <?= e(date('h:i A', strtotime($sc['last_active_at']))) ?>
                                    <?php else: ?>
                                        <span class="muted">কোনো অ্যাক্টিভিটি নেই</span>
                                    <?php endif; ?>
                                </span>
                                <a class="btn btn-light btn-sm" href="reports.php?tab=matrix&preset=<?= e($datePreset) ?>&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?>&user_id=<?= (int)$sc['id'] ?>">
                                    ম্যাট্রিক্স দেখুন ➔
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- TAB 3: HUMANIZED ACTIVITY TIMELINE -->
    <?php if ($activeTab === 'timeline'): ?>
        <section class="panel">
            <div class="panel-header-row">
                <div>
                    <h2 style="margin:0;">🕒 অ্যাক্টিভিটি টাইমলাইন</h2>
                    <p class="muted" style="margin:4px 0 0;font-size:13px;">
                        ইউজারদের প্রতিটি অ্যাকশনের মানবিকভাবে পাঠযোগ্য টাইমলাইন — তারিখ ও সময় অনুযায়ী
                    </p>
                </div>
            </div>

            <?php if (empty($timelineRows)): ?>
                <div class="empty-state" style="padding:40px 20px;margin-top:14px;">
                    <h3>📭 কোনো অ্যাক্টিভিটি পাওয়া যায়নি</h3>
                    <p class="muted">নির্বাচিত সময় ও ফিল্টারে কোনো অ্যাকশন রেকর্ড হয়নি। তারিখ বা ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন।</p>
                </div>
            <?php else: ?>
                <?php
                    // Group timeline by date, then by time-of-day
                    $tlDateGroups = [];
                    foreach ($timelineRows as $tl) {
                        $dateKey = date('Y-m-d', strtotime($tl['created_at']));
                        $hour = (int)date('G', strtotime($tl['created_at']));
                        if ($hour < 12) {
                            $period = 'সকাল (Morning)';
                            $periodIcon = '🌅';
                        } elseif ($hour < 15) {
                            $period = 'দুপুর (Noon)';
                            $periodIcon = '☀️';
                        } elseif ($hour < 18) {
                            $period = 'বিকেল (Afternoon)';
                            $periodIcon = '🌤️';
                        } else {
                            $period = 'রাত (Evening)';
                            $periodIcon = '🌙';
                        }
                        $tlDateGroups[$dateKey][$periodIcon . ' ' . $period][] = $tl;
                    }
                ?>
                <?php foreach ($tlDateGroups as $tlDate => $periods): ?>
                    <div class="timeline-date-group">
                        <div class="timeline-date-header">
                            <div class="timeline-date-badge">
                                📅 <?= e(date('d M Y (l)', strtotime($tlDate))) ?>
                            </div>
                        </div>

                        <?php foreach ($periods as $periodLabel => $periodEntries): ?>
                            <div class="timeline-time-group">
                                <div class="timeline-time-label"><?= e($periodLabel) ?></div>
                                <div class="timeline-list">
                                    <?php foreach ($periodEntries as $tl):
                                        $story = humanizeTimeline($tl);
                                        $createdTs = strtotime($tl['created_at']);
                                        $relativeTime = formatRelativeTime($tl['created_at']);
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-icon-box <?= e($story['icon_class']) ?>">
                                                <?= $story['emoji'] ?>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">
                                                    <?= $story['title'] ?>
                                                </div>
                                                <div class="timeline-meta">
                                                    <span>🕒 <?= e(date('h:i A', $createdTs)) ?></span>
                                                    <span>· <?= e($relativeTime) ?></span>
                                                    <?php if (!empty($tl['ip_address'])): ?>
                                                        <span style="opacity:0.6;">(IP: <?= e($tl['ip_address']) ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
