<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'submit_feedback') {
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $score = (int) ($_POST['score'] ?? -1);
        $comment = trim((string) ($_POST['comment'] ?? ''));

        /* account_id in de WHERE voorkomt dat een klant feedback kan koppelen aan
           een project dat niet van zijn eigen bedrijf is. */
        $stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE id = ? AND account_id = ? AND status = 'afgerond'");
        $stmt->execute([$projectId, $accountId]);
        $project = $stmt->fetch();

        $already = $pdo->prepare("SELECT id FROM kp_nps_feedback WHERE project_id = ? AND account_id = ?");
        $already->execute([$projectId, $accountId]);

        if ($project && $score >= 1 && $score <= 10 && !$already->fetch()) {
            $pdo->prepare("INSERT INTO kp_nps_feedback (account_id, project_id, score, comment) VALUES (?,?,?,?)")
                ->execute([$accountId, $projectId, $score, $comment !== '' ? $comment : null]);
            logAudit('submitted', 'feedback', $projectId, "NPS-feedback (score {$score}) ingediend voor project '{$project['name']}'");
            flash('success', 'Bedankt voor je feedback!');
        } else {
            flash('error', 'Feedback kon niet worden opgeslagen.');
        }
        header('Location: ' . BASE . '/feedback.php');
        exit;
    }
}

/* Afgeronde projecten die nog GEEN feedback hebben. */
$stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE account_id = ? AND status = 'afgerond'
                       AND id NOT IN (SELECT project_id FROM kp_nps_feedback WHERE account_id = ?)
                       ORDER BY created_at DESC");
$stmt->execute([$accountId, $accountId]);
$pendingProjects = $stmt->fetchAll();

/* Reeds ingevulde feedback, met projectnaam. */
$stmt = $pdo->prepare("SELECT f.*, p.name AS project_name FROM kp_nps_feedback f
                       JOIN kp_projects p ON p.id = f.project_id
                       WHERE f.account_id = ? ORDER BY f.created_at DESC");
$stmt->execute([$accountId]);
$submittedFeedback = $stmt->fetchAll();

function nps_badge_color(int $score): string
{
    if ($score >= 9) {
        return 'green';
    }
    if ($score >= 7) {
        return 'orange';
    }
    return 'red';
}

$pageTitle = 'Feedback';
$activeNav = 'feedback';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Feedback', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-2">Feedback</h1>
<p class="text-slate-500 text-sm mb-6">Vertel ons hoe tevreden je bent over afgeronde projecten (NPS-score 1-10).</p>

<?php if (empty($pendingProjects)): ?>
    <div class="hz-card mb-8">
        <p class="text-sm text-slate-400">Er staan momenteel geen afgeronde projecten open om feedback op te geven. Zodra een nieuw project wordt afgerond, verschijnt hier een uitnodiging.</p>
    </div>
<?php else: ?>
    <div class="space-y-4 mb-8">
        <?php foreach ($pendingProjects as $p): ?>
            <div class="hz-card">
                <div class="flex items-start justify-between mb-3 flex-wrap gap-2">
                    <div>
                        <h3 class="font-bold text-slate-800"><?php echo e($p['name']); ?></h3>
                        <p class="text-sm text-slate-500"><?php echo e($p['description']); ?></p>
                    </div>
                    <span class="hz-badge hz-badge--green">Afgerond</span>
                </div>
                <form method="POST" class="mt-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="submit_feedback">
                    <input type="hidden" name="project_id" value="<?php echo (int) $p['id']; ?>">
                    <label class="block text-xs text-slate-500 mb-2">Hoe waarschijnlijk is het dat je ons zou aanbevelen? (1 = zeer onwaarschijnlijk, 10 = zeer waarschijnlijk)</label>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="score" value="<?php echo $i; ?>" required class="peer sr-only">
                                <span class="hz-badge hz-badge--gray peer-checked:hz-badge--green inline-flex items-center justify-center w-9 h-9 rounded-full border border-slate-200 text-sm font-medium hover:border-slate-400" style="min-width:2.25rem;"><?php echo $i; ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                    <label class="block text-xs text-slate-500 mb-1">Opmerking (optioneel)</label>
                    <textarea name="comment" rows="2" placeholder="Wat vond je van de samenwerking?" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm mb-3"></textarea>
                    <button type="submit" class="hz-btn hz-btn--primary">Feedback versturen</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="text-lg font-bold text-slate-800 mb-3">Eerder ingediende feedback</h2>
<?php if (empty($submittedFeedback)): ?>
    <p class="text-sm text-slate-400">Je hebt nog geen feedback ingediend.</p>
<?php else: ?>
    <div class="hz-card">
        <div class="overflow-x-auto">
        <table class="hz-table w-full">
            <thead><tr><th>Project</th><th>Score</th><th>Opmerking</th><th>Datum</th></tr></thead>
            <tbody>
                <?php foreach ($submittedFeedback as $f): ?>
                    <tr>
                        <td class="font-medium"><?php echo e($f['project_name']); ?></td>
                        <td><span class="hz-badge hz-badge--<?php echo nps_badge_color((int) $f['score']); ?>"><?php echo (int) $f['score']; ?>/10</span></td>
                        <td class="text-slate-600"><?php echo $f['comment'] ? e($f['comment']) : '<span class="text-slate-300">—</span>'; ?></td>
                        <td class="text-slate-400"><?php echo date('d-m-Y', strtotime($f['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
