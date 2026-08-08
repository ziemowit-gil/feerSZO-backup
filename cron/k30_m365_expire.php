<?php
/**
 * Automatyczne wyłączanie wygasłych kont M365 dla Dydaktyka 3.
 * Uruchamiany z CLI (cron), np. raz dziennie.
 *
 * Wyłącza (accountEnabled=false) konta, których data ważności minęła:
 *   1. Beneficjenci — k30_clients.m365_expires_at
 *   2. Konta szkoleniowe (prefix{N}) — k30_m365_accounts.expires_at
 * Po wyłączeniu zapisuje znacznik (m365_disabled_at / disabled_at), więc każde
 * konto wyłączane jest tylko raz.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/m365.php';

/** Czyta ustawienie K30-M365 (przestrzeń kluczy k30_m365_*). */
function k30m_setting(string $key, string $default = ''): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['k30_m365_'.$key]);
        return $r['value'] ?? $default;
    } catch (\Throwable $e) { return $default; }
}

/** Buduje M365Graph dla K30 — identycznie jak k30_m365() w panelu admina. */
function k30m_graph(): M365Graph {
    if ((bool)k30m_setting('use_own_tenant')) {
        return new M365Graph([
            'tenant_id'     => k30m_setting('tenant_id'),
            'client_id'     => k30m_setting('client_id'),
            'client_secret' => k30m_setting('client_secret'),
            'domain'        => k30m_setting('domain'),
        ]);
    }
    $domain_override = k30m_setting('domain');
    return new M365Graph([
        'tenant_id'     => m365_setting('m365_tenant_id'),
        'client_id'     => m365_setting('m365_graph_client_id'),
        'client_secret' => m365_setting('m365_graph_client_secret'),
        'domain'        => $domain_override ?: m365_setting('m365_domain'),
    ]);
}

$today = date('Y-m-d');
echo "[{$today} " . date('H:i:s') . "] Wyłączanie wygasłych kont M365 (K30) — start\n";

$count_off = 0; $count_err = 0;

try {
    $g = k30m_graph();
    $g->test_connection(); // wyłap błąd konfiguracji od razu
} catch (\Throwable $e) {
    echo "[" . date('H:i:s') . "] BŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
    exit(1);
}

// ── 1. Beneficjenci ──────────────────────────────────────────────────────────
try {
    $rows = db_all(
        "SELECT id, name, m365_user_id, m365_login, m365_expires_at
           FROM k30_clients
          WHERE m365_user_id IS NOT NULL AND m365_user_id != ''
            AND m365_expires_at IS NOT NULL AND m365_expires_at != ''
            AND m365_expires_at < ?
            AND m365_disabled_at IS NULL",
        [$today]
    );
    foreach ($rows as $r) {
        try {
            $g->set_enabled($r['m365_user_id'], false);
            db()->prepare("UPDATE k30_clients SET m365_disabled_at=datetime('now') WHERE id=?")->execute([$r['id']]);
            echo "  [OK] {$r['m365_login']} ({$r['name']}) — WYŁĄCZONO (ważne do {$r['m365_expires_at']})\n";
            $count_off++;
        } catch (\Throwable $e) {
            echo "  [BŁĄD] {$r['m365_login']} — " . $e->getMessage() . "\n";
            $count_err++;
        }
    }
} catch (\Throwable $e) {
    echo "  [WARN] k30_clients: " . $e->getMessage() . "\n";
}

// ── 2. Konta szkoleniowe (prefix) ─────────────────────────────────────────────
try {
    $rows = db_all(
        "SELECT id, login, m365_user_id, expires_at
           FROM k30_m365_accounts
          WHERE m365_user_id IS NOT NULL AND m365_user_id != ''
            AND expires_at IS NOT NULL AND expires_at != ''
            AND expires_at < ?
            AND disabled_at IS NULL",
        [$today]
    );
    foreach ($rows as $r) {
        try {
            $g->set_enabled($r['m365_user_id'], false);
            db()->prepare("UPDATE k30_m365_accounts SET disabled_at=datetime('now') WHERE id=?")->execute([$r['id']]);
            echo "  [OK] {$r['login']} — WYŁĄCZONO (ważne do {$r['expires_at']})\n";
            $count_off++;
        } catch (\Throwable $e) {
            echo "  [BŁĄD] {$r['login']} — " . $e->getMessage() . "\n";
            $count_err++;
        }
    }
} catch (\Throwable $e) {
    echo "  [WARN] k30_m365_accounts: " . $e->getMessage() . "\n";
}

echo "[" . date('H:i:s') . "] Zakończono — wyłączono: {$count_off} | błędów: {$count_err}\n";
