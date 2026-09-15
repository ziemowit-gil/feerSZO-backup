<?php
/**
 * ResetServiceUserPassword.php
 * Ustawia NOWE, losowe hasło dla istniejącego konta (domyślnie: konto
 * serwisowe, serwis@local) i wypisuje je na ekran — to jedyny moment,
 * kiedy jest widoczne (baza trzyma tylko hash bcrypt, jednokierunkowy).
 *
 * Użycie:
 *   php cli/ResetServiceUserPassword.php                     # serwis@local
 *   php cli/ResetServiceUserPassword.php inny@email.pl        # inne konto
 */

function findPath($target) {
    $currentDir = __DIR__;
    while (!file_exists($currentDir . '/' . $target) && $currentDir !== '/' && $currentDir !== '/root') {
        $currentDir = dirname($currentDir);
    }
    return $currentDir . '/' . $target;
}

$configFile = findPath('config.php');
$dbFile     = findPath('includes/db.php');

if (!file_exists($configFile) || !file_exists($dbFile)) {
    die("Błąd: Nie odnaleziono plików konfiguracyjnych w drzewie katalogów.\n");
}
require_once $configFile;
require_once $dbFile;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Dostęp tylko z CLI.');
}

$email = $argv[1] ?? 'serwis@local';

$existing = db_one("SELECT id FROM users WHERE email=?", [$email]);
if (!$existing) {
    die("Błąd: nie znaleziono użytkownika o e-mailu {$email}.\n");
}

$password = bin2hex(random_bytes(12));
$hash     = password_hash($password, PASSWORD_BCRYPT);
db()->prepare("UPDATE users SET password=? WHERE email=?")->execute([$hash, $email]);

echo "SUKCES: hasło zresetowane.\n";
echo "E-mail:  {$email}\n";
echo "Hasło:   {$password}   (zapisz je teraz — to jedyny moment, kiedy jest widoczne)\n";
