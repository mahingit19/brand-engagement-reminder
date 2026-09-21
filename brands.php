<?php
require __DIR__ . '/config.php';
requireAdmin();
$currentUser = currentUser();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save_brand') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $latestPost = trim($_POST['latest_post_url'] ?? '');
        $rssFeed = trim($_POST['rss_feed_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name === '') {
            header('Location: brands.php?error=' . urlencode('Brand name is required.'));
            exit;
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE brands SET name=:name, latest_post_url=:post, notes=:notes WHERE id=:id");
            $stmt->execute(['name'=>$name, 'post'=>$latestPost ?: null, 'notes'=>$notes ?: null, 'id'=>$id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO brands (name, latest_post_url, notes) VALUES (:name,:post,:notes)");
            $stmt->execute(['name'=>$name, 'post'=>$latestPost ?: null, 'notes'=>$notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }

        $linkIds = $_POST['link_id'] ?? [];
        $platforms = $_POST['platform'] ?? [];
        $urls = $_POST['url'] ?? [];
        $socialRssUrls = $_POST['social_rss_feed_url'] ?? [];

        $keptIds = [];
        $updateLink = $pdo->prepare("UPDATE social_links SET platform=:platform, url=:url, rss_feed_url=:rss WHERE id=:id AND brand_id=:brand_id");
        $insertLink = $pdo->prepare("INSERT INTO social_links (brand_id, platform, url, rss_feed_url) VALUES (:brand_id,:platform,:url,:rss)");

        $hasRss = false;
        foreach ($platforms as $i => $platform) {
            $platform = trim((string)$platform);
            $url = trim((string)($urls[$i] ?? ''));
            $rss = trim((string)($socialRssUrls[$i] ?? ''));
            $linkId = (int)($linkIds[$i] ?? 0);

            if ($platform !== '' && $url !== '') {
                if ($rss !== '') {
                    $hasRss = true;
                }
                if ($linkId > 0) {
                    $updateLink->execute([
                        'platform' => $platform,
                        'url' => $url,
                        'rss' => $rss ?: null,
                        'id' => $linkId,
                        'brand_id' => $id,
                    ]);
                    $keptIds[] = $linkId;
                } else {
                    $insertLink->execute([
                        'brand_id' => $id,
                        'platform' => $platform,
                        'url' => $url,
                        'rss' => $rss ?: null,
                    ]);
                    $keptIds[] = (int)$pdo->lastInsertId();
                }
            }
        }

        if (!empty($keptIds)) {
            $inClause = implode(',', array_map('intval', $keptIds));
            $pdo->exec("DELETE FROM social_links WHERE brand_id = {$id} AND id NOT IN ({$inClause})");
        } else {
            $pdo->exec("DELETE FROM social_links WHERE brand_id = {$id}");
        }

        if ($hasRss) {
            require_once __DIR__ . '/fetch_posts.php';
            fetchBrandPosts($pdo, $id, true);
        }

        logUserActivity($pdo, (int)$currentUser['id'], 'save_brand', $id, null, null, "Brand '{$name}' saved");

        header('Location: brands.php?saved=1');
        exit;
    }

    if ($action === 'check_feed') {
        require_once __DIR__ . '/fetch_posts.php';
        $id = (int)($_POST['id'] ?? 0);
        $res = fetchBrandPosts($pdo, $id, true);
        $newCount = (int)($res['new_posts'] ?? 0);
        header("Location: brands.php?feed_checked={$newCount}");
        exit;
    }

    if ($action === 'toggle_brand') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE brands SET status = IF(status=1,0,1) WHERE id=:id")->execute(['id'=>$id]);
        logUserActivity($pdo, (int)$currentUser['id'], 'toggle_brand', $id, null, null, "Toggled status for brand #{$id}");
        header('Location: brands.php');
        exit;
    }
}

$editBrand = null;
$editLinks = [];
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM brands WHERE id=:id");
    $stmt->execute(['id'=>(int)$_GET['edit']]);
    $editBrand = $stmt->fetch();
    if ($editBrand) {
        $stmt = $pdo->prepare("SELECT * FROM social_links WHERE brand_id=:id ORDER BY id");
        $stmt->execute(['id'=>$editBrand['id']]);
        $editLinks = $stmt->fetchAll();
    }
}

$brands = $pdo->query("SELECT b.*,
    COUNT(DISTINCT s.id) AS link_count,
    COUNT(DISTINCT bp.id) AS post_count,
    COUNT(DISTINCT CASE WHEN s.rss_feed_url IS NOT NULL AND TRIM(s.rss_feed_url) != '' THEN s.id END) AS rss_feed_count,
    COUNT(DISTINCT CASE WHEN s.rss_feed_url IS NOT NULL AND TRIM(s.rss_feed_url) != '' AND s.last_feed_status = 'error' THEN s.id END) AS rss_error_count,
    COUNT(DISTINCT CASE WHEN s.rss_feed_url IS NOT NULL AND TRIM(s.rss_feed_url) != '' AND s.last_feed_status = 'ok' THEN s.id END) AS rss_ok_count
FROM brands b
LEFT JOIN social_links s ON s.brand_id=b.id
LEFT JOIN brand_posts bp ON bp.brand_id=b.id
GROUP BY b.id
ORDER BY b.status DESC, b.name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brands · Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <div><h1>Brand Management</h1><p>Add each brand and its social/profile links.</p></div>
    <div class="topbar-right">
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <a href="leaderboard.php">Leaderboard</a>
            <?php if (isAdmin()): ?>
                <a href="brands.php" class="active">Brands</a>
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
<main class="container brands-layout">
    <section class="panel sticky-panel" id="brandFormPanel">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px;">
            <h2 style="margin:0;"><?= $editBrand ? 'Edit Brand' : 'Add Brand' ?></h2>
            <?php if ($editBrand): ?>
                <a class="btn btn-light btn-sm" href="brands.php">+ New Brand</a>
            <?php endif; ?>
        </div>
        <?php if (!empty($_GET['error'])): ?><div class="alert error"><?= e($_GET['error']) ?></div><?php endif; ?>
        <?php if (!empty($_GET['saved'])): ?><div class="alert success">Saved successfully.</div><?php endif; ?>
        <?php if (isset($_GET['feed_checked'])): ?><div class="alert success">Feed scanned! <?= (int)$_GET['feed_checked'] ?> new post(s) found.</div><?php endif; ?>
        <form method="post" id="brandForm">
            <input type="hidden" name="form_action" value="save_brand">
            <input type="hidden" name="id" value="<?= (int)($editBrand['id'] ?? 0) ?>">
            <label>Brand Name
                <input type="text" name="name" required value="<?= e($editBrand['name'] ?? '') ?>" placeholder="e.g. PBX.BD">
            </label>
            <label>Latest Post URL <small>(optional)</small>
                <input type="url" name="latest_post_url" value="<?= e($editBrand['latest_post_url'] ?? '') ?>" placeholder="Paste the current post URL">
            </label>
            <label>Notes <small>(optional)</small>
                <textarea name="notes" rows="2" placeholder="Any short reminder note"><?= e($editBrand['notes'] ?? '') ?></textarea>
            </label>

            <div class="form-section-title">
                <strong>Social Links &amp; RSS Feeds</strong>
                <button type="button" id="addSocial" class="btn btn-light btn-sm">+ Add Social Link</button>
            </div>

            <div class="field-hint" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin:10px 0 14px;font-size:12.5px;color:#166534;line-height:1.6;">
                <strong>💡 ফ্রি ও আনলিমিটেড RSS ফিড পাওয়ার উপায়:</strong><br>
                • <strong>লোকাল RSS-Bridge (কোনো লিমিট নেই):</strong> <a href="/rss-bridge/" target="_blank" style="color:#15803d;font-weight:700;text-decoration:underline;">লোকাল RSS-Bridge ওপেন করুন (localhost/rss-bridge)</a>। সেখানে Facebook, YouTube, Instagram, Twitter ইত্যাদি ব্রিজ থেকে ইউজারনেম দিয়ে <strong>Atom</strong> ফিড লিঙ্ক কপি করে এখানে পেস্ট করুন।<br>
                • <strong>YouTube নিজস্ব ফিড:</strong> <code>https://www.youtube.com/feeds/videos.xml?channel_id=UC...</code><br>
                • <strong>Blog / Website:</strong> ওয়েবসাইটের নিজস্ব ফিড লিঙ্ক (যেমন: <code>https://example.com/feed</code>)।
            </div>

            <div id="socialRows">
                <?php $rows = $editLinks ?: [['id'=>0, 'platform'=>'Facebook','url'=>'','rss_feed_url'=>'']]; ?>
                <?php foreach ($rows as $row): ?>
                    <div class="social-card-row">
                        <input type="hidden" name="link_id[]" value="<?= (int)($row['id'] ?? 0) ?>">
                        <div class="social-row-main">
                            <input type="text" name="platform[]" placeholder="Platform (e.g. YouTube, Facebook, LinkedIn)" value="<?= e($row['platform'] ?? '') ?>" required>
                            <input type="url" name="url[]" placeholder="Page / Channel URL (https://...)" value="<?= e($row['url'] ?? '') ?>" required>
                            <button type="button" class="remove-row" title="Remove link">×</button>
                        </div>
                        <div class="social-row-rss">
                            <input type="url" name="social_rss_feed_url[]" placeholder="📡 RSS Feed URL (or click Auto-Gen)" value="<?= e($row['rss_feed_url'] ?? '') ?>">
                            <button type="button" class="btn-autogen-rss" title="Auto-generate RSS feed URL">⚡ Auto-Gen</button>
                            <button type="button" class="btn-test-rss" title="Test if this RSS feed works right now">🧪 Test</button>
                        </div>
                        <div class="feed-test-msg" style="font-size:12px;margin-top:2px;display:none;line-height:1.4;"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save Brand</button>
                <?php if ($editBrand): ?><a class="btn btn-light" href="brands.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header-row">
            <div>
                <h2 style="margin:0;">All Brands <span class="counter-badge" id="brandCountBadge"><?= count($brands) ?></span></h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">Manage brands, social profiles &amp; RSS feeds</p>
            </div>
            <div class="search-box">
                <input type="search" id="brandSearch" placeholder="🔍 Search brands..." autocomplete="off">
            </div>
        </div>

        <div id="brandSearchEmpty" class="empty-state" style="display:none;padding:35px 20px;margin-top:12px;">
            <h3 style="margin:0 0 6px;font-size:16px;">No matching brands found</h3>
            <p class="muted" style="margin:0;font-size:13px;">Try searching with another keyword.</p>
        </div>

        <div class="brand-list" id="brandList">
            <?php foreach ($brands as $brand): ?>
                <div class="brand-item <?= $brand['status'] ? '' : 'inactive' ?>" data-name="<?= e(mb_strtolower($brand['name'])) ?>">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <strong><?= e($brand['name']) ?></strong>
                            <?php if ((int)$brand['rss_ok_count'] > 0): ?>
                                <span class="badge-rss" style="background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;" title="Auto RSS Tracking Active">📡 <?= (int)$brand['rss_ok_count'] ?> Active RSS (<?= (int)$brand['post_count'] ?> posts)</span>
                            <?php endif; ?>
                            <?php if ((int)$brand['rss_error_count'] > 0): ?>
                                <span class="badge-rss" style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;" title="Bridge Error occurred on this feed">⚠️ <?= (int)$brand['rss_error_count'] ?> Bridge Error</span>
                            <?php endif; ?>
                        </div>
                        <span><?= (int)$brand['link_count'] ?> links · <?= $brand['status'] ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="inline-actions">
                        <?php if ((int)$brand['rss_feed_count'] > 0): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="form_action" value="check_feed"><input type="hidden" name="id" value="<?= (int)$brand['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit" title="Scan feeds for this brand">Scan Feeds</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-light btn-sm" href="brands.php?edit=<?= (int)$brand['id'] ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Change active status?')">
                            <input type="hidden" name="form_action" value="toggle_brand"><input type="hidden" name="id" value="<?= (int)$brand['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= $brand['status'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$brands): ?><p class="muted">No brands added yet.</p><?php endif; ?>
        </div>
    </section>
</main>
<script>
function autoGenerateRssForCard(card, showNotice = false) {
  const platformInput = card.querySelector('input[name="platform[]"]');
  const urlInput = card.querySelector('input[name="url[]"]');
  const rssInput = card.querySelector('input[name="social_rss_feed_url[]"]');
  if (!urlInput || !rssInput) return;

  const rawUrl = (urlInput.value || '').trim();
  let platform = (platformInput ? platformInput.value : '').trim();

  if (!rawUrl) {
    if (showNotice) alert('অনুগ্রহ করে প্রথমে পেজ বা চ্যানেলের লিঙ্ক (URL) দিন।');
    urlInput.focus();
    return;
  }

  // Detect platform if missing or generic
  const lowerUrl = rawUrl.toLowerCase();
  if (!platform || (platform.toLowerCase() === 'facebook' && (lowerUrl.includes('youtube') || lowerUrl.includes('youtu.be')))) {
    if (lowerUrl.includes('youtube.com') || lowerUrl.includes('youtu.be')) platform = 'YouTube';
    else if (lowerUrl.includes('facebook.com') || lowerUrl.includes('fb.com')) platform = 'Facebook';
    else if (lowerUrl.includes('instagram.com')) platform = 'Instagram';
    else if (lowerUrl.includes('twitter.com') || lowerUrl.includes('x.com')) platform = 'Twitter/X';
    else if (lowerUrl.includes('linkedin.com')) platform = 'LinkedIn';
    else if (lowerUrl.includes('tiktok.com')) platform = 'TikTok';
    if (platformInput && platform) platformInput.value = platform;
  }

  const p = (platform || '').toLowerCase();
  let feedUrl = null;

  // 1. YouTube
  if (p.includes('youtube') || lowerUrl.includes('youtube.com') || lowerUrl.includes('youtu.be')) {
    const chMatch = rawUrl.match(/\/channel\/(UC[a-zA-Z0-9_\-]+)/i);
    const handleMatch = rawUrl.match(/@([a-zA-Z0-9_\-\.]+)/);
    const cMatch = rawUrl.match(/\/(?:c|user)\/([a-zA-Z0-9_\-\.]+)/i);

    if (chMatch) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=YoutubeBridge&context=By+channel+id&c=${encodeURIComponent(chMatch[1])}&format=Atom`;
    } else if (handleMatch) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=YoutubeBridge&context=By+custom+name&custom=${encodeURIComponent(handleMatch[1])}&format=Atom`;
    } else if (cMatch) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=YoutubeBridge&context=By+custom+name&custom=${encodeURIComponent(cMatch[1])}&format=Atom`;
    } else {
      const cleanUrl = rawUrl.split(/[?#]/)[0].replace(/\/+$/, '');
      const parts = cleanUrl.split('/');
      const last = parts[parts.length - 1];
      if (last && !['watch', 'videos', 'shorts', 'youtube.com'].includes(last.toLowerCase())) {
        feedUrl = `http://localhost/rss-bridge/?action=display&bridge=YoutubeBridge&context=By+custom+name&custom=${encodeURIComponent(last.replace(/^@/, ''))}&format=Atom`;
      }
    }
  }
  // 2. Facebook
  else if (p.includes('facebook') || lowerUrl.includes('facebook.com') || lowerUrl.includes('fb.com')) {
    if (lowerUrl.includes('/groups/')) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=FacebookBridge&context=Group&g=${encodeURIComponent(rawUrl.split('?')[0])}&format=Atom`;
    } else {
      const idMatch = rawUrl.match(/[?&]id=(\d+)/);
      if (idMatch) {
        feedUrl = `http://localhost/rss-bridge/?action=display&bridge=FacebookBridge&context=User&u=${encodeURIComponent(idMatch[1])}&format=Atom`;
      } else {
        const clean = rawUrl.split(/[?#]/)[0].replace(/\/+$/, '');
        const parts = clean.split('/');
        const last = parts[parts.length - 1];
        if (last && !['facebook.com', 'fb.com', 'home.php', 'watch', 'pages'].includes(last.toLowerCase())) {
          feedUrl = `http://localhost/rss-bridge/?action=display&bridge=FacebookBridge&context=User&u=${encodeURIComponent(last)}&format=Atom`;
        }
      }
    }
  }
  // 3. Instagram
  else if (p.includes('instagram') || lowerUrl.includes('instagram.com')) {
    const clean = rawUrl.split(/[?#]/)[0].replace(/\/+$/, '');
    const parts = clean.split('/');
    const last = parts[parts.length - 1];
    if (last && !['instagram.com', 'p', 'reel', 'explore'].includes(last.toLowerCase())) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=InstagramBridge&context=Username&u=${encodeURIComponent(last)}&format=Atom`;
    }
  }
  // 4. Twitter / X
  else if (p.includes('twitter') || p.includes('x') || lowerUrl.includes('twitter.com') || lowerUrl.includes('x.com')) {
    const clean = rawUrl.split(/[?#]/)[0].replace(/\/+$/, '');
    const parts = clean.split('/');
    const last = parts[parts.length - 1];
    if (last && !['twitter.com', 'x.com', 'home', 'explore'].includes(last.toLowerCase())) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=TwitterBridge&context=By+username&u=${encodeURIComponent(last)}&format=Atom`;
    }
  }
  // 5. TikTok
  else if (p.includes('tiktok') || lowerUrl.includes('tiktok.com')) {
    const handleMatch = rawUrl.match(/@([a-zA-Z0-9_\-\.]+)/);
    if (handleMatch) {
      feedUrl = `http://localhost/rss-bridge/?action=display&bridge=TikTokBridge&context=By+user&u=${encodeURIComponent(handleMatch[1])}&format=Atom`;
    }
  }
  // 6. Direct RSS / Atom feed already
  else if (lowerUrl.endsWith('.xml') || lowerUrl.includes('/feed') || lowerUrl.includes('/rss')) {
    feedUrl = rawUrl;
  }

  if (feedUrl) {
    rssInput.value = feedUrl;
    testFeedForCard(card);
  } else if (showNotice) {
    alert('এই লিঙ্কের জন্য স্বয়ংক্রিয় RSS তৈরি করা যায়নি। আপনি ম্যানুয়ালি RSS লিঙ্ক দিতে পারেন অথবা localhost/rss-bridge থেকে তৈরি করে নিতে পারেন।');
  }
}

async function testFeedForCard(card) {
  const rssInput = card.querySelector('input[name="social_rss_feed_url[]"]');
  let msgEl = card.querySelector('.feed-test-msg');
  const testBtn = card.querySelector('.btn-test-rss');
  const autoBtn = card.querySelector('.btn-autogen-rss');
  if (!rssInput) return;

  if (!msgEl) {
    msgEl = document.createElement('div');
    msgEl.className = 'feed-test-msg';
    msgEl.style.fontSize = '12px';
    msgEl.style.marginTop = '4px';
    msgEl.style.lineHeight = '1.4';
    card.appendChild(msgEl);
  }

  const url = (rssInput.value || '').trim();
  if (!url) {
    msgEl.style.display = 'block';
    msgEl.style.background = '#fef3c7';
    msgEl.style.color = '#92400e';
    msgEl.style.border = '1px solid #fde68a';
    msgEl.style.padding = '6px 10px';
    msgEl.style.borderRadius = '8px';
    msgEl.innerHTML = '⚠️ অনুগ্রহ করে প্রথমে RSS Feed URL দিন অথবা ⚡ Auto-Gen বাটনে ক্লিক করুন।';
    return;
  }

  // Set testing state
  msgEl.style.display = 'block';
  msgEl.style.background = '#f0f9ff';
  msgEl.style.color = '#0369a1';
  msgEl.style.border = '1px solid #bae6fd';
  msgEl.style.padding = '6px 10px';
  msgEl.style.borderRadius = '8px';
  msgEl.innerHTML = '⏳ <strong>যাচাই করা হচ্ছে...</strong> RSS-Bridge থেকে লাইভ পোস্ট চেক চলছে...';
  if (testBtn) testBtn.disabled = true;
  if (autoBtn) autoBtn.disabled = true;

  try {
    const formData = new URLSearchParams();
    formData.append('url', url);
    const res = await fetch('api_test_feed.php', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();

    if (data.ok) {
      msgEl.style.background = '#ecfdf5';
      msgEl.style.color = '#065f46';
      msgEl.style.border = '1px solid #a7f3d0';
      msgEl.innerHTML = `<strong>${data.message}</strong><br><small style="color:#047857;">সর্বশেষ পোস্ট: "${data.latest_title}"</small>`;
      rssInput.style.borderColor = '#10b981';
      rssInput.style.backgroundColor = '#f0fdf4';
    } else {
      msgEl.style.background = '#fef2f2';
      msgEl.style.color = '#991b1b';
      msgEl.style.border = '1px solid #fecaca';
      msgEl.innerHTML = `<strong>${data.message}</strong><br><small style="color:#7f1d1d;">💡 এটি খালি রাখতে পারেন—তাহলে সিস্টেম অটো ফলব্যাক করে সরাসরি ওই সোশ্যাল পেজের রিমাইন্ডার দিবে।</small>`;
      rssInput.style.borderColor = '#ef4444';
      rssInput.style.backgroundColor = '#fff5f5';
    }
  } catch (err) {
    msgEl.style.background = '#fef2f2';
    msgEl.style.color = '#991b1b';
    msgEl.style.border = '1px solid #fecaca';
    msgEl.innerHTML = '❌ টেস্ট করতে সমস্যা হয়েছে। লোকাল XAMPP Apache চালু আছে কিনা নিশ্চিত করুন।';
  } finally {
    if (testBtn) testBtn.disabled = false;
    if (autoBtn) autoBtn.disabled = false;
  }
}

document.getElementById('addSocial').addEventListener('click', () => {
  const card = document.createElement('div');
  card.className = 'social-card-row';
  card.innerHTML = `
    <input type="hidden" name="link_id[]" value="0">
    <div class="social-row-main">
      <input type="text" name="platform[]" placeholder="Platform (e.g. YouTube, LinkedIn)" required>
      <input type="url" name="url[]" placeholder="Page / Channel URL (https://...)" required>
      <button type="button" class="remove-row" title="Remove link">×</button>
    </div>
    <div class="social-row-rss">
      <input type="url" name="social_rss_feed_url[]" placeholder="📡 RSS Feed URL (or click Auto-Gen)">
      <button type="button" class="btn-autogen-rss" title="Auto-generate RSS feed URL">⚡ Auto-Gen</button>
      <button type="button" class="btn-test-rss" title="Test if this RSS feed works right now">🧪 Test</button>
    </div>
    <div class="feed-test-msg" style="font-size:12px;margin-top:2px;display:none;line-height:1.4;"></div>
  `;
  document.getElementById('socialRows').appendChild(card);
});

document.addEventListener('click', e => {
  if (e.target.classList.contains('remove-row')) {
    const card = e.target.closest('.social-card-row');
    if (card) card.remove();
  } else if (e.target.closest('.btn-autogen-rss')) {
    const btn = e.target.closest('.btn-autogen-rss');
    const card = btn.closest('.social-card-row');
    if (card) autoGenerateRssForCard(card, true);
  } else if (e.target.closest('.btn-test-rss')) {
    const btn = e.target.closest('.btn-test-rss');
    const card = btn.closest('.social-card-row');
    if (card) testFeedForCard(card);
  }
});

document.addEventListener('change', e => {
  if (e.target.matches('input[name="url[]"]')) {
    const card = e.target.closest('.social-card-row');
    const rssInput = card ? card.querySelector('input[name="social_rss_feed_url[]"]') : null;
    if (card && rssInput && !rssInput.value.trim()) {
      autoGenerateRssForCard(card, false);
    }
  }
});

// Live Search for Brands
const brandSearch = document.getElementById('brandSearch');
const brandList = document.getElementById('brandList');
const brandSearchEmpty = document.getElementById('brandSearchEmpty');
const brandCountBadge = document.getElementById('brandCountBadge');
const totalBrands = <?= count($brands) ?>;

if (brandSearch && brandList) {
  const doFilter = () => {
    const q = (brandSearch.value || '').trim().toLowerCase();
    const items = brandList.querySelectorAll('.brand-item');
    let visible = 0;

    items.forEach(item => {
      const name = (item.getAttribute('data-name') || '').toLowerCase();
      const match = !q || name.includes(q);
      if (match) {
        item.classList.remove('is-hidden');
        item.style.display = '';
        visible++;
      } else {
        item.classList.add('is-hidden');
        item.style.display = 'none';
      }
    });

    if (brandCountBadge) {
      brandCountBadge.textContent = q ? `${visible} / ${totalBrands}` : `${totalBrands}`;
    }
    if (brandSearchEmpty) {
      brandSearchEmpty.style.display = (visible === 0 && q) ? 'block' : 'none';
    }
  };

  try {
    const saved = sessionStorage.getItem('brand_search_query') || '';
    if (saved) {
      brandSearch.value = saved;
      doFilter();
    }
  } catch (err) {}

  ['input', 'keyup', 'change', 'search'].forEach(evt => {
    brandSearch.addEventListener(evt, () => {
      try {
        sessionStorage.setItem('brand_search_query', brandSearch.value);
      } catch (err) {}
      doFilter();
    });
  });

  brandSearch.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') e.preventDefault();
  });
}
</script>
</body>
</html>
