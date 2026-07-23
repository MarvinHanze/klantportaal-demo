<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

/* Kennisbank is bewust gedeeld/globaal (geen account_id) — zie ensure_schema(). */
$search = trim((string) ($_GET['q'] ?? ''));
$category = $_GET['category'] ?? '';

$categories = $pdo->query("SELECT DISTINCT category FROM kp_kb_articles ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(title LIKE ? OR body LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($category !== '' && in_array($category, $categories, true)) {
    $where[] = 'category = ?';
    $params[] = $category;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stmt = $pdo->prepare("SELECT * FROM kp_kb_articles {$whereSql} ORDER BY category, title");
$stmt->execute($params);
$articles = $stmt->fetchAll();

$grouped = [];
foreach ($articles as $a) {
    $grouped[$a['category']][] = $a;
}

$pageTitle = 'Kennisbank';
$activeNav = 'kennisbank';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Kennisbank', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-2">Kennisbank</h1>
<p class="text-slate-500 text-sm mb-6">Veelgestelde vragen en handleidingen. Vind je geen antwoord? Maak dan een <a href="<?php echo BASE; ?>/tickets.php" style="color:var(--hz-primary);">support-ticket</a> aan.</p>

<form method="GET" class="flex flex-wrap gap-3 mb-6">
    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Zoeken in kennisbank..." class="px-3 py-2 border border-slate-300 rounded-lg text-sm w-64">
    <select name="category" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Alle categorieën</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?php echo e($c); ?>" <?php echo $category === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="hz-btn hz-btn--secondary">Filteren</button>
    <?php if ($search !== '' || $category !== ''): ?>
        <a href="<?php echo BASE; ?>/kennisbank.php" class="hz-btn hz-btn--outline">Wissen</a>
    <?php endif; ?>
</form>

<?php if (empty($articles)): ?>
    <div class="hz-card">
        <p class="text-sm text-slate-400">Geen artikelen gevonden voor deze zoekopdracht.</p>
    </div>
<?php else: ?>
    <div class="space-y-8">
        <?php foreach ($grouped as $cat => $items): ?>
            <div>
                <h2 class="text-lg font-bold text-slate-800 mb-3"><?php echo e($cat); ?></h2>
                <div class="space-y-3">
                    <?php foreach ($items as $art): ?>
                        <details class="hz-card">
                            <summary class="font-medium text-slate-800 cursor-pointer flex items-center justify-between">
                                <?php echo e($art['title']); ?>
                            </summary>
                            <p class="text-sm text-slate-600 mt-3 whitespace-pre-wrap"><?php echo e($art['body']); ?></p>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
