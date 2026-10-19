<?php
/**
 * cli/seedWolontariuszeZmianaDanych.php — 3 konta testowe wolontariuszy do scenariuszy „Zmiana danych”
 * (modules/collaborator_data: Mój panel › Zmiana danych → kontakt w users, adres i rozliczenia w persons,
 * zmiana rachunku wymaga akceptacji i podpisu kodem SMS/e-mail).
 *
 *   wol1 — „Anna Kompletna (TEST)”: pełne dane (telefon, adres, rachunek, urząd skarbowy), umowa podpisana.
 *          Scenariusz: zmiana adresu/telefonu (od razu) + zmiana rachunku (akceptacja, kod SMS na --telefon).
 *   wol2 — „Bartosz Bezadresowy (TEST)”: rekord osoby tylko z imieniem i nazwiskiem, bez telefonu.
 *          Scenariusz: uzupełnianie braków; kod podpisu wyłącznie e-mailem.
 *   wol3 — „Celina Bezrekordu (TEST)”: konto + umowa wolontariacka BEZ rekordu w rejestrze Osób.
 *          Scenariusz: pierwsza zmiana adresu zakłada rekord Osoby i podpina go do zmian.
 *
 * Loginy: lokalne konta SZO (rola viewer). Adresy: <lokalna>+wol1@<domena> przy --email=lokalna@domena
 * (kod podpisu przyjdzie do Twojej skrzynki), inaczej wol1@feer.test (nie dostaje poczty — RFC 2606).
 * Hasła NIE ma w pliku — podajesz je w --haslo.
 *
 *   php cli/seedWolontariuszeZmianaDanych.php                                   # podgląd
 *   php cli/seedWolontariuszeZmianaDanych.php --haslo='Haslo123!' --apply       # załóż (idempotentnie)
 *   php cli/seedWolontariuszeZmianaDanych.php --haslo=… --email=ja@gmail.com --telefon=48500100200 --apply
 *   php cli/seedWolontariuszeZmianaDanych.php --delete [--email=…]              # podgląd usuwania
 *   php cli/seedWolontariuszeZmianaDanych.php --delete --apply [--email=…]      # usuń konta, osoby, umowy, historię zmian
 * Także z Admin › Seedery.
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}
$base = dirname(__DIR__);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';

$opts   = cli_getopt('', ['haslo::', 'email::', 'telefon::', 'apply', 'delete']);
$apply  = array_key_exists('apply', $opts);
$delete = array_key_exists('delete', $opts);
$pass   = (string)($opts['haslo'] ?? '');
$base   = trim((string)($opts['email'] ?? ''));
$phone  = preg_replace('/[^\d+]/', '', (string)($opts['telefon'] ?? ''));

if ($base !== '' && !filter_var($base, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Nieprawidłowy --email.\n"); cli_exit(2); }
if (str_ends_with(strtolower($base), '@feer.org.pl')) { fwrite(STDERR, "Adresy @feer.org.pl logują się tylko przez Microsoft — podaj inną skrzynkę.\n"); cli_exit(2); }
$mail = function (string $tag) use ($base): string {
    if ($base === '') return $tag . '@feer.test';
    [$l, $d] = explode('@', $base, 2);
    return strtolower(explode('+', $l)[0] . '+' . $tag . '@' . $d);
};

/** Poprawny PL IBAN testowy (suma kontrolna mod 97) z 8-cyfrowego numeru rozliczeniowego i 16-cyfrowego rachunku. */
$iban = function (string $bank, string $acc): string {
    $bban = $bank . $acc;
    $num = $bban . '2521' . '00';   // P=25, L=21, 00
    $mod = 0;
    foreach (str_split($num, 7) as $chunk) $mod = (int)(($mod . $chunk) % 97);
    $cd = str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT);
    return 'PL' . $cd . $bban;
};

$accounts = [
    'wol1' => ['name' => 'Anna Kompletna (TEST)', 'phone' => $phone, 'nr' => 'WOL/TEST-ZD/1',
               'person' => ['addr_street' => 'ul. Testowa', 'addr_house' => '12', 'addr_flat' => '4', 'addr_postal' => '30-001', 'addr_city' => 'Kraków', 'addr_country' => 'Polska',
                            'adres' => 'ul. Testowa 12/4, 30-001 Kraków', 'rachunek_bankowy' => $iban('10202906', '0000000000012345'), 'urzad_skarbowy' => 'Urząd Skarbowy Kraków-Krowodrza',
                            'data_urodzenia' => '1995-04-12'],
               'scen' => 'pełne dane — zmiana adresu/telefonu od razu; zmiana rachunku → akceptacja + kod ' . ($phone !== '' ? 'SMS' : 'e-mail (brak --telefon)')],
    'wol2' => ['name' => 'Bartosz Bezadresowy (TEST)', 'phone' => '', 'nr' => 'WOL/TEST-ZD/2', 'person' => [],
               'scen' => 'rekord osoby bez adresu, rachunku i telefonu — uzupełnianie braków, kod podpisu e-mailem'],
    'wol3' => ['name' => 'Celina Bezrekordu (TEST)', 'phone' => '', 'nr' => 'WOL/TEST-ZD/3', 'person' => null,
               'scen' => 'umowa bez rekordu w rejestrze Osób — pierwsza zmiana adresu zakłada i podpina Osobę'],
];

echo ($delete ? 'USUWANIE' : 'ZAKŁADANIE') . ($apply ? '' : ' (podgląd — dodaj --apply)') . "\n";
$today = date('Y-m-d');

foreach ($accounts as $tag => $a) {
    $email = $mail($tag);
    $u = db_one("SELECT id FROM users WHERE lower(email) = ?", [$email]);
    $c = db_one("SELECT id, person_id FROM umowy_wolontariat WHERE numer_umowy = ?", [$a['nr']]);
    $p = db_one("SELECT id FROM persons WHERE lower(email) = ?", [$email]);

    if ($delete) {
        echo "  {$tag} {$email}: konto " . ($u ? '#' . $u['id'] : '—') . ', umowa ' . ($c ? '#' . $c['id'] : '—') . ', osoba ' . ($p ? '#' . $p['id'] : '—') . "\n";
        if (!$apply) continue;
        $uid = $u ? (int)$u['id'] : 0;
        try { if ($uid) db_exec("DELETE FROM collaborator_data_changes WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        try { if ($uid) db_exec("DELETE FROM collaborator_sign_requests WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        try { if ($uid) db_exec("DELETE FROM user_applications WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}   // wnioski SZD z testów
        if ($c) db_exec("DELETE FROM umowy_wolontariat WHERE id = ?", [(int)$c['id']]);
        // osoba: założona przez seeder (wol1/wol2) albo przez moduł przy pierwszej zmianie (wol3) — zawsze po e-mailu testowym
        db_exec("DELETE FROM persons WHERE lower(email) = ?", [$email]);
        if ($uid) db_exec("DELETE FROM users WHERE id = ?", [$uid]);
        continue;
    }

    if ($u) { echo "  POMINIĘTO {$tag} {$email} — konto już jest (#{$u['id']}). {$a['scen']}\n"; continue; }
    echo "  {$tag} {$email} — {$a['name']}: {$a['scen']}\n";
    if (!$apply) continue;
    if (strlen($pass) < 8) { fwrite(STDERR, "Podaj --haslo (min. 8 znaków).\n"); cli_exit(2); }

    $uid = db_insert('users', ['name' => $a['name'], 'email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'viewer', 'is_active' => 1]);
    if ($a['phone'] !== '') db_exec("UPDATE users SET phone_number = ? WHERE id = ?", [$a['phone'], $uid]);
    $pid = null;
    if ($a['person'] !== null && !$p) {
        $pid = db_insert('persons', ['imie_nazwisko' => $a['name'], 'email' => $email, 'telefon' => $a['phone'], 'created_by' => $uid, 'uwagi' => 'Konto testowe — scenariusze Zmiany danych (cli/seedWolontariuszeZmianaDanych.php)'] + $a['person']);
    } elseif ($p) $pid = (int)$p['id'];
    if (!$c) {
        db_insert('umowy_wolontariat', ['numer_umowy' => $a['nr'], 'status' => 'podpisana', 'imie_nazwisko' => $a['name'], 'email' => $email, 'telefon' => $a['phone'],
            'data_zawarcia' => $today, 'data_rozpoczecia' => $today, 'bezterminowa' => 1, 'przedmiot_porozumienia' => 'Wolontariat testowy — scenariusze zmiany danych',
            'person_id' => $pid, 'uwagi' => 'TEST — usuń: php cli/seedWolontariuszeZmianaDanych.php --delete --apply', 'created_at' => date('Y-m-d H:i:s')]);
    }
    echo "    OK konto #{$uid}" . ($pid ? ", osoba #{$pid}" : ', bez rekordu osoby') . "\n";
}
if (!$apply) echo "Nic nie zapisano.\n";
