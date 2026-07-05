<?php
/**
 * includes/canva.php — Dostęp do Canva Pro.
 *
 * Canva nie obsługuje logowania SAML (SSO) — dostęp jest wyłącznie na
 * podstawie wniosku: użytkownik składa prośbę o dostęp do Canva Pro (system
 * Zatwierdzeń → canva_access), administrator ją zatwierdza i ręcznie
 * zaprasza konto w panelu Canva. Po przyznaniu dostępu użytkownik loguje
 * się na canva.com przez „Continue with Microsoft".
 */

require_once __DIR__ . '/db.php';

// ── Dostęp do Canva na poziomie konta (dla wolontariuszy BEZ umowy) ───────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS canva_user_access (
            user_id      INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            access       INTEGER NOT NULL DEFAULT 1,
            requested_at DATETIME, invited_at DATETIME,
            login        TEXT, haslo TEXT, konto_zrodlo TEXT, konto_at DATETIME,
            granted_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
})();

/** Dostęp Canva danego użytkownika (poziom konta) lub null. */
function canva_user_access_get(int $user_id): ?array {
    if ($user_id <= 0) return null;
    try { return db_one("SELECT * FROM canva_user_access WHERE user_id=?", [$user_id]); }
    catch (\Throwable $e) { return null; }
}

/** Włącz dostęp Canva dla użytkownika (admin). */
function canva_user_grant(int $user_id, int $by): void {
    $now = date('Y-m-d H:i:s');
    $ex = canva_user_access_get($user_id);
    if ($ex) {
        db()->prepare("UPDATE canva_user_access SET access=1, invited_at=COALESCE(invited_at,?), granted_by=?, updated_at=? WHERE user_id=?")
            ->execute([$now, $by, $now, $user_id]);
    } else {
        db()->prepare("INSERT INTO canva_user_access (user_id, access, invited_at, granted_by, updated_at) VALUES (?,1,?,?,?)")
            ->execute([$user_id, $now, $by, $now]);
    }
}

/** Wyłącz dostęp Canva użytkownika. */
function canva_user_revoke(int $user_id): void {
    try { db()->prepare("UPDATE canva_user_access SET access=0, updated_at=datetime('now') WHERE user_id=?")->execute([$user_id]); }
    catch (\Throwable $e) {}
}

/** Zapisz dane konta Canva (login/hasło) na poziomie użytkownika. */
function canva_user_set_account(int $user_id, string $login, string $haslo, string $zrodlo): void {
    $ex = canva_user_access_get($user_id);
    if (!$ex) { canva_user_grant($user_id, 0); }
    db()->prepare("UPDATE canva_user_access SET login=?, haslo=?, konto_zrodlo=?, konto_at=datetime('now'), updated_at=datetime('now') WHERE user_id=?")
        ->execute([$login, $haslo, $zrodlo, $user_id]);
}

/** Lista dostępów Canva przyznanych na poziomie konta (z danymi użytkownika). */
function canva_users_with_access(): array {
    try {
        return db_all(
            "SELECT c.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
             FROM canva_user_access c JOIN users u ON u.id=c.user_id
             ORDER BY u.name"
        );
    } catch (\Throwable $e) { return []; }
}

/** Czy użytkownik ma dostęp do Canva (z umowy wolontariackiej lub na poziomie konta). */
function canva_user_has_access(int $user_id): bool {
    if ($user_id <= 0) return false;
    $a = canva_user_access_get($user_id);
    if ($a && (int)($a['access'] ?? 0) === 1) return true;
    try {
        $u = db_one("SELECT email FROM users WHERE id=?", [$user_id]);
        if (!empty($u['email'])) {
            $r = db_one("SELECT 1 AS x FROM umowy_wolontariat WHERE email=? AND canva_access=1 LIMIT 1", [$u['email']]);
            if ($r) return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

/** Klucz API integracji „Canva Button" (Design Button SDK) — z ustawień org. */
function canva_button_api_key(): string {
    return trim((string)org_setting('canva_button_api_key'));
}

/** Czy kreator Canva (Design Button) jest skonfigurowany. */
function canva_creator_enabled(): bool {
    return canva_button_api_key() !== '';
}
