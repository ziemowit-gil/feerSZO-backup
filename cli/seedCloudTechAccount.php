<?php
/**
 * cli/seedCloudTechAccount.php — konto techniczne SZO w chmurze plików (Nextcloud): administrator, na którym SZO woła
 * OCS Provisioning API (konta, grupy zespołów). Polecenie 2026-11-14 po błędzie „Logged in account must be at least a sub admin
 * (kod 403)”: obecne konto API nie jest administratorem chmury.
 *
 * Konto istnieje tylko w chmurze. W SZO są wyłącznie ustawienia sso_cloud_user i sso_cloud_pass (fcEncrypt), bez rekordu w users,
 * więc nie pojawia się na żadnej liście SZO (użytkownicy, katalog, wyszukiwarka, wybór osób) ani w synchronizacji z Logto.
 * W chmurze nazwa i e-mail dostają zakres „prywatny” (v2-private), żeby konto nie podpowiadało się przy udostępnianiu.
 *
 * Użycie (na produkcji, w katalogu aplikacji; także z Admin › Seedery):
 *   php cli/seedCloudTechAccount.php --status
 *   php cli/seedCloudTechAccount.php --create [--uid=szo-api] [--haslo=…]
 *       Obecne konto API jest administratorem → zakłada konto przez API, dodaje do grupy admin i od razu przełącza SZO.
 *       Nie jest → zapisuje hasło (zaszyfrowane) jako oczekujące i wypisuje JEDNORAZOWO polecenie occ do uruchomienia na serwerze chmury.
 *   php cli/seedCloudTechAccount.php --activate
 *       Po wykonaniu occ: sprawdza logowanie i członkostwo w grupie admin, potem przełącza SZO na to konto.
 *   php cli/seedCloudTechAccount.php --forget      # porzuca oczekujące konto (nie rusza chmury ani bieżących ustawień)
 * Hasło: 32 znaki (litery i cyfry, bez znaków wymagających cytowania w powłoce), chyba że podasz własne w --haslo.
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }

$base = dirname(__DIR__);
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/modules/field_crypto/lib/fieldCrypto.php';
require_once $base . '/modules/audit_logs/logic/audit_logs.php';
require_once $base . '/modules/sso_logto/lib/SsoConfig.php';

const CTA_DEFAULT_UID = 'szo-api';
const CTA_DISPLAY     = 'SZO (konto techniczne)';
const CTA_PENDING     = 'sso_cloud_tech_pending';   // JSON {uid, pass: fcEncrypt} — konto czekające na occ

$opt = cli_getopt('', ['status', 'create', 'activate', 'forget', 'uid:', 'haslo:']);
$opt = is_array($opt) ? $opt : [];
$uid = strtolower(trim((string)($opt['uid'] ?? CTA_DEFAULT_UID)));
if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $uid)) { fwrite(STDERR, "Login konta: 3–64 znaki, małe litery, cyfry, . _ -\n"); cli_exit(1); }

function ctaPassword(): string
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 32; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
    return $p;
}

/** Czy konto $user (z hasłem) loguje się do API i jest w grupie admin. @return array{ok:bool, admin:bool, msg:string} */
function ctaCheck(string $user, string $pass): array
{
    if ($user === '' || $pass === '') return ['ok' => false, 'admin' => false, 'msg' => 'brak loginu albo hasła'];
    $api = new CloudApi(SsoConfig::cloudUrl(), $user, $pass, SsoConfig::cloudKind());
    try {
        $me = $api->ocs('GET', 'users/' . rawurlencode($user));
        $groups = array_map('strval', (array)($me['groups'] ?? []));
        return ['ok' => true, 'admin' => in_array('admin', $groups, true), 'msg' => 'grupy: ' . ($groups ? implode(', ', $groups) : '(brak)')];
    } catch (CloudApiException $e) {
        return ['ok' => false, 'admin' => false, 'msg' => $e->getMessage()];
    }
}

function ctaCurrent(): array
{
    $pass = '';
    $enc = SsoConfig::get('sso_cloud_pass');
    if ($enc !== '') $pass = (string)(fcDecrypt($enc, 'settings.sso_cloud_pass') ?? '');
    return [SsoConfig::get('sso_cloud_user'), $pass];
}

function ctaPending(): ?array
{
    $j = json_decode(SsoConfig::get(CTA_PENDING), true);
    if (!is_array($j) || empty($j['uid'])) return null;
    $p = (string)(fcDecrypt((string)($j['pass'] ?? ''), 'settings.' . CTA_PENDING) ?? '');
    return $p === '' ? null : ['uid' => (string)$j['uid'], 'pass' => $p];
}

/** Przełącza SZO na konto techniczne (login + zaszyfrowane hasło), czyści oczekujące. */
function ctaSwitch(string $user, string $pass): void
{
    org_setting_set('sso_cloud_user', $user);
    org_setting_set('sso_cloud_pass', (string)fcEncrypt($pass, 'settings.sso_cloud_pass'));
    org_setting_set(CTA_PENDING, '');
    audit_log('sso.cloud_tech_account', ['uid' => $user, 'action' => 'switch'], null);
}

/** Zakres „prywatny” dla nazwy i e-maila — konto nie podpowiada się przy udostępnianiu (Nextcloud 21+; błąd nie przerywa). */
function ctaHide(CloudApi $api, string $uid): void
{
    foreach (['displaynameScope', 'emailScope', 'phoneScope', 'addressScope', 'websiteScope', 'twitterScope'] as $k) {
        try { $api->ocs('PUT', 'users/' . rawurlencode($uid), ['key' => $k, 'value' => 'v2-private']); } catch (CloudApiException) {}
    }
}

if (!SsoConfig::cloudConfigured()) { echo "Chmura plików nie jest skonfigurowana (adres w /ssoadmin). Nic nie zrobiono.\n"; cli_exit(1); }
$label = SsoConfig::cloudLabel();
[$curUser, $curPass] = ctaCurrent();

// ── --status ────────────────────────────────────────────────────────────────────────────────
if (isset($opt['status']) || (!isset($opt['create']) && !isset($opt['activate']) && !isset($opt['forget']))) {
    echo "{$label}: " . SsoConfig::cloudUrl() . "\n";
    $c = ctaCheck($curUser, $curPass);
    echo "Konto API w SZO: " . ($curUser !== '' ? $curUser : '(brak)') . ' — ' . ($c['ok'] ? ($c['admin'] ? 'administrator ✓' : 'NIE jest administratorem') : 'nie loguje się') . " ({$c['msg']})\n";
    $p = ctaPending();
    echo $p ? "Oczekujące konto techniczne: {$p['uid']} — po wykonaniu occ uruchom --activate.\n" : "Brak oczekującego konta technicznego.\n";
    cli_exit(0);
}

// ── --forget ────────────────────────────────────────────────────────────────────────────────
if (isset($opt['forget'])) {
    org_setting_set(CTA_PENDING, '');
    echo "Porzucono oczekujące konto techniczne. Ustawienia konta API i chmura bez zmian.\n";
    cli_exit(0);
}

// ── --activate ──────────────────────────────────────────────────────────────────────────────
if (isset($opt['activate'])) {
    $p = ctaPending();
    if (!$p) { echo "Brak oczekującego konta. Najpierw --create.\n"; cli_exit(1); }
    $c = ctaCheck($p['uid'], $p['pass']);
    if (!$c['ok']) { echo "Konto {$p['uid']} nie loguje się do API ({$c['msg']}). Czy polecenie occ zostało wykonane?\n"; cli_exit(1); }
    if (!$c['admin']) { echo "Konto {$p['uid']} loguje się, ale nie jest w grupie admin ({$c['msg']}). Dodaj je: occ group:adduser admin {$p['uid']}\n"; cli_exit(1); }
    ctaHide(new CloudApi(SsoConfig::cloudUrl(), $p['uid'], $p['pass'], SsoConfig::cloudKind()), $p['uid']);
    ctaSwitch($p['uid'], $p['pass']);
    echo "Gotowe: SZO korzysta teraz z konta technicznego {$p['uid']} (administrator {$label}). Poprzednie konto API: " . ($curUser ?: '(brak)') . ".\n";
    cli_exit(0);
}

// ── --create ────────────────────────────────────────────────────────────────────────────────
if ($curUser === $uid) {
    $c = ctaCheck($curUser, $curPass);
    if ($c['ok'] && $c['admin']) { echo "SZO już korzysta z konta technicznego {$uid} i jest ono administratorem. Nic nie zrobiono.\n"; cli_exit(0); }
}
$pass = (string)($opt['haslo'] ?? '');
if ($pass === '') $pass = ctaPassword();
elseif (strlen($pass) < 16) { fwrite(STDERR, "Hasło konta technicznego: co najmniej 16 znaków.\n"); cli_exit(1); }

$cur = ctaCheck($curUser, $curPass);
if ($cur['ok'] && $cur['admin']) {
    $api = SsoConfig::cloud();
    try {
        if ($api->userExists($uid)) {
            $api->setPassword($uid, $pass);
            try { $api->ocs('POST', 'users/' . rawurlencode($uid) . '/groups', ['groupid' => 'admin']); } catch (CloudApiException) {}
            echo "Konto {$uid} już było w {$label}: nadano nowe hasło i grupę admin.\n";
        } else {
            $api->createUser($uid, $pass, CTA_DISPLAY, '', 'admin');
            echo "Założono konto {$uid} w {$label} (grupa admin).\n";
        }
    } catch (CloudApiException $e) {
        echo "Nie udało się założyć konta: " . $e->getMessage() . "\n";
        cli_exit(1);
    }
    $c = ctaCheck($uid, $pass);
    if (!$c['ok'] || !$c['admin']) { echo "Konto powstało, ale test logowania nie przeszedł ({$c['msg']}). Ustawienia SZO bez zmian.\n"; cli_exit(1); }
    ctaHide(new CloudApi(SsoConfig::cloudUrl(), $uid, $pass, SsoConfig::cloudKind()), $uid);
    ctaSwitch($uid, $pass);
    echo "Gotowe: SZO korzysta teraz z konta technicznego {$uid}. Hasło zapisane zaszyfrowane w SZO, nigdzie go nie wyświetlamy.\n";
    cli_exit(0);
}

// Obecne konto API nie jest administratorem: konto musi założyć administrator serwera chmury (occ).
// Wynik z Admin › Seedery zostaje w historii sesji — tam wygenerowanego hasła nie pokazujemy, trzeba podać własne w polu.
$own = (string)($opt['haslo'] ?? '') !== '';
if (!$own && defined('SZO_CLI_INPROC')) {
    echo "Obecne konto API (" . ($curUser ?: 'brak') . ") nie jest administratorem {$label}, więc konto założy administrator serwera chmury poleceniem occ.\n"
       . "Wpisz własne hasło konta technicznego (co najmniej 16 znaków) w polu „Hasło konta” i uruchom „Załóż” ponownie,\n"
       . "albo uruchom ten seeder z terminala — wtedy wygeneruje hasło i pokaże je raz.\n";
    cli_exit(1);
}
$shown = $own ? '<hasło podane w --haslo>' : $pass;
$hint = $own ? 'w miejsce znacznika wstaw swoje hasło' : 'hasło wyświetlamy tylko teraz; nie zapisuj go nigdzie indziej';
org_setting_set(CTA_PENDING, (string)json_encode(['uid' => $uid, 'pass' => fcEncrypt($pass, 'settings.' . CTA_PENDING)]));
audit_log('sso.cloud_tech_account', ['uid' => $uid, 'action' => 'pending'], null);
echo "Obecne konto API (" . ($curUser ?: 'brak') . ") nie jest administratorem {$label}, więc nie może samo założyć konta.\n"
   . "Zapisaliśmy hasło (zaszyfrowane) jako oczekujące. Na serwerze chmury uruchom JEDNO z poleceń poniżej\n"
   . "({$hint}):\n\n"
   . "  # instalacja zwykła (katalog Nextcloud):\n"
   . "  sudo -u www-data env OC_PASS='{$shown}' php occ user:add --password-from-env --display-name='" . CTA_DISPLAY . "' --group=admin {$uid}\n\n"
   . "  # Docker:\n"
   . "  docker exec -u www-data -e OC_PASS='{$shown}' <kontener-nextcloud> php occ user:add --password-from-env --display-name='" . CTA_DISPLAY . "' --group=admin {$uid}\n\n"
   . "Gdy konto już istnieje: occ user:resetpassword --password-from-env {$uid} oraz occ group:adduser admin {$uid}.\n"
   . "Potem: php cli/seedCloudTechAccount.php --activate (albo „Aktywuj” w Admin › Seedery).\n";
cli_exit(0);
