#!/usr/bin/env php
<?php
/**
 * cron/agents/sp_backup_incremental.php — Przyrostowy backup na SharePoint.
 *
 * Uruchamiany przez cron/dispatcher.php (co 6h). Wysyła tylko bazę i pliki
 * uploads zmienione od ostatniej synchronizacji SP — patrz sp_backup_incremental()
 * w includes/m365.php. Pomija cicho (exit 0), jeśli backup SharePoint nie jest
 * skonfigurowany, żeby nie zaśmiecać logów CRON błędami na instalacjach bez SP.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';

$result = sp_backup_incremental();

if (!empty($result['skipped'])) {
    echo "[SKIP] " . ($result['error'] ?? 'SharePoint backup wyłączony.') . "\n";
    exit(0);
}

if ($result['ok']) {
    if ($result['sent']) {
        foreach ($result['sent'] as $path) echo "[OK] → SP: {$path}\n";
    } else {
        echo "[SKIP] Brak zmian od ostatniej synchronizacji SP.\n";
    }
    exit(0);
}

echo "[ERROR] " . ($result['error'] ?? 'nieznany błąd') . "\n";
exit(1);
