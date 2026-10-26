<?php
/**
 * docreg/index.php — rejestr numerów i kodów plików (modules/doc_registry):
 *   ?qr=KOD     — PNG z kodem QR (adres weryfikacji /espis/?c=KOD),
 *   ?label=KOD  — etykieta do druku (PDF 70×38 mm): Code128 + QR + numer; do naklejenia na papierowy odpowiednik,
 *   ?o=KOD      — „Otwórz dokument online”: po zalogowaniu przekierowanie do pliku w systemie źródłowym (EZD / CRM / Zadania),
 *                 który sam sprawdza uprawnienia (ten adres niczego nie omija).
 * Wszystko wymaga logowania (publiczna jest tylko weryfikacja kodu w /espis/).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';

require_login();
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function docreg_fail(int $code, string $msg): never { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

if (isset($_GET['qr'])) {
    $row = DocRegistry::find((string)$_GET['qr']) ?: docreg_fail(404, 'Nie znaleziono kodu.');
    $png = DocRegistry::qrPng((string)$row['code']) ?: docreg_fail(503, 'Generator QR jest niedostępny.');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: image/png'); header('Content-Length: ' . strlen($png)); header('Cache-Control: private, max-age=86400');
    echo $png; exit;
}

if (isset($_GET['label'])) {
    $row = DocRegistry::find((string)$_GET['label']) ?: docreg_fail(404, 'Nie znaleziono kodu.');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $code = (string)$row['code'];
    $html = '<table style="width:100%;border:0;font-family:dejavusans"><tr>'
        . '<td style="vertical-align:middle;width:64%"><div style="font-size:6pt">' . $e(defined('ORG_NAME') ? ORG_NAME : '') . '</div>'
        . '<div style="font-size:8pt;font-weight:bold">' . $e($row['doc_number']) . '</div>'
        . '<div style="padding-top:1.5mm"><barcode code="' . $e($code) . '" type="C128B" size="0.7" height="0.9" /></div>'
        . '<div style="font-size:8pt;font-family:dejavusansmono">' . $e($code) . '</div></td>'
        . '<td style="vertical-align:middle;text-align:center"><barcode code="' . $e(DocRegistry::url($code)) . '" type="QR" class="barcode" size="0.6" error="M" disableborder="1" />'
        . '<div style="font-size:5pt">sprawdź / otwórz online</div></td></tr></table>';
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => [70, 38], 'margin_left' => 2.5, 'margin_right' => 2.5, 'margin_top' => 2.5, 'margin_bottom' => 2, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $pdf->SetTitle('Etykieta ' . $code);
    $pdf->WriteHTML($html);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="etykieta-' . preg_replace('~[^A-Z0-9]+~', '-', $code) . '.pdf"');
    $pdf->Output('', \Mpdf\Output\Destination::INLINE); exit;
}

if (isset($_GET['o'])) {
    $row = DocRegistry::find((string)$_GET['o']) ?: docreg_fail(404, 'Nie znaleziono dokumentu o tym kodzie.');
    $st = DocRegistry::state($row);
    if ($st['src'] === null) docreg_fail(410, $st['note']);
    header('Location: ' . DocRegistry::openUrl((string)$row['source_type'], (int)$row['source_id'])); exit;
}

docreg_fail(400, 'Brak parametru (qr, label albo o).');
