<?php
/**
 * modules/ti_scheduler/logic/schedulerExport.php — wydruki i eksporty raportów planisty (PDF, DOCX, CSV).
 * sc_report_dataset() zamienia raport na tabelę (nagłówki, wiersze, szerokości względne),
 * a sc_export_pdf/docx/csv() renderują ją jednolicie.
 */
require_once __DIR__ . '/schedulerReports.php';

const SC_REPORTS = ['pay' => 'Prognoza wynagrodzeń trenerów', 'rooms' => 'Wykorzystanie sal', 'att' => 'Planowana frekwencja grup',
                    'cyc' => 'Cykle zajęć grup', 'week' => 'Obciążenie godzinowe trenerów wg tygodni'];

/** @return array{title:string,subtitle:string,headers:list<string>,rows:list<list<string>>,w:list<float>,num:list<int>} */
function sc_report_dataset(string $key, string $ym, int $weeks = 8): array {
    $ym = sc_ym($ym);
    $n2 = fn($x) => number_format((float)$x, 2, ',', '');
    $h1 = fn(int $m) => number_format($m / 60, 1, ',', '');
    if ($key === 'pay') {
        $rows = []; foreach (sc_report_payouts($ym) as $r) $rows[] = [$r['name'], $r['form'], (string)$r['held_n'], (string)$r['planned_n'], $h1($r['total_min']) . ($r['over_limit'] ? ' (ponad limit)' : ''), $n2($r['total_bb']), $n2($r['total_netto'])];
        return ['title' => SC_REPORTS['pay'], 'subtitle' => "Miesiąc: $ym · stawki brutto-brutto za lekcję; odbyte faktycznie, zaplanowane prognozowane",
            'headers' => ['Trener', 'Forma', 'Odbyte', 'Zaplanowane', 'Godziny', 'BB razem (zł)', 'Netto razem (zł)'], 'rows' => $rows, 'w' => [4, 1.6, 1.2, 1.6, 1.6, 2, 2], 'num' => [2, 3, 4, 5, 6]];
    }
    if ($key === 'rooms') {
        $rows = []; foreach (sc_report_rooms($ym) as $r) $rows[] = [($r['building'] !== '' ? $r['building'] . ' · ' : '') . $r['room'], (string)$r['lessons'], $h1($r['used_min']), $h1($r['avail_min']), $n2($r['util']) . '%', $h1((int)round($r['avg_people'] * 60)), (string)$r['capacity']];
        return ['title' => SC_REPORTS['rooms'], 'subtitle' => "Miesiąc: $ym · dostępność: dni robocze " . sprintf('%02d:00–%02d:00', SC_OPEN_FROM / 60, SC_OPEN_TO / 60),
            'headers' => ['Sala', 'Lekcje', 'Zajęte (h)', 'Dostępne (h)', 'Wykorzystanie', 'Śr. osób', 'Pojemność'], 'rows' => $rows, 'w' => [4, 1.2, 1.5, 1.7, 1.8, 1.4, 1.5], 'num' => [1, 2, 3, 4, 5, 6]];
    }
    if ($key === 'att') {
        $rows = []; foreach (sc_report_attendance($ym) as $r) $rows[] = [($r['code'] !== '' ? $r['code'] . ' · ' : '') . $r['course'], $r['instructor'], (string)$r['planned'], (string)$r['held'], (string)$r['cancelled'],
            $r['enrolled'] . ($r['max'] ? ' / ' . $r['max'] : ''), $r['rate'] === null ? '—' : $n2($r['rate']) . '%', $r['expected'] . ' z ' . $r['slots']];
        return ['title' => SC_REPORTS['att'], 'subtitle' => "Miesiąc: $ym · prognoza = zaplanowane lekcje × zapisani × frekwencja z 90 dni",
            'headers' => ['Grupa', 'Prowadzący', 'Zapl.', 'Odb.', 'Odw.', 'Zapisani / limit', 'Frekw. hist.', 'Prognoza obecności'], 'rows' => $rows, 'w' => [4, 2.6, 1, 1, 1, 1.8, 1.6, 2.2], 'num' => [2, 3, 4, 5, 6, 7]];
    }
    if ($key === 'cyc') {
        $rows = []; foreach (sc_report_cycles() as $r) $rows[] = [($r['code'] !== '' ? $r['code'] . ' · ' : '') . $r['course'], $r['instructor'], $r['pattern'], (string)$r['lessons'], $h1($r['min']),
            date('d.m.Y', strtotime($r['first'])), date('d.m.Y', strtotime($r['last'])), $h1($r['per_week_min']), $r['gap_days'] ? (string)$r['gap_days'] : '—'];
        return ['title' => SC_REPORTS['cyc'], 'subtitle' => 'Stan na ' . date('d.m.Y') . ' · lekcje zaplanowane od dziś; odstęp = mediana dni między terminami',
            'headers' => ['Grupa', 'Prowadzący', 'Wzorzec (dzień, godziny)', 'Lekcji', 'Godz.', 'Od', 'Do', 'Godz./tydz.', 'Odstęp (dni)'], 'rows' => $rows, 'w' => [3.4, 2.2, 4.2, 1, 1, 1.5, 1.5, 1.4, 1.4], 'num' => [3, 4, 7, 8]];
    }
    if ($key === 'week') {
        $d = sc_report_week_load($weeks); $rows = [];
        foreach ($d['rows'] as $r) { $row = [$r['name']]; foreach ($d['weeks'] as $w) $row[] = $r['cells'][$w] ? $h1($r['cells'][$w]) . ($r['week_limit'] && $r['cells'][$w] > $r['week_limit'] ? '!' : '') : '·'; $row[] = $h1($r['total']); $rows[] = $row; }
        $heads = ['Trener']; foreach ($d['weeks'] as $w) $heads[] = date('d.m', strtotime($w)); $heads[] = 'Razem';
        $widths = array_merge([3.4], array_fill(0, count($d['weeks']), 1), [1.2]);
        return ['title' => SC_REPORTS['week'], 'subtitle' => 'Tygodnie od ' . date('d.m.Y', strtotime($d['weeks'][0])) . ' (godziny; „!” = ponad limit tygodniowy trenera)',
            'headers' => $heads, 'rows' => $rows, 'w' => $widths, 'num' => range(1, count($d['weeks']) + 1)];
    }
    return ['title' => 'Raport', 'subtitle' => '', 'headers' => [], 'rows' => [], 'w' => [], 'num' => []];
}

function sc_export_pdf(array $ds, string $by): string {
    require_once dirname(__DIR__, 3) . '/includes/fpdf/fpdf.php';
    require_once dirname(__DIR__, 3) . '/modules/ti_pdf/logic/TiPdf.php';
    $pl = fn(string $s): string => TiPdf::pl($s);
    $pdf = new TiPdf('L', 'mm', 'A4'); $pdf->SetAutoPageBreak(true, 15); $pdf->SetMargins(12, 12, 12); $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24; $sumw = array_sum($ds['w']) ?: 1; $cw = array_map(fn($x) => $x / $sumw * $W, $ds['w']);
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl($ds['title']), 0, 1, 'L', true);
    $pdf->SetTextColor(80, 80, 80); $pdf->SetFont('Helvetica', '', 8.5); $pdf->Cell($W, 6, $pl($ds['subtitle']), 0, 1); $pdf->Ln(1);
    $hdr = function () use ($pdf, $ds, $cw, $pl) {
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225); $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', 'B', 8.5);
        foreach ($ds['headers'] as $i => $t) $pdf->Cell($cw[$i], 7, $pl($t), 1, 0, 'C', true);
        $pdf->Ln();
    };
    $hdr(); $pdf->SetFont('Helvetica', '', 8.5);
    foreach ($ds['rows'] as $r) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 22) { $pdf->AddPage(); $hdr(); $pdf->SetFont('Helvetica', '', 8.5); }
        foreach ($r as $i => $v) $pdf->Cell($cw[$i], 6.3, $pl(mb_strimwidth((string)$v, 0, max(6, (int)($cw[$i] / 1.75)), '…')), 1, 0, in_array($i, $ds['num'], true) ? 'R' : 'L');
        $pdf->Ln();
    }
    if (!$ds['rows']) { $pdf->Cell($W, 8, $pl('Brak danych dla wybranego okresu.'), 0, 1); }
    $pdf->SetAutoPageBreak(false); $pdf->SetY(-15); $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by), 0, 0, 'L');
    return $pdf->Output('S');
}

function sc_export_docx(array $ds, string $by, string $filename = 'raport_planisty.docx'): void {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $w = new \PhpOffice\PhpWord\PhpWord();
    $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
    $sec = $w->addSection(['orientation' => 'landscape']);
    $sec->addFooter()->addText('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by, ['size' => 7, 'color' => '828282']);
    $sec->addText($ds['title'], ['bold' => true, 'size' => 15, 'color' => '0F5096']);
    $sec->addText($ds['subtitle'], ['size' => 9, 'color' => '555555']);
    $sumw = array_sum($ds['w']) ?: 1; $tw = 14000;
    $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 50]);
    $t->addRow(null, ['tblHeader' => true]);
    foreach ($ds['headers'] as $i => $h) $t->addCell((int)($ds['w'][$i] / $sumw * $tw), ['bgColor' => 'E0E8F4'])->addText($h, ['bold' => true, 'size' => 8.5]);
    foreach ($ds['rows'] as $r) {
        $t->addRow();
        foreach ($r as $i => $v) $t->addCell((int)($ds['w'][$i] / $sumw * $tw))->addText((string)$v, ['size' => 8.5], in_array($i, $ds['num'], true) ? ['alignment' => 'right'] : []);
    }
    if (!$ds['rows']) $sec->addText('Brak danych dla wybranego okresu.');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save('php://output');
}

function sc_export_csv(array $ds, string $name): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $ds['headers'], ';'); foreach ($ds['rows'] as $r) fputcsv($o, $r, ';');
    fclose($o);
}

/** Wersje robocze (szkice) lekcji grupy jako tabela do wydruku — z typem kursu i formą każdej lekcji. */
function sc_drafts_dataset(int $course_id): array {
    require_once __DIR__ . '/schedulerValidator.php';
    $c = db_one("SELECT c.name, c.class_type, COALESCE(u.name,'—') AS iname, COALESCE(st.name,'') AS subject
                   FROM k30_ti_courses c LEFT JOIN users u ON u.id=c.instructor_id LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id WHERE c.id=?", [$course_id])
        ?: ['name' => '', 'class_type' => '', 'iname' => '—', 'subject' => ''];
    $hybrid = ti_course_is_hybrid($course_id);
    $TYPE = ['individual' => 'Indywidualny', 'group' => 'Grupowy', 'hybrid' => 'Hybryda (online + stacjonarnie)'];
    $DN = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    $M = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'online (Zoom)', 'zdalna_inne' => 'online (inne)'];
    $rows = []; $n = 0; $min = 0; $by_mode = ['stacjonarnie' => 0, 'online' => 0, 'bez metody' => 0];
    foreach (db_all("SELECT s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic, s.notes, s.lesson_method, s.rate_override, COALESCE(r.name,'') AS room, COALESCE(u.name,'') AS iname
                       FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id=s.room_id LEFT JOIN users u ON u.id=s.instructor_id
                      WHERE s.course_id=? AND s.status='draft' AND s.draft_id IS NULL ORDER BY s.lesson_date, s.time_from", [$course_id]) as $r) {
        $n++; $dur = (int)$r['duration_min'] ?: max(0, ti_hm2min((string)$r['time_to']) - ti_hm2min((string)$r['time_from'])); $min += $dur;
        $lm = (string)$r['lesson_method'];
        $mode = $lm === 'stacjonarna' ? 'stacjonarnie' : (in_array($lm, ['zdalna_zoom', 'zdalna_inne'], true) ? 'online' : 'bez metody');
        $by_mode[$mode] += $dur;
        $form = $M[$lm] ?? ($hybrid ? '⚠ BRAK METODY' : '—');
        $room = $r['room'] !== '' ? $r['room'] : ($lm === 'stacjonarna' ? '⚠ bez sali' : '');
        $rate = ((float)($r['rate_override'] ?? 0) > 0.005) ? number_format((float)$r['rate_override'], 2, ',', '') . ' zł' : '';
        $rows[] = [(string)$n, date('d.m.Y', strtotime($r['lesson_date'])), $DN[(int)date('N', strtotime($r['lesson_date']))] ?? '',
            $r['time_from'] !== '' ? substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) : '—',
            number_format($dur / 60, 1, ',', ''), $form, $room, $r['iname'], $rate, (string)$r['topic'], (string)$r['notes']];
    }
    $h = fn(int $m) => number_format($m / 60, 1, ',', '');
    $sum = 'stacjonarnie ' . $h($by_mode['stacjonarnie']) . ' h · online ' . $h($by_mode['online']) . ' h' . ($by_mode['bez metody'] ? ' · bez metody ' . $h($by_mode['bez metody']) . ' h' : '');
    return ['title' => 'Wersje robocze lekcji — ' . $c['name'],
        'subtitle' => 'Typ kursu: ' . ($TYPE[$c['class_type']] ?? 'Indywidualny') . ($hybrid && $c['class_type'] !== 'hybrid' ? ' (hybrydowy)' : '') . ($c['subject'] !== '' ? ' · rodzaj zajęć: ' . $c['subject'] : '')
            . ' · prowadzący: ' . $c['iname'] . ' · stan na ' . date('d.m.Y H:i') . ' · ' . $n . ' lekcji, razem ' . $h($min) . ' h (' . $sum . ') · niewidoczne dla kursantów, nie liczą się do rozliczeń',
        'headers' => ['Lp.', 'Data', 'Dzień', 'Godziny', 'Godz.', 'Forma zajęć', 'Sala', 'Trener (zastępstwo)', 'Stawka ind.', 'Temat', 'Notatki'], 'rows' => $rows,
        'keys' => array_keys(SC_DRAFT_COLS),
        'w' => [0.8, 1.6, 1, 1.8, 1, 2, 1.8, 2, 1.4, 3, 3], 'num' => [4, 8]];
}

/** Kolumny wydruku wersji roboczych (klucz => etykieta) — do wyboru przez użytkownika. */
const SC_DRAFT_COLS = ['lp' => 'Lp.', 'data' => 'Data', 'dzien' => 'Dzień tygodnia', 'godziny' => 'Godziny', 'godz' => 'Czas (godz.)', 'forma' => 'Forma zajęć (stacjonarnie / online)',
                       'sala' => 'Sala', 'trener' => 'Trener (zastępstwo)', 'stawka' => 'Stawka indywidualna', 'temat' => 'Temat', 'notatki' => 'Notatki'];

/** Zawęża tabelę do wybranych kolumn (klucze z $ds['keys']); pusty wybór = wszystkie. */
function sc_dataset_columns(array $ds, array $want): array {
    $keys = $ds['keys'] ?? [];
    $idx = []; foreach ($keys as $i => $k) if (in_array($k, $want, true)) $idx[] = $i;
    if (!$idx || count($idx) === count($keys)) return $ds;
    $pick = fn(array $a) => array_values(array_map(fn($i) => $a[$i], $idx));
    $ds['headers'] = $pick($ds['headers']); $ds['w'] = $pick($ds['w']);
    $ds['rows'] = array_map($pick, $ds['rows']);
    $ds['num'] = array_values(array_keys(array_filter($idx, fn($i) => in_array($i, $ds['num'], true))));
    return $ds;
}

function sc_export_xlsx(array $ds, string $by, string $filename): void {
    require_once dirname(__DIR__, 3) . '/includes/xlsx.php';
    $x = new XlsxWriter(); $x->addSheet('Robocze');
    $x->writeRow([$ds['title']], ['header']); $x->writeRow([$ds['subtitle']]); $x->writeRow([]);
    $x->writeRow($ds['headers'], ['header']);
    foreach ($ds['rows'] as $r) $x->writeRow($r);
    if (!$ds['rows']) $x->writeRow(['Brak wersji roboczych.']);
    $x->writeRow([]); $x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by]);
    $x->output($filename);
}

/* ───────── Wersja tekstowa bez tabel (czytniki ekranu) ───────── */

/** Pozycje tabeli jako pełne zdania: „1. Data: …. Godziny: …." (puste pola pomijane). @return list<string> */
function sc_text_items(array $ds): array {
    $out = [];
    foreach ($ds['rows'] as $i => $r) {
        $parts = [];
        foreach ($ds['headers'] as $k => $h) {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '' || $v === '—' || ($h === 'Lp.')) continue;
            $parts[] = rtrim($h, '.:') . ': ' . str_replace(['⚠ ', '⚠'], 'uwaga: ', $v);
        }
        $out[] = ($i + 1) . '. ' . ($parts ? implode('. ', $parts) . '.' : 'brak danych.');
    }
    return $out;
}

/** Dostępna strona HTML: nagłówki, lista numerowana, bez tabel; gotowa do druku. */
function sc_export_text_html(array $ds, string $by): string {
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $items = sc_text_items($ds);
    $h = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($ds['title']) . '</title>'
       . '<style>body{font:1.1rem/1.6 system-ui,Arial,sans-serif;max-width:50rem;margin:1.5rem auto;padding:0 1rem;color:#111}h1{font-size:1.6rem}h2{font-size:1.25rem;margin-top:1.5rem}'
       . 'li{margin:.6rem 0}.meta{color:#333}.skip{position:absolute;left:-999px}.skip:focus{left:1rem;top:1rem;background:#fff;padding:.4rem}@media print{.noprint{display:none}body{font-size:12pt}}</style></head><body>'
       . '<a class="skip noprint" href="#lista">Przejdź do listy</a><main><h1>' . $e($ds['title']) . '</h1><p class="meta">' . $e($ds['subtitle']) . '</p>'
       . '<p class="noprint"><button onclick="window.print()">Drukuj</button></p>'
       . '<h2 id="lista">Pozycje: ' . count($items) . '</h2>';
    $h .= $items ? '<ol>' . implode('', array_map(fn($t) => '<li>' . $e(preg_replace('/^\d+\.\s*/', '', $t)) . '</li>', $items)) . '</ol>' : '<p>Brak danych.</p>';
    return $h . '<footer><p class="meta">Wygenerowano: ' . $e(date('d.m.Y H:i')) . ' przez ' . $e($by) . '.</p></footer></main></body></html>';
}

function sc_export_text_plain(array $ds, string $by): string {
    $t = $ds['title'] . "\n" . str_repeat('=', min(60, mb_strlen($ds['title']))) . "\n" . $ds['subtitle'] . "\n\n";
    $items = sc_text_items($ds);
    $t .= $items ? implode("\n", $items) . "\n" : "Brak danych.\n";
    return $t . "\nWygenerowano: " . date('d.m.Y H:i') . ' przez ' . $by . ".\n";
}

/** Wysyła wersję tekstową: html (strona dostępna) albo txt (plik). */
function sc_export_text_send(array $ds, string $by, string $fmt, string $basename): void {
    while (ob_get_level() > 0) ob_end_clean();
    if ($fmt === 'txt') {
        header('Content-Type: text/plain; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $basename . '.txt"');
        echo sc_export_text_plain($ds, $by); return;
    }
    header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: private, no-store');
    echo sc_export_text_html($ds, $by);
}

/** Lista wszystkich lekcji grupy (odbyte, zaplanowane, odwołane) jako tabela — dla planu zajęć w wersji tekstowej. */
function sc_lesson_list_dataset(int $course_id): array {
    require_once dirname(__DIR__, 2) . '/ti_pdf/logic/lessonList.php';
    $c = db_one("SELECT c.name, c.class_type, COALESCE(u.name,'—') AS iname FROM k30_ti_courses c LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$course_id]) ?: ['name' => '', 'class_type' => '', 'iname' => '—'];
    $rows = []; foreach (ti_course_lesson_list($course_id) as $r) $rows[] = [(string)$r['n'], $r['date'], $r['dow'], $r['time'], $r['method'], $r['topic'], $r['status_label'] . ($r['upcoming'] ? ' (nadchodząca)' : '')];
    return ['title' => 'Lista zajęć — ' . $c['name'], 'subtitle' => 'Prowadzący: ' . $c['iname'] . ' · stan na ' . date('d.m.Y H:i') . ' · odbyte, zaplanowane i odwołane lekcje',
        'headers' => ['Lp.', 'Data', 'Dzień', 'Godziny', 'Forma', 'Temat', 'Status'], 'rows' => $rows, 'keys' => [], 'w' => [1, 1, 1, 1, 1, 1, 1], 'num' => []];
}
