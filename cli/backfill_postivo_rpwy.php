<?php
/**
 * cli/backfill_postivo_rpwy.php — jednorazowy backfill: dla wpisów RPW-W
 * powiązanych z pismem już wcześniej wysłanym przez Postivo.pl (przed
 * wprowadzeniem includes/ezd_rpwy.php::ezd_rpwy_apply_postivo_status()),
 * dociąga operatora/typ przesyłki/nr zlecenia/historię statusów.
 *
 * Użycie: php cli/backfill_postivo_rpwy.php [--dry-run]
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

$dry = in_array('--dry-run', $argv, true);

if (postivo_setting('postivo_enabled') !== '1') { echo "Integracja Postivo.pl wyłączona — nic do zrobienia.\n"; exit; }
$client = new PostivoClient();
if (!$client->is_configured()) { echo "Brak klucza API Postivo.pl.\n"; exit; }

// Pisma wysłane przez Postivo (mają postivo_job_id), których wpis RPW-W
// jeszcze nie ma zapisanego postivo_job_id (czyli powstał/był wysłany
// zanim ta synchronizacja istniała).
$rows = db_all(
    "SELECT w.id AS rpwy_id, w.rpwy_nr, w.rok, p.id AS pismo_id, p.postivo_job_id
     FROM ezd_rpwy w
     JOIN ezd_pisma p ON p.id = w.pismo_id
     WHERE p.postivo_job_id IS NOT NULL AND p.postivo_job_id <> ''
       AND (w.postivo_job_id IS NULL OR w.postivo_job_id = '')"
);

echo "Znaleziono do backfillu: " . count($rows) . "\n";
if ($dry) echo "[--dry-run] nic nie zostanie zapisane.\n";

$ok = 0; $err = 0;
foreach ($rows as $row) {
    echo "RPW-W {$row['rpwy_nr']}/{$row['rok']} (pismo #{$row['pismo_id']}, zlecenie {$row['postivo_job_id']})… ";
    try {
        $status_data = $client->get_status($row['postivo_job_id']);
        if (!$dry) {
            ezd_rpwy_apply_postivo_status((int)$row['rpwy_id'], $status_data, 0);
        }
        echo "OK — " . ($status_data['operator'] ?: '—') . " / " . ($status_data['service_name'] ?: '—')
            . " / status: " . ($status_data['status_name'] ?: $status_data['status']) . "\n";
        $ok++;
    } catch (\Throwable $e) {
        echo "BŁĄD: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "Gotowe. OK: $ok, błędy: $err.\n";
