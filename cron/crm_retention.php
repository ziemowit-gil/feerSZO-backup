<?php
/**
 * cron/crm_retention.php — przegląd retencji danych osobowych w CRM.
 *
 * Agent NIE ANONIMIZUJE. Oznacza kartoteki spełniające reguły i raz w tygodniu
 * wysyła zestawienie administratorom. Anonimizacja jest nieodwracalna i wymaga
 * decyzji człowieka po obejrzeniu listy — operacja tego rodzaju wykonana po cichu
 * w nocy to nie zgodność z RODO, tylko utrata danych z opóźnieniem.
 *
 * Wyjątki (darowizna albo faktura z ostatnich 5 lat, otwarta sprawa, beneficjent
 * programu, ręczna blokada) są wycinane w zapytaniu — patrz includes/crm_retention.php.
 *
 * Użycie: php cron/crm_retention.php [--quiet] [--limit=500]
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_retention.php';
require_once $base_dir . '/includes/mail_queue.php';

$opts  = getopt('', ['quiet', 'limit::']);
$quiet = isset($opts['quiet']);
$limit = max(1, min(5000, (int)($opts['limit'] ?? 500)));

echo '[' . date('Y-m-d H:i:s') . "] Start: crm_retention\n";

if (org_setting('crm_enabled') !== '1') { echo "  Moduł CRM wyłączony — koniec.\n"; exit(0); }

crm_retention_migrate();

$rules = crm_retention_rules();
if (!$rules) {
    echo "  Brak reguł retencji — nic nie jest oznaczane. Ustaw je w CRM → Ustawienia → Retencja.\n";
    exit(0);
}

$cands = crm_retention_all_candidates($limit);
echo '  Reguł: ' . count($rules) . ', kartotek spełniających: ' . count($cands) . "\n";

$new = crm_retention_flag(array_map(static fn($c) => (int)$c['id'], $cands));
echo "  Nowo oznaczonych: {$new}\n";

if ($quiet || !$cands) { echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_retention\n"; exit(0); }

// ── Zestawienie do administratorów ─────────────────────────────────────────
$rows = array_slice($cands, 0, 100);
$text = "Kartoteki CRM spełniające reguły retencji danych osobowych: " . count($cands) . "\n\n"
      . "Agent niczego nie usunął — anonimizację uruchamia człowiek po obejrzeniu listy.\n\n";
foreach ($rows as $c) {
    $text .= sprintf("  %-40s ostatni ruch %s — reguła: %s (%d mies.)\n",
        mb_substr((string)$c['imie_nazwisko'], 0, 40),
        substr((string)$c['last_touch'], 0, 10),
        (string)$c['rule'], (int)$c['months']);
}
if (count($cands) > count($rows)) $text .= '  … i ' . (count($cands) - count($rows)) . " więcej\n";
$text .= "\nPrzegląd i anonimizacja:\n" . APP_URL . "/crm/settings/retention.php\n\n"
       . "Pominięte zawsze: kartoteki z darowizną albo fakturą z ostatnich 5 lat,\n"
       . "z otwartą sprawą, powiązane z beneficjentem programu oraz ręcznie wyłączone\n"
       . "z retencji — ich przechowywanie wynika z innych przepisów niż RODO.\n";

$sent = 0;
try {
    foreach (db_all("SELECT name, email FROM users WHERE role='admin' AND is_active=1") as $u) {
        if (!filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL)) continue;
        mail_queue_add($u['email'], (string)$u['name'],
            'CRM: retencja danych — ' . count($cands) . ' kartotek do przeglądu',
            '', $text, 'crm_retention', 0, '', false);
        $sent++;
    }
} catch (\Throwable $e) { echo '  ! kolejka: ' . $e->getMessage() . "\n"; }

echo "  Zestawień w kolejce: {$sent}\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_retention\n";
