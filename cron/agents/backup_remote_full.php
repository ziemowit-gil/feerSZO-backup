#!/usr/bin/env php
<?php
/**
 * cron/agents/backup_remote_full.php — pełna kopia całego systemu na serwer zdalny (SFTP / rsync).
 *
 * Uruchamiany przez cron/dispatcher.php raz na dobę w nocy; sam sprawdza, czy pełna kopia jest potrzebna
 * (co noc albo co 7 dni — ustawienie backup_remote_full_every). Po wysyłce: retencja na serwerze.
 * Niezależny od SharePointa. Pomija cicho (exit 0), gdy cel zdalny jest wyłączony albo kopia niepotrzebna.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/backup_remote/lib/backupRemote.php';

$result = backupRemoteRunFull('cron');

if (!empty($result['skipped'])) {
    echo "[SKIP] " . ($result['error'] ?? 'Pominięto.') . "\n";
    exit(0);
}

if ($result['ok']) {
    foreach ($result['sent'] as $rel) echo "[OK] → serwer zdalny: {$rel}\n";
    echo "[OK] " . (int)$result['files'] . " plik(ów), " . backupRemoteHumanSize((int)$result['bytes']) . "\n";
    if (!empty($result['warning'])) echo "[INFO] {$result['warning']}\n";
    exit(0);
}

$msg = $result['error'] ?? 'nieznany błąd';
echo "[ERROR] {$msg}\n";
try {
    backup_alert('Pełna kopia na serwer zdalny nie powiodła się',
        "Nocna pełna kopia na serwer zdalny (SFTP/rsync) zakończyła się błędem:\n\n{$msg}",
        'remote_backup_full_failed', 6);
} catch (\Throwable $e) {}
exit(1);
