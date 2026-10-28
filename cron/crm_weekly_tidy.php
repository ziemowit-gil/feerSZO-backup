<?php
/**
 * cron/crm_weekly_tidy.php — tygodniowe porządki i kategoryzowanie adresów CRM wg 3 kategorii:
 *   imienny (personal) · ogólny (generic) · techniczny (technical).
 *
 * Uruchamiany raz w tygodniu przez cron/dispatcher.php (agent 'crm_weekly_tidy'). Co robi:
 *   1. Przelicza WZORCAMI wszystkie aktywne kartoteki z adresem (nie tylko nowe — reguły się zmieniają:
 *      nowe domeny platform, lista skrzynek systemowych). Rozpoznanie wcześniejsze z modelu (AI) nie jest
 *      cofane, gdy wzorce nie rozstrzygają.
 *   2. Niejednoznaczne (nierozpoznane) idą do modelu paczkami po 40, do --ai-limit adresów na przebieg
 *      (domyślnie 400 — kontrola kosztu); bez klucza Anthropic ten krok jest pomijany.
 *   3. Adresy uznane za TECHNICZNE, które nadal leżą jako zwykły kontakt, trafiają do bota sprzątającego
 *      jako zgłoszenia „Adres techniczny jako zwykły kontakt” — decyzja zostaje po stronie człowieka.
 *      Dopiero z --apply-technical kontakty są oznaczane jako techniczne od razu.
 *   4. Zapisuje w logu bilans: ile kontaktów w każdej kategorii przed i po.
 *
 * Użycie: php cron/crm_weekly_tidy.php [--dry-run] [--ai-limit=400] [--apply-technical]
 *   --dry-run   niczego nie zapisuje i nie woła modelu, tylko pokazuje, co by się zmieniło
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/auth.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';
require_once $base_dir . '/includes/crm_email_kind.php';
require_once $base_dir . '/includes/crm_janitor.php';

$opts     = getopt('', ['dry-run', 'ai-limit::', 'apply-technical']);
$dry      = isset($opts['dry-run']);
$ai_limit = max(0, min(2000, (int)($opts['ai-limit'] ?? 400)));
$apply    = isset($opts['apply-technical']);

echo '[' . date('Y-m-d H:i:s') . '] Start: crm_weekly_tidy' . ($dry ? ' (dry-run)' : '') . "\n";

if (function_exists('org_setting') && org_setting('crm_enabled') === '0') {
    echo "  Moduł CRM wyłączony — koniec.\n";
    exit(0);
}

crm_email_kind_migrate();
crm_janitor_migrate();

/** Bilans kategorii: kind => liczba aktywnych kartotek z adresem. */
$balance = static function (): array {
    $out = ['personal' => 0, 'generic' => 0, 'technical' => 0, 'unknown' => 0];
    foreach (db_all("SELECT COALESCE(NULLIF(email_kind,''),'unknown') AS k, COUNT(*) AS n FROM crm_contacts
                      WHERE crm_active=1 AND COALESCE(email,'') LIKE '%@%' GROUP BY 1") as $r) {
        $k = isset($out[$r['k']]) ? $r['k'] : 'unknown';
        $out[$k] += (int)$r['n'];
    }
    return $out;
};
$print_balance = static function (string $title, array $b): void {
    echo "  $title: imiennych {$b['personal']}, ogólnych {$b['generic']}, technicznych {$b['technical']}, nierozpoznanych {$b['unknown']}\n";
};

$before = $balance();
$print_balance('Przed', $before);

// ── 1. Wzorce na wszystkich kartotekach ────────────────────────────────────
$rows = db_all("SELECT id, imie_nazwisko, email, email_kind, type, status FROM crm_contacts
                 WHERE crm_active=1 AND COALESCE(email,'') LIKE '%@%' ORDER BY id");
$changed = 0; $ask = [];
foreach ($rows as $c) {
    $cur = (string)($c['email_kind'] ?? '');
    $r   = crm_email_kind_local((string)$c['email'], $c['imie_nazwisko']);
    if ($r['sure']) {
        if ($cur !== $r['kind']) {
            $changed++;
            if (!$dry) crm_email_kind_save((int)$c['id'], $r['kind'], $r['why']);
        }
    } elseif (!isset(CRM_EMAIL_KINDS[$cur]) || $cur === 'unknown') {
        $ask[] = ['id' => (int)$c['id'], 'email' => (string)$c['email'], 'name' => (string)$c['imie_nazwisko']];
    }   // wzorce nie rozstrzygają, ale jest już rozpoznanie (np. z AI) — zostaje
}
echo '  Wzorce: zmieniono kategorię ' . $changed . ', do rozstrzygnięcia przez model ' . count($ask) . "\n";

// ── 2. Model dla niejednoznacznych ─────────────────────────────────────────
$ai_done = 0;
if ($ask && !$dry && $ai_limit > 0 && crm_email_kind_ai_available()) {
    foreach (array_chunk(array_slice($ask, 0, $ai_limit), 40) as $chunk) {
        $map = crm_email_kind_ai(array_map(static fn($a) => ['email' => $a['email'], 'name' => $a['name']], $chunk));
        foreach ($chunk as $a) {
            $r = $map[mb_strtolower($a['email'])] ?? null;
            if (!$r) continue;
            crm_email_kind_save($a['id'], $r['kind'], $r['why']);
            $ai_done++;
        }
    }
    echo "  Model: rozpoznano $ai_done z " . min(count($ask), $ai_limit) . " zapytanych\n";
} elseif ($ask && !$dry && !crm_email_kind_ai_available()) {
    echo "  Model niedostępny (brak klucza Anthropic) — niejednoznaczne zostają nierozpoznane.\n";
}

// ── 3. Techniczne leżące jako zwykłe kontakty → bot sprzątający (albo od razu) ──
$tech = db_all("SELECT id, imie_nazwisko, email FROM crm_contacts
                 WHERE crm_active=1 AND email_kind='technical' AND type <> 'kontakt_techniczny'
                   AND COALESCE(status,'') NOT IN ('techniczny','powiadomienia')");
$proposed = 0; $marked = 0;
if ($tech) {
    $has_st = db_one("SELECT 1 AS x FROM crm_statuses WHERE slug='techniczny' AND is_active=1");
    foreach ($tech as $t) {
        if ($apply && !$dry) {
            db_exec("UPDATE crm_contacts SET type='kontakt_techniczny'" . ($has_st ? ", status='techniczny'" : '') . ", updated_at=? WHERE id=?", [date('Y-m-d H:i:s'), (int)$t['id']]);
            $marked++;
        } elseif (!$apply && !$dry && crm_janitor_rule_enabled('robot_contact')) {
            crm_janitor_add('robot_contact', 'c:' . (int)$t['id'], [
                'contact_id' => (int)$t['id'],
                'title'      => 'Adres techniczny: ' . $t['email'],
                'detail'     => 'Kartoteka „' . $t['imie_nazwisko'] . '” — adres rozpoznany jako systemowy w tygodniowym przeglądzie. Nie powinna dostawać wysyłek.',
                'payload'    => ['id' => (int)$t['id']],
            ]);
            $proposed++;
        }
    }
}
echo '  Techniczne wśród zwykłych kontaktów: ' . count($tech)
   . ($apply ? ", oznaczono jako techniczne: $marked" : ", zgłoszono do decyzji w bocie sprzątającym: $proposed") . "\n";

$print_balance('Po   ', $balance());
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_weekly_tidy\n";
exit(0);
