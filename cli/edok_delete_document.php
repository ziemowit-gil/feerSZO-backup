#!/usr/bin/env php
<?php
/**
 * cli/edok_delete_document.php — jednorazowe, twarde usunięcie dokumentu EODoK
 * razem z powiązaniami (etapy, zdarzenia, wygenerowane PDF-y, wypłaty) i odpięciem
 * odwołań (wyciąg bankowy, kolejka KSeF, rachunki umów zlecenie).
 *
 * Użycie:
 *   php cli/edok_delete_document.php EODoK/0011/2026            # tylko podgląd (nic nie usuwa)
 *   php cli/edok_delete_document.php EODoK/0011/2026 --yes      # usuń
 *   php cli/edok_delete_document.php 123 --yes                  # po id dokumentu
 *   opcje: --with-files  (skasuj też pliki z dysku: skan, dowód zapłaty, wygenerowane PDF)
 *          --db=/sciezka/do/bazy.db  (inna baza niż z config.php, np. kopia do próby)
 *
 * Usunięcie jest nieodwracalne — zrób kopię bazy (cron/agents/backup.php albo cp umowy.db).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

$args   = array_slice($argv, 1);
$yes    = in_array('--yes', $args, true);
$files  = in_array('--with-files', $args, true);
$dbOpt  = null;
foreach ($args as $a) if (str_starts_with($a, '--db=')) $dbOpt = substr($a, 5);
$ident  = '';
foreach ($args as $a) if (!str_starts_with($a, '--')) { $ident = trim($a); break; }

if ($ident === '') {
    fwrite(STDERR, "Podaj numer dokumentu (np. EODoK/0011/2026) albo id.\n");
    exit(2);
}
if ($dbOpt !== null) {
    if (!is_file($dbOpt)) { fwrite(STDERR, "Brak pliku bazy: {$dbOpt}\n"); exit(2); }
    define('DB_PATH', $dbOpt);
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$doc = ctype_digit($ident)
    ? db_one("SELECT * FROM edok_documents WHERE id = ?", [(int)$ident])
    : db_one("SELECT * FROM edok_documents WHERE number = ?", [$ident]);

if (!$doc) {
    // Pomoc: podobne numery (literówka, inny rok)
    $like = db_all("SELECT id, number, status, title FROM edok_documents WHERE number LIKE ? ORDER BY id DESC LIMIT 10",
        ['%' . preg_replace('/^EODoK\/?/i', '', $ident) . '%']);
    fwrite(STDERR, "Nie znaleziono dokumentu „{$ident}”.\n");
    foreach ($like as $l) fwrite(STDERR, sprintf("  podobny: #%d %s [%s] %s\n", $l['id'], $l['number'], $l['status'], $l['title']));
    exit(1);
}

$id = (int)$doc['id'];
$cnt = fn(string $sql, array $p = []) => (int)(db_one($sql, $p)['c'] ?? 0);

$rel = [
    'edok_steps'         => $cnt("SELECT COUNT(*) c FROM edok_steps WHERE doc_id=?", [$id]),
    'edok_events'        => $cnt("SELECT COUNT(*) c FROM edok_events WHERE doc_id=?", [$id]),
    'edok_generated_pdf' => $cnt("SELECT COUNT(*) c FROM edok_generated_pdf WHERE doc_id=?", [$id]),
    'edok_wyplaty'       => $cnt("SELECT COUNT(*) c FROM edok_wyplaty WHERE doc_id=?", [$id]),
    'edok_bank_tx (odpięcie)'   => $cnt("SELECT COUNT(*) c FROM edok_bank_tx WHERE doc_id=?", [$id]),
    'edok_ksef_queue (odpięcie)'=> $cnt("SELECT COUNT(*) c FROM edok_ksef_queue WHERE doc_id=?", [$id]),
    'zlecenie_rachunki (odpięcie)' => $cnt("SELECT COUNT(*) c FROM zlecenie_rachunki WHERE edok_doc_id=?", [$id]),
    'edok_documents.proforma_id (odpięcie)' => $cnt("SELECT COUNT(*) c FROM edok_documents WHERE proforma_id=?", [$id]),
];

$pdfs = db_all("SELECT file_path FROM edok_generated_pdf WHERE doc_id = ?", [$id]);
$paths = array_filter(array_merge(
    [$doc['file_path'] ?? '', $doc['dowod_zaplaty_path'] ?? ''],
    array_column($pdfs, 'file_path')
));

echo "Dokument #{$id}  {$doc['number']}\n";
echo "  tytuł:      " . ($doc['title'] ?: '—') . "\n";
echo "  typ/status: " . ($doc['typ_dokumentu'] ?: '—') . " / {$doc['status']} (płatność: " . ($doc['status_platnosci'] ?: '—') . ")\n";
echo "  kontrahent: " . ($doc['kontrahent_nazwa'] ?: '—') . " " . ($doc['nr_faktury'] ? "faktura {$doc['nr_faktury']}" : '') . "\n";
echo "  kwota:      " . ($doc['kwota_brutto'] !== '' ? $doc['kwota_brutto'] . ' ' . $doc['waluta'] : '—') . "\n";
echo "  umowa:      " . ($doc['contract_type'] ? "{$doc['contract_type']} #{$doc['contract_id']} {$doc['umowa_numer']}" : '—') . "\n";
echo "  utworzył:   " . ($doc['creator_name'] ?: '—') . " " . ($doc['created_at'] ?: '') . "\n";
echo "  powiązania:\n";
foreach ($rel as $k => $v) echo sprintf("    %-40s %d\n", $k, $v);
echo "  pliki (" . ($files ? 'ZOSTANĄ usunięte' : 'zostają na dysku, dodaj --with-files') . "):\n";
foreach ($paths as $p) echo "    " . $p . (is_file(UPLOAD_DIR . ltrim($p, '/')) ? '' : '  (brak na dysku)') . "\n";
if (!$paths) echo "    —\n";

if (!$yes) {
    echo "\nPodgląd. Aby usunąć, uruchom ponownie z --yes" . ($files ? '' : ' (i opcjonalnie --with-files)') . ".\n";
    exit(0);
}

db()->beginTransaction();
try {
    db_exec("DELETE FROM edok_generated_pdf WHERE doc_id = ?", [$id]);
    db_exec("DELETE FROM edok_wyplaty WHERE doc_id = ?", [$id]);
    db_exec("DELETE FROM edok_steps WHERE doc_id = ?", [$id]);
    db_exec("DELETE FROM edok_events WHERE doc_id = ?", [$id]);
    db_exec("UPDATE edok_bank_tx SET doc_id = NULL, matched_how = NULL, matched_by = NULL, matched_by_name = NULL, matched_at = NULL WHERE doc_id = ?", [$id]);
    db_exec("UPDATE edok_ksef_queue SET doc_id = NULL WHERE doc_id = ?", [$id]);
    db_exec("UPDATE zlecenie_rachunki SET edok_doc_id = NULL, edok_number = NULL WHERE edok_doc_id = ?", [$id]);
    db_exec("UPDATE edok_documents SET proforma_id = NULL WHERE proforma_id = ?", [$id]);
    db_exec("DELETE FROM edok_documents WHERE id = ?", [$id]);
    db()->commit();
} catch (\Throwable $e) {
    db()->rollBack();
    fwrite(STDERR, "BŁĄD — nic nie usunięto: {$e->getMessage()}\n");
    exit(1);
}

$removed = 0;
if ($files) {
    foreach ($paths as $p) {
        $abs = UPLOAD_DIR . ltrim($p, '/');
        if (is_file($abs) && @unlink($abs)) $removed++;
    }
}

// Ślad w audycie administracyjnym (jeśli dostępny)
try {
    require_once dirname(__DIR__) . '/includes/admin_audit.php';
    if (function_exists('admin_audit_migrate')) admin_audit_migrate();
    if (function_exists('admin_audit')) {
        admin_audit('edok_delete_document', 'edok',
            'CLI: usunięto dokument EODoK ' . $doc['number'] . ' (' . ($doc['title'] ?: '—') . ', status ' . $doc['status']
            . ', ' . ($doc['kwota_brutto'] !== '' ? $doc['kwota_brutto'] . ' ' . $doc['waluta'] : 'bez kwoty') . ($files ? ', z plikami' : '') . ')',
            $id, $doc['number']);
    }
} catch (\Throwable $e) {
    // audyt opcjonalny — brak nie blokuje operacji
}

echo "\nUsunięto dokument #{$id} {$doc['number']}"
   . ($files ? " i {$removed} plik(ów)" : '') . ".\n";
exit(0);
