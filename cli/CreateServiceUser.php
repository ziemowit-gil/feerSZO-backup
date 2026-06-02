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

$isCli = (php_sapi_name() === 'cli');
$message = "";

// Obsługa CLI - interaktywne potwierdzenie
if ($isCli) {
    echo "--- SERVICE ACCOUNT CREATE ---\n";
    echo "Działanie: Utworzenie lub aktualizacja konta serwisowego.\n";
    echo "Dane: serwis@local / hasło: serwis / rola: admin\n";
    echo "Czy chcesz kontynuować? (t/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 't') {
        echo "Anulowano.\n";
        exit;
    }
}

// Logika zapisu do bazy
if (($_SERVER['REQUEST_METHOD'] === 'POST') || $isCli) {
    $name     = 'Konto serwisowe';
    $email    = 'serwis@local';
    $password = 'serwis';
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

if ($isCli) {
    echo $message . "\n";
    exit;
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>ServiceAccountCreate</title>
</head>
<body class="bg-light p-5">
    <div class="container card p-4 shadow-sm" style="max-width: 400px;">
        <h4 class="mb-4">ServiceAccountCreate</h4>
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php else: ?>
            <p>Zalecane jest uruchomienie z CLI. Jeśli wolisz, kliknij poniżej:</p>
            <form method="POST">
                <button type="submit" class="btn btn-primary w-100">Utwórz konto serwisowe</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>