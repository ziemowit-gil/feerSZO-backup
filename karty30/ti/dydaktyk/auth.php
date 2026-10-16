<?php
/**
 * Panel dydaktyka TI — autoryzacja.
 *
 * Osobna sesja (jak panel kursanta), więc działa też na subdomenie ti.* —
 * niezależnie od sesji głównej SZO. Logowanie odbywa się danymi z SZO
 * (e-mail + hasło). Dostęp mają doradcy TI (k30_consultant), pracownicy
 * modułu Dydaktyka 3 (zapis) oraz administratorzy.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
// auth.php SZO (ładuje też permissions.php) — udostępnia auth_start() używane przez
// flash_*() oraz role_permissions()/user_permissions(). Naszej sesji nie zmienia
// (auth_start jest no-op przy aktywnej sesji panelu). Musi być przed karty30.php.
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/owncloud.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_remember.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_blackout.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/dyd_totp_exempt/logic/dydTotpExempt.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/analytics/logic/analyticsManager.php';   // dostęp do Analityki TI (dyd_can_analytics)
require_once __DIR__ . '/_nav.php';

/**
 * Plan cykliczny (zakładka „cykliczne”, Planer IT: _tab_cykliczne.php +
 * planner_ajax.php) — WYŁĄCZONY od 2026-09-29 decyzją kierownictwa.
 * Dane (k30_ti_weekly_plan) zostają; ponowne włączenie: org_setting
 * ti_plan_cykliczny = '1'. Wyłączenie chowa nawigację i blokuje zakładkę,
 * operację weekly_autoassign oraz endpoint AJAX.
 */
function dyd_plan_cykliczny_enabled(): bool {
    return function_exists('org_setting') && org_setting('ti_plan_cykliczny') === '1';
}   // dyd_topbar_enrich() — sekcje w pasku + menu użytkownika
// Equi Exams — moduł testów wiedzy i umiejętności (schemat samonaprawia się przy dołączeniu)
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';

const DYD_SESSION_KEY = 'k30_ti_dyd';
const DYD_SESSION_TTL = 3600 * 2; // 120 min bezczynności — dłużej trzyma cichy token „zapamiętaj mnie" (patrz niżej)

function dyd_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Ten sam magazyn sesji co reszta aplikacji (DB), aby sesja założona
        // w jednym żądaniu (np. mostek Office office_enter.php) była widoczna
        // w kolejnym (index.php). Bez tego domyślny handler plikowy i handler DB
        // zarejestrowany przez auth_start() mogłyby się rozjechać.
        static $handler_set = false;
        if (!$handler_set) {
            $handler_set = true;
            try {
                require_once dirname(dirname(dirname(__DIR__))) . '/includes/session_db_handler.php';
                session_set_save_handler(new DbSessionHandler(db()), true);
            } catch (\Throwable $e) { /* fallback: domyślny handler PHP */ }
        }
        session_name('k30_dydaktyk');
        // Sesja w bazie żyje tyle, co panel (DYD_SESSION_TTL), a nie domyślne 24 min (gc_maxlifetime): inaczej długie
        // wypełnianie dokumentacji lekcji kończyło się nową sesją (cichy token) — bez wyboru trybu i z nowym CSRF, zapis przepadał.
        if ((int)ini_get('session.gc_maxlifetime') < DYD_SESSION_TTL) ini_set('session.gc_maxlifetime', (string)DYD_SESSION_TTL);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function dyd_current(): ?array {
    dyd_start();
    $s = $_SESSION[DYD_SESSION_KEY] ?? null;
    if ($s) {
        if ((time() - ($s['ts'] ?? 0)) <= DYD_SESSION_TTL) {
            // Stara sesja bez is_staff (przed dodaniem pola) — odśwież uprawnienia z DB
            if (!array_key_exists('is_staff', $s)) {
                $u = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$s['user_id'] ?? 0]);
                $profile = $u ? dyd_profile_from_user($u) : null;
                if ($profile) { dyd_login_user($profile); return $_SESSION[DYD_SESSION_KEY]; }
                unset($_SESSION[DYD_SESSION_KEY]);
                return null;
            }
            $_SESSION[DYD_SESSION_KEY]['ts'] = time();
            return $_SESSION[DYD_SESSION_KEY];
        }
        unset($_SESSION[DYD_SESSION_KEY]);
    }
    // Cicha próba wznowienia z trwałego tokenu (bez logowania, bez opcji w UI).
    $rem = ti_remember_consume('dyd');
    if ($rem) {
        $u = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$rem['account_id']]);
        $profile = $u ? dyd_profile_from_user($u) : null;
        if ($profile) { dyd_login_user($profile); $_SESSION['k30_dyd_resumed'] = 1; return $_SESSION[DYD_SESSION_KEY]; }
        ti_remember_forget('dyd');
    }
    return null;
}

/**
 * Buduje profil sesji dydaktyka z wiersza users i sprawdza uprawnienia.
 * Zwraca dane do zapisania w sesji lub null (konto nieaktywne / brak uprawnień).
 * Wspólne dla logowania hasłem (dyd_authenticate) i Office (office_enter.php).
 */
function dyd_profile_from_user(array $u): ?array {
    if (empty($u['is_active'])) return null;
    $role = $u['role'] ?? '';
    $rp = role_permissions($role);
    $up = user_permissions((int)$u['id']);
    // Osobny system uprawnień panelu (k30_ti_panel_roles): kierownik/zastepca
    // = pełne funkcje kierownika, prowadzacy = wejście jak doradca TI —
    // niezależnie od roli i uprawnień modułowych SZO.
    $panel_role    = function_exists('ti_panel_role') ? ti_panel_role((int)$u['id']) : '';
    $is_staff      = ($role === 'admin') || !empty($rp['karty30']['can_write']) || !empty($up['karty30']['can_write'])
                     || in_array($panel_role, ['kierownik', 'zastepca'], true);
    $is_consultant = $is_staff || !empty($u['k30_consultant']) || $panel_role !== ''
                     || !empty($rp['karty30']['can_read']) || !empty($up['karty30']['can_read']);
    // Analityka TI (modules/analytics): zarząd i osoby z uprawnieniem „analityka” — tylko ten ekran, bez własnych grup.
    if (!$is_consultant && AnalyticsManager::canUser($u)) $is_consultant = true;
    if (!$is_consultant) return null; // konto bez uprawnień doradcy/dydaktyka TI
    return [
        'user_id'    => (int)$u['id'],
        'name'       => $u['name'] ?? ($u['email'] ?? ''),
        'email'      => $u['email'] ?? '',
        'role'       => $role,
        'panel_role' => $panel_role,
        'is_staff'   => $is_staff,
    ];
}

/**
 * Weryfikuje dane logowania SZO (e-mail + hasło) i uprawnienia dydaktyka.
 * Zwraca dane do zapisania w sesji lub null (błędne dane / brak uprawnień).
 */
function dyd_authenticate(string $email, string $password): ?array {
    $u = db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
    if (!$u || empty($u['password']) || !password_verify($password, $u['password'])) return null;
    return dyd_profile_from_user($u);
}

function dyd_login_user(array $data): void {
    dyd_start();
    $data['ts'] = time();
    $_SESSION[DYD_SESSION_KEY] = $data;
    $_SESSION['k30_dyd_csrf']  = bin2hex(random_bytes(16));
    ti_remember_issue('dyd', (int)$data['user_id']);
}

function dyd_logout(): void {
    dyd_start();
    unset($_SESSION[DYD_SESSION_KEY]);
    ti_remember_forget('dyd');
    session_destroy();
}

const DYD_2FA_KEY = 'k30_dyd_2fa_pending';
const DYD_2FA_TTL = 600; // 10 min na dokończenie kroku TOTP po haśle/SSO

/**
 * Zapisuje profil (z dyd_authenticate()/dyd_profile_from_user()) jako
 * „czeka na 2FA" — TOTP jest obowiązkowe dla każdego konta dydaktyka, więc
 * ani logowanie hasłem, ani Office SSO nie wołają już dyd_login_user()
 * wprost; robi to dopiero totp_gate.php po zweryfikowaniu kodu.
 */
function dyd_2fa_stash(array $data, string $next = ''): void {
    dyd_start();
    $_SESSION[DYD_2FA_KEY] = ['profile' => $data, 'ts' => time(), 'next' => dyd_safe_next($next)];
}

/**
 * Bezpieczny cel skoku po wejściu do panelu (?next= mostka office_enter.php): nazwa ISTNIEJĄCEGO pliku .php
 * z tego katalogu, ewentualnie z prostym zapytaniem (key=…) — bez ścieżek, adresów zewnętrznych, znaków %, :, /
 * i sterujących. Cokolwiek innego → index.php.
 */
function dyd_safe_next(string $next): string {
    [$file, $qs] = array_pad(explode('?', $next, 2), 2, '');
    $ok = preg_match('/^[A-Za-z][A-Za-z0-9]*\.php$/', $file) && is_file(__DIR__ . '/' . $file)
       && ($qs === '' || preg_match('/^[A-Za-z0-9_=&.\-]{1,120}$/', $qs));
    return $ok ? $next : 'index.php';
}

/** Cel zapamiętany przy odkładaniu na 2FA (po TOTP wracamy tam, gdzie użytkownik chciał wejść). */
function dyd_2fa_next(): string {
    dyd_start();
    return dyd_safe_next((string)($_SESSION[DYD_2FA_KEY]['next'] ?? ''));
}

/** Profil czekający na dokończenie 2FA, albo null (brak albo upłynął czas). */
function dyd_2fa_pending(): ?array {
    dyd_start();
    $p = $_SESSION[DYD_2FA_KEY] ?? null;
    if (!$p || (time() - ($p['ts'] ?? 0)) > DYD_2FA_TTL) { unset($_SESSION[DYD_2FA_KEY]); return null; }
    return $p['profile'];
}

function dyd_2fa_clear_pending(): void {
    dyd_start();
    unset($_SESSION[DYD_2FA_KEY], $_SESSION['k30_dyd_2fa_setup_secret'], $_SESSION['k30_dyd_2fa_backup_show']);
}

/**
 * Czy konto musi przy wejściu do panelu potwierdzić się kluczem sprzętowym (YubiKey, WebAuthn) — yk_gate.php.
 * Obecnie nikt: administrator, kierownik, zastępca i prowadzący wchodzą po TOTP. Bramka zostaje na wypadek
 * ponownego włączenia wymogu.
 */
function dyd_yk_required(array $p): bool {
    return false;
}

/**
 * Koniec kroku TOTP (albo krok pominięty u administratora): konto wymagające klucza idzie jeszcze na yk_gate.php,
 * pozostałe dostają pełną sesję panelu i wracają tam, gdzie chciały wejść.
 */
function dyd_2fa_finish(array $profile): void {
    dyd_start();
    if (dyd_yk_required($profile) && empty($profile['yk_ok'])) {
        if (empty($_SESSION[DYD_2FA_KEY])) dyd_2fa_stash($profile);
        $_SESSION[DYD_2FA_KEY]['totp_ok'] = 1;
        $_SESSION[DYD_2FA_KEY]['ts']      = time();
        unset($_SESSION['k30_dyd_2fa_setup_secret'], $_SESSION['k30_dyd_2fa_backup_show']);
        header('Location: yk_gate.php'); exit;
    }
    $go = dyd_2fa_next();
    dyd_login_user($profile);
    dyd_2fa_clear_pending();
    header('Location: ' . $go); exit;
}

/**
 * Wymaga zalogowanego dydaktyka; przy braku sesji → strona logowania.
 *
 * Kierownik, który jest TEŻ prowadzącym (ma własne kursy jako instructor_id
 * lub coProwadzący), wybiera raz na sesję rolę — patrz choose_context.php,
 * dyd_is_staff() (honoruje wybór) i dyd_ctx_role(). Zwykły kierownik bez
 * własnych kursów i zwykły prowadzący (bez uprawnień kierownika) nigdy nie
 * widzą tego ekranu — nie mają czego wybierać.
 */
function dyd_require(): array {
    $s = dyd_current();
    if (!$s) { header('Location: login.php'); exit; }

    // TOTP jest obowiązkowe dla każdego konta dydaktyka (patrz totp_gate.php).
    // Sesja mogła powstać przed wprowadzeniem tej bramki albo wznowić się cicho
    // przez „zapamiętaj mnie" (dyd_current()) — sprawdzamy więc stan konta w
    // bazie przy każdym żądaniu, nie tylko przy świeżym logowaniu. Zwolnieni:
    // impersonacja (imp.php, $s['imp']) — administrator już się uwierzytelnił.
    // Administrator SZO też przechodzi TOTP (klucz sprzętowy nie jest już wymagany).
    // Zwolnione z CLI (php cli/2fa.php dyd-exempt <email>) — bez TOTP.
    // Wejście po uzasadnieniu (totp_gate.php, kierownik/zastępca): ta sesja bez kodu — kolejne logowanie znów z kodem.
    if (empty($s['imp']) && empty($s['totp_bypass']) && !dydTotpIsExempt((int)$s['user_id'])) {
        $totp = db_one("SELECT totp_confirmed, totp_secret FROM users WHERE id=?", [(int)$s['user_id']]);
        if (!$totp || empty($totp['totp_confirmed']) || empty($totp['totp_secret'])) {
            dyd_2fa_stash($s);
            unset($_SESSION[DYD_SESSION_KEY]);
            // Bez tego dyd_current() w totp_gate.php cicho wznawia TĘ SAMĄ sesję
            // z trwałego tokenu „zapamiętaj mnie" (ti_remember_consume) i od razu
            // odsyła z powrotem do index.php — a stamtąd znów tutaj: pętla przekierowań.
            ti_remember_forget('dyd');
            header('Location: totp_gate.php'); exit;
        }
    }

    // Klucz sprzętowy obowiązkowy dla administratora (yk_gate.php). Sesja bez potwierdzenia
    // kluczem (sprzed tej bramki, wznowiona z „zapamiętaj mnie”, odświeżona w dyd_current()) wraca na bramkę —
    // TOTP sprawdzono wyżej, więc od razu krok klucza. Cel po kluczu: bieżący ekran (tylko GET).
    if (dyd_yk_required($s) && empty($s['yk_ok'])) {
        $_next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            ? basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')
            : '';
        dyd_2fa_stash($s, $_next);
        $_SESSION[DYD_2FA_KEY]['totp_ok'] = 1;
        unset($_SESSION[DYD_SESSION_KEY]);
        ti_remember_forget('dyd'); // jak wyżej: bez tego cichy token wznawia sesję i powstaje pętla
        header('Location: yk_gate.php'); exit;
    }

    // Wspólny ekran wyboru (choose_mode.php): rola Kierownik / Prowadzący (kierownik z własnymi grupami)
    // i tryb pracy Szkolenia / Konsultacje (gdy dostępne oba — TiWorkModes). Raz na sesję; gdy wyboru nie ma, ustawiane same.
    $_need = false;
    // Wznowiona sesja (cichy token) albo zapis formularza: przywróć ostatni wybór (choose_mode.php zapisuje go w user_prefs),
    // zamiast odsyłać na ekran wyboru — przekierowanie gubiło wysyłany formularz (np. dokumentację lekcji).
    if (!empty($_SESSION['k30_dyd_resumed']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $_pr = dyd_pref((int)$s['user_id'], 'dyd_ctx_role');
        if (!isset($_SESSION['k30_dyd_ctx']['role']) && !empty($s['is_staff']) && in_array($_pr, ['staff', 'instructor'], true)) $_SESSION['k30_dyd_ctx']['role'] = $_pr;
        $_pm = dyd_pref((int)$s['user_id'], 'dyd_ctx_mode');
        if (!isset($_SESSION['k30_dyd_ctx']['mode']) && in_array($_pm, dyd_modes_available($s), true)) $_SESSION['k30_dyd_ctx']['mode'] = $_pm;
    }
    if (!empty($s['is_staff']) && !isset($_SESSION['k30_dyd_ctx']['role'])) {
        if (k30_ti_instructor_courses((int)$s['user_id'], false)) $_need = true;
        else $_SESSION['k30_dyd_ctx']['role'] = 'staff'; // nic do wyboru — ustal raz i nie pytaj więcej
    }
    $_modes = dyd_modes_available($s);
    if (!isset($_SESSION['k30_dyd_ctx']['mode']) || !in_array($_SESSION['k30_dyd_ctx']['mode'], $_modes ?: ['kursy'], true)) {
        if (count($_modes) > 1) $_need = true;
        else $_SESSION['k30_dyd_ctx']['mode'] = $_modes[0] ?? 'kursy';
    }
    if ($_need) { header('Location: choose_mode.php'); exit; }
    // Osoba tylko z Konsultacjami: ekrany Szkoleń są niedostępne (wiadomości i komunikaty — tak)
    if ($_modes === ['konsultacje'] && !dyd_konsultacje_page()) { header('Location: rezerwacje.php'); exit; }

    // Pełne wcielenie w wybranego prowadzącego (patrz choose_context.php, opcja
    // „Wejdź jako inny prowadzący"). Od tego miejsca $s['user_id']/name/email to
    // dane WSKAZANEGO prowadzącego — dalsze dyd_owns_course()/dyd_owns_session()
    // i wszystkie zapisy (created_by, instructor_id...) w dalszym kodzie działają
    // tak, jakby to on był zalogowany. Prawdziwa tożsamość (TOTP wyżej już jej
    // użyło) zostaje pod real_user_id — do banera „w zastępstwie" w UI.
    $s['real_user_id'] = (int)$s['user_id'];
    $as_id = (int)($_SESSION['k30_dyd_ctx']['as_instructor_id'] ?? 0);
    if ($as_id && dyd_ctx_role() === 'instructor') {
        $target = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$as_id]);
        if ($target) {
            $s['user_id']         = (int)$target['id'];
            $s['name']            = (string)$target['name'];
            $s['email']           = (string)$target['email'];
            $s['acting_as_other'] = true;
        } else {
            // Prowadzący zniknął/dezaktywowany w międzyczasie — wyjdź z wcielenia.
            unset($_SESSION['k30_dyd_ctx']['as_instructor_id']);
            header('Location: choose_context.php'); exit;
        }
    }
    return $s;
}

/**
 * Loguje przełączenie kierownika na widok/konto innego prowadzącego (pełne
 * wcielenie — patrz choose_context.php). Wymaga uzasadnienia po stronie
 * wywołującego; tu tylko zapis do audytu.
 */
function dyd_instructor_switch_log(int $kierownik_user_id, int $instructor_user_id, string $reason): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_switch_log (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            kierownik_user_id   INTEGER NOT NULL,
            instructor_user_id  INTEGER NOT NULL,
            reason              TEXT NOT NULL,
            created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->prepare(
            "INSERT INTO k30_ti_instructor_switch_log (kierownik_user_id, instructor_user_id, reason) VALUES (?,?,?)"
        )->execute([$kierownik_user_id, $instructor_user_id, $reason]);
    } catch (\Throwable $e) {}
}

/** Rola robocza kierownika-prowadzącego w tej sesji: 'staff'|'instructor'|'' (zwykły użytkownik, brak wyboru). */
function dyd_ctx_role(): string {
    return (string)($_SESSION['k30_dyd_ctx']['role'] ?? '');
}

/**
 * Tryby pracy dostępne dla zalogowanego: 'kursy' (kierownik, prowadzący z kursami, rola panelu) i 'konsultacje'
 * (kierownik albo doradca TI — users.k30_consultant). Pusta lista = konto tylko do Analityki → traktujemy jak 'kursy'.
 */
function dyd_modes_available(?array $s = null): array {
    $s ??= dyd_current();
    if (!$s) return [];
    require_once dirname(__DIR__, 3) . '/modules/ti_work_modes/logic/workModes.php';
    // Uprawnienia per osoba (Zespół i role → Tryby pracy); bez ustawienia — automatycznie, kierownik zawsze oba
    return TiWorkModes::available((int)($s['user_id'] ?? 0), !empty($s['is_staff']), (string)($s['panel_role'] ?? ''));
}

/** Strony dostępne w samym trybie Konsultacje (osoba bez uprawnienia do Szkoleń). */
const DYD_KONSULTACJE_PAGES = ['konsultacje.php', 'konsultacja.php', 'konsultacjaTermin.php', 'konsultacjaPdf.php',
                               'rezerwacje.php', 'rezerwacja.php', 'rezerwacjeKalendarz.php', 'rezerwacjaPdf.php', 'kolejka.php',
                               'konsultacjeZestawienia.php', 'konsultacjeEksport.php', 'konsultacjeUstawienia.php', 'konsultacjeKomunikacja.php',
                               'konsultacjeKlienci.php', 'konsultacjeKlient.php', 'konsultacjePfron.php', 'konsultacjePfronDokumenty.php',
                               'licencjeKursantow.php', 'regulaminy.php',
                               'moj_certyfikat.php', 'logout.php', 'choose_mode.php', 'office_enter.php', 'totp_gate.php'];

function dyd_konsultacje_page(): bool {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, DYD_KONSULTACJE_PAGES, true)) return true;
    if ($script === 'index.php') return in_array((string)($_GET['tab'] ?? ''), ['wiadomosci', 'komunikaty'], true);
    return (bool)preg_match('/^(api|notif|ajax|poll|heartbeat)/i', $script);   // tła panelu (dzwonek, odświeżanie)
}

/** Bieżący tryb pracy: 'kursy' (Szkolenia, domyślnie) albo 'konsultacje'. */
function dyd_mode(): string {
    return (($_SESSION['k30_dyd_ctx']['mode'] ?? '') === 'konsultacje') ? 'konsultacje' : 'kursy';
}

/** Token CSRF panelu dydaktyka (osobna sesja k30_dydaktyk). */
function dyd_token(): string {
    dyd_start();
    if (empty($_SESSION['k30_dyd_csrf'])) $_SESSION['k30_dyd_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['k30_dyd_csrf'];
}

/** Weryfikacja tokenu CSRF — kończy żądanie 403 przy niezgodności. */
function dyd_token_check(): void {
    dyd_start();
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['k30_dyd_csrf'] ?? '', (string)$sent)) {
        http_response_code(403);
        exit('Nieprawidłowy token sesji.');
    }
}

/**
 * Czy zalogowany dydaktyk jest pracownikiem D3 (widzi wszystkie kursy).
 * Kierownik, który świadomie wybrał pracę jako Prowadzący (choose_context.php),
 * dostaje tu `false` przez resztę sesji — traci widok kierownika, dopóki nie
 * przełączy roli z powrotem (bez ponownego logowania).
 */
function dyd_is_staff(): bool {
    $s = dyd_current();
    if (!$s || empty($s['is_staff'])) return false;
    return dyd_ctx_role() !== 'instructor';
}

/**
 * Czy zalogowany ma dostęp do Analityki TI (modules/analytics): administrator, zarząd albo uprawnienie „analityka”.
 * Kierownik pracujący świadomie jako Prowadzący (też „w zastępstwie”) traci ten widok, jak inne ekrany zarządcze.
 */
function dyd_can_analytics(?array $s = null): bool {
    $s ??= dyd_current();
    if (!$s || dyd_ctx_role() === 'instructor') return false;
    return AnalyticsManager::canAccess(['id' => (int)($s['user_id'] ?? 0), 'role' => (string)($s['role'] ?? '')]);
}

/** Administrator systemu (users.role=admin) — jedyny, kto może ręcznie zmienić status lekcji w edycji. */
function dyd_is_admin(): bool {
    $s = dyd_current();
    return $s && ($s['role'] ?? '') === 'admin' && dyd_ctx_role() !== 'instructor';
}

/** Czy dydaktyk może zarządzać kursem (własny kurs lub pracownik D3). */
function dyd_owns_course(int $uid, int $course_id): bool {
    if (!$course_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_course($uid, $course_id);
}

/** Czy dydaktyk może zarządzać lekcją (jej kurs jest jego — lub pracownik D3). */
function dyd_owns_session(int $uid, int $session_id): bool {
    if (!$session_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_session($uid, $session_id);
}

/** Kursy, którymi dydaktyk może zarządzać (własne; pracownik D3 — wszystkie). */
function dyd_courses(int $uid): array {
    return dyd_is_staff() ? k30_ti_courses(false) : k30_ti_instructor_courses($uid, false);
}

/**
 * Własne grupy prowadzącego (także współprowadzone) dla paska i przełącznika —
 * raz na żądanie, między żądaniami cache Redis 2 min w grupie 'ti'.
 */
function dyd_own_courses_cached(int $uid): array {
    static $memo = [];
    if (isset($memo[$uid])) return $memo[$uid];
    $r = function_exists('szo_cache_remember')
        ? szo_cache_remember(szo_cache_gkey('ti', 'dyd_own_courses:u' . $uid), 120, fn() => k30_ti_instructor_courses($uid, false))
        : k30_ti_instructor_courses($uid, false);
    return $memo[$uid] = is_array($r) ? $r : [];
}

/**
 * Czy panel dydaktyka jest włączony (domyślnie tak).
 * Dwa niezależne mechanizmy: ręczny przełącznik oraz zaplanowane okno
 * wyłączenia ([[includes/ti_blackout.php]]), które działa samo po datach.
 */
function dyd_panel_is_enabled(): bool {
    $v = org_setting('dyd_panel_enabled');
    if ($v !== '' && $v !== '1') return false;              // wyłączony ręcznie
    return ti_blackout_active('dydaktyk') === null;          // albo trwa okno przerwy
}

/** Komunikat wyświetlany gdy panel wyłączony (okno przerwy ma pierwszeństwo). */
function dyd_panel_message(): string {
    $win = ti_blackout_active('dydaktyk');
    if ($win) return ti_blackout_message($win);
    $m = org_setting('dyd_panel_message');
    return $m !== '' ? $m : 'Panel dydaktyka jest tymczasowo niedostępny. Zapraszamy ponownie wkrótce.';
}

/** Planowany czas wznowienia — dla okna przerwy to jego koniec. */
function dyd_panel_resume(): string {
    $win = ti_blackout_active('dydaktyk');
    if ($win) return (string)$win['ends_at'];
    return org_setting('dyd_panel_resume');
}

/** Aktywne okno wyłączenia dziennika ocen (albo null) — bramka zakładki „Oceny". */
function dyd_dziennik_blackout(): ?array {
    return ti_blackout_active('dziennik');
}

// ── Widok panelu: klasyczny / USOS ───────────────────────────────────────────
// Wybór jest per użytkownik i przeżywa wylogowanie, dlatego trzyma się w
// user_prefs (ta sama tabela co inne preferencje), a nie w sesji panelu.
// current_user() w panelu dydaktyka bywa puste (osobna sesja), więc user_id
// przekazujemy zawsze wprost.

/** Preferencja panelu dydaktyka (user_prefs; tabela zakładana leniwie). */
function dyd_pref(int $uid, string $key, string $default = ''): string {
    if ($uid <= 0) return $default;
    try {
        $r = db_one("SELECT value FROM user_prefs WHERE user_id=? AND pref_key=?", [$uid, $key]);
        return $r ? (string)$r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

/** Zapis preferencji panelu dydaktyka. */
function dyd_pref_set(int $uid, string $key, string $value): void {
    if ($uid <= 0) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_prefs (
            user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            pref_key TEXT    NOT NULL,
            value    TEXT    NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, pref_key)
        )");
        db()->prepare("INSERT INTO user_prefs (user_id, pref_key, value) VALUES (?,?,?)
                       ON CONFLICT(user_id, pref_key) DO UPDATE SET value=excluded.value")
            ->execute([$uid, $key, $value]);
    } catch (\Throwable $e) { error_log('[dyd_pref_set] ' . $e->getMessage()); }
}

/**
 * Widok panelu. Wybór szablonu został WYCOFANY — panel ma jeden wygląd (USOS),
 * żeby prowadzący i kierownicy oglądali te same ekrany i żeby zgłoszenia
 * dotyczyły jednego układu.
 *
 * Funkcja zostaje jako jedno miejsce decyzji: gdyby wybór miał wrócić, wystarczy
 * przywrócić tu odczyt ?ui= i preferencji dyd_ui (dyd_pref/dyd_pref_set nadal
 * działają), a w index.php dołożyć przełącznik. Zapisane wcześniej preferencje
 * są ignorowane, nie kasujemy ich.
 */
function dyd_ui(int $uid): string {
    return 'usos';
}
