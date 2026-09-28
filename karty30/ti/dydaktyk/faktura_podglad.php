<?php
/**
 * karty30/ti/dydaktyk/faktura_podglad.php — podgląd faktury z rozliczenia TI
 * tak, jak zobaczy ją kursant/płatnik, BEZ tworzenia dokumentu.
 *
 * Fakturę roboczą buduje ten sam kod co „Wystaw fakturę” (invoice_from_ti_billing,
 * pozycje invoice_ti_items, PDF invoice_pdf_render z załącznikami TI), w
 * transakcji, która jest zawsze WYCOFYWANA — w bazie nic nie zostaje i numer
 * nie jest zużywany. Ostrzeżenia (nieaktualne rozliczenie, firma bez NIP,
 * miniony termin) trafiają do nagłówka X-Invoice-Warnings (operator widzi je też przy „Wystaw fakturę”).
 * GET: billing_id. Tylko kierownik (jak billing.php). Odpowiednik CLI:
 * php cli/ti_invoices.php preview --billing=ID
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/invoices.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/invoice_pdf.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }
karty30_migrate();
invoices_migrate();

$bid = (int)($_GET['billing_id'] ?? 0);
if (!$bid || !db_one("SELECT id FROM k30_ti_billing WHERE id=?", [$bid])) { http_response_code(404); exit('Nie znaleziono rozliczenia.'); }

$pdf = null; $err = ''; $warn = [];
db()->beginTransaction();
try {
    // Istniejąca faktura blokowałaby utworzenie nowej (UNIQUE source) — w
    // transakcji chowamy ją, żeby podgląd pokazał AKTUALNE przeliczenie.
    db()->prepare("UPDATE invoices SET deleted_at=datetime('now') WHERE source='ti_billing' AND source_id=?")->execute([$bid]);
    $r = invoice_from_ti_billing($bid, (int)$me['user_id'], false);
    if (empty($r['ok'])) {
        $err = (string)($r['error'] ?? 'Nie udało się przygotować faktury.');
    } else {
        $warn = $r['warnings'] ?? [];
        $pdf  = invoice_pdf_render(invoice_get((int)$r['id']));
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
} finally {
    db()->rollBack();
}

if ($pdf === null) { http_response_code(422); exit('Podgląd faktury niedostępny: ' . h($err)); }
if ($warn) header('X-Invoice-Warnings: ' . rawurlencode(implode(' | ', $warn)));
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="podglad_faktury_rozliczenie_' . $bid . '.pdf"');
header('Cache-Control: no-store');
echo $pdf;
