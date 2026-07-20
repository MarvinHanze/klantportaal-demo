<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
ensure_schema();
check_auto_reset();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if ($email === AUTH_EMAIL && $pass === AUTH_PASS) {
        $_SESSION['authenticated'] = true;
        header('Location: ' . BASE . '/index.php');
        exit;
    }
    $error = 'Ongeldige inloggegevens.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klantportaal - Inloggen</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center px-4">
<div class="w-full max-w-md">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <div class="flex items-center justify-center gap-3 mb-8">
            <svg class="w-10 h-10 text-purple-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
            </svg>
            <h1 class="text-2xl font-bold text-slate-800">Klantportaal</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                <input type="email" id="email" name="email" required
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition"
                       value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                <input type="password" id="password" name="password" required
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition">
            </div>
            <button type="submit"
                    class="w-full py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                Inloggen
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-slate-400">Demo: admin@demo.nl / demo123</p>
    </div>
</div>
</body>
</html>
