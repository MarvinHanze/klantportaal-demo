<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
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
        $remaining = loginLockoutRemaining();
        if ($remaining > 0) {
            $error = "Te veel mislukte inlogpogingen. Probeer het over {$remaining} seconden opnieuw.";
            $emailValue = trim((string) ($_POST['email'] ?? ''));
        } else {
            $email = trim((string) ($_POST['email'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');

            $stmt = db()->prepare("SELECT * FROM kp_users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass, $user['password_hash'])) {
                resetFailedLogins();
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
            registerFailedLogin();
            $newRemaining = loginLockoutRemaining();
            $error = $newRemaining > 0
                ? "Te veel mislukte inlogpogingen. Probeer het over {$newRemaining} seconden opnieuw."
                : 'Ongeldige inloggegevens.';
            $emailValue = $email;
        }
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
    <style>
        :root { --kp-rose: #e11d48; }
        body.kp-crm {
            min-height: 100vh;
            background-color: #fafafa;
            background-image:
                repeating-linear-gradient(135deg, rgba(225,29,72,.045) 0 1px, transparent 1px 26px),
                linear-gradient(rgba(15,23,42,.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15,23,42,.035) 1px, transparent 1px);
            background-size: auto, 40px 40px, 40px 40px;
            display: flex; align-items: center; justify-content: center; padding: 1.5rem;
        }
        .kp-shell {
            display: grid; grid-template-columns: 1fr 1fr;
            max-width: 880px; width: 100%;
            background: #ffffff; border-radius: 1rem; overflow: hidden;
            box-shadow: 0 20px 50px rgba(15,23,42,.12), 0 2px 8px rgba(15,23,42,.06);
            border: 1px solid #e5e7eb;
        }
        .kp-showcase {
            background: linear-gradient(160deg, var(--kp-brand, #e11d48) 0%, #7f1233 100%);
            color: #fff; padding: 2.25rem 2rem; display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        .kp-showcase::after {
            content: ""; position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255,255,255,.14) 1px, transparent 1px);
            background-size: 18px 18px; opacity: .5;
        }
        .kp-showcase > * { position: relative; z-index: 1; }
        .kp-mini-card {
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22);
            border-radius: .6rem; padding: .75rem .9rem; margin-bottom: .6rem; backdrop-filter: blur(4px);
        }
        .kp-mini-card .kp-mc-top { display:flex; justify-content:space-between; align-items:center; font-size:.72rem; opacity:.85; margin-bottom:.25rem; }
        .kp-mini-card .kp-mc-title { font-size:.85rem; font-weight:600; }
        .kp-mc-pill { font-size:.62rem; font-weight:700; padding:.12rem .5rem; border-radius:999px; background:rgba(255,255,255,.25); text-transform:uppercase; letter-spacing:.03em; }
        .kp-stat-row { display:flex; gap:1.25rem; margin-top:1.5rem; }
        .kp-stat-row .kp-stat-num { font-size:1.4rem; font-weight:800; line-height:1; }
        .kp-stat-row .kp-stat-lbl { font-size:.68rem; opacity:.8; margin-top:.15rem; }
        .kp-formcol { padding: 2.25rem 2.25rem; }
        @media (max-width: 760px) {
            .kp-shell { grid-template-columns: 1fr; }
            .kp-showcase { display: none; }
        }
    </style>
</head>
<body class="kp-crm">
<div class="kp-shell">
    <div class="kp-showcase">
        <div>
            <div class="flex items-center gap-2 mb-6">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                </svg>
                <span class="font-bold text-lg">Klantportaal</span>
            </div>
            <p class="text-sm opacity-90 mb-5">Al je tickets, offertes en facturen op een centrale plek.</p>

            <div class="kp-mini-card">
                <div class="kp-mc-top"><span>#TCK-2291</span><span class="kp-mc-pill">Open</span></div>
                <div class="kp-mc-title">Vraag over levertijd order</div>
            </div>
            <div class="kp-mini-card">
                <div class="kp-mc-top"><span>#TCK-2288</span><span class="kp-mc-pill">In behandeling</span></div>
                <div class="kp-mc-title">Wijziging contactgegevens</div>
            </div>
            <div class="kp-mini-card">
                <div class="kp-mc-top"><span>#OFF-114</span><span class="kp-mc-pill">Verzonden</span></div>
                <div class="kp-mc-title">Offerte jaarcontract 2026</div>
            </div>
        </div>
        <div class="kp-stat-row">
            <div><div class="kp-stat-num">12</div><div class="kp-stat-lbl">Open tickets</div></div>
            <div><div class="kp-stat-num">4</div><div class="kp-stat-lbl">Offertes</div></div>
            <div><div class="kp-stat-num">98%</div><div class="kp-stat-lbl">Tevredenheid</div></div>
        </div>
    </div>

    <div class="kp-formcol">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Welkom terug</h1>
            <p class="text-sm text-slate-500 mt-1">Log in om je account te beheren</p>
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
