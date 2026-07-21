<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
requireAuth();
ensure_schema();
check_auto_reset();

function detect_filetype(string $filename): string
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true) => 'afbeelding',
        in_array($ext, ['doc', 'docx'], true) => 'word',
        in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'excel',
        default => 'overig',
    };
}

$pdo = db();
$accountId = currentAccountId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'upload_document') {
        $category = in_array($_POST['category'] ?? '', ['contract', 'factuur-bijlage', 'rapportage', 'overig'], true) ? $_POST['category'] : 'overig';
        $projectId = (int) ($_POST['project_id'] ?? 0);
        if ($projectId > 0) {
            /* Alleen koppelen aan een project dat bij dit account hoort — voorkomt dat
               een klant een upload aan het project van een ander bedrijf hangt. */
            $chk = $pdo->prepare("SELECT id FROM kp_projects WHERE id = ? AND account_id = ?");
            $chk->execute([$projectId, $accountId]);
            if (!$chk->fetch()) {
                $projectId = 0;
            }
        }

        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $filename = basename((string) $_FILES['file']['name']);
            $sizeKb = (int) round(((int) $_FILES['file']['size']) / 1024);
            $filetype = detect_filetype($filename);
            $pdo->prepare("INSERT INTO kp_documents (account_id, project_id, filename, filetype, size_kb, category, uploaded_by)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([$accountId, $projectId ?: null, $filename, $filetype, $sizeKb, $category, currentUserName()]);
            $newId = (int) $pdo->lastInsertId();
            logAudit('uploaded', 'document', $newId, "Document '{$filename}' geupload");
            flash('success', "Document '{$filename}' geupload. (Demo: alleen bestandsmetadata wordt opgeslagen, geen bestandsinhoud.)");
        } else {
            flash('error', 'Kies eerst een bestand om te uploaden.');
        }
    }

    if ($act === 'add_comment') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $stmt = $pdo->prepare("SELECT id, filename FROM kp_documents WHERE id = ? AND account_id = ?");
        $stmt->execute([$documentId, $accountId]);
        $doc = $stmt->fetch();
        if ($doc && $comment !== '') {
            $pdo->prepare("INSERT INTO kp_document_comments (document_id, account_id, author, comment) VALUES (?,?,?,?)")
                ->execute([$documentId, $accountId, currentUserName() ?: 'Klant', $comment]);
            logAudit('commented', 'document', $documentId, "Reactie geplaatst op '{$doc['filename']}'");
        }
    }

    if ($act === 'download') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM kp_documents WHERE id = ? AND account_id = ?");
        $stmt->execute([$documentId, $accountId]);
        $doc = $stmt->fetch();
        if ($doc) {
            logAudit('downloaded', 'document', $documentId, "Document '{$doc['filename']}' gedownload");
            flash('info', "Download van '{$doc['filename']}' gestart. (Demo: er wordt geen echt bestand geserveerd.)");
        }
    }

    header('Location: ' . BASE . '/documenten.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM kp_projects WHERE account_id = ? ORDER BY name");
$stmt->execute([$accountId]);
$projects = $stmt->fetchAll();
$projectNames = array_column($projects, 'name', 'id');

$stmt = $pdo->prepare("SELECT * FROM kp_documents WHERE account_id = ? ORDER BY created_at DESC");
$stmt->execute([$accountId]);
$documents = $stmt->fetchAll();

$commentsByDoc = [];
if ($documents) {
    $ids = array_column($documents, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM kp_document_comments WHERE account_id = ? AND document_id IN ({$in}) ORDER BY created_at ASC");
    $stmt->execute(array_merge([$accountId], $ids));
    foreach ($stmt->fetchAll() as $c) {
        $commentsByDoc[$c['document_id']][] = $c;
    }
}

$typeIcons = ['pdf' => '&#128196;', 'afbeelding' => '&#128444;', 'word' => '&#128209;', 'excel' => '&#128202;', 'overig' => '&#128193;'];
$catLabels = ['contract' => 'Contract', 'factuur-bijlage' => 'Factuurbijlage', 'rapportage' => 'Rapportage', 'overig' => 'Overig'];

$pageTitle = 'Documenten';
$activeNav = 'documenten';
$breadcrumbs = [['label' => 'Dashboard', 'url' => BASE . '/index.php'], ['label' => 'Documenten', 'url' => null]];
require __DIR__ . '/partials/nav.php';
?>

<h1 class="text-2xl font-bold text-slate-800 mb-6">Documenten</h1>

<div class="hz-card mb-6">
    <h2 class="font-bold text-slate-800 mb-3">Bestand uploaden</h2>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="upload_document">
        <div class="hz-dropzone mb-3" id="uploadDropzone">
            <input type="file" name="file" id="fileInput" class="hidden" required>
            <p>&#128228; Sleep een bestand hierheen of klik om te kiezen</p>
            <p class="text-xs mt-1" id="detectedType">PDF, Word, Excel of afbeelding</p>
            <div class="hz-dropzone__preview"></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
            <select name="category" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <?php foreach ($catLabels as $k => $label): ?>
                    <option value="<?php echo $k; ?>"><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="project_id" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <option value="0">Niet aan een project koppelen</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?php echo (int) $p['id']; ?>"><?php echo e($p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="hz-btn hz-btn--primary">Uploaden</button>
    </form>
</div>

<div class="space-y-4">
    <?php if (empty($documents)): ?>
        <p class="text-slate-400 text-sm">Nog geen documenten geupload.</p>
    <?php endif; ?>
    <?php foreach ($documents as $d): ?>
        <div class="hz-card" id="doc<?php echo (int) $d['id']; ?>">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div class="flex items-start gap-3">
                    <span class="text-2xl" aria-hidden="true"><?php echo $typeIcons[$d['filetype']] ?? $typeIcons['overig']; ?></span>
                    <div>
                        <p class="font-medium text-slate-800"><?php echo e($d['filename']); ?></p>
                        <p class="text-xs text-slate-400">
                            <?php echo $catLabels[$d['category']] ?? 'Overig'; ?>
                            <?php if ($d['project_id'] && isset($projectNames[$d['project_id']])): ?>
                                &middot; project: <?php echo e($projectNames[$d['project_id']]); ?>
                            <?php endif; ?>
                            &middot; <?php echo (int) $d['size_kb']; ?> KB &middot; door <?php echo e($d['uploaded_by']); ?>
                            &middot; <?php echo date('d-m-Y', strtotime($d['created_at'])); ?>
                        </p>
                    </div>
                </div>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="download">
                    <input type="hidden" name="document_id" value="<?php echo (int) $d['id']; ?>">
                    <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.4rem .8rem;">Downloaden</button>
                </form>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100">
                <p class="text-xs font-semibold text-slate-400 uppercase mb-2">Reacties</p>
                <?php foreach (($commentsByDoc[$d['id']] ?? []) as $c): ?>
                    <div class="text-sm mb-2">
                        <span class="font-medium text-slate-700"><?php echo e($c['author']); ?></span>
                        <span class="text-xs text-slate-400 ml-1"><?php echo date('d-m-Y H:i', strtotime($c['created_at'])); ?></span>
                        <p class="text-slate-600"><?php echo e($c['comment']); ?></p>
                    </div>
                <?php endforeach; ?>
                <form method="POST" class="flex gap-2 mt-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="document_id" value="<?php echo (int) $d['id']; ?>">
                    <input type="text" name="comment" placeholder="Voeg een reactie toe..." required
                           class="flex-1 px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
                    <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.4rem .8rem;">Plaatsen</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var input = document.getElementById('fileInput');
    var label = document.getElementById('detectedType');
    var typeMap = { pdf: 'PDF-document', png: 'Afbeelding', jpg: 'Afbeelding', jpeg: 'Afbeelding', gif: 'Afbeelding', webp: 'Afbeelding', doc: 'Word-document', docx: 'Word-document', xls: 'Excel-bestand', xlsx: 'Excel-bestand', csv: 'Excel-bestand' };
    function announce() {
        if (!input.files.length) return;
        var name = input.files[0].name;
        var ext = name.split('.').pop().toLowerCase();
        var type = typeMap[ext] || 'Overig bestandstype';
        label.textContent = name + ' — herkend als: ' + type;
    }
    input.addEventListener('change', announce);
    document.getElementById('uploadDropzone').addEventListener('drop', function () { setTimeout(announce, 50); });
})();
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
