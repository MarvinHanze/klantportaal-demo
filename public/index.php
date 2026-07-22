<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();

/* ─── POST-acties (altijd account-gescoped) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'mark_notifications_read') {
        $pdo->prepare("UPDATE kp_notifications SET is_read = 1 WHERE account_id = ?")->execute([$accountId]);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? (BASE . '/index.php')));
        exit;
    }
}

$account = currentAccount();

/* ─── Stats, strikt gescoped op account_id van de ingelogde sessie ─── */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM kp_tickets WHERE account_id = ? AND status != 'opgelost'");
$stmt->execute([$accountId]);
$openTickets = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM kp_invoices WHERE account_id = ? AND status IN ('open','te_laat')");
$stmt->execute([$accountId]);
[$openInvoiceCount, $openInvoiceTotal] = $stmt->fetch(PDO::FETCH_NUM);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM kp_projects WHERE account_id = ? AND status = 'lopend'");
$stmt->execute([$accountId]);
$activeProjects = (int) $stmt->fetchColumn();

$unread = unreadNotificationCount($accountId);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM kp_quotes WHERE account_id = ? AND status = 'verzonden'");
$stmt->execute([$accountId]);
$pendingQuotes = (int) $stmt->fetchColumn();

/* ─── Recente activiteit ─── */
$stmt = $pdo->prepare("SELECT * FROM kp_notifications WHERE account_id = ? ORDER BY created_at DESC LIMIT 6");
$stmt->execute([$accountId]);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE account_id = ? AND status = 'lopend' ORDER BY deadline ASC LIMIT 3");
$stmt->execute([$accountId]);
$runningProjects = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE account_id = ? AND status = 'afgerond'
                       AND id NOT IN (SELECT project_id FROM kp_nps_feedback WHERE account_id = ?) LIMIT 1");
$stmt->execute([$accountId, $accountId]);
$feedbackDue = $stmt->fetch();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$breadcrumbs = [['label' => 'Dashboard', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-800">Welkom, <?php echo e($account['company_name'] ?? ''); ?> &#128075;</h1>
    <p class="text-slate-500 text-sm mt-1">Hier is een overzicht van je lopende zaken bij ons.</p>
</div>

<?php if ($feedbackDue): ?>
<div class="hz-card mb-6" style="border-left:4px solid var(--hz-primary);">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <p class="font-medium text-slate-800">Project '<?php echo e($feedbackDue['name']); ?>' is afgerond.</p>
            <p class="text-sm text-slate-500">Vertel ons hoe we het gedaan hebben — het kost je 30 seconden.</p>
        </div>
        <a href="<?php echo BASE; ?>/feedback.php" class="hz-btn hz-btn--primary">Vragenlijst invullen</a>
    </div>
</div>
<?php endif; ?>

<div class="hz-grid hz-grid--3 mb-8" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
    <a href="<?php echo BASE; ?>/tickets.php" class="hz-card hz-card--stat relative">
        <?php if ($openTickets > 0): ?><span class="kp-notif-dot" style="top:.5rem; right:.5rem;"><?php echo $openTickets; ?></span><?php endif; ?>
        <div class="hz-card__label">Openstaande tickets</div>
        <div class="hz-card__value"><?php echo $openTickets; ?></div>
    </a>
    <a href="<?php echo BASE; ?>/facturen.php" class="hz-card hz-card--stat relative">
        <?php if ($openInvoiceCount > 0): ?><span class="kp-notif-dot" style="top:.5rem; right:.5rem;"><?php echo (int) $openInvoiceCount; ?></span><?php endif; ?>
        <div class="hz-card__label">Openstaande facturen</div>
        <div class="hz-card__value">&euro;<?php echo number_format((float) $openInvoiceTotal, 2, ',', '.'); ?></div>
    </a>
    <a href="<?php echo BASE; ?>/projecten.php" class="hz-card hz-card--stat relative">
        <div class="hz-card__label">Lopende projecten</div>
        <div class="hz-card__value"><?php echo $activeProjects; ?></div>
    </a>
    <a href="<?php echo BASE; ?>/offertes.php" class="hz-card hz-card--stat relative">
        <?php if ($pendingQuotes > 0): ?><span class="kp-notif-dot" style="top:.5rem; right:.5rem;"><?php echo $pendingQuotes; ?></span><?php endif; ?>
        <div class="hz-card__label">Offertes ter beoordeling</div>
        <div class="hz-card__value"><?php echo $pendingQuotes; ?></div>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="hz-card">
        <div class="hz-card__header">
            <h2 class="font-bold text-slate-800">Lopende projecten</h2>
            <a href="<?php echo BASE; ?>/projecten.php" class="text-xs" style="color:var(--hz-primary);">Alles bekijken &rarr;</a>
        </div>
        <?php if (empty($runningProjects)): ?>
            <p class="text-sm text-slate-400">Geen lopende projecten.</p>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($runningProjects as $p): ?>
                    <div>
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-medium text-slate-700"><?php echo e($p['name']); ?></span>
                            <span class="text-slate-400"><?php echo (int) $p['progress_percent']; ?>%</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full rounded-full" style="width:<?php echo (int) $p['progress_percent']; ?>%; background:var(--hz-primary);"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="hz-card">
        <div class="hz-card__header">
            <h2 class="font-bold text-slate-800">Recente notificaties</h2>
            <?php if ($unread > 0): ?>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="mark_notifications_read">
                    <button type="submit" class="text-xs" style="color:var(--hz-primary);">Alles gelezen</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (empty($notifications)): ?>
            <p class="text-sm text-slate-400">Geen notificaties.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($notifications as $n): ?>
                    <a href="<?php echo e($n['link'] ?? '#'); ?>" class="block text-sm">
                        <span class="text-slate-700<?php echo $n['is_read'] ? '' : ' font-semibold'; ?>"><?php echo e($n['message']); ?></span>
                        <span class="block text-xs text-slate-400"><?php echo date('d-m-Y H:i', strtotime($n['created_at'])); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
