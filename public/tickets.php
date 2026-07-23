<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
secure_session_start();
requireAuth();
ensure_schema();
check_auto_reset();

$pdo = db();
$accountId = currentAccountId();
$redirect = BASE . '/tickets.php';

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
            in_array($_POST['priority'] ?? '', ['laag', 'normaal', 'hoog', 'urgent'], true) ? $_POST['priority'] : 'normaal',
            in_array($_POST['status'] ?? '', ['open', 'bezig', 'wachtend', 'opgelost'], true) ? $_POST['status'] : 'open',
            trim((string) $_POST['department']),
        ];

        if ($id > 0) {
            /* Bestaand ticket: eerst controleren dat het echt van dit account is,
               anders zou een klant via een geraden id een ticket van iemand anders kunnen wijzigen. */
            $stmt = $pdo->prepare("SELECT * FROM kp_tickets WHERE id = ? AND account_id = ?");
            $stmt->execute([$id, $accountId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $data[] = $id;
                $data[] = $accountId;
                $pdo->prepare("UPDATE kp_tickets SET customer_name=?, customer_email=?, subject=?, description=?, priority=?, status=?, department=?, updated_at=NOW()
                               WHERE id=? AND account_id=?")->execute($data);
                if ($existing['status'] !== $_POST['status']) {
                    createNotification($accountId, "Ticket-status gewijzigd naar '{$_POST['status']}' voor '{$existing['subject']}'.", BASE . '/tickets.php?action=detail&id=' . $id);
                    logAudit('status_changed', 'ticket', $id, "Status gewijzigd van '{$existing['status']}' naar '{$_POST['status']}'");
                }
            }
        } else {
            array_unshift($data, $accountId);
            $pdo->prepare("INSERT INTO kp_tickets (account_id, customer_name, customer_email, subject, description, priority, status, department)
                           VALUES (?,?,?,?,?,?,?,?)")->execute($data);
            $newId = (int) $pdo->lastInsertId();
            logAudit('created', 'ticket', $newId, "Ticket '{$_POST['subject']}' aangemaakt");
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($act === 'delete') {
        $id = (int) $_POST['id'];
        $pdo->prepare("DELETE FROM kp_ticket_notes WHERE ticket_id = ? AND account_id = ?")->execute([$id, $accountId]);
        $pdo->prepare("DELETE FROM kp_tickets WHERE id = ? AND account_id = ?")->execute([$id, $accountId]);
        header('Location: ' . $redirect);
        exit;
    }

    if ($act === 'add_note') {
        $ticketId = (int) $_POST['ticket_id'];
        $note = trim((string) $_POST['note']);
        $stmt = $pdo->prepare("SELECT id FROM kp_tickets WHERE id = ? AND account_id = ?");
        $stmt->execute([$ticketId, $accountId]);
        if ($stmt->fetch() && $note !== '') {
            $pdo->prepare("INSERT INTO kp_ticket_notes (ticket_id, account_id, author, note) VALUES (?, ?, ?, ?)")
                ->execute([$ticketId, $accountId, currentUserName() ?: 'Klant', $note]);
        }
        header('Location: ' . BASE . '/tickets.php?action=detail&id=' . $ticketId);
        exit;
    }
}

$action = $_GET['action'] ?? 'list';
$filterPriority = $_GET['priority'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';
$where = ['account_id = ?'];
$params = [$accountId];

if (in_array($filterPriority, ['laag', 'normaal', 'hoog', 'urgent'], true)) {
    $where[] = 'priority = ?';
    $params[] = $filterPriority;
}
if (in_array($filterStatus, ['open', 'bezig', 'wachtend', 'opgelost'], true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = '(subject LIKE ? OR description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM kp_tickets {$whereSql} ORDER BY FIELD(priority,'urgent','hoog','normaal','laag'), created_at DESC");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$detailTicket = null;
$detailNotes = [];
if ($action === 'detail' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM kp_tickets WHERE id = ? AND account_id = ?");
    $stmt->execute([(int) $_GET['id'], $accountId]);
    $detailTicket = $stmt->fetch();
    if ($detailTicket) {
        $stmt = $pdo->prepare("SELECT * FROM kp_ticket_notes WHERE ticket_id = ? AND account_id = ? ORDER BY created_at ASC");
        $stmt->execute([$detailTicket['id'], $accountId]);
        $detailNotes = $stmt->fetchAll();
    }
}

$pColors = ['laag' => 'green', 'normaal' => 'gray', 'hoog' => 'orange', 'urgent' => 'red'];
$sColors = ['open' => 'red', 'bezig' => 'orange', 'wachtend' => 'gray', 'opgelost' => 'green'];

$pageTitle = 'Support tickets';
$activeNav = 'tickets';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Support tickets', 'url' => $detailTicket ? (BASE . '/tickets.php') : null]];
if ($detailTicket) {
    $breadcrumbs[] = ['label' => $detailTicket['subject'], 'url' => null];
}
require __DIR__ . '/partials/nav.php';
?>

<?php if ($action === 'detail' && $detailTicket): ?>
<div class="hz-card max-w-2xl">
    <div class="hz-card__header">
        <div>
            <h1 class="text-xl font-bold text-slate-800"><?php echo e($detailTicket['subject']); ?></h1>
            <p class="text-sm text-slate-400"><?php echo e($detailTicket['customer_name']); ?> &mdash; <?php echo e($detailTicket['customer_email']); ?></p>
        </div>
        <div class="flex gap-2">
            <button onclick="openModal(<?php echo json_encode($detailTicket, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)" class="hz-btn hz-btn--secondary" style="padding:.4rem .8rem;">Bewerken</button>
            <form method="POST" data-hz-confirm="Weet je zeker dat je dit ticket wilt verwijderen?">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int) $detailTicket['id']; ?>">
                <button type="submit" class="hz-btn hz-btn--danger" style="padding:.4rem .8rem;">Verwijderen</button>
            </form>
        </div>
    </div>
    <div class="grid grid-cols-3 gap-3 text-sm mb-4">
        <div><span class="text-slate-400 block text-xs">Prioriteit</span><span class="hz-badge hz-badge--<?php echo $pColors[$detailTicket['priority']]; ?>"><?php echo ucfirst($detailTicket['priority']); ?></span></div>
        <div><span class="text-slate-400 block text-xs">Status</span><span class="hz-badge hz-badge--<?php echo $sColors[$detailTicket['status']]; ?>"><?php echo ucfirst($detailTicket['status']); ?></span></div>
        <div><span class="text-slate-400 block text-xs">Afdeling</span><span class="font-medium"><?php echo e($detailTicket['department']); ?></span></div>
    </div>
    <p class="text-sm text-slate-600 whitespace-pre-wrap border-t border-slate-100 pt-3"><?php echo e($detailTicket['description']); ?></p>
</div>

<div class="mt-6 max-w-2xl">
    <h3 class="font-bold text-slate-800 mb-3">Berichten</h3>
    <?php if (empty($detailNotes)): ?>
        <p class="text-sm text-slate-400 mb-4">Nog geen berichten.</p>
    <?php else: ?>
        <div class="space-y-3 mb-4">
            <?php foreach ($detailNotes as $note): ?>
                <div class="hz-card">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-slate-700"><?php echo e($note['author']); ?></span>
                        <span class="text-xs text-slate-400"><?php echo date('d-m-Y H:i', strtotime($note['created_at'])); ?></span>
                    </div>
                    <p class="text-sm text-slate-600 whitespace-pre-wrap"><?php echo e($note['note']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="POST" class="hz-card">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_note">
        <input type="hidden" name="ticket_id" value="<?php echo (int) $detailTicket['id']; ?>">
        <textarea name="note" rows="3" required placeholder="Typ een bericht..." class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm mb-3"></textarea>
        <button type="submit" class="hz-btn hz-btn--primary">Versturen</button>
    </form>
</div>
<a href="<?php echo BASE; ?>/tickets.php" class="inline-block mt-4 text-sm" style="color:var(--hz-primary);">&larr; Terug naar overzicht</a>

<?php else: ?>

<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <h1 class="text-2xl font-bold text-slate-800">Support tickets</h1>
    <button data-hz-modal-open="ticketModal" onclick="prepModal()" class="hz-btn hz-btn--primary">+ Nieuw ticket</button>
</div>

<form method="GET" class="flex flex-wrap gap-3 mb-6">
    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Zoeken..." class="px-3 py-2 border border-slate-300 rounded-lg text-sm w-48">
    <select name="priority" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Alle prioriteiten</option>
        <?php foreach (['laag', 'normaal', 'hoog', 'urgent'] as $p): ?>
            <option value="<?php echo $p; ?>" <?php echo $filterPriority === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Alle statussen</option>
        <?php foreach (['open', 'bezig', 'wachtend', 'opgelost'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="hz-btn hz-btn--secondary">Filteren</button>
</form>

<div class="hz-card">
<div class="overflow-x-auto">
<table class="hz-table w-full">
    <thead><tr><th>Onderwerp</th><th>Prioriteit</th><th>Status</th><th>Afdeling</th><th>Aangemaakt</th><th class="text-right">Acties</th></tr></thead>
    <tbody>
        <?php if (empty($tickets)): ?><tr><td colspan="6" class="text-center text-slate-400 py-8">Geen tickets gevonden.</td></tr><?php endif; ?>
        <?php foreach ($tickets as $t): ?>
            <tr class="cursor-pointer" onclick="window.location='<?php echo BASE; ?>/tickets.php?action=detail&id=<?php echo (int) $t['id']; ?>'">
                <td class="font-medium"><?php echo e($t['subject']); ?></td>
                <td><span class="hz-badge hz-badge--<?php echo $pColors[$t['priority']]; ?>"><?php echo ucfirst($t['priority']); ?></span></td>
                <td><span class="hz-badge hz-badge--<?php echo $sColors[$t['status']]; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                <td class="text-slate-500"><?php echo e($t['department']); ?></td>
                <td class="text-slate-400"><?php echo date('d-m-Y', strtotime($t['created_at'])); ?></td>
                <td class="text-right" onclick="event.stopPropagation()">
                    <button onclick='openModal(<?php echo json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)' class="hz-icon-btn" title="Bewerken"><?php echo hz_icon('edit'); ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<div class="hz-modal__backdrop" id="ticketModal">
    <div class="hz-modal">
        <form method="POST" id="ticketForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="form_id" value="">
            <div class="hz-modal__header">
                <h3 class="font-bold" id="modalTitle">Nieuw ticket</h3>
                <button type="button" data-hz-modal-close class="hz-icon-btn">&times;</button>
            </div>
            <div class="space-y-3">
                <input type="hidden" name="customer_name" id="form_name" value="<?php echo e(currentUserName()); ?>">
                <input type="hidden" name="customer_email" id="form_email" value="<?php echo e(currentUserEmail()); ?>">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Onderwerp *</label>
                    <input type="text" name="subject" id="form_subject" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Beschrijving *</label>
                    <textarea name="description" id="form_description" rows="4" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Prioriteit</label>
                        <select name="priority" id="form_priority" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <option value="laag">Laag</option>
                            <option value="normaal" selected>Normaal</option>
                            <option value="hoog">Hoog</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Status</label>
                        <select name="status" id="form_status" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                            <option value="open">Open</option>
                            <option value="bezig">Bezig</option>
                            <option value="wachtend">Wachtend</option>
                            <option value="opgelost">Opgelost</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Afdeling</label>
                        <input type="text" name="department" id="form_department" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    </div>
                </div>
            </div>
            <div class="hz-modal__footer">
                <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<script>
function prepModal() {
    document.getElementById('modalTitle').textContent = 'Nieuw ticket';
    document.getElementById('form_id').value = '';
    document.getElementById('ticketForm').reset();
}
function openModal(ticket) {
    document.getElementById('modalTitle').textContent = 'Ticket bewerken';
    document.getElementById('form_id').value = ticket.id;
    document.getElementById('form_name').value = ticket.customer_name;
    document.getElementById('form_email').value = ticket.customer_email;
    document.getElementById('form_subject').value = ticket.subject;
    document.getElementById('form_description').value = ticket.description;
    document.getElementById('form_priority').value = ticket.priority;
    document.getElementById('form_status').value = ticket.status;
    document.getElementById('form_department').value = ticket.department;
    document.getElementById('ticketModal').classList.add('hz-is-open');
}
</script>

<?php endif; ?>
<?php require __DIR__ . '/partials/foot.php'; ?>
