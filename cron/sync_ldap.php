<?php
/**
 * Jednokierunkowa synchronizacja aktywnych kont SZO do katalogu OpenLDAP.
 * Uruchamiany z poziomu CLI (cron). Współdzieli klienta i kolektor z GUI
 * (admin/ldap_sync.php) przez includes/ldap.php.
 *
 * Użycie:
 *   php cron/sync_ldap.php            # eksport
 *   php cron/sync_ldap.php --dry-run  # tylko podgląd, bez zapisu do LDAP
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/ldap.php';

$opts = getopt('', ['dry-run', 'help']);

if (isset($opts['help'])) {
    echo "Użycie: php cron/sync_ldap.php [--dry-run]\n";
    exit(0);
}

$dry = isset($opts['dry-run']);
$today = date('Y-m-d H:i:s');
echo "[{$today}] Synchronizacja LDAP — start" . ($dry ? ' (DRY-RUN)' : '') . "\n";

$created = 0;
$updated = 0;
$failed = 0;

try {
    $ldap = new LdapDirectory();
    if (!$ldap->is_configured()) {
        throw new RuntimeException('LDAP nie jest skonfigurowany (stałe LDAP_* w config.local.php).');
    }
    if (!$dry) {
        $ldap->connect();
    }

    foreach (ldap_collect_users() as $user) {
        $label = ($user['name'] ?? '?') . ' <' . ($user['email'] ?? '?') . '>';

        if ($dry) {
            echo "  [DRY] uid={$user['id']} {$label}\n";
            continue;
        }

        try {
            $action = $ldap->upsert_user($user);
            $action === 'created' ? $created++ : $updated++;
            echo "  [OK] {$label} — " . ($action === 'created' ? 'utworzono' : 'zaktualizowano') . "\n";
        } catch (\Throwable $e) {
            $failed++;
            error_log('[LDAP sync] uid=' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
            echo "  [BŁĄD] {$label} — {$e->getMessage()}\n";
        }
    }

    $ldap->close();

    if (!$dry) {
        ldap_save_setting('ldap_last_sync', date('Y-m-d H:i:s'));
    }

    echo "Podsumowanie: utworzono {$created}, zaktualizowano {$updated}, błędy {$failed}.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'BŁĄD KRYTYCZNY: ' . $e->getMessage() . "\n");
    exit(1);
}

exit($failed > 0 ? 1 : 0);
