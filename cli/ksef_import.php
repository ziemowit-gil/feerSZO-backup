#!/usr/bin/env php
<?php
/**
 * cli/ksef_import.php — Ręczny import faktury z KSeF do EOD Dokumentów Księgowych
 *
 * Użycie:
 *   php cli/ksef_import.php <numer_ref_ksef>
 *   php cli/ksef_import.php --from=2026-01-01 --to=2026-03-31
 *   php cli/ksef_import.php --sync          (od ostatniej sync)
 *   php cli/ksef_import.php --list          (tylko lista, bez importu)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("Tylko CLI.\n"); }

define('CLI_MODE', true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';
require_once dirname(__DIR__) . '/includes/kdok_ksef.php';

kdok_migrate();
kdok_ksef_migrate();

$opts = getopt('', ['from:', 'to:', 'sync', 'list', 'yes']);
$ref  = $argv[1] ?? null;
$from = $opts['from'] ?? null;
$to   = $opts['to']   ?? date('Y-m-d');
$sync = isset($opts['sync']);
$list = isset($opts['list']);
$yes  = isset($opts['yes']);

// ── Pomocnicze ────────────────────────────────────────────────────────────────

function cli_log(string $msg, string $level = 'INFO'): void {
    $prefix = match($level) {
        'OK'    => "\033[32m✓\033[0m",
        'WARN'  => "\033[33m!\033[0m",
        'ERR'   => "\033[31m✗\033[0m",
        'HEAD'  => "\033[36m►\033[0m",
        default => "\033[90m·\033[0m",
    };
    echo date('[H:i:s] ') . $prefix . ' ' . $msg . "\n";
    error_log("KSEF_IMPORT [{$level}] {$msg}");
}

function cli_confirm(string $question): bool {
    echo $question . ' [t/N] ';
    $ans = strtolower(trim(fgets(STDIN)));
    return $ans === 't' || $ans === 'tak';
}

// ── Sprawdź konfigurację ──────────────────────────────────────────────────────

if (!org_setting('kdok_ksef_enabled')) {
    cli_log('KSeF nie jest włączony. Skonfiguruj w admin → KSeF → Ustawienia.', 'ERR');
    exit(1);
}
if (!org_setting('kdok_ksef_nip')) {
    cli_log('Brak NIP w konfiguracji KSeF.', 'ERR');
    exit(1);
}

cli_log('Środowisko: ' . (KSEF_ENVS[kdok_ksef_env_id()]['label'] ?? kdok_ksef_env_id()), 'HEAD');
cli_log('NIP: ' . org_setting('kdok_ksef_nip'), 'INFO');
cli_log('Metoda auth: ' . kdok_ksef_auth_method(), 'INFO');

// ── Tryb: import pojedynczej faktury po numerze referencyjnym ─────────────────

if ($ref && !str_starts_with($ref, '-')) {
    cli_log("Import faktury: {$ref}", 'HEAD');

    $exists = kdok_one("SELECT * FROM kdok_ksef_queue WHERE ksef_reference=?", [$ref]);
    if ($exists) {
        if ($exists['doc_id']) {
            cli_log("Faktura już w systemie — dokument EOD #{$exists['doc_id']}", 'WARN');
        } else {
            cli_log('Faktura w kolejce, ale bez dokumentu EOD. Tworzę...', 'WARN');
            $doc_id = kdok_ksef_create_doc([
                'ksef_reference' => $ref,
                'invoice_number' => $exists['invoice_number'],
                'seller_name'    => $exists['seller_name'],
                'seller_nip'     => $exists['seller_nip'],
                'gross_value'    => $exists['gross_value'],
                'currency'       => $exists['currency'],
                'issue_date'     => $exists['issue_date'],
            ]);
            kdok_exec("UPDATE kdok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
            cli_log("Utworzono dokument EOD #{$doc_id}", 'OK');
        }
        exit(0);
    }

    try {
        cli_log('Autoryzacja z KSeF...', 'INFO');
        $client = kdok_ksef_client();
        cli_log('Pobieranie XML faktury...', 'INFO');
        $xml  = kdok_ksef_get_invoice_xml(null, $ref);
        $data = kdok_ksef_parse_xml($xml);
        $data['ksef_reference'] = $ref;

        cli_log("Nr faktury : " . ($data['invoice_number'] ?: '—'), 'INFO');
        cli_log("Sprzedawca : " . ($data['seller_name']    ?: '—'), 'INFO');
        cli_log("NIP        : " . ($data['seller_nip']     ?: '—'), 'INFO');
        cli_log("Kwota      : " . ($data['gross_value']    ?: '—') . ' ' . ($data['currency'] ?: ''), 'INFO');

        if (!$yes && !cli_confirm('Zaimportować do EOD?')) {
            cli_log('Anulowano.', 'WARN');
            exit(0);
        }

        kdok_insert('kdok_ksef_queue', [
            'ksef_reference' => $ref,
            'invoice_number' => $data['invoice_number'] ?? '',
            'seller_name'    => $data['seller_name']    ?? '',
            'seller_nip'     => $data['seller_nip']     ?? '',
            'gross_value'    => $data['gross_value']     ?? '',
            'currency'       => $data['currency']        ?? 'PLN',
            'issue_date'     => $data['issue_date']      ?? '',
            'ksef_date'      => date('Y-m-d'),
        ]);
        $doc_id = kdok_ksef_create_doc($data);
        kdok_exec("UPDATE kdok_ksef_queue SET doc_id=? WHERE ksef_reference=?", [$doc_id, $ref]);
        cli_log("Zaimportowano → dokument EOD #{$doc_id}", 'OK');
    } catch (\Throwable $e) {
        cli_log('Błąd: ' . $e->getMessage(), 'ERR');
        exit(1);
    }
    exit(0);
}

// ── Tryb: synchronizacja zakresowa ────────────────────────────────────────────

if ($sync) {
    $last = org_setting('kdok_ksef_last_sync');
    $from = $last ? date('Y-m-d', strtotime($last)) : date('Y-m-d', strtotime('-30 days'));
    cli_log("Synchronizacja przyrostowa od {$from} do {$to}", 'HEAD');
} elseif ($from) {
    cli_log("Synchronizacja zakresu {$from} – {$to}", 'HEAD');
} else {
    echo "\nUżycie:\n";
    echo "  php cli/ksef_import.php <ref_ksef>              — import jednej faktury\n";
    echo "  php cli/ksef_import.php --sync                  — sync od ostatniego razu\n";
    echo "  php cli/ksef_import.php --from=RRRR-MM-DD       — sync od daty\n";
    echo "  php cli/ksef_import.php --from=X --to=Y --list  — tylko wylistuj\n";
    echo "  php cli/ksef_import.php --yes                   — bez pytania\n";
    exit(0);
}

// Sprawdź listę przed importem
if ($list) {
    cli_log('Tryb podglądu — bez importu', 'HEAD');
    try {
        $client = kdok_ksef_client();
        $chunks = kdok_ksef_date_chunks($from, $to, 3);
        foreach ($chunks as [$cf, $ct]) {
            $refs = kdok_ksef_query_by_date(null, $cf, $ct, 'Issue');
            cli_log("Chunk {$cf}–{$ct}: " . count($refs) . ' faktur', 'INFO');
            foreach ($refs as $r) echo "  {$r}\n";
        }
    } catch (\Throwable $e) {
        cli_log('Błąd: ' . $e->getMessage(), 'ERR');
        exit(1);
    }
    exit(0);
}

// Import
try {
    cli_log('Autoryzacja z KSeF...', 'INFO');
    $stats = kdok_ksef_sync_export($from, $to);

    if ($sync) {
        kdok_ksef_setting_save('kdok_ksef_last_sync', date('Y-m-d H:i:s'));
    }

    cli_log("Zaimportowano: {$stats['imported']}", 'OK');
    cli_log("Pominięto:     {$stats['skipped']}", 'INFO');
    if ($stats['errors']) {
        cli_log("Błędy: " . count($stats['errors']), 'WARN');
        foreach ($stats['errors'] as $err) cli_log("  $err", 'WARN');
    }
} catch (\Throwable $e) {
    cli_log('Błąd: ' . $e->getMessage(), 'ERR');
    exit(1);
}
