<?php
/**
 * includes/ti_grades_pdf.php — generowanie PDF dziennika ocen (e-dziennik).
 * Wykorzystuje FPDF/FPDI + fonty DejaVu (jak includes/ksiegowosc.php).
 */
require_once __DIR__ . '/karty30.php';

/** Konwersja UTF-8 → ISO-8859-2 (fonty FPDF). */
function ti_pdf_txt(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

/** Tworzy obiekt PDF z fontem DejaVu. */
function ti_pdf_new(string $orient = 'P'): \setasign\Fpdi\Fpdi {
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';
    $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(15, 15, 15);
    $font_dir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $font_dir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $font_dir);
    return $pdf;
}

/** Kolor RGB tła oceny (z hex helpera). */
function ti_pdf_grade_rgb(?float $num): array {
    [$hex] = k30_ti_grade_color($num);
    return [hexdec(substr($hex,1,2)), hexdec(substr($hex,3,2)), hexdec(substr($hex,5,2))];
}

/** Nagłówek strony raportu. */
function ti_pdf_header(\setasign\Fpdi\Fpdi $pdf, string $title, string $subtitle, float $W): void {
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $pdf->SetFillColor(30, 64, 120);
    $pdf->Rect($pdf->GetX(), $pdf->GetY(), $W, 12, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 12, ti_pdf_txt($title), 0, 1, 'L', false);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->Cell($W, 6, ti_pdf_txt($subtitle), 0, 1, 'L');
    $pdf->SetFont('DejaVu', '', 7.5);
    $pdf->SetTextColor(120,120,120);
    $pdf->Cell($W, 5, ti_pdf_txt($org . '  ·  wygenerowano ' . date('d.m.Y H:i')), 0, 1, 'L');
    $pdf->SetTextColor(0,0,0);
    $pdf->Ln(2);
}

/** Rysuje pasek ocen (kolorowe komórki value). Zwraca nową pozycję Y. */
function ti_pdf_grade_cells(\setasign\Fpdi\Fpdi $pdf, array $grades, float $startX, float $maxX): void {
    $x = $startX; $y = $pdf->GetY();
    $h = 5; $pad = 1.2;
    $pdf->SetFont('DejaVu', 'B', 8);
    foreach ($grades as $g) {
        $txt = ti_pdf_txt((string)$g['value_text']);
        $w   = max(6, $pdf->GetStringWidth($txt) + 2*$pad);
        if ($x + $w > $maxX) { $x = $startX; $y += $h + 1; }
        $num = isset($g['value_num']) && $g['value_num'] !== null ? (float)$g['value_num'] : null;
        [$r,$gn,$b] = ti_pdf_grade_rgb($num);
        $pdf->SetFillColor($r,$gn,$b);
        $pdf->SetTextColor($num !== null && $num >= 2.75 && $num < 4.75 ? 33 : 255, $num !== null && $num >= 2.75 && $num < 4.75 ? 37 : 255, $num !== null && $num >= 2.75 && $num < 4.75 ? 41 : 255);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $txt, 0, 0, 'C', true);
        $x += $w + 1;
    }
    $pdf->SetTextColor(0,0,0);
    $pdf->SetXY($startX, $y + $h + 2);
}

/** Streamuje PDF dziennika ocen całego kursu (widok prowadzącego). Kończy skrypt. */
function ti_grades_pdf_course(int $course_id): void {
    $course = k30_ti_course_get($course_id);
    if (!$course) { http_response_code(404); exit('Kurs nie istnieje.'); }
    $roster = array_values(array_filter(k30_ti_enrollments($course_id), fn($e)=>$e['status']==='active'));
    $grades = k30_ti_course_grades($course_id);
    $by = [];
    foreach ($grades as $g) { $by[(int)$g['client_id']][] = $g; }

    $pdf = ti_pdf_new('L');
    $W   = 267;
    $pdf->AddPage();
    ti_pdf_header($pdf, 'Dziennik ocen', $course['name'], $W);

    foreach ($roster as $e) {
        $cgr = $by[(int)$e['client_id']] ?? [];
        $avg = k30_ti_grades_average($cgr);
        if ($pdf->GetY() > 185) { $pdf->AddPage(); }
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->Cell(80, 6, ti_pdf_txt($e['client_name']), 0, 0, 'L');
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell(40, 6, ti_pdf_txt('Średnia: ' . ($avg !== null ? number_format($avg,2,',','') : '—')), 0, 1, 'L');
        if ($cgr) {
            // chronologicznie rosnąco w wydruku
            $list = array_reverse($cgr);
            ti_pdf_grade_cells($pdf, $list, 15, 15 + $W);
        } else {
            $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(130,130,130);
            $pdf->Cell($W, 5, ti_pdf_txt('— brak ocen —'), 0, 1); $pdf->SetTextColor(0,0,0);
        }
        $pdf->SetDrawColor(220,220,220); $pdf->Line(15, $pdf->GetY(), 15+$W, $pdf->GetY()); $pdf->Ln(1.5);
    }

    $name = 'dziennik_ocen_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $course['name']) . '_' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $pdf->Output('I', $name);
    exit;
}

/** Streamuje PDF wykazu ocen jednego kursanta (kursant/rodzic). Kończy skrypt. */
function ti_grades_pdf_student(int $client_id, string $student_name = ''): void {
    $grades = k30_ti_client_grades($client_id);
    $by = [];
    foreach ($grades as $g) { $by[$g['course_name']][] = $g; }
    if ($student_name === '') {
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$client_id]);
        $student_name = $cl['name'] ?? ('Kursant #' . $client_id);
    }

    $pdf = ti_pdf_new('P');
    $W   = 180;
    $pdf->AddPage();
    ti_pdf_header($pdf, 'Wykaz ocen', $student_name, $W);

    if (!$grades) {
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell($W, 8, ti_pdf_txt('Brak ocen.'), 0, 1);
    }
    foreach ($by as $cname => $cgr) {
        $avg = k30_ti_grades_average($cgr);
        if ($pdf->GetY() > 250) { $pdf->AddPage(); }
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->SetFillColor(238, 242, 250);
        $pdf->Cell($W, 7, ti_pdf_txt($cname . '   (średnia: ' . ($avg !== null ? number_format($avg,2,',','') : '—') . ')'), 0, 1, 'L', true);
        // tabela ocen
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->SetFillColor(248,248,248);
        $wDesc = $W-22-16-12-30-38;
        $pdf->Cell(22, 6, ti_pdf_txt('Data'),      1, 0, 'C', true);
        $pdf->Cell(16, 6, ti_pdf_txt('Ocena'),     1, 0, 'C', true);
        $pdf->Cell(12, 6, ti_pdf_txt('Waga'),      1, 0, 'C', true);
        $pdf->Cell(30, 6, ti_pdf_txt('Kategoria'), 1, 0, 'C', true);
        $pdf->Cell($wDesc, 6, ti_pdf_txt('Za co'), 1, 0, 'L', true);
        $pdf->Cell(38, 6, ti_pdf_txt('Wystawił(a)'), 1, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 8);
        foreach (array_reverse($cgr) as $g) {
            if ($pdf->GetY() > 280) { $pdf->AddPage(); }
            $num = isset($g['value_num']) && $g['value_num'] !== null ? (float)$g['value_num'] : null;
            [$r,$gn,$b] = ti_pdf_grade_rgb($num);
            $pdf->Cell(22, 6, ti_pdf_txt(substr((string)$g['graded_at'],0,10)), 1, 0, 'C');
            $pdf->SetFillColor($r,$gn,$b);
            $pdf->SetTextColor($num !== null && $num >= 2.75 && $num < 4.75 ? 33 : 255, $num !== null && $num >= 2.75 && $num < 4.75 ? 37 : 255, $num !== null && $num >= 2.75 && $num < 4.75 ? 41 : 255);
            $pdf->SetFont('DejaVu','B',8);
            $pdf->Cell(16, 6, ti_pdf_txt((string)$g['value_text']), 1, 0, 'C', true);
            $pdf->SetTextColor(0,0,0); $pdf->SetFont('DejaVu','',8);
            $pdf->Cell(12, 6, ti_pdf_txt(rtrim(rtrim(number_format((float)$g['weight'],2,'.',''),'0'),'.') ?: '1'), 1, 0, 'C');
            $pdf->Cell(30, 6, ti_pdf_txt(k30_ti_grade_category_label((string)$g['category'])), 1, 0, 'L');
            $pdf->Cell($wDesc, 6, ti_pdf_txt(mb_substr((string)$g['description'],0,45)), 1, 0, 'L');
            $pdf->Cell(38, 6, ti_pdf_txt(mb_substr((string)($g['graded_by_name'] ?? ''),0,22)), 1, 1, 'L');
        }
        $pdf->Ln(3);
    }

    $name = 'wykaz_ocen_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $student_name) . '_' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $pdf->Output('I', $name);
    exit;
}
