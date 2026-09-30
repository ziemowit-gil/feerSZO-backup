<?php
/**
 * AJAX: wczytuje fakturę z pliku XML (KSeF FA) wskazanego w formularzu edok/add.php,
 * zapisuje go jako dokument źródłowy i zwraca dane do wypełnienia formularza.
 * JSON: { ok, data: {...}, file_path } albo { ok:false, error }.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok_queue.php';
require_once __DIR__ . '/../includes/crm_offers.php';

header('Content-Type: application/json; charset=utf-8');
function xml_err(string $m): never { echo json_encode(['ok' => false, 'error' => $m]); exit; }

require_login();
if (!edok_has_role('upload') && !is_admin()) xml_err('Brak uprawnień.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') xml_err('Metoda niedozwolona.');
csrf_check();

$f = $_FILES['file'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xml') xml_err('Wybierz plik XML.');
if ($f['size'] > 5 * 1024 * 1024) xml_err('Plik XML jest za duży (max 5 MB).');
$xml = (string)file_get_contents($f['tmp_name']);
$data = edok_parse_invoice_xml($xml);
if (!$data) xml_err('To nie jest faktura ustrukturyzowana (KSeF FA) — nie można odczytać danych.');

edok_migrate();
// Pliki pobrane z KSeF są nazwane numerem KSeF (NIP-RRRRMMDD-…-CRC) — zachowujemy go dla wizualizacji faktur zakupu.
$base = pathinfo($f['name'], PATHINFO_FILENAME);
$ksef_no = preg_match('/^\d{10}-\d{8}-[0-9A-Fa-f]{12}-[0-9A-Fa-f]{2}$/', $base) ? strtoupper($base) : '';
echo json_encode(['ok' => true, 'data' => $data, 'file_path' => edok_queue_save_bytes($xml, 'xml'), 'ksef_number' => $ksef_no]);
