<?php
/**
 * Skrypt cron: czyszczenie plików harmonogramów dołączanych do maili TI
 * (uploads/ti_harmonogram_mail/ — PDF/XLSX/TXT generowane przy zatwierdzaniu
 * lekcji, zmianie terminów i zatwierdzaniu planu cyklicznego).
 *   30 3 * * * php /var/www/html/cron/ti_harmonogram_cleanup.php
 *
 * Kasuje pliki starsze niż 30 dni (--days=N zmienia próg), ale NIGDY plik, do którego
 * odwołuje się wiadomość czekająca jeszcze w kolejce (mail_queue.status='pending').
 * --dry-run tylko wypisuje, co zostałoby usunięte.
 */
if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';

$days = 30; $dry = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) $days = max(1, (int)$m[1]);
    if ($a === '--dry-run') $dry = true;
}

echo "[" . date('Y-m-d H:i:s') . "] Start: ti_harmonogram_cleanup (próg {$days} dni" . ($dry ? ', dry-run' : '') . ")\n";

$dir = rtrim(UPLOAD_DIR, '/') . '/ti_harmonogram_mail';
if (!is_dir($dir)) { echo "Brak katalogu — nic do zrobienia.\n"; exit(0); }

// Nazwy plików, które jeszcze są potrzebne czekającym mailom
$needed = [];
try {
    foreach (db_all("SELECT attachments FROM mail_queue WHERE status='pending' AND attachments LIKE '%ti_harmonogram_mail%'") as $r) {
        foreach (json_decode((string)$r['attachments'], true) ?: [] as $att) {
            if (!empty($att['path'])) $needed[basename((string)$att['path'])] = true;
        }
    }
} catch (\Throwable $e) {
    echo "Nie można odczytać kolejki maili (" . $e->getMessage() . ") — przerywam, żeby nie skasować potrzebnych plików.\n";
    exit(1);
}

$limit = time() - $days * 86400;
$deleted = 0; $kept = 0; $bytes = 0;
foreach (glob($dir . '/*') ?: [] as $f) {
    if (!is_file($f)) continue;
    if (filemtime($f) > $limit || isset($needed[basename($f)])) { $kept++; continue; }
    $size = (int)filesize($f);
    if ($dry || @unlink($f)) { $deleted++; $bytes += $size; if ($dry) echo "  usunięto by: " . basename($f) . "\n"; }
}
echo "Usunięto: {$deleted} (" . round($bytes / 1024) . " KB), zostawiono: {$kept}.\n";
