<?php
/**
 * AJAX: odczyt danych faktury ze skanu/PDF przez AI (Anthropic) do formularza edok/add.php.
 * POST: file (upload) albo queue_id (plik z kolejki „do opisania"). JSON: { ok, data, warnings, file_path? } | { ok:false, error }.
 * Wynik to tylko podpowiedź — użytkownik sprawdza i sam składa dokument.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_queue.php';
require_once __DIR__ . '/../includes/edok_invoice_ocr.php';

header('Content-Type: application/json; charset=utf-8');
function ocr_err(string $m): never { echo json_encode(['ok' => false, 'error' => $m]); exit; }

require_login();
if (!edok_has_role('upload') && !is_admin()) ocr_err('Brak uprawnień.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ocr_err('Metoda niedozwolona.');
csrf_check();
edok_migrate();

$saved = null;
if ((int)($_POST['queue_id'] ?? 0) > 0) {
    $q = db_one("SELECT file_path FROM edok_queue WHERE id = ?", [(int)$_POST['queue_id']]);
    if (!$q) ocr_err('Nie ma takiego pliku w kolejce.');
    $abs = UPLOAD_DIR . ltrim((string)$q['file_path'], '/');
} else {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) ocr_err('Wybierz plik (PDF, JPG lub PNG).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) ocr_err('Odczyt obsługuje PDF, JPG i PNG.');
    if ($f['size'] > EDOK_OCR_MAX_BYTES) ocr_err('Plik jest za duży do odczytu (max 12 MB).');
    $abs = $f['tmp_name'];
    // edok_invoice_ocr() ustala typ po rozszerzeniu, a tmp_name go nie ma — kopia z właściwym rozszerzeniem
    $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp/ocr_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!is_dir(dirname($tmp))) @mkdir(dirname($tmp), 0755, true);
    copy($f['tmp_name'], $tmp);
    $abs = $tmp;
    $saved = edok_queue_save_bytes((string)file_get_contents($tmp), $ext === 'jpeg' ? 'jpg' : $ext);
}

$r = edok_invoice_ocr($abs);
if (isset($tmp)) @unlink($tmp);
audit_log('edok.invoice_ocr', ['ok' => $r['ok'], 'queue_id' => (int)($_POST['queue_id'] ?? 0), 'warnings' => count($r['warnings'] ?? [])], (int)(current_user()['id'] ?? 0) ?: null);
if (!$r['ok']) ocr_err($r['error']);
echo json_encode(['ok' => true, 'data' => $r['data'], 'warnings' => $r['warnings'], 'file_path' => $saved], JSON_UNESCAPED_UNICODE);
