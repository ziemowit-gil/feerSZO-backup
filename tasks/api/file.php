<?php
/**
 * API: Podgląd / pobranie załącznika zadania (task_files, uploads/tasks/) z kontrolą dostępu.
 * GET ?id=N        → inline (obrazy, PDF, tekst), pozostałe typy jako attachment
 * GET ?id=N&dl=1   → zawsze attachment
 *
 * Zastępuje bezpośrednie linki /uploads/tasks/{stored_name}, które były dostępne dla każdego
 * znającego URL — katalog jest teraz zablokowany w uploads/tasks/.htaccess.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';

require_login();

function _tf_fail(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$file_id = (int)($_GET['id'] ?? 0);
if (!$file_id) _tf_fail(400, 'Brak id pliku.');

$file = db_one(
    "SELECT tf.*, t.workspace_id AS t_ws, tl.workspace_id AS l_ws
     FROM task_files tf
     JOIN tasks t ON t.id = tf.task_id
     LEFT JOIN task_lists tl ON tl.id = t.list_id
     WHERE tf.id = ? AND t.deleted_at IS NULL",
    [$file_id]
);
if (!$file) _tf_fail(404, 'Plik nie istnieje.');

$ws_id   = (int)($file['l_ws'] ?: $file['t_ws']);
$ws_role = task_workspace_role($ws_id);
if (!$ws_role || !task_field_visible('files', $ws_role)) _tf_fail(403, 'Brak dostępu do pliku.');

// stored_name generujemy sami, ale na wszelki wypadek odcinamy ścieżki
$stored = basename((string)$file['stored_name']);
$path   = dirname(__DIR__, 2) . '/uploads/tasks/' . $stored;
if ($stored === '' || !is_file($path)) _tf_fail(404, 'Plik nie istnieje na serwerze.');

$mime = (string)($file['mime_type'] ?: 'application/octet-stream');

// Miniatura (okładka karty na Kanbanie) — tylko obrazy, fallback na oryginał
if (!empty($_GET['thumb']) && in_array($mime, TASK_COVER_MIMES, true)) {
    $thumb = task_file_thumb($path, $stored, $mime);
    if ($thumb) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($thumb));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        readfile($thumb);
        exit;
    }
}
// Inline tylko dla typów bezpiecznych do wyświetlenia (bez SVG/HTML — ryzyko XSS)
$inline_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];
$inline = empty($_GET['dl']) && in_array($mime, $inline_mimes, true);
if ($inline && $mime === 'text/csv') $mime = 'text/plain';

$name     = (string)$file['original_name'];
$fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'plik';

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') ? '; charset=utf-8' : ''));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
// sandbox blokuje wbudowaną przeglądarkę PDF w Chrome — dla PDF pomijamy
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
