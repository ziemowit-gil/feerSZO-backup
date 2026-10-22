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
    // Kafle METRO: h1 = kafel #0f6fa8 (biały tekst 5,4:1), h2 = kafel grafitowy; tabele bez ramek pionowych.
    return 'body { font-family:dejavusans; font-size:9.5pt; color:#1d1d1d; line-height:1.35; }
     h1 { font-size:17pt; color:#ffffff; background:#0f6fa8; margin:0 0 5mm; padding:4.5mm 5mm; border-bottom:1.4mm solid #1d1d1d; }
     h2 { font-size:10.5pt; color:#ffffff; background:#1d1d1d; margin:7mm 0 2.5mm; padding:1.8mm 3mm; }
     h3 { font-size:10pt; color:#0f6fa8; margin:4mm 0 1.5mm; }
     a { color:#0f6fa8; }
     p.sub, .muted, .s { font-size:8.5pt; color:#4b5563; }
     p.sub { margin:1mm 0 2.5mm; }
     table { border-collapse:collapse; width:100%; }
     th { background:#f3f4f6; color:#1d1d1d; border:none; border-bottom:.7mm solid #0f6fa8; padding:2mm 2mm; font-size:8.5pt; text-align:left; font-weight:bold; }
     td { border:none; border-bottom:.2mm solid #d1d5db; padding:1.8mm 2mm; font-size:9pt; vertical-align:top; }
     tr.zebra td, table.zebra tr:nth-child(even) td { background:#f9fafb; }
     .r, td.r, th.r { text-align:right; }
     .c, td.c, th.c { text-align:center; }
     tr.sum td, table.zebra tr.sum td, table.items tr.sum td { background:#1d1d1d; color:#ffffff; font-weight:bold; border-bottom:none; }
     .note { margin-top:5mm; padding:3mm 3.5mm; border-left:3mm solid #8a4a00; background:#f3f4f6; font-size:9.5pt; }
     .ok { color:#007a00; } .bad { color:#a20025; }';
}

/** Nagłówek strony: kafelek #0f6fa8 + organizacja, rodzaj dokumentu po prawej. */
function ti_metro_pdf_header(string $org, string $docLabel): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;font-family:dejavusans;font-size:8pt;color:#1d1d1d">'
        . '<tr><td width="4" style="background:#0f6fa8;border:none">&nbsp;</td>'
        . '<td style="font-weight:bold;padding:1mm 0 1mm 2.5mm;border:none">' . $e($org) . '</td>'
        . '<td style="text-align:right;padding:1mm 0;color:#4b5563;border:none">' . $e($docLabel) . '</td></tr></table>';
}

/** Stopka: kafelek #0f6fa8, data wydruku po lewej, „Strona X z Y” po prawej. */
function ti_metro_pdf_footer(string $left): string {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return '<table width="100%" style="border-collapse:collapse;font-family:dejavusans;font-size:7.5pt;color:#4b5563">'
        . '<tr><td width="4" style="background:#0f6fa8;border:none">&nbsp;</td>'
        . '<td style="padding:.8mm 0 .8mm 2.5mm;border:none">' . $e($left) . '</td>'
        . '<td style="text-align:right;padding:.8mm 0;border:none">Strona {PAGENO} z {nbpg}</td></tr></table>';
}
