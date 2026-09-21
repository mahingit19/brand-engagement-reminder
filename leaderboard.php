<?php
require_once __DIR__ . '/config.php';
requireLogin();

$currentUser = currentUser();
$currentUserId = (int)$currentUser['id'];
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
} elseif ($datePreset === 'all') {
    $fromDate = '2000-01-01';
    $toDate = $todayDate;
} elseif ($datePreset === 'custom' && !empty($customFrom) && !empty($customTo)) {
    $fromDate = date('Y-m-d', strtotime($customFrom));
    $toDate = date('Y-m-d', strtotime($customTo));
} else {
    $datePreset = 'today';
    $fromDate = $todayDate;
    $toDate = $todayDate;
}

// Streak Calculation Helper
if (!function_exists('calculateUserStreak')) {
    function calculateUserStreak(array $completedDates): int {
        if (empty($completedDates)) return 0;
        $today = today();
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));

        $dates = array_values(array_unique($completedDates));
        rsort($dates);

        $firstDate = $dates[0];
        if ($firstDate !== $today && $firstDate !== $yesterday) {
            return 0;
        }

        $streak = 1;
        $expectedPrevDate = date('Y-m-d', strtotime('-1 day', strtotime($firstDate)));

        for ($i = 1; $i < count($dates); $i++) {
            if ($dates[$i] === $expectedPrevDate) {
                $streak++;
                $expectedPrevDate = date('Y-m-d', strtotime('-1 day', strtotime($expectedPrevDate)));
            } else {
                break;
            }
        }
        return $streak;
    }
}

// Pre-fetch all completed dates for streaks in a single fast query
$streakStmt = $pdo->query("
    SELECT user_id, engagement_date 
    FROM daily_engagements 
    WHERE status = 'completed'
    GROUP BY user_id, engagement_date
    ORDER BY user_id ASC, engagement_date DESC
");
$allUserStreakDates = [];
foreach ($streakStmt->fetchAll() as $sRow) {
    $allUserStreakDates[(int)$sRow['user_id']][] = $sRow['engagement_date'];
}

// Fetch performance stats for all active users in the selected period
$leaderboardSql = "
    SELECT u.id, u.name, u.username, u.role,
           COUNT(DISTINCT d.id) AS total_brands,
           COUNT(DISTINCT CASE WHEN d.status = 'completed' THEN d.id END) AS completed_brands,
           COUNT(DISTINCT dse.id) AS total_links_done,
           COUNT(DISTINCT upe.id) AS total_posts_seen,
           MAX(d.completed_at) AS last_completed_at,
           MAX(l.created_at) AS last_active_at
    FROM users u
    LEFT JOIN daily_engagements d 
        ON d.user_id = u.id AND d.engagement_date BETWEEN :f1 AND :t1
    LEFT JOIN daily_social_engagements dse 
        ON dse.user_id = u.id AND dse.engagement_date BETWEEN :f2 AND :t2 AND dse.is_done = 1
    LEFT JOIN (
        SELECT upe_inner.id, upe_inner.user_id, upe_inner.engaged_at
        FROM user_post_engagements upe_inner
        INNER JOIN brand_posts bp ON bp.id = upe_inner.post_id
        INNER JOIN users u2 ON u2.id = upe_inner.user_id
        WHERE upe_inner.is_engaged = 1 
          AND (bp.created_at >= u2.created_at OR (bp.published_at IS NOT NULL AND bp.published_at >= u2.created_at))
    ) upe ON upe.user_id = u.id AND DATE(upe.engaged_at) BETWEEN :f3 AND :t3
    LEFT JOIN user_activity_logs l 
        ON l.user_id = u.id AND DATE(l.created_at) BETWEEN :f4 AND :t4
    WHERE u.status = 1
    GROUP BY u.id
";

$stmt = $pdo->prepare($leaderboardSql);
$stmt->execute([
    'f1' => $fromDate, 't1' => $toDate,
    'f2' => $fromDate, 't2' => $toDate,
    'f3' => $fromDate, 't3' => $toDate,
    'f4' => $fromDate, 't4' => $toDate,
]);
$usersData = $stmt->fetchAll();

// Calculate points, rates, streaks and initials
foreach ($usersData as &$u) {
    $uid = (int)$u['id'];
    $comp = (int)$u['completed_brands'];
    $links = (int)$u['total_links_done'];
    $posts = (int)$u['total_posts_seen'];
    $totBrands = (int)$u['total_brands'];

    // Points Scoring: Completed Brand = 10 pts, Link Done = 2 pts, Post Seen = 3 pts
    $u['points'] = ($comp * 10) + ($links * 2) + ($posts * 3);
    $u['rate'] = $totBrands > 0 ? round(($comp / $totBrands) * 100, 1) : 0;
    $u['streak'] = calculateUserStreak($allUserStreakDates[$uid] ?? []);
    $u['initial'] = mb_strtoupper(mb_substr($u['name'], 0, 1, 'UTF-8'), 'UTF-8');
}
unset($u);

// Sort users by Points DESC, Completed Brands DESC, Links Done DESC, Name ASC
usort($usersData, function($a, $b) {
    if ($b['points'] !== $a['points']) return $b['points'] <=> $a['points'];
    if ($b['completed_brands'] !== $a['completed_brands']) return $b['completed_brands'] <=> $a['completed_brands'];
    if ($b['total_links_done'] !== $a['total_links_done']) return $b['total_links_done'] <=> $a['total_links_done'];
    return strcmp($a['name'], $b['name']);
});

// Assign ranks and identify current user's standing
$myStanding = [
    'rank' => '-',
    'points' => 0,
    'streak' => 0,
    'completed' => 0,
    'total' => 0,
    'rate' => 0
];

$rankNum = 1;
foreach ($usersData as $idx => &$u) {
    $u['rank'] = $rankNum++;
    if ((int)$u['id'] === $currentUserId) {
        $myStanding = [
            'rank' => '#' . $u['rank'],
            'points' => $u['points'],
            'streak' => $u['streak'],
            'completed' => (int)$u['completed_brands'],
            'total' => (int)$u['total_brands'],
            'rate' => $u['rate']
        ];
    }
}
unset($u);

// Prepare Top 3 for Podium
$top1 = $usersData[0] ?? null;
$top2 = $usersData[1] ?? null;
$top3 = $usersData[2] ?? null;
$totalUsersCount = count($usersData);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Leaderboard · Brand Engagement</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div>
        <h1>🏆 User Leaderboard</h1>
        <p>টিম মেম্বারদের দৈনিক এনগেজমেন্ট পারফরম্যান্স, স্কোর ও ধারাবাহিকতা র‍্যাঙ্কিং</p>
    </div>
    <div class="topbar-right">
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <a href="leaderboard.php" class="active">Leaderboard</a>
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
    <!-- Hero / My Standing Banner -->
    <section class="leaderboard-hero">
        <div class="leaderboard-hero-inner">
            <div class="leaderboard-hero-title">
                <div class="leaderboard-hero-icon">🏆</div>
                <div class="leaderboard-hero-text">
                    <h2>Engagement Leaderboard</h2>
                    <p>দলের সবাই কে কত এগিয়ে আছেন তা দেখুন এবং নিয়মিত ব্র্যান্ড এনগেজমেন্ট সম্পন্ন করে স্কোর বাড়ান!</p>
                </div>
            </div>

            <div class="my-standing-badge">
                <div class="my-standing-item">
                    <span class="my-standing-label">আপনার র‍্যাঙ্ক</span>
                    <span class="my-standing-val highlight"><?= e($myStanding['rank']) ?> <small style="font-size:13px;color:#94a3b8;font-weight:600;">/ <?= $totalUsersCount ?></small></span>
                </div>
                <div class="my-standing-divider"></div>
                <div class="my-standing-item">
                    <span class="my-standing-label">আপনার স্কোর</span>
                    <span class="my-standing-val"><?= number_format($myStanding['points']) ?> <small style="font-size:12px;color:#94a3b8;font-weight:600;">pts</small></span>
                </div>
                <div class="my-standing-divider"></div>
                <div class="my-standing-item">
                    <span class="my-standing-label">স্ট্রিক (Streak)</span>
                    <span class="my-standing-val" style="color:#fb923c;">🔥 <?= $myStanding['streak'] ?> <small style="font-size:12px;color:#94a3b8;font-weight:600;">দিন</small></span>
                </div>
                <div class="my-standing-divider"></div>
                <div class="my-standing-item">
                    <span class="my-standing-label">সম্পন্ন ব্র্যান্ড</span>
                    <span class="my-standing-val"><?= $myStanding['completed'] ?> <small style="font-size:12px;color:#94a3b8;font-weight:600;">(<?= $myStanding['rate'] ?>%)</small></span>
                </div>
            </div>
        </div>
    </section>

    <!-- Period Filter Panel -->
    <section class="panel filter-panel" style="margin-bottom:24px;">
        <form method="get" action="leaderboard.php" class="reports-filter-form">
            <div class="filter-row">
                <!-- Preset Buttons -->
                <div class="filter-group">
                    <label style="margin:0 0 6px;">সময়কাল (Period)</label>
                    <div class="btn-group-segmented">
                        <a href="leaderboard.php?preset=today" class="btn-segment <?= $datePreset === 'today' ? 'active' : '' ?>">আজ (Today)</a>
                        <a href="leaderboard.php?preset=yesterday" class="btn-segment <?= $datePreset === 'yesterday' ? 'active' : '' ?>">গতকাল</a>
                        <a href="leaderboard.php?preset=7days" class="btn-segment <?= $datePreset === '7days' ? 'active' : '' ?>">গত ৭ দিন</a>
                        <a href="leaderboard.php?preset=month" class="btn-segment <?= $datePreset === 'month' ? 'active' : '' ?>">এই মাস</a>
                        <a href="leaderboard.php?preset=all" class="btn-segment <?= $datePreset === 'all' ? 'active' : '' ?>">সর্বমোট (All Time)</a>
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

                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:9px 18px;">🔍 ফিল্টার করুন</button>
                </div>
            </div>
        </form>
    </section>

    <!-- Podium Section (Top 3 Users) -->
    <?php if ($top1): ?>
        <section class="podium-container">
            <div class="podium-grid">
                <!-- 2nd Place -->
                <?php if ($top2): 
                    $isSelf2 = ((int)$top2['id'] === $currentUserId);
                ?>
                    <div class="podium-card rank-2 <?= $isSelf2 ? 'is-self' : '' ?>">
                        <div class="podium-medal-badge">🥈</div>
                        <div class="podium-avatar"><?= e($top2['initial']) ?></div>
                        <h3 class="podium-name">
                            <?= e($top2['name']) ?>
                            <?php if ($isSelf2): ?><span class="you-pill">You</span><?php endif; ?>
                        </h3>
                        <div class="podium-username">@<?= e($top2['username']) ?> · <span class="role-badge role-<?= e($top2['role']) ?>"><?= e(strtoupper($top2['role'])) ?></span></div>
                        <div class="podium-points"><?= number_format($top2['points']) ?></div>
                        <div class="podium-points-label">Points</div>
                        <div class="podium-meta-chips">
                            <span class="podium-chip">✅ <?= $top2['completed_brands'] ?> ব্র্যান্ড</span>
                            <span class="podium-chip">🔗 <?= $top2['total_links_done'] ?> লিঙ্ক</span>
                            <?php if ($top2['streak'] > 0): ?>
                                <span class="podium-chip streak">🔥 <?= $top2['streak'] ?> দিন</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 1st Place (Champion) -->
                <?php 
                    $isSelf1 = ((int)$top1['id'] === $currentUserId);
                ?>
                <div class="podium-card rank-1 <?= $isSelf1 ? 'is-self' : '' ?>">
                    <div class="podium-medal-badge">🥇</div>
                    <div class="podium-avatar"><?= e($top1['initial']) ?></div>
                    <h3 class="podium-name">
                        <?= e($top1['name']) ?>
                        <?php if ($isSelf1): ?><span class="you-pill">You</span><?php endif; ?>
                    </h3>
                    <div class="podium-username">@<?= e($top1['username']) ?> · <span class="role-badge role-<?= e($top1['role']) ?>"><?= e(strtoupper($top1['role'])) ?></span></div>
                    <div class="podium-points"><?= number_format($top1['points']) ?></div>
                    <div class="podium-points-label">Points · Champion 🏆</div>
                    <div class="podium-meta-chips">
                        <span class="podium-chip">✅ <?= $top1['completed_brands'] ?> ব্র্যান্ড</span>
                        <span class="podium-chip">🔗 <?= $top1['total_links_done'] ?> লিঙ্ক</span>
                        <?php if ($top1['streak'] > 0): ?>
                            <span class="podium-chip streak">🔥 <?= $top1['streak'] ?> দিন</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3rd Place -->
                <?php if ($top3): 
                    $isSelf3 = ((int)$top3['id'] === $currentUserId);
                ?>
                    <div class="podium-card rank-3 <?= $isSelf3 ? 'is-self' : '' ?>">
                        <div class="podium-medal-badge">🥉</div>
                        <div class="podium-avatar"><?= e($top3['initial']) ?></div>
                        <h3 class="podium-name">
                            <?= e($top3['name']) ?>
                            <?php if ($isSelf3): ?><span class="you-pill">You</span><?php endif; ?>
                        </h3>
                        <div class="podium-username">@<?= e($top3['username']) ?> · <span class="role-badge role-<?= e($top3['role']) ?>"><?= e(strtoupper($top3['role'])) ?></span></div>
                        <div class="podium-points"><?= number_format($top3['points']) ?></div>
                        <div class="podium-points-label">Points</div>
                        <div class="podium-meta-chips">
                            <span class="podium-chip">✅ <?= $top3['completed_brands'] ?> ব্র্যান্ড</span>
                            <span class="podium-chip">🔗 <?= $top3['total_links_done'] ?> লিঙ্ক</span>
                            <?php if ($top3['streak'] > 0): ?>
                                <span class="podium-chip streak">🔥 <?= $top3['streak'] ?> দিন</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Full Leaderboard Table -->
    <section class="panel">
        <div class="panel-header-row">
            <div>
                <h2 style="margin:0;">📊 সম্পূর্ণ পারফরম্যান্স র‍্যাঙ্কিং <span class="counter-badge"><?= count($usersData) ?> জন সদস্য</span></h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">
                    পয়েন্ট স্কোর ও এনগেজমেন্টের ভিত্তিতে সাজানো পূর্ণাঙ্গ তালিকা
                </p>
            </div>
        </div>

        <?php if (empty($usersData)): ?>
            <div class="empty-state" style="padding:40px 20px;margin-top:14px;">
                <h3>📭 কোনো সক্রিয় ইউজার পাওয়া যায়নি</h3>
                <p class="muted">সিস্টেমে কোনো ইউজার ডাটা নেই।</p>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="overflow-x:auto;margin-top:14px;">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th style="width:70px;text-align:center;">র‍্যাঙ্ক</th>
                            <th>টিম মেম্বার</th>
                            <th style="text-align:center;">স্কোর (Points)</th>
                            <th style="text-align:center;">সম্পন্ন ব্র্যান্ড</th>
                            <th style="text-align:center;">লিঙ্ক ভিজিট</th>
                            <th style="text-align:center;">পোস্ট দেখেছেন</th>
                            <th style="text-align:center;">ধারাবাহিকতা (Streak)</th>
                            <th style="text-align:right;">সর্বশেষ সক্রিয়তা</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersData as $row): 
                            $isSelf = ((int)$row['id'] === $currentUserId);
                            $rank = (int)$row['rank'];
                            $rankClass = '';
                            $rankIcon = '#' . $rank;
                            if ($rank === 1) {
                                $rankClass = 'rank-gold';
                                $rankIcon = '🥇';
                            } elseif ($rank === 2) {
                                $rankClass = 'rank-silver';
                                $rankIcon = '🥈';
                            } elseif ($rank === 3) {
                                $rankClass = 'rank-bronze';
                                $rankIcon = '🥉';
                            }

                            $rateClass = $row['rate'] >= 80 ? 'progress-high' : ($row['rate'] >= 50 ? 'progress-mid' : 'progress-low');
                            $lastActiveTime = $row['last_active_at'] ?? $row['last_completed_at'] ?? null;
                        ?>
                            <tr class="<?= $isSelf ? 'is-self' : '' ?>">
                                <td style="text-align:center;">
                                    <span class="rank-pill <?= $rankClass ?>"><?= $rankIcon ?></span>
                                </td>
                                <td>
                                    <div class="user-cell-wrapper">
                                        <div class="user-cell-avatar"><?= e($row['initial']) ?></div>
                                        <div class="user-cell-info">
                                            <div class="user-cell-name">
                                                <?= e($row['name']) ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="you-pill">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-cell-meta">
                                                @<?= e($row['username']) ?>
                                                <span class="role-badge role-<?= e($row['role']) ?>" style="margin-left:4px;font-size:9px;padding:1px 5px;"><?= e(strtoupper($row['role'])) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="points-val">
                                        <?= number_format($row['points']) ?> <span>pts</span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div style="font-weight:700;font-size:14px;color:#0f172a;">
                                        <?= (int)$row['completed_brands'] ?> <span style="font-size:12px;color:var(--muted);font-weight:400;">/ <?= (int)$row['total_brands'] ?></span>
                                    </div>
                                    <div class="progress-bar-container" style="width:100px;margin:4px auto 0;height:5px;">
                                        <div class="progress-bar-fill <?= $rateClass ?>" style="width:<?= min(100, $row['rate']) ?>%;"></div>
                                    </div>
                                    <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= $row['rate'] ?>% সম্পন্ন</div>
                                </td>
                                <td style="text-align:center;font-weight:700;color:var(--success);">
                                    <?= (int)$row['total_links_done'] ?>
                                </td>
                                <td style="text-align:center;font-weight:600;color:var(--primary);">
                                    <?= (int)$row['total_posts_seen'] ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($row['streak'] > 0): ?>
                                        <span class="streak-pill">🔥 <?= $row['streak'] ?> দিন</span>
                                    <?php else: ?>
                                        <span class="streak-pill zero">0 দিন</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;font-size:12.5px;color:var(--muted);">
                                    <?php if (!empty($lastActiveTime)): ?>
                                        <?= e(formatRelativeTime($lastActiveTime)) ?>
                                    <?php else: ?>
                                        <span class="muted">সক্রিয়তা নেই</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Scoring Rules & Motivation Card -->
    <section class="panel" style="margin-top:24px;">
        <h3 style="margin:0 0 4px;font-size:16px;">💡 লিডারবোর্ড পয়েন্ট ও নিয়মাবলী</h3>
        <p class="muted" style="margin:0;font-size:13px;">দৈনিক প্রতিটি সফল অ্যাকশনের জন্য আপনি স্বয়ংক্রিয়ভাবে পয়েন্ট অর্জন করবেন:</p>
        
        <div class="rules-grid">
            <div class="rule-item">
                <div class="rule-item-icon">🏆</div>
                <div class="rule-item-points">+১০ পয়েন্ট</div>
                <div class="rule-item-desc">প্রতিটি ব্র্যান্ডের সমস্ত সোশ্যাল লিঙ্ক সম্পন্ন করে দৈনিক টাস্ক সম্পূর্ণ করলে</div>
            </div>
            <div class="rule-item">
                <div class="rule-item-icon">🔗</div>
                <div class="rule-item-points">+২ পয়েন্ট</div>
                <div class="rule-item-desc">ব্র্যান্ডের যেকোনো সোশ্যাল প্ল্যাটফর্ম লিঙ্ক (YouTube, Facebook, LinkedIn ইত্যাদি) ভিজিট করলে</div>
            </div>
            <div class="rule-item">
                <div class="rule-item-icon">📢</div>
                <div class="rule-item-points">+৩ পয়েন্ট</div>
                <div class="rule-item-desc">ফিডে নতুন আসা ব্র্যান্ড পোস্ট ওপেন করলে বা Seen হিসেবে মার্ক করলে</div>
            </div>
            <div class="rule-item">
                <div class="rule-item-icon">🔥</div>
                <div class="rule-item-points">স্ট্রিক বোনাস</div>
                <div class="rule-item-desc">প্রতিদিন নিয়মিত এনগেজমেন্ট সম্পন্ন করে আপনার ধারাবাহিক স্ট্রিক ধরে রাখুন</div>
            </div>
        </div>
    </section>
</main>
</body>
</html>
