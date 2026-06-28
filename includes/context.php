<?php
/**
 * includes/context.php — Kontekst pracy administratora ("wcielanie się").
 *
 * Pozwala koncie z rolą `admin` pracować w systemie jako ktoś inny:
 *   • mode='user' — wcielenie w konkretnego użytkownika (widzi JEGO dane: panel,
 *     umowy, zadania). Klasyczna impersonacja na potrzeby wsparcia/diagnozy.
 *   • mode='role' — podgląd samej roli na własnym koncie (interfejs/uprawnienia
 *     danej roli, bez danych konkretnej osoby).
 *
 * Konto administratora bywa równocześnie wolontariuszem / zleceniobiorcą — wtedy
 * wystarczy wejść we własny panel (bez kontekstu). Wcielanie służy do oglądania
 * cudzych kont.
 *
 * Prawdziwy zalogowany użytkownik zawsze pozostaje w $_SESSION['user'] i nigdy nie
 * jest podmieniany — nakładkę stosuje wyłącznie current_user() (zob. auth.php).
 * Każde wejście/wyjście jest audytowane (tabela user_context_log + auth_log).
 */

if (!function_exists('ctx_real_user')) {

/** Prawdziwy zalogowany użytkownik (z pominięciem nakładki kontekstu). */
function ctx_real_user(): ?array {
    auth_start();
    return $_SESSION['user'] ?? null;
}

/** Czy prawdziwy użytkownik może przełączać kontekst (tylko admin). */
function ctx_can_switch(): bool {
    $u = ctx_real_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/** Aktywny kontekst (lub null). Tylko dla prawdziwego admina — zabezpieczenie. */
function ctx_active(): ?array {
    if (!ctx_can_switch()) return null;
    $c = $_SESSION['ctx'] ?? null;
    return is_array($c) ? $c : null;
}

/** Czy admin pracuje w cudzym/innym kontekście. */
function ctx_is_impersonating(): bool {
    return ctx_active() !== null;
}

/**
 * Nakładka kontekstu na current_user(). $real to $_SESSION['user'] (admin).
 * Zwraca tablicę użytkownika „widzianą" przez resztę systemu albo null
 * (gdy kontekst nieaktywny lub cel zniknął → fallback do admina).
 */
function ctx_overlay(array $real): ?array {
    static $cache = null;
    $c = $_SESSION['ctx'] ?? null;
    if (!is_array($c) || ($real['role'] ?? '') !== 'admin') return null;

    if (($c['mode'] ?? '') === 'role') {
        $u = $real;
        $u['role']             = $c['role'];
        $u['_impersonating']   = true;
        $u['_ctx_mode']        = 'role';
        $u['_ctx_label']       = ctx_role_label($c['role']) . ' (podgląd roli)';
        $u['_real_user']       = ['id'=>$real['id'],'name'=>$real['name'],'email'=>$real['email']];
        return $u;
    }

    if (($c['mode'] ?? '') === 'user') {
        $uid = (int)($c['uid'] ?? 0);
        if ($uid <= 0) return null;
        if ($cache === null || ($cache['id'] ?? null) !== $uid) {
            try {
                $row = db_one(
                    "SELECT id, name, email, role, microsoft_id, portal_scope
                       FROM users WHERE id=? AND is_active=1", [$uid]
                );
            } catch (\Throwable $e) { $row = null; }
            $cache = $row ?: false;
        }
        if (!$cache) return null; // cel nieaktywny/usunięty → wróć do admina
        return [
            'id'             => (int)$cache['id'],
            'name'           => $cache['name'],
            'email'          => $cache['email'],
            'role'           => $cache['role'],
            'microsoft_id'   => $cache['microsoft_id'] ?? '',
            'portal_scope'   => $cache['portal_scope'] ?? null,
            '_impersonating' => true,
            '_ctx_mode'      => 'user',
            '_ctx_label'     => trim(($cache['name'] ?? '') . ' · ' . ($cache['email'] ?? '')),
            '_real_user'     => ['id'=>$real['id'],'name'=>$real['name'],'email'=>$real['email']],
        ];
    }
    return null;
}

function ctx_role_label(string $role): string {
    $map = [
        'admin'    => 'Administrator',
        'editor'   => 'Edytor',
        'viewer'   => 'Wolontariusz / współpracownik (widz)',
        'crm_user' => 'Użytkownik CRM',
        'ezd_user' => 'Użytkownik EZD',
    ];
    return $map[$role] ?? $role;
}

/** Tworzy tabelę dziennika kontekstu (idempotentnie). */
function ctx_ensure_log_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_context_log (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            real_user_id   INTEGER NOT NULL,
            real_email     TEXT,
            mode           TEXT NOT NULL,
            target_user_id INTEGER,
            target_role    TEXT,
            target_label   TEXT,
            reason         TEXT,
            ip             TEXT,
            entered_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            exited_at      DATETIME
        )");
    } catch (\Throwable $e) {}
    // Dołożenie kolumny w istniejących instalacjach.
    try { db()->exec("ALTER TABLE user_context_log ADD COLUMN reason TEXT"); } catch (\Throwable $e) {}
}

function _ctx_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Wejście w kontekst konkretnego użytkownika. Wymaga podania powodu (audyt).
 * Zwraca true/false.
 */
function ctx_enter_user(int $uid, string $reason = ''): bool {
    if (!ctx_can_switch()) return false;
    $reason = trim($reason);
    if ($reason === '') return false; // powód wymagany
    $real = ctx_real_user();
    if ($uid <= 0 || $uid === (int)$real['id']) return false;
    $target = null;
    try { $target = db_one("SELECT id, name, email, role FROM users WHERE id=? AND is_active=1", [$uid]); }
    catch (\Throwable $e) {}
    if (!$target) return false;

    $reason = mb_substr($reason, 0, 500);
    $label = trim(($target['name'] ?? '') . ' · ' . ($target['email'] ?? ''));
    ctx_ensure_log_table();
    $log_id = 0;
    try {
        db()->prepare(
            "INSERT INTO user_context_log (real_user_id, real_email, mode, target_user_id, target_role, target_label, reason, ip)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([(int)$real['id'], $real['email'] ?? '', 'user', $uid, $target['role'] ?? '', $label, $reason, _ctx_ip()]);
        $log_id = (int)db()->lastInsertId();
    } catch (\Throwable $e) {}

    $_SESSION['ctx'] = ['mode'=>'user', 'uid'=>$uid, 'since'=>time(), 'log_id'=>$log_id];
    $_SESSION['ctx_decided'] = 1;
    _ctx_authlog($real, "Wejście w kontekst użytkownika: {$label} — powód: {$reason}");
    return true;
}

/** Wejście w podgląd roli (bez danych osoby). */
function ctx_enter_role(string $role): bool {
    if (!ctx_can_switch()) return false;
    $allowed = ['editor', 'viewer', 'crm_user', 'ezd_user'];
    if (!in_array($role, $allowed, true)) return false;
    $real = ctx_real_user();

    ctx_ensure_log_table();
    $log_id = 0;
    try {
        db()->prepare(
            "INSERT INTO user_context_log (real_user_id, real_email, mode, target_role, target_label, ip)
             VALUES (?,?,?,?,?,?)"
        )->execute([(int)$real['id'], $real['email'] ?? '', 'role', $role, ctx_role_label($role), _ctx_ip()]);
        $log_id = (int)db()->lastInsertId();
    } catch (\Throwable $e) {}

    $_SESSION['ctx'] = ['mode'=>'role', 'role'=>$role, 'since'=>time(), 'log_id'=>$log_id];
    $_SESSION['ctx_decided'] = 1;
    _ctx_authlog($real, "Podgląd roli: " . ctx_role_label($role));
    return true;
}

/** Wyjście z kontekstu — powrót do administratora. */
function ctx_exit(): void {
    $real = ctx_real_user();
    $c = $_SESSION['ctx'] ?? null;
    if (is_array($c) && $real) {
        if (!empty($c['log_id'])) {
            try { db()->prepare("UPDATE user_context_log SET exited_at=datetime('now') WHERE id=?")->execute([(int)$c['log_id']]); }
            catch (\Throwable $e) {}
        }
        _ctx_authlog($real, 'Powrót do kontekstu administratora');
    }
    unset($_SESSION['ctx']);
    $_SESSION['ctx_decided'] = 1; // nie pytaj ponownie w tej sesji
}

function _ctx_authlog(array $real, string $msg): void {
    try {
        if (function_exists('authlog_write')) {
            authlog_write((int)$real['id'], 'context_switch', $real['email'] ?? '', $msg);
        }
    } catch (\Throwable $e) {}
}

/** Baner widoczny w każdym widoku, gdy kontekst jest aktywny. */
function ctx_banner_html(): string {
    if (!function_exists('ctx_active')) return '';
    $c = ctx_active();
    if (!$c) return '';
    $cu    = current_user();
    $label = $cu['_ctx_label'] ?? '';
    $real  = ctx_real_user();
    $rname = $real['name'] ?? 'administrator';
    $app   = defined('APP_URL') ? APP_URL : '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $mode_txt = ($c['mode'] ?? '') === 'role' ? 'Podgląd roli' : 'Jako';
    return '<div style="position:sticky;top:0;z-index:10800;display:flex;align-items:center;gap:.45rem;'
        . 'flex-wrap:wrap;background:#7c2d12;color:#fff;padding:.15rem .7rem;font-size:.72rem;line-height:1.4;'
        . 'box-shadow:0 1px 3px rgba(0,0,0,.2)" role="alert">'
        . '<i class="bi bi-incognito" style="font-size:.8rem"></i>'
        . '<span><strong>' . $h($mode_txt) . ':</strong> ' . $h($label) . '</span>'
        . '<a href="' . $h($app) . '/auth/exit_context.php" '
        . 'style="margin-left:auto;background:#fff;color:#7c2d12;font-weight:700;text-decoration:none;'
        . 'padding:.05rem .55rem;border-radius:5px;white-space:nowrap;font-size:.72rem">'
        . '<i class="bi bi-box-arrow-left"></i> Wróć do administratora</a>'
        . '</div>';
}

} // function_exists guard
