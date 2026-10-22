<?php
/**
 * modules/ti_pdf/logic/tiMetroCss.php — wspólny arkusz stylu METRO dla wydruków TI robionych w mPDF
 * (odpowiednik palety TiPdf dla FPDF; ta sama paleta co ti_metro_kursant.css i moduł Tożsamość).
 *
 * Zasady: płasko, bez zaokrągleń, grafit #1d1d1d + cyjan #1ba1e2 jako akcent (kreski, nie tło pod tekstem),
 * link/akcent tekstowy #0f6fa8 (5,4:1 na bieżącym tle), tekst #1d1d1d (16:1), pomocniczy #4b5563 (7,6:1).
 * Nagłówek tabeli: grafit z białym tekstem (16:1), zebra #f9fafb, siatka #d1d5db.
 *
 * Użycie: $mpdf->WriteHTML(ti_metro_pdf_css(), \Mpdf\HTMLParserMode::HEADER_CSS); przed treścią.
 * Własne reguły dokumentu dopisujemy PO tym arkuszu (kaskada) — tylko to, czego tu brakuje.
 */

/** Stałe palety — do inline'owych stylów w nagłówkach/stopkach. */
const TI_METRO_INK    = '#1d1d1d';
const TI_METRO_CYAN   = '#1ba1e2';
const TI_METRO_LINK   = '#0f6fa8';
const TI_METRO_MUTED  = '#4b5563';
const TI_METRO_GRID   = '#d1d5db';
const TI_METRO_BAR    = '#f3f4f6';
const TI_METRO_ZEBRA  = '#f9fafb';

function ti_metro_pdf_css(): string {
    return 'body { font-family:dejavusans; font-size:9.5pt; color:#1d1d1d; line-height:1.35; }
     h1 { font-size:15pt; color:#1d1d1d; margin:0 0 4mm; padding-bottom:2mm; border-bottom:.8mm solid #1ba1e2; }
     h2 { font-size:10.5pt; color:#1d1d1d; margin:7mm 0 2.5mm; padding:1.4mm 2.5mm; border-left:1.6mm solid #1ba1e2; background:#f3f4f6; }
     h3 { font-size:10pt; color:#1d1d1d; margin:4mm 0 1.5mm; }
     a { color:#0f6fa8; }
     p.sub, .muted, .s { font-size:8.5pt; color:#4b5563; }
     p.sub { margin:1mm 0 2.5mm; }
     table { border-collapse:collapse; width:100%; }
     th { background:#1d1d1d; color:#ffffff; border:.2mm solid #1d1d1d; padding:1.8mm 2mm; font-size:8.5pt; text-align:left; font-weight:bold; }
     td { border:.2mm solid #d1d5db; padding:1.7mm 2mm; font-size:9pt; vertical-align:top; }
     tr.zebra td, table.zebra tr:nth-child(even) td { background:#f9fafb; }
     .r, td.r, th.r { text-align:right; }
     .c, td.c, th.c { text-align:center; }
     tr.sum td { background:#f3f4f6; font-weight:bold; border-top:.5mm solid #1d1d1d; }
     .note { margin-top:5mm; padding:3mm 3.5mm; border-left:1.6mm solid #8a4a00; background:#f3f4f6; font-size:9.5pt; }
     .ok { color:#007a00; } .bad { color:#a20025; }';
}

/** Nagłówek strony: nazwa organizacji + rodzaj dokumentu, pod spodem cyjanowa kreska (jak pasek METRO). */
function ti_metro_pdf_header(string $org, string $docLabel): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;border-bottom:.8mm solid #1ba1e2;font-family:dejavusans;font-size:8pt;color:#1d1d1d">'
        . '<tr><td style="font-weight:bold;padding:0 0 1.5mm;border:none">' . $e($org) . '</td>'
        . '<td style="text-align:right;padding:0 0 1.5mm;color:#4b5563;border:none">' . $e($docLabel) . '</td></tr></table>';
}

/** Stopka: data wydruku po lewej, „Strona X z Y” po prawej, cienka linia grafitowa. */
function ti_metro_pdf_footer(string $left): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;border-top:.2mm solid #1d1d1d;font-family:dejavusans;font-size:7.5pt;color:#4b5563">'
        . '<tr><td style="padding:1.5mm 0 0;border:none">' . $e($left) . '</td>'
        . '<td style="text-align:right;padding:1.5mm 0 0;border:none">Strona {PAGENO} z {nbpg}</td></tr></table>';
}
