<?php
/**
 * Pobieranie instalatora EZD Client.
 *   ?os=mac|win     — najnowszy instalator systemu (dmg/exe)
 *   ?ext=dmg|pkg|exe|msi — najnowszy plik danego typu
 * Tylko dla zalogowanych z dostępem do EZD. Nazwa pliku nigdy nie pochodzi z żądania (brak path traversal).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';
require_once dirname(__DIR__, 2) . '/includes/ezd_client.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$files = ezd_client_files();
$ext   = strtolower((string)($_GET['ext'] ?? ''));
if ($ext === '') {
    $ext = match ((string)($_GET['os'] ?? '')) { 'mac' => 'dmg', 'win' => 'exe', default => '' };
}
$f = $files[$ext] ?? null;
if (!$f) {
    http_response_code(404);
    exit('Instalator nie jest jeszcze dostępny. Skontaktuj się z administratorem.');
}

$path = ezd_client_dir() . $f['file'];
$real = realpath($path);
if ($real === false || !str_starts_with($real, realpath(ezd_client_dir()) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Nie znaleziono pliku.');
}

if (function_exists('ezd_log')) {
    try { ezd_log(null, null, null, null, (int)current_user()['id'], 'client_download', 'Pobrano ' . $f['file']); } catch (\Throwable $e) {}
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $f['file']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;
