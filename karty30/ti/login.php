<?php
/**
 * karty30/ti/login.php — wspólne logowanie TI (na ti.* / kurs.* pod „/login.php”).
 *
 * Jeden formularz „login + hasło” dla wszystkich: uczestnik, rodzic/opiekun, osoba upoważniona i kadra (prowadzący,
 * konsultant, kierownik). Rodzaj konta rozpoznaje TiLogin (modules/ti_login/logic/TiLogin.php) — reguły i zabezpieczenia
 * opisane tam. Adresy @feer.org.pl → Microsoft 365; kolizja loginów → wybór panelu po poprawnym haśle.
 *
 * Stare wejścia (tozsamosc/kursant.php, kursant/login.php, kursant/parent_login.php, kursant/authp_login.php,
 * dydaktyk/login.php, dydaktyk/logowanie.php) przekierowują tutaj — POST kodem 307 (z danymi formularza).
 * Kody ?e=N ze starych przekierowań nadal działają (jeden ogólny komunikat dla błędnych danych).
 * Kandydaci (nabór) mają osobne konto: /nabor/logowanie (?tab=kandydat przekierowuje tam).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/pfron.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/org_case.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_messages.php';        // ti_account_log() — nieudane próby uczestnika
require_once dirname(dirname(__DIR__)) . '/includes/ti_student_otp.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_sec_image.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_student_reauth.php';
require_once __DIR__ . '/kursant/auth.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_login/logic/TiLogin.php';

karty30_migrate();
ti_sec_image_migrate();
ti_reauth_migrate();

$TI_BASE = rtrim((string)(parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/') . '/karty30/ti/';

$hint = in_array($_GET['tab'] ?? '', ['kursant', 'dydaktyk', 'rodzic', 'up', 'kandydat'], true) ? (string)$_GET['tab'] : '';
if ($hint === 'kandydat') { header('Location: /nabor/logowanie', true, 302); exit; }

$ERR = [
    2 => 'Rodzic lub opiekun wstrzymał dostęp do panelu. Skontaktuj się z nim.',
    4 => 'To konto Microsoft 365 nie ma dostępu do panelu prowadzącego. Napisz do kierownika.',
    5 => TiLogin::ERROR_THROTTLE,
];
$ec     = (int)($_GET['e'] ?? 0);
$error  = $ec > 0 ? ($ERR[$ec] ?? TiLogin::ERROR) : null;
$notice = ($_GET['out'] ?? '') === '1' ? 'Wylogowano z panelu.' : null;
$login  = '';
$view   = 'form';
$choiceItems = []; $choiceNonce = '';

$go = static function (string $url): void { header('Location: ' . $url, true, 303); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wybor'])) {
    // Krok 2 przy kolizji: wybrany panel (konta zapamiętane w sesji po poprawnym haśle).
    $c = TiLogin::pendingChoice();
    $i = (int)$_POST['wybor'];
    if (!$c || !hash_equals((string)$c['nonce'], (string)($_POST['nonce'] ?? '')) || !isset($c['items'][$i])) {
        $error = 'Czas na wybór panelu minął. Zaloguj się ponownie.';
    } else {
        TiLogin::clearChoice();
        $url = TiLogin::completePanel($c['items'][$i], (string)$c['login']);
        if ($url !== null) $go($url);
        $error = TiLogin::ERROR;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $error = null;

    if ($login === '' || mb_strlen($login) > 190) {
        $error = 'Wpisz login lub adres e-mail.';
    } elseif (TiLogin::isOfficeLogin($login)) {
        // Konta Microsoft 365 organizacji — tylko logowanie Microsoft (reguła po domenie, bez sprawdzania hasła).
        $go(TiLogin::officeUrl());
    } elseif (TiLogin::throttled($login)) {
        $error = TiLogin::ERROR_THROTTLE;
    } elseif (TiLogin::isAltConsultantLogin($login)) {
        // Login zastępczy konsultanta (numer 3-cyfrowy) ma osobny adres logowania (konsultant.php) — tu tylko podpowiedź.
        $error = 'Login zastępczy konsultanta wpisz na stronie: ' . TiLogin::altUrl();
    } elseif (TiLogin::isStaffLogin($login)) {
        // Kadra hasłem (konta panelowe spoza domeny Microsoft 365) → obowiązkowe TOTP w panelu dydaktyka.
        require_once __DIR__ . '/dydaktyk/auth.php';
        $url = TiLogin::completeStaff($login, $pass);
        if ($url !== null) { TiLogin::clearAttempts($login); $go($url); }
        TiLogin::recordAttempt($login);
        $error = TiLogin::ERROR;
    } else {
        $r = TiLogin::resolvePanelAccounts($login, $pass);
        $usable = array_values(array_filter($r['matches'], fn($m) => empty($m['blocked'])));
        if (count($usable) === 1) {
            TiLogin::clearAttempts($login);
            $url = TiLogin::completePanel($usable[0], $login);
            if ($url !== null) $go($url);
            $error = TiLogin::ERROR;
        } elseif (count($usable) > 1) {
            TiLogin::clearAttempts($login);
            $choiceItems = $usable;
            $choiceNonce = TiLogin::stashChoice($login, $usable);
            $view = 'choice';
        } elseif ($r['matches']) {
            // Hasło poprawne, ale uczestnik ma wstrzymany dostęp przez opiekuna — mówimy o tym dopiero po haśle.
            TiLogin::clearAttempts($login);
            $error = $ERR[2];
        } elseif ($r['reauth']) {
            // Pierwsze logowanie / ponowna autoryzacja uczestnika: kod SMS (liczony do limitu prób — chroni przed zalewem SMS-ów).
            TiLogin::recordAttempt($login);
            $url = TiLogin::startReauth($r['reauth']);
            if ($url !== null) $go($url);
            $error = TiLogin::ERROR;
        } else {
            TiLogin::recordAttempt($login);
            $error = TiLogin::ERROR;
        }
    }
} elseif ($ec === 0 && $notice === null) {
    // Podpowiedź dla wejść ze starych adresów (dawne zakładki ról) — formularz i tak jest jeden.
    $notice = [
        'dydaktyk' => 'Kadra: zaloguj się przez Microsoft 365 albo wpisz adres e-mail konta panelu.',
        'rodzic'   => 'Rodzic lub opiekun: wpisz login rodzica (np. j.kowalski-r) i hasło. Bez hasła — kod SMS w „Inne sposoby logowania”.',
        'up'       => 'Osoba upoważniona: wpisz login od uczestnika (np. a.nowak-up) i hasło.',
    ][$hint] ?? null;
}

$formAction = TiLogin::url();
$officeUrl  = TiLogin::officeUrl();
$pfronOn    = k30_pfron_enabled();
$naborOn    = org_setting('ti_nabor_enabled') === '1';
header('Cache-Control: no-store');
include dirname(dirname(__DIR__)) . '/modules/ti_login/view/loginPage.php';
