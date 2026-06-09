#!/usr/bin/env php
<?php
/**
 * bin/outlook_sync.php — CLI runner synchronizacji Outlook / MS365 → CRM
 *
 * Użycie:
 *   php bin/outlook_sync.php [--contacts] [--calendar] [--reset] [--user=ID]
 *
 * Bez flag: synchronizuje wszystko (zgodnie z ustawieniami w bazie).
 *
 * Przykład z crona (co godzinę):
 *   0 * * * * php /Users/zgil/webev-projects/feerSZO/bin/outlook_sync.php
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
define('APP_CLI', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/crm.php';
require_once $root . '/includes/m365.php';
require_once $root . '/includes/outlook_sync.php';

// ── Opcje CLI ──────────────────────────────────────────────────────────────────
$opts = getopt('', ['contacts', 'calendar', 'reset', 'user:', 'dry-run', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Synchronizacja Outlook → CRM

Użycie:
  php bin/outlook_sync.php [opcje]

Opcje:
  --contacts     Synchronizuj tylko kontakty
  --calendar     Synchronizuj tylko kalendarz
  --reset        Usuń delta-link przed synchronizacją (pełny re-sync)
  --user=ID      UPN lub GUID użytkownika M365 (nadpisuje ustawienia)
  --dry-run      Tylko pokaż co by zostało zsynchronizowane (nie implementowane)
  --help         Ta pomoc

Bez flag: synchronizuje kontakty i kalendarz zgodnie z ustawieniami.

HELP;
    exit(0);
}

crm_migrate();

$user_id = $opts['user'] ?? null;

// ── Sync ───────────────────────────────────────────────────────────────────────
try {
    $sync = new OutlookSync($user_id);

    if (isset($opts['reset'])) {
        $sync->reset_delta();
        echo cli_line('ok', 'Delta-link zresetowany — następna synchronizacja pobierze wszystko.');
    }

    $do_contacts = isset($opts['contacts']) || (!isset($opts['contacts']) && !isset($opts['calendar']));
    $do_calendar = isset($opts['calendar']) || (!isset($opts['contacts']) && !isset($opts['calendar']));

    if ($do_contacts && crm_setting('m365_sync_contacts') !== '0') {
        echo cli_line('info', 'Synchronizacja kontaktów…');
        $r = $sync->sync_contacts();
        echo cli_line(
            empty($r['errors']) ? 'ok' : 'warn',
            sprintf('+%d nowych, ~%d zaktualizowanych, -%d usuniętych', $r['created'], $r['updated'], $r['removed'])
        );
        foreach ($r['errors'] as $e) {
            echo cli_line('error', $e);
        }
    } elseif ($do_contacts) {
        echo cli_line('info', 'Synchronizacja kontaktów wyłączona w ustawieniach.');
    }

    if ($do_calendar && crm_setting('m365_sync_calendar') !== '0') {
        echo cli_line('info', 'Synchronizacja kalendarza…');
        $r = $sync->sync_calendar();
        echo cli_line(
            empty($r['errors']) ? 'ok' : 'warn',
            sprintf('+%d nowych, ~%d zaktualizowanych, -%d usuniętych', $r['created'], $r['updated'], $r['removed'])
        );
        foreach ($r['errors'] as $e) {
            echo cli_line('error', $e);
        }
    } elseif ($do_calendar) {
        echo cli_line('info', 'Synchronizacja kalendarza wyłączona w ustawieniach.');
    }

} catch (\Throwable $e) {
    echo cli_line('error', 'Krytyczny błąd: ' . $e->getMessage());
    exit(1);
}

echo cli_line('ok', 'Gotowe.');
exit(0);

// ── Helpers ────────────────────────────────────────────────────────────────────
function cli_line(string $type, string $msg): string
{
    $now    = date('Y-m-d H:i:s');
    $prefix = match($type) {
        'ok'    => "\033[32m✓\033[0m",
        'warn'  => "\033[33m⚠\033[0m",
        'error' => "\033[31m✗\033[0m",
        default => "\033[36mℹ\033[0m",
    };
    return "[{$now}] {$prefix} {$msg}\n";
}
