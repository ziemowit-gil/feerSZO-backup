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
require_once dirname(__DIR__, 2) . '/includes/backup.php';

$encrypt = backup_encryption_enabled();
if ($encrypt) echo "[INFO] Szyfrowanie kopii: WŁĄCZONE (AES-256).\n";

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
$err_log = '';

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
            // Kontrola integralności świeżej kopii (na plaintext, przed szyfrowaniem).
            $db_integrity = 'unknown';
            try {
                $chk = new PDO('sqlite:' . $db_bak);
                $r = $chk->query('PRAGMA integrity_check')->fetch(PDO::FETCH_NUM);
                $chk = null;
                $db_integrity = ($r && strtolower((string)$r[0]) === 'ok') ? 'ok' : 'fail';
            } catch (\Throwable $e) { $db_integrity = 'fail'; }
            if ($db_integrity !== 'ok') { echo "[ERROR] integrity_check świeżej kopii bazy: {$db_integrity}\n"; $err_log .= "integrity_check bazy: {$db_integrity}\n"; $ok = false; }
            if ($encrypt) {
                $enc = backup_encrypt_file($db_bak);
                if ($enc) { $db_bak = $enc; } else { echo "[WARN] Nie udało się zaszyfrować bazy — zostaje niezaszyfrowana.\n"; }
            }
            backup_manifest_record($db_bak, ['db_integrity' => $db_integrity]);
            $size = round(filesize($db_bak) / 1024);
            echo "[OK] Baza → {$db_bak} ({$size} KB)\n";
        } catch (\Throwable $e) {
            echo "[ERROR] Backup bazy: " . $e->getMessage() . "\n";
            $err_log .= 'Backup bazy: ' . $e->getMessage() . "\n";
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
            if ($encrypt) {
                $enc = backup_encrypt_file($tar_bak);
                if ($enc) { $tar_bak = $enc; } else { echo "[WARN] Nie udało się zaszyfrować uploads — zostają niezaszyfrowane.\n"; }
            }
            backup_manifest_record($tar_bak);
            $size = round(filesize($tar_bak) / 1024);
            echo "[OK] Uploads (przyrostowo, " . count($changed) . " plik(ów)) → {$tar_bak} ({$size} KB)\n";
        } else {
            echo "[ERROR] tar: " . implode(' ', $output) . "\n";
            $err_log .= 'tar uploads: ' . implode(' ', $output) . "\n";
            $ok = false;
        }
    }
} else {
    echo "[WARN] Katalog uploads nie istnieje: {$uploads_src}\n";
}

// ── 2b. Backup katalogu certs/ (klucze prywatne — pełny tar, gdy zmieniony) ──
//      Certyfikaty i klucze zmieniają się rzadko; archiwizujemy CAŁY katalog,
//      gdy cokolwiek się w nim zmieniło od ostatniego backupu. Zdecydowanie
//      zalecane wraz z włączonym szyfrowaniem (zawiera klucze prywatne).
$certs_src      = $base . '/certs';
$marker_certs   = $bak_root . '/.last_certs_ts';
$last_certs_ts  = file_exists($marker_certs) ? (int)file_get_contents($marker_certs) : 0;
if (is_dir($certs_src)) {
    $certs_changed = false;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($certs_src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getMTime() > $last_certs_ts) { $certs_changed = true; break; }
    }
    if (!$certs_changed) {
        echo "[SKIP] Certs bez zmian od ostatniego backupu — pomijam.\n";
    } else {
        $certs_bak = $bak_dir . '/certs_' . $stamp . '.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($certs_bak)
             . ' -C ' . escapeshellarg($base) . ' certs 2>&1';
        $output = []; $ret = 0; exec($cmd, $output, $ret);
        if ($ret === 0) {
            file_put_contents($marker_certs, $now);
            if ($encrypt) {
                $enc = backup_encrypt_file($certs_bak);
                if ($enc) { $certs_bak = $enc; } else { echo "[WARN] Nie udało się zaszyfrować certs — zostają niezaszyfrowane.\n"; }
            }
            backup_manifest_record($certs_bak);
            $size = round(filesize($certs_bak) / 1024);
            echo "[OK] Certs → {$certs_bak} ({$size} KB)\n";
        } else {
            echo "[ERROR] tar (certs): " . implode(' ', $output) . "\n";
            $err_log .= 'tar certs: ' . implode(' ', $output) . "\n";
            $ok = false;
        }
    }
} else {
    echo "[INFO] Katalog certs nie istnieje — pomijam.\n";
}

// ── 3. Rotacja — zachowaj min. 3 ostatnie kopie każdego typu (db/gz),
//      pozostałe starsze niż 30 dni usuń ─────────────────────────────────
$deleted = 0;
$groups  = ['db' => [], 'uploads' => [], 'certs' => []];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($bak_root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    // Rozpoznaj typ kopii po nazwie (obejmuje warianty .enc: db/uploads/certs).
    $kind = backup_kind($file->getFilename());
    if ($kind === null || !isset($groups[$kind])) continue;
    $groups[$kind][] = $file->getPathname();
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

// Synchronizuj manifest z rzeczywistością (usuń wpisy po zrotowanych plikach).
$pruned = backup_manifest_prune();
if ($pruned) echo "[OK] Manifest: usunięto {$pruned} nieaktualnych wpisów.\n";

// Stan przebiegu: znacznik sukcesu albo alert do administratorów.
if ($ok) {
    backup_mark_run_ok();
} else {
    try {
        backup_alert(
            'Backup nie powiódł się',
            "Agent kopii zapasowych zgłosił błędy o " . date('Y-m-d H:i') . ":\n\n" . ($err_log ?: 'Szczegóły w logu CRON.'),
            'backup_failed', 3
        );
        echo "[INFO] Wysłano alert o niepowodzeniu do administratorów.\n";
    } catch (\Throwable $e) {
        echo "[WARN] Nie udało się wysłać alertu: " . $e->getMessage() . "\n";
    }
}

echo "[DONE] " . date('Y-m-d H:i:s') . " — " . ($ok ? 'sukces' : 'błędy (patrz wyżej)') . "\n";
exit($ok ? 0 : 1);
