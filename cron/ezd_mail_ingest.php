<?php
/**
 * cron/ezd_mail_ingest.php — Cron ingestujący wiadomości e-mail do EZD.
 *
 * Uruchamiany co X minut (zalecane: 5 min).
 * Przykład crontab:  * /5 * * * * php /var/www/html/cron/ezd_mail_ingest.php >> /var/log/feer/ezd_mail_ingest.log 2>&1
 *
 * Działanie:
 *  1. Uruchamia PocztaScanService::dispatch_pending() — skanuje skrzynki M365.
 *  2. Dla każdej nowej crm_communications wywołuje EzdMailService::handleIncomingComm():
 *       a. Wykrywa [EZD: ZNAK] w temacie
 *       b. Jeśli dopasowanie → tworzy ezd_pisma + linkuje do sprawy
 *       c. Jeśli brak → trafia do Inbox Ogólnego
 *  3. Uruchamia przetwarzanie kolejki mail_queue (outgoing).
 *
 * Można też wywołać ręcznie:  php cron/ezd_mail_ingest.php [--dry-run] [--mailbox=N]
 */

// Upewnij się, że skrypt jest wywoływany z CLI lub z cron.php (zamknięty dostęp)
if (PHP_SAPI !== 'cli' && !(defined('CRON_DISPATCH') && CRON_DISPATCH === true)) {
    http_response_code(403);
    exit('Dostęp tylko przez CLI lub cron.php');
}

$t0 = microtime(true);

// Wykryj ścieżkę bazową projektu (obsługa wywoływania z różnych CWD)
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/crm.php';
require_once $base . '/includes/poczta.php';
require_once $base . '/includes/ezd.php';
require_once $base . '/includes/ezd_mail.php';
require_once $base . '/includes/mail_queue.php';

// ── Parsowanie argumentów CLI ──────────────────────────────────────────────────
$dry_run    = in_array('--dry-run', $argv ?? [], true);
$only_mbox  = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--mailbox=')) {
        $only_mbox = (int)substr($arg, 10);
    }
}

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('=== EZD Mail Ingest START' . ($dry_run ? ' (dry-run)' : '') . ' ===');

if ($dry_run) {
    $log('Tryb dry-run: nie będą wysyłane maile ani tworzone rekordy.');
}

// ── 1. Skanowanie skrzynek M365 ────────────────────────────────────────────────
try {
    if ($only_mbox) {
        $log("Skanowanie pojedynczej skrzynki #{$only_mbox}…");
        $scan_result = [(new PocztaScanService())->scan_mailbox($only_mbox)];
    } else {
        $log('Uruchamianie PocztaScanService::dispatch_pending()…');
        $dispatch = PocztaScanService::dispatch_pending();
        $scan_result = [];
        $log("  Wykolejkowano: {$dispatch['queued']}, przeskanowano: {$dispatch['scanned']}, błędów: " . count($dispatch['errors']));
        foreach ($dispatch['errors'] as $e) { $log("  BŁĄD: {$e}"); }
    }
} catch (\Throwable $e) {
    $log('KRYTYCZNY błąd skanowania: ' . $e->getMessage());
    $scan_result = [];
}

// ── 2. Post-processing: EZD ingest nowych wiadomości ──────────────────────────
$log('Post-processing nowych wiadomości (EZD match)…');

// Pobieramy te crm_communications, które jeszcze nie mają ezd_sprawa_id i nie
// mają ustawionego thread_key — są to świeże rekordy nieprzetworzone przez EZD.
// Okno: tylko z ostatnich 2 godzin (backoff), aby nie przetwarzać starych przy restarcie.
$cutoff = date('Y-m-d H:i:s', strtotime('-2 hours'));
try {
    $new_comms = db_all(
        "SELECT id, subject, direction, ezd_sprawa_id, thread_key
         FROM crm_communications
         WHERE channel = 'email'
           AND direction = 'in'
           AND (ezd_sprawa_id IS NULL)
           AND (thread_key IS NULL OR thread_key = '')
           AND sent_at >= ?
         ORDER BY sent_at ASC
         LIMIT 200",
        [$cutoff]
    );
} catch (\Throwable $e) {
    $log('Błąd pobierania nowych wiadomości: ' . $e->getMessage());
    $new_comms = [];
}

$cnt_new = count($new_comms);
$log("  Znaleziono {$cnt_new} wiadomości do przetworzenia.");
$cnt_matched  = 0;
$cnt_inbox    = 0;
$cnt_error    = 0;

$svc = new EzdMailService();

foreach ($new_comms as $comm) {
    if ($dry_run) {
        $sign = EzdMailService::detectEzdSign($comm['subject'] ?? '');
        $log("  [DRY] #{$comm['id']} temat: {$comm['subject']} → " . ($sign ?? '(brak znaku)'));
        continue;
    }

    try {
        $pismo_id = $svc->handleIncomingComm((int)$comm['id']);
        if ($pismo_id) {
            $cnt_matched++;
            $log("  ✓ Comm #{$comm['id']} → pismo #{$pismo_id}");
        } else {
            $cnt_inbox++;
        }
    } catch (\Throwable $e) {
        $cnt_error++;
        $log("  ✗ Comm #{$comm['id']}: " . $e->getMessage());
    }
}

$log("  Dopasowano do EZD: {$cnt_matched}, do Inbox Ogólnego: {$cnt_inbox}, błędów: {$cnt_error}.");

// ── 3. Wysyłka kolejki wychodzącej ────────────────────────────────────────────
if (!$dry_run) {
    try {
        $queue_result = mail_queue_process(50);
        $log("Kolejka mail_queue: sent={$queue_result['sent']}, failed={$queue_result['failed']}, source={$queue_result['source']}.");
    } catch (\Throwable $e) {
        $log('Błąd przetwarzania kolejki mail_queue: ' . $e->getMessage());
    }
}

// ── Podsumowanie ───────────────────────────────────────────────────────────────
$elapsed = round((microtime(true) - $t0) * 1000);
$log("=== EZD Mail Ingest END ({$elapsed}ms) ===");
