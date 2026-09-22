<?php
/**
 * cron/redmine_sync.php — dwukierunkowa synchronizacja Helpdesk ↔ Redmine.
 *
 * Pobiera z Redmine stan powiązanych zgłoszeń: importuje nowe notatki jako
 * wiadomości i mapuje zamknięcie issue na status SZO „rozwiązane".
 * Uruchamiany przez cron/dispatcher.php. Aktywny gdy integracja włączona.
 *
 * Ręcznie:
 *   php cron/redmine_sync.php
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/redmine.php';
require_once $base . '/includes/helpdesk.php';

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';

if (!redmine_is_enabled()) {
    echo $ts() . " redmine_sync: integracja wyłączona — pomijam.\n";
    exit(0);
}

echo $ts() . " Start: redmine_sync\n";
try {
    $res = hd_redmine_pull_all();
    echo $ts() . " Zaktualizowano: {$res['synced']} | błędy: {$res['errors']}\n";
    exit($res['errors'] ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $ts() . ' [KRYTYCZNY] ' . $e->getMessage() . "\n");
    error_log('[redmine] cron redmine_sync krytyczny błąd: ' . $e->getMessage());
    exit(2);
}
