<?php
/**
 * cli/migrate.php — uruchomienie migracji schematu z linii poleceń.
 *
 * Używane przez:
 *   - docker/update.sh  (po git pull)
 *   - cron / ręcznie:    php cli/migrate.php
 *
 * Bezpieczne do wielokrotnego uruchamiania (idempotentne). Nie usuwa danych.
 * Zapisuje installed_version = bieżący hash HEAD do settings.
 *
 * Kody wyjścia: 0 = OK (brak błędów), 1 = były błędy migracji, 2 = błąd krytyczny.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);

if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}

// Bootstrap bez pełnego bootstrap.php (migracje mogą zmieniać schemat).
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/version.php';
require_once $base . '/setup/setup_sql.php';

function cli_line(string $s): void { fwrite(STDOUT, $s . "\n"); }

cli_line("━━ Migracje schematu bazy ━━━━━━━━━━━━━━━━━━━━━━━━");

try {
    $pdo = db();
} catch (\Throwable $e) {
    fwrite(STDERR, "Błąd połączenia z bazą: " . $e->getMessage() . "\n");
    exit(2);
}

// Bramka samonaprawy (szo_schema_current w includes/db.php): po jawnej migracji
// wszystkie *_migrate() mają się wykonać ponownie przy najbliższym żądaniu.
szo_schema_reset();
foreach (szo_selfrepair_run() as $_id => $_st) cli_line("  samonaprawa {$_id}: {$_st}");

try {
    $results = migrate_tenant_db($pdo);
} catch (\Throwable $e) {
    fwrite(STDERR, "Błąd krytyczny migracji: " . $e->getMessage() . "\n");
    exit(2);
}

$ok = $skip = $err = 0;
foreach ($results as [$status, $label]) {
    if ($status === 'ok')   { $ok++;   cli_line("  ✔ {$label}"); }
    elseif ($status === 'err') { $err++; cli_line("  ✖ {$label}"); }
    // 'skip' nie zaśmiecamy wyjścia — pokazujemy tylko podsumowanie
}

// Zapisz aktualną wersję (hash HEAD) do settings.
try {
    $ver  = app_version();
    $hash = $ver['hash'] ?? '';
    if ($hash && $hash !== 'unknown') {
        $exists = $pdo->query("SELECT 1 FROM settings WHERE key_='installed_version'")->fetchColumn();
        if ($exists) {
            $pdo->prepare("UPDATE settings SET value=? WHERE key_='installed_version'")->execute([$hash]);
        } else {
            $pdo->prepare("INSERT INTO settings (key_,value) VALUES ('installed_version',?)")->execute([$hash]);
        }
        cli_line("  ▸ installed_version = {$hash}");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  ⚠ Nie zapisano installed_version: " . $e->getMessage() . "\n");
}

cli_line("━━ Gotowe: nowych {$ok}, pominięto " . (count($results) - $ok - $err) . ", błędów {$err} ━━");

exit($err > 0 ? 1 : 0);
