<?php
/**
 * Panel dydaktyka TI — autoryzacja.
 *
 * Osobna sesja (jak panel kursanta), więc działa też na subdomenie ti.* —
 * niezależnie od sesji głównej SZO. Logowanie odbywa się danymi z SZO
 * (e-mail + hasło). Dostęp mają doradcy TI (k30_consultant), pracownicy
 * modułu Karty 30 (zapis) oraz administratorzy.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
// auth.php SZO (ładuje też permissions.php) — udostępnia auth_start() używane przez
// flash_*() oraz role_permissions()/user_permissions(). Naszej sesji nie zmienia
// (auth_start jest no-op przy aktywnej sesji panelu). Musi być przed karty30.php.
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

const DYD_SESSION_KEY = 'k30_ti_dyd';
const DYD_SESSION_TTL = 3600 * 8; // 8h

function dyd_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('k30_dydaktyk');
        session_start();
    }
}

function dyd_current(): ?array {
    dyd_start();
    $s = $_SESSION[DYD_SESSION_KEY] ?? null;
    if (!$s) return null;
    if ((time() - ($s['ts'] ?? 0)) > DYD_SESSION_TTL) { unset($_SESSION[DYD_SESSION_KEY]); return null; }
    return $s;
}

/**
 * Weryfikuje dane logowania SZO (e-mail + hasło) i uprawnienia dydaktyka.
 * Zwraca dane do zapisania w sesji lub null (błędne dane / brak uprawnień).
 */
function dyd_authenticate(string $email, string $password): ?array {
    $u = db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
    if (!$u || empty($u['password']) || !password_verify($password, $u['password'])) return null;
    $role = $u['role'] ?? '';
    $rp = role_permissions($role);
    $up = user_permissions((int)$u['id']);
    $is_staff      = ($role === 'admin') || !empty($rp['karty30']['can_write']) || !empty($up['karty30']['can_write']);
    $is_consultant = $is_staff || !empty($u['k30_consultant'])
                     || !empty($rp['karty30']['can_read']) || !empty($up['karty30']['can_read']);
    if (!$is_consultant) return null; // konto bez uprawnień doradcy/dydaktyka TI
    return [
        'user_id'  => (int)$u['id'],
        'name'     => $u['name'] ?? $email,
        'email'    => $u['email'] ?? $email,
        'role'     => $role,
        'is_staff' => $is_staff,
    ];
}

function dyd_login_user(array $data): void {
    dyd_start();
    $data['ts'] = time();
    $_SESSION[DYD_SESSION_KEY] = $data;
    $_SESSION['k30_dyd_csrf']  = bin2hex(random_bytes(16));
}

function dyd_logout(): void {
    dyd_start();
    unset($_SESSION[DYD_SESSION_KEY]);
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

/** Czy zalogowany dydaktyk jest pracownikiem K30 (widzi wszystkie kursy). */
function dyd_is_staff(): bool {
    $s = dyd_current();
    return $s ? !empty($s['is_staff']) : false;
}

/** Czy dydaktyk może zarządzać kursem (własny kurs lub pracownik K30). */
function dyd_owns_course(int $uid, int $course_id): bool {
    if (!$course_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_course($uid, $course_id);
}

/** Czy dydaktyk może zarządzać lekcją (jej kurs jest jego — lub pracownik K30). */
function dyd_owns_session(int $uid, int $session_id): bool {
    if (!$session_id) return false;
    return dyd_is_staff() || k30_ti_instructor_owns_session($uid, $session_id);
}

/** Kursy, którymi dydaktyk może zarządzać (własne; pracownik K30 — wszystkie). */
function dyd_courses(int $uid): array {
    return dyd_is_staff() ? k30_ti_courses(false) : k30_ti_instructor_courses($uid, false);
}
