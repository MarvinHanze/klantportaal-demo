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

    if ($act === 'toggle_crm') {
        $stmt = $pdo->prepare("SELECT * FROM kp_crm_settings WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $settings = $stmt->fetch();

        if ($settings) {
            $newEnabled = $settings['enabled'] ? 0 : 1;
            if ($newEnabled) {
                $pdo->prepare("UPDATE kp_crm_settings SET enabled = 1, last_sync_at = NOW() WHERE account_id = ?")->execute([$accountId]);
                logAudit('enabled', 'crm_integration', (int) $settings['id'], "CRM-integratie ({$settings['provider']}) ingeschakeld");
                flash('success', 'CRM-integratie ingeschakeld en gesynchroniseerd.');
            } else {
                $pdo->prepare("UPDATE kp_crm_settings SET enabled = 0 WHERE account_id = ?")->execute([$accountId]);
                logAudit('disabled', 'crm_integration', (int) $settings['id'], "CRM-integratie ({$settings['provider']}) uitgeschakeld");
                flash('success', 'CRM-integratie uitgeschakeld.');
            }
        }
        header('Location: ' . BASE . '/instellingen.php');
        exit;
    }

    if ($act === 'sync_now') {
        $stmt = $pdo->prepare("SELECT * FROM kp_crm_settings WHERE account_id = ? AND enabled = 1");
        $stmt->execute([$accountId]);
        $settings = $stmt->fetch();
        if ($settings) {
            $pdo->prepare("UPDATE kp_crm_settings SET last_sync_at = NOW() WHERE account_id = ?")->execute([$accountId]);
            logAudit('synced', 'crm_integration', (int) $settings['id'], "Handmatige sync uitgevoerd ({$settings['provider']})");
            flash('success', 'Synchronisatie voltooid (mock, geen echte externe API).');
        }
        header('Location: ' . BASE . '/instellingen.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM kp_crm_settings WHERE account_id = ?");
$stmt->execute([$accountId]);
$crmSettings = $stmt->fetch();

$providerLabels = ['hubspot' => 'HubSpot', 'salesforce' => 'Salesforce', 'pipedrive' => 'Pipedrive'];

$pageTitle = 'Integraties';
$activeNav = 'instellingen';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Integraties', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-2">Integraties</h1>
<p class="text-slate-500 text-sm mb-6">Beheer koppelingen met externe systemen. Dit is een demo-omgeving: synchronisaties zijn gesimuleerd en verbinden niet met een echte externe API.</p>

<?php if ($crmSettings): ?>
<div class="hz-card max-w-xl">
    <div class="hz-card__header">
        <div>
            <h3 class="font-bold text-slate-800"><?php echo e($providerLabels[$crmSettings['provider']] ?? ucfirst($crmSettings['provider'])); ?> CRM-koppeling</h3>
            <p class="text-sm text-slate-500">Synchroniseert klantgegevens, offertes en facturen automatisch.</p>
        </div>
        <?php if ($crmSettings['enabled']): ?>
            <span class="hz-badge hz-badge--green">Actief</span>
        <?php else: ?>
            <span class="hz-badge hz-badge--gray">Uitgeschakeld</span>
        <?php endif; ?>
    </div>

    <div class="flex items-center justify-between border-t border-slate-100 pt-4 mb-4">
        <div>
            <p class="text-sm font-medium text-slate-700">Integratie ingeschakeld</p>
            <p class="text-xs text-slate-400">Zet de koppeling aan of uit.</p>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_crm">
            <label class="hz-toggle">
                <input type="checkbox" onchange="this.form.submit()" <?php echo $crmSettings['enabled'] ? 'checked' : ''; ?>>
                <span class="hz-toggle__track"></span>
            </label>
        </form>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-4 text-sm">
        <div class="flex items-center justify-between">
            <span class="text-slate-500">Laatste synchronisatie</span>
            <span class="font-medium text-slate-700">
                <?php echo $crmSettings['last_sync_at'] ? date('d-m-Y H:i', strtotime($crmSettings['last_sync_at'])) : 'Nog niet gesynchroniseerd'; ?>
            </span>
        </div>
    </div>

    <?php if ($crmSettings['enabled']): ?>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="hz-btn hz-btn--secondary">Nu synchroniseren</button>
    </form>
    <?php endif; ?>
</div>
<?php else: ?>
    <div class="hz-card"><p class="text-sm text-slate-400">Geen integratie-instellingen gevonden.</p></div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
