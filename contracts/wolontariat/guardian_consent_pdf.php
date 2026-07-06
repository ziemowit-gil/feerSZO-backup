<?php
/**
 * contracts/wolontariat/guardian_consent_pdf.php — Podgląd/wydruk pisma
 * przewodniego (zaproszenia do odnowienia zgody przedstawiciela ustawowego)
 * na żądanie, niezależnie od tego czy zostało już wysłane/dołączone do EZD.
 * GET: id (wymagane — id umowy wolontariackiej).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/guardian_consent.php';

require_role('admin', 'editor');

$id  = (int)($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono umowy.'); }

if (empty($row['niepelnoletni'])) {
    http_response_code(400);
    die('Ta umowa nie dotyczy wolontariusza niepełnoletniego — pismo nie ma zastosowania.');
}

$guardian_name = $row['rodzic_imie_nazwisko'] ?: ($row['rodzic_email'] ?? '');
$znak_sprawy   = '';
if (!empty($row['zgoda_przedstawiciela_ezd_sprawa_id']) && function_exists('ezd_sprawa_get')) {
    require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
    $sprawa = ezd_sprawa_get((int)$row['zgoda_przedstawiciela_ezd_sprawa_id']);
    $znak_sprawy = $sprawa['znak_sprawy'] ?? '';
}

$u      = current_user();
$signer = $u ? ['name' => $u['name'] ?? '', 'title' => $u['crm_job_title'] ?? ''] : null;

$bytes = guardian_consent_generate_pdf($row, $guardian_name, $znak_sprawy, $signer);
if (!$bytes) {
    http_response_code(500);
    die('Błąd generowania PDF. Sprawdź logi serwera.');
}

$osoba    = $row['imie_nazwisko'] ?? 'wolontariusz';
$filename = 'Pismo przewodnie — zgoda na wolontariat — ' . $osoba . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
