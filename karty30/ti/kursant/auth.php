<?php
/**
 * Autoryzacja kursantów TI — osobny system sesji, bez dostępu do reszty aplikacji.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_remember.php';

const STUDENT_SESSION_KEY = 'k30_ti_student';
const STUDENT_SESSION_TTL = 3600 * 2; // 120 min bezczynności — dłużej trzyma cichy token „zapamiętaj mnie" (patrz niżej)

function student_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('k30_student');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function student_current(): ?array {
    student_start();
    $s = $_SESSION[STUDENT_SESSION_KEY] ?? null;
    if ($s) {
        if ((time() - ($s['ts'] ?? 0)) <= STUDENT_SESSION_TTL) {
            $_SESSION[STUDENT_SESSION_KEY]['ts'] = time(); // sesja aktywna — odśwież licznik bezczynności
            return $_SESSION[STUDENT_SESSION_KEY];
        }
        unset($_SESSION[STUDENT_SESSION_KEY]);
    }
    // Sesja wygasła lub jej brak — cicha próba wznowienia z trwałego tokenu (bez logowania,
    // bez opcji w UI). Pomijamy konta zablokowane/nieaktywne, tak jak przy zwykłym logowaniu.
    $rem = ti_remember_consume('student');
    if ($rem) {
        $account = db_one(
            "SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1",
            [$rem['account_id']]
        );
        if ($account && empty($account['child_access_blocked'])) {
            student_login_user($account, 'remember');
            return $_SESSION[STUDENT_SESSION_KEY];
        }
        ti_remember_forget('student');
    }
    return null;
}

/**
 * Uruchamia sesję kursanta i zapisuje IP wejścia do panelu — zarówno przy zwykłym
 * logowaniu hasłem ($method='password'), jak i przy cichym wznowieniu sesji tokenem
 * „zapamiętaj mnie" ($method='remember'), żeby administrator widział skąd kursant
 * faktycznie korzysta z panelu, nie tylko skąd wpisał hasło.
 */
function student_login_user(array $account, string $method = 'password', string $detail = ''): void {
    student_start();
    $_SESSION[STUDENT_SESSION_KEY] = [
        'id'        => (int)$account['id'],
        'client_id' => (int)$account['client_id'],
        'login'     => $account['login'],
        'ts'        => time(),
    ];
    ti_remember_issue('student', (int)$account['id']);

    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    try {
        db()->prepare("UPDATE k30_ti_student_accounts SET last_login=datetime('now'), last_login_ip=? WHERE id=?")
            ->execute([$ip, (int)$account['id']]);
    } catch (\Throwable $e) {
        try { db()->prepare("UPDATE k30_ti_student_accounts SET last_login=datetime('now') WHERE id=?")->execute([(int)$account['id']]); }
        catch (\Throwable $e2) {}
    }

    if (!function_exists('ti_account_log')) @require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
    if (function_exists('ti_account_log')) {
        $msg = $method === 'remember' ? 'Wznowiono sesję (zapamiętaj mnie)' : 'Zalogowano';
        if ($detail !== '') $msg .= ' — ' . $detail;
        ti_account_log((int)$account['id'], $method === 'remember' ? 'login_resume' : 'login', $msg . " | IP: {$ip}");
    }
}

function student_logout(): void {
    student_start();
    unset($_SESSION[STUDENT_SESSION_KEY]);
    ti_remember_forget('student');
    session_destroy();
}

/**
 * Loguje administratora w sesji kursanta jako podgląd „zaloguj jako" (impersonacja).
 * Sesja kursanta ma osobne ciasteczko (k30_student), więc sesja admina pozostaje
 * nienaruszona. Zapisuje znacznik `imp` z danymi administratora.
 *
 * UWAGA: wywoływać po session_write_close() sesji głównej aplikacji — w przeciwnym
 * razie PHP nie pozwoli otworzyć drugiej sesji w tym samym żądaniu.
 */
function student_impersonate(array $account, int $adminId, string $adminName): void {
    student_start();
    $_SESSION[STUDENT_SESSION_KEY] = [
        'id'        => (int)$account['id'],
        'client_id' => (int)$account['client_id'],
        'login'     => $account['login'],
        'ts'        => time(),
        'imp'       => ['by' => $adminId, 'name' => $adminName],
    ];
    // świeży token CSRF dla sesji podglądu
    $_SESSION['k30_student_csrf'] = bin2hex(random_bytes(16));
}

/** Zwraca dane administratora podszywającego się pod kursanta lub null (zwykłe logowanie). */
function student_impersonator(): ?array {
    $s = student_current();
    return (is_array($s) && !empty($s['imp'])) ? $s['imp'] : null;
}

function student_require(): array {
    $s = student_current();
    if (!$s) {
        header('Location: login.php');
        exit;
    }
    return $s;
}

/** Token CSRF dla panelu kursanta (osobna sesja k30_student). */
function student_token(): string {
    student_start();
    if (empty($_SESSION['k30_student_csrf'])) {
        $_SESSION['k30_student_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['k30_student_csrf'];
}

/** Weryfikacja tokenu CSRF kursanta — kończy żądanie 403 przy niezgodności. */
function student_token_check(): void {
    student_start();
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['k30_student_csrf'] ?? '', (string)$sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Nieprawidłowy token sesji.']);
        exit;
    }
}

// ── Widok panelu kursanta: klasyczny / alternatywny ──────────────────────────
// Widok alternatywny (gęsty, tabelaryczny) jest do wyboru: domyślnie panel
// wygląda jak dotąd, kursant włącza go sam i wybór zostaje przy koncie (nie
// w przeglądarce — ma przeżyć wylogowanie i zmianę urządzenia). Panel kursanta
// ma osobną sesję i konto bez wpisu w `users`, więc preferencja nie mieści się
// w user_prefs (tam siedzi wybór panelu dydaktyka) — idzie kolumną na koncie.

/** Widok panelu kursanta: 'alt' albo 'klasyczny' (domyślnie klasyczny). */
function student_ui(int $accountId): string {
    if ($accountId <= 0) return 'klasyczny';
    try {
        $r = db_one("SELECT ui_pref FROM k30_ti_student_accounts WHERE id=?", [$accountId]);
    } catch (\Throwable $e) { return 'klasyczny'; }
    return (($r['ui_pref'] ?? '') === 'alt') ? 'alt' : 'klasyczny';
}

/** Zapis wyboru widoku panelu kursanta. */
function student_ui_set(int $accountId, string $ui): void {
    if ($accountId <= 0) return;
    $ui = ($ui === 'alt') ? 'alt' : 'klasyczny';
    try {
        db_exec("UPDATE k30_ti_student_accounts SET ui_pref=? WHERE id=?", [$ui, $accountId]);
    } catch (\Throwable $e) { error_log('[student_ui_set] ' . $e->getMessage()); }
}

// ── Dostęp rodzica (osobna sesja, dla małoletnich kursantów) ──────────────────
const PARENT_SESSION_KEY = 'k30_ti_parent';
const PARENT_SESSION_TTL = 3600 * 2; // 120 min bezczynności — cichy token „zapamiętaj mnie" niżej

function parent_current(): ?array {
    student_start();
    $p = $_SESSION[PARENT_SESSION_KEY] ?? null;
    if ($p) {
        if ((time() - ($p['ts'] ?? 0)) <= PARENT_SESSION_TTL) {
            $_SESSION[PARENT_SESSION_KEY]['ts'] = time();
            return $_SESSION[PARENT_SESSION_KEY];
        }
        unset($_SESSION[PARENT_SESSION_KEY]);
    }
    // Cicha próba wznowienia z trwałego tokenu (bez logowania, bez opcji w UI).
    $rem = ti_remember_consume('parent');
    if ($rem && parent_login_for_student($rem['account_id'], $rem['payload'])) {
        return $_SESSION[PARENT_SESSION_KEY];
    }
    return null;
}

/** Zaloguj rodzica do widoku konkretnego kursanta (po OTP lub linku magicznym). */
function parent_login_for_student(int $studentId, array $extra = []): bool {
    $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1", [$studentId]);
    if (!$acc) return false;
    $client = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
    student_start();
    $_SESSION[PARENT_SESSION_KEY] = array_merge([
        'student_id' => (int)$acc['id'],
        'client_id'  => (int)$acc['client_id'],
        'name'       => $client['name'] ?? $acc['login'],
        'ts'         => time(),
    ], $extra);
    ti_remember_issue('parent', (int)$acc['id'], $extra);
    return true;
}

/**
 * Logowanie rodzica loginem i hasłem (konto rodzica: login pierwsza-litera-imienia.nazwisko-r).
 * Zwraca true po zalogowaniu. Ustawia w sesji znacznik konta hasłowego oraz wymóg zmiany hasła.
 */
function parent_login_with_password(string $login, string $pass): bool {
    $login = strtolower(trim($login));
    if ($login === '' || $pass === '') return false;
    $acc = db_one(
        "SELECT * FROM k30_ti_student_accounts WHERE parent_login=? AND parent_login!='' AND is_active=1",
        [$login]
    );
    if (!$acc || empty($acc['parent_password_hash'])) return false;
    if (!password_verify($pass, $acc['parent_password_hash'])) return false;
    db()->prepare("UPDATE k30_ti_student_accounts SET parent_last_login=datetime('now') WHERE id=?")
       ->execute([(int)$acc['id']]);
    return parent_login_for_student((int)$acc['id'], [
        'auth'         => 'password',
        'must_change'  => !empty($acc['parent_must_change']) ? 1 : 0,
    ]);
}

function parent_logout(): void {
    student_start();
    unset($_SESSION[PARENT_SESSION_KEY]);
    ti_remember_forget('parent');
}

/** Generuje OTP dla rodzica (sesja, ważny 5 min) i wysyła SMS-em. Zwraca liczbę dopasowanych dzieci. */
function parent_otp_send(string $phone): int {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
    $norm = sms_normalize_phone($phone);
    if ($norm === '') return 0;
    // Małoletni kursanci z tym numerem opiekuna
    $kids = db_all(
        "SELECT a.id, a.guardian_phone, cl.name FROM k30_ti_student_accounts a
         JOIN k30_clients cl ON cl.id=a.client_id
         WHERE a.is_active=1 AND a.is_minor=1 AND a.guardian_phone!=''",
        []
    );
    $matched = array_values(array_filter($kids, fn($k) => sms_normalize_phone($k['guardian_phone']) === $norm));
    if (!$matched) return 0;

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    student_start();
    // Zbierz e-mail opiekuna z pierwszego dopasowanego konta kursanta
    $guardian_email = '';
    foreach ($matched as $kid) {
        $ge = db_one("SELECT guardian_email FROM k30_ti_student_accounts WHERE id=?", [(int)$kid['id']]);
        if (!empty($ge['guardian_email'])) { $guardian_email = $ge['guardian_email']; break; }
    }
    $_SESSION['k30_parent_otp'] = [
        'hash'         => password_hash($code, PASSWORD_BCRYPT),
        'phone'        => $norm,
        'kids'         => array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name']], $matched),
        'expires'      => time() + 300,
        'tries'        => 0,
        'fallback_via' => 'sms',
    ];
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $via = sms_send_with_fallback($norm, "{$org}: kod dostepu rodzica do rozliczen: {$code}", $guardian_email);
    $_SESSION['k30_parent_otp']['fallback_via'] = $via;
    return count($matched);
}

/** Weryfikuje OTP rodzica; zwraca listę dzieci [id=>name] lub null. */
function parent_otp_verify(string $code): ?array {
    student_start();
    $o = $_SESSION['k30_parent_otp'] ?? null;
    if (!$o || time() > $o['expires'] || ($o['tries'] ?? 0) >= 5) { unset($_SESSION['k30_parent_otp']); return null; }
    $_SESSION['k30_parent_otp']['tries']++;
    if (!password_verify(trim($code), $o['hash'])) return null;
    unset($_SESSION['k30_parent_otp']);
    return $o['kids'];
}

/** Generuje (lub odświeża) token linku magicznego dla rodzica danego kursanta. Zwraca token. */
function parent_make_token(int $studentId, int $days = 30): string {
    $token = bin2hex(random_bytes(20));
    db()->prepare("DELETE FROM k30_ti_parent_tokens WHERE student_id=?")->execute([$studentId]);
    db_insert('k30_ti_parent_tokens', [
        'student_id' => $studentId,
        'token'      => $token,
        'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return $token;
}

/** Weryfikuje token linku magicznego; zwraca student_id lub null. */
function parent_token_student(string $token): ?int {
    if ($token === '') return null;
    $row = db_one(
        "SELECT student_id FROM k30_ti_parent_tokens
         WHERE token=? AND (expires_at IS NULL OR expires_at > datetime('now'))",
        [$token]
    );
    return $row ? (int)$row['student_id'] : null;
}

// ── Osoby upoważnione przez pełnoletniego kursanta ────────────────────────────
const AUTHP_SESSION_KEY = 'k30_ti_authp';
const AUTHP_SESSION_TTL = 3600 * 8; // 8h

function authp_current(): ?array {
    student_start();
    $p = $_SESSION[AUTHP_SESSION_KEY] ?? null;
    if (!$p) return null;
    if ((time() - ($p['ts'] ?? 0)) > AUTHP_SESSION_TTL) {
        unset($_SESSION[AUTHP_SESSION_KEY]);
        return null;
    }
    return $p;
}

function authp_login(string $login, string $pass): bool {
    $login = strtolower(trim($login));
    if ($login === '' || $pass === '') return false;
    $row = db_one(
        "SELECT p.*, a.client_id, a.is_minor FROM k30_ti_authorized_persons p
         JOIN k30_ti_student_accounts a ON a.id=p.student_account_id
         WHERE p.login=? AND p.is_active=1",
        [$login]
    );
    if (!$row || empty($row['password_hash'])) return false;
    if (!password_verify($pass, $row['password_hash'])) return false;
    // Tylko dla pełnoletnich kursantów
    if (!empty($row['is_minor'])) return false;
    db()->prepare("UPDATE k30_ti_authorized_persons SET last_login=datetime('now') WHERE id=?")
       ->execute([(int)$row['id']]);
    student_start();
    $_SESSION[AUTHP_SESSION_KEY] = [
        'authp_id'   => (int)$row['id'],
        'student_id' => (int)$row['student_account_id'],
        'client_id'  => (int)$row['client_id'],
        'name'       => $row['name'],
        'ts'         => time(),
    ];
    return true;
}

function authp_logout(): void {
    student_start();
    unset($_SESSION[AUTHP_SESSION_KEY]);
}
