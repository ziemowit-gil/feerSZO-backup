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
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once dirname(__DIR__, 2) . '/includes/backup.php';

$result = sp_backup_full();

if (!empty($result['skipped'])) {
    echo "[SKIP] " . ($result['error'] ?? 'SharePoint backup wyłączony.') . "\n";
    exit(0);
}

if ($result['ok']) {
    foreach ($result['sent'] as $path) echo "[OK] → SP: {$path}\n";
    sp_backup_mark_ok();
    // Retencja kopii na SharePoint (po pełnym backupie, raz na dobę).
    try {
        $ret = sp_backup_retention();
        if (!empty($ret['ok'])) echo "[OK] Retencja SP: usunięto {$ret['deleted']} z {$ret['scanned']} kopii.\n";
    } catch (\Throwable $e) {
        echo "[WARN] Retencja SP: " . $e->getMessage() . "\n";
    }
    exit(0);
}

$msg = $result['error'] ?? 'nieznany błąd';
echo "[ERROR] {$msg}\n";
try {
    backup_alert('Backup SharePoint (pełny) nie powiódł się',
        "Nocny pełny backup na SharePoint zakończył się błędem:\n\n{$msg}",
        'sp_backup_full_failed', 6);
} catch (\Throwable $e) {}
exit(1);
