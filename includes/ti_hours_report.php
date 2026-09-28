<?php
/**
 * includes/ti_hours_report.php - „Rozpiska godzin" dla beneficjenta (PDF).
 *
 * Szczegółowe zestawienie zajęć stojących za kwotą rozliczenia: lekcja po lekcji
 * (data, godziny, temat, obecność, godziny rozliczane, kwota), w podziale na grupy
 * (model kombinowany - każda grupa ma własny model rozliczania i własne saldo).
 *
 * Używane przez:
 *   karty30/ti/hours_pdf.php          - wydruk pracownika/administratora
 *   karty30/ti/kursant/hours_pdf.php  - pobranie przez kursanta/opiekuna
 */
require_once __DIR__ . '/ti_payments.php';

const TI_HR_MONTHS_PL = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                         7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
const TI_HR_DAYS_PL    = ['Mon'=>'pon.','Tue'=>'wt.','Wed'=>'śr.','Thu'=>'czw.','Fri'=>'pt.','Sat'=>'sob.','Sun'=>'niedz.'];

/**
 * Dane rozpiski: lekcje w miesiącu w podziale na grupy + naliczenia i salda.
 * $course_id > 0 zawęża do jednej grupy.
 *
 * @return array{client:array,year:int,month:int,period:string,groups:array,totals:array,payment:array}
 */
function ti_hours_data(int $client_id, int $year, int $month, int $course_id = 0): array {
    karty30_migrate();
    ti_payments_migrate();

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
    $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) ?: ['id'=>$client_id,'name'=>'—'];

    $enrs = db_all(
        "SELECT e.*, c.id AS cid, c.name AS course_name, c.billing_model AS course_billing_model,
                c.billing_amount AS course_billing_amount, c.pay_account AS course_pay_account,
                c.pay_title AS course_pay_title, c.pay_due_days AS course_pay_due_days,
                u.name AS instructor_name
         FROM k30_ti_enrollments e
         JOIN k30_ti_courses c ON c.id=e.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE e.client_id=? AND e.status='active'"
        . ($course_id > 0 ? " AND e.course_id=?" : "") . "
         ORDER BY c.name",
        $course_id > 0 ? [$client_id, $course_id] : [$client_id]
    );

    $groups = [];
    $tot = ['lessons'=>0, 'present'=>0, 'hours'=>0.0, 'amount'=>0.0];

    foreach ($enrs as $e) {
        $cid = (int)$e['cid'];
        $eff = k30_ti_effective_billing($e, [
            'billing_model'  => $e['course_billing_model'],
            'billing_amount' => $e['course_billing_amount'],
            'pay_account'    => $e['course_pay_account'],
            'pay_title'      => $e['course_pay_title'],
            'pay_due_days'   => $e['course_pay_due_days'],
        ]);
        $hourly = ((int)$eff['model'] === 2);

        $rows = db_all(
            "SELECT s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status, s.topic,
                    a.attended, a.no_show, a.no_show_billing, COALESCE(a.cancelled,0) AS att_cancelled
             FROM k30_ti_sessions s
             LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
             WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ?
               AND s.status IN ('held','individual_change','remote_material')
             ORDER BY s.lesson_date, s.time_from",
            [$client_id, $cid, $from, $to]
        );

        $lessons = []; $g_hours = 0.0; $g_present = 0;
        foreach ($rows as $r) {
            $dur      = (int)$r['duration_min'];
            $attended = !empty($r['attended']) && empty($r['att_cancelled']);
            $noshow   = !empty($r['no_show']) && empty($r['att_cancelled']);
            $hrs      = 0.0;
            if ($attended)      $hrs = (float)ceil($dur / 60);
            elseif ($noshow)    $hrs = ($r['no_show_billing'] === '1h') ? 1.0 : (float)ceil($dur / 60);
            $g_hours = round($g_hours + $hrs, 2);
            if ($attended) $g_present++;

            $lessons[] = [
                'date'     => (string)$r['lesson_date'],
                'time'     => trim(substr((string)$r['time_from'], 0, 5) . (($r['time_to'] ?? '') ? '–' . substr((string)$r['time_to'], 0, 5) : '')),
                'topic'    => (string)($r['topic'] ?? ''),
                'duration' => $dur,
                'hours'    => $hrs,
                'status'   => $attended ? 'obecny' : ($noshow ? 'nieobecność płatna' : (empty($r['attended']) && !empty($r['att_cancelled']) ? 'odwołana' : 'nieobecny')),
                'billed'   => $hrs > 0,
                'amount'   => $hourly ? round($hrs * (float)$eff['hourly_rate'], 2) : 0.0,
                'remote'   => $r['status'] === 'remote_material',
            ];
        }

        $g_amount = $hourly ? round($g_hours * (float)$eff['hourly_rate'], 2) : round((float)$eff['amount'], 2);
        // Ryczałt naliczany tylko gdy jest za co (kwota ustawiona) - zgodnie z k30_ti_calculate_billing
        if (!$hourly && $g_amount <= 0) $g_amount = 0.0;

        $bal = ti_group_balance($client_id, $cid);
        $mb  = ti_group_month_billing($client_id, $cid, $year, $month);

        $groups[$cid] = [
            'course_id'   => $cid,
            'course_name' => (string)$e['course_name'],
            'instructor'  => (string)($e['instructor_name'] ?? ''),
            'model'       => (int)$eff['model'],
            'model_label' => (string)$eff['label'],
            'hourly'      => $hourly,
            'hourly_rate' => (float)$eff['hourly_rate'],
            'flat_amount' => (float)$eff['amount'],
            'lessons'     => $lessons,
            'hours'       => $g_hours,
            'present'     => $g_present,
            'amount'      => $g_amount,
            'balance'     => $bal,
            'month_bill'  => $mb,
        ];
        $tot['lessons'] += count($lessons);
        $tot['present'] += $g_present;
        $tot['hours']    = round($tot['hours'] + $g_hours, 2);
        $tot['amount']   = round($tot['amount'] + $g_amount, 2);
    }

    return [
        'client'  => $client,
        'year'    => $year,
        'month'   => $month,
        'period'  => (TI_HR_MONTHS_PL[$month] ?? $month) . ' ' . $year,
        'groups'  => $groups,
        'totals'  => $tot,
        'payment' => k30_ti_client_payment($client_id),
        'account' => ti_client_group_balances($client_id),
    ];
}

/**
 * Renderuje rozpiskę do PDF i zwraca jego zawartość (string).
 * $opts: ['footer_note' => string]
 */
function ti_hours_pdf(array $d, array $opts = []): string {
    require_once __DIR__ . '/fpdf/fpdf.php';
    // Font z pełnym zestawem polskich znaków (DejaVu, kodowanie ISO-8859-2)
    $pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
    $zl = fn($x): string => number_format((float)$x, 2, ',', ' ');
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->SetMargins(12, 12, 12);
    $fdir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $fdir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $fdir);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 24;

    // ── Nagłówek ──────────────────────────────────────────────────────────────
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 14);
    $pdf->Cell($W, 10, $pl('Rozpiska godzin zajęć'), 0, 1, 'L', true);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->Cell($W, 6, $pl(($org ? $org . '   |   ' : '') . 'okres: ' . $d['period']), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(3);

    $pdf->SetFont('DejaVu', 'B', 11);
    $pdf->Cell($W, 6, $pl('Beneficjent: ' . (string)($d['client']['name'] ?? '')), 0, 1);
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W, 5, $pl('Wygenerowano: ' . date('d.m.Y H:i')
        . '   |   grup: ' . count($d['groups'])
        . '   |   zajęć w okresie: ' . (int)$d['totals']['lessons']), 0, 1);
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(2);

    // ── Sekcje per grupa ──────────────────────────────────────────────────────
    $cW = ['date'=>22, 'day'=>12, 'time'=>24, 'topic'=>0, 'dur'=>16, 'hrs'=>16, 'att'=>30, 'amt'=>22];
    $cW['topic'] = $W - array_sum($cW);

    foreach ($d['groups'] as $g) {
        if ($pdf->GetY() > $pdf->GetPageHeight() - 55) $pdf->AddPage();

        $pdf->SetFillColor(233, 238, 245);
        $pdf->SetFont('DejaVu', 'B', 10.5);
        $pdf->Cell($W, 7, $pl($g['course_name'] . ($g['instructor'] ? '   |   prowadzący: ' . $g['instructor'] : '')), 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(80, 80, 80);
        $basis = $g['hourly']
            ? 'rozliczenie godzinowe - stawka ' . $zl($g['hourly_rate']) . ' zł/godz.'
            : 'rozliczenie ' . mb_strtolower($g['model_label']) . ' - kwota ' . $zl($g['flat_amount']) . ' zł za okres';
        $pdf->Cell($W, 5, $pl($basis . '   |   obecności: ' . (int)$g['present'] . '/' . count($g['lessons'])
                              . '   |   godziny rozliczane: ' . $zl($g['hours'])), 0, 1);
        $pdf->SetTextColor(0, 0, 0); $pdf->Ln(1);

        // Nagłówek tabeli lekcji
        $pdf->SetFont('DejaVu', 'B', 6.8);
        $pdf->SetFillColor(224, 232, 244); $pdf->SetDrawColor(190, 205, 225);
        $pdf->Cell($cW['date'],  6, $pl('Data'),      1, 0, 'L', true);
        $pdf->Cell($cW['day'],   6, $pl('Dzień'),     1, 0, 'C', true);
        $pdf->Cell($cW['time'],  6, $pl('Godziny'),   1, 0, 'C', true);
        $pdf->Cell($cW['topic'], 6, $pl('Temat'),     1, 0, 'L', true);
        $pdf->Cell($cW['dur'],   6, $pl('Czas'),      1, 0, 'C', true);
        $pdf->Cell($cW['hrs'],   6, $pl('Godz. rozl.'), 1, 0, 'R', true);
        $pdf->Cell($cW['att'],   6, $pl('Obecność'),  1, 0, 'C', true);
        $pdf->Cell($cW['amt'],   6, $pl('Kwota'),     1, 1, 'R', true);

        $pdf->SetFont('DejaVu', '', 6.8);
        $fill = false;
        foreach ($g['lessons'] as $ls) {
            if ($pdf->GetY() > $pdf->GetPageHeight() - 22) { $pdf->AddPage(); $pdf->SetFont('DejaVu', '', 6.8); }
            $pdf->SetFillColor($fill ? 247 : 255, $fill ? 249 : 255, $fill ? 253 : 255);
            $ts   = strtotime($ls['date']);
            $day  = TI_HR_DAYS_PL[date('D', $ts)] ?? '';
            $topic = $ls['topic'] !== '' ? $ls['topic'] : ($ls['remote'] ? 'praca własna (materiał zdalny)' : '—');
            $pdf->Cell($cW['date'],  6, $pl(date('d.m.Y', $ts)), 1, 0, 'L', true);
            $pdf->Cell($cW['day'],   6, $pl($day), 1, 0, 'C', true);
            $pdf->Cell($cW['time'],  6, $pl($ls['time'] !== '' ? $ls['time'] : '—'), 1, 0, 'C', true);
            $pdf->Cell($cW['topic'], 6, $pl(mb_strimwidth($topic, 0, 44, '…')), 1, 0, 'L', true);
            $pdf->Cell($cW['dur'],   6, $pl($ls['duration'] . ' min'), 1, 0, 'C', true);
            $pdf->Cell($cW['hrs'],   6, $zl($ls['hours']), 1, 0, 'R', true);
            if ($ls['status'] === 'obecny')                 $pdf->SetTextColor(0, 120, 0);
            elseif ($ls['status'] === 'nieobecność płatna') $pdf->SetTextColor(180, 100, 0);
            elseif ($ls['status'] === 'nieobecny')          $pdf->SetTextColor(170, 0, 0);
            else                                            $pdf->SetTextColor(120, 120, 120);
            $pdf->Cell($cW['att'], 6, $pl($ls['status']), 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($cW['amt'], 6, $g['hourly'] ? $zl($ls['amount']) : $pl('—'), 1, 1, 'R', true);
            $fill = !$fill;
        }
        if (!$g['lessons']) {
            $pdf->Cell($W, 6, $pl('Brak zajęć w tym okresie.'), 1, 1, 'L');
        }

        // Podsumowanie grupy
        $pdf->SetFont('DejaVu', 'B', 7.6);
        $pdf->SetFillColor(240, 244, 250);
        $pdf->Cell($W - $cW['hrs'] - $cW['att'] - $cW['amt'], 6.5, $pl('Razem - ' . $g['course_name']), 1, 0, 'R', true);
        $pdf->Cell($cW['hrs'], 6.5, $zl($g['hours']), 1, 0, 'R', true);
        $pdf->Cell($cW['att'], 6.5, $pl($g['hourly'] ? 'godzinowo' : 'ryczałt'), 1, 0, 'C', true);
        $pdf->Cell($cW['amt'], 6.5, $zl($g['amount']), 1, 1, 'R', true);

        // Saldo tej grupy
        $bal = $g['balance'];
        $pdf->SetFont('DejaVu', '', 6.8); $pdf->SetTextColor(80, 80, 80);
        $sal = 'Rozliczenie grupy: należności ' . $zl($bal['charges']) . ' zł | pokryte ' . $zl($bal['paid']) . ' zł';
        if ($bal['debt']   > 0.005) $sal .= ' | DO ZAPŁATY ' . $zl($bal['debt']) . ' zł';
        if ($bal['credit'] > 0.005) $sal .= ' | nadpłata ' . $zl($bal['credit']) . ' zł (zaliczona na kolejne zajęcia w tej grupie)';
        if ($bal['debt'] <= 0.005 && $bal['credit'] <= 0.005) $sal .= ' | saldo rozliczone';
        $pdf->MultiCell($W, 4.2, $pl($sal), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    if (!$d['groups']) {
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell($W, 8, $pl('Brak aktywnych grup w tym okresie.'), 0, 1);
    }

    // ── Podsumowanie całości ──────────────────────────────────────────────────
    if ($pdf->GetY() > $pdf->GetPageHeight() - 45) $pdf->AddPage();
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->SetFillColor(15, 80, 150); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($W - 60, 8, $pl('RAZEM za okres ' . $d['period']), 0, 0, 'R', true);
    $pdf->Cell(30, 8, $pl($zl($d['totals']['hours']) . ' godz.'), 0, 0, 'R', true);
    $pdf->Cell(30, 8, $pl($zl($d['totals']['amount']) . ' zł'), 0, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(2);

    $acc = $d['account'];
    if ($acc['general_credit'] > 0.005) {
        $pdf->SetFont('DejaVu', '', 7.6); $pdf->SetTextColor(0, 110, 0);
        $pdf->Cell($W, 5, $pl('Nadpłata ogólna na koncie: ' . $zl($acc['general_credit'])
            . ' zł - zostanie zaliczona na kolejne zajęcia (dowolna grupa).'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }

    // Dane do wpłaty
    $pay = $d['payment'];
    if (($pay['account'] ?? '') !== '' || ($pay['title'] ?? '') !== '') {
        $pdf->Ln(1);
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->Cell($W, 5.5, $pl('Dane do wpłaty'), 0, 1);
        $pdf->SetFont('DejaVu', '', 7.6);
        if (($pay['account'] ?? '') !== '') $pdf->Cell($W, 5, $pl('Nr konta: ' . $pay['account']), 0, 1);
        if (($pay['title'] ?? '')   !== '') $pdf->Cell($W, 5, $pl('Tytuł przelewu: ' . $pay['title']), 0, 1);
        $pdf->Cell($W, 5, $pl('Termin płatności: ' . (int)($pay['due_days'] ?? 7) . ' dni od wystawienia rozliczenia'), 0, 1);
    }

    $pdf->Ln(3);
    $pdf->SetFont('DejaVu', '', 6.2); $pdf->SetTextColor(110, 110, 110);
    $note = $opts['footer_note'] ?? '';
    $pdf->MultiCell($W, 4, $pl(
        'Godziny rozliczane liczone są jako pełne godziny zegarowe rozpoczęte (czas lekcji zaokrąglany w górę do pełnej godziny). '
        . 'Nieobecność zgłoszona zbyt późno („nieobecność płatna") jest naliczana zgodnie z regulaminem. '
        . 'Każda grupa (przedmiot) rozliczana jest osobno - ma własny model naliczania i własne saldo; '
        . 'nadpłata przypisana do grupy pokrywa kolejne zajęcia w tej grupie. '
        . 'Zestawienie ma charakter informacyjny i nie jest dokumentem księgowym.'
        . ($note !== '' ? ' ' . $note : '')), 0, 'L');

    return $pdf->Output('S');
}
