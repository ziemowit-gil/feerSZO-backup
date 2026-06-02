#!/usr/bin/env php
<?php
/**
 * cron/agents/backup.php — Kopia zapasowa bazy SQLite + pliki uploads.
 *
 * Uruchamiany przez cron/dispatcher.php (raz dziennie, w nocy).
 * Można też uruchomić ręcznie: php cron/agents/backup.php
 *
 * Wyniki trafiają do BASE_DIR/backups/YYYY-MM/
 * Rotacja: pliki starsze niż 30 dni są usuwane.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$base    = rtrim(dirname(__DIR__, 2), '/');
$bak_dir = $base . '/backups/' . date('Y-m');

if (!is_dir($bak_dir) && !mkdir($bak_dir, 0750, true)) {
    echo "[ERROR] Nie można utworzyć katalogu: {$bak_dir}\n";
    exit(1);
}

$stamp   = date('Ymd_His');
$ok      = true;

// ── 1. Backup bazy SQLite ─────────────────────────────────────────────────
$db_src  = defined('DB_PATH') ? DB_PATH : ($base . '/umowy.db');
$db_bak  = $bak_dir . '/umowy_' . $stamp . '.db';

if (file_exists($db_src)) {
    try {
        // VACUUM INTO tworzy atomową kopię — bezpieczne przy otwartej bazie
        $pdo = new PDO('sqlite:' . $db_src);
        $pdo->exec("VACUUM INTO " . $pdo->quote($db_bak));
        $size = round(filesize($db_bak) / 1024);
        echo "[OK] Baza → {$db_bak} ({$size} KB)\n";
    } catch (\Throwable $e) {
        echo "[ERROR] Backup bazy: " . $e->getMessage() . "\n";
        $ok = false;
    }
} else {
    echo "[WARN] Baza nie znaleziona: {$db_src}\n";
}

// ── 2. Backup uploads ─────────────────────────────────────────────────────
$uploads_src = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/') : ($base . '/uploads');
$tar_bak     = $bak_dir . '/uploads_' . $stamp . '.tar.gz';

if (is_dir($uploads_src)) {
    $cmd    = 'tar -czf ' . escapeshellarg($tar_bak)
            . ' -C ' . escapeshellarg(dirname($uploads_src))
            . ' ' . escapeshellarg(basename($uploads_src))
            . ' 2>&1';
    $output = [];
    $ret    = 0;
    exec($cmd, $output, $ret);
    if ($ret === 0) {
        $size = round(filesize($tar_bak) / 1024);
        echo "[OK] Uploads → {$tar_bak} ({$size} KB)\n";
    } else {
        echo "[ERROR] tar: " . implode(' ', $output) . "\n";
        $ok = false;
    }
} else {
    echo "[WARN] Katalog uploads nie istnieje: {$uploads_src}\n";
}

// ── 3. Rotacja — usuń backupy starsze niż 30 dni ─────────────────────────
$deleted = 0;
$bak_root = $base . '/backups';
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($bak_root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, ['db', 'gz'], true)) continue;
    if (time() - $file->getMTime() > 30 * 86400) {
        unlink($file->getPathname());
        $deleted++;
    }
}
if ($deleted) echo "[OK] Usunięto {$deleted} starych backupów.\n";

echo "[DONE] " . date('Y-m-d H:i:s') . " — " . ($ok ? 'sukces' : 'błędy (patrz wyżej)') . "\n";
exit($ok ? 0 : 1);
