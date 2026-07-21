<?php
declare(strict_types=1);
/**
 * Gedeelde topbar + sidebar + breadcrumbs.
 * Verwacht dat de aanroepende pagina vóór het includen instelt:
 *   $pageTitle   (string)
 *   $activeNav   (string) — één van de keys in $navItems hieronder
 *   $breadcrumbs (array)  — [['label' => 'Dashboard', 'url' => BASE.'/index.php'], ['label' => 'Huidige pagina', 'url' => null]]
 */

$account = currentAccount();
$brandColor = $account['brand_color'] ?? '#7c3aed';
$brandColorDark = darken_hex($brandColor);
$unread = unreadNotificationCount(currentAccountId());
$recentNotifs = db()->prepare("SELECT * FROM kp_notifications WHERE account_id = ? ORDER BY created_at DESC LIMIT 8");
$recentNotifs->execute([currentAccountId()]);
$recentNotifs = $recentNotifs->fetchAll();

$navItems = [
    'dashboard'    => ['label' => 'Dashboard',            'url' => BASE . '/index.php',        'icon' => '&#127968;'],
    'offertes'     => ['label' => 'Offertes',              'url' => BASE . '/offertes.php',      'icon' => '&#128203;'],
    'facturen'     => ['label' => 'Facturen',               'url' => BASE . '/facturen.php',      'icon' => '&#128179;'],
    'projecten'    => ['label' => 'Projecten',              'url' => BASE . '/projecten.php',     'icon' => '&#128202;'],
    'documenten'   => ['label' => 'Documenten',             'url' => BASE . '/documenten.php',    'icon' => '&#128193;'],
    'tickets'      => ['label' => 'Support tickets',        'url' => BASE . '/tickets.php',       'icon' => '&#127911;'],
    'kennisbank'   => ['label' => 'Kennisbank',             'url' => BASE . '/kennisbank.php',    'icon' => '&#128218;'],
    'feedback'     => ['label' => 'Feedback',               'url' => BASE . '/feedback.php',      'icon' => '&#11088;'],
    'profiel'      => ['label' => 'Profiel & Beveiliging',  'url' => BASE . '/profiel.php',       'icon' => '&#128100;'],
    'instellingen' => ['label' => 'Integraties',            'url' => BASE . '/instellingen.php',  'icon' => '&#9881;'],
    'audit'        => ['label' => 'Audit log',               'url' => BASE . '/audit.php',         'icon' => '&#128220;'],
];
?>
<!DOCTYPE html>
<html lang="nl" style="--hz-primary: <?php echo e($brandColor); ?>; --hz-primary-dark: <?php echo e($brandColorDark); ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? 'Klantportaal'); ?> — Klantportaal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?php echo BASE; ?>/assets/css/components.css">
    <style>
        [x-cloak] { display: none !important; }
        body { display: flex; min-height: 100vh; }
        .kp-shell-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .kp-notif-dot { position: absolute; top: -4px; right: -4px; background: var(--hz-danger); color: #fff; border-radius: 999px; font-size: .65rem; line-height: 1; padding: .2rem .35rem; min-width: 16px; text-align: center; font-weight: 700; }
        .kp-sidebar-brand { display:flex; align-items:center; gap:.6rem; padding: 1rem 1.1rem; border-bottom: 1px solid var(--hz-border); }
        .kp-sidebar-logo { width: 36px; height: 36px; border-radius: .5rem; background: var(--hz-primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; flex-shrink:0; overflow:hidden; }
        .kp-sidebar-logo img { width:100%; height:100%; object-fit:cover; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800">

<!-- Sidebar -->
<aside class="hz-sidebar" id="kpSidebar">
    <div class="kp-sidebar-brand">
        <div class="kp-sidebar-logo">
            <?php if (!empty($account['logo_data_uri'])): ?>
                <img src="<?php echo e($account['logo_data_uri']); ?>" alt="Logo">
            <?php else: ?>
                <?php echo e($account['logo_initials'] ?? 'KP'); ?>
            <?php endif; ?>
        </div>
        <div class="min-w-0">
            <div class="font-bold text-sm truncate hz-sidebar__label"><?php echo e($account['company_name'] ?? 'Klantportaal'); ?></div>
            <div class="text-xs text-slate-400 hz-sidebar__label">Klantportaal</div>
        </div>
    </div>
    <nav class="flex-1 py-2 overflow-y-auto">
        <?php foreach ($navItems as $key => $item): ?>
            <a href="<?php echo $item['url']; ?>" class="hz-sidebar__item<?php echo $activeNav === $key ? ' hz-is-active' : ''; ?>">
                <span aria-hidden="true"><?php echo $item['icon']; ?></span>
                <span class="hz-sidebar__label"><?php echo e($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-3 border-t border-slate-200">
        <button data-hz-sidebar-toggle="kpSidebar" class="hz-sidebar__toggle w-full text-left text-xs">&#8646; In-/uitklappen</button>
    </div>
</aside>

<div class="kp-shell-main">
    <!-- Topbar -->
    <header class="hz-navbar">
        <div class="flex items-center gap-3">
            <span class="text-sm text-slate-400 hidden sm:inline">Ingelogd als</span>
            <span class="font-medium text-sm"><?php echo e($account['company_name'] ?? ''); ?></span>
        </div>
        <div class="hz-navbar__actions">
            <div class="hz-dropdown">
                <button data-hz-dropdown-trigger="notifMenu" class="hz-icon-btn hz-icon-btn--round relative" title="Notificaties" aria-label="Notificaties">
                    &#128276;
                    <?php if ($unread > 0): ?><span class="kp-notif-dot"><?php echo $unread > 9 ? '9+' : $unread; ?></span><?php endif; ?>
                </button>
                <div id="notifMenu" class="hz-dropdown__menu" style="min-width:320px; max-height:360px; overflow-y:auto;">
                    <div class="px-3 py-2 border-b border-slate-100 flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-500 uppercase">Notificaties</span>
                        <?php if ($unread > 0): ?>
                            <form method="POST" action="<?php echo BASE; ?>/index.php">
                                <input type="hidden" name="action" value="mark_notifications_read">
                                <?php echo csrfField(); ?>
                                <button type="submit" class="text-xs text-purple-600 hover:underline" style="color: var(--hz-primary);">Alles gelezen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($recentNotifs)): ?>
                        <div class="px-3 py-6 text-center text-sm text-slate-400">Geen notificaties.</div>
                    <?php else: ?>
                        <?php foreach ($recentNotifs as $n): ?>
                            <a href="<?php echo e($n['link'] ?? (BASE . '/index.php')); ?>" class="block px-3 py-2 border-b border-slate-50 hover:bg-slate-50">
                                <div class="text-sm text-slate-700<?php echo $n['is_read'] ? '' : ' font-semibold'; ?>"><?php echo e($n['message']); ?></div>
                                <div class="text-xs text-slate-400 mt-0.5"><?php echo date('d-m-Y H:i', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hz-dropdown">
                <button data-hz-dropdown-trigger="userMenu" class="hz-avatar" title="<?php echo e(currentUserName()); ?>">
                    <?php echo e(mb_strtoupper(mb_substr(currentUserName() ?: '?', 0, 1))); ?>
                </button>
                <div id="userMenu" class="hz-dropdown__menu">
                    <div class="px-3 py-2 border-b border-slate-100">
                        <div class="text-sm font-medium truncate"><?php echo e(currentUserName()); ?></div>
                        <div class="text-xs text-slate-400 truncate"><?php echo e(currentUserEmail()); ?></div>
                    </div>
                    <a href="<?php echo BASE; ?>/profiel.php">Profiel &amp; Beveiliging</a>
                    <a href="<?php echo BASE; ?>/logout.php">Uitloggen</a>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 max-w-7xl w-full mx-auto">
        <?php if (!empty($breadcrumbs)): ?>
        <nav class="hz-breadcrumbs mb-4">
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="hz-breadcrumbs__sep">/</span><?php endif; ?>
                <?php if (!empty($crumb['url'])): ?>
                    <a href="<?php echo e($crumb['url']); ?>"><?php echo e($crumb['label']); ?></a>
                <?php else: ?>
                    <span class="text-slate-700 font-medium"><?php echo e($crumb['label']); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
