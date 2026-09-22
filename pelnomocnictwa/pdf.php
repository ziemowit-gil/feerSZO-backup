<?php
/**
 * Eksport pełnomocnictwa/odwołania do pliku PDF (mPDF).
 *   ?id=N&typ=pelnomocnictwo|odwolanie   → PDF w oknie (inline)
 *   &download=1                          → wymuś pobranie
 * Treść i układ 1:1 z widokiem ekranowym (dokument.php), ze zwrotem Pan/Pani
 * rozstrzygniętym na etapie wpisu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru pełnomocnictw.'); header('Location:'.APP_URL.'/index.php'); exit; }

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? pelnomocnictwo_get($id) : null;
if (!$row) { http_response_code(404); die('Nie znaleziono wpisu.'); }

$typ = ($_GET['typ'] ?? '') === 'odwolanie' ? 'odwolanie' : 'pelnomocnictwo';

$pdf = pelnomocnictwo_pdf_render($row, $typ);
if (!$pdf) { http_response_code(500); die('Nie udało się wygenerować PDF (brak biblioteki mPDF).'); }

[$bytes, $filename] = $pdf;
pelnomocnictwo_log($id, 'doc_generate', 'Eksport PDF: ' . ($typ === 'odwolanie' ? 'odwołanie' : 'pełnomocnictwo') . '.', (int)current_user()['id']);

$disp = !empty($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
