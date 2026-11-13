<?php
/**
 * cli/resetHasel.php — reset WSZYSTKICH haseł osoby po adresie e-mail z SZO (polecenie 2026-11-14).
 * Logika: modules/nowe_haslo/logic/NoweHasloAdminReset.php.
 *
 * Co zmienia (domyślnie wszystko, --tylko zawęża):
 *   szo    — hasło lokalne SZO (= to samo co logowanie jednokrotne, gdy osoba je ma) + wylogowanie wszystkich sesji SZO,
 *   sso    — logowanie jednokrotne (Logto) i chmura plików: jedno wspólne, losowe hasło,
 *   poczta — skrzynka na dhosting: osobne, losowe hasło (poczta ma zawsze inne hasło).
 * Hasła idą SMS-em na numer z konta albo z umowy; e-mail „ustawiliśmy nowe hasła” bez haseł; notatka w Helpdesku; audit_logs.
 *
 * Użycie (na produkcji, w katalogu aplikacji):
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl                     # podgląd: co osoba ma, bez zmian
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply             # reset + SMS z hasłami
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply --showpassword            # reset + SMS + hasła na ekranie
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply --showpassword --bez-sms  # tylko na ekranie, bez SMS
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply --tylko=sso,szo
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply --zapytaj                 # hasła wpisane ręcznie (bez echa, bez historii powłoki)
 *   php cli/resetHasel.php jan.kowalski@feer.org.pl --apply --haslo='…' --haslo-poczty='…'
 * Hasła ręczne: --haslo (SZO + logowanie jednokrotne + chmura), --haslo-poczty (poczta; musi być inne). Te same wymagania co /nowehaslo:
 * min. 12 znaków, małe i wielkie litery, cyfry, bez imienia/nazwiska/loginu. Puste przy --zapytaj = losowe.
 * --showpassword (albo --pokaz) wyświetla nowe hasła w terminalu — wymagane, gdy osoba nie ma numeru komórkowego albo przy --bez-sms.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

$base = dirname(__DIR__);
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/sms.php';
require_once $base . '/modules/nowe_haslo/logic/NoweHasloAdminReset.php';

// Notatka w Helpdesku sięga do sesji (auth_start) — startujemy ją przed pierwszym wypisanym tekstem, żeby nie było ostrzeżeń „headers already sent”.
if (session_status() === PHP_SESSION_NONE) @auth_start();

$args = array_slice($argv, 1);
$email = '';
$apply = $show = $noSms = $ask = false;
$manual = [];
$parts = NoweHasloAdminReset::PARTS;
foreach ($args as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--showpassword' || $a === '--pokaz') $show = true;
    elseif ($a === '--bez-sms' || $a === '--nosms') $noSms = true;
    elseif ($a === '--zapytaj') $ask = true;
    elseif (str_starts_with($a, '--haslo=')) $manual['sso'] = substr($a, 8);
    elseif (str_starts_with($a, '--haslo-poczty=')) $manual['poczta'] = substr($a, 15);
    elseif (str_starts_with($a, '--tylko=')) $parts = array_values(array_intersect(NoweHasloAdminReset::PARTS, array_map('trim', explode(',', substr($a, 8)))));
    elseif (!str_starts_with($a, '--') && $email === '') $email = $a;
    else { fwrite(STDERR, "Nieznana opcja: {$a}\n"); exit(2); }
}
if ($email === '') {
    fwrite(STDERR, "Użycie: php cli/resetHasel.php <e-mail z SZO> [--apply] [--showpassword] [--bez-sms] [--tylko=szo,sso,poczta]\n"
                 . "                                 [--zapytaj | --haslo=… --haslo-poczty=…]\n");
    exit(2);
}
if (!$parts) { fwrite(STDERR, "--tylko: wybierz z szo, sso, poczta.\n"); exit(2); }

$u = NoweHasloAdminReset::findUser($email);
if (!$u) { fwrite(STDERR, "Nie ma w SZO konta z adresem {$email} (sprawdzono e-mail, zapasowy e-mail i login Microsoft).\n"); exit(1); }
$p = NoweHasloAdminReset::person($u);
$plan = NoweHasloAdminReset::plan($u, $p);

echo "Osoba:   {$u['name']} <{$u['email']}> (id {$u['id']})" . ((int)$u['is_active'] ? '' : ' — KONTO NIEAKTYWNE') . "\n";
echo "Telefon: " . ($plan['phone'] !== '' ? $plan['phone'] : 'brak numeru komórkowego') . "\n";
echo "SZO:     {$plan['szo']}; aktywne sesje: {$plan['sessions']}\n";
echo "IAM:     {$plan['sso']}\n";
echo "Poczta:  {$plan['poczta']}\n";
echo "Zakres:  " . implode(', ', $parts) . "\n\n";

/** Hasło z klawiatury bez echa (pusta odpowiedź = losowe). */
function rhAsk(string $label): string
{
    fwrite(STDOUT, $label . ' (Enter = losowe): ');
    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    if ($tty) @shell_exec('stty -echo');
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) { @shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $v;
}
if ($apply && $ask) {
    if (in_array('sso', $parts, true) || in_array('szo', $parts, true)) {
        $v = rhAsk('Hasło SZO / logowania jednokrotnego / chmury');
        if ($v !== '' && $v !== rhAsk('Powtórz')) { fwrite(STDERR, "Hasła się różnią. Nic nie zmieniono.\n"); exit(1); }
        if ($v !== '') $manual['sso'] = $v;
    }
    if (in_array('poczta', $parts, true)) {
        $v = rhAsk('Hasło do poczty (inne)');
        if ($v !== '' && $v !== rhAsk('Powtórz')) { fwrite(STDERR, "Hasła się różnią. Nic nie zmieniono.\n"); exit(1); }
        if ($v !== '') $manual['poczta'] = $v;
    }
}
if ($manual) {
    try { NoweHasloAdminReset::validateManual($p, $manual); }
    catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage() . " Nic nie zmieniono.\n"); exit(1); }
    echo "Hasła ręczne: " . implode(', ', array_map(fn($k) => $k === 'sso' ? 'SZO/logowanie jednokrotne/chmura' : 'poczta', array_keys($manual))) . " (pozostałe losowe)\n\n";
}

$sms = !$noSms && $plan['phone'] !== '';
// hasła ustawione ręcznie administrator zna — nie wymagamy SMS-a ani --showpassword, o ile nie zostaje żadne losowe
$randomLeft = (in_array('sso', $parts, true) || in_array('szo', $parts, true)) && !isset($manual['sso'])
           || in_array('poczta', $parts, true) && !isset($manual['poczta']);
if (!$sms && !$show && $randomLeft) {
    echo ($noSms ? 'Przy --bez-sms' : 'Osoba nie ma numeru komórkowego, a hasła nie wysyłamy e-mailem.')
       . " Dodaj --showpassword, żeby zobaczyć nowe hasła tutaj i przekazać je osobie.\n";
    exit(1);
}
if (!$apply) {
    echo "PODGLĄD — nic nie zmieniono. Dodaj --apply, żeby zresetować hasła"
       . ($sms ? ' (pójdą SMS-em' . ($show ? ' i pokażemy je tutaj' : '') . ').' : ' (pokażemy je tutaj, bez SMS).') . "\n";
    exit(0);
}

$by = 'CLI ' . (get_current_user() ?: 'admin');
$r = NoweHasloAdminReset::run($u, $p, $parts, $sms, $by, $manual);

foreach ($r['done'] as $l) echo "✓ {$l}\n";
foreach ($r['skipped'] as $l) echo "– {$l}\n";
foreach ($r['failed'] as $l) echo "✗ {$l}\n";
echo "SMS: " . ($r['sms'] ? implode(', ', $r['sms']) : ($sms ? 'brak haseł do wysłania' : 'nie wysyłano')) . "\n";
echo "E-mail o zmianie (bez haseł): " . ($r['mail'] !== '' ? $r['mail'] : 'nie wysłano') . "\n";

if ($show && $r['passwords']) {
    $labels = ['sso' => 'Logowanie jednokrotne + chmura' . (isset($r['passwords']['sso']) && in_array('szo', $parts, true) && (string)($u['password'] ?? '') !== '' ? ' + SZO' : ''),
               'szo' => 'SZO', 'poczta' => 'Poczta ' . ($p['mailbox'] ?? '')];
    echo "\nNowe hasła (pokazujemy tylko teraz):\n";
    foreach ($r['passwords'] as $k => $pw) echo '  ' . str_pad($labels[$k] . ':', 42) . $pw . "\n";
}
exit($r['failed'] ? 1 : 0);
