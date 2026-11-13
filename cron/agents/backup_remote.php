#!/usr/bin/env php
<?php
/**
 * cron/agents/backup_remote.php — wysyłka nowych kopii lokalnych na serwer zdalny (SFTP / rsync).
 *
 * Uruchamiany przez cron/dispatcher.php (co godzinę). Wysyła kopie z backups/RRRR-MM/, których jeszcze nie ma
 * na serwerze; bez żadnej pełnej kopii na serwerze robi najpierw pełną — patrz modules/backup_remote/lib/backupRemote.php.
 * Niezależny od SharePointa (osobny agent i stan). Pomija cicho (exit 0), gdy cel zdalny jest wyłączony.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/backup_remote/lib/backupRemote.php';

$result = backupRemoteRunIncremental('cron');

if (!empty($result['skipped'])) {
    echo "[SKIP] " . ($result['error'] ?? 'Kopia na serwer zdalny wyłączona.') . "\n";
    exit(0);
}

if ($result['ok']) {
    if (!empty($result['sent'])) {
        foreach ($result['sent'] as $rel) echo "[OK] → serwer zdalny: {$rel}\n";
        echo "[OK] " . (int)$result['files'] . " plik(ów), " . backupRemoteHumanSize((int)$result['bytes'])
           . (!empty($result['first_full']) ? " (pierwsza wysyłka — pełna kopia)" : '') . "\n";
    } else {
        echo "[SKIP] Brak nowych kopii lokalnych do wysłania.\n";
    }
    if (!empty($result['skipped_plain'])) echo "[WARN] Pominięto " . (int)$result['skipped_plain'] . " niezaszyfrowanych kopii (szyfrowanie włączone).\n";
    if (!empty($result['warning'])) echo "[WARN] {$result['warning']}\n";
    exit(0);
}

$msg = $result['error'] ?? 'nieznany błąd';
echo "[ERROR] {$msg}\n";
try {
    backup_alert('Kopia na serwer zdalny nie powiodła się',
        "Wysyłka kopii na serwer zdalny (SFTP/rsync) zakończyła się błędem:\n\n{$msg}",
        'remote_backup_failed', 6);
} catch (\Throwable $e) {}
exit(1);
