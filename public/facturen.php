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

    /* account_id = $accountId is verplicht in de WHERE, anders zou een klant via een
       aangepast id een factuur van een ander bedrijf kunnen "betalen" of bekijken. */
    $stmt = $pdo->prepare("SELECT * FROM kp_invoices WHERE id = ? AND account_id = ?");
    $stmt->execute([$id, $accountId]);
    $invoice = $stmt->fetch();

    if ($invoice && $act === 'pay_invoice' && in_array($invoice['status'], ['open', 'te_laat'], true)) {
        $pdo->prepare("UPDATE kp_invoices SET status = 'betaald', paid_at = NOW() WHERE id = ? AND account_id = ?")
            ->execute([$id, $accountId]);
        logAudit('paid', 'invoice', $id, "Factuur {$invoice['number']} betaald (mock checkout, geen echte betaalprovider)");
        createNotification($accountId, "Betaling voor factuur {$invoice['number']} verwerkt.", BASE . '/facturen.php');
        flash('success', "Factuur {$invoice['number']} is betaald. (Dit is een gesimuleerde betaalflow, geen echte Mollie/Stripe-koppeling.)");
    }

    header('Location: ' . BASE . '/facturen.php');
    exit;
}

$filterStatus = $_GET['status'] ?? '';
$where = ['account_id = ?'];
$params = [$accountId];
if (in_array($filterStatus, ['open', 'betaald', 'te_laat'], true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM kp_invoices WHERE {$whereSql} ORDER BY due_date DESC");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$statusColors = ['open' => 'orange', 'betaald' => 'green', 'te_laat' => 'red'];
$statusLabels = ['open' => 'Open', 'betaald' => 'Betaald', 'te_laat' => 'Te laat'];

$pageTitle = 'Facturen';
$activeNav = 'facturen';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Facturen', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <h1 class="text-2xl font-bold text-slate-800">Facturen</h1>
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
            <option value="">Alle statussen</option>
            <?php foreach ($statusLabels as $k => $label): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterStatus === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="hz-card">
<div class="overflow-x-auto">
<table class="hz-table w-full">
    <thead>
        <tr>
            <th>Nummer</th>
            <th>Bedrag</th>
            <th>Status</th>
            <th>Vervaldatum</th>
            <th class="text-right">Actie</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($invoices)): ?>
            <tr><td colspan="5" class="text-center text-slate-400 py-8">Geen facturen gevonden.</td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $inv): ?>
            <tr>
                <td class="font-medium"><?php echo e($inv['number']); ?></td>
                <td>&euro;<?php echo number_format((float) $inv['amount'], 2, ',', '.'); ?></td>
                <td><span class="hz-badge hz-badge--<?php echo $statusColors[$inv['status']]; ?>"><?php echo $statusLabels[$inv['status']]; ?></span></td>
                <td class="text-slate-500"><?php echo $inv['due_date'] ? date('d-m-Y', strtotime($inv['due_date'])) : '-'; ?></td>
                <td class="text-right">
                    <?php if (in_array($inv['status'], ['open', 'te_laat'], true)): ?>
                        <button data-hz-modal-open="payModal<?php echo (int) $inv['id']; ?>" class="hz-btn hz-btn--primary" style="padding:.4rem .8rem;">Betalen</button>
                    <?php else: ?>
                        <span class="text-xs text-slate-400">Betaald op <?php echo date('d-m-Y', strtotime($inv['paid_at'])); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php foreach ($invoices as $inv): ?>
    <?php if (in_array($inv['status'], ['open', 'te_laat'], true)): ?>
    <div class="hz-modal__backdrop" id="payModal<?php echo (int) $inv['id']; ?>">
        <div class="hz-modal">
            <div class="hz-modal__header">
                <h3 class="font-bold">Factuur <?php echo e($inv['number']); ?> betalen</h3>
                <button data-hz-modal-close class="hz-icon-btn" aria-label="Sluiten">&times;</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="pay_invoice">
                <input type="hidden" name="id" value="<?php echo (int) $inv['id']; ?>">
                <p class="text-sm text-slate-500 mb-3">Gesimuleerde betaalflow (mock) — er wordt geen echte betaalprovider aangeroepen.</p>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Kaartnummer</label>
                        <input type="text" placeholder="4242 4242 4242 4242" maxlength="19" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Vervaldatum</label>
                            <input type="text" placeholder="MM/JJ" maxlength="5" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">CVC</label>
                            <input type="text" placeholder="123" maxlength="4" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        </div>
                    </div>
                </div>
                <div class="hz-modal__footer">
                    <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                    <button type="submit" class="hz-btn hz-btn--primary">Bevestig betaling van &euro;<?php echo number_format((float) $inv['amount'], 2, ',', '.'); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
