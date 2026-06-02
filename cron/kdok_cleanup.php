<?php
/**
 * Czyszczenie modułu dokumentów księgowych.
 *
 * Uruchamiaj z CLI lub crona np. co tydzień:
 *   php /path/to/umowy/cron/kdok_cleanup.php
 *   0 3 * * 0 php /path/to/umowy/cron/kdok_cleanup.php >> /var/log/kdok_cleanup.log 2>&1
 */

define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

kdok_migrate();

// Zachowaj 3 ostatnie wersje PDF na dokument, usuń starsze niż 90 dni
$stats = kdok_cleanup_old_generated(keep_per_doc: 3, older_than_days: 90);

$ts  = date('Y-m-d H:i:s');
$msg = "[{$ts}] KDOK cleanup: usunięto {$stats['deleted_files']} plików, "
     . "zwolniono " . number_format($stats['freed_bytes'] / 1024 / 1024, 2) . " MB";

if ($stats['errors']) {
    $msg .= " | BŁĘDY: " . implode('; ', $stats['errors']);
}

echo $msg . PHP_EOL;

// Weryfikacja sum kontrolnych istniejących plików
$rows = db_all("SELECT g.*, d.number FROM kdok_generated_pdf g JOIN kdok_documents d ON d.id = g.doc_id");
$bad  = 0;
foreach ($rows as $row) {
    $abs = UPLOAD_DIR . $row['file_path'];
    if (!is_file($abs)) {
        echo "[{$ts}] OSTRZEŻENIE: Brak pliku {$row['file_path']} (dok. {$row['number']})\n";
        $bad++;
        continue;
    }
    $actual = hash_file('sha256', $abs);
    if ($actual !== $row['file_sha256']) {
        echo "[{$ts}] BŁĄD INTEGRALNOŚCI: {$row['file_path']} (dok. {$row['number']}) — hash niezgodny!\n";
        $bad++;
    }
}

$orig_rows = db_all("SELECT id, number, file_path, file_sha256 FROM kdok_documents WHERE file_path IS NOT NULL");
foreach ($orig_rows as $row) {
    $abs = UPLOAD_DIR . $row['file_path'];
    if (!is_file($abs)) {
        echo "[{$ts}] OSTRZEŻENIE: Brak oryginału {$row['file_path']} (dok. {$row['number']})\n";
        $bad++;
        continue;
    }
    $actual = hash_file('sha256', $abs);
    if ($actual !== $row['file_sha256']) {
        echo "[{$ts}] BŁĄD INTEGRALNOŚCI ORYGINAŁU: {$row['file_path']} (dok. {$row['number']})\n";
        $bad++;
    }
}

if ($bad === 0) {
    echo "[{$ts}] Weryfikacja sum kontrolnych: wszystkie pliki OK (" . (count($rows) + count($orig_rows)) . " plików)\n";
}
