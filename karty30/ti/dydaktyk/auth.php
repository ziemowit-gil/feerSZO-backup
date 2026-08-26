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
        if ($profile) { dyd_login_user($profile); return $_SESSION[DYD_SESSION_KEY]; }
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
    $is_staff      = ($role === 'admin') || !empty($rp['karty30']['can_write']) || !empty($up['karty30']['can_write']);
    $is_consultant = $is_staff || !empty($u['k30_consultant'])
                     || !empty($rp['karty30']['can_read']) || !empty($up['karty30']['can_read']);
    if (!$is_consultant) return null; // konto bez uprawnień doradcy/dydaktyka TI
    return [
        'user_id'  => (int)$u['id'],
        'name'     => $u['name'] ?? ($u['email'] ?? ''),
        'email'    => $u['email'] ?? '',
        'role'     => $role,
        'is_staff' => $is_staff,
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

/** Wymaga zalogowanego dydaktyka; przy braku sesji → strona logowania. */
function dyd_require(): array {
    $s = dyd_current();
    if (!$s) { header('Location: login.php'); exit; }
    return $s;
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

/** Czy zalogowany dydaktyk jest pracownikiem D3 (widzi wszystkie kursy). */
function dyd_is_staff(): bool {
    $s = dyd_current();
    return $s ? !empty($s['is_staff']) : false;
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
 * Wybrany widok panelu: 'usos' albo 'classic' (domyślny).
 * ?ui=usos|classic przestawia i zapamiętuje.
 */
function dyd_ui(int $uid): string {
    $v = (string)($_GET['ui'] ?? '');
    if ($v === 'usos' || $v === 'classic') {
        dyd_pref_set($uid, 'dyd_ui', $v);
        return $v;
    }
    return dyd_pref($uid, 'dyd_ui', 'classic') === 'usos' ? 'usos' : 'classic';
}
