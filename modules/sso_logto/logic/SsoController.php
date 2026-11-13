<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/SsoController.php — logowanie jednokrotne (Logto) i chmura plików w SZO.
 *
 * Administrator (role:admin + IKA): GET /ssoadmin (ustawienia, adresy webhooka i SMS dla Logto, testy połączeń),
 *   POST /ssoadmin/ustawienia, /ssoadmin/test, /ssoadmin/test-chmury,
 *   POST /ssoadmin/osoba — konto Logto / nowe hasło / konto w chmurze dla osoby z umowy (karta w tozsamosc/access.php),
 *   POST /ssoadmin/pliki — anuluj / ponów kopiowanie plików z OneDrive,
 *   POST /migracjaadmin/pliki — migrator: konta Logto + chmura + kopiowanie plików dla zaznaczonych kont Microsoft 365.
 * Użytkownik (zalogowany): POST /sso/konto (sam zakłada konto), POST /sso/haslo (nowe hasło) — sekcja #sso w Tożsamości;
 *   GET /sso/start — prośba po zalogowaniu (lib/ssoPrompt.php): ten sam formularz co w Tożsamości, z from=start wraca tutaj.
 * Logowanie do SZO przez Logto (bez zalogowania): GET /sso/logowanie (start, OpenID Connect) i GET /sso/powrot (Redirect URI)
 *   — lib/LogtoOidc.php; te same bramki co logowanie hasłem (aktywność, panel TI, Microsoft-only administratorów, IKA w CRM, 2FA, WebAuthn).
 * Wygenerowane hasła admin widzi tylko raz (sesja), nigdy w adresie.
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once __DIR__ . '/SsoAccounts.php';
require_once __DIR__ . '/CloudFilesCopy.php';
require_once dirname(__DIR__) . '/lib/ssoPrompt.php';
require_once dirname(__DIR__) . '/lib/LogtoOidc.php';

final class SsoController
{
    private const VIEW = __DIR__ . '/../view/';
    public const K_RESULT = 'ssoResult';
    private const K_FLASH = 'ssoFlash';
    private const K_TEST = 'ssoTest';
    private const CONTRACT_TYPES = ['wolontariat', 'zlecenie', 'dzielo'];

    // ── Administrator: ustawienia ─────────────────────────────────────────────────────────

    public function index(): string
    {
        ika_require();
        return Router::view(self::VIEW . 'admin.php', [
            'flash' => $this->take(self::K_FLASH),
            'test'  => $this->take(self::K_TEST),
            'deliveries' => SsoAccounts::recentDeliveries(10),
            'jobs'  => CloudFilesCopy::jobs(20),
            'cfg'   => [
                'logto_url'   => SsoConfig::get('sso_logto_url', SsoConfig::DEFAULT_LOGTO_URL),
                'm2m_id'      => SsoConfig::get('sso_logto_m2m_id'),
                'has_secret'  => SsoConfig::has('sso_logto_m2m_secret'),
                'has_webhook' => SsoConfig::has('sso_logto_webhook_key'),
                'self'        => SsoConfig::selfEnabled(),
                'prompt'      => SsoConfig::get('sso_login_prompt', '1') !== '0',
                'szo_login'   => SsoConfig::get('sso_szo_login') === '1',
                'szo_app_id'  => SsoConfig::get('sso_szo_app_id'),
                'has_szo_secret' => SsoConfig::has('sso_szo_app_secret'),
                'szo_ready'   => LogtoOidc::configured(),
                'ms_off_date' => SsoConfig::get('sso_ms_off_date'),
                'sms_prefix'  => SsoConfig::get('sso_sms_prefix'),
                'cloud_kind'  => SsoConfig::cloudKind(),
                'cloud_url'   => SsoConfig::get('sso_cloud_url'),
                'cloud_user'  => SsoConfig::get('sso_cloud_user'),
                'has_cloud_pass' => SsoConfig::has('sso_cloud_pass'),
                'cloud_group' => SsoConfig::cloudGroup(),
                'cloud_quota' => SsoConfig::cloudQuota(),
                'cloud_self'  => SsoConfig::cloudSelf(),
                'graph'       => GraphDrive::configured(),
            ],
        ]);
    }

    public function saveSettings(): ?string
    {
        $back = route('sso.index');
        if (!ika_ok($back)) return $this->flash($back, 'warning', 'Sesja kodu IKA wygasła — ustawień NIE zapisano. Potwierdź kod IKA i powtórz.');
        try {
            SsoConfig::save($_POST);
        } catch (\Throwable $e) {
            return $this->flash($back . '#ustawienia', 'danger', $e->getMessage());
        }
        $this->audit('sso.settings', ['kto' => (string)(current_user()['email'] ?? '')]);
        return $this->flash($back . '#ustawienia', 'success', 'Zapisano ustawienia logowania jednokrotnego i chmury.');
    }

    public function testLogto(): ?string
    {
        $out = ['what' => 'Logto', 'ok' => true, 'lines' => []];
        try {
            $n = SsoConfig::logto()->ping();
            $out['lines'][] = 'Połączenie z ' . SsoConfig::logtoUrl() . ' działa' . ($n >= 0 ? ', kont użytkowników: ' . $n : '') . '.';
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['lines'][] = $e->getMessage();
        }
        $_SESSION[self::K_TEST] = $out;
        return Router::redirect(route('sso.index') . '#ustawienia');
    }

    public function testCloud(): ?string
    {
        $out = ['what' => 'Chmura', 'ok' => true, 'lines' => []];
        try {
            $ver = SsoConfig::cloud()->ping();
            $out['lines'][] = 'Połączenie z ' . SsoConfig::cloudUrl() . ' działa: ' . $ver . ', konto administratora przyjęte.';
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['lines'][] = $e->getMessage();
        }
        $_SESSION[self::K_TEST] = $out;
        return Router::redirect(route('sso.index') . '#chmura');
    }

    // ── Administrator: osoba z umowy (karta w Zarządzaniu dostępem) ───────────────────────

    public function personAction(): ?string
    {
        $type = (string)($_POST['type'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if (!in_array($type, self::CONTRACT_TYPES, true) || $id <= 0) return Router::redirect(APP_URL . '/tozsamosc/index.php');
        $back = APP_URL . '/tozsamosc/access.php?type=' . rawurlencode($type) . '&id=' . $id;
        $row = db_one("SELECT * FROM umowy_{$type} WHERE id=?", [$id]);
        if (!$row) return $this->flashGlobal($back, 'danger', 'Nie znaleziono umowy.');
        $person = self::personFromContract($row);
        $by = (string)(current_user()['email'] ?? '');
        $do = (string)($_POST['do'] ?? '');
        try {
            $res = match ($do) {
                'create'   => SsoAccounts::provision($person, ['cloud' => !empty($_POST['cloud']), 'files' => !empty($_POST['files']), 'by' => $by, 'via' => 'admin']),
                'password' => SsoAccounts::setPassword($person['email'], null, $by),
                'cloud'    => SsoAccounts::provision($person, ['cloud' => true, 'files' => !empty($_POST['files']), 'by' => $by, 'via' => 'admin']),
                default    => throw new InvalidArgumentException('Nieznana czynność.'),
            };
        } catch (\Throwable $e) {
            return $this->flashGlobal($back . '#sso', 'danger', 'Logowanie jednokrotne: ' . $e->getMessage());
        }
        $res['name'] = $person['name'];
        $res['sms'] = !empty($_POST['sms']) && $res['password'] ? $this->smsCredentials($person['phone'], $res) : '';
        $_SESSION[self::K_RESULT] = ['ctx' => $type . ':' . $id, 'rows' => [$res]];
        if (function_exists('log_contract_action')) {
            try { log_contract_action($type, $id, (int)(current_user()['id'] ?? 0), 'note', 'Logowanie jednokrotne (' . $do . '): ' . $res['username'] . ($res['cloud'] ? ', chmura: ' . $res['cloud'] : '')); } catch (\Throwable $e) {}
        }
        return Router::redirect($back . '#sso');
    }

    /** Osoba z wiersza umowy: e-mail z SZO, login organizacji (Microsoft 365) jako podstawa nazwy użytkownika. */
    public static function personFromContract(array $row): array
    {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $user = $email !== '' ? db_one("SELECT id, name, phone_number FROM users WHERE LOWER(email)=?", [$email]) : null;
        $hasM365 = !empty($row['m365_konto']) && trim((string)($row['m365_user_id'] ?? '')) !== '';
        return [
            'email'   => $email,
            'name'    => trim((string)($row['imie_nazwisko'] ?? ($user['name'] ?? ''))),
            'login'   => trim((string)($row['m365_login'] ?? '')),
            'm365'    => $hasM365 ? trim((string)($row['m365_login'] ?? '') ?: (string)$row['m365_user_id']) : '',
            'user_id' => $user ? (int)$user['id'] : null,
            'phone'   => trim((string)($user['phone_number'] ?? '') ?: (string)($row['telefon'] ?? '')),
        ];
    }

    public function filesAction(): ?string
    {
        $back = (string)($_POST['back'] ?? '');
        if (!str_starts_with($back, '/')) $back = '/migracjaadmin';
        $back = rtrim(APP_URL, '/') . $back . '#pliki';
        $id = (int)($_POST['job'] ?? 0);
        if (($_POST['do'] ?? '') === 'cancel') {
            CloudFilesCopy::cancel($id);
            $this->audit('sso.files_cancel', ['job' => $id]);
        } else {
            CloudFilesCopy::runSoon($id);
        }
        return Router::redirect($back);
    }

    // ── Migrator: zaznaczone konta Microsoft 365 → Logto + chmura + pliki ───────────────────

    public function migratorFiles(): ?string
    {
        $back = route('dhm.index');
        if (!ika_ok($back)) {
            $_SESSION['dhmFlash'] = ['type' => 'warning', 'msg' => 'Sesja kodu IKA wygasła — kont w chmurze NIE założono. Potwierdź kod IKA i powtórz.'];
            return Router::redirect($back);
        }
        $ids = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['ids'] ?? [])))));
        if (!$ids) {
            $_SESSION['dhmFlash'] = ['type' => 'warning', 'msg' => 'Zaznacz co najmniej jedno konto.'];
            return Router::redirect($back);
        }
        if (count($ids) > 25) {
            $_SESSION['dhmFlash'] = ['type' => 'warning', 'msg' => 'Naraz najwyżej 25 kont do chmury — zaznacz mniej i powtórz.'];
            return Router::redirect($back);
        }
        @set_time_limit(300);
        $by = (string)(current_user()['email'] ?? '');
        $rows = [];
        foreach ($ids as $mid) {
            $person = self::personByM365Id($mid);
            if (!$person || $person['email'] === '') {
                $rows[] = ['email' => '', 'name' => $mid, 'username' => '', 'password' => null, 'logto' => '', 'cloud' => 'błąd: brak osoby z umową i e-mailem w SZO dla tego konta Microsoft', 'job' => null, 'sms' => ''];
                continue;
            }
            try {
                $res = SsoAccounts::provision($person, ['cloud' => true, 'files' => !empty($_POST['files_copy']), 'by' => $by, 'via' => 'migrator']);
            } catch (\Throwable $e) {
                $rows[] = ['email' => $person['email'], 'name' => $person['name'], 'username' => '', 'password' => null, 'logto' => '', 'cloud' => 'błąd: ' . $e->getMessage(), 'job' => null, 'sms' => ''];
                continue;
            }
            $res['name'] = $person['name'];
            $res['sms'] = !empty($_POST['files_sms']) && $res['password'] ? $this->smsCredentials($person['phone'], $res) : '';
            $rows[] = $res;
        }
        $this->audit('sso.migrator_cloud', ['konta' => count($rows), 'pliki' => !empty($_POST['files_copy']),
            'items' => array_map(fn($r) => ['email' => $r['email'], 'logto' => $r['logto'], 'cloud' => $r['cloud'], 'job' => $r['job']], $rows)]);
        $_SESSION[self::K_RESULT] = ['ctx' => 'migrator', 'rows' => $rows];
        return Router::redirect($back . '#pliki');
    }

    /** Osoba po identyfikatorze konta Microsoft 365: najnowsza umowa z tym kontem, inaczej konto SZO z microsoft_id. */
    public static function personByM365Id(string $mid): ?array
    {
        foreach (self::CONTRACT_TYPES as $t) {
            try {
                $row = db_one("SELECT * FROM umowy_{$t} WHERE m365_user_id=? AND email<>'' ORDER BY id DESC LIMIT 1", [$mid]);
            } catch (\Throwable $e) { $row = null; }
            if ($row) return self::personFromContract($row);
        }
        $u = db_one("SELECT id, email, name, phone_number, m365_login FROM users WHERE microsoft_id=?", [$mid]);
        if (!$u) return null;
        return ['email' => strtolower((string)$u['email']), 'name' => (string)$u['name'], 'login' => (string)($u['m365_login'] ?? ''),
                'm365' => (string)($u['m365_login'] ?? '') ?: $mid, 'user_id' => (int)$u['id'], 'phone' => (string)($u['phone_number'] ?? '')];
    }

    // ── Logowanie do SZO przez Logto (OpenID Connect) ────────────────────────────────────────

    /** GET /sso/logowanie?redirect= — przekierowanie na stronę logowania Logto. */
    public function loginStart(): ?string
    {
        $raw = (string)($_GET['redirect'] ?? '');
        $redirect = ($raw !== '' && str_starts_with($raw, APP_URL . '/')) ? $raw : '';
        if (current_user()) return Router::redirect($redirect !== '' ? $redirect : auth_default_landing());
        if (!LogtoOidc::enabled()) return $this->loginFail('Logowanie jednokrotne nie jest jeszcze włączone. Zaloguj się e-mailem i hasłem.');
        try {
            return Router::redirect(LogtoOidc::authUrl($redirect));
        } catch (\Throwable $e) {
            error_log('[sso] logowanie Logto — start: ' . $e->getMessage());
            return $this->loginFail('Serwer logowania jednokrotnego nie odpowiada. Spróbuj za chwilę albo zaloguj się e-mailem i hasłem.');
        }
    }

    /** GET /sso/powrot — Redirect URI aplikacji SZO w Logto: konto SZO po identyfikatorze Logto albo potwierdzonym e-mailu. */
    public function loginCallback(): ?string
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/approval.php';        // log_auth_action
        require_once $root . '/includes/auth_security.php';   // authlog_write
        require_once $root . '/modules/user_merge/lib/userMergeResolve.php';
        if (!LogtoOidc::enabled()) return $this->loginFail('Logowanie jednokrotne nie jest włączone.');
        try {
            $c = LogtoOidc::callback($_GET);
        } catch (\Throwable $e) {
            error_log('[sso] logowanie Logto — powrót: ' . $e->getMessage());
            if (($_GET['error'] ?? '') === 'access_denied') return $this->loginFail('Logowanie zostało przerwane. Spróbuj ponownie.');
            return $this->loginFail('Nie udało się zalogować przez logowanie jednokrotne. Spróbuj ponownie albo zaloguj się e-mailem i hasłem.');
        }

        // Konto SZO: najpierw powiązanie z Logto (sso_accounts.logto_id), potem potwierdzony e-mail z Logto (i dowiązanie).
        $acc = SsoAccounts::byLogtoId($c['sub']);
        $email = $acc ? (string)$acc['email'] : '';
        if ($email === '' && $c['email'] !== '' && $c['email_verified']) {
            if (!db_one("SELECT id FROM users WHERE LOWER(email)=?", [$c['email']])) {   // nie zakładamy powiązań dla obcych adresów
                authlog_write(null, 'login_fail', $c['email'], 'Logto: brak konta w SZO');
                return $this->loginFail('Nie ma konta w SZO z adresem ' . $c['email'] . '. Napisz do Helpdesku IT.');
            }
            $email = SsoAccounts::linkFromLogto(['id' => $c['sub'], 'primaryEmail' => $c['email'], 'username' => $c['username'], 'name' => $c['name']]);
        }
        if ($email === '') {
            authlog_write(null, 'login_fail', $c['email'], 'Logto: konto bez powiązania z SZO (' . $c['sub'] . ')');
            return $this->loginFail('Twoje konto logowania jednokrotnego nie jest połączone z kontem w SZO' . ($c['email'] !== '' ? ' (' . $c['email'] . ')' : '') . '. Zaloguj się e-mailem i hasłem albo napisz do Helpdesku IT.');
        }
        $user = userMergeFollow(db_one("SELECT * FROM users WHERE LOWER(email)=?", [$email]) ?: null);
        if (!$user) {
            authlog_write(null, 'login_fail', $email, 'Logto: brak konta w SZO');
            return $this->loginFail('Nie ma konta w SZO z adresem ' . $email . '. Napisz do Helpdesku IT.');
        }
        $uid = (int)$user['id'];
        if (empty($user['is_active'])) return $this->loginFail('Konto zostało dezaktywowane. Skontaktuj się z administratorem.');
        if (account_is_ti_panel_only($user)) {
            authlog_write($uid, 'login_blocked_ti_panel', (string)$user['email'], 'Logto: konto panelu dydaktyka TI');
            return $this->loginFail('To konto działa wyłącznie w panelu dydaktyka TI — zaloguj się pod adresem karty30/ti/dydaktyk/.');
        }
        if (account_is_office_only($user)) {
            authlog_write($uid, 'login_blocked_office', (string)$user['email'], 'Logto: konto administratora — wymagane logowanie przez Microsoft 365');
            return $this->loginFail('Konto administratora @feer.org.pl loguje się wyłącznie przez Microsoft 365 (Office).');
        }
        $crmOnly = ($user['role'] ?? '') === 'crm_user';
        if (!$crmOnly) { try { $crmOnly = !empty(db_one("SELECT crm_only FROM roles WHERE name=?", [(string)$user['role']])['crm_only']); } catch (\Throwable $e) {} }
        if ($crmOnly && empty($user['cpc_code'])) {
            return $this->loginFail('Twoje konto wymaga aktywacji kodu IKA przed pierwszym logowaniem. Skontaktuj się z administratorem.');
        }

        $target = auth_login_landing($user, $c['redirect']);
        $endUrl = LogtoOidc::endSessionUrl($c['id_token']);
        try { SsoAccounts::touchSignIn($c['sub']); } catch (\Throwable $e) {}

        // Logowanie dwuetapowe SZO (TOTP/SMS) — jak przy haśle; administratorów tu nie ma (tylko Microsoft 365).
        if (!empty($user['twofa_method'])) {
            $_SESSION['2fa_uid']      = $uid;
            $_SESSION['2fa_method']   = $user['twofa_method'];
            $_SESSION['2fa_phone']    = $user['twofa_phone'] ?? '';
            $_SESSION['2fa_attempts'] = 0;
            if ($endUrl !== '') $_SESSION['logto_logout'] = $endUrl;
            if ($user['twofa_method'] === 'sms' && !empty($user['twofa_phone'])) {
                try {
                    require_once $root . '/includes/sms.php';
                    $otp = sms_generate_otp($user['twofa_phone'], $uid);
                    sms_send_with_fallback($user['twofa_phone'], "Kod 2FA: {$otp} (ważny 5 min)", $user['email'] ?? '', 'twofa_code');
                    $_SESSION['2fa_sms_sent'] = true;
                } catch (\Throwable $e) {}
            }
            return Router::redirect(APP_URL . '/auth/2fa.php?redirect=' . urlencode($target));
        }

        log_auth_action($uid, 'login_logto', 'Logowanie jednokrotne (Logto): ' . $user['email']);
        authlog_write($uid, 'login', (string)$user['email'], 'Logowanie jednokrotne (Logto)');
        if ($endUrl !== '') $_SESSION['logto_logout'] = $endUrl;   // auth/logout.php kończy też sesję w Logto
        require_once $root . '/includes/webauthn.php';
        if (webauthn_login_gate($user, $target)) exit;
        login_user($user);
        return Router::redirect($target);
    }

    /** Błąd logowania przez Logto — komunikat na zwykłym ekranie logowania (auth/login.php czyta login_notice_error). */
    private function loginFail(string $msg): ?string
    {
        $_SESSION['login_notice_error'] = $msg;
        return Router::redirect(APP_URL . '/auth/login.php');
    }

    // ── Użytkownik (Tożsamość → Logowanie jednokrotne) ───────────────────────────────────────

    /**
     * GET /sso/start — prośba po zalogowaniu: załóż konto logowania jednokrotnego i w chmurze (Nextcloud).
     * Gdy prośba jest wyłączona albo nie da się założyć konta samemu — od razu dalej ($next). Po założeniu konta
     * ta sama strona pokazuje „Gotowe” (login, link do chmury) i przycisk „Przejdź dalej”.
     */
    public function prompt(): ?string
    {
        $_SESSION[SSO_PROMPT_K_CHECKED] = 1;
        $next = ssoPromptSafeNext((string)($_GET['next'] ?? ''));
        $me   = current_user();
        $db   = db_one("SELECT id, email, name, first_name, m365_login FROM users WHERE id=?", [(int)$me['id']]) ?: [];
        $flash = function_exists('flash_get') ? flash_get() : null;
        require_once dirname(__DIR__, 3) . '/includes/permissions.php';   // panel_volunteer_shell — strona startowa Panelu albo SZO
        $home = function_exists('panel_volunteer_shell') && panel_volunteer_shell($me) ? APP_URL . '/panel/index.php' : APP_URL . '/index.php';
        $cont = $next !== '' ? $next : $home;
        if (!SsoConfig::selfEnabled() || !SsoConfig::logtoConfigured()) {
            if ($flash) flash_set($flash['type'], $flash['msg']);
            return Router::redirect($cont);
        }
        $email = strtolower((string)($db['email'] ?? ''));
        return Router::view(self::VIEW . 'loginPrompt.php', [
            'me'    => $db,
            'email' => $email,
            'need'  => ssoPromptNeed($email),
            'acc'   => $email !== '' ? SsoAccounts::get($email) : null,
            'cloud' => ssoPromptWantsCloud(),
            'next'  => $next,
            'cont'  => $cont,
            'flash' => $flash,
        ]);
    }

    /** Dokąd wrócić po formularzu: ekran po zalogowaniu (/sso/start), strona migracji poczty albo Tożsamość. */
    private function selfBack(): string
    {
        $from = (string)($_POST['from'] ?? '');
        if ($from === 'start') {
            $pnext = ssoPromptSafeNext((string)($_POST['next'] ?? ''));
            return rtrim(APP_URL, '/') . SSO_PROMPT_PATH . ($pnext !== '' ? '?next=' . rawurlencode($pnext) : '');
        }
        if ($from === 'migracja') return rtrim(APP_URL, '/') . '/migracjapoczty#hSso';
        return APP_URL . '/tozsamosc/index.php#sso';
    }

    public function selfCreate(): ?string
    {
        // z ekranu prośby po zalogowaniu (/sso/start) wracamy tam — ze ścieżką dalszą; ze strony migracji poczty — na nią; w innym razie do Tożsamości
        $fromPrompt = ($_POST['from'] ?? '') === 'start';
        $back = $this->selfBack();
        if (!SsoConfig::selfEnabled() || !SsoConfig::logtoConfigured()) return $this->flashGlobal($back, 'warning', 'Konta logowania jednokrotnego zakłada teraz tylko administrator. Napisz do Helpdesku IT.');
        $me = current_user();
        $db = db_one("SELECT id, email, name, m365_login FROM users WHERE id=?", [(int)$me['id']]);
        $pass = $this->newPasswordFromPost($back);
        if ($pass === null) return Router::redirect($back);
        try {
            $res = SsoAccounts::provision(
                ['email' => (string)$db['email'], 'name' => (string)$db['name'], 'login' => (string)($db['m365_login'] ?? ''), 'user_id' => (int)$db['id']],
                ['password' => $pass, 'cloud' => SsoConfig::cloudSelf() && SsoConfig::cloudConfigured(), 'files' => false, 'by' => (string)$db['email'], 'via' => 'self']
            );
        } catch (\Throwable $e) {
            if ($fromPrompt) {   // ekran po zalogowaniu: bez technicznych szczegółów — te idą do dziennika
                error_log('[sso] prośba po zalogowaniu, ' . (string)$db['email'] . ': ' . $e->getMessage());
                return $this->flashGlobal($back, 'danger', 'Nie udało się teraz założyć konta. Spróbuj za kilka minut, a jeśli dalej nie działa, napisz do Helpdesku IT.');
            }
            return $this->flashGlobal($back, 'danger', $e->getMessage());
        }
        if (function_exists('log_user_action')) { try { log_user_action((int)$db['id'], (int)$db['id'], 'sso_create', 'Konto logowania jednokrotnego: ' . $res['username']); } catch (\Throwable $e) {} }
        $msg = $res['logto'] === 'created'
            ? 'Gotowe, masz konto logowania jednokrotnego. Login: ' . $res['username'] . ' albo Twój adres e-mail.'
            : 'Konto logowania jednokrotnego już miałeś(-aś). Połączyliśmy je z SZO i ustawiliśmy nowe hasło.';
        if (in_array($res['cloud'], ['created', 'linked', 'synced'], true)) $msg .= ' W chmurze plików działa to samo hasło.';
        elseif (str_starts_with($res['cloud'], 'błąd')) $msg .= ' Konta w chmurze nie udało się przygotować. Administrator już o tym wie.';
        return $this->flashGlobal($back, 'success', $msg);
    }

    public function selfPassword(): ?string
    {
        $back = $this->selfBack();
        $me = current_user();
        $db = db_one("SELECT id, email FROM users WHERE id=?", [(int)$me['id']]);
        $pass = $this->newPasswordFromPost($back);
        if ($pass === null) return Router::redirect($back);
        try {
            $res = SsoAccounts::setPassword((string)$db['email'], $pass, (string)$db['email']);
        } catch (\Throwable $e) {
            return $this->flashGlobal($back, 'danger', $e->getMessage());
        }
        if (function_exists('log_user_action')) { try { log_user_action((int)$db['id'], (int)$db['id'], 'sso_password', 'Nowe hasło logowania jednokrotnego'); } catch (\Throwable $e) {} }
        $msg = 'Zmieniliśmy hasło logowania jednokrotnego.';
        if (in_array($res['cloud'], ['linked', 'synced'], true)) $msg .= ' W chmurze plików też działa już nowe hasło.';
        elseif (str_starts_with($res['cloud'], 'błąd')) $msg .= ' W chmurze plików zostało stare hasło, bo zmiana się nie udała (' . mb_substr($res['cloud'], 6) . ').';
        return $this->flashGlobal($back, 'success', $msg);
    }

    /** Hasło z formularza: dwa razy to samo, min. 8 znaków. null = błąd (komunikat już ustawiony). */
    private function newPasswordFromPost(string $back): ?string
    {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if ($p1 === '' || $p1 !== $p2) { flash_set('danger', 'Hasła się różnią. Wpisz to samo hasło w obu polach.'); return null; }
        if (mb_strlen($p1) < 10) { flash_set('danger', 'Hasło musi mieć co najmniej 10 znaków.'); return null; }
        if (mb_strlen($p1) > 128) { flash_set('danger', 'Hasło może mieć najwyżej 128 znaków.'); return null; }
        return $p1;
    }

    // ── Pomocnicze ─────────────────────────────────────────────────────────────────────────

    private function smsCredentials(string $phone, array $res): string
    {
        if ($phone === '') return 'brak numeru';
        try {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            if (!sms_channel_ready()) return 'brak dostawcy SMS';
            $cloudOnly = ($res['logto'] ?? '') === 'pominięte';   // Logto nieskonfigurowane — samo konto w chmurze
            $msg = sms_ascii(SsoConfig::smsPrefix() . ($cloudOnly ? ': chmura plikow' : ': logowanie jednokrotne') . '. Login: ' . $res['username'] . ' Haslo: ' . $res['password']
                . ' Haslo obowiazuje od ' . date('d.m.y H:i:s') . '. Strona: ' . preg_replace('~^https://~', '', $cloudOnly ? SsoConfig::cloudUrl() : SsoConfig::logtoUrl()));
            sms_send($phone, $msg, 'access_data');
            return 'wysłano';
        } catch (\Throwable $e) {
            return 'błąd SMS';
        }
    }

    private function audit(string $action, array $data): void
    {
        try {
            if (!function_exists('audit_log')) require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($action, $data);
        } catch (\Throwable $e) { error_log('[sso] audit: ' . $e->getMessage()); }
    }

    private function take(string $k): mixed
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }

    private function flash(string $url, string $type, string $msg): ?string
    {
        $_SESSION[self::K_FLASH] = ['type' => $type, 'msg' => $msg];
        return Router::redirect($url);
    }

    /** Komunikat w zwykłym mechanizmie SZO (flash_set) — dla stron Tożsamości. */
    private function flashGlobal(string $url, string $type, string $msg): ?string
    {
        flash_set($type, $msg);
        return Router::redirect($url);
    }

    /** Wynik z hasłami do pokazania raz (karta osoby: ctx „typ:id”, migrator: „migrator”). */
    public static function takeResult(string $ctx): ?array
    {
        $r = $_SESSION[self::K_RESULT] ?? null;
        if (!is_array($r) || ($r['ctx'] ?? '') !== $ctx) return null;
        unset($_SESSION[self::K_RESULT]);
        return $r['rows'];
    }
}
