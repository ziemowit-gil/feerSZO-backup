#!/usr/bin/env php
<?php
/**
 * cron/agents/sp_backup_full.php — Pełny backup całego systemu na SharePoint.
 *
 * Uruchamiany przez cron/dispatcher.php (raz na dobę, w nocy). Wysyła pełną
 * kopię bazy (VACUUM INTO) i całego katalogu uploads, niezależnie od zmian —
 * patrz sp_backup_full() w includes/m365.php. Pomija cicho (exit 0), jeśli
 * backup SharePoint nie jest skonfigurowany.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';

$result = sp_backup_full();

if (!empty($result['skipped'])) {
    echo "[SKIP] " . ($result['error'] ?? 'SharePoint backup wyłączony.') . "\n";
    exit(0);
}

if ($result['ok']) {
    foreach ($result['sent'] as $path) echo "[OK] → SP: {$path}\n";
    exit(0);
}

echo "[ERROR] " . ($result['error'] ?? 'nieznany błąd') . "\n";
exit(1);
