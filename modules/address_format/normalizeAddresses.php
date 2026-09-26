<?php
/**
 * CLI: podgląd / ręczne uruchomienie normalizacji pisowni adresów (CRM, osoby, umowy).
 *   php modules/address_format/normalizeAddresses.php           — tylko podgląd zmian
 *   php modules/address_format/normalizeAddresses.php --apply   — zapis do bazy
 * Ta sama operacja wykonuje się jednorazowo w migracji (addresses.normalize_pl_v1).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

$base = dirname(__DIR__, 2);
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';

$apply = in_array('--apply', $argv, true);
$stats = normalizeAddressesInDb(db(), $apply, function (string $table, int $id, array $old, array $new): void {
    foreach ($new as $col => $v) {
        echo "{$table}#{$id}.{$col}: {$old[$col]}  →  {$v}\n";
    }
});

echo "\n" . ($apply ? 'Zapisano' : 'Podgląd (bez zapisu, dodaj --apply)') . ': ';
echo $stats ? implode(', ', array_map(fn($t, $n) => "{$t} {$n}", array_keys($stats), $stats)) : 'brak zmian';
echo "\n";
