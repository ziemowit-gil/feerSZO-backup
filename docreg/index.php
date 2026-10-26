<?php
/**
 * docreg/index.php — rejestr numerów i kodów plików (modules/doc_registry):
 *   ?view=KOD   — strona z QR, numerem i kodem (działa bez JS i bez okna modalnego),
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
    if ((DocRegistry::SOURCES[$row['source_type']][4] ?? 'rand') === 'seq') docreg_fail(404, 'Ten rodzaj dokumentu nie ma etykiety — tylko numer i kod QR.');
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

if (isset($_GET['view'])) {
    $row = DocRegistry::find((string)$_GET['view']) ?: docreg_fail(404, 'Nie znaleziono kodu.');
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $def = DocRegistry::SOURCES[$row['source_type']] ?? ['', 'Plik w systemie'];
    $seq = ($def[4] ?? 'rand') === 'seq';
    $code = (string)$row['code'];
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Identyfikator dokumentu ' . $e($row['doc_number']) . '</title>'
        . '<style>body{font-family:"Segoe UI",system-ui,sans-serif;margin:0;background:#f4f4f4;color:#1d1d1d;text-align:center;padding:1.5rem}main{max-width:22rem;margin:0 auto;background:#fff;border:1px solid #c9c9c9;padding:1.2rem}'
        . 'img{width:220px;height:220px}code{font-size:1.05rem}.s{font-size:.85rem;color:#4b5b6b}a.b{display:inline-block;margin:.3rem .2rem;padding:.45rem .9rem;background:#0050ef;color:#fff;text-decoration:none;font-weight:600}a.o{background:#4b5b6b}:focus-visible{outline:3px solid #ff9800;outline-offset:2px}'
        . '@media (prefers-color-scheme:dark){body{background:#1b1b1b;color:#f2f2f2}main{background:#262626;border-color:#4a4a4a}.s{color:#aebccb}}@media print{a.b{display:none}}</style></head><body><main id="tresc">'
        . '<h1 style="font-size:1.1rem;font-weight:600;margin:.2rem 0 .8rem">' . $e($def[1]) . '</h1>'
        . '<img src="?qr=' . $e(rawurlencode($code)) . '" alt="Kod QR do weryfikacji i otwarcia dokumentu online">'
        . '<p class="s" style="margin:.6rem 0 0">Numer</p><p style="margin:0 0 .5rem;font-weight:600">' . $e($row['doc_number']) . '</p>'
        . ($seq ? '' : '<p class="s" style="margin:0">Kod (Code128)</p><p style="margin:0 0 .5rem"><code>' . $e($code) . '</code></p>')
        . '<p class="s">Kod QR otwiera stronę weryfikacji; po zalogowaniu — dokument online.</p>'
        . '<a class="b" href="' . $e(DocRegistry::url($code)) . '">Strona weryfikacji</a>'
        . ($seq ? '' : '<a class="b o" href="?label=' . $e(rawurlencode($code)) . '">Etykieta do druku (PDF)</a>')
        . '</main></body></html>';
    exit;
}

if (isset($_GET['o'])) {
    $row = DocRegistry::find((string)$_GET['o']) ?: docreg_fail(404, 'Nie znaleziono dokumentu o tym kodzie.');
    $st = DocRegistry::state($row);
    if ($st['src'] === null) docreg_fail(410, $st['note']);
    header('Location: ' . DocRegistry::openUrl((string)$row['source_type'], (int)$row['source_id'])); exit;
}

docreg_fail(400, 'Brak parametru (qr, view, label albo o).');
