<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    /* account_id = $accountId in de WHERE voorkomt dat een klant de deadline van
       het project van een ander bedrijf kan bevestigen via een geraden id. */
    $stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE id = ? AND account_id = ?");
    $stmt->execute([$id, $accountId]);
    $project = $stmt->fetch();

    if ($project && $act === 'confirm_deadline') {
        $pdo->prepare("UPDATE kp_projects SET deadline_confirmed = 1 WHERE id = ? AND account_id = ?")->execute([$id, $accountId]);
        logAudit('confirmed', 'project', $id, "Deadline bevestigd voor project '{$project['name']}'");
        flash('success', 'Deadline bevestigd.');
    }

    header('Location: ' . BASE . '/projecten.php?action=detail&id=' . $id);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE account_id = ? ORDER BY FIELD(status,'lopend','gepland','afgerond'), created_at DESC");
$stmt->execute([$accountId]);
$projects = $stmt->fetchAll();

$detailProject = null;
$detailDocs = [];
if (($_GET['action'] ?? '') === 'detail' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE id = ? AND account_id = ?");
    $stmt->execute([(int) $_GET['id'], $accountId]);
    $detailProject = $stmt->fetch();
    if ($detailProject) {
        $stmt = $pdo->prepare("SELECT * FROM kp_documents WHERE project_id = ? AND account_id = ? ORDER BY created_at DESC");
        $stmt->execute([$detailProject['id'], $accountId]);
        $detailDocs = $stmt->fetchAll();
    }
}

$statusColors = ['gepland' => 'gray', 'lopend' => 'orange', 'afgerond' => 'green'];
$statusLabels = ['gepland' => 'Gepland', 'lopend' => 'Lopend', 'afgerond' => 'Afgerond'];

$pageTitle = 'Projecten';
$activeNav = 'projecten';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Projecten', 'url' => $detailProject ? (BASE . '/projecten.php') : null]];
if ($detailProject) {
    $breadcrumbs[] = ['label' => $detailProject['name'], 'url' => null];
}
require __DIR__ . '/partials/nav.php';
?>

<?php if ($detailProject): ?>
<div class="hz-card max-w-2xl">
    <div class="hz-card__header">
        <h1 class="text-xl font-bold text-slate-800"><?php echo e($detailProject['name']); ?></h1>
        <span class="hz-badge hz-badge--<?php echo $statusColors[$detailProject['status']]; ?>"><?php echo $statusLabels[$detailProject['status']]; ?></span>
    </div>
    <p class="text-sm text-slate-600 mb-4"><?php echo e($detailProject['description']); ?></p>

    <div class="mb-4">
        <div class="flex items-center justify-between text-sm mb-1">
            <span class="text-slate-500">Voortgang</span>
            <span class="font-medium"><?php echo (int) $detailProject['progress_percent']; ?>%</span>
        </div>
        <div class="w-full h-2.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full" style="width:<?php echo (int) $detailProject['progress_percent']; ?>%; background:var(--hz-primary);"></div>
        </div>
    </div>

    <?php if ($detailProject['deadline']): ?>
    <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-lg p-4 mb-4">
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide">Deadline</p>
            <p class="font-medium text-slate-700"><?php echo date('d-m-Y', strtotime($detailProject['deadline'])); ?></p>
        </div>
        <?php if ($detailProject['deadline_confirmed']): ?>
            <span class="hz-badge hz-badge--green">Bevestigd door jou</span>
        <?php else: ?>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="confirm_deadline">
                <input type="hidden" name="id" value="<?php echo (int) $detailProject['id']; ?>">
                <button type="submit" class="hz-btn hz-btn--primary">Bevestig deadline</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h3 class="font-bold text-slate-800 mb-2 text-sm">Gekoppelde documenten</h3>
    <?php if (empty($detailDocs)): ?>
        <p class="text-sm text-slate-400 mb-2">Geen documenten gekoppeld aan dit project.</p>
    <?php else: ?>
        <ul class="text-sm space-y-1 mb-2">
            <?php foreach ($detailDocs as $d): ?>
                <li><a href="<?php echo BASE; ?>/documenten.php#doc<?php echo (int) $d['id']; ?>" style="color:var(--hz-primary);">&#128196; <?php echo e($d['filename']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($detailProject['status'] === 'afgerond'): ?>
        <a href="<?php echo BASE; ?>/feedback.php" class="hz-btn hz-btn--outline mt-2">Feedback geven op dit project</a>
    <?php endif; ?>
</div>
<a href="<?php echo BASE; ?>/projecten.php" class="inline-block mt-4 text-sm" style="color:var(--hz-primary);">&larr; Terug naar overzicht</a>

<?php else: ?>
<h1 class="text-2xl font-bold text-slate-800 mb-6">Projecten</h1>
<div class="hz-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
    <?php if (empty($projects)): ?><p class="text-slate-400 text-sm">Geen projecten gevonden.</p><?php endif; ?>
    <?php foreach ($projects as $p): ?>
        <a href="<?php echo BASE; ?>/projecten.php?action=detail&id=<?php echo (int) $p['id']; ?>" class="hz-card block hover:shadow-md transition">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-slate-800"><?php echo e($p['name']); ?></h3>
                <span class="hz-badge hz-badge--<?php echo $statusColors[$p['status']]; ?>"><?php echo $statusLabels[$p['status']]; ?></span>
            </div>
            <div class="w-full h-2 rounded-full bg-slate-100 overflow-hidden mb-2">
                <div class="h-full rounded-full" style="width:<?php echo (int) $p['progress_percent']; ?>%; background:var(--hz-primary);"></div>
            </div>
            <p class="text-xs text-slate-400"><?php echo (int) $p['progress_percent']; ?>% voltooid<?php echo $p['deadline'] ? ' &middot; deadline ' . date('d-m-Y', strtotime($p['deadline'])) : ''; ?></p>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
