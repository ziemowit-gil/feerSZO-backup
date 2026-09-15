<?php
/**
 * Skrypt cron: zbiorcza wysyłka przesyłek zakolejkowanych do Postivo.pl w ciągu dnia.
 * Uruchamiaj codziennie o 16:30:
 *   30 16 * * * php /var/www/html/cron/postivo_dispatch_batch.php
 *
 * Wpisy RPW-W ze statusem "Przetwarzanie - Postivo" (postivo_queued) —
 * zarejestrowane przez ezd/sprawy/quick_dispatch.php (tryb Postivo) —
 * czekają tu na faktyczne nadanie zamiast wysyłać się natychmiast pojedynczo.
 * Patrz includes/ezd_rpwy.php::ezd_rpwy_postivo_dispatch().
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/ezd.php';
require_once $base_dir . '/includes/ezd_rpwy.php';
require_once $base_dir . '/includes/postivo.php';

echo "[" . date('Y-m-d H:i:s') . "] Start: postivo_dispatch_batch\n";

if (!module_enabled('ezd_enabled')) { echo "  EZD wyłączony — pomijam.\n"; exit; }
if (postivo_setting('postivo_enabled') !== '1') { echo "  Integracja Postivo.pl wyłączona — pomijam.\n"; exit; }

$client = new PostivoClient();
if (!$client->is_configured()) { echo "  Brak klucza API Postivo.pl — pomijam.\n"; exit; }

$queued = db_all("SELECT id FROM ezd_rpwy WHERE status='postivo_queued' ORDER BY id");
echo "  Zakolejkowanych: " . count($queued) . "\n";

$ok = 0; $err = 0;
foreach ($queued as $row) {
    $id = (int)$row['id'];
    $res = ezd_rpwy_postivo_dispatch($id, 0);
    if ($res['ok']) {
        echo "  ✓ RPW-W #$id — nadano, ID Postivo: {$res['postivo_id']}\n";
        $ok++;
    } else {
        echo "  ✗ RPW-W #$id — błąd: {$res['error']}\n";
        $err++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Nadano: $ok, błędy: $err.\n";
