<?php
/**
 * includes/mobile_auth.php — szybkie logowanie do dialera: UID + PIN (+ IKA).
 *
 * Świadomie SŁABSZA ścieżka uwierzytelnienia niż MS365, dlatego jest obudowana
 * trzema ograniczeniami:
 *
 *   1. Zakres — sesja z PIN-u jest oznaczona (`_mobile_scope`) i wpuszcza WYŁĄCZNIE
 *      do dialera; każdy inny moduł ją odrzuca (patrz require_login()).
 *   2. IKA — po PIN-ie nadal obowiązuje weryfikacja kodem IKA, jak w całym CRM.
 *   3. Blokada — po MOBILE_PIN_MAX_FAILS nieudanych próbach konto ma zablokowane
 *      logowanie PIN-em na MOBILE_PIN_LOCK_MIN minut (normalne logowanie działa dalej).
 *
 * PIN jest przechowywany wyłącznie jako hash (password_hash), nigdy jawnie —
 * nawet administrator nie może go odczytać, tylko wyłączyć tę ścieżkę użytkownikowi.
 */

declare(strict_types=1);

const MOBILE_PIN_LENGTH    = 6;
const MOBILE_PIN_MAX_FAILS = 5;
const MOBILE_PIN_LOCK_MIN  = 15;

/** Ścieżki (względem APP_URL), do których wolno wejść sesji ograniczonej do dialera. */
const MOBILE_SCOPE_PATHS = [
    '/mobilna',
    '/crm/mobile',
    '/contracts/ika_gate.php',   // weryfikacja IKA — wymagana także przy PIN-ie
    '/auth/logout.php',
    '/assets',
    '/errors',
];

/** Idempotentne dołożenie kolumn PIN-u do users (wzorzec samonaprawy schematu). */
function mobile_pin_schema_heal(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    foreach ([
        "ALTER TABLE users ADD COLUMN mobile_pin              TEXT",
        "ALTER TABLE users ADD COLUMN mobile_pin_set_at       DATETIME",
        "ALTER TABLE users ADD COLUMN mobile_pin_blocked      INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN mobile_pin_fails        INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN mobile_pin_locked_until DATETIME",
        "ALTER TABLE users ADD COLUMN mobile_pin_used_at      DATETIME",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) { /* kolumna już jest */ }
    }
}

/** Czy mechanizm jest włączony globalnie (Administracja → ustawienia). */
function mobile_pin_module_enabled(): bool
{
    return module_enabled('mobile_pin_enabled');
}

/**
 * Czy ten użytkownik może korzystać z logowania PIN-em.
 * Administrator może odebrać tę możliwość pojedynczemu kontu (mobile_pin_blocked).
 */
function mobile_pin_allowed(array $u): bool
{
    if (!mobile_pin_module_enabled()) return false;
    if (!empty($u['mobile_pin_blocked'])) return false;
    // Konto awaryjne nie dostaje uproszczonej ścieżki wejścia.
    if (strtolower(trim((string)($u['email'] ?? ''))) === 'serwis@local') return false;
    return true;
}

/** Czy PIN jest ustawiony. */
function mobile_pin_is_set(array $u): bool
{
    return trim((string)($u['mobile_pin'] ?? '')) !== '';
}

/**
 * Sprawdza, czy PIN spełnia wymagania. Zwraca komunikat błędu albo null.
 * Odrzucamy PIN-y trywialne — przy 6 cyfrach i publicznie znanym UID to realna różnica.
 */
function mobile_pin_validate(string $pin, int $uid = 0): ?string
{
    if (!preg_match('/^\d{' . MOBILE_PIN_LENGTH . '}$/', $pin)) {
        return 'PIN musi mieć dokładnie ' . MOBILE_PIN_LENGTH . ' cyfr.';
    }
    if (preg_match('/^(\d)\1+$/', $pin)) {
        return 'PIN nie może składać się z jednej powtórzonej cyfry.';
    }
    $asc = '0123456789';
    if (str_contains($asc, $pin) || str_contains(strrev($asc), $pin)) {
        return 'PIN nie może być ciągiem kolejnych cyfr.';
    }
    if ($uid > 0 && $pin === str_pad((string)$uid, MOBILE_PIN_LENGTH, '0', STR_PAD_LEFT)) {
        return 'PIN nie może być Twoim numerem konta.';
    }
    return null;
}

/** Ustawia (lub zmienia) PIN. Zwraca komunikat błędu albo null przy powodzeniu. */
function mobile_pin_set(int $uid, string $pin): ?string
{
    mobile_pin_schema_heal();
    if ($err = mobile_pin_validate($pin, $uid)) return $err;

    db()->prepare(
        "UPDATE users
            SET mobile_pin = ?, mobile_pin_set_at = ?, mobile_pin_fails = 0, mobile_pin_locked_until = NULL
          WHERE id = ?"
    )->execute([password_hash($pin, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $uid]);

    if (function_exists('authlog_write')) authlog_write($uid, 'mobile_pin_set', '', 'Ustawiono PIN do dialera');
    return null;
}

/** Usuwa PIN — logowanie PIN-em przestaje działać do ponownego ustawienia. */
function mobile_pin_clear(int $uid): void
{
    mobile_pin_schema_heal();
    db()->prepare(
        "UPDATE users
            SET mobile_pin = NULL, mobile_pin_set_at = NULL, mobile_pin_fails = 0, mobile_pin_locked_until = NULL
          WHERE id = ?"
    )->execute([$uid]);

    if (function_exists('authlog_write')) authlog_write($uid, 'mobile_pin_clear', '', 'Usunięto PIN do dialera');
}

/** Ile minut zostało do końca blokady (0 = brak blokady). */
function mobile_pin_lock_left(array $u): int
{
    $until = (string)($u['mobile_pin_locked_until'] ?? '');
    if ($until === '') return 0;
    $left = strtotime($until) - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

/**
 * Próba logowania UID + PIN.
 *
 * Zwraca ['ok'=>true,'user'=>rekord] albo ['ok'=>false,'error'=>komunikat].
 * Komunikaty są celowo nierozróżnialne dla „nie ma takiego UID" i „zły PIN" —
 * inaczej formularz stałby się wyliczarką istniejących numerów kont.
 */
function mobile_pin_attempt(int $uid, string $pin): array
{
    mobile_pin_schema_heal();

    $generic = ['ok' => false, 'error' => 'Nieprawidłowy numer konta lub PIN.'];
    if ($uid <= 0 || $pin === '') return $generic;

    $u = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$u) {
        if (function_exists('authlog_write')) authlog_write(null, 'mobile_pin_fail', '', 'Nieznany UID: ' . $uid);
        return $generic;
    }

    if (!mobile_pin_allowed($u)) {
        return ['ok' => false, 'error' => 'Logowanie PIN-em jest wyłączone dla tego konta.'];
    }
    if (!mobile_pin_is_set($u)) {
        return $generic;   // nie ujawniamy, że konto istnieje, ale nie ma PIN-u
    }
    if ($left = mobile_pin_lock_left($u)) {
        return ['ok' => false, 'error' => 'Zbyt wiele błędnych prób. Spróbuj za ' . $left . ' min.'];
    }

    if (!password_verify($pin, (string)$u['mobile_pin'])) {
        $fails = (int)($u['mobile_pin_fails'] ?? 0) + 1;
        $lock  = $fails >= MOBILE_PIN_MAX_FAILS
            ? date('Y-m-d H:i:s', time() + MOBILE_PIN_LOCK_MIN * 60)
            : null;
        db()->prepare("UPDATE users SET mobile_pin_fails = ?, mobile_pin_locked_until = ? WHERE id = ?")
            ->execute([$lock ? 0 : $fails, $lock, $uid]);

        if (function_exists('authlog_write')) {
            authlog_write($uid, 'mobile_pin_fail', (string)($u['email'] ?? ''),
                'Błędny PIN (' . $fails . '/' . MOBILE_PIN_MAX_FAILS . ')' . ($lock ? ' — blokada ' . MOBILE_PIN_LOCK_MIN . ' min' : ''));
        }
        if ($lock) {
            return ['ok' => false, 'error' => 'Zbyt wiele błędnych prób. Spróbuj za ' . MOBILE_PIN_LOCK_MIN . ' min.'];
        }
        return $generic;
    }

    db()->prepare("UPDATE users SET mobile_pin_fails = 0, mobile_pin_locked_until = NULL, mobile_pin_used_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $uid]);

    return ['ok' => true, 'user' => $u];
}

/**
 * Zakłada sesję ograniczoną do dialera.
 * Poza znacznikiem zakresu korzystamy ze zwykłego login_user() — ten sam rejestr
 * sesji, ten sam fingerprint UA, to samo zdalne wylogowanie.
 */
function mobile_session_login(array $u): void
{
    // Zakres przekazujemy jawnie do login_user() — ono ustawia znacznik razem
    // z resztą sesji i zamyka ją jednym zapisem.
    login_user($u, true);

    if (function_exists('authlog_write')) {
        authlog_write((int)$u['id'], 'mobile_pin_login', (string)($u['email'] ?? ''), 'Logowanie PIN-em do dialera');
    }
}

/** Czy bieżąca sesja jest ograniczona do dialera. */
function mobile_session_is_scoped(): bool
{
    return !empty($_SESSION['_mobile_scope']);
}

/** Czy dana ścieżka (względem APP_URL) jest dozwolona dla sesji dialera. */
function mobile_scope_allows(string $path): bool
{
    foreach (MOBILE_SCOPE_PATHS as $allowed) {
        if ($path === $allowed || str_starts_with($path, $allowed . '/')) return true;
    }
    return false;
}

/** Ścieżka bieżącego żądania względem APP_URL (bez query). */
function mobile_request_path(): string
{
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $app  = rtrim((string)(parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/');
    if ($app !== '' && str_starts_with($path, $app)) {
        $path = substr($path, strlen($app));
    }
    return $path === '' ? '/' : $path;
}
