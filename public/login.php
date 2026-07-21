<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
ensure_schema();
check_auto_reset();

if (!empty($_SESSION['authenticated'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

function finalize_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['account_id'] = (int) $user['account_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    unset($_SESSION['login_stage'], $_SESSION['login_user_id'], $_SESSION['totp_attempts']);
    logAudit('login', 'account', null, 'Ingelogd op klantportaal (2FA geverifieerd)');
    header('Location: ' . BASE . '/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'login') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');

        $stmt = db()->prepare("SELECT * FROM kp_users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['login_user_id'] = (int) $user['id'];
            if (empty($user['totp_enabled'])) {
                if (empty($user['totp_secret'])) {
                    $secret = totp_generate_secret();
                    db()->prepare("UPDATE kp_users SET totp_secret = ? WHERE id = ?")->execute([$secret, $user['id']]);
                }
                $_SESSION['login_stage'] = 'setup';
            } else {
                $_SESSION['login_stage'] = 'verify';
            }
            header('Location: ' . BASE . '/login.php');
            exit;
        }
        $error = 'Ongeldige inloggegevens.';
        $emailValue = $email;
    }

    if ($act === 'cancel') {
        unset($_SESSION['login_stage'], $_SESSION['login_user_id'], $_SESSION['totp_attempts']);
        header('Location: ' . BASE . '/login.php');
        exit;
    }

    if (($act === 'confirm_setup' || $act === 'verify_totp') && !empty($_SESSION['login_user_id'])) {
        $stmt = db()->prepare("SELECT * FROM kp_users WHERE id = ?");
        $stmt->execute([$_SESSION['login_user_id']]);
        $pendingUser = $stmt->fetch();

        if (!$pendingUser) {
            unset($_SESSION['login_stage'], $_SESSION['login_user_id']);
            header('Location: ' . BASE . '/login.php');
            exit;
        }

        $code = (string) ($_POST['totp_code'] ?? '');

        if ($act === 'confirm_setup' && ($_SESSION['login_stage'] ?? '') === 'setup') {
            if (totp_verify($pendingUser['totp_secret'], $code)) {
                db()->prepare("UPDATE kp_users SET totp_enabled = 1 WHERE id = ?")->execute([$pendingUser['id']]);
                finalize_login($pendingUser);
            }
            $error = 'Ongeldige of verlopen code. Probeer opnieuw.';
        }

        if ($act === 'verify_totp' && ($_SESSION['login_stage'] ?? '') === 'verify') {
            $_SESSION['totp_attempts'] = ($_SESSION['totp_attempts'] ?? 0) + 1;
            if ($_SESSION['totp_attempts'] > 6) {
                unset($_SESSION['login_stage'], $_SESSION['login_user_id'], $_SESSION['totp_attempts']);
                $error = 'Te veel mislukte pogingen. Log opnieuw in.';
            } elseif (totp_verify($pendingUser['totp_secret'], $code)) {
                finalize_login($pendingUser);
            } else {
                $error = 'Ongeldige verificatiecode.';
            }
        }
    }
}

$stage = $_SESSION['login_stage'] ?? null;
$pendingAccount = null;
$pendingSecret = null;
$pendingEmail = null;
$pendingUserFull = null;
if ($stage && !empty($_SESSION['login_user_id'])) {
    $stmt = db()->prepare("SELECT u.*, a.company_name, a.brand_color FROM kp_users u JOIN kp_accounts a ON a.id = u.account_id WHERE u.id = ?");
    $stmt->execute([$_SESSION['login_user_id']]);
    $pendingUserFull = $stmt->fetch();
    if (!$pendingUserFull) {
        $stage = null;
    } else {
        $pendingAccount = $pendingUserFull['company_name'];
        $pendingSecret = $pendingUserFull['totp_secret'];
        $pendingEmail = $pendingUserFull['email'];
    }
}
$brandColor = is_array($pendingUserFull) ? ($pendingUserFull['brand_color'] ?? '#7c3aed') : '#7c3aed';
?>
<!DOCTYPE html>
<html lang="nl" style="--kp-brand: <?php echo e($brandColor); ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klantportaal - Inloggen</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?php echo BASE; ?>/assets/css/components.css">
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center px-4">
<div class="w-full max-w-md">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <div class="flex items-center justify-center gap-3 mb-8">
            <svg class="w-10 h-10" style="color: var(--kp-brand);" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
            </svg>
            <h1 class="text-2xl font-bold text-slate-800">Klantportaal</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($stage === 'setup'): ?>
            <!-- ═══ 2FA-SETUP (verplicht bij eerste login) ═══ -->
            <p class="text-sm text-slate-500 mb-1">Welkom bij <strong><?php echo e($pendingAccount); ?></strong>.</p>
            <p class="text-sm text-slate-600 mb-4">Voor toegang tot het klantportaal is tweestapsverificatie (2FA) verplicht. Voeg onderstaande sleutel toe aan je authenticator-app (bv. Google Authenticator) en bevestig met de gegenereerde code.</p>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-4 text-sm space-y-2">
                <div>
                    <span class="text-slate-400 block text-xs uppercase tracking-wide">Account</span>
                    <span class="font-medium"><?php echo e($pendingEmail); ?></span>
                </div>
                <div>
                    <span class="text-slate-400 block text-xs uppercase tracking-wide">Geheime sleutel (base32)</span>
                    <span class="font-mono font-medium break-all"><?php echo e($pendingSecret); ?></span>
                </div>
                <div>
                    <span class="text-slate-400 block text-xs uppercase tracking-wide">otpauth-URI</span>
                    <span class="font-mono text-xs break-all text-slate-500"><?php echo e(totp_otpauth_uri($pendingSecret, $pendingEmail)); ?></span>
                </div>
            </div>
            <div class="hz-badge hz-badge--orange w-full block text-center py-2 mb-4">
                DEMO-modus: huidige geldige code is <strong class="font-mono"><?php echo e(totp_current_code($pendingSecret)); ?></strong> (ververst elke 30s) — in het echt zou dit alleen in je authenticator-app zichtbaar zijn.
            </div>
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="confirm_setup">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Bevestigingscode</label>
                    <input type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg outline-none tracking-widest text-center text-lg font-mono"
                           style="--tw-ring-color: var(--kp-brand);">
                </div>
                <button type="submit" class="hz-btn hz-btn--primary w-full justify-center">2FA activeren en inloggen</button>
            </form>
            <form method="POST" class="mt-2">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="w-full text-center text-xs text-slate-400 hover:text-slate-600 mt-2">Annuleren</button>
            </form>

        <?php elseif ($stage === 'verify'): ?>
            <!-- ═══ 2FA-CODE INVOEREN ═══ -->
            <p class="text-sm text-slate-500 mb-1">Welkom terug bij <strong><?php echo e($pendingAccount); ?></strong>.</p>
            <p class="text-sm text-slate-600 mb-4">Voer de 6-cijferige code uit je authenticator-app in.</p>
            <div class="hz-badge hz-badge--orange w-full block text-center py-2 mb-4">
                DEMO-modus: huidige geldige code is <strong class="font-mono"><?php echo e(totp_current_code($pendingSecret)); ?></strong>
            </div>
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="verify_totp">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Verificatiecode</label>
                    <input type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg outline-none tracking-widest text-center text-lg font-mono">
                </div>
                <button type="submit" class="hz-btn hz-btn--primary w-full justify-center">Inloggen</button>
            </form>
            <form method="POST" class="mt-2">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="w-full text-center text-xs text-slate-400 hover:text-slate-600 mt-2">Terug naar inloggen</button>
            </form>

        <?php else: ?>
            <!-- ═══ STAP 1: E-MAIL + WACHTWOORD ═══ -->
            <form method="POST" class="space-y-5">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="login">
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                    <input type="email" id="email" name="email" required
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg outline-none transition"
                           value="<?php echo e($emailValue ?? 'klant@bakkerzonen.nl'); ?>">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                    <input type="password" id="password" name="password" required
                           value="<?php echo e(DEMO_USER_PASSWORD); ?>"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg outline-none transition">
                </div>
                <button type="submit" class="hz-btn hz-btn--primary w-full justify-center py-2.5">Inloggen</button>
            </form>

            <div class="mt-6 text-xs text-slate-400 space-y-1 border-t border-slate-100 pt-4">
                <p class="font-medium text-slate-500">Demo-accounts (2 aparte klantbedrijven, tonen data-isolatie):</p>
                <p>klant@bakkerzonen.nl / <?php echo e(DEMO_USER_PASSWORD); ?></p>
                <p>klant@devriesconsult.nl / <?php echo e(DEMO_USER_PASSWORD); ?></p>
                <p class="pt-1">Bij eerste login per account wordt eenmalig 2FA ingesteld (verplicht).</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
