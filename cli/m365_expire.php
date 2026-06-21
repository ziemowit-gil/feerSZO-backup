<?php
/**
 * cli/m365_expire.php — wyłącza konta M365 (K30), którym minął termin ważności.
 *
 * Dla każdego beneficjenta z ustawionym `m365_expires_at` w przeszłości i jeszcze
 * niewyłączonego (`m365_disabled_at` puste) ustawia accountEnabled=false w Entra ID
 * i zapisuje znacznik wyłączenia. Idempotentny — można uruchamiać z crona codziennie.
 *
 * Uruchom:  php cli/m365_expire.php
 * Cron:     5 1 * * *  php /sciezka/cli/m365_expire.php >> /var/log/m365_expire.log 2>&1
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/m365.php';   // M365Graph + m365_setting()

/** Ustawienie K30-M365 (te same klucze, co panel admina). */
function k30m_setting(string $key, string $default = ''): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['k30_m365_' . $key]);
        return $r['value'] ?? $default;
    } catch (\Throwable $e) { return $default; }
}

/** Instancja M365Graph dla K30 (własny tenant lub główny — jak w panelu). */
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

// Upewnij się, że kolumny istnieją (gdy panel m365.php nie był jeszcze otwarty)
foreach (['m365_expires_at DATE', 'm365_disabled_at DATETIME'] as $col) {
    try { db()->exec("ALTER TABLE k30_clients ADD COLUMN $col"); } catch (\Throwable $e) {}
}

$rows = db_all(
    "SELECT id, name, m365_user_id, m365_login, m365_expires_at
     FROM k30_clients
     WHERE m365_user_id IS NOT NULL AND m365_user_id <> ''
       AND m365_expires_at IS NOT NULL AND m365_expires_at <> ''
       AND date(m365_expires_at) < date('now','localtime')
       AND (m365_disabled_at IS NULL OR m365_disabled_at = '')"
);

if (!$rows) { echo "[" . date('Y-m-d H:i') . "] Brak kont do wyłączenia.\n"; exit(0); }

try {
    $g = k30m_graph();
} catch (\Throwable $e) {
    fwrite(STDERR, "Błąd inicjalizacji M365: " . $e->getMessage() . "\n");
    exit(1);
}

$ok = 0; $err = 0;
foreach ($rows as $r) {
    try {
        $g->set_enabled($r['m365_user_id'], false);
        db()->prepare("UPDATE k30_clients SET m365_disabled_at=datetime('now') WHERE id=?")->execute([$r['id']]);
        echo "  [OK]  {$r['m365_login']} (ważne do {$r['m365_expires_at']}) — wyłączone\n";
        $ok++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  [BŁĄD] {$r['m365_login']} — " . $e->getMessage() . "\n");
        $err++;
    }
}

echo "[" . date('Y-m-d H:i') . "] Wyłączono kont: {$ok}" . ($err ? ", błędów: {$err}" : '') . ".\n";
exit($err ? 1 : 0);
