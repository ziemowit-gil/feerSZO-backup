<?php
/**
 * cron/betterfly_sync.php — synchronizacja statusów faktur z Comarch Betterfly.
 *
 * Pobiera z Betterfly aktualny stan faktur z lokalnego rejestru (betterfly_invoices),
 * które nie są jeszcze w pełni opłacone, i aktualizuje status dokumentu oraz płatności.
 * Uruchamiany przez cron/dispatcher.php (domyślnie co ~3h w godzinach pracy).
 *
 * Ręcznie:
 *   php cron/betterfly_sync.php
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/betterfly.php';
require_once $base . '/includes/betterfly_invoices.php';

$ts = fn() => '[' . date('Y-m-d H:i:s') . ']';

if (!BetterFlyClient::isEnabled()) {
    echo $ts() . " betterfly_sync: integracja wyłączona — pomijam.\n";
    exit(0);
}

echo $ts() . " Start: betterfly_sync\n";

try {
    $res = betterfly_sync_pending();
    echo $ts() . " Zsynchronizowano: {$res['synced']} | błędy: " . count($res['errors']) . "\n";
    foreach ($res['errors'] as $localId => $msg) {
        echo $ts() . "   [BŁĄD] rekord #{$localId}: {$msg}\n";
    }
    // Kod wyjścia 1, gdy były błędy (widoczne w logach/monitoringu crona).
    exit($res['errors'] ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $ts() . ' [KRYTYCZNY] ' . $e->getMessage() . "\n");
    error_log('[betterfly] cron betterfly_sync krytyczny błąd: ' . $e->getMessage());
    exit(2);
}
