<?php
/**
 * Logowanie aplikacji desktopowej EZD Client kontem użytkownika (Microsoft 365 przez istniejące logowanie web).
 *
 * Przepływ (OAuth 2.0 Authorization Code + PKCE S256, redirect na loopback):
 *   1. aplikacja otwiera w przeglądarce  /auth/desktop_login.php?redirect_uri=http://127.0.0.1:PORT/callback
 *      &state=…&code_challenge=…&device=…   (użytkownik loguje się jak zwykle — M365 — i zatwierdza dostęp)
 *   2. serwer przekierowuje na loopback z jednorazowym kodem (ważny 2 min)
 *   3. aplikacja wymienia kod + code_verifier na token:  POST /api/v1/desktop_token.php
 * Token to zwykły klucz API (api_keys) z created_by = użytkownik, więc wszystkie akcje w EZD/CRM mają
 * właściwego autora, a uprawnienia (scope) wynikają z uprawnień konta — nie ze wspólnego klucza.
 */

const DESKTOP_TOKEN_PREFIX  = 'EZD Client — ';
const DESKTOP_TOKEN_TTL_DAYS = 30;
const DESKTOP_CODE_TTL_SEC  = 120;

function desktop_auth_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("
        CREATE TABLE IF NOT EXISTS desktop_auth_codes (
            code_hash      TEXT PRIMARY KEY,
            user_id        INTEGER NOT NULL,
            code_challenge TEXT NOT NULL,
            device         TEXT NOT NULL DEFAULT '',
            expires_at     INTEGER NOT NULL,
            used_at        INTEGER
        )
    ");
}

/** Uprawnienia API wynikające z uprawnień konta (rola ∪ indywidualne; admin ma wszystko). */
function desktop_scopes_for_user(array $user): array {
    $has = static function (string $module, string $level) use ($user): bool {
        if (($user['role'] ?? '') === 'admin') return true;
        $rp = role_permissions((string)$user['role'])[$module] ?? [];
        if (!empty($rp[$level])) return true;
        $up = user_permissions((int)$user['id'])[$module] ?? [];
        return !empty($up[$level]);
    };
    $scopes = [];
    if ($has('ezd', 'can_read'))  $scopes[] = 'ezd:read';
    if ($has('ezd', 'can_write')) { $scopes[] = 'ezd:upload'; $scopes[] = 'ezd:write'; }
    if ($has('crm', 'can_read'))  $scopes[] = 'crm:read';
    if ($has('crm', 'can_write')) $scopes[] = 'crm:write';
    if ($has('msues_wdrozenie', 'can_read'))  $scopes[] = 'msues:read';
    if ($has('msues_wdrozenie', 'can_write')) $scopes[] = 'msues:write';
    return $scopes;
}

/** Składnia dozwolonego dodatkowego adresu powrotu: https:// albo loopback, bez fragmentu i danych logowania. */
function desktop_redirect_syntax_ok(string $uri): bool {
    if ($uri === '' || strlen($uri) > 500 || preg_match('/[\s#\x00-\x1f]/', $uri)) return false;
    $p = parse_url($uri);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme === 'https') return true;
    return $scheme === 'http' && in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
}

/** Lista dodatkowych adresów z ustawienia desktop_allowed_redirects (czytana wprost z bazy — bez cache org_setting). */
function desktop_allowed_redirects(): array {
    $r = db_one("SELECT value FROM settings WHERE key_=?", ['desktop_allowed_redirects']);
    $out = [];
    foreach (explode("\n", (string)($r['value'] ?? '')) as $l) {
        $l = trim($l);
        if ($l !== '' && desktop_redirect_syntax_ok($l)) $out[] = $l;
    }
    return $out;
}

function desktop_valid_redirect(string $uri): bool {
    if (preg_match('#^http://127\.0\.0\.1:(\d{4,5})/callback$#', $uri)
        && (int)parse_url($uri, PHP_URL_PORT) >= 1024 && (int)parse_url($uri, PHP_URL_PORT) <= 65535) return true;
    return in_array($uri, desktop_allowed_redirects(), true);   // porównanie dokładne, bez wildcardów
}

function desktop_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function desktop_clean_device(string $d): string {
    $d = trim(preg_replace('/[^\p{L}\p{N} ._()-]/u', '', $d) ?? '');
    return mb_substr($d, 0, 60);
}

/** Wystawia token dla użytkownika. Poprzednie tokeny tego urządzenia są unieważniane. */
function desktop_issue_token(array $user, string $device): array {
    $name = DESKTOP_TOKEN_PREFIX . $user['name'] . ($device !== '' ? ' (' . $device . ')' : '');
    db()->prepare("UPDATE api_keys SET is_active=0 WHERE created_by=? AND name=?")->execute([(int)$user['id'], $name]);
    $plain   = 'ezdc_' . bin2hex(random_bytes(32));
    $scopes  = desktop_scopes_for_user($user);
    $expires = date('Y-m-d H:i:s', time() + DESKTOP_TOKEN_TTL_DAYS * 86400);
    db_insert('api_keys', [
        'key_hash'    => hash('sha256', $plain),
        'name'        => $name,
        'permissions' => json_encode($scopes),
        'created_at'  => date('Y-m-d H:i:s'),
        'expires_at'  => $expires,
        'is_active'   => 1,
        'created_by'  => (int)$user['id'],
    ]);
    return ['token' => $plain, 'expires_at' => $expires, 'scopes' => $scopes];
}
