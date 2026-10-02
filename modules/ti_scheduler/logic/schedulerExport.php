<?php
/**
 * modules/ti_scheduler/logic/schedulerExport.php — wydruki i eksporty raportów planisty (PDF, DOCX, CSV).
 * sc_report_dataset() zamienia raport na tabelę (nagłówki, wiersze, szerokości względne),
 * a sc_export_pdf/docx/csv() renderują ją jednolicie.
 */
require_once __DIR__ . '/schedulerReports.php';
require_once __DIR__ . '/schedulerScenarios.php';
require_once dirname(__DIR__, 2) . '/ti_pdf/logic/exportName.php';

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

/** Sekcje tabeli (grupowanie wydruku): domyślnie jedna sekcja bez tytułu ze wszystkimi wierszami. */
function sc_ds_sections(array $ds): array {
    return $ds['sections'] ?? [['title' => '', 'rows' => $ds['rows']]];
}

function sc_export_pdf(array $ds, string $by): string {
    require_once dirname(__DIR__, 3) . '/includes/fpdf/fpdf.php';
    require_once dirname(__DIR__, 3) . '/modules/ti_pdf/logic/TiPdf.php';
    $pl = fn(string $s): string => TiPdf::pl($s);
    $pdf = new TiPdf('L', 'mm', 'A4'); $pdf->SetAutoPageBreak(true, 15); $pdf->SetMargins(12, 12, 12); $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24; $sumw = array_sum($ds['w']) ?: 1; $cw = array_map(fn($x) => $x / $sumw * $W, $ds['w']);
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell($W, 9, $pl(mb_strimwidth($ds['title'], 0, 140, '…')), 0, 1, 'L', true);
    $pdf->SetTextColor(80, 80, 80); $pdf->SetFont('Helvetica', '', 8.5); $pdf->MultiCell($W, 4.5, $pl($ds['subtitle']), 0, 'L'); $pdf->Ln(1);
    $hdr = function () use ($pdf, $ds, $cw, $pl) {
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225); $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', 'B', 8.5);
        foreach ($ds['headers'] as $i => $t) $pdf->Cell($cw[$i], 7, $pl($t), 1, 0, 'C', true);
        $pdf->Ln();
    };
    $any = false; $hdr(); $pdf->SetFont('Helvetica', '', 8.5);
    foreach (sc_ds_sections($ds) as $sec) {
        if (!$sec['rows']) continue;
        if ($sec['title'] !== '') {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 34) { $pdf->AddPage(); $hdr(); }
            $pdf->SetFillColor(241, 245, 249); $pdf->SetTextColor(15, 40, 80); $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell($W, 6.5, $pl($sec['title'] . ' (' . count($sec['rows']) . ')'), 1, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->SetFont('Helvetica', '', 8.5);
        foreach ($sec['rows'] as $r) {
            $any = true;
            if ($pdf->GetY() > $pdf->GetPageHeight() - 22) { $pdf->AddPage(); $hdr(); $pdf->SetFont('Helvetica', '', 8.5); }
            foreach ($r as $i => $v) $pdf->Cell($cw[$i], 6.3, $pl(mb_strimwidth((string)$v, 0, max(6, (int)($cw[$i] / 1.75)), '…')), 1, 0, in_array($i, $ds['num'], true) ? 'R' : 'L');
            $pdf->Ln();
        }
    }
    if (!$any) { $pdf->Cell($W, 8, $pl('Brak danych dla wybranego okresu.'), 0, 1); }
    $pdf->SetAutoPageBreak(false); $pdf->SetY(-15); $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by), 0, 0, 'L');
    return $pdf->Output('S');
}

function sc_export_docx(array $ds, string $by, string $filename = 'raport_planisty.docx'): void {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    error_reporting(error_reporting() & ~E_DEPRECATED);   // PhpWord na PHP 8.5 zgłasza uwagi deprecated, które zepsułyby plik
    $w = new \PhpOffice\PhpWord\PhpWord();
    $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
    $sec = $w->addSection(['orientation' => 'landscape']);
    $sec->addFooter()->addText('Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by, ['size' => 7, 'color' => '828282']);
    $sec->addText($ds['title'], ['bold' => true, 'size' => 15, 'color' => '0F5096']);
    $sec->addText($ds['subtitle'], ['size' => 9, 'color' => '555555']);
    $sumw = array_sum($ds['w']) ?: 1; $tw = 14000; $any = false;
    foreach (sc_ds_sections($ds) as $s) {
        if (!$s['rows']) continue;
        $any = true;
        if ($s['title'] !== '') $sec->addText($s['title'] . ' (' . count($s['rows']) . ')', ['bold' => true, 'size' => 10.5, 'color' => '0F2850'], ['spaceBefore' => 200, 'spaceAfter' => 60]);
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 50]);
        $t->addRow(null, ['tblHeader' => true]);
        foreach ($ds['headers'] as $i => $h) $t->addCell((int)($ds['w'][$i] / $sumw * $tw), ['bgColor' => 'E0E8F4'])->addText($h, ['bold' => true, 'size' => 8.5]);
        foreach ($s['rows'] as $r) {
            $t->addRow();
            foreach ($r as $i => $v) $t->addCell((int)($ds['w'][$i] / $sumw * $tw))->addText((string)$v, ['size' => 8.5], in_array($i, $ds['num'], true) ? ['alignment' => 'right'] : []);
        }
    }
    if (!$any) $sec->addText('Brak danych dla wybranego okresu.');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save('php://output');
}

function sc_export_csv(array $ds, string $name): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $titled = false; foreach (sc_ds_sections($ds) as $s) if ($s['title'] !== '') $titled = true;
    fputcsv($o, $titled ? array_merge(['Sekcja'], $ds['headers']) : $ds['headers'], ';', '"', '\\');
    foreach (sc_ds_sections($ds) as $s) foreach ($s['rows'] as $r) fputcsv($o, $titled ? array_merge([$s['title']], $r) : $r, ';', '"', '\\');
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
        'keys' => array_keys(SC_DRAFT_COLS), 'sheet' => 'Robocze',
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
    if (isset($ds['sections'])) foreach ($ds['sections'] as &$sc) $sc['rows'] = array_map($pick, $sc['rows']);
    unset($sc);
    $ds['num'] = array_values(array_keys(array_filter($idx, fn($i) => in_array($i, $ds['num'], true))));
    return $ds;
}

function sc_export_xlsx(array $ds, string $by, string $filename): void {
    require_once dirname(__DIR__, 3) . '/includes/xlsx.php';
    $x = new XlsxWriter(); $x->addSheet($ds['sheet'] ?? 'Dane');
    $x->writeRow([$ds['title']], ['header']); $x->writeRow([$ds['subtitle']]); $x->writeRow([]);
    $x->writeRow($ds['headers'], ['header']);
    $any = false;
    foreach (sc_ds_sections($ds) as $s) {
        if (!$s['rows']) continue;
        if ($s['title'] !== '') $x->writeRow([$s['title'] . ' (' . count($s['rows']) . ')'], ['header']);
        foreach ($s['rows'] as $r) { $x->writeRow($r); $any = true; }
    }
    if (!$any) $x->writeRow(['Brak danych dla wybranego okresu.']);
    $x->writeRow([]); $x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by]);
    $x->output($filename);
}

/* ───────── Wersja tekstowa bez tabel (czytniki ekranu) ───────── */

/** Pozycje tabeli jako pełne zdania: „1. Data: …. Godziny: …." (puste pola pomijane). @return list<string> */
function sc_text_items(array $ds): array {
    return sc_text_rows($ds['headers'], $ds['rows']);
}

/** Wiersze jako zdania „1. Data: …. Godziny: …." (puste pola pomijane). @return list<string> */
function sc_text_rows(array $headers, array $rows): array {
    $out = [];
    foreach ($rows as $i => $r) {
        $parts = [];
        foreach ($headers as $k => $h) {
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
    $total = 0; foreach (sc_ds_sections($ds) as $s) $total += count($s['rows']);
    $h = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($ds['title']) . '</title>'
       . '<style>body{font:1.1rem/1.6 system-ui,Arial,sans-serif;max-width:50rem;margin:1.5rem auto;padding:0 1rem;color:#111}h1{font-size:1.6rem}h2{font-size:1.25rem;margin-top:1.5rem}'
       . 'li{margin:.6rem 0}.meta{color:#333}.skip{position:absolute;left:-999px}.skip:focus{left:1rem;top:1rem;background:#fff;padding:.4rem}@media print{.noprint{display:none}body{font-size:12pt}}</style></head><body>'
       . '<a class="skip noprint" href="#lista">Przejdź do listy</a><main><h1>' . $e($ds['title']) . '</h1><p class="meta">' . $e($ds['subtitle']) . '</p>'
       . '<p class="noprint"><button onclick="window.print()">Drukuj</button></p>';
    if (!$total) return $h . '<h2 id="lista">Pozycje: 0</h2><p>Brak danych.</p><footer><p class="meta">Wygenerowano: ' . $e(date('d.m.Y H:i')) . ' przez ' . $e($by) . '.</p></footer></main></body></html>';
    $first = true;
    foreach (sc_ds_sections($ds) as $s) {
        if (!$s['rows']) continue;
        $items = sc_text_rows($ds['headers'], $s['rows']);
        $h .= '<h2' . ($first ? ' id="lista"' : '') . '>' . $e(($s['title'] !== '' ? $s['title'] . ' — pozycji: ' : 'Pozycje: ') . count($items)) . '</h2>'
            . '<ol>' . implode('', array_map(fn($t) => '<li>' . $e(preg_replace('/^\d+\.\s*/', '', $t)) . '</li>', $items)) . '</ol>';
        $first = false;
    }
    return $h . '<footer><p class="meta">Wygenerowano: ' . $e(date('d.m.Y H:i')) . ' przez ' . $e($by) . '.</p></footer></main></body></html>';
}

function sc_export_text_plain(array $ds, string $by): string {
    $t = $ds['title'] . "\n" . str_repeat('=', min(60, mb_strlen($ds['title']))) . "\n" . $ds['subtitle'] . "\n\n";
    $any = false;
    foreach (sc_ds_sections($ds) as $s) {
        if (!$s['rows']) continue;
        $any = true;
        if ($s['title'] !== '') $t .= $s['title'] . ' (' . count($s['rows']) . ")\n" . str_repeat('-', min(60, mb_strlen($s['title']))) . "\n";
        $t .= implode("\n", sc_text_rows($ds['headers'], $s['rows'])) . "\n\n";
    }
    if (!$any) $t .= "Brak danych.\n";
    return rtrim($t) . "\n\nWygenerowano: " . date('d.m.Y H:i') . ' przez ' . $by . ".\n";
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


/* ───────── Wydruki planisty (widok kalendarza / scenariusze) ───────── */

const SC_PLANNER_COLS = ['data' => 'Data', 'dzien' => 'Dzień tygodnia', 'godziny' => 'Godziny', 'czas' => 'Czas (godz.)', 'grupa' => 'Grupa', 'typ' => 'Typ kursu',
                         'forma' => 'Forma zajęć', 'trener' => 'Trener', 'sala' => 'Sala', 'status' => 'Status', 'stawka' => 'Stawka indywidualna',
                         'temat' => 'Temat', 'zmiana' => 'Zmiana w scenariuszu'];
const SC_PLANNER_GROUPS = ['none' => 'Chronologicznie', 'instructor' => 'Wg trenera', 'room' => 'Wg sali', 'course' => 'Wg grupy', 'day' => 'Wg dnia'];

/**
 * Lekcje z widoku planisty jako tabela (z sekcjami wg wybranego grupowania).
 * $o: from, to (Y-m-d), scenario (0 = plan oficjalny), course_id, instructor_id, room_id (0 = wszystkie),
 *     group (none|instructor|room|course|day), cancelled (bool — uwzględnić odwołane).
 */
function sc_planner_dataset(array $o): array {
    $from = (string)($o['from'] ?? date('Y-m-d')); $to = (string)($o['to'] ?? $from);
    $scn = (int)($o['scenario'] ?? 0); if ($scn && !sc_scenario_open($scn)) $scn = 0;
    $cid = (int)($o['course_id'] ?? 0); $iid = (int)($o['instructor_id'] ?? 0); $rid = (int)($o['room_id'] ?? 0);
    $group = isset(SC_PLANNER_GROUPS[$o['group'] ?? '']) ? $o['group'] : 'none';
    $with_cancelled = !empty($o['cancelled']);
    $feed = sc_feed($from, $to, $scn);
    $types = []; foreach (db_all("SELECT id, class_type FROM k30_ti_courses") as $c) $types[(int)$c['id']] = TI_EXPORT_TYPE_LABELS[(string)$c['class_type']] ?? '';
    $DN = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    $M = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'online (Zoom)', 'zdalna_inne' => 'online (inne)'];
    $list = array_values(array_filter($feed['sessions'], fn($s) =>
        ($with_cancelled || $s['status'] !== 'cancelled') && (!$cid || $s['course_id'] === $cid) && (!$iid || $s['instructor_id'] === $iid) && (!$rid || $s['room_id'] === $rid)));
    $gkey = fn(array $s): string => match ($group) {
        'instructor' => $s['instructor'] !== '' ? $s['instructor'] : '~ bez trenera',
        'room'       => $s['room'] !== '' ? $s['room'] : '~ bez sali',
        'course'     => ($s['code'] !== '' ? $s['code'] . ' · ' : '') . $s['course'],
        'day'        => $s['date'],
        default      => '',
    };
    usort($list, fn($a, $b) => [$gkey($a), $a['date'], $a['from']] <=> [$gkey($b), $b['date'], $b['from']]);
    $hyb = []; $by_mode = ['stacjonarnie' => 0, 'online' => 0, 'bez metody' => 0]; $min = 0; $sections = []; $rows = []; $n = 0;
    foreach ($list as $s) {
        $n++; $dur = max(0, ti_hm2min($s['to']) - ti_hm2min($s['from'])); $min += $dur;
        $lm = $s['method']; $mode = $lm === 'stacjonarna' ? 'stacjonarnie' : (in_array($lm, ['zdalna_zoom', 'zdalna_inne'], true) ? 'online' : 'bez metody'); $by_mode[$mode] += $dur;
        $hyb[$s['course_id']] ??= ti_course_is_hybrid($s['course_id']);
        $forma = $M[$lm] ?? ($hyb[$s['course_id']] ? '⚠ BRAK METODY' : '—');
        $sala = $s['room'] !== '' ? $s['room'] : ($lm === 'stacjonarna' ? '⚠ bez sali' : '');
        $zm = '';
        if (!empty($s['changed']) && !empty($s['orig'])) {
            $or = $s['orig']; $z = [];
            if ($or['date'] !== $s['date'] || $or['from'] !== $s['from'] || $or['to'] !== $s['to']) $z[] = 'termin: ' . date('d.m.Y', strtotime($or['date'])) . ' ' . $or['from'] . '–' . $or['to'];
            if ($or['instructor_id'] !== $s['instructor_id']) $z[] = 'trener: ' . ($or['instructor'] ?: '—');
            if ($or['room_id'] !== $s['room_id']) $z[] = 'sala: ' . ($or['room'] ?: '—');
            $zm = 'było — ' . implode('; ', $z);
        }
        $row = [date('d.m.Y', strtotime($s['date'])), $DN[(int)date('N', strtotime($s['date']))] ?? '', $s['from'] . '–' . $s['to'], number_format($dur / 60, 1, ',', ''),
            ($s['code'] !== '' ? $s['code'] . ' · ' : '') . $s['course'], $types[$s['course_id']] ?? '', $forma, $s['instructor'], $sala,
            SC_STATUS_STYLES[$s['ds']]['label'] ?? $s['ds'], $s['rate'] ? number_format($s['rate'], 2, ',', '') . ' zł' : '', $s['topic'], $zm];
        $rows[] = $row;
        $title = match ($group) {
            'instructor' => $s['instructor'] !== '' ? 'Trener: ' . $s['instructor'] : 'Bez trenera',
            'room'       => $s['room'] !== '' ? 'Sala: ' . $s['room'] : 'Bez sali',
            'course'     => 'Grupa: ' . $gkey($s),
            'day'        => 'Dzień: ' . ($DN[(int)date('N', strtotime($s['date']))] ?? '') . ' ' . date('d.m.Y', strtotime($s['date'])),
            default      => '',
        };
        $k = $title; $sections[$k] ??= ['title' => $title, 'rows' => []]; $sections[$k]['rows'][] = $row;
    }
    $h = fn(int $m) => number_format($m / 60, 1, ',', '');
    $ver = $scn ? 'scenariusz „' . (string)(db_one("SELECT title FROM k30_sc_scenarios WHERE id=?", [$scn])['title'] ?? '#' . $scn) . '” (wersja robocza, nie wpływa na plan oficjalny)' : 'plan oficjalny';
    $flt = [];
    if ($cid) $flt[] = 'grupa: ' . (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? $cid);
    if ($iid) $flt[] = 'trener: ' . (string)(db_one("SELECT COALESCE(NULLIF(TRIM(first_name||' '||last_name),''), name) AS n FROM users WHERE id=?", [$iid])['n'] ?? $iid);
    if ($rid) $flt[] = 'sala: ' . (string)(db_one("SELECT name FROM k30_pl_rooms WHERE id=?", [$rid])['name'] ?? $rid);
    $range = $from === $to ? date('d.m.Y', strtotime($from)) : date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to));
    return ['title' => 'Plan zajęć — ' . $range, 'sheet' => 'Plan',
        'subtitle' => 'Wersja: ' . $ver . ($flt ? ' · filtr: ' . implode(', ', $flt) : '') . ' · ' . SC_PLANNER_GROUPS[$group] . ' · ' . $n . ' lekcji, razem ' . $h($min)
            . ' h (stacjonarnie ' . $h($by_mode['stacjonarnie']) . ' h · online ' . $h($by_mode['online']) . ' h' . ($by_mode['bez metody'] ? ' · bez metody ' . $h($by_mode['bez metody']) . ' h' : '') . ')'
            . ($with_cancelled ? ' · z odwołanymi' : ' · bez odwołanych'),
        'headers' => array_values(SC_PLANNER_COLS), 'keys' => array_keys(SC_PLANNER_COLS), 'rows' => $rows,
        'sections' => $group === 'none' ? null : array_values($sections),
        'w' => [1.5, 1.1, 1.7, 1, 3.2, 1.6, 1.8, 2.2, 2, 1.8, 1.5, 2.6, 2.6], 'num' => [3]];
}

/** Różnice scenariusza względem planu oficjalnego jako tabela do wydruku. */
function sc_scenario_diff_dataset(int $scn): array {
    $sc = db_one("SELECT title, note FROM k30_sc_scenarios WHERE id=?", [$scn]) ?: ['title' => '#' . $scn, 'note' => ''];
    $rows = []; foreach (sc_scenario_diff($scn) as $i => $d) $rows[] = [(string)($i + 1), $d['course'], $d['from'], $d['to'], $d['instructor'], $d['room']];
    return ['title' => 'Zmiany scenariusza „' . $sc['title'] . '” względem planu oficjalnego', 'sheet' => 'Zmiany',
        'subtitle' => 'Stan na ' . date('d.m.Y H:i') . ' · zmian: ' . count($rows) . ' · scenariusz jest wersją roboczą i nie wpływa na plan oficjalny do czasu publikacji' . ($sc['note'] !== '' ? ' · ' . $sc['note'] : ''),
        'headers' => ['Lp.', 'Grupa', 'Było', 'Będzie', 'Trener', 'Sala'], 'keys' => [], 'rows' => $rows, 'w' => [0.8, 3.6, 3, 3, 3, 3], 'num' => []];
}
