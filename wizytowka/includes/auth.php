<?php
/**
 * auth.php — logowanie do panelu: hasła przez password_hash/verify,
 * regeneracja sesji, prosty throttling nieudanych prób.
 */
declare(strict_types=1);

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 300;

/** Aktualnie zalogowany administrator albo null. */
function current_user(): ?array
{
    static $user = null;
    if ($user !== null) return $user;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id <= 0) return null;
    $row = q_one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $id]);
    return $user = $row;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Wymuś logowanie — inaczej przekieruj na formularz. */
function require_admin(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? url('/admin');
        redirect('/admin/login');
    }
    return $u;
}

/** Ile sekund blokady logowania zostało (0 = brak blokady). */
function login_lock_remaining(): int
{
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    $last  = (int)($_SESSION['login_last_fail'] ?? 0);
    if ($fails < LOGIN_MAX_ATTEMPTS) return 0;
    $left = LOGIN_LOCK_SECONDS - (time() - $last);
    if ($left <= 0) {
        unset($_SESSION['login_fails'], $_SESSION['login_last_fail']);
        return 0;
    }
    return $left;
}

/** Próba logowania; zwraca true przy sukcesie. */
function attempt_login(string $email, string $password): bool
{
    $user = q_one('SELECT * FROM users WHERE email = :e AND is_active = 1', ['e' => $email]);

    // password_verify zawsze na jakimś hashu — stały czas odpowiedzi.
    $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    if (!password_verify($password, $hash) || !$user) {
        $_SESSION['login_fails']     = (int)($_SESSION['login_fails'] ?? 0) + 1;
        $_SESSION['login_last_fail'] = time();
        return false;
    }

    // Odświeżenie hasha, jeśli zmienił się algorytm/koszt.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db_update('users', (int)$user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    }

    session_regenerate_id(true);
    unset($_SESSION['login_fails'], $_SESSION['login_last_fail']);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    q(
        "UPDATE users SET last_login_at = datetime('now'), last_login_ip = :ip WHERE id = :id",
        ['ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'id' => (int)$user['id']]
    );
    return true;
}

/** Wyloguj i zniszcz sesję. */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Walidacja siły hasła — zwraca listę błędów. */
function password_problems(string $pass, string $confirm): array
{
    $err = [];
    if (mb_strlen($pass) < 10)               $err[] = 'Hasło musi mieć co najmniej 10 znaków.';
    if (!preg_match('~[A-ZĄĆĘŁŃÓŚŹŻ]~u', $pass)) $err[] = 'Hasło musi zawierać wielką literę.';
    if (!preg_match('~[a-ząćęłńóśźż]~u', $pass)) $err[] = 'Hasło musi zawierać małą literę.';
    if (!preg_match('~[0-9]~', $pass))       $err[] = 'Hasło musi zawierać cyfrę.';
    if ($pass !== $confirm)                  $err[] = 'Hasła nie są identyczne.';
    return $err;
}
