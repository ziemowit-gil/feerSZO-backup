<?php
/**
 * Autoryzacja kursantów TI — osobny system sesji, bez dostępu do reszty aplikacji.
 */

const STUDENT_SESSION_KEY = 'k30_ti_student';
const STUDENT_SESSION_TTL = 3600 * 8; // 8h

function student_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('k30_student');
        session_start();
    }
}

function student_current(): ?array {
    student_start();
    $s = $_SESSION[STUDENT_SESSION_KEY] ?? null;
    if (!$s) return null;
    if ((time() - ($s['ts'] ?? 0)) > STUDENT_SESSION_TTL) {
        unset($_SESSION[STUDENT_SESSION_KEY]);
        return null;
    }
    return $s;
}

function student_login_user(array $account): void {
    student_start();
    $_SESSION[STUDENT_SESSION_KEY] = [
        'id'        => (int)$account['id'],
        'client_id' => (int)$account['client_id'],
        'login'     => $account['login'],
        'ts'        => time(),
    ];
}

function student_logout(): void {
    student_start();
    unset($_SESSION[STUDENT_SESSION_KEY]);
    session_destroy();
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

// ── Dostęp rodzica (osobna sesja, dla małoletnich kursantów) ──────────────────
const PARENT_SESSION_KEY = 'k30_ti_parent';
const PARENT_SESSION_TTL = 3600 * 4; // 4h

function parent_current(): ?array {
    student_start();
    $p = $_SESSION[PARENT_SESSION_KEY] ?? null;
    if (!$p) return null;
    if ((time() - ($p['ts'] ?? 0)) > PARENT_SESSION_TTL) {
        unset($_SESSION[PARENT_SESSION_KEY]);
        return null;
    }
    return $p;
}

/** Zaloguj rodzica do widoku konkretnego kursanta (po OTP lub linku magicznym). */
function parent_login_for_student(int $studentId): bool {
    $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1", [$studentId]);
    if (!$acc) return false;
    $client = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
    student_start();
    $_SESSION[PARENT_SESSION_KEY] = [
        'student_id' => (int)$acc['id'],
        'client_id'  => (int)$acc['client_id'],
        'name'       => $client['name'] ?? $acc['login'],
        'ts'         => time(),
    ];
    return true;
}

function parent_logout(): void {
    student_start();
    unset($_SESSION[PARENT_SESSION_KEY]);
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
    $_SESSION['k30_parent_otp'] = [
        'hash'    => password_hash($code, PASSWORD_BCRYPT),
        'phone'   => $norm,
        'kids'    => array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name']], $matched),
        'expires' => time() + 300,
        'tries'   => 0,
    ];
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    sms_send($norm, "{$org}: kod dostepu rodzica do rozliczen: {$code}");
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
