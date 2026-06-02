<?php
/**
 * Backup tenanta — tworzy ZIP z bazą danych i plikami uploads.
 * Jeśli tenant ma skonfigurowaną backup_path → zapisuje plik na dysku.
 * W przeciwnym razie → wysyła plik do pobrania przez przeglądarkę.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION[SAAS_SESSION_KEY])) {
    header('Location: index.php'); exit;
}

$id     = (int)($_GET['id'] ?? 0);
$tenant = $id ? saas_get($id) : null;

// ── Pomocnik błędu ────────────────────────────────────────────────────────────
function bail(string $msg): never {
    http_response_code(400);
    echo '<!DOCTYPE html><html><body><h3 style="color:red">Błąd backupu</h3><p>'
       . htmlspecialchars($msg) . '</p><a href="index.php">← Powrót</a></body></html>';
    exit;
}

if (!$tenant) bail('Nie znaleziono tenanta.');
if (!$tenant['db_ready']) bail('Baza tenanta nie jest zainicjowana.');

$slug    = $tenant['slug'] ?: $tenant['krs'];
$dir     = TENANTS_DIR . '/' . $slug;
$db_path = $dir . '/umowy.db';

if (!is_file($db_path)) bail('Plik bazy danych nie istnieje: ' . $db_path);
if (!class_exists('ZipArchive')) bail('Rozszerzenie PHP ZipArchive nie jest dostępne.');

// ── Katalog uploads ───────────────────────────────────────────────────────────
$upload_dir = rtrim($tenant['upload_dir'] ?: ($dir . '/uploads'), '/');

// ── Nazwa pliku backupu ───────────────────────────────────────────────────────
$stamp    = date('Ymd_His');
$filename = 'backup_' . $slug . '_' . $stamp . '.zip';

// ── Ścieżka docelowa ──────────────────────────────────────────────────────────
$save_path = rtrim($tenant['backup_path'] ?? '', '/');
$save_to_disk = ($save_path !== '' && is_dir($save_path));

$zip_path = $save_to_disk
    ? $save_path . '/' . $filename
    : sys_get_temp_dir() . '/' . $filename;

// ── Utwórz ZIP ────────────────────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    bail('Nie można utworzyć pliku ZIP: ' . $zip_path);
}

// Dodaj bazę danych
$zip->addFile($db_path, 'umowy.db');

// Dodaj uploads rekurencyjnie
if (is_dir($upload_dir)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($upload_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $file) {
        if ($file->isFile()) {
            $rel = 'uploads/' . ltrim(str_replace($upload_dir, '', $file->getPathname()), '/');
            $zip->addFile($file->getPathname(), $rel);
        }
    }
}

// Dodaj tenant.php (bez hasła — usuń ms_client_secret)
$cfg_file = $dir . '/tenant.php';
if (is_file($cfg_file)) {
    $cfg_content = file_get_contents($cfg_file);
    // Maskuj client_secret w backupie
    $cfg_content = preg_replace("/'ms_client_secret'\s*=>\s*'[^']*'/", "'ms_client_secret' => '***'", $cfg_content);
    $zip->addFromString('tenant.php', $cfg_content);
}

// Dodaj metadane backupu
$meta = json_encode([
    'backup_created' => date('c'),
    'tenant_org'     => $tenant['org_name'],
    'tenant_krs'     => $tenant['krs'],
    'tenant_slug'    => $slug,
    'db_size_bytes'  => filesize($db_path),
    'app_version'    => '1.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$zip->addFromString('backup_meta.json', $meta);

$zip->close();

// ── Zapisz na dysku lub wyślij do pobrania ────────────────────────────────────
if ($save_to_disk) {
    $size_kb = round(filesize($zip_path) / 1024, 1);
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
    <meta charset="UTF-8">
    <title>Backup zapisany</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>body{background:#f0f4f8}</style>
    </head>
    <body>
    <div class="container py-5" style="max-width:600px">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h5 class="fw-bold mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i>Backup zapisany</h5>
          <table class="table table-sm small">
            <tr><th>Organizacja</th><td><?= htmlspecialchars($tenant['org_name']) ?></td></tr>
            <tr><th>Plik</th><td class="font-monospace"><?= htmlspecialchars($zip_path) ?></td></tr>
            <tr><th>Rozmiar</th><td><?= $size_kb ?> KB</td></tr>
            <tr><th>Data</th><td><?= date('d.m.Y H:i:s') ?></td></tr>
          </table>
          <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Powrót do panelu
          </a>
        </div>
      </div>
    </div>
    </body></html>
    <?php
} else {
    // Wyślij do pobrania
    $size = filesize($zip_path);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $size);
    header('Pragma: no-cache');
    readfile($zip_path);
    unlink($zip_path);
}
exit;
