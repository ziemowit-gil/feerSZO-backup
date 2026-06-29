<?php
/**
 * karty30/pfron/upload_doc.php — Wgranie skanu podpisanej umowy PFRON.
 *
 * POST multipart: pfron_id, _csrf, plik `signed_doc`
 * Odpowiedź JSON: { ok, path, url } lub { ok:false, error [, ika_expired] }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}

k30_require_access();
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Metoda niedozwolona', 405);
if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) jerr('Nieprawidłowy token CSRF.', 403);

$pfron_id = (int)($_POST['pfron_id'] ?? 0);
if (!$pfron_id) jerr('Brak ID umowy PFRON.');

// IKA — upload dokumentu z danymi osobowymi
$_ika_ts = (int)($_SESSION['_ika_ts'] ?? 0);
if (function_exists('ika_require') && $_ika_ts > 0 && (time() - $_ika_ts) >= 1800) {
    jerr('Sesja IKA wygasła. Odśwież stronę i zaloguj się ponownie.', 403, ['ika_expired' => true]);
}

$pfron = k30_pfron_contract_get($pfron_id);
if (!$pfron) jerr('Umowa PFRON nie istnieje.');

// Wgraj plik — obsługa przez handle_upload (PDF/JPG/PNG, max 20 MB)
// Dozwól też webp przez chwilowe rozszerzenie dozwolonego zestawu
$file = $_FILES['signed_doc'] ?? null;
if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
    jerr('Brak pliku lub błąd przesyłania (kod: ' . ($file['error'] ?? '?') . ').');
}
$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
    jerr('Niedozwolony format. Akceptowane: PDF, JPG, PNG, WebP.');
}
if ($file['size'] > 20 * 1024 * 1024) jerr('Plik za duży (max 20 MB).');

// Nadaj nazwę: pfron-AS_XX_YYYY-<rand>.<ext>
$safe_num = $pfron['doc_number']
    ? preg_replace('/[^A-Za-z0-9\-]/', '_', $pfron['doc_number'])
    : 'pfron-' . $pfron_id;
$filename = 'pfron_umowa_' . $safe_num . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
$subdir   = 'contract_docs';
$dir      = UPLOAD_DIR . $subdir . '/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$dest = $dir . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) jerr('Błąd zapisu pliku na serwerze.', 500);

$rel_path = $subdir . '/' . $filename;

// Usuń poprzedni plik jeśli istniał
if (!empty($pfron['signed_doc_path'])) {
    $old = UPLOAD_DIR . $pfron['signed_doc_path'];
    if (is_file($old)) @unlink($old);
}

db()->prepare(
    "UPDATE k30_pfron_contracts SET signed_doc_path=?, updated_at=datetime('now') WHERE id=?"
)->execute([$rel_path, $pfron_id]);

// SP sync
if (function_exists('sp_sync_upload')) {
    try { sp_sync_upload($rel_path); } catch (\Throwable $e) {
        error_log('[SP sync pfron] ' . $e->getMessage());
    }
}

echo json_encode([
    'ok'   => true,
    'path' => $rel_path,
    'url'  => APP_URL . '/uploads/' . $rel_path,
    'name' => $filename,
]);
