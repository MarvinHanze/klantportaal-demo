<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();
$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'change_password') {
        /* account_id in de WHERE voorkomt dat iemand via een gemanipuleerd user-id
           het wachtwoord van een gebruiker bij een ander account kan wijzigen. */
        $stmt = $pdo->prepare("SELECT * FROM kp_users WHERE id = ? AND account_id = ?");
        $stmt->execute([$userId, $accountId]);
        $user = $stmt->fetch();

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!$user || !password_verify($current, $user['password_hash'])) {
            flash('error', 'Huidig wachtwoord is onjuist.');
        } elseif ($new !== $confirm) {
            flash('error', 'Nieuw wachtwoord en bevestiging komen niet overeen.');
        } else {
            $errors = validate_password_strength($new);
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE kp_users SET password_hash = ? WHERE id = ? AND account_id = ?")
                    ->execute([$hash, $userId, $accountId]);
                logAudit('changed_password', 'account', $userId, 'Wachtwoord gewijzigd');
                flash('success', 'Wachtwoord succesvol gewijzigd.');
            }
        }
        header('Location: ' . BASE . '/profiel.php');
        exit;
    }

    if ($act === 'disable_2fa') {
        $pdo->prepare("UPDATE kp_users SET totp_enabled = 0, totp_secret = NULL WHERE id = ? AND account_id = ?")
            ->execute([$userId, $accountId]);
        logAudit('disabled_2fa', 'account', $userId, 'Tweestapsverificatie uitgeschakeld');
        flash('success', 'Tweestapsverificatie uitgeschakeld. Bij de volgende login wordt opnieuw een sleutel aangemaakt.');
        header('Location: ' . BASE . '/profiel.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM kp_users WHERE id = ? AND account_id = ?");
$stmt->execute([$userId, $accountId]);
$user = $stmt->fetch();

$pageTitle = 'Profiel & Beveiliging';
$activeNav = 'profiel';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Profiel & Beveiliging', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-6">Profiel & Beveiliging</h1>

<?php if ($user): ?>
<div class="hz-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:1.5rem;">

    <div class="hz-card">
        <h3 class="font-bold text-slate-800 mb-3">Accountgegevens</h3>
        <div class="text-sm space-y-2">
            <div>
                <span class="text-slate-400 block text-xs uppercase tracking-wide">Naam</span>
                <span class="font-medium text-slate-700"><?php echo e($user['name']); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block text-xs uppercase tracking-wide">E-mailadres</span>
                <span class="font-medium text-slate-700"><?php echo e($user['email']); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block text-xs uppercase tracking-wide">Bedrijf</span>
                <span class="font-medium text-slate-700"><?php echo e(currentAccount()['company_name'] ?? ''); ?></span>
            </div>
        </div>
        <p class="text-xs text-slate-400 mt-4">Naam en bedrijf kunnen in deze demo niet zelf gewijzigd worden — neem contact op via een support-ticket.</p>
    </div>

    <div class="hz-card">
        <h3 class="font-bold text-slate-800 mb-3">Tweestapsverificatie (2FA)</h3>
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm text-slate-600">Status</span>
            <?php if (!empty($user['totp_enabled'])): ?>
                <span class="hz-badge hz-badge--green">Ingeschakeld</span>
            <?php else: ?>
                <span class="hz-badge hz-badge--gray">Uitgeschakeld</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($user['totp_enabled'])): ?>
            <p class="text-xs text-slate-400 mb-3">2FA is verplicht voor het inloggen op dit account. Je kunt het hier uitschakelen; bij de volgende login wordt dan opnieuw een nieuwe sleutel aangemaakt en moet je 2FA opnieuw instellen.</p>
            <form method="POST" data-hz-confirm="Weet je zeker dat je 2FA wilt uitschakelen?">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="disable_2fa">
                <button type="submit" class="hz-btn hz-btn--danger">2FA uitschakelen</button>
            </form>
        <?php else: ?>
            <p class="text-xs text-slate-400">2FA wordt automatisch opnieuw ingesteld bij je volgende login.</p>
        <?php endif; ?>
    </div>

    <div class="hz-card">
        <h3 class="font-bold text-slate-800 mb-3">Wachtwoord wijzigen</h3>
        <form method="POST" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="change_password">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Huidig wachtwoord</label>
                <input type="password" name="current_password" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Nieuw wachtwoord</label>
                <input type="password" name="new_password" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Bevestig nieuw wachtwoord</label>
                <input type="password" name="confirm_password" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <p class="text-xs text-slate-400">Minimaal 8 tekens, met een hoofdletter en een cijfer.</p>
            <button type="submit" class="hz-btn hz-btn--primary">Wachtwoord wijzigen</button>
        </form>
    </div>

</div>
<?php else: ?>
    <div class="hz-card"><p class="text-sm text-slate-400">Gebruiker niet gevonden.</p></div>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
