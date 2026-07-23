<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

$action = trim((string) ($_GET['action'] ?? ''));
$entityType = trim((string) ($_GET['entity_type'] ?? ''));

$where = ['account_id = ?'];
$params = [$accountId];
if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($entityType !== '') {
    $where[] = 'entity_type = ?';
    $params[] = $entityType;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$actions = $pdo->prepare("SELECT DISTINCT action FROM kp_audit_log WHERE account_id = ? ORDER BY action");
$actions->execute([$accountId]);
$actions = $actions->fetchAll(PDO::FETCH_COLUMN);

$entityTypes = $pdo->prepare("SELECT DISTINCT entity_type FROM kp_audit_log WHERE account_id = ? ORDER BY entity_type");
$entityTypes->execute([$accountId]);
$entityTypes = $entityTypes->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT * FROM kp_audit_log {$whereSql} ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$logs = $stmt->fetchAll();

function audit_badge_color(string $action): string
{
    return match (true) {
        in_array($action, ['login'], true) => 'gray',
        in_array($action, ['created', 'submitted', 'uploaded', 'accepted', 'confirmed', 'paid'], true) => 'green',
        in_array($action, ['status_changed', 'updated'], true) => 'orange',
        in_array($action, ['deleted', 'rejected'], true) => 'red',
        default => 'gray',
    };
}

$pageTitle = 'Audit log';
$activeNav = 'audit';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Audit log', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-2">Audit log</h1>
<p class="text-slate-500 text-sm mb-6">Overzicht van belangrijke acties binnen je account: logins, wijzigingen en indieningen. Alleen zichtbaar voor jouw bedrijf.</p>

<form method="GET" class="flex flex-wrap gap-3 mb-6">
    <select name="action" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Alle acties</option>
        <?php foreach ($actions as $a): ?>
            <option value="<?php echo e($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $a))); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="entity_type" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Alle onderdelen</option>
        <?php foreach ($entityTypes as $t): ?>
            <option value="<?php echo e($t); ?>" <?php echo $entityType === $t ? 'selected' : ''; ?>><?php echo e(ucfirst($t)); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="hz-btn hz-btn--secondary">Filteren</button>
    <?php if ($action !== '' || $entityType !== ''): ?>
        <a href="<?php echo BASE; ?>/audit.php" class="hz-btn hz-btn--outline">Wissen</a>
    <?php endif; ?>
</form>

<div class="hz-card">
<div class="overflow-x-auto">
<table class="hz-table w-full">
    <thead><tr><th>Datum/tijd</th><th>Actie</th><th>Onderdeel</th><th>Details</th><th>Gebruiker</th><th>IP-adres</th></tr></thead>
    <tbody>
        <?php if (empty($logs)): ?><tr><td colspan="6" class="text-center text-slate-400 py-8">Geen logregels gevonden.</td></tr><?php endif; ?>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="text-slate-400 whitespace-nowrap"><?php echo date('d-m-Y H:i', strtotime($l['created_at'])); ?></td>
                <td><span class="hz-badge hz-badge--<?php echo audit_badge_color($l['action']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $l['action']))); ?></span></td>
                <td class="text-slate-600"><?php echo e(ucfirst($l['entity_type'])); ?><?php echo $l['entity_id'] ? ' #' . (int) $l['entity_id'] : ''; ?></td>
                <td class="text-slate-600"><?php echo e($l['details']); ?></td>
                <td class="text-slate-500"><?php echo e($l['user_email']); ?></td>
                <td class="text-slate-400 font-mono text-xs"><?php echo e($l['ip_address']); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
