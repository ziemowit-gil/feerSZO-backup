<?php
/**
 * Procesor kolejki synchronizacji M365.
 * Uruchamiany z poziomu CLI (cron), np. co 5 minut.
 *
 * Logika:
 *  1. Pobiera do 50 nieprzetworzone wpisy z m365_sync_queue.
 *  2. Dla każdego: wywołuje Graph API get_user_by_id(), aktualizuje lokalną tabelę users.
 *  3. Oznacza wpis jako przetworzony (processed_at = teraz).
 *
 * Rate limit: usleep(200000) między wywołaniami API.
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
echo "[{$now}] Procesowanie kolejki M365 — start\n";

// Upewnij się, że tabela istnieje (na wypadek gdyby webhook nie był jeszcze wywołany)
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS m365_sync_queue (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      TEXT    NOT NULL,
            queued_at    TEXT    NOT NULL,
            processed_at TEXT    NULL
        )
    ");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_m365q_uid_proc ON m365_sync_queue (user_id, processed_at)");
} catch (\Throwable $e) {
    // Tabela już istnieje
}

try {
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        throw new RuntimeException('M365 nie jest skonfigurowane (brak tenant_id / client_id / client_secret).');
    }

    // Pobierz do 50 nieprzetworzone wpisy
    $queue = db_all(
        "SELECT id, user_id, queued_at FROM m365_sync_queue WHERE processed_at IS NULL ORDER BY queued_at ASC LIMIT 50"
    );

    if (empty($queue)) {
        echo "[{$now}] Kolejka pusta — nic do przetworzenia.\n";
        exit(0);
    }

    echo "[{$now}] W kolejce: " . count($queue) . " wpisów.\n";

    $stats = ['processed' => 0, 'updated' => 0, 'not_found' => 0, 'errors' => 0];

    foreach ($queue as $entry) {
        $entry_id  = (int)$entry['id'];
        $az_user_id = $entry['user_id'];
        $ts = date('Y-m-d H:i:s');

        try {
            // Znajdź lokalny rekord użytkownika
            $local_user = db_one(
                "SELECT id, name, email, is_active FROM users WHERE microsoft_id = ?",
                [$az_user_id]
            );

            if (!$local_user) {
                echo "[{$ts}] Użytkownik Azure {$az_user_id} — brak w lokalnej bazie, pomijam.\n";
                $stats['not_found']++;
                _mark_processed($entry_id);
                usleep(200000);
                continue;
            }

            $local_id     = (int)$local_user['id'];
            $local_active = (bool)(int)$local_user['is_active'];
            $local_name   = $local_user['name'] ?? '';
            $local_email  = $local_user['email'] ?? '';

            // Pobierz dane z Azure
            $az_user = $graph->get_user_by_id($az_user_id);

            if (empty($az_user) || empty($az_user['id'])) {
                // Konto usunięte / niedostępne
                if ($local_active) {
                    db_update('users', ['is_active' => 0], $local_id);
                    echo "[{$ts}] {$local_name} ({$local_email}) — konto niedostępne w Azure, ustawiam is_active=0\n";
                    $stats['updated']++;
                } else {
                    echo "[{$ts}] {$local_name} ({$local_email}) — konto niedostępne w Azure (już nieaktywne lokalnie)\n";
                }
                _mark_processed($entry_id);
                $stats['processed']++;
                usleep(200000);
                continue;
            }

            $az_enabled = (bool)($az_user['accountEnabled'] ?? false);
            $az_upn     = $az_user['userPrincipalName'] ?? '';

            $changes = [];

            // Sprawdź zmianę is_active
            if ($az_enabled !== $local_active) {
                $changes['is_active'] = $az_enabled ? 1 : 0;
                $stan = $az_enabled ? 'włączone' : 'wyłączone';
                echo "[{$ts}] {$az_upn} — konto {$stan} w Azure, aktualizuję is_active\n";
            }

            if (!empty($changes)) {
                db_update('users', $changes, $local_id);
                $stats['updated']++;
            } else {
                echo "[{$ts}] {$az_upn} — brak zmian\n";
            }

            // Aktualizuj m365_login w tabelach umów jeśli UPN zmienił się
            if (!empty($az_upn)) {
                foreach (['umowy_wolontariat', 'umowy_zlecenie', 'umowy_dzielo'] as $table) {
                    try {
                        $outdated = db_all(
                            "SELECT id, m365_login FROM {$table} WHERE m365_user_id = ? AND (m365_login IS NULL OR m365_login != ?)",
                            [$az_user_id, $az_upn]
                        );
                        foreach ($outdated as $row) {
                            try {
                                db_update($table, ['m365_login' => $az_upn], (int)$row['id']);
                                echo "[{$ts}] {$az_upn} — zaktualizowano m365_login w {$table}#{$row['id']}\n";
                            } catch (\Throwable $e) {
                                // Ignoruj błędy aktualizacji loginu — nie blokujemy kolejki
                            }
                        }
                    } catch (\Throwable $e) {
                        // Tabela może nie istnieć lub nie mieć kolumny
                    }
                }
            }

            _mark_processed($entry_id);
            $stats['processed']++;

        } catch (\Throwable $e) {
            $ts = date('Y-m-d H:i:s');
            echo "[{$ts}] BŁĄD dla {$az_user_id}: " . $e->getMessage() . "\n";
            $stats['errors']++;
            // Nie oznaczamy jako przetworzone — zostanie ponowione przy następnym uruchomieniu
        }

        usleep(200000);
    }

    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Procesowanie kolejki zakończone.\n";
    echo "  Przetworzonych: {$stats['processed']}, zaktualizowanych: {$stats['updated']}, nieznanych: {$stats['not_found']}, błędy: {$stats['errors']}\n";

} catch (\Throwable $e) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] BŁĄD KRYTYCZNY: " . $e->getMessage() . "\n";
    exit(1);
}

function _mark_processed(int $entry_id): void {
    db()->prepare("UPDATE m365_sync_queue SET processed_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $entry_id]);
}
