<?php
/**
 * karty30/ti/kreator_raportow.php — Kreator raportów TI (tabele przestawne).
 * Raporty: Zaległości | Nadpłaty | Frekwencja (per kursant/grupa × miesiąc/kwartał/rok).
 * Eksport CSV dla każdego raportu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_payments.php';

k30_require_access();
karty30_migrate();
ti_payments_migrate();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

// ── Filtry ────────────────────────────────────────────────────────────────────
$report         = in_array($_GET['report'] ?? '', ['zaleglosci','nadplaty','frekwencja','lekcje','prowadzacy','wynagrodzenia']) ? $_GET['report'] : 'zaleglosci';
$preset         = $_GET['preset'] ?? 'school_year';
$course_id      = (int)($_GET['course_id'] ?? 0);
$group_by       = in_array($_GET['group_by'] ?? '', ['month','quarter','year']) ? $_GET['group_by'] : 'month';
$instructor_id  = (int)($_GET['instructor_id'] ?? 0);
$status_filter  = in_array($_GET['status'] ?? '', ['planned','held','individual_change','remote_material','cancelled']) ? $_GET['status'] : '';
$export         = ($_GET['export'] ?? '') === 'csv';

// Okresy
$now_y = (int)date('Y');
$now_m = (int)date('n');
$sy_start = $now_m >= 9 ? $now_y : $now_y - 1; // rok szkolny od września

$presets = [
    'this_month'       => [sprintf('%d-%02d', $now_y, $now_m), sprintf('%d-%02d', $now_y, $now_m)],
    'this_year'        => [sprintf('%d-01', $now_y),            sprintf('%d-12', $now_y)],
    'school_year'      => [sprintf('%d-09', $sy_start),         sprintf('%d-08', $sy_start + 1)],
    'last_school_year' => [sprintf('%d-09', $sy_start - 1),     sprintf('%d-08', $sy_start)],
    'custom'           => [$_GET['date_from'] ?? date('Y-m'), $_GET['date_to'] ?? date('Y-m')],
];
$valid_presets = array_keys($presets);
if (!in_array($preset, $valid_presets, true)) $preset = 'school_year';
[$date_from_str, $date_to_str] = $presets[$preset];

// Normalizuj YYYY-MM do YYYY-MM-DD
$date_from = $date_from_str . '-01';
$date_to   = date('Y-m-t', strtotime($date_to_str . '-01')); // ostatni dzień miesiąca

// billing: from_key/to_key = YYYYMM int
[$fy, $fm] = explode('-', $date_from_str);
[$ty, $tm] = explode('-', $date_to_str);
$from_key = (int)$fy * 100 + (int)$fm;
$to_key   = (int)$ty * 100 + (int)$tm;

$courses        = k30_ti_courses(false);
$ti_instructors = k30_ti_instructors();

// ── Kwartał: labels ───────────────────────────────────────────────────────────
function _quarter_label(string $rok, string $m): string {
    return 'Q' . ceil((int)$m / 3) . ' ' . $rok;
}
function _period_label(string $rok, string $m, string $group_by): string {
    if ($group_by === 'year') return $rok;
    if ($group_by === 'quarter') return _quarter_label($rok, $m);
    $mn = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
            7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
    return ($mn[(int)$m] ?? $m) . ' ' . substr($rok, 2);
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: ZALEGŁOŚCI
// ══════════════════════════════════════════════════════════════════════════════
$zal_rows = []; $zal_total_due = 0; $zal_total_paid = 0; $zal_total_debt = 0;

if ($report === 'zaleglosci' || $export) {
    $zal_params = [$from_key, $to_key];
    $zal_where  = "b.status IN ('issued','paid') AND (b.year*100 + b.month) BETWEEN ? AND ?";
    if ($course_id > 0) { $zal_where .= " AND b.course_id=?"; $zal_params[] = $course_id; }

    $zal_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient, cl.email,
                COALESCE(c.name,'') AS kurs,
                b.course_id,
                ROUND(SUM(b.amount + COALESCE(b.adjustment,0)), 2) AS naleznosci,
                ROUND(SUM(COALESCE(b.paid_amount,0)), 2) AS zaplacono,
                ROUND(SUM(b.amount + COALESCE(b.adjustment,0) - COALESCE(b.paid_amount,0)), 2) AS zaleglost,
                MIN(CASE WHEN b.status='issued' AND b.due_date < date('now') THEN b.due_date END) AS overdue_from
         FROM k30_ti_billing b
         JOIN k30_clients cl ON cl.id=b.client_id
         LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
         WHERE $zal_where
         GROUP BY cl.id, b.course_id
         HAVING zaleglost > 0.01
         ORDER BY klient COLLATE NOCASE, kurs",
        $zal_params
    );

    // Grupuj per klient (mogą być >1 wiersz per klient gdy multi-kurs)
    $zal_by_client = [];
    foreach ($zal_raw as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($zal_by_client[$cid])) {
            $zal_by_client[$cid] = ['klient'=>$r['klient'],'email'=>$r['email'],'kursy'=>[],'naleznosci'=>0,'zaplacono'=>0,'zaleglost'=>0,'overdue_from'=>null];
        }
        $mg = &$zal_by_client[$cid];
        if ($r['kurs'] !== '') $mg['kursy'][] = $r['kurs'];
        $mg['naleznosci'] += (float)$r['naleznosci'];
        $mg['zaplacono']  += (float)$r['zaplacono'];
        $mg['zaleglost']  += (float)$r['zaleglost'];
        if ($r['overdue_from'] && (!$mg['overdue_from'] || $r['overdue_from'] < $mg['overdue_from'])) $mg['overdue_from'] = $r['overdue_from'];
        unset($mg);
    }
    // Sortuj po zaległości DESC
    uasort($zal_by_client, fn($a,$b) => $b['zaleglost'] <=> $a['zaleglost']);
    $zal_rows = array_values($zal_by_client);
    foreach ($zal_rows as $r) {
        $zal_total_due  += $r['naleznosci'];
        $zal_total_paid += $r['zaplacono'];
        $zal_total_debt += $r['zaleglost'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: NADPŁATY
// ══════════════════════════════════════════════════════════════════════════════
$nad_rows = []; $nad_total_charge = 0; $nad_total_paid = 0; $nad_total_credit = 0;

if ($report === 'nadplaty' || $export) {
    // Nadpłata = globalne saldo (niezależne od okresu — FIFO alokacja jest globalna)
    $nad_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient, cl.email,
                ROUND(COALESCE(p.total_paid,0), 2) AS zaplacono,
                ROUND(COALESCE(ch.total_due,0), 2) AS naleznosci,
                ROUND(COALESCE(p.total_paid,0) - COALESCE(ch.total_due,0), 2) AS nadplata
         FROM k30_clients cl
         JOIN (SELECT client_id, SUM(amount) AS total_paid FROM k30_ti_payments GROUP BY client_id) p
              ON p.client_id=cl.id
         LEFT JOIN (SELECT client_id, SUM(amount+COALESCE(adjustment,0)) AS total_due
                    FROM k30_ti_billing WHERE status IN ('issued','paid') GROUP BY client_id) ch
              ON ch.client_id=cl.id
         WHERE COALESCE(p.total_paid,0) - COALESCE(ch.total_due,0) > 0.01
         ORDER BY nadplata DESC",
        []
    );
    $nad_rows = $nad_raw;
    foreach ($nad_rows as $r) {
        $nad_total_charge += (float)$r['naleznosci'];
        $nad_total_paid   += (float)$r['zaplacono'];
        $nad_total_credit += (float)$r['nadplata'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: FREKWENCJA — tabela przestawna (wiersze=kursant, kolumny=okres)
// ══════════════════════════════════════════════════════════════════════════════
$freq_rows = []; $freq_periods = []; $freq_pivot = [];

if ($report === 'frekwencja') {
    $freq_params = [$date_from, $date_to];
    $freq_where  = "s.status IN ('held','individual_change','remote_material')
                    AND s.lesson_date BETWEEN ? AND ?";
    if ($course_id > 0) { $freq_where .= " AND s.course_id=?"; $freq_params[] = $course_id; }

    $freq_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient,
                c.id AS course_id, c.name AS kurs,
                strftime('%Y', s.lesson_date) AS rok,
                strftime('%m', s.lesson_date) AS miesiac,
                COUNT(DISTINCT s.id) AS sesje,
                ROUND(SUM(COALESCE(s.duration_min,60)) / 60.0, 2) AS godziny,
                SUM(CASE WHEN a.attended=1 THEN 1 ELSE 0 END) AS obecny,
                SUM(CASE WHEN COALESCE(a.attended,0)=0 AND COALESCE(a.cancelled,0)=0
                               AND COALESCE(a.cancel_pending,0)=0 AND COALESCE(a.no_show,0)=0
                         THEN 1 ELSE 0 END) AS nieobecny_n,
                SUM(CASE WHEN COALESCE(a.no_show,0)=1 THEN 1 ELSE 0 END) AS no_show
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.status IN ('active','inactive')
         JOIN k30_clients cl ON cl.id=e.client_id
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=cl.id
         WHERE $freq_where
         GROUP BY rok, miesiac, cl.id, c.id
         ORDER BY klient COLLATE NOCASE, kurs, rok, miesiac",
        $freq_params
    );

    // Zbierz unikalne okresy i klucze (kursant+kurs)
    $period_keys = [];
    foreach ($freq_raw as $r) {
        $pk = _period_label($r['rok'], $r['miesiac'], $group_by);
        $period_keys[$pk] = true;
    }
    $freq_periods = array_keys($period_keys);

    // Buduj pivot: [klient_id-course_id][period] = dane
    $pivot = [];
    foreach ($freq_raw as $r) {
        $key    = $r['client_id'] . '-' . $r['course_id'];
        $pk     = _period_label($r['rok'], $r['miesiac'], $group_by);
        if (!isset($pivot[$key])) {
            $pivot[$key] = ['klient'=>$r['klient'],'kurs'=>$r['kurs'],
                            'client_id'=>$r['client_id'],'course_id'=>$r['course_id'],'periods'=>[]];
        }
        if (!isset($pivot[$key]['periods'][$pk])) {
            $pivot[$key]['periods'][$pk] = ['sesje'=>0,'godziny'=>0,'obecny'=>0,'nieobecny_n'=>0,'no_show'=>0];
        }
        $p = &$pivot[$key]['periods'][$pk];
        $p['sesje']      += (int)$r['sesje'];
        $p['godziny']    += (float)$r['godziny'];
        $p['obecny']     += (int)$r['obecny'];
        $p['nieobecny_n']+= (int)$r['nieobecny_n'];
        $p['no_show']    += (int)$r['no_show'];
        unset($p);
    }
    $freq_pivot = array_values($pivot);
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: LEKCJE — szczegółowa lista lekcji
// ══════════════════════════════════════════════════════════════════════════════
$les_rows  = [];
$les_stats = ['total'=>0,'held'=>0,'ind'=>0,'remote'=>0,'cancelled'=>0,'planned'=>0,'min'=>0,'bb'=>0.0,'netto'=>0.0];

if ($report === 'lekcje') {
    $les_params = [$date_from, $date_to];
    $les_where  = "s.lesson_date BETWEEN ? AND ?";
    if ($course_id > 0)       { $les_where .= " AND s.course_id=?";      $les_params[] = $course_id; }
    if ($instructor_id > 0)   { $les_where .= " AND c.instructor_id=?";  $les_params[] = $instructor_id; }
    if ($status_filter !== '') { $les_where .= " AND s.status=?";         $les_params[] = $status_filter; }

    $les_raw = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min,
                s.status, s.topic, COALESCE(s.self_prep_remote,0) AS self_prep_remote,
                c.id AS course_id, c.name AS course_name,
                COALESCE(c.lesson_payout_bb, 0.0) AS bb,
                COALESCE(u.name,'(brak prowadzącego)') AS instructor_name,
                COALESCE(u.ti_payout_form,
                    CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END
                ) AS payout_form,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id AND a.attended=1) AS att_present,
                (SELECT COUNT(*) FROM k30_ti_attendance a WHERE a.session_id=s.id) AS att_total
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE $les_where
         ORDER BY s.lesson_date DESC, s.time_from",
        $les_params
    );

    foreach ($les_raw as &$r) {
        $bb_val     = (float)$r['bb'];
        $is_payable = in_array($r['status'], ['held','individual_change','remote_material'], true) && $bb_val > 0;
        $form_exempt = in_array($r['payout_form'] ?? 'zlecenie', ['student','b2b'], true);
        $eff_student = $form_exempt || (bool)$r['self_prep_remote'];
        $netto = 0.0;
        if ($is_payable) {
            $brk   = k30_ti_payout_breakdown($bb_val, $eff_student);
            $netto = $brk['netto'];
            $les_stats['bb']    += $bb_val;
            $les_stats['netto'] += $netto;
        }
        $r['netto']  = $netto;
        $r['bb_val'] = $bb_val;
        $les_stats['total']++;
        $les_stats['min'] += (int)($r['duration_min'] ?? 0);
        if     ($r['status'] === 'held')              $les_stats['held']++;
        elseif ($r['status'] === 'individual_change') $les_stats['ind']++;
        elseif ($r['status'] === 'remote_material')   $les_stats['remote']++;
        elseif ($r['status'] === 'cancelled')          $les_stats['cancelled']++;
        else                                           $les_stats['planned']++;
    }
    unset($r);
    $les_rows = $les_raw;
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: PROWADZĄCY — godziny i wynagrodzenia zbiorczo
// ══════════════════════════════════════════════════════════════════════════════
$prow_rows   = [];
$prow_totals = _k30_ti_payout_zero() + ['held'=>0,'ind'=>0,'remote'=>0,'min'=>0];

if ($report === 'prowadzacy') {
    $prow_params = [$date_from, $date_to];
    $prow_where  = "s.status IN ('held','individual_change','remote_material') AND s.lesson_date BETWEEN ? AND ?";
    if ($instructor_id > 0) { $prow_where .= " AND c.instructor_id=?"; $prow_params[] = $instructor_id; }
    if ($course_id > 0)     { $prow_where .= " AND s.course_id=?";     $prow_params[] = $course_id; }

    $prow_raw = db_all(
        "SELECT c.instructor_id,
                COALESCE(u.name,'(brak prowadzącego)') AS iname,
                COALESCE(u.ti_payout_form,
                    CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END
                ) AS payout_form,
                s.status, COALESCE(s.self_prep_remote,0) AS self_prep_remote,
                COALESCE(s.duration_min,0) AS duration_min,
                COALESCE(c.lesson_payout_bb,0.0) AS bb
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE $prow_where
         ORDER BY iname COLLATE NOCASE",
        $prow_params
    );

    $by_instr = [];
    foreach ($prow_raw as $r) {
        $iid = $r['instructor_id'] !== null ? (int)$r['instructor_id'] : 0;
        if (!isset($by_instr[$iid])) {
            $by_instr[$iid] = _k30_ti_payout_zero() + [
                'instructor_id' => $iid, 'name' => $r['iname'],
                'payout_form'   => $r['payout_form'],
                'held' => 0, 'ind' => 0, 'remote' => 0, 'min' => 0,
            ];
        }
        $form_exempt = in_array($r['payout_form'], ['student','b2b'], true);
        $eff_student = $form_exempt || (bool)$r['self_prep_remote'];
        _k30_ti_payout_accumulate($by_instr[$iid], k30_ti_payout_breakdown((float)$r['bb'], $eff_student));
        $by_instr[$iid]['min'] += (int)$r['duration_min'];
        if     ($r['status'] === 'held')              $by_instr[$iid]['held']++;
        elseif ($r['status'] === 'individual_change') $by_instr[$iid]['ind']++;
        else                                           $by_instr[$iid]['remote']++;
    }
    usort($by_instr, fn($a,$b) => strcasecmp($a['name'], $b['name']));
    $prow_rows = $by_instr;
    foreach ($prow_rows as $r) {
        foreach (['lessons','brutto_brutto','brutto','zus_employer','skladki','pit','netto'] as $k)
            $prow_totals[$k] += $r[$k];
        foreach (['held','ind','remote','min'] as $k)
            $prow_totals[$k] += $r[$k];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: WYNAGRODZENIA — pivot prowadzący × miesiąc (netto)
// ══════════════════════════════════════════════════════════════════════════════
$wyn_pivot = []; $wyn_months = []; $wyn_col_totals = []; $wyn_grand_total = 0.0;

if ($report === 'wynagrodzenia') {
    $wyn_params = [$date_from, $date_to];
    $wyn_where  = "s.status IN ('held','individual_change','remote_material')
                   AND c.lesson_payout_bb > 0
                   AND s.lesson_date BETWEEN ? AND ?";
    if ($instructor_id > 0) { $wyn_where .= " AND c.instructor_id=?"; $wyn_params[] = $instructor_id; }

    $wyn_raw = db_all(
        "SELECT c.instructor_id,
                COALESCE(u.name,'(brak prowadzącego)') AS iname,
                COALESCE(u.ti_payout_form,
                    CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END
                ) AS payout_form,
                COALESCE(s.self_prep_remote,0) AS self_prep_remote,
                strftime('%Y-%m', s.lesson_date) AS ym,
                COALESCE(c.lesson_payout_bb,0.0) AS bb
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         WHERE $wyn_where
         ORDER BY iname COLLATE NOCASE, ym",
        $wyn_params
    );

    $pivot = []; $month_keys = [];
    foreach ($wyn_raw as $r) {
        $iid = $r['instructor_id'] !== null ? (int)$r['instructor_id'] : 0;
        $ym  = $r['ym'];
        $month_keys[$ym] = true;
        if (!isset($pivot[$iid])) {
            $pivot[$iid] = ['name'=>$r['iname'],'payout_form'=>$r['payout_form'],'months'=>[],'total'=>0.0];
        }
        $form_exempt = in_array($r['payout_form'], ['student','b2b'], true);
        $eff_student = $form_exempt || (bool)$r['self_prep_remote'];
        $brk = k30_ti_payout_breakdown((float)$r['bb'], $eff_student);
        if (!isset($pivot[$iid]['months'][$ym])) $pivot[$iid]['months'][$ym] = 0.0;
        $pivot[$iid]['months'][$ym] += $brk['netto'];
        $pivot[$iid]['total']       += $brk['netto'];
    }
    $wyn_months = array_keys($month_keys);
    sort($wyn_months);
    usort($pivot, fn($a,$b) => strcasecmp($a['name'], $b['name']));
    $wyn_pivot      = array_values($pivot);
    $wyn_col_totals = array_fill_keys($wyn_months, 0.0);
    foreach ($wyn_pivot as $row) {
        foreach ($wyn_months as $ym) {
            $v = $row['months'][$ym] ?? 0.0;
            $wyn_col_totals[$ym] += $v;
            $wyn_grand_total     += $v;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// EKSPORT CSV
// ══════════════════════════════════════════════════════════════════════════════
if ($export && in_array($report, ['zaleglosci','nadplaty','frekwencja','lekcje','prowadzacy','wynagrodzenia'], true)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $report . '_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 dla Excel
    $out = fopen('php://output', 'w');

    if ($report === 'frekwencja') {
        fputcsv($out, ['Kursant','Kurs','Okres','Sesje odbyte','Godziny','Obecny (lekcje)','Nieobecny','No-show','Frekwencja (%)'], ';');
        foreach ($freq_pivot as $row) {
            foreach ($row['periods'] as $pk => $p) {
                $total = $p['obecny'] + $p['nieobecny_n'] + $p['no_show'];
                $pct   = $total > 0 ? round($p['obecny'] / $total * 100, 1) : '';
                fputcsv($out, [
                    $row['klient'], $row['kurs'], $pk,
                    $p['sesje'], number_format($p['godziny'],2,',',''),
                    $p['obecny'], $p['nieobecny_n'], $p['no_show'],
                    $pct !== '' ? $pct . '%' : '—',
                ], ';');
            }
        }
        fclose($out);
        exit;
    }

    if ($report === 'zaleglosci') {
        fputcsv($out, ['Kursant','E-mail','Grupy','Należności (zł)','Zapłacono (zł)','Zaległość (zł)','Przeterminowane od'], ';');
        foreach ($zal_rows as $r) {
            fputcsv($out, [
                $r['klient'], $r['email'], implode(', ', $r['kursy']),
                number_format($r['naleznosci'],2,',',''),
                number_format($r['zaplacono'],2,',',''),
                number_format($r['zaleglost'],2,',',''),
                $r['overdue_from'] ?? '',
            ], ';');
        }
        fputcsv($out, ['SUMA','','',
            number_format($zal_total_due,2,',',''),
            number_format($zal_total_paid,2,',',''),
            number_format($zal_total_debt,2,',',''),''], ';');
    } elseif ($report === 'nadplaty') {
        fputcsv($out, ['Kursant','E-mail','Należności (zł)','Wpłacono (zł)','Nadpłata (zł)'], ';');
        foreach ($nad_rows as $r) {
            fputcsv($out, [
                $r['klient'], $r['email'],
                number_format((float)$r['naleznosci'],2,',',''),
                number_format((float)$r['zaplacono'],2,',',''),
                number_format((float)$r['nadplata'],2,',',''),
            ], ';');
        }
        fputcsv($out, ['SUMA','',
            number_format($nad_total_charge,2,',',''),
            number_format($nad_total_paid,2,',',''),
            number_format($nad_total_credit,2,',','')], ';');
    } elseif ($report === 'lekcje') {
        fputcsv($out, ['Data','Czas od','Czas do','Kurs','Prowadzący','Status','Temat','Obecni','Zapisani','Frekwencja (%)','Czas (min)','Godziny','BB (zł)','Netto est. (zł)'], ';');
        $dows = ['Sun'=>'Nd','Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'So'];
        foreach ($les_rows as $r) {
            $att_pct = $r['att_total'] > 0 ? round((int)$r['att_present'] / (int)$r['att_total'] * 100, 1) . '%' : '—';
            $h = round((int)($r['duration_min'] ?? 0) / 60, 2);
            fputcsv($out, [
                date('d.m.Y', strtotime($r['lesson_date'])),
                substr((string)($r['time_from'] ?? ''), 0, 5),
                substr((string)($r['time_to'] ?? ''), 0, 5),
                $r['course_name'],
                $r['instructor_name'],
                K30_TI_SESSION_STATUSES[$r['status']]['label'] ?? $r['status'],
                (string)($r['topic'] ?? ''),
                (int)$r['att_present'],
                (int)$r['att_total'],
                $att_pct,
                (int)($r['duration_min'] ?? 0),
                number_format($h, 2, ',', ''),
                number_format($r['bb_val'], 2, ',', ''),
                number_format($r['netto'], 2, ',', ''),
            ], ';');
        }
        $total_h = round($les_stats['min'] / 60, 2);
        fputcsv($out, ['SUMA','','','','','','',
            '','','',
            $les_stats['min'],
            number_format($total_h, 2, ',', ''),
            number_format($les_stats['bb'], 2, ',', ''),
            number_format($les_stats['netto'], 2, ',', ''),
        ], ';');
    } elseif ($report === 'prowadzacy') {
        fputcsv($out, ['Prowadzący','Forma','Odbyte','Indywid.','Materiał','Lekcji razem','Czas (min)','Godziny','BB (zł)','Brutto (zł)','ZUS pracodawcy (zł)','Składki prac. (zł)','PIT (zł)','Netto est. (zł)'], ';');
        foreach ($prow_rows as $r) {
            fputcsv($out, [
                $r['name'], $r['payout_form'],
                $r['held'], $r['ind'], $r['remote'],
                $r['lessons'],
                $r['min'],
                number_format(round($r['min']/60,2), 2, ',', ''),
                number_format($r['brutto_brutto'], 2, ',', ''),
                number_format($r['brutto'],        2, ',', ''),
                number_format($r['zus_employer'],  2, ',', ''),
                number_format($r['skladki'],       2, ',', ''),
                number_format($r['pit'],           2, ',', ''),
                number_format($r['netto'],         2, ',', ''),
            ], ';');
        }
        $tot = $prow_totals;
        fputcsv($out, ['SUMA','', $tot['held'], $tot['ind'], $tot['remote'], $tot['lessons'], $tot['min'],
            number_format(round($tot['min']/60,2),2,',',''),
            number_format($tot['brutto_brutto'],2,',',''),
            number_format($tot['brutto'],2,',',''),
            number_format($tot['zus_employer'],2,',',''),
            number_format($tot['skladki'],2,',',''),
            number_format($tot['pit'],2,',',''),
            number_format($tot['netto'],2,',',''),
        ], ';');
    } else { // wynagrodzenia
        $mn_names = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
                     7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
        $hdr = ['Prowadzący','Forma'];
        foreach ($wyn_months as $ym) {
            [$y,$m] = explode('-', $ym);
            $hdr[] = ($mn_names[(int)$m] ?? $m) . ' ' . substr($y,2) . ' (netto zł)';
        }
        $hdr[] = 'RAZEM (zł)';
        fputcsv($out, $hdr, ';');
        foreach ($wyn_pivot as $row) {
            $line = [$row['name'], $row['payout_form']];
            foreach ($wyn_months as $ym)
                $line[] = number_format($row['months'][$ym] ?? 0.0, 2, ',', '');
            $line[] = number_format($row['total'], 2, ',', '');
            fputcsv($out, $line, ';');
        }
        $sline = ['SUMA',''];
        foreach ($wyn_months as $ym) $sline[] = number_format($wyn_col_totals[$ym], 2, ',', '');
        $sline[] = number_format($wyn_grand_total, 2, ',', '');
        fputcsv($out, $sline, ';');
    }
    fclose($out);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// HTML
// ══════════════════════════════════════════════════════════════════════════════
$PAGE_TITLE = 'Kreator Raportów — TI';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';

// Helper: URL z zamianą params
function _kr_url(array $extra): string {
    $p = array_merge($_GET, $extra);
    unset($p['export']);
    return '?' . http_build_query($p);
}
function _kr_csv_url(): string {
    return '?' . http_build_query(array_merge($_GET, ['export'=>'csv']));
}

$preset_labels = [
    'this_month'       => 'Ten miesiąc',
    'this_year'        => 'Ten rok',
    'school_year'      => 'Rok szkolny ' . $sy_start . '/' . ($sy_start+1),
    'last_school_year' => 'Rok szkolny ' . ($sy_start-1) . '/' . $sy_start,
    'custom'           => 'Własny zakres',
];
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.85rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="raporty.php">Raporty TI</a></li>
  <li class="breadcrumb-item active">Kreator Raportów</li>
</ol></nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="fw-bold mb-0"><i class="bi bi-table text-primary me-2"></i>Kreator Raportów</h4>
  <span class="badge bg-primary-subtle text-primary-emphasis">Tabela przestawna</span>
</div>

<?= flash_html() ?>

<!-- ── Panel filtrów ──────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body pb-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="report" value="<?= h($report) ?>">

      <!-- Preset okresu -->
      <div class="col-12">
        <div class="fw-semibold small mb-2">Zakres okresu</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php foreach ($preset_labels as $k => $lbl): ?>
          <a href="<?= h(_kr_url(['preset' => $k, 'report' => $report])) ?>"
             class="btn btn-sm <?= $preset === $k ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= h($lbl) ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php if ($preset === 'custom'): ?>
        <div class="d-flex gap-2 align-items-center">
          <label class="small text-body-secondary">Od</label>
          <input type="month" name="date_from" value="<?= h($date_from_str) ?>" class="form-control form-control-sm" style="max-width:150px">
          <label class="small text-body-secondary">Do</label>
          <input type="month" name="date_to"   value="<?= h($date_to_str) ?>"   class="form-control form-control-sm" style="max-width:150px">
          <button type="submit" class="btn btn-primary btn-sm">Zastosuj</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!in_array($report, ['prowadzacy','wynagrodzenia'], true)): ?>
      <!-- Kurs (nie dla raportów tylko prowadzących) -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Grupa / kurs</label>
        <select name="course_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0" <?= $course_id===0?'selected':'' ?>>Wszystkie grupy</option>
          <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $course_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if (in_array($report, ['lekcje','prowadzacy','wynagrodzenia'], true)): ?>
      <!-- Prowadzący (dla nowych raportów) -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Prowadzący</label>
        <select name="instructor_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0" <?= $instructor_id===0?'selected':'' ?>>Wszyscy prowadzący</option>
          <?php foreach ($ti_instructors as $it): ?>
          <option value="<?= (int)$it['id'] ?>" <?= $instructor_id===(int)$it['id']?'selected':'' ?>><?= h($it['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($report === 'lekcje'): ?>
      <!-- Status (tylko lekcje) -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="" <?= $status_filter===''?'selected':'' ?>>Wszystkie statusy</option>
          <?php foreach (K30_TI_SESSION_STATUSES as $sk => $sv): ?>
          <option value="<?= h($sk) ?>" <?= $status_filter===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($report === 'frekwencja'): ?>
      <!-- Grupowanie (tylko frekwencja) -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Grupuj po</label>
        <select name="group_by" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="month"   <?= $group_by==='month'?'selected':'' ?>>Miesiąc</option>
          <option value="quarter" <?= $group_by==='quarter'?'selected':'' ?>>Kwartał</option>
          <option value="year"    <?= $group_by==='year'?'selected':'' ?>>Rok</option>
        </select>
      </div>
      <?php endif; ?>

    </form>

    <!-- Zakres i eksport w pasku -->
    <div class="d-flex align-items-center gap-3 mt-2 pt-2 border-top flex-wrap">
      <span class="small text-body-secondary">
        <i class="bi bi-calendar-range me-1"></i>
        <?= h(date('d.m.Y', strtotime($date_from))) ?> – <?= h(date('d.m.Y', strtotime($date_to))) ?>
      </span>
      <?php if (in_array($report, ['zaleglosci','nadplaty','frekwencja'], true)): ?>
      <a href="<?= h(_kr_csv_url()) ?>" class="btn btn-outline-success btn-sm ms-auto">
        <i class="bi bi-filetype-csv me-1"></i>Eksport CSV
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Tabs raportów ──────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $report==='zaleglosci'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'zaleglosci'])) ?>">
      <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Zaległości
      <?php if ($report==='zaleglosci' && $zal_rows): ?>
      <span class="badge bg-danger ms-1"><?= count($zal_rows) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='nadplaty'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'nadplaty'])) ?>">
      <i class="bi bi-piggy-bank-fill text-success me-1"></i>Nadpłaty
      <?php if ($report==='nadplaty' && $nad_rows): ?>
      <span class="badge bg-success ms-1"><?= count($nad_rows) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='frekwencja'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'frekwencja'])) ?>">
      <i class="bi bi-bar-chart-fill text-primary me-1"></i>Frekwencja
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='lekcje'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'lekcje'])) ?>">
      <i class="bi bi-journal-text text-success me-1"></i>Lekcje
      <?php if ($report==='lekcje' && $les_stats['total']): ?>
      <span class="badge bg-success ms-1"><?= $les_stats['total'] ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='prowadzacy'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'prowadzacy'])) ?>">
      <i class="bi bi-person-badge text-info me-1"></i>Prowadzący
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='wynagrodzenia'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'wynagrodzenia'])) ?>">
      <i class="bi bi-cash-stack text-warning me-1"></i>Wynagrodzenia
    </a>
  </li>
</ul>

<?php /* ══ ZALEGŁOŚCI ══════════════════════════════════════════════════════ */ ?>
<?php if ($report === 'zaleglosci'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill text-danger"></i>Zaległości w płatnościach
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= count($zal_rows) ?> kursant<?= count($zal_rows) !== 1 ? 'ów' : '' ?> ·
      łącznie <strong class="text-danger"><?= number_format($zal_total_debt, 2, ',', ' ') ?> zł</strong>
    </span>
  </div>
  <?php if (!$zal_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
    Brak zaległości w wybranym okresie.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover">
      <caption class="visually-hidden">Zaległości kursantów</caption>
      <thead class="table-light">
        <tr>
          <th>Kursant</th>
          <th>Grupy</th>
          <th class="text-end">Należności</th>
          <th class="text-end">Zapłacono</th>
          <th class="text-end text-danger">Zaległość</th>
          <th>Przeterminowane od</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($zal_rows as $r):
          $overdue = !empty($r['overdue_from']);
        ?>
        <tr class="<?= $overdue ? 'table-danger' : '' ?>">
          <td>
            <div class="fw-semibold"><?= h($r['klient']) ?></div>
            <?php if ($r['email']): ?><div class="small text-body-secondary"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="small text-body-secondary"><?= $r['kursy'] ? h(implode(', ', $r['kursy'])) : '—' ?></td>
          <td class="text-end"><?= number_format($r['naleznosci'], 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($r['zaplacono'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-bold text-danger"><?= number_format($r['zaleglost'], 2, ',', ' ') ?> zł</td>
          <td>
            <?php if ($overdue): ?>
              <span class="badge bg-danger"><i class="bi bi-clock me-1"></i><?= h(date('d.m.Y', strtotime($r['overdue_from']))) ?></span>
            <?php else: ?>
              <span class="text-body-secondary small">Terminowe</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">SUMA (<?= count($zal_rows) ?> kursantów)</td>
          <td class="text-end"><?= number_format($zal_total_due, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($zal_total_paid, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-danger"><?= number_format($zal_total_debt, 2, ',', ' ') ?> zł</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ NADPŁATY ════════════════════════════════════════════════════════ */ ?>
<?php elseif ($report === 'nadplaty'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-piggy-bank-fill text-success"></i>Nadpłaty kursantów
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= count($nad_rows) ?> kursant<?= count($nad_rows) !== 1 ? 'ów' : '' ?> ·
      łącznie <strong class="text-success"><?= number_format($nad_total_credit, 2, ',', ' ') ?> zł</strong>
    </span>
  </div>
  <div class="card-body py-2 text-body-secondary small border-bottom">
    <i class="bi bi-info-circle me-1"></i>Nadpłata = globalne saldo kursanta (łączne wpłaty minus łączne należności).
    Kwota ta jest automatycznie zaliczana na poczet przyszłych zajęć.
  </div>
  <?php if (!$nad_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-piggy-bank fs-2 d-block mb-2"></i>Brak nadpłat.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover">
      <caption class="visually-hidden">Nadpłaty kursantów</caption>
      <thead class="table-light">
        <tr>
          <th>Kursant</th>
          <th class="text-end">Należności (ogółem)</th>
          <th class="text-end">Wpłacono (ogółem)</th>
          <th class="text-end text-success">Nadpłata</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nad_rows as $r): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($r['klient']) ?></div>
            <?php if ($r['email']): ?><div class="small text-body-secondary"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="text-end"><?= number_format((float)$r['naleznosci'], 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format((float)$r['zaplacono'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-bold text-success"><?= number_format((float)$r['nadplata'], 2, ',', ' ') ?> zł</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td>SUMA (<?= count($nad_rows) ?> kursantów)</td>
          <td class="text-end"><?= number_format($nad_total_charge, 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format($nad_total_paid, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($nad_total_credit, 2, ',', ' ') ?> zł</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ FREKWENCJA — tabela przestawna ════════════════════════════════ */ ?>
<?php elseif ($report === 'frekwencja'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-bar-chart-fill text-primary"></i>Frekwencja
    <span class="text-body-secondary fw-normal small ms-2">
      pivot: kursant × <?= $group_by === 'month' ? 'miesiąc' : ($group_by === 'quarter' ? 'kwartał' : 'rok') ?>
    </span>
  </div>
  <?php if (!$freq_pivot): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>Brak zajęć w wybranym okresie.
  </div>
  <?php else: ?>
  <!-- Legenda -->
  <div class="card-body py-2 border-bottom small text-body-secondary d-flex gap-3 flex-wrap">
    <span><span class="badge bg-success">100%</span> Obecność = (Obecny / Sesje) × 100</span>
    <span><i class="bi bi-clock text-danger me-1"></i>Nieobecny = bez usprawiedliwienia</span>
    <span><i class="bi bi-arrow-up text-success me-1"></i>Δ = zmiana vs poprzedni okres</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover" style="font-size:.84rem">
      <caption class="visually-hidden">Frekwencja kursantów — tabela przestawna</caption>
      <thead class="table-light">
        <tr>
          <th style="min-width:140px">Kursant</th>
          <th style="min-width:120px">Kurs/Grupa</th>
          <?php foreach ($freq_periods as $pk): ?>
          <th class="text-center" style="min-width:90px"><?= h($pk) ?></th>
          <?php endforeach; ?>
          <th class="text-center bg-light">Razem h</th>
          <th class="text-center bg-light">Avg %</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $pivot_totals = []; // period → [sesje, godziny, obecny_sum, total_sum]
        foreach ($freq_periods as $pk) $pivot_totals[$pk] = ['h'=>0,'obecny'=>0,'sesje'=>0];
        $gt_h = 0; $gt_obecny = 0; $gt_sesje = 0;

        foreach ($freq_pivot as $row):
            $prev_pct = null;
            $row_h = 0; $row_obecny = 0; $row_sesje = 0;
        ?>
        <tr>
          <td class="fw-semibold"><?= h($row['klient']) ?></td>
          <td class="text-body-secondary small"><?= h($row['kurs']) ?></td>
          <?php foreach ($freq_periods as $pk):
            $pd = $row['periods'][$pk] ?? null;
            $pct = ($pd && $pd['sesje'] > 0) ? round($pd['obecny'] / $pd['sesje'] * 100) : null;
            if ($pd) {
                $row_h      += $pd['godziny'];
                $row_obecny += $pd['obecny'];
                $row_sesje  += $pd['sesje'];
                $pivot_totals[$pk]['h']      += $pd['godziny'];
                $pivot_totals[$pk]['obecny'] += $pd['obecny'];
                $pivot_totals[$pk]['sesje']  += $pd['sesje'];
            }
            // Delta vs poprzedni okres
            $delta = null;
            if ($pct !== null && $prev_pct !== null) $delta = $pct - $prev_pct;
            $pct_col = $pct === null ? 'secondary' : ($pct >= 80 ? 'success' : ($pct >= 60 ? 'warning' : 'danger'));
            if ($pct !== null) $prev_pct = $pct;
          ?>
          <td class="text-center">
            <?php if ($pd): ?>
            <span class="badge bg-<?= $pct_col ?>-subtle text-<?= $pct_col ?>-emphasis px-2 py-1">
              <?= $pct ?>%
            </span>
            <div class="text-body-secondary mt-1" style="font-size:.72rem">
              <?= $pd['obecny'] ?>/<?= $pd['sesje'] ?> · <?= number_format($pd['godziny'],1,',','') ?>h
            </div>
            <?php if ($delta !== null): ?>
            <div class="<?= $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : 'text-body-secondary') ?>" style="font-size:.7rem">
              <?= $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '=') ?> <?= abs($delta) ?>pp
            </div>
            <?php endif; ?>
            <?php else: ?>
            <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-center bg-light fw-semibold"><?= number_format($row_h, 1, ',', '') ?>h</td>
          <td class="text-center bg-light">
            <?php if ($row_sesje > 0):
              $avg_pct = round($row_obecny / $row_sesje * 100);
              $avg_col = $avg_pct >= 80 ? 'success' : ($avg_pct >= 60 ? 'warning' : 'danger');
            ?>
            <span class="badge bg-<?= $avg_col ?>"><?= $avg_pct ?>%</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php
          $gt_h      += $row_h;
          $gt_obecny += $row_obecny;
          $gt_sesje  += $row_sesje;
        endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">SUMA GRUP</td>
          <?php foreach ($freq_periods as $pk):
            $pt  = $pivot_totals[$pk];
            $pct = $pt['sesje'] > 0 ? round($pt['obecny'] / $pt['sesje'] * 100) : null;
          ?>
          <td class="text-center">
            <?php if ($pct !== null): ?><?= $pct ?>%
            <div class="text-body-secondary fw-normal" style="font-size:.72rem"><?= number_format($pt['h'],1,',','') ?>h</div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-center bg-light"><?= number_format($gt_h, 1, ',', '') ?>h</td>
          <td class="text-center bg-light">
            <?php if ($gt_sesje > 0):
              $gt_pct = round($gt_obecny / $gt_sesje * 100);
              $gc = $gt_pct >= 80 ? 'success' : ($gt_pct >= 60 ? 'warning' : 'danger');
            ?>
            <span class="badge bg-<?= $gc ?>"><?= $gt_pct ?>%</span>
            <?php endif; ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php /* ══ LEKCJE — szczegółowa lista ══════════════════════════════════════ */ ?>
<?php elseif ($report === 'lekcje'): ?>
<?php
  $les_total_h    = round($les_stats['min'] / 60, 2);
  $held_payable   = $les_stats['held'] + $les_stats['ind'] + $les_stats['remote'];
  $att_all_pres   = array_sum(array_column($les_rows, 'att_present'));
  $att_all_total  = array_sum(array_column($les_rows, 'att_total'));
  $avg_att        = $att_all_total > 0 ? round($att_all_pres / $att_all_total * 100) : null;
  $max_bar_w      = $les_stats['total'] > 0 ? 100 : 0;
  $bar_segments   = [
      ['held',     'bg-success',          K30_TI_SESSION_STATUSES['held']['label']              ?? 'Odbyła się'],
      ['ind',      'bg-primary',          K30_TI_SESSION_STATUSES['individual_change']['label'] ?? 'Indywidualna'],
      ['remote',   '',                    K30_TI_SESSION_STATUSES['remote_material']['label']   ?? 'Materiał'],
      ['cancelled','bg-danger',           K30_TI_SESSION_STATUSES['cancelled']['label']         ?? 'Odwołana'],
      ['planned',  'bg-secondary',        K30_TI_SESSION_STATUSES['planned']['label']           ?? 'Planowana'],
  ];
?>
<!-- KPI cards -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Lekcji łącznie</div>
      <div class="fs-3 fw-bold"><?= $les_stats['total'] ?></div>
      <div class="small text-body-secondary">
        <?= $les_stats['held'] ?> odbyło się · <?= $les_stats['cancelled'] ?> odwołano
      </div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Łączny czas</div>
      <div class="fs-3 fw-bold"><?= number_format($les_total_h, 1, ',', '') ?> h</div>
      <div class="small text-body-secondary"><?= $les_stats['min'] ?> min · <?= $held_payable ?> lekcji rozliczanych</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Avg frekwencja</div>
      <?php if ($avg_att !== null): ?>
      <div class="fs-3 fw-bold <?= $avg_att>=80?'text-success':($avg_att>=60?'text-warning':'text-danger') ?>"><?= $avg_att ?>%</div>
      <div class="small text-body-secondary"><?= $att_all_pres ?> / <?= $att_all_total ?> wejść</div>
      <?php else: ?><div class="fs-3 fw-bold text-body-secondary">—</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Netto est. łącznie</div>
      <div class="fs-3 fw-bold text-success"><?= number_format($les_stats['netto'], 2, ',', ' ') ?> zł</div>
      <div class="small text-body-secondary">BB: <?= number_format($les_stats['bb'], 2, ',', ' ') ?> zł</div>
    </div></div>
  </div>
</div>

<?php if ($les_stats['total'] > 0): ?>
<!-- Status breakdown bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="small fw-semibold mb-2 text-body-secondary">Rozkład statusów lekcji</div>
    <div class="progress mb-2" style="height:22px;border-radius:4px" role="progressbar" aria-label="Rozkład statusów">
      <?php foreach ($bar_segments as [$key, $bsClass, $bsLabel]):
        $cnt = $les_stats[$key];
        if (!$cnt) continue;
        $pct = round($cnt / $les_stats['total'] * 100, 1);
        $style_extra = $key === 'remote' ? 'background:#0694A2;' : '';
      ?>
      <div class="progress-bar <?= $bsClass ?>" style="width:<?= $pct ?>%;<?= $style_extra ?>"
           title="<?= h($bsLabel) ?>: <?= $cnt ?> (<?= $pct ?>%)">
        <?php if ($pct >= 8): ?><?= $cnt ?><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-3" style="font-size:.8rem">
      <?php foreach ($bar_segments as [$key, $bsClass, $bsLabel]):
        $cnt = $les_stats[$key];
        if (!$cnt) continue;
        $pct = round($cnt / $les_stats['total'] * 100, 1);
        $dot_style = $key === 'remote' ? 'background:#0694A2' : '';
        $dot_class = $key === 'remote' ? '' : str_replace('bg-','text-',$bsClass);
      ?>
      <span>
        <span class="<?= $dot_class ?>" style="<?= $dot_style ? 'color:#0694A2' : '' ?>">■</span>
        <?= h($bsLabel) ?>: <strong><?= $cnt ?></strong> <span class="text-body-secondary">(<?= $pct ?>%)</span>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Tabela lekcji -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-journal-text text-success"></i>Lista lekcji
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= $les_stats['total'] ?> rekord<?= $les_stats['total'] !== 1 ? 'ów' : '' ?> ·
      <?= number_format($les_total_h, 1, ',', '') ?> h ·
      netto est. <?= number_format($les_stats['netto'], 2, ',', ' ') ?> zł
    </span>
  </div>
  <?php if (!$les_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>Brak lekcji dla wybranych filtrów.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover" style="font-size:.84rem">
      <caption class="visually-hidden">Szczegółowa lista lekcji TI</caption>
      <thead class="table-light">
        <tr>
          <th style="min-width:90px">Data</th>
          <th style="min-width:80px">Czas</th>
          <th style="min-width:120px">Kurs</th>
          <th style="min-width:110px">Prowadzący</th>
          <th style="min-width:120px">Status</th>
          <th style="min-width:130px">Temat</th>
          <th class="text-center" style="min-width:80px">Frekwencja</th>
          <th class="text-end" style="min-width:55px">Czas h</th>
          <th class="text-end" style="min-width:80px">Netto est.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($les_rows as $les_r):
          $st_def  = K30_TI_SESSION_STATUSES[$les_r['status']] ?? ['label'=>$les_r['status'],'color'=>'#888','bg'=>''];
          $att_pct = (int)$les_r['att_total'] > 0 ? round((int)$les_r['att_present'] / (int)$les_r['att_total'] * 100) : null;
          $att_col = $att_pct === null ? 'secondary' : ($att_pct >= 80 ? 'success' : ($att_pct >= 60 ? 'warning' : 'danger'));
          $row_bg  = $les_r['status'] === 'cancelled' ? 'table-danger bg-opacity-10' : ($les_r['status'] === 'remote_material' ? '' : '');
          $row_style = $les_r['status'] === 'remote_material' ? 'background:#E6FEF9' : '';
        ?>
        <tr class="<?= $row_bg ?>" style="<?= $row_style ?>">
          <td class="text-nowrap">
            <?= date('d.m.Y', strtotime($les_r['lesson_date'])) ?>
            <div class="small text-body-secondary"><?php
              $dow = ['Sun'=>'Nd','Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'So'];
              echo $dow[date('D', strtotime($les_r['lesson_date']))] ?? '';
            ?></div>
          </td>
          <td class="text-nowrap small text-body-secondary">
            <?= substr((string)($les_r['time_from'] ?? ''), 0, 5) ?>
            <?php if ($les_r['time_to']): ?>–<?= substr((string)$les_r['time_to'], 0, 5) ?><?php endif; ?>
          </td>
          <td>
            <a href="course.php?id=<?= (int)$les_r['course_id'] ?>" class="text-decoration-none fw-semibold" style="font-size:.83rem"><?= h($les_r['course_name']) ?></a>
          </td>
          <td class="small text-body-secondary"><?= h($les_r['instructor_name']) ?></td>
          <td>
            <span class="badge" style="background:<?= h($st_def['bg'] ?: '#e5e7eb') ?>;color:<?= h($st_def['color']) ?>;border:1px solid <?= h($st_def['color']) ?>20">
              <?= h($st_def['label']) ?>
            </span>
          </td>
          <td class="small text-body-secondary" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h((string)($les_r['topic'] ?? '')) ?>">
            <?= h((string)($les_r['topic'] ?? '—')) ?>
          </td>
          <td class="text-center">
            <?php if ($les_r['status'] === 'remote_material'): ?>
              <span class="text-body-secondary" title="Praca prowadzącego — brak frekwencji"><i class="bi bi-person-workspace"></i></span>
            <?php elseif ($att_pct !== null): ?>
              <span class="badge bg-<?= $att_col ?>-subtle text-<?= $att_col ?>-emphasis"><?= $att_pct ?>%</span>
              <div class="text-body-secondary mt-1" style="font-size:.72rem"><?= $les_r['att_present'] ?>/<?= $les_r['att_total'] ?></div>
            <?php else: ?>
              <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php $les_h = round((int)($les_r['duration_min'] ?? 0)/60, 2); ?>
            <?= number_format($les_h, 2, ',', '') ?> h
          </td>
          <td class="text-end text-nowrap fw-semibold <?= $les_r['netto'] > 0 ? 'text-success' : 'text-body-secondary' ?>">
            <?= $les_r['netto'] > 0 ? number_format($les_r['netto'], 2, ',', ' ') . ' zł' : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="6">SUMA (<?= $les_stats['total'] ?> lekcji)</td>
          <td class="text-center">
            <?php if ($avg_att !== null): ?>
            <span class="badge bg-<?= $avg_att>=80?'success':($avg_att>=60?'warning':'danger') ?>"><?= $avg_att ?>%</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= number_format($les_total_h, 2, ',', '') ?> h</td>
          <td class="text-end text-success"><?= number_format($les_stats['netto'], 2, ',', ' ') ?> zł</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ PROWADZĄCY — godziny i wynagrodzenia ════════════════════════════ */ ?>
<?php elseif ($report === 'prowadzacy'): ?>
<?php
  $prow_max_netto = max(array_merge([0], array_column($prow_rows, 'netto')));
?>
<!-- KPI cards -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Prowadzących</div>
      <div class="fs-3 fw-bold"><?= count($prow_rows) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Lekcji łącznie</div>
      <div class="fs-3 fw-bold"><?= $prow_totals['lessons'] ?></div>
      <div class="small text-body-secondary"><?= $prow_totals['held'] ?> odbyto · <?= $prow_totals['remote'] ?> materiał</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Łączny czas</div>
      <div class="fs-3 fw-bold"><?= number_format(round($prow_totals['min']/60,1),1,',','') ?> h</div>
      <div class="small text-body-secondary"><?= $prow_totals['min'] ?> min</div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
      <div class="text-body-secondary small">Netto łącznie</div>
      <div class="fs-3 fw-bold text-success"><?= number_format($prow_totals['netto'], 2, ',', ' ') ?> zł</div>
      <div class="small text-body-secondary">BB: <?= number_format($prow_totals['brutto_brutto'], 2, ',', ' ') ?> zł</div>
    </div></div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-person-badge text-info"></i>Prowadzący — zestawienie
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= h($date_from_str) ?> – <?= h($date_to_str) ?>
    </span>
  </div>
  <?php if (!$prow_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>Brak lekcji w wybranym okresie.
  </div>
  <?php else: ?>
  <div class="card-body py-2 border-bottom small text-body-secondary">
    <i class="bi bi-info-circle me-1"></i>Liczy tylko lekcje rozliczane: Odbyła się, Zmiana indywidualna, Materiał zdalny. Netto est. wg formy umowy (zlecenie/student/B2B).
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover" style="font-size:.84rem">
      <caption class="visually-hidden">Prowadzący — godziny i wynagrodzenia TI</caption>
      <thead class="table-light">
        <tr>
          <th style="min-width:140px">Prowadzący</th>
          <th style="min-width:80px">Forma</th>
          <th class="text-center" title="Odbyła się">Odbyte</th>
          <th class="text-center" title="Zmiana indywidualna">Ind.</th>
          <th class="text-center" title="Praca prowadzącego (materiał zdalny)">Materiał</th>
          <th class="text-center">Lekcji</th>
          <th class="text-end">Czas h</th>
          <th class="text-end">BB (zł)</th>
          <th class="text-end">Brutto (zł)</th>
          <th class="text-end">ZUS prac. (zł)</th>
          <th class="text-end">Składki (zł)</th>
          <th class="text-end">PIT (zł)</th>
          <th class="text-end" style="min-width:140px">Netto est. (zł)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($prow_rows as $pr):
          $pr_h     = round($pr['min']/60, 2);
          $bar_pct  = $prow_max_netto > 0 ? round($pr['netto'] / $prow_max_netto * 100) : 0;
        ?>
        <tr>
          <td class="fw-semibold"><?= h($pr['name']) ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($pr['payout_form'] ?? '—') ?></span></td>
          <td class="text-center"><?= $pr['held'] ?: '—' ?></td>
          <td class="text-center"><?= $pr['ind']  ?: '—' ?></td>
          <td class="text-center"><?= $pr['remote']?: '—' ?></td>
          <td class="text-center fw-semibold"><?= $pr['lessons'] ?></td>
          <td class="text-end"><?= number_format($pr_h, 2, ',', '') ?></td>
          <td class="text-end"><?= number_format($pr['brutto_brutto'], 2, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($pr['brutto'], 2, ',', ' ') ?></td>
          <td class="text-end text-body-secondary"><?= number_format($pr['zus_employer'], 2, ',', ' ') ?></td>
          <td class="text-end text-body-secondary"><?= number_format($pr['skladki'], 2, ',', ' ') ?></td>
          <td class="text-end text-body-secondary"><?= number_format($pr['pit'], 2, ',', ' ') ?></td>
          <td class="text-end">
            <div class="fw-bold text-success"><?= number_format($pr['netto'], 2, ',', ' ') ?> zł</div>
            <?php if ($bar_pct > 0): ?>
            <div class="progress mt-1" style="height:4px">
              <div class="progress-bar bg-success" style="width:<?= $bar_pct ?>%"></div>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <?php $tot_h = round($prow_totals['min']/60, 2); ?>
          <td colspan="2">SUMA (<?= count($prow_rows) ?> prowadzących)</td>
          <td class="text-center"><?= $prow_totals['held'] ?></td>
          <td class="text-center"><?= $prow_totals['ind'] ?></td>
          <td class="text-center"><?= $prow_totals['remote'] ?></td>
          <td class="text-center"><?= $prow_totals['lessons'] ?></td>
          <td class="text-end"><?= number_format($tot_h, 2, ',', '') ?></td>
          <td class="text-end"><?= number_format($prow_totals['brutto_brutto'], 2, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($prow_totals['brutto'], 2, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($prow_totals['zus_employer'], 2, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($prow_totals['skladki'], 2, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($prow_totals['pit'], 2, ',', ' ') ?></td>
          <td class="text-end text-success"><?= number_format($prow_totals['netto'], 2, ',', ' ') ?> zł</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ WYNAGRODZENIA — pivot prowadzący × miesiąc ════════════════════════ */ ?>
<?php elseif ($report === 'wynagrodzenia'): ?>
<?php
  $mn_names = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
               7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
  $wyn_lbl = [];
  foreach ($wyn_months as $ym) {
      [$y,$m] = explode('-', $ym);
      $wyn_lbl[$ym] = ($mn_names[(int)$m] ?? $m) . ' ' . substr($y, 2);
  }
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-cash-stack text-warning"></i>Wynagrodzenia netto — pivot
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= h($date_from_str) ?> – <?= h($date_to_str) ?> ·
      łącznie <strong><?= number_format($wyn_grand_total, 2, ',', ' ') ?> zł netto</strong>
    </span>
  </div>
  <div class="card-body py-2 border-bottom small text-body-secondary">
    <i class="bi bi-info-circle me-1"></i>Tylko lekcje z ustawioną stawką (BB&nbsp;&gt;&nbsp;0). Netto est. wg formy umowy prowadzącego. Odwołane i planowane lekcje są wykluczone.
  </div>
  <?php if (!$wyn_pivot): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-cash fs-2 d-block mb-2"></i>Brak danych dla wybranego okresu.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover" style="font-size:.84rem">
      <caption class="visually-hidden">Wynagrodzenia netto prowadzących — tabela przestawna</caption>
      <thead class="table-light">
        <tr>
          <th style="min-width:160px">Prowadzący</th>
          <th style="min-width:70px">Forma</th>
          <?php foreach ($wyn_months as $ym): ?>
          <th class="text-end" style="min-width:80px"><?= h($wyn_lbl[$ym]) ?></th>
          <?php endforeach; ?>
          <th class="text-end bg-light" style="min-width:90px">Razem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($wyn_pivot as $wyn_r):
          $row_max = $wyn_r['total'] > 0 ? $wyn_r['total'] : 1;
        ?>
        <tr>
          <td class="fw-semibold"><?= h($wyn_r['name']) ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($wyn_r['payout_form'] ?? '—') ?></span></td>
          <?php foreach ($wyn_months as $ym):
            $val = $wyn_r['months'][$ym] ?? 0.0;
            $bar = $wyn_grand_total > 0 ? round($val / max(array_values($wyn_col_totals)) * 100) : 0;
          ?>
          <td class="text-end">
            <?php if ($val > 0): ?>
            <div><?= number_format($val, 2, ',', ' ') ?></div>
            <div class="progress mt-1" style="height:3px">
              <div class="progress-bar bg-warning" style="width:<?= $bar ?>%"></div>
            </div>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-end bg-light fw-bold text-success">
            <?= number_format($wyn_r['total'], 2, ',', ' ') ?> zł
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">SUMA (<?= count($wyn_pivot) ?> prowadzących)</td>
          <?php foreach ($wyn_months as $ym): ?>
          <td class="text-end"><?= number_format($wyn_col_totals[$ym], 2, ',', ' ') ?></td>
          <?php endforeach; ?>
          <td class="text-end bg-light text-success"><?= number_format($wyn_grand_total, 2, ',', ' ') ?> zł</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
