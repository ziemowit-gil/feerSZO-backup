<?php
/**
 * Ciche „zapamiętaj mnie" dla paneli TI (kursant, rodzic, dydaktyk).
 *
 * Bez żadnej widocznej opcji w UI: sesja PHP wygasa po krótkim czasie
 * bezczynności (patrz *_SESSION_TTL w auth.php poszczególnych paneli), a ten
 * trwały token w tle cicho ją wznawia, bez ponownego logowania użytkownika.
 *
 * Wzorzec selector/validator (jak k30_ti_instructor_cal_tokens / user_sessions
 * w includes/auth_security.php) — wyciek samej bazy nie ujawnia działających
 * ciasteczek, bo w bazie trzymamy tylko hash walidatora. Token jest jednorazowy:
 * każde udane wznowienie sesji obraca go (nowy selector/validator + cookie).
 */

const TI_REMEMBER_TTL_DAYS = 30;

function ti_remember_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_remember_tokens (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            realm          TEXT NOT NULL,
            selector       TEXT NOT NULL,
            validator_hash TEXT NOT NULL,
            account_id     INTEGER NOT NULL,
            payload        TEXT NOT NULL DEFAULT '',
            expires_at     DATETIME NOT NULL,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) { error_log('[ti_remember] migrate: ' . $e->getMessage()); }
    try { db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_remember_selector ON k30_ti_remember_tokens(realm, selector)"); }
    catch (\Throwable $e) {}
}

function _ti_remember_cookie_name(string $realm): string {
    return 'k30_ti_remember_' . $realm;
}

function _ti_remember_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Wystawia (lub obraca) token trwałego logowania dla danego panelu. Cichy — bez UI. */
function ti_remember_issue(string $realm, int $account_id, array $payload = []): void {
    ti_remember_migrate();
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $ttl       = TI_REMEMBER_TTL_DAYS * 86400;
    $expires   = date('Y-m-d H:i:s', time() + $ttl);
    try {
        db()->prepare(
            "INSERT INTO k30_ti_remember_tokens (realm, selector, validator_hash, account_id, payload, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$realm, $selector, hash('sha256', $validator), $account_id, json_encode($payload), $expires]);
    } catch (\Throwable $e) { return; } // cicha porażka — logowanie i tak się powiodło

    setcookie(_ti_remember_cookie_name($realm), $selector . '.' . $validator, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'httponly' => true,
        'secure'   => _ti_remember_https(),
        'samesite' => 'Lax',
    ]);
}

/**
 * Weryfikuje token z ciasteczka i — jeśli ważny — obraca go (nowy selector/validator).
 * Zwraca ['account_id'=>int,'payload'=>array] albo null (brak / wygasł / niezgodny).
 */
function ti_remember_consume(string $realm): ?array {
    ti_remember_migrate();
    $raw = (string)($_COOKIE[_ti_remember_cookie_name($realm)] ?? '');
    if ($raw === '' || !str_contains($raw, '.')) return null;
    [$selector, $validator] = explode('.', $raw, 2);

    $row = db_one("SELECT * FROM k30_ti_remember_tokens WHERE realm=? AND selector=?", [$realm, $selector]);
    if (!$row) return null;

    // Token użyty niezależnie od wyniku walidacji — jednorazowy (chroni przed powtórką).
    db()->prepare("DELETE FROM k30_ti_remember_tokens WHERE id=?")->execute([$row['id']]);

    if ($row['expires_at'] < date('Y-m-d H:i:s')) return null;
    if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
        // Niezgodny walidator przy trafionym selektorze — możliwa próba kradzieży/zgadywania.
        ti_remember_forget($realm);
        return null;
    }

    $account_id = (int)$row['account_id'];
    $payload    = json_decode((string)$row['payload'], true) ?: [];
    ti_remember_issue($realm, $account_id, $payload); // rotacja tokenu
    return ['account_id' => $account_id, 'payload' => $payload];
}

/** Usuwa token trwałego logowania (wylogowanie / zablokowane konto). */
function ti_remember_forget(string $realm): void {
    $name = _ti_remember_cookie_name($realm);
    $raw  = (string)($_COOKIE[$name] ?? '');
    if ($raw !== '' && str_contains($raw, '.')) {
        [$selector] = explode('.', $raw, 2);
        try { db()->prepare("DELETE FROM k30_ti_remember_tokens WHERE realm=? AND selector=?")->execute([$realm, $selector]); }
        catch (\Throwable $e) {}
    }
    setcookie($name, '', ['expires' => time() - 86400, 'path' => '/', 'httponly' => true, 'secure' => _ti_remember_https(), 'samesite' => 'Lax']);
    unset($_COOKIE[$name]);
}
