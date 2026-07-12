<?php
/**
 * karty30/ti/wup_report.php — Sprawozdanie do Wojewódzkiego Urzędu Pracy (TI).
 *
 * Tryby:
 *   (brak out)         → PODGLĄD HTML: filtry okresu, pola narracyjne (zmiany
 *                        w strukturze org., uzasadnienie braku danych, uzasadnienia
 *                        0 h prowadzących), wskaźniki + zapisane/podpisane sprawozdania.
 *   POST out=pdf       → generuje PDF (zapisuje też rekord sprawozdania).
 *   POST _op=upload_signed → wgranie podpisanego PDF-a do rekordu.
 *   GET  ?download=N   → pobranie podpisanego pliku sprawozdania #N.
 *
 * Dane: zajęcia wg prowadzących i grup, liczba osób/uczestników (w tym z
 * niepełnosprawnością — umowa PFRON), czas pracy, zajęcia online/stacjonarne
 * (wg flagi grupy), odwołania, frekwencja. Pomijane: grupy „Nie uwzględniaj w WUP"
 * oraz rekordy z „Test" w nazwie. Podstawa: ustawa z 20.04.2004 o promocji zatrudnienia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_participant_report.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

$user_id = (int)current_user()['id'];

// Usuwać zapisane sprawozdania może wyłącznie kierownictwo (admin lub zarząd).
$zarzad_ids = array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids'))));
$can_delete = is_admin() || in_array($user_id, $zarzad_ids, true);

// ── Ustawienia organizacji / RIS / kierownik ─────────────────────────────────────
$S = function (string $k): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    return trim((string)($r['value'] ?? ''));
};
$org_name   = $S('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = $S('org_adres');
$org_miejsc = $S('org_miejscowosc');
$org_nip    = $S('org_nip');
$org_regon  = $S('org_regon')     ?: '38311833100000';
$ris_number = $S('ti_ris_number') ?: 'KR.403.2023';
$ris_date   = $S('ti_ris_date');
$ris_voiv   = $S('ti_ris_voivodeship');
$mgr_name   = $S('ti_manager_name');
$mgr_title  = $S('ti_manager_title') ?: 'Kierownik';

// ── Okres + filtr prowadzącego ───────────────────────────────────────────────────
$ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime((string)$d);
$from = (string)($_GET['from'] ?? $_POST['from'] ?? '');
$to   = (string)($_GET['to']   ?? $_POST['to']   ?? '');
if (!$ok($from) || !$ok($to)) {
    $ym = (string)($_GET['m'] ?? date('Y-m', strtotime('first day of last month')));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m', strtotime('first day of last month'));
    $from = $ym . '-01';
    $to   = date('Y-m-t', strtotime($from));
}
if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }
$instr_filter = (int)($_GET['instructor_id'] ?? $_POST['instructor_id'] ?? 0);

// Kategorie danych do sekcji „uzasadnienie braku danych"
$MISSING_CATS = [
    'attendance'   => 'Frekwencja / listy obecności',
    'disability'   => 'Dane o niepełnosprawności uczestników',
    'hours'        => 'Czas pracy prowadzących',
    'participants' => 'Dane uczestników',
    'online_split' => 'Podział online / stacjonarne',
    'other'        => 'Inne',
];

// ── Rekord zapisanego sprawozdania (pola narracyjne + podpisany plik) ────────────
$reportRow = db_one("SELECT * FROM k30_ti_wup_reports WHERE period_from=? AND period_to=? AND instructor_id=?",
    [$from, $to, $instr_filter]);
$fields = $reportRow ? (json_decode($reportRow['fields_json'] ?: '{}', true) ?: []) : [];
$fld = fn(string $k, $def = '') => $fields[$k] ?? $def;

// ── Pobranie podpisanego pliku ───────────────────────────────────────────────────
if (($did = (int)($_GET['download'] ?? 0))) {
    $r = db_one("SELECT * FROM k30_ti_wup_reports WHERE id=?", [$did]);
    if (!$r || $r['signed_path'] === '' || !is_file($r['signed_path'])) { http_response_code(404); die('Brak pliku.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . ($r['signed_name'] ?: ('sprawozdanie_WUP_' . $did . '.pdf')) . '"');
    header('Content-Length: ' . filesize($r['signed_path']));
    readfile($r['signed_path']);
    exit;
}

// ── Upload podpisanego PDF-a ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'upload_signed') {
    csrf_check();
    $rid = (int)($_POST['report_id'] ?? 0);
    $r   = db_one("SELECT * FROM k30_ti_wup_reports WHERE id=?", [$rid]);
    if (!$r) { flash_set('error', 'Nie znaleziono sprawozdania.'); }
    elseif (empty($_FILES['signed']['tmp_name']) || $_FILES['signed']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Nie wybrano pliku lub błąd wysyłki.');
    } else {
        $f    = $_FILES['signed'];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
        if ($ext !== 'pdf' || ($mime && stripos($mime, 'pdf') === false)) {
            flash_set('error', 'Dozwolony jest wyłącznie plik PDF.');
        } elseif ($f['size'] > 25 * 1024 * 1024) {
            flash_set('error', 'Plik przekracza 25 MB.');
        } else {
            $dir = rtrim(UPLOAD_DIR, '/') . '/k30_wup';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $dest = $dir . '/wup_signed_' . $rid . '.pdf';
            if (@move_uploaded_file($f['tmp_name'], $dest)) {
                $name = 'sprawozdanie_WUP_' . $r['period_from'] . '_' . $r['period_to'] . '_podpisane.pdf';
                db()->prepare("UPDATE k30_ti_wup_reports SET signed_path=?, signed_name=?, signed_by=?, signed_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$dest, $name, $user_id, $rid]);
                flash_set('success', 'Wgrano podpisane sprawozdanie.');
            } else {
                flash_set('error', 'Nie udało się zapisać pliku na serwerze.');
            }
        }
    }
    header('Location: wup_report.php?from=' . urlencode($r['period_from'] ?? $from) . '&to=' . urlencode($r['period_to'] ?? $to)
        . '&instructor_id=' . (int)($r['instructor_id'] ?? $instr_filter)); exit;
}

// ── Usunięcie zapisanego sprawozdania (tylko kierownictwo) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'delete_report') {
    csrf_check();
    if (!$can_delete) { http_response_code(403); die('Usuwanie sprawozdań jest zastrzeżone dla kierownictwa.'); }
    $rid = (int)($_POST['report_id'] ?? 0);
    $r   = db_one("SELECT * FROM k30_ti_wup_reports WHERE id=?", [$rid]);
    if ($r) {
        if (!empty($r['signed_path']) && is_file($r['signed_path'])) @unlink($r['signed_path']);
        db()->prepare("DELETE FROM k30_ti_wup_reports WHERE id=?")->execute([$rid]);
        flash_set('success', 'Sprawozdanie usunięte.');
    } else {
        flash_set('error', 'Nie znaleziono sprawozdania.');
    }
    header('Location: wup_report.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '&instructor_id=' . $instr_filter); exit;
}

// ── Zbieranie danych (wspólne dla podglądu i PDF) ────────────────────────────────
// Wykluczenia: grupy oznaczone „nie uwzględniaj w WUP" oraz nazwy z „test".
$isTest = fn(string $s) => stripos($s, 'test') !== false;

$byInstr  = [];   // iid => [name, groups[], ids[], mins, participants, lessons]
$all_ids  = [];
$grand    = ['groups' => 0, 'participants' => 0, 'mins' => 0, 'lessons' => 0, 'cancelled' => 0, 'online' => 0, 'onsite' => 0];
$instrAll = [];   // iid => name (wszyscy prowadzący z uwzględnionych grup)

foreach (k30_ti_courses(false) as $co) {
    $iid = (int)($co['instructor_id'] ?? 0);
    if ($instr_filter && $iid !== $instr_filter) continue;
    if (!empty($co['wup_exclude']))  continue;
    if ($isTest((string)$co['name'])) continue;
    $cid = (int)$co['id'];

    $iname = $iid ? (trim((string)($co['instructor_name'] ?? '')) ?: ('Prowadzący #' . $iid)) : '(brak przypisanego prowadzącego)';
    if ($iid) $instrAll[$iid] = $iname;

    $w = db_one(
        "SELECT COALESCE(SUM(duration_min),0) AS mins, COUNT(*) AS lessons
         FROM k30_ti_sessions
         WHERE course_id=? AND lesson_date BETWEEN ? AND ?
           AND status IN ('held','individual_change','remote_material')", [$cid, $from, $to]);
    $lessons = (int)$w['lessons'];
    $mins    = (int)$w['mins'];

    $canc = (int)(db_one(
        "SELECT COUNT(*) AS c FROM k30_ti_sessions WHERE course_id=? AND lesson_date BETWEEN ? AND ? AND status='cancelled'",
        [$cid, $from, $to])['c'] ?? 0);
    $grand['cancelled'] += $canc;

    if ($lessons === 0) continue; // brak zajęć odbytych — grupy nie pokazujemy, ale odwołania policzone

    $ids = array_map('intval', array_column(db_all(
        "SELECT e.client_id FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active' AND LOWER(cl.name) NOT LIKE '%test%'", [$cid]), 'client_id'));
    $part = count($ids);
    $online = !empty($co['is_online']);

    if (!isset($byInstr[$iid])) {
        $byInstr[$iid] = ['name' => $iname, 'groups' => [], 'ids' => [], 'mins' => 0, 'participants' => 0, 'lessons' => 0];
    }
    $byInstr[$iid]['groups'][] = ['name' => $co['name'], 'participants' => $part, 'mins' => $mins, 'lessons' => $lessons, 'online' => $online];
    foreach ($ids as $id) { $byInstr[$iid]['ids'][$id] = true; $all_ids[$id] = true; }
    $byInstr[$iid]['mins']         += $mins;
    $byInstr[$iid]['participants'] += $part;
    $byInstr[$iid]['lessons']      += $lessons;

    $grand['groups']++; $grand['participants'] += $part; $grand['mins'] += $mins; $grand['lessons'] += $lessons;
    if ($online) $grand['online'] += $lessons; else $grand['onsite'] += $lessons;
}
uasort($byInstr, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Prowadzący bez zajęć w okresie (0 h) — wymagają uzasadnienia
$zeroInstr = [];
foreach ($instrAll as $iid => $nm) { if (!isset($byInstr[$iid])) $zeroInstr[$iid] = $nm; }
asort($zeroInstr, SORT_NATURAL | SORT_FLAG_CASE);

$employed = count($instrAll);

// Uczestnicy z niepełnosprawnością (umowa PFRON)
$disabled = 0;
if ($all_ids) {
    $ph = implode(',', array_fill(0, count($all_ids), '?'));
    $disabled = (int)(db_one("SELECT COUNT(DISTINCT client_id) AS c FROM k30_pfron_contracts WHERE client_id IN ($ph)",
        array_keys($all_ids))['c'] ?? 0);
}

// Frekwencja % — obecności na zajęciach odbytych (grupy z track_attendance, bez Test/WUP-exclude)
$frRow = db_one(
    "SELECT COUNT(*) AS total, COALESCE(SUM(a.attended),0) AS present
     FROM k30_ti_attendance a
     JOIN k30_ti_sessions s ON s.id=a.session_id
     JOIN k30_ti_courses  c ON c.id=s.course_id
     JOIN k30_clients     cl ON cl.id=a.client_id
     WHERE s.lesson_date BETWEEN ? AND ? AND s.status IN ('held','individual_change','remote_material')
       AND c.track_attendance=1 AND c.wup_exclude=0 AND LOWER(c.name) NOT LIKE '%test%'
       AND LOWER(cl.name) NOT LIKE '%test%'"
    . ($instr_filter ? " AND c.instructor_id=?" : ''),
    $instr_filter ? [$from, $to, $instr_filter] : [$from, $to]);
$fr_total = (int)($frRow['total'] ?? 0);
$fr_pres  = (int)($frRow['present'] ?? 0);
$frek_pct = $fr_total > 0 ? round(100 * $fr_pres / $fr_total, 1) : null;

$fmtH  = fn(int $m): string => number_format($m / 60, 2, ',', ' ');
$fmtHd = fn(int $m): string => number_format($m / 45, 2, ',', ' ');
$period_txt = date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to));

// ── Generowanie PDF (POST out=pdf) — zapis rekordu + dokument ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['out'] ?? '') === 'pdf') {
    csrf_check();

    // Zbierz pola narracyjne z formularza
    $zero_just = [];
    foreach ($zeroInstr as $iid => $nm) {
        $t = trim((string)($_POST['zero_just'][$iid] ?? ''));
        if ($t !== '') $zero_just[(string)$iid] = $t;
    }
    $missing_flags = array_values(array_intersect(array_keys($MISSING_CATS), (array)($_POST['missing'] ?? [])));
    $fields = [
        'manager_name'      => trim((string)($_POST['manager_name'] ?? $mgr_name)),
        'manager_title'     => trim((string)($_POST['manager_title'] ?? $mgr_title)),
        'structural_changes'=> trim((string)($_POST['structural_changes'] ?? '')),
        'missing_flags'     => $missing_flags,
        'missing_reason'    => trim((string)($_POST['missing_reason'] ?? '')),
        'zero_just'         => $zero_just,
    ];

    // Upsert rekordu sprawozdania
    if ($reportRow) {
        db()->prepare("UPDATE k30_ti_wup_reports SET fields_json=?, generated_by=?, generated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $user_id, (int)$reportRow['id']]);
    } else {
        db()->prepare("INSERT INTO k30_ti_wup_reports (period_from,period_to,instructor_id,fields_json,generated_by) VALUES (?,?,?,?,?)")
            ->execute([$from, $to, $instr_filter, json_encode($fields, JSON_UNESCAPED_UNICODE), $user_id]);
    }
    $fld = fn(string $k, $def = '') => $fields[$k] ?? $def;

    require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
    $FD = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';
    $pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;

    try {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetMargins(14, 14, 14);
        $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
        $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
        $pdf->AddPage();
        $W = $pdf->GetPageWidth() - 28;

        // Nagłówek — nadawca + miejscowość/data
        $pdf->SetFont('DejaVu', 'B', 9);
        $topY = $pdf->GetY();
        $pdf->MultiCell($W * 0.62, 4.5, $pl($org_name), 0, 'L');
        $pdf->SetFont('DejaVu', '', 8);
        $addr = trim($org_adres . ($org_miejsc ? ($org_adres ? ', ' : '') . $org_miejsc : ''));
        if ($addr)       $pdf->MultiCell($W * 0.62, 4, $pl($addr), 0, 'L');
        $reg = trim(($org_nip ? 'NIP ' . $org_nip : '') . ($org_regon ? ($org_nip ? '   ·   ' : '') . 'REGON ' . $org_regon : ''));
        if ($reg)        $pdf->MultiCell($W * 0.62, 4, $pl($reg), 0, 'L');
        $risLine = 'Nr wpisu do RIS: ' . $ris_number . ($ris_date ? '  (z dnia ' . $ris_date . ')' : '');
        $pdf->MultiCell($W * 0.62, 4, $pl($risLine), 0, 'L');
        if ($ris_voiv) $pdf->MultiCell($W * 0.62, 4, $pl('Województwo: ' . $ris_voiv), 0, 'L');
        $endLeftY = $pdf->GetY();
        $pdf->SetXY(14 + $W * 0.62, $topY);
        $pdf->SetFont('DejaVu', '', 8.5);
        $placeDate = ($org_miejsc ? $org_miejsc . ', ' : '') . 'dnia ' . date('d.m.Y') . ' r.';
        $pdf->MultiCell($W * 0.38, 4.5, $pl($placeDate), 0, 'R');
        $pdf->SetY(max($endLeftY, $topY) + 4);

        // Tytuł
        $pdf->SetFont('DejaVu', 'B', 13);
        $pdf->MultiCell($W, 6.5, $pl('ZESTAWIENIE ZAJĘĆ / SPRAWOZDANIE'), 0, 'C');
        $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(70, 70, 70);
        $pdf->MultiCell($W, 4.5, $pl('dla Wojewódzkiego Urzędu Pracy — na podstawie ustawy z dnia 20 kwietnia 2004 r. '
            . 'o promocji zatrudnienia i instytucjach rynku pracy'), 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', 'B', 9.5);
        $pdf->MultiCell($W, 5.5, $pl('za okres: ' . $period_txt), 0, 'C');
        $pdf->Ln(3);

        // Tabela grup wg prowadzących
        $cLp = 8; $cLes = 16; $cPart = 22; $cHz = 24; $cHd = 24; $cMode = 20;
        $cGrp = $W - ($cLp + $cLes + $cPart + $cHz + $cHd + $cMode);
        $tableHead = function () use ($pdf, $pl, $cLp, $cGrp, $cLes, $cPart, $cHz, $cHd, $cMode) {
            $pdf->SetFont('DejaVu', 'B', 7.3);
            $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(150, 165, 185);
            $pdf->Cell($cLp,   7, $pl('Lp.'),          1, 0, 'C', true);
            $pdf->Cell($cGrp,  7, $pl('Grupa (kurs)'), 1, 0, 'L', true);
            $pdf->Cell($cMode, 7, $pl('Tryb'),         1, 0, 'C', true);
            $pdf->Cell($cLes,  7, $pl('Zajęcia'),      1, 0, 'C', true);
            $pdf->Cell($cPart, 7, $pl('Uczestnicy'),   1, 0, 'C', true);
            $pdf->Cell($cHz,   7, $pl('Czas (h zeg.)'),1, 0, 'C', true);
            $pdf->Cell($cHd,   7, $pl("Godz. dyd."),   1, 1, 'C', true);
        };
        $tableHead();
        $pdf->SetFont('DejaVu', '', 8.5);
        $lp = 0;
        if (!$byInstr) $pdf->Cell($W, 7, $pl('Brak zajęć odbytych w wybranym okresie.'), 1, 1, 'C');
        foreach ($byInstr as $ins) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) { $pdf->AddPage(); $tableHead(); $pdf->SetFont('DejaVu', '', 8.5); }
            $pdf->SetFont('DejaVu', 'B', 9); $pdf->SetFillColor(236, 240, 246);
            $pdf->Cell($W, 6.5, $pl('Prowadzący: ' . $ins['name']
                . '   (godz. zeg.: ' . $fmtH($ins['mins']) . ' · godz. dyd.: ' . $fmtHd($ins['mins']) . ')'), 1, 1, 'L', true);
            $pdf->SetFont('DejaVu', '', 8.5);
            $fill = false;
            foreach ($ins['groups'] as $g) {
                if ($pdf->GetY() > $pdf->GetPageHeight() - 24) { $pdf->AddPage(); $tableHead(); $pdf->SetFont('DejaVu', '', 8.5); }
                $lp++;
                $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
                $pdf->Cell($cLp,   6, (string)$lp, 1, 0, 'C', true);
                $pdf->Cell($cGrp,  6, $pl(mb_strimwidth($g['name'], 0, 52, '…')), 1, 0, 'L', true);
                $pdf->Cell($cMode, 6, $pl($g['online'] ? 'online' : 'stacjon.'), 1, 0, 'C', true);
                $pdf->Cell($cLes,  6, (string)$g['lessons'], 1, 0, 'C', true);
                $pdf->Cell($cPart, 6, (string)$g['participants'], 1, 0, 'C', true);
                $pdf->Cell($cHz,   6, $fmtH($g['mins']),  1, 0, 'R', true);
                $pdf->Cell($cHd,   6, $fmtHd($g['mins']), 1, 1, 'R', true);
                $fill = !$fill;
            }
            $pdf->SetFont('DejaVu', 'B', 8); $pdf->SetFillColor(224, 232, 244);
            $pdf->Cell($cLp + $cGrp + $cMode, 6.5, $pl('Razem — ' . $ins['name'] . '   (osób: ' . count($ins['ids']) . ')'), 1, 0, 'L', true);
            $pdf->Cell($cLes,  6.5, (string)$ins['lessons'], 1, 0, 'C', true);
            $pdf->Cell($cPart, 6.5, (string)$ins['participants'], 1, 0, 'C', true);
            $pdf->Cell($cHz,   6.5, $fmtH($ins['mins']),  1, 0, 'R', true);
            $pdf->Cell($cHd,   6.5, $fmtHd($ins['mins']), 1, 1, 'R', true);
            $pdf->SetFont('DejaVu', '', 8.5);
        }
        if ($byInstr) {
            $pdf->SetFont('DejaVu', 'B', 8.5); $pdf->SetFillColor(210, 221, 236);
            $pdf->Cell($cLp + $cGrp + $cMode, 7, $pl('OGÓŁEM   (osób unikalnych: ' . count($all_ids) . ')'), 1, 0, 'L', true);
            $pdf->Cell($cLes,  7, (string)$grand['lessons'], 1, 0, 'C', true);
            $pdf->Cell($cPart, 7, (string)$grand['participants'], 1, 0, 'C', true);
            $pdf->Cell($cHz,   7, $fmtH($grand['mins']),  1, 0, 'R', true);
            $pdf->Cell($cHd,   7, $fmtHd($grand['mins']), 1, 1, 'R', true);
        }
        $pdf->Ln(3);

        // Prowadzący bez zajęć (0 h) + uzasadnienie
        $zj = (array)$fld('zero_just', []);
        if ($zeroInstr) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
            $pdf->SetFont('DejaVu', 'B', 9.5);
            $pdf->MultiCell($W, 5.5, $pl('Prowadzący bez zajęć w okresie (0 godz.)'), 0, 'L');
            $pdf->SetFont('DejaVu', '', 8.5);
            foreach ($zeroInstr as $iid => $nm) {
                $u = trim((string)($zj[(string)$iid] ?? $zj[$iid] ?? ''));
                $pdf->SetFillColor(245, 247, 250);
                $pdf->Cell($W * 0.42, 6, $pl($nm), 1, 0, 'L', true);
                $pdf->Cell($W * 0.58, 6, $pl('Uzasadnienie: ' . ($u !== '' ? $u : '—')), 1, 1, 'L', false);
            }
            $pdf->Ln(2);
        }

        // Wskaźniki zbiorcze
        if ($pdf->GetY() > $pdf->GetPageHeight() - 80) $pdf->AddPage();
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->MultiCell($W, 6, $pl('Wskaźniki zbiorcze'), 0, 'L');
        $kv = function (string $k, string $v, bool $sub = false) use ($pdf, $pl, $W) {
            $pdf->SetFont('DejaVu', $sub ? '' : 'B', 8.5);
            $pdf->SetFillColor($sub ? 247 : 236, $sub ? 249 : 240, $sub ? 253 : 246);
            $pdf->Cell($W * 0.70, 6, $pl(($sub ? '     ' : '') . $k), 1, 0, 'L', true);
            $pdf->SetFont('DejaVu', 'B', 9);
            $pdf->Cell($W * 0.30, 6, $pl($v), 1, 1, 'R', true);
        };
        $kv('Liczba osób zatrudnionych do prowadzenia szkoleń', (string)$employed);
        $kv('Liczba uczestników (unikalnych)', (string)count($all_ids));
        $kv('w tym z niepełnosprawnością (umowa PFRON)', (string)$disabled, true);
        $kv('Liczba zajęć odbytych — ogółem', (string)$grand['lessons']);
        $kv('w tym online / zdalnie', (string)$grand['online'], true);
        $kv('w tym stacjonarnie', (string)$grand['onsite'], true);
        $kv('Liczba zajęć odwołanych', (string)$grand['cancelled']);
        $kv('Frekwencja (obecności / zajęcia)', $frek_pct === null ? 'brak danych' : (number_format($frek_pct, 1, ',', ' ') . ' %'));
        $kv('Liczba grup (kursów)', (string)$grand['groups']);
        $pdf->Ln(3);

        // Istotne zmiany w strukturze organizacji
        $struct = trim((string)$fld('structural_changes', ''));
        $pdf->SetFont('DejaVu', 'B', 9.5);
        $pdf->MultiCell($W, 5.5, $pl('Istotne zmiany w strukturze organizacji'), 0, 'L');
        $pdf->SetFont('DejaVu', '', 8.5);
        $pdf->MultiCell($W, 4.6, $pl($struct !== '' ? $struct : 'Brak istotnych zmian w okresie sprawozdawczym.'), 1, 'L');
        $pdf->Ln(2);

        // Uzasadnienie braku danych
        $mf = (array)$fld('missing_flags', []);
        $mr = trim((string)$fld('missing_reason', ''));
        if ($mf || $mr !== '') {
            $pdf->SetFont('DejaVu', 'B', 9.5);
            $pdf->MultiCell($W, 5.5, $pl('Uzasadnienie braku / niekompletności danych'), 0, 'L');
            $pdf->SetFont('DejaVu', '', 8.5);
            $labels = array_map(fn($k) => $MISSING_CATS[$k] ?? $k, $mf);
            $pdf->MultiCell($W, 4.6, $pl('Dane niedostępne / niepełne: ' . ($labels ? implode(', ', $labels) : '—')), 1, 'L');
            $pdf->MultiCell($W, 4.6, $pl('Uzasadnienie: ' . ($mr !== '' ? $mr : '—')), 1, 'L');
            $pdf->Ln(2);
        }

        // Przypis metodyczny
        $pdf->SetFont('DejaVu', '', 6.8); $pdf->SetTextColor(110, 110, 110);
        $pdf->MultiCell($W, 3.8, $pl(
            'Czas pracy = suma czasu zajęć odbytych (statusy: odbyła się / zmiana indywidualna / praca własna). '
            . '1 godz. dydaktyczna = 45 min; godz. zegarowa = 60 min. Liczba osób nie liczy podwójnie osób z kilku grup. '
            . 'Tryb online/stacjonarny wg oznaczenia grupy. Uczestnicy z niepełnosprawnością = posiadający umowę PFRON. '
            . 'Frekwencja = obecności ÷ wpisy obecności na zajęciach odbytych (grupy z listą obecności). '
            . 'Pominięto grupy oznaczone „nie uwzględniaj w WUP" oraz rekordy z „Test" w nazwie.'), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        // Podpis kierownika (z danych RIS)
        $pdf->Ln(12);
        if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
        $sigW = 78; $sigX = 14 + $W - $sigW; $ySig = $pdf->GetY() + 10;
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->Line($sigX, $ySig, $sigX + $sigW, $ySig);
        $pdf->SetXY($sigX, $ySig + 1);
        $pdf->SetFont('DejaVu', '', 8);
        $mgrN = trim((string)$fld('manager_name', $mgr_name));
        $mgrT = trim((string)$fld('manager_title', $mgr_title)) ?: 'Kierownik';
        $pdf->Cell($sigW, 4, $pl($mgrN !== '' ? $mgrN : '(podpis i pieczęć kierownika)'), 0, 1, 'C');
        if ($mgrN !== '') { $pdf->SetX($sigX); $pdf->Cell($sigW, 4, $pl($mgrT), 0, 1, 'C'); }

        while (ob_get_level() > 0) ob_end_clean();
        $pdf->Output('D', 'sprawozdanie_WUP_' . $from . '_' . $to . '.pdf');
        exit;
    } catch (\Throwable $e) {
        error_log('[wup_report] ' . $from . '..' . $to . ': ' . $e->getMessage());
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
        echo "Nie udało się wygenerować sprawozdania.\nPowód: " . $e->getMessage() . "\n";
        exit;
    }
}

// ════════════════════════════════ PODGLĄD HTML ══════════════════════════════════
$ti_instructors = k30_ti_instructors();
$savedReports   = db_all("SELECT r.*, u.name AS gen_name FROM k30_ti_wup_reports r
    LEFT JOIN users u ON u.id=r.generated_by ORDER BY r.period_from DESC, r.instructor_id LIMIT 30");

$PAGE_TITLE = 'Sprawozdanie do WUP';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
$mgrNameVal  = (string)$fld('manager_name', $mgr_name);
$mgrTitleVal = (string)$fld('manager_title', $mgr_title);
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.85rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Sprawozdanie do WUP</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-bank me-2 text-primary"></i>Sprawozdanie do WUP — podgląd</h4>
  <a href="<?= APP_URL ?>/karty30/ti/ris.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-card-list me-1"></i>Dane do RIS</a>
</div>

<!-- Filtr okresu -->
<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 row g-2 align-items-end">
    <div class="col-auto"><label class="form-label small mb-1">Od</label>
      <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small mb-1">Do</label>
      <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small mb-1">Prowadzący</label>
      <select name="instructor_id" class="form-select form-select-sm">
        <option value="0">Wszyscy prowadzący</option>
        <?php foreach ($ti_instructors as $it): ?>
        <option value="<?= (int)$it['id'] ?>" <?= $instr_filter===(int)$it['id']?'selected':'' ?>><?= h($it['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Odśwież podgląd</button></div>
    <div class="col-auto ms-auto text-body-secondary small">Okres: <strong><?= h($period_txt) ?></strong></div>
  </div>
</form>

<div class="row g-3">
  <!-- Podgląd danych -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-table me-2"></i>Zajęcia wg prowadzących</div>
      <div class="card-body p-0">
        <?php if (!$byInstr): ?>
          <p class="text-body-secondary small p-3 mb-0">Brak zajęć odbytych w wybranym okresie.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
            <thead class="table-light"><tr><th>Grupa</th><th>Tryb</th><th class="text-center">Zajęcia</th><th class="text-center">Ucz.</th><th class="text-end">Godz. dyd.</th></tr></thead>
            <tbody>
            <?php foreach ($byInstr as $ins): ?>
              <tr class="table-light"><td colspan="5" class="fw-semibold"><?= h($ins['name']) ?> — <?= $fmtHd($ins['mins']) ?> godz. dyd.</td></tr>
              <?php foreach ($ins['groups'] as $g): ?>
              <tr>
                <td><?= h($g['name']) ?></td>
                <td><span class="badge bg-<?= $g['online']?'info':'secondary' ?> bg-opacity-25 text-dark"><?= $g['online']?'online':'stacjon.' ?></span></td>
                <td class="text-center"><?= (int)$g['lessons'] ?></td>
                <td class="text-center"><?= (int)$g['participants'] ?></td>
                <td class="text-end"><?= $fmtHd($g['mins']) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-2"></i>Wskaźniki zbiorcze</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:.85rem">
          <tbody>
            <tr><td>Osoby zatrudnione do prowadzenia szkoleń</td><td class="text-end fw-bold"><?= $employed ?></td></tr>
            <tr><td>Uczestnicy (unikalni)</td><td class="text-end fw-bold"><?= count($all_ids) ?></td></tr>
            <tr><td class="ps-4 text-body-secondary">w tym z niepełnosprawnością (PFRON)</td><td class="text-end"><?= $disabled ?></td></tr>
            <tr><td>Zajęcia odbyte — ogółem</td><td class="text-end fw-bold"><?= $grand['lessons'] ?></td></tr>
            <tr><td class="ps-4 text-body-secondary">online / zdalnie</td><td class="text-end"><?= $grand['online'] ?></td></tr>
            <tr><td class="ps-4 text-body-secondary">stacjonarnie</td><td class="text-end"><?= $grand['onsite'] ?></td></tr>
            <tr><td>Zajęcia odwołane</td><td class="text-end fw-bold"><?= $grand['cancelled'] ?></td></tr>
            <tr><td>Frekwencja</td><td class="text-end fw-bold"><?= $frek_pct===null ? '—' : number_format($frek_pct,1,',',' ').' %' ?></td></tr>
            <tr><td>Grupy (kursy)</td><td class="text-end fw-bold"><?= $grand['groups'] ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Pola sprawozdania + generowanie -->
  <div class="col-lg-5">
    <form method="post" class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil-square me-2"></i>Dane sprawozdania</div>
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="out" value="pdf">
        <input type="hidden" name="from" value="<?= h($from) ?>">
        <input type="hidden" name="to" value="<?= h($to) ?>">
        <input type="hidden" name="instructor_id" value="<?= $instr_filter ?>">

        <div class="row g-2 mb-3">
          <div class="col-7"><label class="form-label small fw-semibold">Kierownik — imię i nazwisko</label>
            <input type="text" name="manager_name" class="form-control form-control-sm" value="<?= h($mgrNameVal) ?>"></div>
          <div class="col-5"><label class="form-label small fw-semibold">Stanowisko</label>
            <input type="text" name="manager_title" class="form-control form-control-sm" value="<?= h($mgrTitleVal) ?>"></div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Istotne zmiany w strukturze organizacji</label>
          <textarea name="structural_changes" class="form-control form-control-sm" rows="3"
            placeholder="Opisz istotne zmiany w okresie lub zostaw puste."><?= h((string)$fld('structural_changes','')) ?></textarea>
        </div>

        <?php if ($zeroInstr): ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Prowadzący bez zajęć (0 h) — uzasadnienie</label>
          <?php $zj = (array)$fld('zero_just', []); foreach ($zeroInstr as $iid => $nm): ?>
          <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="min-width:38%"><?= h($nm) ?></span>
            <input type="text" name="zero_just[<?= (int)$iid ?>]" class="form-control" placeholder="powód braku zajęć"
                   value="<?= h((string)($zj[(string)$iid] ?? $zj[$iid] ?? '')) ?>">
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="mb-2">
          <label class="form-label small fw-semibold">Uzasadnienie braku danych — których danych brak:</label>
          <?php $mf = (array)$fld('missing_flags', []); foreach ($MISSING_CATS as $mk => $ml): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="missing[]" value="<?= $mk ?>" id="m_<?= $mk ?>" <?= in_array($mk,$mf,true)?'checked':'' ?>>
            <label class="form-check-label small" for="m_<?= $mk ?>"><?= h($ml) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Uzasadnienie — dlaczego</label>
          <textarea name="missing_reason" class="form-control form-control-sm" rows="2"
            placeholder="Wyjaśnij przyczynę braku / niekompletności danych."><?= h((string)$fld('missing_reason','')) ?></textarea>
        </div>

        <button type="submit" class="btn btn-danger" formtarget="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>Zapisz i pobierz PDF</button>
        <div class="form-text">Zapisuje pola i generuje PDF do wydruku/podpisu. Podpisany plik wgraj poniżej.</div>
      </div>
    </form>
  </div>
</div>

<!-- Zapisane / podpisane sprawozdania -->
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-folder2-open me-2"></i>Zapisane sprawozdania</div>
  <div class="card-body p-0">
    <?php if (!$savedReports): ?>
      <p class="text-body-secondary small p-3 mb-0">Brak zapisanych sprawozdań. Wygeneruj PDF, aby utworzyć wpis.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr><th>Okres</th><th>Prowadzący</th><th>Wygenerowano</th><th>Podpisany</th><th class="text-end">Akcje</th></tr></thead>
        <tbody>
        <?php foreach ($savedReports as $r): ?>
          <tr>
            <td class="font-monospace"><?= h($r['period_from']) ?> – <?= h($r['period_to']) ?></td>
            <td><?php
              $nm = 'Wszyscy';
              if ((int)$r['instructor_id']) { foreach ($ti_instructors as $it) { if ((int)$it['id']===(int)$r['instructor_id']) { $nm=$it['name']; break; } } if ($nm==='Wszyscy') $nm='#'.$r['instructor_id']; }
              echo h($nm); ?></td>
            <td class="text-body-secondary"><?= h((string)($r['generated_at'] ?? '')) ?><?php if($r['gen_name']): ?> · <?= h($r['gen_name']) ?><?php endif; ?></td>
            <td><?= $r['signed_path'] ? '<span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>tak</span>' : '<span class="badge bg-secondary">nie</span>' ?></td>
            <td class="text-end text-nowrap">
              <a href="wup_report.php?from=<?= urlencode($r['period_from']) ?>&to=<?= urlencode($r['period_to']) ?>&instructor_id=<?= (int)$r['instructor_id'] ?>" class="btn btn-outline-secondary btn-sm py-0 px-2"><i class="bi bi-eye"></i></a>
              <?php if ($r['signed_path']): ?>
              <a href="wup_report.php?download=<?= (int)$r['id'] ?>" class="btn btn-outline-success btn-sm py-0 px-2"><i class="bi bi-download"></i></a>
              <?php endif; ?>
              <button class="btn btn-outline-primary btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#signModal" data-rid="<?= (int)$r['id'] ?>" data-lbl="<?= h($r['period_from'].' – '.$r['period_to']) ?>"><i class="bi bi-upload"></i></button>
              <?php if ($can_delete): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć zapisane sprawozdanie<?= $r['signed_path'] ? ' wraz z podpisanym plikiem' : '' ?>? Operacji nie można cofnąć.');">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_op" value="delete_report">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń sprawozdanie (kierownictwo)"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: wgranie podpisanego -->
<div class="modal fade" id="signModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="upload_signed">
      <input type="hidden" name="report_id" id="signRid" value="">
      <div class="modal-header py-2"><h2 class="modal-title h6 mb-0">Wgraj podpisane sprawozdanie</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">
        <p class="small text-body-secondary">Sprawozdanie <strong id="signLbl"></strong>. Dozwolony plik PDF (max 25 MB).</p>
        <input type="file" name="signed" accept="application/pdf" class="form-control" required>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Wgraj</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('signModal')?.addEventListener('show.bs.modal', function (ev) {
  var b = ev.relatedTarget;
  document.getElementById('signRid').value = b ? b.getAttribute('data-rid') : '';
  document.getElementById('signLbl').textContent = b ? b.getAttribute('data-lbl') : '';
});
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
