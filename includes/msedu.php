<?php
/**
 * includes/msedu.php — kody dostępowe (LCCC) do automatycznego zakładania
 * kont Microsoft 365 (tenant główny organizacji, reużywa M365Graph).
 *
 * Admin: admin/ms_edu_codes.php (CRUD kodów + licencje przypisywane zbiorczo
 * każdemu kontu założonemu danym kodem). Publiczne wejście z kodem:
 * ext/kontoMicrosoftEdu/index.php (bez logowania).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/m365.php';

function msedu_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS msedu_codes (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            code         TEXT NOT NULL UNIQUE,
            login_prefix TEXT NOT NULL DEFAULT '',
            max_uses     INTEGER NOT NULL DEFAULT 1,
            used_count   INTEGER NOT NULL DEFAULT 0,
            license_skus TEXT NOT NULL DEFAULT '',
            expires_at   DATETIME,
            is_active    INTEGER NOT NULL DEFAULT 1,
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS msedu_accounts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            code_id    INTEGER NOT NULL REFERENCES msedu_codes(id) ON DELETE CASCADE,
            ms_user_id TEXT NOT NULL DEFAULT '',
            upn        TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS msedu_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ip         TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_msedu_attempts_ip ON msedu_attempts(ip, created_at)");
    } catch (\Throwable $e) {}
}

// ── Format kodu ────────────────────────────────────────────────────────────

function msedu_code_normalize(string $raw): string {
    return strtoupper(trim($raw));
}

function msedu_code_valid_format(string $code): bool {
    return preg_match('/^[A-Z]\d{3}$/', $code) === 1;
}

// ── Rate limiting (proste, per IP) ───────────────────────────────────────────

function msedu_rate_limited(string $ip, int $maxPerHour = 20): bool {
    $since = date('Y-m-d H:i:s', time() - 3600);
    $n = (int)(db_one(
        "SELECT COUNT(*) AS n FROM msedu_attempts WHERE ip=? AND created_at > ?",
        [$ip, $since]
    )['n'] ?? 0);
    return $n >= $maxPerHour;
}

function msedu_record_attempt(string $ip): void {
    try { db_insert('msedu_attempts', ['ip' => $ip]); } catch (\Throwable $e) {}
}

// ── CRUD kodów (admin) ───────────────────────────────────────────────────────

function msedu_codes_list(): array {
    return db_all("SELECT * FROM msedu_codes ORDER BY created_at DESC");
}

function msedu_code_get(string $code): ?array {
    return db_one("SELECT * FROM msedu_codes WHERE code=?", [msedu_code_normalize($code)]);
}

function msedu_code_get_by_id(int $id): ?array {
    return db_one("SELECT * FROM msedu_codes WHERE id=?", [$id]);
}

/** @param array<string> $skus Lista skuId do zapisania jako JSON. */
function msedu_code_save(array $data, ?int $id = null): int {
    $fields = [
        'code'         => msedu_code_normalize((string)($data['code'] ?? '')),
        'login_prefix' => trim((string)($data['login_prefix'] ?? '')),
        'max_uses'     => max(1, (int)($data['max_uses'] ?? 1)),
        'license_skus' => json_encode(array_values(array_filter((array)($data['license_skus'] ?? [])))),
        'expires_at'   => (($data['expires_at'] ?? '') !== '') ? $data['expires_at'] : null,
        'is_active'    => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        db_update('msedu_codes', $fields, $id);
        return $id;
    }
    $fields['created_by'] = $data['created_by'] ?? null;
    return db_insert('msedu_codes', $fields);
}

function msedu_code_delete(int $id): void {
    db()->prepare("DELETE FROM msedu_codes WHERE id=?")->execute([$id]);
}

function msedu_accounts_for_code(int $codeId): array {
    return db_all("SELECT * FROM msedu_accounts WHERE code_id=? ORDER BY created_at DESC", [$codeId]);
}

/** Status kodu do wyświetlenia w adminie: 'active' | 'inactive' | 'expired' | 'exhausted'. */
function msedu_code_status(array $code): string {
    if (empty($code['is_active'])) return 'inactive';
    if (!empty($code['expires_at']) && $code['expires_at'] < date('Y-m-d H:i:s')) return 'expired';
    if ((int)$code['used_count'] >= (int)$code['max_uses']) return 'exhausted';
    return 'active';
}

// ── Realizacja kodu (publiczna strona) ──────────────────────────────────────

/**
 * Waliduje i realizuje kod: zakłada konto M365, nadaje licencje, zapisuje log.
 * Zwraca ['ok'=>true,'upn'=>...,'password'=>...] albo ['ok'=>false,'msg'=>...].
 */
function msedu_redeem(string $rawCode, string $ip): array {
    if (msedu_rate_limited($ip)) {
        return ['ok' => false, 'msg' => 'Zbyt wiele prób z tego adresu. Spróbuj ponownie później.'];
    }
    msedu_record_attempt($ip);

    $code = msedu_code_normalize($rawCode);
    if (!msedu_code_valid_format($code)) {
        return ['ok' => false, 'msg' => 'Nieprawidłowy format kodu — 1 litera i 3 cyfry, np. A123.'];
    }

    $row = msedu_code_get($code);
    if (!$row) {
        return ['ok' => false, 'msg' => 'Nieznany kod dostępowy.'];
    }
    $status = msedu_code_status($row);
    if ($status === 'inactive') return ['ok' => false, 'msg' => 'Ten kod został dezaktywowany.'];
    if ($status === 'expired')  return ['ok' => false, 'msg' => 'Ten kod utracił ważność.'];
    if ($status === 'exhausted') return ['ok' => false, 'msg' => 'Limit kont dla tego kodu został wyczerpany.'];

    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        return ['ok' => false, 'msg' => 'Integracja Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.'];
    }

    $domain = m365_setting('m365_domain') ?: 'feer.org.pl';
    $prefix = $row['login_prefix'] !== '' ? $row['login_prefix'] : 'edu_';
    $local  = $prefix . strtolower($code);

    $login = '';
    for ($i = (int)$row['used_count'] + 1; $i <= (int)$row['used_count'] + 100; $i++) {
        $candidate = "{$local}_{$i}@{$domain}";
        if (!$graph->login_exists($candidate)) { $login = $candidate; break; }
    }
    if ($login === '') {
        return ['ok' => false, 'msg' => 'Nie udało się wygenerować unikalnego loginu — spróbuj ponownie.'];
    }

    $pass = M365Graph::generate_password();
    try {
        $created = $graph->create_user($login, $login, $pass, true);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd tworzenia konta Microsoft: ' . $e->getMessage()];
    }
    $userId = $created['id'] ?? '';
    if ($userId === '') {
        return ['ok' => false, 'msg' => 'Graph nie zwrócił identyfikatora konta.'];
    }

    $skus = json_decode((string)($row['license_skus'] ?? ''), true) ?: [];
    foreach ($skus as $skuId) {
        try { $graph->assign_license($userId, (string)$skuId); } catch (\Throwable $e) { /* licencja nieobowiązkowa dla samego założenia konta */ }
    }

    db_insert('msedu_accounts', ['code_id' => (int)$row['id'], 'ms_user_id' => $userId, 'upn' => $login]);
    db()->prepare("UPDATE msedu_codes SET used_count = used_count + 1 WHERE id=?")->execute([(int)$row['id']]);

    return ['ok' => true, 'upn' => $login, 'password' => $pass];
}
