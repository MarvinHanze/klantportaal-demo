<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: ' . BASE . '/login.php');
    exit;
}

ensure_schema();
check_auto_reset();

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$redirect = BASE . '/index.php';

/* ─── Handle POST actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            trim((string) $_POST['customer_name']),
            trim((string) $_POST['customer_email']),
            trim((string) $_POST['subject']),
            trim((string) $_POST['description']),
            $_POST['priority'],
            $_POST['status'],
            trim((string) $_POST['department']),
        ];

        if ($id > 0) {
            $data[] = $id;
            $stmt = $pdo->prepare("UPDATE klant_tickets SET customer_name=?, customer_email=?, subject=?, description=?, priority=?, status=?, department=?, updated_at=NOW() WHERE id=?");
        } else {
            $stmt = $pdo->prepare("INSERT INTO klant_tickets (customer_name, customer_email, subject, description, priority, status, department) VALUES (?,?,?,?,?,?,?)");
        }
        $stmt->execute($data);
        header('Location: ' . $redirect);
        exit;
    }

    if ($act === 'delete') {
        $id = (int) $_POST['id'];
        $pdo->prepare("DELETE FROM klant_notes WHERE ticket_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM klant_tickets WHERE id = ?")->execute([$id]);
        header('Location: ' . $redirect);
        exit;
    }

    if ($act === 'add_note') {
        $ticketId = (int) $_POST['ticket_id'];
        $author = trim((string) $_POST['author']);
        $note = trim((string) $_POST['note']);
        if ($author !== '' && $note !== '') {
            $pdo->prepare("INSERT INTO klant_notes (ticket_id, author, note) VALUES (?, ?, ?)")
                ->execute([$ticketId, $author, $note]);
        }
        header('Location: ' . BASE . '/index.php?action=detail&id=' . $ticketId);
        exit;
    }
}

/* ─── Fetch stats ─── */
$stats = $pdo->query("SELECT
    SUM(status = 'open') AS open_count,
    SUM(status = 'bezig') AS bezig_count,
    SUM(priority = 'urgent' AND status != 'opgelost') AS urgent_count,
    SUM(status = 'opgelost') AS solved_count
    FROM klant_tickets")->fetch();

/* ─── Filters ─── */
$filterPriority = $_GET['priority'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';
$where = [];
$params = [];

if ($filterPriority !== '' && in_array($filterPriority, ['laag', 'normaal', 'hoog', 'urgent'], true)) {
    $where[] = 'priority = ?';
    $params[] = $filterPriority;
}
if ($filterStatus !== '' && in_array($filterStatus, ['open', 'bezig', 'wachtend', 'opgelost'], true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = '(customer_name LIKE ? OR subject LIKE ? OR customer_email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$tickets = $pdo->prepare("SELECT * FROM klant_tickets {$whereSql} ORDER BY FIELD(priority, 'urgent', 'hoog', 'normaal', 'laag'), created_at DESC");
$tickets->execute($params);
$tickets = $tickets->fetchAll();

/* ─── Detail view ─── */
$detailTicket = null;
$detailNotes = [];
if ($action === 'detail' && isset($_GET['id'])) {
    $detailId = (int) $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM klant_tickets WHERE id = ?");
    $stmt->execute([$detailId]);
    $detailTicket = $stmt->fetch();
    if ($detailTicket) {
        $noteStmt = $pdo->prepare("SELECT * FROM klant_notes WHERE ticket_id = ? ORDER BY created_at ASC");
        $noteStmt->execute([$detailId]);
        $detailNotes = $noteStmt->fetchAll();
    }
}

/* ─── Edit view ─── */
$editTicket = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $editId = (int) $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM klant_tickets WHERE id = ?");
    $stmt->execute([$editId]);
    $editTicket = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klantportaal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-50">

<!-- Nav -->
<nav class="bg-white shadow-sm border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <a href="<?php echo BASE; ?>/index.php" class="flex items-center gap-2.5">
                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                </svg>
                <span class="text-xl font-bold text-slate-800">Klantportaal</span>
            </a>
            <a href="<?php echo BASE; ?>/logout.php"
               class="text-sm text-slate-500 hover:text-slate-700 transition">
                Uitloggen
            </a>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

<?php if ($action === 'detail' && $detailTicket): ?>
<!-- ═══ DETAIL VIEW ═══ -->
<div class="mb-6">
    <a href="<?php echo BASE; ?>/index.php" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-purple-600 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
        Terug naar overzicht
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-200">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-xl font-bold text-slate-800"><?php echo htmlspecialchars($detailTicket['subject'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="mt-1 text-sm text-slate-500">
                    <?php echo htmlspecialchars($detailTicket['customer_name'], ENT_QUOTES, 'UTF-8'); ?>
                    &mdash;
                    <?php echo htmlspecialchars($detailTicket['customer_email'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="<?php echo BASE; ?>/index.php?action=edit&id=<?php echo $detailTicket['id']; ?>"
                   class="px-3 py-1.5 text-sm bg-slate-100 hover:bg-slate-200 rounded-lg transition text-slate-700">
                    Bewerken
                </a>
                <button onclick="document.getElementById('deleteForm-<?php echo $detailTicket['id']; ?>').submit()"
                        class="px-3 py-1.5 text-sm bg-red-50 hover:bg-red-100 text-red-600 rounded-lg transition">
                    Verwijderen
                </button>
            </div>
        </div>
    </div>
    <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
        <div>
            <span class="text-slate-400">Prioriteit</span>
            <div class="mt-1">
                <?php
                $pColors = ['laag' => 'green', 'normaal' => 'blue', 'hoog' => 'orange', 'urgent' => 'red'];
                $pc = $pColors[$detailTicket['priority']] ?? 'slate';
                ?>
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-<?php echo $pc; ?>-100 text-<?php echo $pc; ?>-700">
                    <?php echo htmlspecialchars(ucfirst($detailTicket['priority']), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
        <div>
            <span class="text-slate-400">Status</span>
            <div class="mt-1">
                <?php
                $sColors = ['open' => 'red', 'bezig' => 'blue', 'wachtend' => 'yellow', 'opgelost' => 'green'];
                $sc = $sColors[$detailTicket['status']] ?? 'slate';
                ?>
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-<?php echo $sc; ?>-100 text-<?php echo $sc; ?>-700">
                    <?php echo htmlspecialchars(ucfirst($detailTicket['status']), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
        <div>
            <span class="text-slate-400">Afdeling</span>
            <div class="mt-1 font-medium text-slate-700">
                <?php echo htmlspecialchars($detailTicket['department'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>
    <div class="px-6 py-5 border-t border-slate-200">
        <span class="text-slate-400 text-sm">Beschrijving</span>
        <p class="mt-1 text-sm text-slate-700 whitespace-pre-wrap"><?php echo htmlspecialchars($detailTicket['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</div>

<!-- Notes -->
<div class="mt-8">
    <h3 class="text-lg font-bold text-slate-800 mb-4">Interne notities</h3>

    <?php if (empty($detailNotes)): ?>
        <p class="text-sm text-slate-400 mb-4">Geen notities.</p>
    <?php else: ?>
        <div class="space-y-3 mb-6">
            <?php foreach ($detailNotes as $note): ?>
                <div class="bg-white rounded-lg border border-slate-200 px-5 py-4">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($note['author'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-xs text-slate-400"><?php echo date('d-m-Y H:i', strtotime($note['created_at'])); ?></span>
                    </div>
                    <p class="text-sm text-slate-600 whitespace-pre-wrap"><?php echo htmlspecialchars($note['note'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-lg border border-slate-200 p-5">
        <input type="hidden" name="action" value="add_note">
        <input type="hidden" name="ticket_id" value="<?php echo $detailTicket['id']; ?>">
        <?php echo csrfField(); ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Auteur</label>
                <input type="text" name="author" required
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
            </div>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-slate-700 mb-1">Notitie</label>
            <textarea name="note" rows="3" required
                      class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none resize-none"></textarea>
        </div>
        <button type="submit"
                class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition">
            Notitie toevoegen
        </button>
    </form>
</div>

<!-- Hidden delete form -->
<form id="deleteForm-<?php echo $detailTicket['id']; ?>" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?php echo $detailTicket['id']; ?>">
    <?php echo csrfField(); ?>
</form>

<?php else: ?>
<!-- ═══ DASHBOARD + TABLE ═══ -->

<!-- Stats -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-sm text-slate-500">Open tickets</p>
                <p class="text-2xl font-bold text-slate-800"><?php echo (int) $stats['open_count']; ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"/>
                </svg>
            </div>
            <div>
                <p class="text-sm text-slate-500">Bezig</p>
                <p class="text-2xl font-bold text-slate-800"><?php echo (int) $stats['bezig_count']; ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.75h.007v.008H12v-.008z"/>
                </svg>
            </div>
            <div>
                <p class="text-sm text-slate-500">Urgent</p>
                <p class="text-2xl font-bold text-slate-800"><?php echo (int) $stats['urgent_count']; ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-sm text-slate-500">Totaal opgelost</p>
                <p class="text-2xl font-bold text-slate-800"><?php echo (int) $stats['solved_count']; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Filters + Add -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <div class="relative">
            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
            </svg>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="Zoeken..."
                   class="pl-9 pr-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none w-48">
        </div>
        <select name="priority"
                class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
            <option value="">Alle prioriteiten</option>
            <option value="laag" <?php echo $filterPriority === 'laag' ? 'selected' : ''; ?>>Laag</option>
            <option value="normaal" <?php echo $filterPriority === 'normaal' ? 'selected' : ''; ?>>Normaal</option>
            <option value="hoog" <?php echo $filterPriority === 'hoog' ? 'selected' : ''; ?>>Hoog</option>
            <option value="urgent" <?php echo $filterPriority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
        </select>
        <select name="status"
                class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
            <option value="">Alle statussen</option>
            <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>>Open</option>
            <option value="bezig" <?php echo $filterStatus === 'bezig' ? 'selected' : ''; ?>>Bezig</option>
            <option value="wachtend" <?php echo $filterStatus === 'wachtend' ? 'selected' : ''; ?>>Wachtend</option>
            <option value="opgelost" <?php echo $filterStatus === 'opgelost' ? 'selected' : ''; ?>>Opgelost</option>
        </select>
        <button type="submit" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm text-slate-600 transition">
            Filteren
        </button>
    </form>
    <button onclick="openModal(null)"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Nieuw ticket
    </button>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    <th class="text-left px-5 py-3 font-medium text-slate-500">Klant</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-500">Onderwerp</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-500">Prioriteit</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-500">Status</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-500 hidden md:table-cell">Afdeling</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-500 hidden lg:table-cell">Aangemaakt</th>
                    <th class="text-right px-5 py-3 font-medium text-slate-500">Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-slate-400">Geen tickets gevonden.</td>
                    </tr>
                <?php else: ?>
                    <?php $noteCountStmt = $pdo->prepare("SELECT COUNT(*) FROM klant_notes WHERE ticket_id = ?"); ?>
                    <?php foreach ($tickets as $t): ?>
                        <?php
                        $noteCountStmt->execute([$t['id']]);
                        $noteCount = (int) $noteCountStmt->fetchColumn();
                        ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50 transition cursor-pointer"
                            onclick="window.location='<?php echo BASE; ?>/index.php?action=detail&id=<?php echo $t['id']; ?>'">
                            <td class="px-5 py-4">
                                <div class="font-medium text-slate-800"><?php echo htmlspecialchars($t['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-xs text-slate-400"><?php echo htmlspecialchars($t['customer_email'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="text-slate-700"><?php echo htmlspecialchars($t['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($noteCount > 0): ?>
                                    <div class="flex items-center gap-1 mt-0.5 text-xs text-slate-400">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                                        </svg>
                                        <?php echo $noteCount; ?> notitie<?php echo $noteCount !== 1 ? 's' : ''; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <?php
                                $pc = ['laag' => 'green', 'normaal' => 'blue', 'hoog' => 'orange', 'urgent' => 'red'][$t['priority']] ?? 'slate';
                                ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-<?php echo $pc; ?>-100 text-<?php echo $pc; ?>-700">
                                    <?php echo htmlspecialchars(ucfirst($t['priority']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <?php
                                $sc = ['open' => 'red', 'bezig' => 'blue', 'wachtend' => 'yellow', 'opgelost' => 'green'][$t['status']] ?? 'slate';
                                ?>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-<?php echo $sc; ?>-100 text-<?php echo $sc; ?>-700">
                                    <?php echo htmlspecialchars(ucfirst($t['status']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 text-slate-600 hidden md:table-cell">
                                <?php echo htmlspecialchars($t['department'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="px-5 py-4 text-slate-400 hidden lg:table-cell">
                                <?php echo date('d-m-Y', strtotime($t['created_at'])); ?>
                            </td>
                            <td class="px-5 py-4 text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="<?php echo BASE; ?>/index.php?action=detail&id=<?php echo $t['id']; ?>"
                                       class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition" title="Bekijken">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                    </a>
                                    <button onclick="openModal(<?php echo json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)"
                                            class="p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition" title="Bewerken">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>
                                        </svg>
                                    </button>
                                    <button onclick="if(confirm('Weet je zeker dat je dit ticket wilt verwijderen?')) document.getElementById('deleteForm-<?php echo $t['id']; ?>').submit()"
                                            class="p-1.5 rounded-lg hover:bg-red-50 text-slate-400 hover:text-red-500 transition" title="Verwijderen">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <form id="deleteForm-<?php echo $t['id']; ?>" method="POST" style="display:none">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                            <?php echo csrfField(); ?>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
</main>

<!-- ═══ ADD/EDIT MODAL ═══ -->
<div id="modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" onclick="closeModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <form method="POST" id="ticketForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form_id" value="">
                <?php echo csrfField(); ?>

                <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-slate-800" id="modalTitle">Nieuw ticket</h3>
                    <button type="button" onclick="closeModal()" class="p-1 rounded-lg hover:bg-slate-100 text-slate-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Klantnaam *</label>
                            <input type="text" name="customer_name" id="form_name" required
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">E-mail *</label>
                            <input type="email" name="customer_email" id="form_email" required
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Onderwerp *</label>
                        <input type="text" name="subject" id="form_subject" required
                               class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Beschrijving *</label>
                        <textarea name="description" id="form_description" rows="4" required
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none resize-none"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Prioriteit</label>
                            <select name="priority" id="form_priority"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                                <option value="laag">Laag</option>
                                <option value="normaal" selected>Normaal</option>
                                <option value="hoog">Hoog</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                            <select name="status" id="form_status"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                                <option value="open">Open</option>
                                <option value="bezig">Bezig</option>
                                <option value="wachtend">Wachtend</option>
                                <option value="opgelost">Opgelost</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Afdeling</label>
                            <input type="text" name="department" id="form_department"
                                   class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none">
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-end gap-3">
                    <button type="button" onclick="closeModal()"
                            class="px-4 py-2 text-sm text-slate-600 hover:bg-slate-100 rounded-lg transition">
                        Annuleren
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition">
                        Opslaan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(ticket) {
    var modal = document.getElementById('modal');
    var title = document.getElementById('modalTitle');
    if (ticket) {
        title.textContent = 'Ticket bewerken';
        document.getElementById('form_id').value = ticket.id;
        document.getElementById('form_name').value = ticket.customer_name;
        document.getElementById('form_email').value = ticket.customer_email;
        document.getElementById('form_subject').value = ticket.subject;
        document.getElementById('form_description').value = ticket.description;
        document.getElementById('form_priority').value = ticket.priority;
        document.getElementById('form_status').value = ticket.status;
        document.getElementById('form_department').value = ticket.department;
    } else {
        title.textContent = 'Nieuw ticket';
        document.getElementById('form_id').value = '';
        document.getElementById('ticketForm').reset();
    }
    modal.classList.remove('hidden');
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

</body>
</html>
