<?php
/**
 * cron/betterfly_purchase_import.php — import faktur ZAKUPU z Comarch Betterfly
 * do obiegu akceptacji EODoK.
 *
 * Pobiera faktury zakupu z Betterfly i tworzy dla nowych dokumenty w obiegu EODoK
 * (deduplikacja po Id faktury Betterfly). Uruchamiany przez cron/dispatcher.php.
 * Aktywny tylko gdy admin włączył intake zakupu (settings: betterfly_edok_gate_purchase=1).
 *
 * Ręcznie:
 *   php cron/betterfly_purchase_import.php
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
    echo $ts() . " betterfly_purchase_import: integracja wyłączona — pomijam.\n";
    exit(0);
}
if (!betterfly_edok_gate_purchase()) {
    echo $ts() . " betterfly_purchase_import: intake zakupu (EODoK) wyłączony — pomijam.\n";
    exit(0);
}

echo $ts() . " Start: betterfly_purchase_import\n";

try {
    $res = betterfly_import_purchase_invoices();
    echo $ts() . " Zaimportowano: {$res['imported']} | pominięto: {$res['skipped']} | błędy: " . count($res['errors']) . "\n";
    foreach ($res['errors'] as $bfId => $msg) {
        echo $ts() . "   [BŁĄD] faktura zakupu #{$bfId}: {$msg}\n";
    }
    exit($res['errors'] ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $ts() . ' [KRYTYCZNY] ' . $e->getMessage() . "\n");
    error_log('[betterfly] cron betterfly_purchase_import krytyczny błąd: ' . $e->getMessage());
    exit(2);
}
