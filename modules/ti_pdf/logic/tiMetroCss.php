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
    // Lekki METRO: biała strona, tytuł jako tekst z cienką cyjanową kreską, sekcje z szarą linią, jeden akcent.
    return 'body { font-family:dejavusans; font-size:9.5pt; color:#1d1d1d; line-height:1.35; }
     h1 { font-size:18pt; font-weight:bold; color:#1d1d1d; background:none; margin:0 0 4mm; padding:0 0 2mm; border-bottom:.8mm solid #1ba1e2; }
     h2 { font-size:11pt; font-weight:bold; color:#1d1d1d; background:none; margin:6mm 0 2mm; padding:0 0 1mm; border-bottom:.2mm solid #d1d5db; }
     h3 { font-size:10pt; color:#0f6fa8; margin:4mm 0 1.5mm; }
     a { color:#0f6fa8; }
     p.sub, .muted, .s { font-size:8.5pt; color:#4b5563; }
     p.sub { margin:1mm 0 2.5mm; }
     table { border-collapse:collapse; width:100%; }
     th { background:none; color:#4b5563; border:none; border-bottom:.4mm solid #1d1d1d; padding:1.6mm 2mm; font-size:8.5pt; text-align:left; font-weight:bold; }
     td { border:none; border-bottom:.2mm solid #e5e7eb; padding:1.6mm 2mm; font-size:9pt; vertical-align:top; }
     tr.zebra td, table.zebra tr:nth-child(even) td { background:#f9fafb; }
     .r, td.r, th.r { text-align:right; }
     .c, td.c, th.c { text-align:center; }
     tr.sum td, table.zebra tr.sum td, table.items tr.sum td { background:none; color:#1d1d1d; font-weight:bold; border-top:.4mm solid #1d1d1d; border-bottom:none; }
     .note { margin-top:5mm; padding:2.5mm 3.5mm; border-left:1mm solid #1ba1e2; background:#f9fafb; font-size:9.5pt; }
     .ok { color:#007a00; } .bad { color:#a20025; }';
}

/** Nagłówek strony: kafelek #0f6fa8 + organizacja, rodzaj dokumentu po prawej. */
function ti_metro_pdf_header(string $org, string $docLabel): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;font-family:dejavusans;font-size:8pt;color:#1d1d1d">'
        . '<tr>'
        . '<td style="font-weight:bold;padding:1mm 0 1mm 2.5mm;border:none;border-left:1.2mm solid #1ba1e2">' . $e($org) . '</td>'
        . '<td style="text-align:right;padding:1mm 0;color:#4b5563;border:none">' . $e($docLabel) . '</td></tr></table>';
}

/** Stopka: kafelek #0f6fa8, data wydruku po lewej, „Strona X z Y” po prawej. */
function ti_metro_pdf_footer(string $left): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;font-family:dejavusans;font-size:7.5pt;color:#4b5563">'
        . '<tr>'
        . '<td style="padding:.8mm 0 .8mm 2.5mm;border:none;border-left:1.2mm solid #1ba1e2">' . $e($left) . '</td>'
        . '<td style="text-align:right;padding:.8mm 0;border:none">Strona {PAGENO} z {nbpg}</td></tr></table>';
}
