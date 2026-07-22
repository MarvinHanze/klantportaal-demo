<?php
declare(strict_types=1);

require_once __DIR__ . '/assets/icons.php';

/* ─────────────────────────── Foutafhandeling ───────────────────────────
   Nooit ruwe PHP-fouten/stacktraces (met paden, queries, etc.) tonen aan de browser.
   Fouten gaan naar de PHP-errorlog; de bezoeker krijgt een nette generieke pagina. */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log('Onafgevangen exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Er ging iets mis</title></head>'
        . '<body style="font-family:system-ui,sans-serif;padding:4rem 2rem;text-align:center;color:#475569;">'
        . '<h1 style="color:#1e293b;">Er is iets misgegaan</h1>'
        . '<p>Probeer het straks nogmaals. Het probleem is gelogd.</p></body></html>';
    exit;
});

define('BASE', '/klantportaal');
define('DEMO_RESET_MINUTES', 30);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');

/* Demo-inloggegevens (2 losse klant-accounts, tonen data-isolatie).
   Wachtwoord voldoet aan de eigen sterkte-eisen (zie validate_password_strength()). */
define('DEMO_USER_PASSWORD', 'Demo1234!');

/* ─────────────────────────── Helpers: output/escaping ─────────────────────────── */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Maakt een hex-kleur iets donkerder, voor hover-states van de whitelabel brandkleur. */
function darken_hex(string $hex, float $amount = 0.18): string
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return '#6d28d9';
    }
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $r = (int) max(0, min(255, $r * (1 - $amount)));
    $g = (int) max(0, min(255, $g * (1 - $amount)));
    $b = (int) max(0, min(255, $b * (1 - $amount)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/* ─────────────────────────── Helpers: CSRF ─────────────────────────── */

function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRFToken()) . '">';
}

function verifyCSRF(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
}

/* ─────────────────────────── Helpers: database ─────────────────────────── */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/* ─────────────────────────── Helpers: auth / sessie / multi-tenancy ───────────────────────────
   BELANGRIJKSTE BEVEILIGINGSREGEL VAN DEZE APP:
   Elke query op klantdata MOET gefilterd zijn op account_id = currentAccountId().
   Dit voorkomt dat klant A via URL-manipulatie (bv. ?id=5 aanpassen) data van
   klant B kan zien of wijzigen. Zie elke pagina: WHERE ... AND account_id = ?. */

/** Start de sessie met veilige cookie-instellingen (httponly, samesite, secure-over-https).
 *  Moet worden aangeroepen vóórdat er output is verstuurd, en in plaats van een kale
 *  session_start(). Pagina's moeten config.php requiren vóórdat ze de sessie starten,
 *  anders zijn deze cookie-parameters al te laat om effect te hebben. */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ─────────────────────────── Brute-force bescherming (login) ───────────────────────────
   Simpele sessie-gebaseerde lockout: na te veel mislukte wachtwoordpogingen moet de
   gebruiker even wachten voor een volgende poging. Geen aparte tabel nodig voor deze demo;
   de teller leeft in de sessie (net als $_SESSION['totp_attempts'] hierboven). */

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 60;

function loginLockoutRemaining(): int
{
    $until = (int) ($_SESSION['login_locked_until'] ?? 0);
    return max(0, $until - time());
}

function registerFailedLogin(): void
{
    $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;
    if ($_SESSION['login_fail_count'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['login_locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
        $_SESSION['login_fail_count'] = 0;
    }
}

function resetFailedLogins(): void
{
    unset($_SESSION['login_fail_count'], $_SESSION['login_locked_until']);
}

function requireAuth(): void
{
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id']) || empty($_SESSION['account_id'])) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

function currentAccountId(): int
{
    return (int) ($_SESSION['account_id'] ?? 0);
}

function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function currentUserName(): string
{
    return (string) ($_SESSION['user_name'] ?? '');
}

function currentUserEmail(): string
{
    return (string) ($_SESSION['user_email'] ?? '');
}

function currentAccount(): array
{
    static $account = null;
    if ($account === null) {
        $stmt = db()->prepare("SELECT * FROM kp_accounts WHERE id = ?");
        $stmt->execute([currentAccountId()]);
        $account = $stmt->fetch() ?: [];
    }
    return $account;
}

function logAudit(string $action, string $entityType, ?int $entityId = null, string $details = ''): void
{
    db()->prepare("INSERT INTO kp_audit_log (account_id, user_email, action, entity_type, entity_id, details, ip_address)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([
            currentAccountId(),
            currentUserEmail(),
            $action,
            $entityType,
            $entityId,
            $details,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
}

function createNotification(int $accountId, string $message, ?string $link = null): void
{
    db()->prepare("INSERT INTO kp_notifications (account_id, message, link) VALUES (?,?,?)")
        ->execute([$accountId, $message, $link]);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function unreadNotificationCount(int $accountId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM kp_notifications WHERE account_id = ? AND is_read = 0");
    $stmt->execute([$accountId]);
    return (int) $stmt->fetchColumn();
}

/* ─────────────────────────── Helpers: wachtwoordsterkte ─────────────────────────── */

function validate_password_strength(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Wachtwoord moet minimaal 8 tekens bevatten.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Wachtwoord moet minimaal één cijfer bevatten.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Wachtwoord moet minimaal één hoofdletter bevatten.';
    }
    return $errors;
}

/* ─────────────────────────── TOTP (RFC 6238) — eigen, compacte implementatie ───────────────────────────
   Geen Composer-dependency: base32 encode/decode + HMAC-SHA1 HOTP (RFC 4226) + tijdstap-venster. */

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $bytes .= chr((int) bindec($byte));
    }
    return $bytes;
}

function totp_generate_secret(int $length = 20): string
{
    return totp_base32_encode(random_bytes($length));
}

function totp_code_at(string $secretBase32, int $timestamp, int $period = 30, int $digits = 6): string
{
    $key = totp_base32_decode($secretBase32);
    $counter = intdiv($timestamp, $period);
    $binCounter = pack('N', 0) . pack('N', $counter); // 8-byte big-endian teller
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $code = $truncated % (10 ** $digits);
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

function totp_current_code(string $secretBase32): string
{
    return totp_code_at($secretBase32, time());
}

function totp_verify(string $secretBase32, string $code, int $window = 1, int $period = 30): bool
{
    $code = trim($code);
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($secretBase32, $now + ($i * $period), $period), $code)) {
            return true;
        }
    }
    return false;
}

function totp_otpauth_uri(string $secretBase32, string $accountEmail, string $issuer = 'Klantportaal'): string
{
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($accountEmail),
        $secretBase32,
        rawurlencode($issuer)
    );
}

/* ─────────────────────────── Schema ─────────────────────────── */

function ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(150) NOT NULL,
        brand_color VARCHAR(7) NOT NULL DEFAULT '#7c3aed',
        logo_initials VARCHAR(4) NOT NULL DEFAULT 'KP',
        logo_data_uri MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        totp_secret VARCHAR(64) NULL,
        totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('concept','verzonden','geaccepteerd','afgewezen') DEFAULT 'verzonden',
        valid_until DATE NULL,
        signed_by VARCHAR(150) NULL,
        signed_at DATETIME NULL,
        signed_ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        quote_id INT NULL,
        number VARCHAR(20) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('open','betaald','te_laat') DEFAULT 'open',
        due_date DATE NULL,
        paid_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT NULL,
        progress_percent INT NOT NULL DEFAULT 0,
        status ENUM('gepland','lopend','afgerond') DEFAULT 'lopend',
        deadline DATE NULL,
        deadline_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        project_id INT NULL,
        filename VARCHAR(255) NOT NULL,
        filetype VARCHAR(20) NOT NULL DEFAULT 'overig',
        size_kb INT NOT NULL DEFAULT 0,
        category VARCHAR(30) NOT NULL DEFAULT 'overig',
        uploaded_by VARCHAR(150) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_document_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        account_id INT NOT NULL,
        author VARCHAR(150) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_email VARCHAR(100) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('laag','normaal','hoog','urgent') DEFAULT 'normaal',
        status ENUM('open','bezig','wachtend','opgelost') DEFAULT 'open',
        department VARCHAR(50) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_ticket_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        account_id INT NOT NULL,
        author VARCHAR(100) NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    /* Kennisbank is bewust gedeeld/globaal (geen account_id): dit is publieke documentatie,
       geen klantspecifieke data, dus geen tenant-scoping nodig. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_kb_articles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        message VARCHAR(255) NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        user_email VARCHAR(150) NOT NULL DEFAULT '',
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT NULL,
        details VARCHAR(255) NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_nps_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        project_id INT NOT NULL,
        score TINYINT NOT NULL,
        comment TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_crm_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL UNIQUE,
        provider VARCHAR(30) NOT NULL DEFAULT 'hubspot',
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        last_sync_at DATETIME NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kp_settings (
        id INT PRIMARY KEY DEFAULT 1,
        last_reset DATETIME
    )");

    /* Accounts + users worden éénmalig aangemaakt (idempotent) zodat login-gegevens
       en 2FA-status stabiel blijven tussen de periodieke demo-reseeds van bedrijfsdata. */
    $count = (int) $pdo->query("SELECT COUNT(*) FROM kp_accounts")->fetchColumn();
    if ($count === 0) {
        seed_accounts_and_users();
    }
}

function seed_accounts_and_users(): void
{
    $pdo = db();

    $pdo->prepare("INSERT INTO kp_accounts (company_name, brand_color, logo_initials) VALUES (?,?,?)")
        ->execute(['Bakker & Zonen BV', '#7c3aed', 'BZ']);
    $account1 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO kp_accounts (company_name, brand_color, logo_initials) VALUES (?,?,?)")
        ->execute(['De Vries Consultancy', '#0891b2', 'DV']);
    $account2 = (int) $pdo->lastInsertId();

    $hash = password_hash(DEMO_USER_PASSWORD, PASSWORD_BCRYPT);

    $pdo->prepare("INSERT INTO kp_users (account_id, name, email, password_hash) VALUES (?,?,?,?)")
        ->execute([$account1, 'Marloes Bakker', 'klant@bakkerzonen.nl', $hash]);

    $pdo->prepare("INSERT INTO kp_users (account_id, name, email, password_hash) VALUES (?,?,?,?)")
        ->execute([$account2, 'Thomas de Vries', 'klant@devriesconsult.nl', $hash]);

    $pdo->prepare("INSERT INTO kp_crm_settings (account_id) VALUES (?)")->execute([$account1]);
    $pdo->prepare("INSERT INTO kp_crm_settings (account_id) VALUES (?)")->execute([$account2]);
}

function check_auto_reset(): void
{
    $pdo = db();
    $row = $pdo->query("SELECT last_reset FROM kp_settings WHERE id = 1")->fetch();

    if (!$row) {
        $pdo->exec("INSERT INTO kp_settings (id, last_reset) VALUES (1, '1970-01-01 00:00:00')");
        $row = $pdo->query("SELECT last_reset FROM kp_settings WHERE id = 1")->fetch();
    }

    $last = strtotime((string) $row['last_reset']);
    $minutes_since = (time() - $last) / 60;

    if ($minutes_since >= DEMO_RESET_MINUTES) {
        seed_demo_data();
        $pdo->prepare("UPDATE kp_settings SET last_reset = NOW() WHERE id = 1")->execute();
    }
}

function seed_demo_data(): void
{
    $pdo = db();

    /* Alleen bedrijfsdata wordt periodiek ververst; accounts/users/2FA-status blijven staan. */
    foreach (['kp_nps_feedback', 'kp_document_comments', 'kp_documents', 'kp_ticket_notes', 'kp_tickets',
              'kp_notifications', 'kp_audit_log', 'kp_invoices', 'kp_quotes', 'kp_projects', 'kp_kb_articles'] as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }
    $pdo->exec("UPDATE kp_crm_settings SET enabled = 0, last_sync_at = NULL");

    $accounts = $pdo->query("SELECT id FROM kp_accounts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if (count($accounts) < 2) {
        return;
    }
    [$a1, $a2] = $accounts;

    seed_kb_articles();
    seed_account_business_data($a1, 'Bakker & Zonen BV', 'Marloes Bakker', 'klant@bakkerzonen.nl');
    seed_account_business_data($a2, 'De Vries Consultancy', 'Thomas de Vries', 'klant@devriesconsult.nl');
}

function seed_kb_articles(): void
{
    $pdo = db();
    $articles = [
        ['Facturatie', 'Hoe kan ik een factuur betalen?', "Open het onderdeel 'Facturen' in het portaal, klik op de gewenste openstaande factuur en kies 'Betalen'. Je doorloopt daarna een korte (gesimuleerde) betaalflow. Zodra de betaling is verwerkt verandert de status naar 'Betaald'."],
        ['Facturatie', 'Waarom staat mijn factuur op \"te laat\"?', 'Een factuur krijgt de status "te laat" zodra de vervaldatum is verstreken zonder registratie van een betaling. Neem contact op via een support-ticket als je denkt dat dit onterecht is.'],
        ['Projecten', 'Hoe zie ik de voortgang van mijn project?', "Ga naar 'Projecten' in de zijbalk. Elk project toont een voortgangsbalk met percentage en de huidige status (gepland, lopend of afgerond)."],
        ['Projecten', 'Kan ik een deadline bevestigen?', "Ja, open het project en klik op 'Bevestig deadline'. Dit wordt vastgelegd inclusief tijdstip, zodat er een duidelijke audit trail ontstaat."],
        ['Account & Beveiliging', 'Hoe stel ik tweestapsverificatie (2FA) in?', 'Bij de eerste keer inloggen wordt automatisch een 2FA-code aangemaakt. Voeg deze toe aan een authenticator-app en bevestig met een code. Daarna is 2FA verplicht bij elke login.'],
        ['Account & Beveiliging', 'Hoe wijzig ik mijn wachtwoord?', "Ga naar 'Profiel & Beveiliging' en gebruik het formulier 'Wachtwoord wijzigen'. Een nieuw wachtwoord moet minimaal 8 tekens, een hoofdletter en een cijfer bevatten."],
        ['Tickets', 'Hoe maak ik een support-ticket aan?', "Ga naar 'Support tickets' en klik op 'Nieuw ticket'. Beschrijf je vraag zo duidelijk mogelijk; je ontvangt een melding zodra de status wijzigt."],
        ['Tickets', 'Vind ik hier geen antwoord op mijn vraag?', "Maak dan een support-ticket aan via 'Support tickets'. Ons team reageert zo snel mogelijk."],
    ];
    $stmt = $pdo->prepare("INSERT INTO kp_kb_articles (category, title, body) VALUES (?,?,?)");
    foreach ($articles as $a) {
        $stmt->execute($a);
    }
}

function seed_account_business_data(int $accountId, string $companyName, string $contactName, string $contactEmail): void
{
    $pdo = db();

    /* ── Offertes ── */
    $quotes = [
        ['Website vernieuwing 2026', 'Volledige restyling van de bedrijfswebsite inclusief CMS-migratie.', 8750.00, 'verzonden', '+21 days', null],
        ['Onderhoudscontract jaarlijks', 'Doorlopend onderhoud en support, 12 maanden.', 2400.00, 'geaccepteerd', '-10 days', '-5 days'],
        ['Uitbreiding cloud-infrastructuur', 'Opschalen van serverfrastructuur voor piekbelasting.', 5200.00, 'concept', '+30 days', null],
    ];
    $quoteIds = [];
    $stmt = $pdo->prepare("INSERT INTO kp_quotes (account_id, title, description, amount, status, valid_until, signed_by, signed_at, signed_ip)
                           VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($quotes as $q) {
        [$title, $desc, $amount, $status, $validOffset, $signedOffset] = $q;
        $validUntil = date('Y-m-d', strtotime($validOffset));
        $signedBy = $status === 'geaccepteerd' ? $contactName : null;
        $signedAt = ($status === 'geaccepteerd' && $signedOffset) ? date('Y-m-d H:i:s', strtotime($signedOffset)) : null;
        $signedIp = $status === 'geaccepteerd' ? '203.0.113.' . random_int(2, 250) : null;
        $stmt->execute([$accountId, $title, $desc, $amount, $status, $validUntil, $signedBy, $signedAt, $signedIp]);
        $quoteIds[] = (int) $pdo->lastInsertId();
    }

    /* ── Facturen (1 gekoppeld aan de geaccepteerde offerte) ── */
    $invoices = [
        [$quoteIds[1], 'INV-' . $accountId . '-2041', 2400.00, 'betaald', '-3 days', '-4 days'],
        [null, 'INV-' . $accountId . '-2042', 640.00, 'open', '+12 days', null],
        [null, 'INV-' . $accountId . '-2043', 1180.00, 'te_laat', '-8 days', null],
    ];
    $stmt = $pdo->prepare("INSERT INTO kp_invoices (account_id, quote_id, number, amount, status, due_date, paid_at)
                           VALUES (?,?,?,?,?,?,?)");
    foreach ($invoices as $inv) {
        [$quoteId, $number, $amount, $status, $dueOffset, $paidOffset] = $inv;
        $due = date('Y-m-d', strtotime($dueOffset));
        $paidAt = $paidOffset ? date('Y-m-d H:i:s', strtotime($paidOffset)) : null;
        $stmt->execute([$accountId, $quoteId, $number, $amount, $status, $due, $paidAt]);
    }

    /* ── Projecten ── */
    $projects = [
        ['Website vernieuwing 2026', 'Herbouw van de publieke website op basis van de geaccepteerde offerte.', 35, 'lopend', '+25 days', 0],
        ['Interne rapportagedashboard', 'Ontwikkeling van een intern dashboard voor managementrapportages.', 100, 'afgerond', '-14 days', 1],
        ['Migratie e-mailsysteem', 'Overzetten van bestaande mailboxen naar nieuw platform.', 100, 'afgerond', '-40 days', 1],
    ];
    $projectIds = [];
    $stmt = $pdo->prepare("INSERT INTO kp_projects (account_id, name, description, progress_percent, status, deadline, deadline_confirmed)
                           VALUES (?,?,?,?,?,?,?)");
    foreach ($projects as $p) {
        [$name, $desc, $progress, $status, $deadlineOffset, $confirmed] = $p;
        $deadline = date('Y-m-d', strtotime($deadlineOffset));
        $stmt->execute([$accountId, $name, $desc, $progress, $status, $deadline, $confirmed]);
        $projectIds[] = (int) $pdo->lastInsertId();
    }

    /* ── NPS-feedback: alleen voor het oudste afgeronde project, zodat het tweede
         afgeronde project nog "open staat" voor een nieuwe feedback-inzending in de demo. ── */
    $pdo->prepare("INSERT INTO kp_nps_feedback (account_id, project_id, score, comment, created_at) VALUES (?,?,?,?,?)")
        ->execute([$accountId, $projectIds[2], 9, 'Prima samenwerking, duidelijke communicatie tijdens de migratie.', date('Y-m-d H:i:s', strtotime('-35 days'))]);

    /* ── Documenten ── */
    $documents = [
        [$projectIds[0], 'Projectplan-Website-2026.pdf', 'pdf', 480, 'contract', $contactName],
        [$projectIds[0], 'Wireframes-v2.png', 'afbeelding', 1200, 'overig', 'Support'],
        [null, 'Onderhoudscontract-2026.pdf', 'pdf', 210, 'contract', 'Support'],
        [$projectIds[1], 'Opleverrapport-Dashboard.docx', 'word', 96, 'rapportage', 'Support'],
    ];
    $docIds = [];
    $stmt = $pdo->prepare("INSERT INTO kp_documents (account_id, project_id, filename, filetype, size_kb, category, uploaded_by)
                           VALUES (?,?,?,?,?,?,?)");
    foreach ($documents as $d) {
        [$projectId, $filename, $filetype, $size, $category, $uploadedBy] = $d;
        $stmt->execute([$accountId, $projectId, $filename, $filetype, $size, $category, $uploadedBy]);
        $docIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO kp_document_comments (document_id, account_id, author, comment, created_at) VALUES (?,?,?,?,?)")
        ->execute([$docIds[0], $accountId, 'Support', 'Kun je de planning in hoofdstuk 3 nog even bevestigen?', date('Y-m-d H:i:s', strtotime('-6 days'))]);

    /* ── Support tickets ── */
    $tickets = [
        [$contactName, $contactEmail, 'Vraag over factuurregel', 'Ik zie een bedrag op mijn laatste factuur dat ik niet meteen herken. Kunnen jullie dit toelichten?', 'normaal', 'open', 'Financieel'],
        [$contactName, $contactEmail, 'Extra gebruiker toevoegen', 'Kunnen jullie een extra teamlid toegang geven tot het portaal?', 'laag', 'bezig', 'Account'],
        [$contactName, $contactEmail, 'Vertraging opgemerkt in project', 'De voortgang van ons project lijkt al twee weken stil te staan, kunnen jullie een update geven?', 'hoog', 'wachtend', 'Projecten'],
    ];
    $ticketIds = [];
    $stmt = $pdo->prepare("INSERT INTO kp_tickets (account_id, customer_name, customer_email, subject, description, priority, status, department)
                           VALUES (?,?,?,?,?,?,?,?)");
    foreach ($tickets as $t) {
        [$name, $email, $subject, $desc, $priority, $status, $dept] = $t;
        $stmt->execute([$accountId, $name, $email, $subject, $desc, $priority, $status, $dept]);
        $ticketIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO kp_ticket_notes (ticket_id, account_id, author, note, created_at) VALUES (?,?,?,?,?)")
        ->execute([$ticketIds[1], $accountId, 'Support', 'We zijn akkoord, wachten nog op naam en e-mailadres van de nieuwe gebruiker.', date('Y-m-d H:i:s', strtotime('-2 days'))]);

    /* ── Notificaties ── */
    $notifications = [
        ["Nieuwe offerte '{$quotes[0][0]}' klaar om te bekijken.", BASE . '/offertes.php'],
        ["Factuur {$invoices[2][1]} is te laat, controleer de status.", BASE . '/facturen.php'],
        ["Project '{$projects[1][0]}' is afgerond — vul de tevredenheidsenquête in.", BASE . '/feedback.php'],
        ["Ticket-status gewijzigd naar 'bezig' voor '{$tickets[1][2]}'.", BASE . '/tickets.php'],
    ];
    $stmt = $pdo->prepare("INSERT INTO kp_notifications (account_id, message, link, created_at) VALUES (?,?,?,?)");
    foreach ($notifications as $i => $n) {
        $created = date('Y-m-d H:i:s', strtotime('-' . random_int(1, 72) . ' hours'));
        $stmt->execute([$accountId, $n[0], $n[1], $created]);
    }

    /* ── Audit trail ── */
    $auditEvents = [
        ['login', 'account', null, 'Ingelogd op klantportaal'],
        ['accepted', 'quote', $quoteIds[1], "Offerte '{$quotes[1][0]}' digitaal geaccordeerd"],
        ['downloaded', 'document', $docIds[2], "Document '{$documents[2][1]}' gedownload"],
        ['paid', 'invoice', null, "Factuur {$invoices[0][1]} betaald (mock checkout)"],
    ];
    $stmt = $pdo->prepare("INSERT INTO kp_audit_log (account_id, user_email, action, entity_type, entity_id, details, ip_address, created_at)
                           VALUES (?,?,?,?,?,?,?,?)");
    foreach ($auditEvents as $a) {
        [$action, $entityType, $entityId, $details] = $a;
        $created = date('Y-m-d H:i:s', strtotime('-' . random_int(1, 96) . ' hours'));
        $stmt->execute([$accountId, $contactEmail, $action, $entityType, $entityId, $details, '203.0.113.' . random_int(2, 250), $created]);
    }
}
