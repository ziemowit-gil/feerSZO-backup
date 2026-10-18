<?php
/**
 * cli/seedZlecenieTechniczna.php — umowa zlecenie TECHNICZNA (numer „---”) dla osoby z rejestru osób.
 *
 *   php cli/seedZlecenieTechniczna.php                              # podgląd dla „Ziemowit Gil” (nic nie zapisuje)
 *   php cli/seedZlecenieTechniczna.php --apply                      # zapis
 *   php cli/seedZlecenieTechniczna.php --osoba="Jan Kowalski" --email=jan@feer.org.pl --apply
 *   php cli/seedZlecenieTechniczna.php --od=2026-09-01 --apply      # od kiedy obowiązuje (domyślnie 1. dzień bieżącego miesiąca)
 *
 * Po co: prowadzący zajęcia w dydaktyce TI bez zwykłej umowy (np. członek zarządu) potrzebuje
 * umowy zlecenie, żeby panel dydaktyka pokazał e-ewidencję i przyjął podpis protokołu.
 *
 * Umowa techniczna: numer „---”, status „w realizacji”, bezterminowa, „Dydaktyka — udostępnij dane
 * do modułu” zaznaczone, prowadzący w e-dzienniku = konto użytkownika. Celowo BEZ numeru rejestru (RU),
 * PDF, konta M365, sprawy CRM i wiadomości — to wpis techniczny, nie obieg umowy.
 * Dane osobowe (PESEL, adres, rachunek…) z rejestru osób — nic nie jest wpisane w kodzie.
 * Ponowne uruchomienie aktualizuje istniejącą umowę „---” tej osoby (nie tworzy drugiej).
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit('Tylko z CLI.'); }
defined('APP_CLI') || define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/zlecenie_rachunki.php'; // samonaprawa kolumn (dydaktyka_udostepnij itd.)

$opts  = cli_getopt('', ['osoba::', 'email::', 'od::', 'apply']);
$osoba = trim((string)($opts['osoba'] ?? 'Ziemowit Gil'));
$email = trim((string)($opts['email'] ?? ''));
$apply = array_key_exists('apply', $opts);
$NUMER = '---';
// Od kiedy obowiązuje: panel dydaktyka szuka umowy obowiązującej w miesiącu protokołu.
$od = (string)($opts['od'] ?? date('Y-m-01'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || !strtotime($od)) { fwrite(STDERR, "Zła data --od (RRRR-MM-DD): {$od}\n"); cli_exit(1); }

// ── Osoba z rejestru ─────────────────────────────────────────────────────────
$person = $email !== ''
    ? db_one("SELECT * FROM persons WHERE lower(email) = lower(?) ORDER BY id LIMIT 1", [$email])
    : null;
$person = $person ?: db_one("SELECT * FROM persons WHERE lower(trim(imie_nazwisko)) = lower(?) ORDER BY id LIMIT 1", [$osoba]);
if (!$person) {
    fwrite(STDERR, "Nie ma w rejestrze osób: {$osoba}" . ($email ? " / {$email}" : '') . ". Dodaj osobę w Osoby → Rejestr i uruchom ponownie.\n");
    cli_exit(1);
}

// ── Konto użytkownika (prowadzący w e-dzienniku) ────────────────────────────
$user = null;
foreach (array_filter([$email, (string)($person['email'] ?? '')]) as $e) {
    $user = $user ?: db_one("SELECT id, name, email FROM users WHERE lower(email) = lower(?) LIMIT 1", [$e]);
}
$user = $user ?: db_one("SELECT id, name, email FROM users WHERE lower(trim(name)) = lower(?) ORDER BY id LIMIT 1", [(string)$person['imie_nazwisko']]);

$cols = array_column(db_all("PRAGMA table_info(umowy_zlecenie)"), 'name');
$unit = db_one("SELECT id FROM org_units WHERE name = 'Zarząd' LIMIT 1");
$now  = date('Y-m-d H:i:s');

$data = [
    'numer_umowy'          => $NUMER,
    'status'               => 'w realizacji',
    'imie_nazwisko'        => (string)$person['imie_nazwisko'],
    'person_id'            => (int)$person['id'],
    'org_unit_id'          => $unit ? (int)$unit['id'] : null,
    'przedmiot_zlecenia'   => 'Umowa techniczna — prowadzenie zajęć w dydaktyce TI (wpis techniczny, bez obiegu umowy)',
    'data_zawarcia'        => $od,
    'data_rozpoczecia'     => $od,
    'bezterminowa'         => 1,
    'forma_podpisania'     => 'elektroniczna',
    'wymagany_rachunek'    => 0,
    'nr_rejestru'          => '',
    'zrodlo_godzin'        => 'karty30',
    'ti_instructor_user_id'=> $user ? (int)$user['id'] : null,
    'dydaktyka_udostepnij' => 1,
    'uwagi'                => 'Umowa techniczna (numer „---”) założona skryptem cli/seedZlecenieTechniczna.php — poza rejestrem umów, bez PDF i M365.',
    'updated_at'           => $now,
];
// Dane osobowe i adres — z rejestru osób.
foreach (['pesel', 'adres', 'email', 'seria_nr_dowodu', 'urzad_skarbowy', 'rachunek_bankowy',
          'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country'] as $f) {
    if (isset($person[$f]) && trim((string)$person[$f]) !== '') $data[$f] = $person[$f];
}
$data = array_intersect_key($data, array_flip($cols)); // tylko istniejące kolumny

$existing = db_one(
    "SELECT id FROM umowy_zlecenie WHERE numer_umowy = ? AND (person_id = ? OR lower(trim(imie_nazwisko)) = lower(?)) ORDER BY id LIMIT 1",
    [$NUMER, (int)$person['id'], (string)$person['imie_nazwisko']]
);

echo ($existing ? "Aktualizacja umowy #{$existing['id']}" : 'Nowa umowa') . " zlecenie „{$NUMER}” — {$person['imie_nazwisko']} (osoba #{$person['id']})\n";
echo '  prowadzący w e-dzienniku: ' . ($user ? "{$user['name']} <{$user['email']}> (#{$user['id']})" : 'BRAK konta — panel dydaktyka nie powiąże umowy; podaj --email') . "\n";
echo "  obowiązuje od: {$od} (bezterminowo)\n";
echo '  komórka: ' . ($unit ? 'Zarząd' : 'brak „Zarząd” — bez komórki') . "\n";
echo '  z rejestru osób: ' . implode(', ', array_keys(array_intersect_key($data, array_flip(['pesel', 'adres', 'email', 'seria_nr_dowodu', 'urzad_skarbowy', 'rachunek_bankowy', 'addr_city'])))) . "\n";

if (!$apply) {
    echo "\nPodgląd — nic nie zapisano. Uruchom z --apply, żeby zapisać.\n";
    cli_exit(0);
}

if ($existing) {
    $id = (int)$existing['id'];
    if (!array_key_exists('od', $opts)) unset($data['data_zawarcia'], $data['data_rozpoczecia']); // daty bez --od zostają jak przy założeniu
    $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
    db()->prepare("UPDATE umowy_zlecenie SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    $akcja = 'Zaktualizowano umowę techniczną (seeder)';
} else {
    $data['created_at'] = $now;
    if (in_array('created_by', $cols, true)) $data['created_by'] = $user ? (int)$user['id'] : null;
    $id = (int)db_insert('umowy_zlecenie', $data);
    $akcja = 'Dodano umowę techniczną „---” (seeder)';
}

if (function_exists('log_contract_action') || (is_file(dirname(__DIR__) . '/includes/approval.php') && (require_once dirname(__DIR__) . '/includes/approval.php'))) {
    try { log_contract_action('zlecenie', $id, $user ? (int)$user['id'] : 0, $existing ? 'update' : 'create', $akcja); } catch (\Throwable $e) {}
}

echo "\nZapisano: umowa zlecenie #{$id} („{$NUMER}”). Widok: " . APP_URL . "/contracts/zlecenie/view.php?id={$id}\n";
