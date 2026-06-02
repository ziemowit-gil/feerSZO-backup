<?php
/**
 * Synchronizacja odwrotna M365 → DB.
 * Czyta rzeczywiste stany kont z Azure AD i aktualizuje lokalną bazę danych.
 *
 * Uruchamiany z poziomu CLI (cron), np. co godzinę.
 *
 * Logika:
 *  1. Dla każdego użytkownika w tabeli users z ustawionym microsoft_id:
 *     - Pobiera dane z Graph API (accountEnabled, displayName, userPrincipalName, assignedLicenses)
 *     - Jeśli accountEnabled zmienił się względem users.is_active → aktualizuje lokalnie
 *     - Jeśli userPrincipalName zmienił się względem m365_login w tabelach umów → aktualizuje
 *     - Loguje zmiany do contract_audit_log
 *  2. Obsługuje konta usunięte/zablokowane w Azure (404 lub accountEnabled=false)
 *     → ustawia users.is_active=0 lokalnie.
 *
 * Rate limit: usleep(200000) między wywołaniami API (maks. 5/s).
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/m365.php';

$now = date('Y-m-d H:i:s');
echo "[{$now}] Synchronizacja odwrotna M365 — start\n";

try {
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        throw new RuntimeException('M365 nie jest skonfigurowane (brak tenant_id / client_id / client_secret).');
    }

    // Pobierz wszystkich użytkowników lokalnych z microsoft_id
    $local_users = db_all(
        "SELECT id, name, email, microsoft_id, is_active FROM users WHERE microsoft_id IS NOT NULL AND microsoft_id != ''"
    );

    if (empty($local_users)) {
        echo "[{$now}] Brak użytkowników z microsoft_id — nic do synchronizacji.\n";
        exit(0);
    }

    $tables_with_login = ['umowy_wolontariat', 'umowy_zlecenie', 'umowy_dzielo'];

    $stats = ['checked' => 0, 'active_updated' => 0, 'login_updated' => 0, 'errors' => 0];

    foreach ($local_users as $user) {
        $local_id     = (int)$user['id'];
        $ms_id        = $user['microsoft_id'];
        $local_active = (bool)(int)$user['is_active'];
        $local_email  = $user['email'] ?? '';
        $local_name   = $user['name'] ?? '';

        $ts = date('Y-m-d H:i:s');

        // Wywołanie Graph API
        $az_user = $graph->get_user_by_id($ms_id);

        $stats['checked']++;

        // ── Konto usunięte lub niedostępne (get_user_by_id zwraca []) ─────────
        if (empty($az_user) || empty($az_user['id'])) {
            if ($local_active) {
                db_update('users', ['is_active' => 0], $local_id);
                echo "[{$ts}] {$local_name} ({$local_email}) — konto niedostępne w Azure (404), ustawiam is_active=0 lokalnie\n";
                // Loguj do contract_audit_log jeśli funkcja dostępna
                _reverse_log($local_name, $local_email, 'konto niedostępne w Azure (404) → is_active=0');
                $stats['active_updated']++;
            }
            usleep(200000);
            continue;
        }

        $az_enabled = (bool)($az_user['accountEnabled'] ?? false);
        $az_upn     = $az_user['userPrincipalName'] ?? '';
        $az_display = $az_user['displayName'] ?? '';

        // ── Sprawdź zmianę accountEnabled ────────────────────────────────────
        if ($az_enabled !== $local_active) {
            db_update('users', ['is_active' => $az_enabled ? 1 : 0], $local_id);
            $stan_az  = $az_enabled  ? 'włączone' : 'wyłączone';
            $stan_lok = $local_active ? 'aktywny'  : 'nieaktywny';
            echo "[{$ts}] {$az_upn} — konto {$stan_az} w Azure, aktualizuję lokalnie (było: {$stan_lok})\n";
            _reverse_log($az_upn, $local_email, "konto {$stan_az} w Azure → is_active=" . ($az_enabled ? '1' : '0'));
            $stats['active_updated']++;
        }

        // ── Sprawdź zmianę userPrincipalName (m365_login) w tabelach umów ───
        if (!empty($az_upn)) {
            foreach ($tables_with_login as $table) {
                try {
                    $contracts = db_all(
                        "SELECT id, m365_login FROM {$table} WHERE m365_user_id = ? AND (m365_login IS NULL OR m365_login != ?)",
                        [$ms_id, $az_upn]
                    );
                    foreach ($contracts as $contract) {
                        $old_login = $contract['m365_login'] ?? '(brak)';
                        try {
                            db_update($table, ['m365_login' => $az_upn], (int)$contract['id']);
                            echo "[{$ts}] {$az_upn} — aktualizacja m365_login w {$table} #{$contract['id']} (było: {$old_login})\n";
                            _reverse_log($az_upn, $local_email, "zmiana m365_login: {$old_login} → {$az_upn} w {$table}#{$contract['id']}");
                            $stats['login_updated']++;
                        } catch (\Throwable $e) {
                            echo "[{$ts}] WARN: nie można zaktualizować m365_login w {$table}#{$contract['id']}: " . $e->getMessage() . "\n";
                        }
                    }
                } catch (\Throwable $e) {
                    // Tabela może nie istnieć lub nie mieć kolumny — pomijamy
                }
            }
        }

        // Rate limiting: maks. 5 wywołań/sekundę
        usleep(200000);
    }

    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Synchronizacja odwrotna zakończona.\n";
    echo "  Sprawdzono: {$stats['checked']}, aktywność zaktualizowana: {$stats['active_updated']}, loginy zaktualizowane: {$stats['login_updated']}, błędy: {$stats['errors']}\n";

} catch (\Throwable $e) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] BŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Pomocnicza funkcja logowania ─────────────────────────────────────────────

function _reverse_log(string $user_label, string $email, string $note): void {
    try {
        // Znajdź ID użytkownika systemowego o roli admin lub użyj 0 dla procesów automatycznych
        $sys_user = db_one("SELECT id FROM users WHERE role = 'admin' LIMIT 1") ?? ['id' => 0];
        $sys_uid  = (int)($sys_user['id'] ?? 0);

        // Spróbuj zalogować przez contract_audit_log (jeśli tabela istnieje)
        // Używamy type='system', id=0 (brak konkretnej umowy) dla wpisów globalnych
        if (function_exists('log_contract_action')) {
            // log_contract_action nie obsługuje type=system, więc logujemy do tabeli bezpośrednio
        }

        // Bezpośredni zapis do contract_audit_log
        $db = db();
        $db->prepare(
            "INSERT INTO contract_audit_log (contract_type, contract_id, user_id, action, note, created_at)
             VALUES ('system', 0, ?, 'm365_reverse_sync', ?, ?)"
        )->execute([$sys_uid, "[{$user_label}] {$note}", date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        // Logowanie opcjonalne — nie przerywamy synchronizacji
    }
}
