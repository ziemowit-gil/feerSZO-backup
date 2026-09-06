<?php
/**
 * karty30/ti/dydaktyk/sale_rezerwacje_pdf.php — Wykaz sal do rezerwacji do pobrania (PDF).
 * Wariant sale_rezerwacje.php (widok HTML) — te same dane i ten sam zakres dat
 * (ti_room_reservation_range() + ti_room_reservation_report()).
 * GET: range ('week'|'month'|'quarter', domyślnie 'week'), w (data kotwicząca).
 *
 * mPDF (nie FPDF) — natywne UTF-8, żeby polskie znaki (ą, ć, ę, ł, ń, ó, ś, ź, ż)
 * nie ginęły przy transliteracji do CP1252, jak w starszych wydrukach FPDF.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak dostępu.'); }
karty30_migrate();
ti_planner_ext_migrate();

$range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'week';
$w     = (string)($_GET['w'] ?? '');
$view  = ($_GET['view'] ?? '') === 'operator' ? 'operator' : 'day';
$RR    = ti_room_reservation_range($w, $range);
$from  = $RR['from']; $to = $RR['to']; $range_label = $RR['label'];

$by_group = $view === 'operator' ? ti_room_reservation_report_by_operator($from, $to) : ti_room_reservation_report($from, $to);

// Jeden konkretny operator (PDF do wysłania TYLKO jemu — bez rezerwacji
// pozostałych operatorów/sal). Działa wyłącznie z view=operator.
// Brak klucza w $by_group nie oznacza błędu — operator może po prostu nie
// mieć żadnych terminów w wybranym okresie (raport grupuje tylko sale z
// realnie zaplanowanymi lekcjami) — wtedy PDF ma po prostu pustą treść,
// tak jak widok "wg dnia" przy braku terminów w ogóle.
$operator = $view === 'operator' ? trim((string)($_GET['operator'] ?? '')) : '';
if ($operator !== '') {
    $by_group = [$operator => $by_group[$operator] ?? []];
}

$org = defined('APP_ORG') ? APP_ORG : (defined('ORG_NAME') ? ORG_NAME : '');

function _h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

try {
    require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

    $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4-L', // poziomo — 5 kolumn
        'margin_left'   => 14,
        'margin_right'  => 14,
        'margin_top'    => 14,
        'margin_bottom' => 16,
        'default_font'  => 'dejavusans',
        'tempDir'       => $mpdf_tmp,
    ]);
    $title_suffix = $operator !== '' ? (' — ' . $operator) : ($view === 'operator' ? ' — wg operatora' : '');
    $mpdf->SetTitle('Wykaz sal do rezerwacji' . $title_suffix . ' — ' . $range_label);
    $mpdf->SetAuthor($org !== '' ? $org : 'FEER');

    $mpdf->WriteHTML(
        'body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111; }
         h1 { font-size: 15pt; margin: 0 0 1mm; }
         p.meta { color: #555; font-size: 9pt; margin: 0 0 5mm; }
         h2.day { font-size: 10.5pt; background: #f1f5f9; padding: 1.5mm 2.5mm; margin: 4mm 0 1.5mm; border-left: 1mm solid #3b82f6; }
         table { border-collapse: collapse; width: 100%; margin-bottom: 2mm; }
         th { background: #f8fafc; border-bottom: .3mm solid #cbd5e1; padding: 1mm 2mm; text-align: left; font-size: 8.5pt; color: #475569; }
         td { border-bottom: .2mm solid #e2e8f0; padding: 1.2mm 2mm; font-size: 9pt; vertical-align: top; }
         td.time { white-space: nowrap; }
         .st-confirmed { color: #166534; }
         .st-pending   { color: #92400e; }
         p.empty { color: #64748b; font-style: italic; }
         p.footer { color: #94a3b8; font-size: 7.5pt; margin-top: 6mm; }',
        \Mpdf\HTMLParserMode::HEADER_CSS
    );

    $html = '<h1>Wykaz sal do rezerwacji' . _h($title_suffix) . '</h1>'
          . '<p class="meta">' . ($org !== '' ? _h($org) . '   ·   ' : '') . _h($range_label) . '</p>';

    if (!$by_group) {
        $html .= '<p class="empty">Brak terminów z przypisaną salą w wybranym okresie.</p>';
    } else {
        foreach ($by_group as $group_key => $rows) {
            if ($view === 'operator') {
                $html .= '<h2 class="day">' . _h($group_key . ' (' . count($rows) . ' termin(y/ów) w harmonogramie)') . '</h2>';
                $html .= '<table><thead><tr>'
                       . '<th style="width:10%">Dzień</th>'
                       . '<th style="width:9%">Od</th>'
                       . '<th style="width:9%">Do</th>'
                       . '<th style="width:11%">Godziny</th>'
                       . '<th style="width:21%">Sala (nazwa operatora)</th>'
                       . '<th style="width:18%">Grupa</th>'
                       . '<th style="width:14%">Prowadzący</th>'
                       . '<th style="width:8%">Status</th>'
                       . '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $status = $r['pending'] === 0
                        ? '<span class="st-confirmed">potwierdzone</span>'
                        : '<span class="st-pending">' . (int)$r['pending'] . '/' . (int)$r['count'] . ' do rezerwacji</span>';
                    $html .= '<tr>'
                           . '<td>' . _h(TI_DAYS_PL_FULL[$r['dow']] ?? '') . '</td>'
                           . '<td class="time">' . _h(date('d.m.Y', strtotime($r['date_from']))) . '</td>'
                           . '<td class="time">' . _h(date('d.m.Y', strtotime($r['date_to']))) . '</td>'
                           . '<td class="time">' . _h($r['time_from'] . '–' . $r['time_to']) . '</td>'
                           . '<td>' . _h($r['room_customary']) . '</td>'
                           . '<td>' . _h($r['course_name']) . '</td>'
                           . '<td>' . _h($r['instructor_label']) . '</td>'
                           . '<td>' . $status . '</td>'
                           . '</tr>';
                }
                $html .= '</tbody></table>';
            } else {
                $dow = (int)date('N', strtotime($group_key));
                $html .= '<h2 class="day">' . _h((TI_DAYS_PL_FULL[$dow] ?? '') . ', ' . date('d.m.Y', strtotime($group_key)) . ' (' . count($rows) . ')') . '</h2>';
                $html .= '<table><thead><tr>'
                       . '<th style="width:16%">Godziny</th>'
                       . '<th style="width:26%">Sala / lokalizacja</th>'
                       . '<th style="width:26%">Grupa</th>'
                       . '<th style="width:20%">Prowadzący</th>'
                       . '<th style="width:12%">Status</th>'
                       . '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $confirmed = $r['room_reservation_status'] === 'potwierdzone';
                    $time_lbl  = substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5);
                    $html .= '<tr>'
                           . '<td class="time">' . _h($time_lbl) . '</td>'
                           . '<td>' . _h($r['room_label']) . '</td>'
                           . '<td>' . _h($r['course_name']) . '</td>'
                           . '<td>' . _h($r['instructor_label']) . '</td>'
                           . '<td class="' . ($confirmed ? 'st-confirmed' : 'st-pending') . '">' . ($confirmed ? 'Potwierdzone' : 'Do rezerwacji') . '</td>'
                           . '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }
    }

    $html .= '<p class="footer">Wygenerowano: ' . _h(date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')) . '</p>';
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    ti_print_log_add('sale_rezerwacje_pdf', 'Wykaz sal do rezerwacji PDF' . $title_suffix . ' — ' . $range_label, 0, 0, ['range' => $range, 'view' => $view, 'operator' => $operator], $me);
    $fname_suffix = $operator !== '' ? '_' . preg_replace('/[^a-z0-9]+/i', '_', $operator) : ($view === 'operator' ? '_operator' : '');
    $fname = 'wykaz_sal' . $fname_suffix . '_' . preg_replace('/[^a-z0-9]+/i', '_', $from . '_' . $to) . '.pdf';
    $pdfData = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[sale_rezerwacje_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
