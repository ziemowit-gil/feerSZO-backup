<?php
/**
 * karty30/ti/dydaktyk/lekcja_pdf.php — Karta pojedynczej lekcji do wydruku (PDF).
 * Odpowiednik podglądu "Wejdź" w zakładce Lekcje (_lekcja_karta.php), ale jako
 * plik do pobrania/wydruku — dostępny też z katalogu Wydruki (wydruki.php).
 * GET: id (session_id, wymagane). Dostęp: dyd_owns_session (własna lekcja lub staff).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id || !dyd_owns_session($uid, $id)) { http_response_code(403); exit('Brak uprawnień do tej lekcji.'); }

$s = db_one(
    "SELECT s.*, c.name AS course_name FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id = s.course_id WHERE s.id=?",
    [$id]
);
if (!$s) { http_response_code(404); exit('Nie znaleziono lekcji.'); }

$att   = k30_ti_session_attendance($id);
$curr  = function_exists('k30_ti_session_curriculum_items') ? k30_ti_session_curriculum_items($id) : [];
$active_att = array_values(array_filter($att, fn($a) => empty($a['cancelled'])));
$st_label = K30_TI_SESSION_STATUSES[(string)$s['status']]['label'] ?? (string)$s['status'];
$method_label = ['stacjonarna' => 'stacjonarne', 'zdalna_zoom' => 'zdalne — Zoom', 'zdalna_inne' => 'zdalne — inne'][(string)($s['lesson_method'] ?? '')] ?? '';
$instr_name = '';
$s_instr = (int)($s['instructor_id'] ?? 0);
if ($s_instr) { $instr_name = (string)(db_one("SELECT name FROM users WHERE id=?", [$s_instr])['name'] ?? ''); }

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$pl = fn(string $t): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $t) ?: $t;
$org = defined('ORG_NAME') ? ORG_NAME : '';

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(14, 14, 14);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 28;

    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl('Karta lekcji — ' . $s['course_name']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($W, 5, $pl(($org !== '' ? $org . '   ·   ' : '') . 'Wygenerowano: ' . date('d.m.Y H:i')), 0, 1);
    $pdf->Ln(4);

    $row = function (string $label, string $value) use ($pdf, $pl, $W) {
        if ($value === '') return;
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(42, 6, $pl($label), 0, 0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->MultiCell($W - 42, 6, $pl($value));
    };

    $date_label = date('d.m.Y', strtotime((string)$s['lesson_date']))
        . ($s['time_from'] ? ', ' . substr((string)$s['time_from'], 0, 5) . ($s['time_to'] ? '–' . substr((string)$s['time_to'], 0, 5) : '') : '');
    $row('Termin:', $date_label);
    $row('Status:', $st_label);
    if ($method_label !== '') $row('Forma:', $method_label);
    if ($instr_name !== '') $row('Prowadzący (zastępstwo):', $instr_name);
    if (trim((string)($s['topic'] ?? '')) !== '') $row('Temat:', (string)$s['topic']);
    if (trim((string)($s['notes'] ?? '')) !== '') $row('Notatka dla kursantów:', (string)$s['notes']);
    if (trim((string)($s['instructor_notes'] ?? '')) !== '') $row('Notatka prowadzącego:', (string)$s['instructor_notes']);
    if ($curr) $row('Punkty sylabusa:', implode(' · ', array_map(fn($c) => (string)$c['title'], $curr)));
    $row('Dokumentacja:', !empty($s['docs_complete']) ? 'uzupełniona' : 'niekompletna');
    $pdf->Ln(3);

    if ($active_att) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($W, 7, $pl('Obecność'), 0, 1);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($W - 40, 7, $pl('Uczestnik'), 1, 0, 'L', true);
        $pdf->Cell(40, 7, $pl('Obecność'), 1, 1, 'C', true);
        $pdf->SetFont('Helvetica', '', 8.5);
        foreach ($active_att as $a) {
            $ok = !empty($a['attended']);
            $pdf->Cell($W - 40, 6, $pl((string)$a['client_name']), 1, 0, 'L');
            $pdf->SetTextColor($ok ? 0 : 170, $ok ? 130 : 0, 0);
            $pdf->Cell(40, 6, $pl($ok ? 'obecny/a' : 'nieobecny/a'), 1, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    ti_print_log_add('lekcja_pdf', 'Karta lekcji — ' . $s['course_name'] . ' (' . $s['lesson_date'] . ')', (int)$s['course_id'], 0, [], $me);
    $fname = 'lekcja_' . preg_replace('/[^a-z0-9]+/i', '_', $s['course_name']) . '_' . $s['lesson_date'] . '.pdf';
    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdfData));
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[lekcja_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
