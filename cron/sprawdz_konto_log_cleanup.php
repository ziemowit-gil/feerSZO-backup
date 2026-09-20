<?php
/**
 * Skrypt cron: retencja logów sprawdzarki numeru konta do wpłat
 * (modules/sprawdz_konto/) — usuwa wpisy starsze niż 90 dni.
 * Uruchamiaj raz dziennie:
 *   0 4 * * * php /var/www/html/cron/sprawdz_konto_log_cleanup.php
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/modules/sprawdz_konto/logic/sprawdz_konto.php';

$deleted = sprawdz_konto_cleanup_old_logs(90);
echo "[" . date('Y-m-d H:i:s') . "] sprawdz_konto_log_cleanup — usunięto {$deleted} wpisów starszych niż 90 dni.\n";
