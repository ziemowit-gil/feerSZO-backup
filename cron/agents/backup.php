#!/usr/bin/env php
<?php
/**
 * cron/agents/backup.php — Przyrostowa kopia zapasowa bazy SQLite + pliki uploads.
 *
 * Uruchamiany przez cron/dispatcher.php (co 4h, całą dobę).
 * Można też uruchomić ręcznie: php cron/agents/backup.php
 *
 * Tryb przyrostowy: baza pomijana, jeśli nie zmieniła się od ostatniego
 * backupu; uploads archiwizowane tylko jako pliki zmienione od ostatniego
 * backupu (znaczniki w BASE_DIR/backups/.last_*).
 *
 * Wyniki trafiają do BASE_DIR/backups/YYYY-MM/
 * Rotacja: zawsze zachowywane min. 3 ostatnie kopie każdego typu,
 * pozostałe starsze niż 30 dni są usuwane.
 */

define('APP_CLI', true);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$base     = rtrim(dirname(__DIR__, 2), '/');
$bak_root = $base . '/backups';
$bak_dir  = $bak_root . '/' . date('Y-m');

if (!is_dir($bak_dir) && !mkdir($bak_dir, 0750, true)) {
    echo "[ERROR] Nie można utworzyć katalogu: {$bak_dir}\n";
    exit(1);
}

$now     = time();
$stamp   = date('Ymd_His', $now);
$ok      = true;

$marker_db      = $bak_root . '/.last_db_mtime';
$marker_uploads = $bak_root . '/.last_uploads_ts';

// ── 1. Backup bazy SQLite (pomijany, jeśli baza się nie zmieniła) ─────────
$db_src  = defined('DB_PATH') ? DB_PATH : ($base . '/umowy.db');
$db_bak  = $bak_dir . '/umowy_' . $stamp . '.db';

if (file_exists($db_src)) {
    $db_mtime      = filemtime($db_src);
    $last_db_mtime = file_exists($marker_db) ? (int)file_get_contents($marker_db) : 0;

    if ($db_mtime <= $last_db_mtime) {
        echo "[SKIP] Baza bez zmian od ostatniego backupu — pomijam.\n";
    } else {
        try {
            // VACUUM INTO tworzy atomową kopię — bezpieczne przy otwartej bazie
            $pdo = new PDO('sqlite:' . $db_src);
            $pdo->exec("VACUUM INTO " . $pdo->quote($db_bak));
            file_put_contents($marker_db, $db_mtime);
            $size = round(filesize($db_bak) / 1024);
            echo "[OK] Baza → {$db_bak} ({$size} KB)\n";
        } catch (\Throwable $e) {
            echo "[ERROR] Backup bazy: " . $e->getMessage() . "\n";
            $ok = false;
        }
    }
} else {
    echo "[WARN] Baza nie znaleziona: {$db_src}\n";
}

// ── 2. Backup uploads (przyrostowo — tylko pliki zmienione od ostatniego backupu) ──
$uploads_src     = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/') : ($base . '/uploads');
$tar_bak         = $bak_dir . '/uploads_' . $stamp . '.tar.gz';
$last_uploads_ts = file_exists($marker_uploads) ? (int)file_get_contents($marker_uploads) : 0;

if (is_dir($uploads_src)) {
    // Pierwsze uruchomienie (brak znacznika) → pełny backup wszystkich plików.
    // Wyszukiwanie zmienionych plików w PHP (nie `find -newermt`, niedostępne na BSD/macOS).
    $changed = [];
    $u_it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads_src, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($u_it as $file) {
        if (!$file->isFile()) continue;
        if ($file->getMTime() > $last_uploads_ts) {
            $changed[] = $file->getPathname();
        }
    }

    if (!$changed) {
        echo "[SKIP] Uploads bez zmian od ostatniego backupu — pomijam.\n";
    } else {
        $list_file = tempnam(sys_get_temp_dir(), 'bak_list_');
        $prefix    = basename($uploads_src) . '/';
        $rel_paths = array_map(
            fn($f) => $prefix . ltrim(substr($f, strlen($uploads_src)), '/'),
            $changed
        );
        file_put_contents($list_file, implode("\n", $rel_paths));

        $cmd = 'tar -czf ' . escapeshellarg($tar_bak)
             . ' -C ' . escapeshellarg(dirname($uploads_src))
             . ' -T ' . escapeshellarg($list_file)
             . ' 2>&1';
        $output = [];
        $ret    = 0;
        exec($cmd, $output, $ret);
        unlink($list_file);

        if ($ret === 0) {
            file_put_contents($marker_uploads, $now);
            $size = round(filesize($tar_bak) / 1024);
            echo "[OK] Uploads (przyrostowo, " . count($changed) . " plik(ów)) → {$tar_bak} ({$size} KB)\n";
        } else {
            echo "[ERROR] tar: " . implode(' ', $output) . "\n";
            $ok = false;
        }
    }
} else {
    echo "[WARN] Katalog uploads nie istnieje: {$uploads_src}\n";
}

// ── 3. Rotacja — zachowaj min. 3 ostatnie kopie każdego typu (db/gz),
//      pozostałe starsze niż 30 dni usuń ─────────────────────────────────
$deleted = 0;
$groups  = ['db' => [], 'gz' => []];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($bak_root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!isset($groups[$ext])) continue;
    $groups[$ext][] = $file->getPathname();
}
foreach ($groups as $files) {
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // najnowsze pierwsze
    foreach (array_slice($files, 3) as $path) { // zawsze zachowaj 3 najnowsze
        if ($now - filemtime($path) > 30 * 86400) {
            unlink($path);
            $deleted++;
        }
    }
}
if ($deleted) echo "[OK] Usunięto {$deleted} starych backupów (zachowano min. 3 ostatnie każdego typu).\n";

echo "[DONE] " . date('Y-m-d H:i:s') . " — " . ($ok ? 'sukces' : 'błędy (patrz wyżej)') . "\n";
exit($ok ? 0 : 1);
