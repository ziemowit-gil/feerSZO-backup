<?php
/**
 * cli/cleanup_edok_kontrahent_test.php — usuwa konto i dokumenty testowe
 * założone przez cli/seed_edok_kontrahent_test.php. Dopasowuje WYŁĄCZNIE po
 * dokładnych numerach/loginie użytych przy zakładaniu — nie rusza żadnych
 * innych danych.
 *
 * Użycie:
 *   php cli/cleanup_edok_kontrahent_test.php [domena_email]
 *
 * Przykład (domyślna domena "feer.test", jak w seed_edok_kontrahent_test.php):
 *   php cli/cleanup_edok_kontrahent_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$domain = $argv[1] ?? 'feer.test';
$email  = 'kontrahent.test@' . $domain;

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';

$acc = db_one("SELECT id FROM edok_kontrahent_accounts WHERE email=?", [$email]);
if ($acc) {
    db_exec("DELETE FROM edok_kontrahent_accounts WHERE id=?", [$acc['id']]);
    echo "USUNIĘTO konto {$email} (id={$acc['id']}).\n";
} else {
    echo "Konto {$email} nie istnieje — pomijam.\n";
}

foreach (['A', 'B', 'C'] as $suffix) {
    $number = 'EODOK-TEST/' . $suffix;
    $doc = db_one("SELECT id, file_path FROM edok_documents WHERE number=?", [$number]);
    if (!$doc) {
        echo "Dokument {$number} nie istnieje — pomijam.\n";
        continue;
    }
    foreach (db_all("SELECT file_path FROM edok_generated_pdf WHERE doc_id=?", [$doc['id']]) as $g) {
        if ($g['file_path']) @unlink(UPLOAD_DIR . $g['file_path']);
    }
    if (!empty($doc['file_path'])) @unlink(UPLOAD_DIR . $doc['file_path']);
    db_exec("DELETE FROM edok_generated_pdf WHERE doc_id=?", [$doc['id']]);
    db_exec("DELETE FROM edok_steps WHERE doc_id=?", [$doc['id']]);
    db_exec("DELETE FROM edok_events WHERE doc_id=?", [$doc['id']]);
    db_exec("DELETE FROM edok_documents WHERE id=?", [$doc['id']]);
    echo "USUNIĘTO dokument {$number} (id={$doc['id']}).\n";
}

echo "\nGotowe.\n";
