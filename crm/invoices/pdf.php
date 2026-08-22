<?php
/**
 * crm/invoices/pdf.php — wydanie lokalnej kopii PDF faktury.
 *
 * Plik leży poza katalogiem publicznym, więc podajemy go przez PHP — dzięki temu
 * obowiązują te same uprawnienia co w rejestrze, a nazwa pliku z bazy nie może
 * wyprowadzić poza katalog faktur (basename + weryfikacja realpath).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
if (!can_read('crm') && !is_admin()) { http_response_code(403); exit('Brak uprawnień.'); }

$inv = invoice_get((int)($_GET['id'] ?? 0));
if (!$inv) { http_response_code(404); exit('Nie znaleziono faktury.'); }

/**
 * Dwa źródła PDF-a:
 *  • kopia z Fakturowni (pdf_path) — dokument księgowy, ma pierwszeństwo,
 *  • generowany z szablonu SZO — dla szkiców i faktur wystawianych samodzielnie.
 * `?gen=1` wymusza wersję z szablonu (podgląd przed wystawieniem).
 */
$force_gen = !empty($_GET['gen']);
if ($force_gen || empty($inv['pdf_path'])) {
    require_once dirname(dirname(__DIR__)) . '/includes/invoice_pdf.php';
    $opts = ['duplikat' => !empty($_GET['duplikat']), 'kopia' => !empty($_GET['kopia'])];
    try {
        $pdf = invoice_pdf_render($inv, $opts);
    } catch (\Throwable $e) {
        http_response_code(500);
        exit('Nie udało się wygenerować PDF: ' . h($e->getMessage()));
    }
    $name = 'Faktura_' . preg_replace('/[^A-Za-z0-9\-_]+/', '-', (string)($inv['number'] ?: $inv['id'])) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)strlen($pdf));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

$dir  = invoices_pdf_dir();
$path = $dir . '/' . basename((string)$inv['pdf_path']);
$real = realpath($path);
if ($real === false || !str_starts_with($real, realpath($dir) . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit('Plik nie istnieje.');
}

$name = 'Faktura_' . preg_replace('/[^A-Za-z0-9\-_]+/', '-', (string)($inv['number'] ?: $inv['id'])) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($real);
