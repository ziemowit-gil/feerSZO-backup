<?php
/**
 * modules/helpdeskReports/lib/helpdeskReports.php — pulpit raportowy Helpdesku (dla operatorów i administratora).
 * Wszystkie zestawienia dotyczą okresu „od $since” (zgłoszenia utworzone w okresie); zgłoszenia scalone (merged_into) pomijamy.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 3) . '/modules/helpdeskTime/lib/helpdeskTime.php';
require_once dirname(__DIR__, 3) . '/modules/helpdeskCsat/lib/helpdeskCsat.php';

const HD_REP_PERIODS = [7 => '7 dni', 30 => '30 dni', 90 => '90 dni', 365 => 'Rok'];

function hdRepSince(int $days): string
{
    return date('Y-m-d 00:00:00', strtotime('-' . max(1, $days) . ' days'));
}

function hdRepMin(?float $v): ?int
{
    return $v === null ? null : (int)round($v);
}

/** Wskaźniki zbiorcze okresu. */
function hdRepKpis(string $since): array
{
    helpdesk_migrate(); hdTimeMigrate();
    $base = "FROM helpdesk_tickets WHERE merged_into IS NULL AND created_at >= ?";
    $k = [
        'created'  => (int)db_one("SELECT COUNT(*) AS n $base", [$since])['n'],
        'closed'   => (int)db_one("SELECT COUNT(*) AS n $base AND status IN ('rozwiązane','zamknięte')", [$since])['n'],
        'open_now' => (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tickets WHERE merged_into IS NULL AND status NOT IN ('rozwiązane','zamknięte')")['n'],
        'unassigned_now' => (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tickets WHERE merged_into IS NULL AND assigned_to IS NULL AND status NOT IN ('rozwiązane','zamknięte')")['n'],
    ];
    $r = db_one("SELECT AVG((julianday(first_response_at)-julianday(created_at))*1440) AS m $base AND first_response_at IS NOT NULL", [$since]);
    $k['avg_response_min'] = hdRepMin($r['m'] !== null ? (float)$r['m'] : null);
    $r = db_one("SELECT AVG((julianday(COALESCE(resolved_at, closed_at))-julianday(created_at))*1440) AS m $base AND COALESCE(resolved_at, closed_at) IS NOT NULL", [$since]);
    $k['avg_resolution_min'] = hdRepMin($r['m'] !== null ? (float)$r['m'] : null);
    // SLA: odsetek zgłoszeń z okresu, w których cel (reakcji / rozwiązania) został dotrzymany lub nie został jeszcze przekroczony
    $tot = ['response' => [0, 0], 'resolution' => [0, 0]];
    foreach (db_all("SELECT * $base", [$since]) as $t) {
        $sla = hd_sla($t);
        foreach (['response', 'resolution'] as $p) {
            if (!$sla[$p] || $sla[$p]['state'] === 'paused') continue;
            $tot[$p][1]++;
            if ($sla[$p]['state'] !== 'breached') $tot[$p][0]++;
        }
    }
    $k['sla_response_pct'] = $tot['response'][1] ? (int)round(100 * $tot['response'][0] / $tot['response'][1]) : null;
    $k['sla_resolution_pct'] = $tot['resolution'][1] ? (int)round(100 * $tot['resolution'][0] / $tot['resolution'][1]) : null;
    $k['time_logged_min'] = (int)db_one("SELECT COALESCE(SUM(m.minutes),0) AS s FROM helpdesk_time m JOIN helpdesk_tickets t ON t.id=m.ticket_id WHERE t.merged_into IS NULL AND t.created_at >= ?", [$since])['s'];
    $k['csat'] = hdCsatSummary($since);
    return $k;
}

/** Liczba zgłoszeń per kategoria (ścieżka ze słownika). */
function hdRepByCategory(string $since): array
{
    $cats = hd_categories();
    $rows = db_all("SELECT category, COUNT(*) AS n,
                           SUM(status IN ('rozwiązane','zamknięte')) AS done
                    FROM helpdesk_tickets WHERE merged_into IS NULL AND created_at >= ? GROUP BY category ORDER BY n DESC", [$since]);
    foreach ($rows as &$r) $r['label'] = $cats[$r['category']] ?? $r['category'];
    return $rows;
}

/** Obciążenie i wyniki operatorów. */
function hdRepByOperator(string $since): array
{
    $rows = db_all("SELECT u.id, u.name, COUNT(t.id) AS assigned,
                           SUM(t.status IN ('rozwiązane','zamknięte')) AS done,
                           SUM(t.status NOT IN ('rozwiązane','zamknięte')) AS open_n,
                           AVG(CASE WHEN COALESCE(t.resolved_at, t.closed_at) IS NOT NULL
                               THEN (julianday(COALESCE(t.resolved_at, t.closed_at))-julianday(t.created_at))*1440 END) AS avg_res
                    FROM users u JOIN helpdesk_tickets t ON t.assigned_to=u.id AND t.merged_into IS NULL AND t.created_at >= ?
                    GROUP BY u.id ORDER BY assigned DESC", [$since]);
    foreach ($rows as &$r) {
        $r['avg_res'] = hdRepMin($r['avg_res'] !== null ? (float)$r['avg_res'] : null);
        $r['logged'] = (int)db_one("SELECT COALESCE(SUM(minutes),0) AS s FROM helpdesk_time WHERE user_id=? AND created_at >= ?", [(int)$r['id'], $since])['s'];
        $c = db_one("SELECT AVG(c.rating) AS a FROM helpdesk_csat c JOIN helpdesk_tickets t ON t.id=c.ticket_id WHERE t.assigned_to=? AND c.rating IS NOT NULL AND t.created_at >= ?", [(int)$r['id'], $since]);
        $r['csat'] = $c['a'] !== null ? round((float)$c['a'], 2) : null;
    }
    return $rows;
}

/** Dzienny napływ zgłoszeń i zamknięć (dla okresów > 90 dni — tygodniami). @return array<int, array{label:string,created:int,closed:int}> */
function hdRepTrend(int $days): array
{
    $bucket = $days > 90 ? 7 : 1;
    $out = [];
    for ($d = $days - 1; $d >= 0; $d -= $bucket) {
        $from = date('Y-m-d 00:00:00', strtotime("-$d days"));
        $to = date('Y-m-d 00:00:00', strtotime('-' . max(0, $d - $bucket) . ' days'));
        if ($d - $bucket < 0) $to = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $out[] = [
            'label' => date('d.m', strtotime($from)),
            'created' => (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tickets WHERE merged_into IS NULL AND created_at >= ? AND created_at < ?", [$from, $to])['n'],
            'closed' => (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tickets WHERE merged_into IS NULL AND COALESCE(resolved_at, closed_at) >= ? AND COALESCE(resolved_at, closed_at) < ?", [$from, $to])['n'],
        ];
    }
    return $out;
}

/** CSV zgłoszeń okresu (separator „;”, UTF-8 z BOM, ochrona przed formułami Excela). */
function hdRepCsv(string $since): string
{
    hdTimeMigrate();
    $cats = hd_categories();
    $esc = function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v;
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $out = "\xEF\xBB\xBF" . implode(';', ['Numer', 'Temat', 'Kategoria', 'Priorytet', 'Status', 'Zgłaszający', 'Przypisane do', 'Utworzono', 'Pierwsza odpowiedź', 'Rozwiązano', 'Czas pracy (min)']) . "\r\n";
    foreach (db_all("SELECT t.*, u.name AS op_name, (SELECT COALESCE(SUM(minutes),0) FROM helpdesk_time WHERE ticket_id=t.id) AS logged
                     FROM helpdesk_tickets t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.merged_into IS NULL AND t.created_at >= ? ORDER BY t.id", [$since]) as $t) {
        $out .= implode(';', array_map($esc, [$t['number'], $t['title'], $cats[$t['category']] ?? $t['category'], HD_PRIORITIES[$t['priority']]['label'] ?? $t['priority'],
            HD_STATUSES[$t['status']]['label'] ?? $t['status'], $t['requester_name'], $t['op_name'] ?? '', $t['created_at'], $t['first_response_at'] ?? '',
            $t['resolved_at'] ?? $t['closed_at'] ?? '', $t['logged']])) . "\r\n";
    }
    return $out;
}
