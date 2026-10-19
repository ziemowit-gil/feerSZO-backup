<?php
/**
 * crm/cases/api/od_import.php — pobiera plik z OneDrive i dołącza do sprawy CRM.
 * POST JSON {case_id, _csrf, item_id, display_name?} — item_id z okna modules/onedrive_picker;
 * plik pobiera serwer z OneDrive ZALOGOWANEGO użytkownika (OneDrivePicker::fetch()).
 * Dawniej przyjmował download_url od klienta i pobierał go bez sprawdzenia hosta (SSRF) — usunięte.
 * Odpowiada JSON {ok, file:{...}} lub {error}
 */

require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/config.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/includes/db.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/includes/auth.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/includes/functions.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/includes/crm.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/includes/m365.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/modules/onedrive_picker/lib/OneDrivePicker.php';

header('Content-Type: application/json; charset=utf-8');

function je(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') je('Metoda niedozwolona', 405);
if (!current_user()) je('Brak sesji', 401);
if (!can_write('crm') && !is_admin()) je('Brak uprawnień', 403);

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) je('Nieprawidłowe dane wejściowe');

// CSRF
auth_start();
if (($body['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) je('Błąd CSRF — odśwież stronę', 403);

$case_id      = (int)($body['case_id'] ?? 0);
$item_id      = trim((string)($body['item_id'] ?? ''));

if (!$case_id || $item_id === '') je('Brak wymaganych pól');

$case = db_one("SELECT * FROM crm_cases WHERE id=?", [$case_id]);
if (!$case) je('Sprawa nie istnieje', 404);
if (!crm_case_can_edit($case)) je('Brak dostępu do sprawy', 403);

// Pobierz plik z OneDrive użytkownika (Graph po stronie serwera)
try {
    $od = (new OneDrivePicker())->fetch($item_id, 52428800);
} catch (\Throwable $e) {
    je($e->getMessage());
}
$orig_name    = $od['name'];
$data         = $od['bytes'];
$actual_size  = $od['size'];
$display_name = trim($body['display_name'] ?? '') ?: $orig_name;

$ext     = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
$allowed = ['pdf','docx','doc','xlsx','xls','csv','txt','jpg','jpeg','png','gif','zip','pptx','ppt','odt','ods'];
if (!in_array($ext, $allowed)) je('Niedozwolone rozszerzenie: .' . $ext);
if ($actual_size === 0) je('Pobrany plik jest pusty.');

$dir = dirname(dirname(dirname(dirname(__DIR__)))) . '/uploads/crm_cases/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$stored = date('Ymd_His') . '_od_' . substr(bin2hex(random_bytes(6)), 0, 8) . '.' . $ext;
$dest   = $dir . $stored;

if (file_put_contents($dest, $data) === false) je('Błąd zapisu pliku na serwerze.', 500);

$new_id = db_insert('crm_case_files', [
    'case_id'       => $case_id,
    'original_name' => $orig_name,
    'stored_path'   => 'crm_cases/' . $stored,
    'display_name'  => $display_name,
    'description'   => 'Zaimportowano z OneDrive',
    'file_size'     => $actual_size,
    'created_by'    => current_user()['id'],
    'created_at'    => date('Y-m-d H:i:s'),
]);

// Backup na SharePoint (nieblokujące)
try { sp_sync_upload('crm_cases/' . $stored); } catch (\Throwable $e) { /* ignoruj */ }

echo json_encode([
    'ok'   => true,
    'file' => [
        'id'           => $new_id,
        'display_name' => $display_name,
        'original_name'=> $orig_name,
        'description'  => 'Zaimportowano z OneDrive',
        'file_size'    => $actual_size,
        'ext'          => $ext,
        'created_at'   => date('Y-m-d H:i:s'),
        'download_url' => APP_URL . '/crm/cases/download.php?id=' . $new_id,
        'can_delete'   => true,
    ],
], JSON_UNESCAPED_UNICODE);
