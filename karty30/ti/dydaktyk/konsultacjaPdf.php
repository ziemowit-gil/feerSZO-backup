<?php
/**
 * karty30/ti/dydaktyk/konsultacjaPdf.php — PDF kart konsultacji: ?id=<karta> albo ?session=<termin SzK> (karty terminu).
 * Dostęp jak do karty (kadra, konsultant, prowadzący). Wydruk notowany w rejestrze dostępu RODO.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();

$ids = []; $name = 'karty-konsultacji.pdf';
if ($id = (int)($_GET['id'] ?? 0)) {
    $k = TiConsultations::get($id);
    if (!$k || !TiConsultations::canAccess($k, $uid, $staff)) { http_response_code(403); exit('Brak dostępu do karty.'); }
    $ids = [$id]; $name = TiConsultations::pdfName($k);
} elseif ($sid = (int)($_GET['session'] ?? 0)) {
    if (!dyd_owns_session($uid, $sid)) { http_response_code(403); exit('Brak dostępu do terminu.'); }
    $ids = array_map(fn($r) => (int)$r['id'], db_all("SELECT id FROM k30_consultations WHERE session_id=? AND status<>'cancelled' ORDER BY id", [$sid]));
    $name = 'karty-konsultacji-termin-' . $sid . '.pdf';
}
if (!$ids) { http_response_code(404); exit('Brak kart do wydruku.'); }
foreach ($ids as $i) { try { k30_log_access('consultation', $i, 'print'); } catch (\Throwable $e) {} }
try { $pdf = TiConsultations::pdf($ids); }
catch (\Throwable $e) { error_log('konsultacjaPdf: ' . $e->getMessage()); http_response_code(500); exit('Nie udało się przygotować PDF.'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: no-store, private');
echo $pdf;
