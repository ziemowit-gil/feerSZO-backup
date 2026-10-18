<?php
/**
 * modules/ti_dop/lib/RateReport.php — raport kierownika „Stawki godzinowe”: na koncie uczestnika liczba godzin
 * rozliczonych i kwota (należności, wpłaty) → efektywna stawka za godzinę; porównanie ze stawką zapisu do grupy.
 * Źródło: k30_ti_billing (issued/paid, bez grup „tylko godziny”), k30_ti_payments (bez przeksięgowań wewnętrznych).
 */
declare(strict_types=1);

final class RateReport
{
    /**
     * @param array{year:int, month:int, q:string} $f  month = 0 → cały rok; year = 0 → wszystkie lata
     * @return array{rows:list<array>, total:array}
     */
    public static function build(array $f): array
    {
        $w = ["b.status IN ('issued','paid')", "COALESCE(b.hours_only, 0) = 0"]; $p = [];
        if ($f['year'] > 0) { $w[] = 'b.year = ?'; $p[] = $f['year']; }
        if ($f['year'] > 0 && $f['month'] > 0) { $w[] = 'b.month = ?'; $p[] = $f['month']; }
        if ($f['q'] !== '') { $w[] = 'c.name LIKE ?'; $p[] = '%' . $f['q'] . '%'; }
        $rows = [];
        foreach (db_all("SELECT b.client_id, COALESCE(b.course_id, 0) AS course_id, c.name AS client_name, co.name AS course_name, co.group_code,
                                SUM(b.hours_billed) AS h, SUM(b.amount + COALESCE(b.adjustment, 0)) AS due, SUM(COALESCE(b.paid_amount, 0)) AS paid, COUNT(*) AS months,
                                SUM(CASE WHEN b.manual_amount IS NOT NULL THEN 1 ELSE 0 END) AS manual
                         FROM k30_ti_billing b JOIN k30_clients c ON c.id = b.client_id LEFT JOIN k30_ti_courses co ON co.id = b.course_id
                         WHERE " . implode(' AND ', $w) . " GROUP BY b.client_id, COALESCE(b.course_id, 0) ORDER BY c.name COLLATE NOCASE, co.name", $p) as $r) {
            $cid = (int)$r['client_id'];
            if (!isset($rows[$cid])) $rows[$cid] = ['client_id' => $cid, 'name' => (string)$r['client_name'], 'groups' => [], 'h' => 0.0, 'due' => 0.0, 'paid' => 0.0, 'payments' => 0.0];
            $enr = (int)$r['course_id'] > 0 ? db_one("SELECT hourly_rate, hourly_rate_online FROM k30_ti_enrollments WHERE client_id = ? AND course_id = ? ORDER BY id DESC LIMIT 1", [$cid, (int)$r['course_id']]) : null;
            $h = round((float)$r['h'], 2); $due = round((float)$r['due'], 2);
            $rate = $h > 0.004 ? round($due / $h, 2) : null; $er = $enr ? (float)$enr['hourly_rate'] : null;
            $rows[$cid]['groups'][] = ['course_id' => (int)$r['course_id'], 'name' => (int)$r['course_id'] > 0 ? trim((string)$r['course_name'] . (trim((string)$r['group_code']) !== '' ? ' (' . $r['group_code'] . ')' : '')) : 'rozliczenie łączne / opłaty',
                'h' => $h, 'due' => $due, 'paid' => round((float)$r['paid'], 2), 'rate' => $rate, 'enr_rate' => $er, 'months' => (int)$r['months'], 'manual' => (int)$r['manual'],
                'diff' => $rate !== null && $er !== null && $er > 0 && abs($rate - $er) >= 0.01];
            $rows[$cid]['h'] += $h; $rows[$cid]['due'] += $due; $rows[$cid]['paid'] += (float)$r['paid'];
        }
        // wpłaty na konto w okresie (rzeczywiste wpływy, bez przeksięgowań)
        [$from, $to] = self::range($f);
        foreach ($rows as $cid => &$x) {
            $x['payments'] = round((float)(db_one("SELECT COALESCE(SUM(amount), 0) s FROM k30_ti_payments WHERE client_id = ? AND method NOT IN ('internal') AND COALESCE(source_type, '') NOT IN ('writeoff','transfer','overpay_refund','overpay_settle','overpay_transfer','dop2_payout')
                                                     AND COALESCE(paid_at, date(created_at)) BETWEEN ? AND ?", [$cid, $from, $to])['s'] ?? 0), 2);
            $x['h'] = round($x['h'], 2); $x['due'] = round($x['due'], 2); $x['paid'] = round($x['paid'], 2);
            $x['rate'] = $x['h'] > 0.004 ? round($x['due'] / $x['h'], 2) : null;
            $x['pay_rate'] = $x['h'] > 0.004 ? round($x['payments'] / $x['h'], 2) : null;
            $x['diff'] = (bool)array_filter($x['groups'], fn($g) => $g['diff']);
        }
        unset($x);
        $t = ['h' => round(array_sum(array_column($rows, 'h')), 2), 'due' => round(array_sum(array_column($rows, 'due')), 2), 'payments' => round(array_sum(array_column($rows, 'payments')), 2)];
        $t['rate'] = $t['h'] > 0.004 ? round($t['due'] / $t['h'], 2) : null;
        $t['pay_rate'] = $t['h'] > 0.004 ? round($t['payments'] / $t['h'], 2) : null;
        return ['rows' => array_values($rows), 'total' => $t];
    }

    /** @return array{0:string,1:string} zakres dat okresu raportu */
    public static function range(array $f): array
    {
        if ($f['year'] <= 0) return ['0000-00-00', '9999-12-31'];
        if ($f['month'] > 0) { $a = sprintf('%04d-%02d-01', $f['year'], $f['month']); return [$a, date('Y-m-t', strtotime($a))]; }
        return [$f['year'] . '-01-01', $f['year'] . '-12-31'];
    }

    public static function csv(array $rep): string
    {
        $n = fn($v) => $v === null ? '' : number_format((float)$v, 2, ',', '');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Uczestnik', 'Grupa', 'Godziny rozliczone', 'Należności (zł)', 'Stawka (zł/h)', 'Stawka zapisu (zł/h)', 'Różnica', 'Wpłaty na konto (zł)', 'Wpłaty / h'], ';', '"', '');
        foreach ($rep['rows'] as $r) {
            fputcsv($out, [$r['name'], 'RAZEM KONTO', $n($r['h']), $n($r['due']), $n($r['rate']), '', $r['diff'] ? 'tak' : '', $n($r['payments']), $n($r['pay_rate'])], ';', '"', '');
            foreach ($r['groups'] as $g) fputcsv($out, [$r['name'], $g['name'], $n($g['h']), $n($g['due']), $n($g['rate']), $n($g['enr_rate']), $g['diff'] ? 'tak' : '', '', ''], ';', '"', '');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }
}
