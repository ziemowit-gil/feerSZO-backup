<?php
/**
 * modules/ti_revenue_report/logic/tiRevenueReport.php — raport przychodu TI od uczestników: naliczone, sugerowane i prognozy wg frekwencji.
 *
 * Tylko odczyt. Dla wybranego miesiąca i każdej grupy (kursu z rozliczanymi uczestnikami):
 *  • NALICZONE dotąd — dokładnie to, co dałoby rozliczenie miesięczne (k30_ti_calculate_billing): godziny obecności / no-show po stawkach
 *    z dnia lekcji (zmiany cen), ryczałty (miesięczny / stały) w pełnej kwocie;
 *  • SUGEROWANE — przychód potencjalny przy PEŁNEJ frekwencji: wszystkie lekcje miesiąca (odbyte + zaplanowane, bez odwołanych)
 *    × każdy aktywny uczestnik × jego stawka (ti_price_hourly_breakdown); ryczałt bez zmian. Różnica „sugerowane − naliczone” to kwota
 *    utracona przez nieobecności (i odwołania, które już zdjęły lekcje z puli);
 *  • PROGNOZA przy frekwencji p% = naliczone dotąd + (zaplanowane lekcje × stawki) × p/100 — frekwencja dotyczy tylko lekcji, które jeszcze
 *    się nie odbyły; scenariusze 100/90/80/70/60/50% oraz „przy bieżącej frekwencji grupy” (obecni / wszyscy zapisani na odbytych lekcjach
 *    miesiąca, a bez nich — z ostatnich 90 dni). Ryczałt nie zależy od frekwencji.
 * Pomijane: grupy „tylko godziny” (bez kwot), uczestnicy „bez rozliczeń”, grupy testowe i usunięte. Wynik w kształcie zestawu danych jak
 * Analityka TI (AnalyticsExport): ['title','subtitle','headers','rows','num','dec','sheet','notes'] + 'summary' (sumy do kafli).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_price_changes.php';
require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testFilter.php';

final class TiRevenueReport
{
    /** Scenariusze frekwencji (%) w prognozie lekcji zaplanowanych. */
    public const SCENARIOS = [100, 90, 80, 70, 60, 50];

    private const HELD = ['held', 'individual_change', 'remote_material'];

    /** Godziny lekcji jak w rozliczeniach: ceil(minuty / 60). */
    private static function hours(array $s): float
    {
        $min = (int)($s['duration_min'] ?? 0);
        if ($min <= 0 && ($s['time_from'] ?? '') !== '' && ($s['time_to'] ?? '') !== '') {
            $min = max(0, (int)((strtotime('2000-01-01 ' . $s['time_to']) - strtotime('2000-01-01 ' . $s['time_from'])) / 60));
        }
        return (float)ceil($min / 60);
    }

    /** Frekwencja grupy (%): obecni / wszyscy z listy obecności odbytych lekcji w zakresie; null gdy brak danych. */
    private static function attendancePct(int $courseId, string $from, string $to): ?float
    {
        $r = db_one(
            "SELECT SUM(CASE WHEN a.attended=1 THEN 1 ELSE 0 END) AS p, COUNT(*) AS n
               FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
              WHERE s.course_id=? AND s.status IN ('held','individual_change','remote_material') AND s.lesson_date BETWEEN ? AND ?
                AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.cancel_pending,0)=0",
            [$courseId, $from, $to]
        );
        return $r && (int)$r['n'] > 0 ? round((int)$r['p'] / (int)$r['n'] * 100, 1) : null;
    }

    /**
     * @return array dataset + 'summary' => ['accrued','potential','lost','planned_full','forecast'=>[p=>kwota],'forecast_cur','groups','month']
     */
    public static function month(string $m): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
        $year = (int)substr($m, 0, 4); $mon = (int)substr($m, 5, 2);
        $from = $m . '-01'; $to = date('Y-m-t', strtotime($from));
        $today = date('Y-m-d');

        $courses = db_all(
            "SELECT c.* FROM k30_ti_courses c
              WHERE c.status NOT IN ('cancelled','archived') AND " . ti_test_course_sql('c.id') . "
                AND EXISTS (SELECT 1 FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active')
              ORDER BY c.name"
        );
        $rows = []; $tot = ['n' => 0, 'held_h' => 0.0, 'plan_h' => 0.0, 'accrued' => 0.0, 'potential' => 0.0, 'planned_full' => 0.0, 'cur' => 0.0];
        $scn = array_fill_keys(self::SCENARIOS, 0.0); $attP = 0; $attN = 0;
        foreach ($courses as $c) {
            $cid = (int)$c['id'];
            if (ti_course_hours_only($cid, $m)) continue;   // bez kwot
            $sessions = db_all(
                "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status, s.lesson_method
                   FROM k30_ti_sessions s WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ? AND s.draft_id IS NULL
                    AND s.status IN ('held','individual_change','remote_material','planned') ORDER BY s.lesson_date, s.time_from",
                [$cid, $from, $to]
            );
            $held = []; $plan = []; $heldH = 0.0; $planH = 0.0;
            foreach ($sessions as $s) {
                $h = self::hours($s);
                $l = ['date' => (string)$s['lesson_date'], 'hours' => $h, 'session_id' => (int)$s['id'], 'online' => ti_session_is_online((string)$s['lesson_method'], $cid)];
                if (in_array((string)$s['status'], self::HELD, true)) { $held[] = $l; $heldH += $h; } else { $plan[] = $l; $planH += $h; }
            }
            $enrs = db_all(
                "SELECT e.*, c.billing_model AS course_billing_model, c.billing_amount AS course_billing_amount
                   FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id WHERE e.course_id=? AND e.status='active'", [$cid]
            );
            $accrued = 0.0; $potential = 0.0; $plannedFull = 0.0; $n = 0;
            foreach ($enrs as $e) {
                $client = (int)$e['client_id'];
                if (ti_client_no_billing($client)) continue;
                $n++;
                $eff = k30_ti_effective_billing($e, ['billing_model' => $e['course_billing_model'], 'billing_amount' => $e['course_billing_amount']]);
                $calc = k30_ti_calculate_billing($client, $mon, $year, $cid);
                $act = 0.0;
                foreach ($calc['courses'] as $cc) if ((int)$cc['course_id'] === $cid) $act = (float)$cc['amount'];
                $accrued += $act;
                if (in_array((int)$eff['model'], [1, 3], true)) {   // ryczałt: kwota niezależna od lekcji i frekwencji
                    $potential += $act;
                } else {
                    $all = array_merge($held, $plan);
                    $potential   += $all  ? (float)ti_price_hourly_breakdown($eff, $cid, $client, $all)['amount']  : 0.0;
                    $plannedFull += $plan ? (float)ti_price_hourly_breakdown($eff, $cid, $client, $plan)['amount'] : 0.0;
                }
            }
            if ($n === 0) continue;
            $pct = self::attendancePct($cid, $from, $to) ?? self::attendancePct($cid, date('Y-m-d', strtotime($today . ' -90 days')), $today);
            $fc = []; foreach (self::SCENARIOS as $p) $fc[$p] = $accrued + $plannedFull * $p / 100;
            $cur = $pct === null ? null : $accrued + $plannedFull * $pct / 100;
            $row = [(string)$c['name'], $n, $pct, round($heldH, 1), round($planH, 1), round($accrued, 2), round($potential, 2), round($potential - $accrued, 2)];
            foreach (self::SCENARIOS as $p) $row[] = round($fc[$p], 2);
            $row[] = $cur === null ? null : round($cur, 2);
            $rows[] = $row;
            $tot['n'] += $n; $tot['held_h'] += $heldH; $tot['plan_h'] += $planH; $tot['accrued'] += $accrued; $tot['potential'] += $potential; $tot['planned_full'] += $plannedFull;
            $tot['cur'] += $cur ?? ($accrued + $plannedFull);   // brak danych o frekwencji → bez założeń o spadku (100%)
            foreach (self::SCENARIOS as $p) $scn[$p] += $fc[$p];
            if ($pct !== null) { $attP += $pct * $n; $attN += $n; }
        }
        $sumRow = ['Razem', $tot['n'], $attN ? round($attP / $attN, 1) : null, round($tot['held_h'], 1), round($tot['plan_h'], 1), round($tot['accrued'], 2), round($tot['potential'], 2), round($tot['potential'] - $tot['accrued'], 2)];
        foreach (self::SCENARIOS as $p) $sumRow[] = round($scn[$p], 2);
        $sumRow[] = round($tot['cur'], 2);
        if ($rows) $rows[] = $sumRow;
        $headers = array_merge(['Grupa', 'Uczestnicy', 'Frekwencja bieżąca (%)', 'Godziny odbyte', 'Godziny zaplanowane', 'Naliczone dotąd (zł)', 'Sugerowane — pełna frekwencja (zł)', 'Utracone (zł)'],
            array_map(fn($p) => 'Prognoza przy ' . $p . '% (zł)', self::SCENARIOS), ['Prognoza przy bieżącej frekwencji (zł)']);
        $money = [5, 6, 7]; foreach (range(8, 8 + count(self::SCENARIOS)) as $i) $money[] = $i;
        $dec = [2 => 1, 3 => 1, 4 => 1]; foreach ($money as $i) $dec[$i] = 2;
        $label = self::monthLabel($m);
        return [
            'title' => 'Przychód TI — ' . $label, 'subtitle' => 'wszystkie grupy z rozliczanymi uczestnikami, ' . $label,
            'headers' => $headers, 'rows' => $rows, 'num' => range(1, count($headers) - 1), 'dec' => $dec, 'sheet' => mb_substr($label, 0, 31),
            'notes' => [
                'Naliczone dotąd = kwota z rozliczenia miesięcznego (godziny obecności i no-show po stawkach z dnia lekcji; ryczałty w pełnej kwocie).',
                'Sugerowane = przychód przy pełnej frekwencji: wszystkie lekcje miesiąca (odbyte i zaplanowane, bez odwołanych) × wszyscy aktywni uczestnicy × ich stawki. „Utracone” to różnica sugerowane − naliczone.',
                'Prognoza przy p% = naliczone dotąd + zaplanowane lekcje × stawki × p%. Frekwencja bieżąca = obecni / wszyscy zapisani na odbytych lekcjach miesiąca (bez nich — z ostatnich 90 dni). Ryczałty i grupy „tylko godziny” nie zależą od frekwencji.',
            ],
            'summary' => ['month' => $m, 'groups' => count($rows) - ($rows ? 1 : 0), 'accrued' => round($tot['accrued'], 2), 'potential' => round($tot['potential'], 2),
                'lost' => round($tot['potential'] - $tot['accrued'], 2), 'planned_full' => round($tot['planned_full'], 2),
                'forecast' => array_map(fn($v) => round($v, 2), $scn), 'forecast_cur' => round($tot['cur'], 2), 'attendance' => $attN ? round($attP / $attN, 1) : null],
        ];
    }

    public static function monthLabel(string $m): string
    {
        $n = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        return ($n[(int)substr($m, 5, 2)] ?? '') . ' ' . substr($m, 0, 4);
    }
}
