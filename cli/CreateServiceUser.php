<?php
/**
 * ServiceAccountCreate.php
 * Skrypt do jednorazowego tworzenia/aktualizacji konta serwisowego.
 * Automatycznie szuka konfiguracji w górę struktury katalogów.
 */

// Funkcja lokalizująca pliki w górę struktury katalogów
function findPath($target) {
    $currentDir = __DIR__;
    while (!file_exists($currentDir . '/' . $target) && $currentDir !== '/' && $currentDir !== '/root') {
        $currentDir = dirname($currentDir);
    }
    return $currentDir . '/' . $target;
}

$configFile = findPath('config.php');
$dbFile     = findPath('includes/db.php');
$lockFile   = dirname($configFile) . '/.INSTALL_COMPLETE';

// Wymagane pliki
if (!file_exists($configFile) || !file_exists($dbFile)) {
    die("Błąd: Nie odnaleziono plików konfiguracyjnych w drzewie katalogów.\n");
}
require_once $configFile;
require_once $dbFile;

// Blokada po pierwszej instalacji
if (file_exists($lockFile)) {
    die("Instalacja została już zakończona (wykryto .INSTALL_COMPLETE). Skrypt zablokowany.\n");
}

// Tylko CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Dostęp tylko z CLI.');
}

$isCli = true;
$message = "";

// Logika zapisu do bazy
if ($isCli) {
    $name     = 'Konto serwisowe';
    $email    = 'serwis@local';
    $password = bin2hex(random_bytes(12)); // losowe hasło — nie 'serwis'
    $role     = 'admin';
    $hash     = password_hash($password, PASSWORD_BCRYPT);

    try {
        $existing = db_one("SELECT id FROM users WHERE email=?", [$email]);
        
        if ($existing) {
            $stmt = db()->prepare("UPDATE users SET name=?, password=?, role=?, is_active=1 WHERE email=?");
            $stmt->execute([$name, $hash, $role, $email]);
            $action = "zaktualizowane";
        } else {
            $stmt = db()->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?,?,?,?,1)");
            $stmt->execute([$name, $email, $hash, $role]);
            $action = "utworzone";
        }

        // Zapisanie pliku blokady o uniwersalnej nazwie .INSTALL_COMPLETE
        file_put_contents($lockFile, date('Y-m-d H:i:s'));
        $message = "SUKCES: Konto serwisowe zostało $action. Plik blokady .INSTALL_COMPLETE utworzony.";
    } catch (Exception $e) {
        $message = "Błąd bazy danych: " . $e->getMessage();
    }
}

echo $message . "\n";
exit;