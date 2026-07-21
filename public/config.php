<?php
declare(strict_types=1);

define('BASE', '/klantportaal');
define('DEMO_RESET_MINUTES', 30);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');

define('AUTH_EMAIL', 'admin@demo.nl');
define('AUTH_PASS', 'demo123');

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

function ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS klant_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS klant_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        author VARCHAR(100) NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS klant_settings (
        id INT PRIMARY KEY DEFAULT 1,
        last_reset DATETIME
    )");
}

function check_auto_reset(): void
{
    $pdo = db();
    $row = $pdo->query("SELECT last_reset FROM klant_settings WHERE id = 1")->fetch();

    if (!$row) {
        $pdo->exec("INSERT INTO klant_settings (id, last_reset) VALUES (1, '1970-01-01 00:00:00')");
        $row = $pdo->query("SELECT last_reset FROM klant_settings WHERE id = 1")->fetch();
    }

    $last = strtotime((string) $row['last_reset']);
    $minutes_since = (time() - $last) / 60;

    if ($minutes_since >= DEMO_RESET_MINUTES) {
        seed_demo_data();
        $pdo->prepare("UPDATE klant_settings SET last_reset = NOW() WHERE id = 1")->execute();
    }
}

function seed_demo_data(): void
{
    $pdo = db();
    $pdo->exec("TRUNCATE TABLE klant_notes");
    $pdo->exec("TRUNCATE TABLE klant_tickets");

    $tickets = [
        ['Jan de Vries', 'jan@vries.nl', 'Factuur niet ontvangen', 'Ik heb een factuur besteld maar deze niet ontvangen via de e-mail. Kun je deze opnieuw versturen?', 'hoog', 'open', 'Financieel'],
        ['Maria Bakker', 'maria@bakker.nl', 'Wachtwoord reset', 'Ik kan niet meer inloggen op mijn account. Mijn wachtwoord wordt niet herkend.', 'normaal', 'bezig', 'IT Support'],
        ['Peter Jansen', 'peter@jansen.nl', 'Software crash bij opstarten', 'Na de laatste update crasht de software bij het opstarten. Foutmelding: "Unhandled exception".', 'urgent', 'open', 'Technisch'],
        ['Sophie Visser', 'sophie@visser.nl', 'Bestelling vertraging', 'Mijn bestelling #4521 is al 2 weken onderweg. Wanneer kan ik deze verwachten?', 'normaal', 'wachtend', 'Logistiek'],
        ['Thomas Mulder', 'thomas@mulder.nl', 'Account geblokkeerd', 'Mijn account is geblokkeerd na 3 mislukte inlogpogingen. Ik wil dit laten deblokkeren.', 'hoog', 'bezig', 'IT Support'],
        ['Emma Smit', 'emma@smit.nl', 'Klacht over service', 'De klantenservice was erg onbeleefd tegen mij tijdens mijn laatste telefoongesprek.', 'normaal', 'open', 'Klachten'],
        ['Lucas Boer', 'lucas@boer.nl', 'Terugbetaling aanvragen', 'Ik wil graag mijn aankoop retourneren en een terugbetaling ontvangen.', 'laag', 'opgelost', 'Financieel'],
        ['Anna Dijkstra', 'anna@dijkstra.nl', 'API foutmelding bij integratie', 'Onze API-integratie geeft een 500 error sinds gisteren. Onze applicatie kan geen data meer ophalen.', 'urgent', 'bezig', 'Technisch'],
        ['Willem Hendriks', 'willem@hendriks.nl', 'Vraag over abonnement', 'Ik wil overstappen van het Basic naar het Premium abonnement. Hoe werkt dit?', 'laag', 'opgelost', 'Verkoop'],
        ['Lisa de Groot', 'lisa@groot.nl', 'Data export aanvragen', 'Ik wil al mijn gegevens exporteren conform de AVG wetgeving.', 'normaal', 'wachtend', 'IT Support'],
        ['Mark van den Berg', 'mark@berg.nl', 'Factuur incorrect', 'Op mijn factuur staan verkeerde bedragen. De korting is niet toegepast.', 'hoog', 'open', 'Financieel'],
        ['Eva Meijer', 'eva@meijer.nl', 'Nieuwe licentie aanvragen', 'We hebben 5 extra licenties nodig voor onze nieuwe medewerkers.', 'normaal', 'opgelost', 'Verkoop'],
    ];

    $stmt = $pdo->prepare("INSERT INTO klant_tickets (customer_name, customer_email, subject, description, priority, status, department) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($tickets as $t) {
        $stmt->execute($t);
    }

    $notes = [
        [1, 'Support', 'Factuur is opnieuw verstuurd op ' . date('Y-m-d')],
        [2, 'Medewerker A', 'Wachtwoord reset link verstuurd naar klant.'],
        [3, 'Support', 'Bug wordt onderzocht door het development team.'],
        [3, 'Developer', 'Reproduceerbaar gemaakt. Patch in voorbereiding.'],
        [5, 'Support', 'Identiteit geverifieerd via ID-bewijs.'],
        [8, 'Developer', 'Oorzaak gevonden: database connectie timeout na migratie.'],
        [8, 'Support', 'Tijdelijke fix toegepast, wachten op permanente oplossing.'],
        [10, 'Support', 'AVG-verzoek doorgezet naar DPO.'],
    ];

    $noteStmt = $pdo->prepare("INSERT INTO klant_notes (ticket_id, author, note, created_at) VALUES (?, ?, ?, ?)");
    foreach ($notes as $n) {
        $created = date('Y-m-d H:i:s', strtotime('-' . random_int(0, 48) . ' hours'));
        $noteStmt->execute([$n[0], $n[1], $n[2], $created]);
    }
}
