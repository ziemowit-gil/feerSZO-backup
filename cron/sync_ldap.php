<?php
/**
 * Jednokierunkowa synchronizacja kont SZO do katalogu OpenLDAP (+ M365 Graph
 * dla kont już powiązanych). Uruchamiany z poziomu CLI (cron). Cała logika
 * (LDAP, kolejka retry, Graph) jest w includes/ldap.php::ldap_run_sync() —
 * współdzielona z GUI (admin/ldap_sync.php, tozsamosc/ldap.php).
 *
 * Użycie:
 *   php cron/sync_ldap.php                # pełna synchronizacja
 *   php cron/sync_ldap.php --dry-run      # tylko podgląd, bez zapisu do LDAP/Graph
 *   php cron/sync_ldap.php --dead-letters # lista operacji, które wyczerpały limit prób
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/ldap.php';

$opts = getopt('', ['dry-run', 'dead-letters', 'help']);

if (isset($opts['help'])) {
    echo "Użycie: php cron/sync_ldap.php [--dry-run] [--dead-letters]\n";
    exit(0);
}

if (isset($opts['dead-letters'])) {
    $dead = array_merge(ldap_queue_dead_letters('ldap'), ldap_queue_dead_letters('graph'));
    if (!$dead) {
        echo "Brak operacji w dead-letter.\n";
        exit(0);
    }
    foreach ($dead as $d) {
        echo "[{$d['target']}] user_id={$d['user_id']} próby={$d['attempts']} — {$d['last_error']}\n";
    }
    exit(1);
}

$today = date('Y-m-d H:i:s');

if (isset($opts['dry-run'])) {
    echo "[{$today}] Synchronizacja LDAP — podgląd (DRY-RUN)\n";
    try {
        $ldap = new LdapDirectory();
        if (!$ldap->is_configured()) {
            throw new RuntimeException('LDAP nie jest skonfigurowany (stałe LDAP_* w config.local.php).');
        }
        foreach (ldap_collect_users() as $user) {
            $status = empty($user['is_active']) ? 'NIEAKTYWNY' : 'aktywny';
            $graph  = !empty($user['microsoft_id']) ? ', powiązany z M365' : '';
            echo "  [DRY] uid={$user['id']} ({$status}{$graph}) " . ($user['name'] ?? '?') . ' <' . ($user['email'] ?? '?') . ">\n";
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'BŁĄD KRYTYCZNY: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

echo "[{$today}] Synchronizacja LDAP — start\n";

try {
    $result  = ldap_run_sync();
    $summary = $result['summary'];

    foreach ($result['items'] as $item) {
        $label = ($item['name'] ?: '?') . ' <' . ($item['email'] ?: '?') . '>';
        $ldapMsg = $item['ldap_action'] === 'error'
            ? "BŁĄD LDAP — {$item['ldap_error']}"
            : "LDAP: {$item['ldap_action']}";
        $graphMsg = '';
        if ($item['graph_action'] === 'error') {
            $graphMsg = " | BŁĄD Graph — {$item['graph_error']}";
        } elseif ($item['graph_action'] === 'updated') {
            $graphMsg = ' | Graph: zaktualizowano';
        }
        $tag = $item['ldap_action'] === 'error' || $item['graph_action'] === 'error' ? 'BŁĄD' : 'OK';
        echo "  [{$tag}] {$label} — {$ldapMsg}{$graphMsg}\n";
    }

    $l = $summary['ldap'];
    echo "Podsumowanie LDAP: utworzono {$l['created']}, zaktualizowano {$l['updated']}, "
        . "dezaktywowano {$l['deactivated']}, reaktywowano {$l['reactivated']}, "
        . "pominięto {$l['skipped']}, błędy {$l['error']}.\n";

    $g = $summary['graph'];
    if ($g['updated'] > 0 || $g['error'] > 0) {
        echo "Podsumowanie Graph: zaktualizowano {$g['updated']}, błędy {$g['error']} "
            . "(pominięto {$g['skipped']} — konto nigdy nie powiązane z M365).\n";
    }

    if ($summary['retried']['ldap'] || $summary['retried']['graph']) {
        echo "Ponowione z kolejki: LDAP {$summary['retried']['ldap']}, Graph {$summary['retried']['graph']}.\n";
    }

    $dead = count(ldap_queue_dead_letters('ldap')) + count(ldap_queue_dead_letters('graph'));
    if ($dead > 0) {
        echo "UWAGA: {$dead} operacji w dead-letter — sprawdź: php cron/sync_ldap.php --dead-letters\n";
    }

    exit($l['error'] > 0 || $g['error'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'BŁĄD KRYTYCZNY: ' . $e->getMessage() . "\n");
    exit(1);
}
