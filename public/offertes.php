<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

/* ─── POST-acties ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    /* Elke UPDATE/SELECT hieronder filtert expliciet op account_id = $accountId,
       zodat een klant nooit via een gemanipuleerd id een offerte van een ander bedrijf kan raken. */
    $stmt = $pdo->prepare("SELECT * FROM kp_quotes WHERE id = ? AND account_id = ?");
    $stmt->execute([$id, $accountId]);
    $quote = $stmt->fetch();

    if ($quote && $act === 'sign_quote' && $quote['status'] === 'verzonden') {
        $signedBy = trim((string) ($_POST['signed_name'] ?? ''));
        $agree = isset($_POST['agree']);
        if ($signedBy !== '' && $agree) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $pdo->prepare("UPDATE kp_quotes SET status = 'geaccepteerd', signed_by = ?, signed_at = NOW(), signed_ip = ? WHERE id = ? AND account_id = ?")
                ->execute([$signedBy, $ip, $id, $accountId]);

            $number = 'INV-' . $accountId . '-' . random_int(3000, 9999);
            $pdo->prepare("INSERT INTO kp_invoices (account_id, quote_id, number, amount, status, due_date) VALUES (?,?,?,?, 'open', ?)")
                ->execute([$accountId, $id, $number, $quote['amount'], date('Y-m-d', strtotime('+14 days'))]);

            logAudit('accepted', 'quote', $id, "Offerte '{$quote['title']}' digitaal geaccordeerd door {$signedBy} (IP {$ip})");
            createNotification($accountId, "Offerte '{$quote['title']}' is geaccepteerd — factuur {$number} is aangemaakt.", BASE . '/facturen.php');
            flash('success', 'Offerte geaccepteerd. Bedankt! We hebben direct een factuur klaargezet.');
        } else {
            flash('error', 'Vul je naam in en bevestig het akkoord om te ondertekenen.');
        }
    }

    if ($quote && $act === 'reject_quote' && $quote['status'] === 'verzonden') {
        $pdo->prepare("UPDATE kp_quotes SET status = 'afgewezen' WHERE id = ? AND account_id = ?")->execute([$id, $accountId]);
        logAudit('rejected', 'quote', $id, "Offerte '{$quote['title']}' afgewezen");
        createNotification($accountId, "Offerte '{$quote['title']}' is afgewezen.", BASE . '/offertes.php');
        flash('info', 'Offerte afgewezen.');
    }

    header('Location: ' . BASE . '/offertes.php' . ($quote ? '?action=detail&id=' . $id : ''));
    exit;
}

$filterStatus = $_GET['status'] ?? '';
$where = ['account_id = ?'];
$params = [$accountId];
if (in_array($filterStatus, ['concept', 'verzonden', 'geaccepteerd', 'afgewezen'], true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM kp_quotes WHERE {$whereSql} ORDER BY created_at DESC");
$stmt->execute($params);
$quotes = $stmt->fetchAll();

$detailQuote = null;
if (($_GET['action'] ?? '') === 'detail' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM kp_quotes WHERE id = ? AND account_id = ?");
    $stmt->execute([(int) $_GET['id'], $accountId]);
    $detailQuote = $stmt->fetch();
}

$statusColors = ['concept' => 'gray', 'verzonden' => 'orange', 'geaccepteerd' => 'green', 'afgewezen' => 'red'];
$statusLabels = ['concept' => 'Concept', 'verzonden' => 'Ter beoordeling', 'geaccepteerd' => 'Geaccepteerd', 'afgewezen' => 'Afgewezen'];

$pageTitle = 'Offertes';
$activeNav = 'offertes';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Offertes', 'url' => $detailQuote ? (BASE . '/offertes.php') : null]];
if ($detailQuote) {
    $breadcrumbs[] = ['label' => $detailQuote['title'], 'url' => null];
}
require __DIR__ . '/partials/nav.php';
?>

<?php if ($detailQuote): ?>
<div class="hz-card max-w-2xl">
    <div class="hz-card__header">
        <div>
            <h1 class="text-xl font-bold text-slate-800"><?php echo e($detailQuote['title']); ?></h1>
            <span class="hz-badge hz-badge--<?php echo $statusColors[$detailQuote['status']]; ?> mt-1"><?php echo $statusLabels[$detailQuote['status']]; ?></span>
        </div>
        <div class="text-2xl font-bold" style="color:var(--hz-primary);">&euro;<?php echo number_format((float) $detailQuote['amount'], 2, ',', '.'); ?></div>
    </div>
    <p class="text-sm text-slate-600 whitespace-pre-wrap mb-4"><?php echo e($detailQuote['description']); ?></p>
    <p class="text-xs text-slate-400 mb-4">Geldig tot <?php echo $detailQuote['valid_until'] ? date('d-m-Y', strtotime($detailQuote['valid_until'])) : '-'; ?></p>

    <?php if ($detailQuote['status'] === 'geaccepteerd'): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 text-sm">
            <p class="font-medium">Digitaal geaccordeerd</p>
            <p>Door: <?php echo e($detailQuote['signed_by']); ?></p>
            <p>Op: <?php echo date('d-m-Y H:i', strtotime($detailQuote['signed_at'])); ?> vanaf IP <?php echo e($detailQuote['signed_ip']); ?></p>
            <p class="text-xs text-green-600 mt-1">Dit geldt als eenvoudig digitaal bewijs van akkoord (naam + tijdstempel + IP-adres) — geen juridisch bindende e-signature-provider.</p>
        </div>
    <?php elseif ($detailQuote['status'] === 'afgewezen'): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 text-sm">Deze offerte is afgewezen.</div>
    <?php elseif ($detailQuote['status'] === 'verzonden'): ?>
        <form method="POST" class="border border-slate-200 rounded-lg p-4 space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="sign_quote">
            <input type="hidden" name="id" value="<?php echo (int) $detailQuote['id']; ?>">
            <p class="text-sm font-medium text-slate-700">Digitaal accorderen</p>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Typ je volledige naam ter bevestiging</label>
                <input type="text" name="signed_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="agree" required class="hz-checkbox">
                Ik ga akkoord met deze offerte. Naam, tijdstempel en IP-adres worden vastgelegd als bewijs.
            </label>
            <div class="flex gap-2">
                <button type="submit" class="hz-btn hz-btn--primary">Accorderen</button>
                <button type="submit" name="action" value="reject_quote" class="hz-btn hz-btn--secondary" data-hz-confirm="Weet je zeker dat je deze offerte wilt afwijzen?">Afwijzen</button>
            </div>
        </form>
    <?php else: ?>
        <p class="text-sm text-slate-400">Deze offerte is nog een concept en nog niet verzonden ter beoordeling.</p>
    <?php endif; ?>
</div>
<a href="<?php echo BASE; ?>/offertes.php" class="inline-block mt-4 text-sm" style="color:var(--hz-primary);">&larr; Terug naar overzicht</a>

<?php else: ?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <h1 class="text-2xl font-bold text-slate-800">Offertes</h1>
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
            <option value="">Alle statussen</option>
            <?php foreach ($statusLabels as $k => $label): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="hz-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
    <?php if (empty($quotes)): ?>
        <p class="text-slate-400 text-sm">Geen offertes gevonden.</p>
    <?php endif; ?>
    <?php foreach ($quotes as $q): ?>
        <a href="<?php echo BASE; ?>/offertes.php?action=detail&id=<?php echo (int) $q['id']; ?>" class="hz-card block hover:shadow-md transition">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-slate-800"><?php echo e($q['title']); ?></h3>
                <span class="hz-badge hz-badge--<?php echo $statusColors[$q['status']]; ?>"><?php echo $statusLabels[$q['status']]; ?></span>
            </div>
            <p class="text-sm text-slate-500 line-clamp-2 mb-3"><?php echo e(mb_substr($q['description'] ?? '', 0, 100)); ?></p>
            <div class="text-lg font-bold" style="color:var(--hz-primary);">&euro;<?php echo number_format((float) $q['amount'], 2, ',', '.'); ?></div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
