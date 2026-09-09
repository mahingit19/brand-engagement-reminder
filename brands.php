<?php
require __DIR__ . '/config.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save_brand') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $latestPost = trim($_POST['latest_post_url'] ?? '');
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

        $platforms = $_POST['platform'] ?? [];
        $urls = $_POST['url'] ?? [];
        $pdo->prepare("DELETE FROM social_links WHERE brand_id=:id")->execute(['id'=>$id]);
        $insertLink = $pdo->prepare("INSERT INTO social_links (brand_id, platform, url) VALUES (:brand_id,:platform,:url)");
        foreach ($platforms as $i => $platform) {
            $platform = trim((string)$platform);
            $url = trim((string)($urls[$i] ?? ''));
            if ($platform !== '' && $url !== '') {
                $insertLink->execute(['brand_id'=>$id, 'platform'=>$platform, 'url'=>$url]);
            }
        }

        header('Location: brands.php?saved=1');
        exit;
    }

    if ($action === 'toggle_brand') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE brands SET status = IF(status=1,0,1) WHERE id=:id")->execute(['id'=>$id]);
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

$brands = $pdo->query("SELECT b.*, COUNT(s.id) AS link_count FROM brands b LEFT JOIN social_links s ON s.brand_id=b.id GROUP BY b.id ORDER BY b.status DESC, b.name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brands · Engagement Reminder</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
    <div><h1>Brand Management</h1><p>Add each brand and its social/profile links.</p></div>
    <nav><a href="index.php">Dashboard</a><a href="brands.php" class="active">Brands</a><a href="settings.php">Settings</a></nav>
</header>
<main class="container two-col">
    <section class="panel">
        <h2><?= $editBrand ? 'Edit Brand' : 'Add Brand' ?></h2>
        <?php if (!empty($_GET['error'])): ?><div class="alert error"><?= e($_GET['error']) ?></div><?php endif; ?>
        <?php if (!empty($_GET['saved'])): ?><div class="alert success">Saved successfully.</div><?php endif; ?>
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
                <strong>Social Links</strong>
                <button type="button" id="addSocial" class="btn btn-light btn-sm">+ Add Link</button>
            </div>
            <div id="socialRows">
                <?php $rows = $editLinks ?: [['platform'=>'Facebook','url'=>'']]; ?>
                <?php foreach ($rows as $row): ?>
                    <div class="social-row">
                        <input type="text" name="platform[]" placeholder="Facebook" value="<?= e($row['platform'] ?? '') ?>">
                        <input type="url" name="url[]" placeholder="https://..." value="<?= e($row['url'] ?? '') ?>">
                        <button type="button" class="remove-row">×</button>
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
        <h2>All Brands</h2>
        <div class="brand-list">
            <?php foreach ($brands as $brand): ?>
                <div class="brand-item <?= $brand['status'] ? '' : 'inactive' ?>">
                    <div>
                        <strong><?= e($brand['name']) ?></strong>
                        <span><?= (int)$brand['link_count'] ?> links · <?= $brand['status'] ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="inline-actions">
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
document.getElementById('addSocial').addEventListener('click', () => {
  const row = document.createElement('div');
  row.className = 'social-row';
  row.innerHTML = '<input type="text" name="platform[]" placeholder="LinkedIn"><input type="url" name="url[]" placeholder="https://..."><button type="button" class="remove-row">×</button>';
  document.getElementById('socialRows').appendChild(row);
});
document.addEventListener('click', e => { if(e.target.classList.contains('remove-row')) e.target.closest('.social-row').remove(); });
</script>
</body>
</html>
