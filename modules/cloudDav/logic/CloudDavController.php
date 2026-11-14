<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/NcLoginFlow.php';
require_once dirname(__DIR__) . '/lib/NcDav.php';
require_once dirname(__DIR__) . '/lib/AppleProfile.php';
require_once dirname(__DIR__, 2) . '/sso_logto/logic/SsoAccounts.php';

/**
 * modules/cloudDav/logic/CloudDavController.php — „Kalendarz i kontakty z chmury” (/kalendarz-kontakty).
 *
 * Polecenie 2026-11-14 („Książka CardDAV, CalDAV, WebDAV Nextcloud + Roundcube”, potem „zrób jakąś integrację”). Webmail dPoczty
 * (Roundcube z dodatkami dostawcy) nie przyjmuje naszych wtyczek, ale jego kalendarz ma „Dodaj kalendarz CalDAV”; kontaktów CardDAV
 * nie obsługuje. Integracja działa więc po stronie SZO: użytkownik wybiera urządzenie, SZO uruchamia Login Flow v2 w chmurze
 * (NcLoginFlow), użytkownik potwierdza dostęp na stronie chmury, a SZO dostaje hasło aplikacji, odczytuje listę kalendarzy
 * i książek adresowych (NcDav) i pokazuje gotowe dane do wklejenia: w dPoczcie, w DAVx5, w Thunderbirdzie; dla iPhone'a, iPada
 * i Maca daje profil .mobileconfig (AppleProfile), który ustawia kalendarz i kontakty jednym dotknięciem.
 *
 * Hasło aplikacji jest tylko w sesji (najwyżej RESULT_TTL, „Zakończ” je usuwa); SZO go nie zapisuje w bazie ani w dzienniku.
 * Konto w chmurze musi być kontem tej osoby (uid z current-user-principal = sso_accounts.cloud_uid); inaczej hasło jest od razu
 * usuwane w chmurze (revoke) i nic nie pokazujemy.
 */
final class CloudDavController
{
    private const VIEW = __DIR__ . '/../view/';
    public const SESSION_KEY = 'cloud_dav';
    /** Jak długo strona pokazuje hasło aplikacji (sekundy). */
    public const RESULT_TTL = 1800;
    /** Najwyżej tyle połączeń na godzinę w jednej sesji. */
    public const MAX_STARTS = 8;

    /** @return array<string, array{label:string, client:string, hint:string}> */
    public static function purposes(): array
    {
        return [
            'dpoczta' => ['label' => 'Kalendarz w poczcie dPoczta', 'client' => 'dPoczta: kalendarz (SZO)',
                          'hint' => 'Kalendarz z chmury pojawi się w kalendarzu na dpoczta.pl.'],
            'apple'   => ['label' => 'iPhone, iPad albo Mac', 'client' => 'iPhone, iPad, Mac (SZO)',
                          'hint' => 'Kalendarz i kontakty. Dostaniesz profil, który ustawi wszystko sam.'],
            'android' => ['label' => 'Telefon albo tablet z Androidem', 'client' => 'Android, DAVx5 (SZO)',
                          'hint' => 'Kalendarz i kontakty przez aplikację DAVx5.'],
            'program' => ['label' => 'Thunderbird albo inny program na komputerze', 'client' => 'Thunderbird lub program (SZO)',
                          'hint' => 'Program, który obsługuje CalDAV i CardDAV.'],
        ];
    }

    public function index(): ?string
    {
        $ctx = $this->context();
        $st = $this->state();
        $flash = function_exists('flash_get') ? flash_get() : null;
        // kalendarze i kontakty z Microsoft 365 (PimImport): zadania dla kont Microsoft tej osoby
        $pim = null;
        if (class_exists('PimImport') && PimImport::enabled() && PimImport::exportAvailable()) {
            $u = db_one("SELECT id, email, m365_login FROM users WHERE id=?", [$ctx['userId']]) ?: [];
            $upns = PimImport::upnsFor($u);
            if ($upns) $pim = ['upns' => $upns, 'jobs' => array_values(array_filter(array_map([PimImport::class, 'forUpn'], $upns)))];
        }
        return Router::view(self::VIEW . 'connect.php', $ctx + [
            'purposes' => self::purposes(),
            'pending'  => $st['pending'] ?? null,
            'result'   => $st['result'] ?? null,
            'purpose'  => (string)($st['purpose'] ?? ''),
            'flash'    => $flash,
            'pim'      => $pim,
        ]);
    }

    /** POST /kalendarz-kontakty/polacz — początek Login Flow v2. XHR: JSON {ok, login}; bez JS: powrót na stronę ze stanem „czekam”. */
    public function start(): mixed
    {
        $ctx = $this->context();
        $purpose = (string)($_POST['purpose'] ?? '');
        $p = self::purposes()[$purpose] ?? null;
        if ($p === null) return $this->fail('Wybierz, gdzie chcesz mieć kalendarz i kontakty.');
        if ($ctx['cloudUrl'] === '' || $ctx['cloudUid'] === '') return $this->fail('Najpierw potrzebujesz konta w chmurze plików.');
        $st = $this->state();
        $starts = array_values(array_filter((array)($st['starts'] ?? []), fn($t) => (int)$t > time() - 3600));
        if (count($starts) >= self::MAX_STARTS) return $this->fail('Za dużo prób w ciągu godziny. Spróbuj później albo napisz do Helpdesku IT.');
        try {
            $flow = (new NcLoginFlow($ctx['cloudUrl']))->start($p['client']);
        } catch (\Throwable $e) {
            error_log('[cloudDav] start ' . $ctx['email'] . ': ' . $e->getMessage());
            return $this->fail('Chmura teraz nie odpowiada. Spróbuj za kilka minut.');
        }
        $starts[] = time();
        $_SESSION[self::SESSION_KEY] = ['purpose' => $purpose, 'starts' => $starts,
            'pending' => ['token' => $flow['token'], 'endpoint' => $flow['endpoint'], 'login' => $flow['login'], 'at' => time()]];
        return $this->isXhr() ? ['ok' => true, 'login' => $flow['login']] : Router::redirect($this->home());
    }

    /** POST /kalendarz-kontakty/sprawdz — czy użytkownik potwierdził dostęp w chmurze. JSON {state: wait|done|expired|other|error|none}. */
    public function poll(): mixed
    {
        $ctx = $this->context();
        $st = $this->state();
        $pending = $st['pending'] ?? null;
        if (!$pending) return $this->pollOut('none', '');
        $flow = new NcLoginFlow($ctx['cloudUrl']);
        try {
            $res = $flow->poll((string)$pending['endpoint'], (string)$pending['token']);
        } catch (NcLoginFlowExpired $e) {
            unset($_SESSION[self::SESSION_KEY]['pending']);
            return $this->pollOut('expired', 'Czas na potwierdzenie minął. Kliknij „Połącz z chmurą” jeszcze raz.');
        } catch (\Throwable $e) {
            error_log('[cloudDav] poll ' . $ctx['email'] . ': ' . $e->getMessage());
            return $this->pollOut('error', 'Chmura teraz nie odpowiada. Spróbujemy ponownie za chwilę.');
        }
        if ($res === null) return $this->pollOut('wait', '');

        unset($_SESSION[self::SESSION_KEY]['pending']);
        $dav = new NcDav($ctx['cloudUrl'], $res['loginName'], $res['appPassword']);
        try {
            $uid = $dav->principal();
        } catch (\Throwable $e) {
            error_log('[cloudDav] principal ' . $ctx['email'] . ': ' . $e->getMessage());
            $flow->revoke($res['loginName'], $res['appPassword']);
            return $this->pollOut('error', 'Nie udało się odczytać konta w chmurze. Spróbuj jeszcze raz albo napisz do Helpdesku IT.');
        }
        if (strcasecmp($uid, $ctx['cloudUid']) !== 0) {
            $flow->revoke($res['loginName'], $res['appPassword']);
            error_log('[cloudDav] inne konto w chmurze: ' . $ctx['email'] . ' (' . $ctx['cloudUid'] . ') potwierdził jako ' . $uid);
            return $this->pollOut('other', 'W chmurze jesteś zalogowany na inne konto (' . $uid . '), a Twoje to ' . $ctx['cloudUid']
                . '. Wyloguj się z chmury, zaloguj na swoje konto i połącz jeszcze raz.');
        }
        $cal = $ab = [];
        $note = '';
        try { $cal = $dav->calendars($uid); } catch (\Throwable $e) { $note = 'Listy kalendarzy nie udało się odczytać.'; error_log('[cloudDav] calendars: ' . $e->getMessage()); }
        try { $ab = $dav->addressbooks($uid); } catch (\Throwable $e) { $note .= ($note ? ' ' : '') . 'Listy książek adresowych nie udało się odczytać.'; error_log('[cloudDav] addressbooks: ' . $e->getMessage()); }
        $_SESSION[self::SESSION_KEY]['result'] = [
            'login' => $res['loginName'], 'pass' => $res['appPassword'], 'uid' => $uid, 'at' => time(),
            'davRoot' => $dav->davRoot(), 'principal' => $dav->principalUrl($uid),
            'calHome' => $dav->calendarHome($uid), 'abHome' => $dav->addressbookHome($uid),
            'cal' => $cal, 'ab' => $ab, 'note' => $note,
        ];
        if (function_exists('log_user_action')) {
            try { log_user_action($ctx['userId'], $ctx['userId'], 'cloud_dav_connect', 'Kalendarz i kontakty z chmury: ' . (self::purposes()[$st['purpose'] ?? '']['label'] ?? '')); } catch (\Throwable $e) {}
        }
        return $this->pollOut('done', '');
    }

    /** GET /kalendarz-kontakty/profil — profil .mobileconfig z danymi z sesji. */
    public function profile(): ?string
    {
        $ctx = $this->context();
        $r = $this->state()['result'] ?? null;
        if (!$r) { $this->flash('warning', 'Najpierw połącz urządzenie z chmurą.'); return Router::redirect($this->home()); }
        $p = parse_url($ctx['cloudUrl']);
        $ssl = strtolower((string)($p['scheme'] ?? 'https')) === 'https';
        $xml = AppleProfile::build(['host' => (string)$p['host'], 'port' => (int)($p['port'] ?? ($ssl ? 443 : 80)), 'ssl' => $ssl,
            'user' => (string)$r['login'], 'pass' => (string)$r['pass'], 'principal' => (string)$r['principal']], $ctx['org'], 'Chmura ' . $ctx['org']);
        header('Content-Type: application/x-apple-aspen-config');
        header('Content-Disposition: attachment; filename="chmura-kalendarz-kontakty.mobileconfig"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $xml;
        return null;
    }

    /** POST /kalendarz-kontakty/zakoncz — zapomnij hasło aplikacji i stan logowania (sesja). */
    public function forget(): ?string
    {
        $starts = $this->state()['starts'] ?? [];
        $_SESSION[self::SESSION_KEY] = ['starts' => $starts];
        if (($_POST['next'] ?? '') !== 'again') $this->flash('success', 'Gotowe. Hasła nie ma już na tej stronie. Urządzenie, w którym je wpisałeś, dalej działa.');
        return Router::redirect($this->home());
    }

    // ── pomocnicze ─────────────────────────────────────────────────────────────

    /** @return array{userId:int, email:string, name:string, cloudUid:string, cloudUrl:string, org:string} */
    private function context(): array
    {
        $me = current_user();
        $u = db_one("SELECT id, email, name, first_name FROM users WHERE id=?", [(int)($me['id'] ?? 0)]) ?: [];
        $email = strtolower(trim((string)($u['email'] ?? '')));
        $acc = $email !== '' ? SsoAccounts::get($email) : null;
        return [
            'userId'   => (int)($u['id'] ?? 0),
            'email'    => $email,
            'name'     => trim((string)($u['first_name'] ?? '')) ?: (explode(' ', trim((string)($u['name'] ?? '')))[0] ?? ''),
            'cloudUid' => (string)($acc['cloud_uid'] ?? ''),
            'cloudUrl' => SsoConfig::cloudConfigured() ? rtrim(SsoConfig::cloudUrl(), '/') : '',
            'org'      => (string)(org_setting('org_short_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO')),
        ];
    }

    /** Stan z sesji bez przeterminowanych części (logowanie po 20 minutach, hasło po RESULT_TTL). */
    private function state(): array
    {
        $st = (array)($_SESSION[self::SESSION_KEY] ?? []);
        if (!empty($st['pending']) && (int)$st['pending']['at'] < time() - NcLoginFlow::TTL) unset($st['pending']);
        if (!empty($st['result']) && (int)$st['result']['at'] < time() - self::RESULT_TTL) unset($st['result']);
        $_SESSION[self::SESSION_KEY] = $st;
        return $st;
    }

    private function pollOut(string $state, string $msg): mixed
    {
        if ($this->isXhr()) return ['state' => $state, 'message' => $msg];
        if ($msg !== '') $this->flash(in_array($state, ['other', 'error', 'expired'], true) ? 'danger' : 'info', $msg);
        elseif ($state === 'wait') $this->flash('info', 'Chmura jeszcze nie dostała potwierdzenia. Kliknij „Udziel dostępu” na stronie chmury i sprawdź ponownie.');
        return Router::redirect($this->home());
    }

    private function fail(string $msg): mixed
    {
        if ($this->isXhr()) return ['ok' => false, 'message' => $msg];
        $this->flash('danger', $msg);
        return Router::redirect($this->home());
    }

    private function flash(string $type, string $msg): void
    {
        if (function_exists('flash_set')) flash_set($type, $msg);
    }

    private function isXhr(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function home(): string
    {
        return rtrim(APP_URL, '/') . '/kalendarz-kontakty';
    }
}
