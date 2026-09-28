<?php
/**
 * modules/ti_pdf/logic/dziennik.php — „Dziennik zajęć” grupy TI do druku (PDF).
 *
 * Układ wzorowany na drukach dziennika zajęć pozaszkolnych/kursowych
 * (strona tytułowa → rozkład zajęć → uczestnicy → program → wykaz
 * uczęszczania → realizacja programu → zestawienie ocen → hospitacje →
 * organizacja kursu i sprawozdanie), ale WYPEŁNIONY danymi z systemu:
 * terminy, obecności, tematy, oceny, protokoły, zaświadczenia.
 *
 * Dane osobowe (data urodzenia, PESEL, opiekun) tylko na żądanie
 * ($opts['personal'] = true) — domyślnie wydruk bez nich.
 * Wywołanie: karty30/ti/dydaktyk/dziennik_pdf.php (tylko kierownik).
 */
require_once __DIR__ . '/TiPdf.php';

function ti_dziennik_pdf(int $course_id, array $opts = []): ?string
{
    require_once dirname(__DIR__, 3) . '/includes/karty30.php';
    $c = k30_ti_course_get($course_id);
    if (!$c) return null;
    $personal = !empty($opts['personal']);
    $pl  = [TiPdf::class, 'pl'];
    $org = (string)org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $adr = trim((string)org_setting('org_adres'));
    $mia = trim((string)org_setting('org_miejscowosc'));
    if ($mia !== '' && mb_stripos($adr, $mia) === false) $adr = trim($adr . ' ' . $mia);   // adres bywa już z miejscowością
    $d   = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
    $held = K30_TI_HELD_STATUSES;

    // ── Dane ────────────────────────────────────────────────────────────────
    $students = db_all(
        "SELECT e.client_id, e.status AS enr_status, e.start_date, e.end_date, cl.name, cl.pesel, cl.date_of_birth, cl.phone,
                a.guardian_name, a.guardian_phone, a.is_minor
           FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
           LEFT JOIN k30_ti_student_accounts a ON a.client_id = e.client_id
          WHERE e.course_id = ?
          ORDER BY cl.name COLLATE NOCASE", [$course_id]
    );
    $nr = []; foreach ($students as $i => $s) $nr[(int)$s['client_id']] = $i + 1;
    $sessions = db_all(
        "SELECT s.*, COALESCE(u.name, iu.name) AS instr_name
           FROM k30_ti_sessions s
           LEFT JOIN users u  ON u.id = s.instructor_id
           LEFT JOIN users iu ON iu.id = ?
          WHERE s.course_id = ? AND s.status NOT IN ('draft', 'reserved')
          ORDER BY s.lesson_date, s.time_from", [(int)$c['instructor_id'], $course_id]
    );
    $att = [];
    foreach (db_all("SELECT a.* FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id WHERE s.course_id = ?", [$course_id]) as $a) {
        $att[(int)$a['session_id']][(int)$a['client_id']] = $a;
    }
    $done = array_values(array_filter($sessions, fn($s) => in_array($s['status'], $held, true)));
    $mark = function (array $s, int $cid) use ($att): string {
        if ($s['status'] === 'cancelled') return 'o';
        $a = $att[(int)$s['id']][$cid] ?? null;
        if (!$a) return '';
        if (!empty($a['cancelled'])) return 'x';
        if (!empty($a['attended'])) return '+';
        return $s['lesson_date'] <= date('Y-m-d') && in_array($s['status'], K30_TI_HELD_STATUSES, true) ? '-' : '';
    };
    $weekly = function_exists('ti_course_weekly_slots') ? ti_course_weekly_slots($course_id) : ['rows_time' => [], 'grid' => [], 'dow_cols' => []];
    $curric = function_exists('k30_ti_curriculum_list') ? k30_ti_curriculum_list($course_id, true) : [];
    $finals = [];
    try {
        foreach (db_all("SELECT e.client_id, e.value_text FROM k30_ti_protocol_entries e JOIN k30_ti_protocols p ON p.id = e.protocol_id
                          WHERE p.course_id = ? AND p.status = 'approved' AND e.value_text != '' ORDER BY p.approved_at", [$course_id]) as $r) {
            $finals[(int)$r['client_id']] = (string)$r['value_text'];               // ostatni zatwierdzony
        }
    } catch (\Throwable $e) {}
    $grades = [];
    foreach (db_all("SELECT client_id, value_num, weight FROM k30_ti_grades WHERE course_id = ?", [$course_id]) as $g) {
        $grades[(int)$g['client_id']][] = $g;
    }
    $certs = [];
    try { foreach (db_all("SELECT client_id FROM k30_ti_certs WHERE course_id = ? AND revoked_at IS NULL", [$course_id]) as $r) $certs[(int)$r['client_id']] = true; } catch (\Throwable $e) {}

    $years = array_map(fn($s) => (int)substr((string)$s['lesson_date'], 0, 4) - ((int)substr((string)$s['lesson_date'], 5, 2) < 9 ? 1 : 0), $sessions);
    $sy_from = $years ? min($years) : (int)date('Y') - ((int)date('n') < 9 ? 1 : 0);
    $sy_to   = ($years ? max($years) : $sy_from) + 1;

    // ── PDF ─────────────────────────────────────────────────────────────────
    $pdf = new TiPdf('P', 'mm', 'A4');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->SetTitle($pl('Dziennik zajęć — ' . $c['name']), false);

    $W = fn() => $pdf->GetPageWidth() - 24;
    $section = function (string $t, string $orient = 'P') use ($pdf, $pl, $W, $c): void {
        $pdf->AddPage($orient);
        $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 12);
        $pdf->Cell($W(), 8, $pl($t), 0, 1, 'L', true);
        $pdf->SetTextColor(100, 100, 100); $pdf->SetFont('DejaVu', '', 7.5);
        $pdf->Cell($W(), 5, $pl('Dziennik zajęć — ' . $c['name']), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(1);
    };
    /** Tabela z nagłówkiem powtarzanym po przełamaniu strony; wiersze z zawijaniem kolumny $wrap. */
    $table = function (array $cols, array $rows, float $lh = 5.2, ?int $wrap = null, float $fs = 8) use ($pdf, $pl): void {
        $head = function () use ($pdf, $pl, $cols, $fs) {
            $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225); $pdf->SetFont('DejaVu', 'B', $fs - 0.5);
            foreach ($cols as [$lbl, $w, $al]) $pdf->Cell($w, 6.5, $pl($lbl), 1, 0, 'C', true);
            $pdf->Ln(); $pdf->SetFont('DejaVu', '', $fs);
        };
        $head();
        $bottom = $pdf->GetPageHeight() - 18;
        foreach ($rows as $ri => $row) {
            $h = $lh;
            if ($wrap !== null) {
                $w = $cols[$wrap][1] - 2; $txt = $pl((string)$row[$wrap]); $lines = 1; $cur = 0;
                foreach (preg_split('/\s+/', $txt) as $word) {
                    $ww = $pdf->GetStringWidth($word . ' ');
                    if ($cur + $ww > $w && $cur > 0) { $lines++; $cur = $ww; } else { $cur += $ww; }
                }
                $h = max($lh, $lines * 4.2 + 1);
            }
            if ($pdf->GetY() + $h > $bottom) { $pdf->AddPage($pdf->GetPageWidth() > $pdf->GetPageHeight() ? 'L' : 'P'); $head(); }
            $pdf->SetFillColor(241, 245, 249);
            $x0 = $pdf->GetX(); $y0 = $pdf->GetY(); $fill = $ri % 2 === 1;
            foreach ($cols as $ci => [$lbl, $w, $al]) {
                $x = $pdf->GetX();
                if ($ci === $wrap) {
                    $pdf->Rect($x, $y0, $w, $h, $fill ? 'DF' : 'D');
                    $pdf->SetXY($x + 1, $y0 + 0.6);
                    $pdf->MultiCell($w - 2, 4.2, $pl((string)$row[$ci]), 0, $al);
                    $pdf->SetXY($x + $w, $y0);
                } else {
                    $pdf->Cell($w, $h, $pl((string)$row[$ci]), 1, 0, $al, $fill);
                }
            }
            $pdf->SetXY($x0, $y0 + $h);
        }
    };

    // 1. Strona tytułowa
    $pdf->AddPage();
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W(), 5, $pl($org), 0, 1, 'R');
    $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W(), 5, $pl($adr), 0, 1, 'R');
    $pdf->SetDrawColor(16, 51, 92); $pdf->Line(12 + $W() / 2, $pdf->GetY() + 1, 12 + $W(), $pdf->GetY() + 1);
    $pdf->SetDrawColor(190, 205, 225); $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(45);
    $pdf->SetFont('DejaVu', 'B', 28); $pdf->SetTextColor(16, 51, 92);
    $pdf->Cell($W(), 14, $pl('DZIENNIK ZAJĘĆ'), 0, 1, 'C');
    $pdf->SetFont('DejaVu', '', 15); $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W(), 10, $pl('Rok szkolny ' . $sy_from . '/' . $sy_to), 0, 1, 'C');
    $pdf->Ln(6);
    $pdf->SetFont('DejaVu', 'B', 14);
    $pdf->MultiCell($W(), 8, $pl((string)$c['name']), 0, 'C');
    $pdf->SetFont('DejaVu', '', 10); $pdf->SetTextColor(90, 90, 90);
    $forma = implode(', ', array_filter([(string)($c['subject_name'] ?? ''), (string)($c['location'] ?? '')], fn($x) => trim($x) !== ''));
    if ($forma !== '') $pdf->Cell($W(), 6, $pl($forma), 0, 1, 'C');
    $pdf->Cell($W(), 6, $pl('Zajęcia ' . ($done ? 'od ' . $d($done[0]['lesson_date']) . ' do ' . $d(end($done)['lesson_date']) : '— brak odbytych zajęć')), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($pdf->GetPageHeight() - 70);
    $pdf->SetFont('DejaVu', '', 10);
    $half = $W() / 2;
    $pdf->Cell($half, 6, '', 0, 0);
    $pdf->Cell($half, 6, $pl((string)($c['instructor_name'] ?? '')), 'B', 1, 'C');
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($half, 5, '', 0, 0); $pdf->Cell($half, 5, $pl('imię i nazwisko prowadzącego'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4); $pdf->SetFont('DejaVu', '', 7.5); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell($W(), 5, $pl('Wygenerowano z systemu: ' . date('d.m.Y H:i') . ($personal ? ' · zawiera dane osobowe' : '')), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // 2. Tygodniowy rozkład zajęć
    $section('Tygodniowy rozkład zajęć');
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W(), 5, $pl('Stan na dzień ' . date('d.m.Y') . (($weekly['source'] ?? '') === 'recent' ? ' — brak nadchodzących terminów, rozkład wg ostatnich zajęć' : '')), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $dows = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 0 => 'Niedziela'];
    $wr = [];
    foreach ($weekly['rows_time'] as $tk => $t) {
        $row = [substr((string)$t['from'], 0, 5) . '–' . substr((string)$t['to'], 0, 5)];
        $lbl = (string)(($c['subject_abbr'] ?? '') ?: 'zajęcia');   // „•” nie istnieje w ISO-8859-2
        foreach (array_keys($dows) as $dw) $row[] = isset($weekly['grid'][$tk][$dw]) ? $lbl : '';
        $wr[] = $row;
    }
    $cols = [['Godziny', 25, 'C']]; foreach ($dows as $l) $cols[] = [$l, ($W() - 25) / 7, 'C'];
    $wr ? $table($cols, $wr, 7) : $pdf->Cell($W(), 6, $pl('Brak zaplanowanych terminów.'), 0, 1);

    // 3. Uczestnicy
    $section('Uczestnicy' . ($personal ? ' — dane osobowe' : ''));
    $rows = [];
    foreach ($students as $i => $s) {
        $st = $s['enr_status'] === 'active' ? 'aktywny' : 'wypisany';
        $okres = trim(($s['start_date'] ? 'od ' . $d($s['start_date']) : '') . ($s['end_date'] ? ' do ' . $d($s['end_date']) : ''));
        $rows[] = $personal
            ? [$i + 1, $s['name'], $d($s['date_of_birth']), (string)$s['pesel'], trim(($s['guardian_name'] ?? '') . ' ' . ($s['guardian_phone'] ?? '')), $st]
            : [$i + 1, $s['name'], $st, $okres];
    }
    $cols = $personal
        ? [['Nr', 9, 'C'], ['Nazwisko i imię', 52, 'L'], ['Data ur.', 20, 'C'], ['PESEL', 26, 'C'], ['Opiekun (tel.)', $W() - 127, 'L'], ['Status', 20, 'C']]
        : [['Nr', 9, 'C'], ['Nazwisko i imię', 80, 'L'], ['Status', 25, 'C'], ['Okres uczestnictwa', $W() - 114, 'L']];
    $rows ? $table($cols, $rows, 6) : $pdf->Cell($W(), 6, $pl('Brak uczestników.'), 0, 1);

    // 4. Program zajęć
    $section('Program zajęć');
    $rows = []; $sum = 0;
    foreach ($curric as $i => $it) {
        $h = (int)($it['est_minutes'] ?? 0); $sum += $h;
        $rows[] = [$i + 1, trim(($it['section'] ? $it['section'] . ' — ' : '') . $it['title'] . ($it['description'] ? ': ' . $it['description'] : '')), $h ? rtrim(rtrim(number_format($h / 60, 1, ',', ''), '0'), ',') : ''];
    }
    if ($rows) {
        $table([['Lp.', 10, 'C'], ['Cele, zadania, tematyka zajęć', $W() - 38, 'L'], ['Godz.', 28, 'C']], $rows, 5.5, 1);
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->Cell($W() - 28, 6, $pl('Razem przeznaczonych godzin'), 1, 0, 'R'); $pdf->Cell(28, 6, $pl(rtrim(rtrim(number_format($sum / 60, 1, ',', ''), '0'), ',')), 1, 1, 'C');
    } else {
        $pdf->SetFont('DejaVu', '', 9); $pdf->Cell($W(), 6, $pl('Grupa nie ma planu nauczania w systemie (zakładka Sylabus / Plan).'), 0, 1);
    }

    // 5. Wykaz uczęszczania — poziomo, po 30 dat na stronę
    $grid = array_values(array_filter($sessions, fn($s) => in_array($s['status'], $held, true) || $s['status'] === 'cancelled'));
    foreach (array_chunk($grid, 30) as $ci => $chunk) {
        $section('Wykaz uczęszczania uczestników na zajęcia' . (count($grid) > 30 ? ' (' . ($ci + 1) . '/' . (int)ceil(count($grid) / 30) . ')' : ''), 'L');
        $cols = [['Nr', 8, 'C'], ['Nazwisko i imię', 52, 'L']];
        $dw = min(12, ($W() - 60) / count($chunk));   // przy kilku datach szersze kolumny, max 12 mm
        foreach ($chunk as $s) $cols[] = [date('d.m', strtotime((string)$s['lesson_date'])), $dw, 'C'];
        $rows = [];
        foreach ($students as $i => $st) {
            $r = [$i + 1, $st['name']];
            foreach ($chunk as $s) $r[] = $mark($s, (int)$st['client_id']);
            $rows[] = $r;
        }
        $table($cols, $rows, 5.2, null, 6.5);
    }
    if (!$grid) { $section('Wykaz uczęszczania uczestników na zajęcia', 'L'); $pdf->Cell($W(), 6, $pl('Brak odbytych zajęć.'), 0, 1); }
    $pdf->Ln(2); $pdf->SetFont('DejaVu', '', 7); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W(), 4, $pl('+ obecny    - nieobecny    x odwołany udział    o lekcja odwołana'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    // podsumowanie frekwencji
    $rows = [];
    foreach ($students as $i => $st) {
        $p = 0; $n = 0;
        foreach ($done as $s) { $m = $mark($s, (int)$st['client_id']); if ($m === '+') $p++; if ($m === '+' || $m === '-') $n++; }
        $rows[] = [$i + 1, $st['name'], $p, $n, $n ? round($p / $n * 100) . '%' : '—'];
    }
    if ($rows) { $pdf->Ln(3); $table([['Nr', 8, 'C'], ['Nazwisko i imię', 90, 'L'], ['Obecności', 30, 'C'], ['Lekcji', 30, 'C'], ['Frekwencja', 30, 'C']], $rows, 5.5); }

    // 6. Realizacja programu zajęć
    $section('Realizacja programu zajęć');
    $rows = []; $hours = 0.0;
    foreach ($sessions as $s) {
        if (!in_array($s['status'], $held, true) && $s['status'] !== 'cancelled') continue;
        $p = 0; $a = 0;
        foreach ($att[(int)$s['id']] ?? [] as $x) { if (!empty($x['cancelled'])) continue; !empty($x['attended']) ? $p++ : $a++; }
        $hrs = round(((int)$s['duration_min']) / 60, 1); if ($s['status'] !== 'cancelled') $hours += $hrs;
        $topic = $s['status'] === 'cancelled' ? 'ZAJĘCIA ODWOŁANE' . ($s['cancel_reason'] ? ' — ' . $s['cancel_reason'] : '') : (string)($s['topic'] ?: '—');
        $rows[] = [$d($s['lesson_date']), $s['status'] === 'cancelled' ? '' : rtrim(rtrim(number_format($hrs, 1, ',', ''), '0'), ','),
                   $s['status'] === 'cancelled' ? '' : $p, $s['status'] === 'cancelled' ? '' : $a, $topic, (string)($s['instr_name'] ?? '')];
    }
    if ($rows) {
        $table([['Data', 20, 'C'], ['Godz.', 12, 'C'], ['Obecni', 14, 'C'], ['Nieob.', 14, 'C'], ['Temat — treść zajęć', $W() - 102, 'L'], ['Prowadzący', 42, 'L']], $rows, 5.5, 4, 7.5);
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->Cell(20, 6, $pl('Razem'), 1, 0, 'R'); $pdf->Cell(12, 6, $pl(rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',')), 1, 0, 'C');
        $pdf->Cell($W() - 32, 6, $pl(count($done) . ' odbytych zajęć'), 1, 1, 'L');
    } else { $pdf->Cell($W(), 6, $pl('Brak zajęć.'), 0, 1); }

    // 7. Zestawienie ocen
    $section('Zestawienie ocen');
    $rows = [];
    foreach ($students as $i => $st) {
        $gs = $grades[(int)$st['client_id']] ?? []; $sw = 0; $sv = 0; $cnt = 0;
        foreach ($gs as $g) { if ($g['value_num'] === null) continue; $w = max(1, (int)$g['weight']); $sv += (float)$g['value_num'] * $w; $sw += $w; $cnt++; }
        $rows[] = [$i + 1, $st['name'], $cnt ?: '', $sw ? number_format($sv / $sw, 2, ',', '') : '—', $finals[(int)$st['client_id']] ?? '—', isset($certs[(int)$st['client_id']]) ? 'tak' : ''];
    }
    $rows ? $table([['Nr', 8, 'C'], ['Nazwisko i imię', $W() - 118, 'L'], ['Ocen', 18, 'C'], ['Średnia ważona', 30, 'C'], ['Ocena końcowa', 36, 'C'], ['Zaświadcz.', 26, 'C']], $rows, 6)
          : $pdf->Cell($W(), 6, $pl('Brak uczestników.'), 0, 1);
    $pdf->Ln(2); $pdf->SetFont('DejaVu', '', 7); $pdf->SetTextColor(90, 90, 90);
    $pdf->MultiCell($W(), 4, $pl('Średnia ważona z ocen cząstkowych dziennika; ocena końcowa z ostatniego zatwierdzonego protokołu zajęć.'), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // 8. Hospitacje i wizytacje (do wypełnienia ręcznie)
    $section('Hospitacje i wizytacje');
    $table([['Lp.', 10, 'C'], ['Data', 22, 'C'], ['Temat zajęć', $W() - 132, 'L'], ['Imię i nazwisko, funkcja hospitującego', 70, 'L'], ['Podpis', 30, 'C']],
           array_map(fn($i) => [$i, '', '', '', ''], range(1, 14)), 12);

    // 9. Organizacja kursu i sprawozdanie
    $section('Organizacja kursu i sprawozdanie z kursu');
    $days = count(array_unique(array_map(fn($s) => $s['lesson_date'], $done)));
    $ended = count(array_filter($students, fn($s) => $s['enr_status'] === 'active' || !empty($s['end_date'])));
    $kv = [
        ['Instytucja organizująca', trim($org . ($adr !== '' ? ', ' . $adr : ''))],
        ['Kierownik', trim((string)org_setting('ti_manager_name') . ((string)org_setting('ti_manager_title') !== '' ? ' — ' . org_setting('ti_manager_title') : '')) ?: '—'],
        ['Prowadzący', (string)($c['instructor_name'] ?? '—')],
        ['Rodzaj zajęć', ($c['subject_name'] ?? '') ?: '—'],
        ['Data rozpoczęcia zajęć', $done ? $d($done[0]['lesson_date']) : '—'],
    ];
    $pdf->SetFont('DejaVu', '', 9);
    foreach ($kv as [$k, $v]) { $pdf->SetFont('DejaVu', 'B', 9); $pdf->Cell(55, 7, $pl($k . ':'), 0, 0); $pdf->SetFont('DejaVu', '', 9); $pdf->MultiCell($W() - 55, 7, $pl($v), 0, 'L'); }
    $pdf->Ln(4);
    $pdf->SetFont('DejaVu', 'B', 10); $pdf->Cell($W(), 7, $pl('Sprawozdanie z kursu'), 0, 1);
    $table([['Od', 23, 'C'], ['Do', 23, 'C'], ['Dni', 13, 'C'], ['Godzin', 17, 'C'], ['Rozpoczynających', 31, 'C'], ['Kończących', 24, 'C'], ['Zaświadczeń', 24, 'C'], ['Uwagi', $W() - 155, 'L']],
           [[$done ? $d($done[0]['lesson_date']) : '—', $done ? $d(end($done)['lesson_date']) : '—', $days,
             rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ','), count($students), $ended, count($certs), '']], 8);
    $pdf->Ln(24);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->Cell($W() / 2, 6, $pl(((string)org_setting('org_miejscowosc') ?: '') . ', dnia ' . date('d.m.Y')), 0, 0, 'L');
    $pdf->Cell($W() / 2, 6, '', 'B', 1);
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($W() / 2, 5, '', 0, 0); $pdf->Cell($W() / 2, 5, $pl('podpis kierownika'), 0, 1, 'C');

    return $pdf->Output('S');
}

/** Nazwa pliku dziennika. */
function ti_dziennik_filename(array $course): string
{
    $n = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$course['name']) ?: 'grupa');
    return 'dziennik_zajec_' . trim($n, '_') . '_' . date('Y-m-d') . '.pdf';
}
