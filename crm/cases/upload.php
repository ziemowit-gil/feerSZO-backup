<?php
/**
 * crm/cases/upload.php — AJAX upload pliku do sprawy CRM.
 * POST multipart: case_id, _csrf, file (plik), file_display_name?, file_description?
 * Odpowiada JSON {ok, file:{id,name,size,ext,created_at,download_url}} lub {error}
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

function je(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') je('Metoda niedozwolona', 405);
if (!current_user()) je('Brak sesji', 401);
if (!can_write('crm') && !is_admin()) je('Brak uprawnień', 403);

// CSRF
if (!csrf_verify($_POST['_csrf'] ?? '')) je('Błąd CSRF', 403);

$case_id = (int)($_POST['case_id'] ?? 0);
if (!$case_id) je('Brak case_id');

$case = db_one("SELECT * FROM crm_cases WHERE id=?", [$case_id]);
if (!$case) je('Sprawa nie istnieje', 404);
if (!crm_case_can_edit($case)) je('Brak dostępu do sprawy', 403);

$f = $_FILES['file'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
    $err_map = [
        UPLOAD_ERR_INI_SIZE   => 'Plik zbyt duży (limit serwera).',
        UPLOAD_ERR_FORM_SIZE  => 'Plik zbyt duży.',
        UPLOAD_ERR_PARTIAL    => 'Upload niekompletny.',
        UPLOAD_ERR_NO_FILE    => 'Brak pliku.',
        UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego.',
        UPLOAD_ERR_CANT_WRITE => 'Błąd zapisu na dysk.',
    ];
    je($err_map[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Błąd uploadu.');
}

$ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$allowed = ['pdf','docx','doc','xlsx','xls','csv','txt','jpg','jpeg','png','gif','zip'];
if (!in_array($ext, $allowed)) je('Niedozwolone rozszerzenie: .' . $ext);

$dir = dirname(dirname(__DIR__)) . '/uploads/crm_cases/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$stored    = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 8) . '.' . $ext;
$disp      = trim($_POST['file_display_name'] ?? '') ?: $f['name'];
$file_desc = trim($_POST['file_description'] ?? '');

if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) je('Nie udało się zapisać pliku.', 500);

$new_id = db_insert('crm_case_files', [
    'case_id'       => $case_id,
    'original_name' => $f['name'],
    'stored_path'   => 'crm_cases/' . $stored,
    'display_name'  => $disp,
    'description'   => $file_desc ?: null,
    'file_size'     => $f['size'],
    'created_by'    => current_user()['id'],
    'created_at'    => date('Y-m-d H:i:s'),
]);

echo json_encode([
    'ok'   => true,
    'file' => [
        'id'           => $new_id,
        'display_name' => $disp,
        'original_name'=> $f['name'],
        'description'  => $file_desc,
        'file_size'    => $f['size'],
        'ext'          => $ext,
        'created_at'   => date('Y-m-d H:i:s'),
        'download_url' => APP_URL . '/crm/cases/download.php?id=' . $new_id,
        'can_delete'   => true,
    ],
], JSON_UNESCAPED_UNICODE);
