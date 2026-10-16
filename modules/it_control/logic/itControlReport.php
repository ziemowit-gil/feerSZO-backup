<?php
/**
 * modules/it_control/logic/itControlReport.php — pełny raport z przebiegów kontroli.
 *
 * itControlReportHtml() — samodzielny dokument HTML (style inline, bez zewnętrznych
 * zasobów): ten sam plik idzie jako załącznik e-maila i do pobrania z panelu.
 * itControlReportText() — wersja tekstowa (CLI, --dry-run, treść tekstowa maila).
 */

const ITC_STATUS_COLORS = [
    'PASS'    => ['fg' => '#166534', 'bg' => '#dcfce7', 'label' => 'PASS'],
    'WARNING' => ['fg' => '#92400e', 'bg' => '#fef3c7', 'label' => 'WARNING'],
    'FAIL'    => ['fg' => '#991b1b', 'bg' => '#fee2e2', 'label' => 'FAIL'],
    'SKIPPED' => ['fg' => '#475569', 'bg' => '#f1f5f9', 'label' => 'POMINIĘTE'],
];
const ITC_SEVERITY_COLORS = [
    'critical' => '#991b1b', 'high' => '#c2410c', 'medium' => '#b45309', 'low' => '#475569', 'info' => '#64748b',
];

function itcEsc(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function itControlBadge(string $status): string {
    $c = ITC_STATUS_COLORS[$status] ?? ITC_STATUS_COLORS['SKIPPED'];
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font:700 12px/1.6 Arial,sans-serif;'
         . 'letter-spacing:.04em;color:' . $c['fg'] . ';background:' . $c['bg'] . ';border:1px solid ' . $c['fg'] . '33">'
         . itcEsc($c['label']) . '</span>';
}

/** Status całościowy listy przebiegów. */
function itControlRunsStatus(array $runs): string {
    $s = itControlWorstStatus(array_column($runs, 'status'));
    return $s === 'SKIPPED' ? 'PASS' : $s;
}

/** Statystyka historii w okresie raportu: liczba przebiegów per zestaw i status. */
function itControlPeriodStats(string $since): array {
    $out = [];
    try {
        foreach (db_all("SELECT suite, status, COUNT(*) AS n, SUM(critical_count) AS crit FROM itc_runs WHERE started_at >= ? GROUP BY suite, status", [$since]) as $r) {
            $out[$r['suite']][$r['status']] = (int)$r['n'];
            $out[$r['suite']]['critical'] = ($out[$r['suite']]['critical'] ?? 0) + (int)$r['crit'];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/**
 * @param array $runs przebiegi (kształt z itControlRunSuite/itControlLoadRun)
 * @param array $opts title, period_label, period_since (Y-m-d H:i:s), dashboard_url
 */
function itControlReportHtml(array $runs, array $opts = []): string {
    $title = $opts['title'] ?? 'Raport kontroli IT';
    $overall = itControlRunsStatus($runs);
    $org = defined('ORG_NAME') ? ORG_NAME : 'SZO';
    $h = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . itcEsc($title) . '</title></head>'
       . '<body style="margin:0;background:#f1f5f9;font:14px/1.5 Arial,Helvetica,sans-serif;color:#0f172a">'
       . '<div style="max-width:960px;margin:0 auto;padding:24px 16px">';
    $h .= '<div style="background:#0f172a;color:#fff;border-radius:12px 12px 0 0;padding:20px 24px">'
        . '<div style="font-size:12px;opacity:.75;text-transform:uppercase;letter-spacing:.08em">' . itcEsc($org) . ' · Kontrola IT</div>'
        . '<h1 style="margin:4px 0 0;font-size:22px">' . itcEsc($title) . '</h1>'
        . '<div style="margin-top:6px;font-size:13px;opacity:.85">Wygenerowano ' . date('d.m.Y H:i')
        . (!empty($opts['period_label']) ? ' · okres: ' . itcEsc($opts['period_label']) : '') . '</div></div>';
    $h .= '<div style="background:#fff;border-radius:0 0 12px 12px;padding:20px 24px;margin-bottom:20px;border:1px solid #e2e8f0;border-top:0">'
        . '<div style="font-size:15px">Status całościowy: ' . itControlBadge($overall) . '</div>';
    if (!empty($opts['period_since'])) {
        $stats = itControlPeriodStats($opts['period_since']);
        if ($stats) {
            $h .= '<p style="margin:12px 0 0;color:#475569;font-size:13px">Przebiegi w okresie: ';
            $parts = [];
            foreach ($stats as $suite => $st) {
                $parts[] = itcEsc(ITC_SUITES[$suite]['label'] ?? $suite) . ' — PASS ' . ($st['PASS'] ?? 0) . ', WARNING ' . ($st['WARNING'] ?? 0)
                         . ', FAIL ' . ($st['FAIL'] ?? 0) . ', znalezisk krytycznych ' . ($st['critical'] ?? 0);
            }
            $h .= implode('; ', $parts) . '.</p>';
        }
    }
    if (!empty($opts['dashboard_url'])) {
        $h .= '<p style="margin:12px 0 0;font-size:13px"><a href="' . itcEsc($opts['dashboard_url']) . '" style="color:#1d4ed8">Otwórz pulpit Kontroli IT</a></p>';
    }
    $h .= '</div>';

    foreach ($runs as $run) {
        $suite = ITC_SUITES[$run['suite']]['label'] ?? $run['suite'];
        $h .= '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:20px;overflow:hidden">'
            . '<div style="padding:16px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'
            . '<h2 style="margin:0;font-size:18px">' . itcEsc($suite) . ' ' . itControlBadge($run['status']) . '</h2>'
            . '<span style="font-size:12px;color:#64748b">' . itcEsc($run['started_at']) . ($run['id'] ? ' · przebieg #' . (int)$run['id'] : ' · tryb próbny')
            . ' · ' . (int)$run['duration_ms'] . ' ms</span></div>';
        $h .= '<table role="presentation" style="width:100%;border-collapse:collapse;text-align:center"><tr>'
            . itControlKpiCell('Przetworzone zdarzenia', number_format((int)$run['events_processed'], 0, ',', ' '))
            . itControlKpiCell('Krytyczne', (int)$run['critical_count'], (int)$run['critical_count'] ? '#991b1b' : null)
            . itControlKpiCell('Sprawdzenia FAIL', (int)$run['fail_count'], (int)$run['fail_count'] ? '#991b1b' : null)
            . itControlKpiCell('Sprawdzenia WARNING', (int)$run['warning_count'], (int)$run['warning_count'] ? '#92400e' : null)
            . '</tr></table>';

        $crit = itControlRunFindings($run, ['critical', 'high']);
        if ($crit) {
            $h .= '<div style="margin:0 24px 16px;padding:12px 16px;border-left:4px solid #991b1b;background:#fef2f2;border-radius:6px">'
                . '<strong style="color:#991b1b">Wykryte błędy krytyczne i poważne</strong><ul style="margin:6px 0 0;padding-left:18px">';
            foreach ($crit as $f) {
                $h .= '<li><strong>' . itcEsc($f['title']) . '</strong>' . ($f['detail'] !== '' ? ' — ' . itcEsc($f['detail']) : '')
                    . ' <span style="color:#64748b">(' . itcEsc($f['check_title']) . ')</span></li>';
            }
            $h .= '</ul></div>';
        }
        $recs = itControlRunRecommendations($run);
        if ($recs) {
            $h .= '<div style="margin:0 24px 16px;padding:12px 16px;border-left:4px solid #1d4ed8;background:#eff6ff;border-radius:6px">'
                . '<strong style="color:#1e3a8a">Rekomendowane akcje</strong><ol style="margin:6px 0 0;padding-left:20px">';
            foreach ($recs as $rec) $h .= '<li>' . itcEsc($rec) . '</li>';
            $h .= '</ol></div>';
        }

        $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="background:#f8fafc;text-align:left;color:#475569">'
            . '<th style="padding:8px 24px;border-bottom:1px solid #e2e8f0">Sprawdzenie</th>'
            . '<th style="padding:8px;border-bottom:1px solid #e2e8f0">Status</th>'
            . '<th style="padding:8px;border-bottom:1px solid #e2e8f0">Zdarzeń</th>'
            . '<th style="padding:8px 24px 8px 8px;border-bottom:1px solid #e2e8f0">Wynik</th></tr></thead><tbody>';
        foreach ($run['results'] as $r) {
            $h .= '<tr style="vertical-align:top"><td style="padding:10px 24px;border-bottom:1px solid #f1f5f9"><strong>' . itcEsc($r['title']) . '</strong>'
                . '<div style="font:11px monospace;color:#94a3b8">' . itcEsc($r['check_id']) . '</div></td>'
                . '<td style="padding:10px 8px;border-bottom:1px solid #f1f5f9">' . itControlBadge($r['status']) . '</td>'
                . '<td style="padding:10px 8px;border-bottom:1px solid #f1f5f9">' . (int)$r['events_processed'] . '</td>'
                . '<td style="padding:10px 24px 10px 8px;border-bottom:1px solid #f1f5f9">' . itcEsc($r['summary']);
            $shown = array_filter($r['findings'], fn($f) => $f['severity'] !== 'info' || $r['status'] === 'PASS');
            if ($shown) {
                $h .= '<ul style="margin:6px 0 0;padding-left:16px">';
                foreach ($shown as $f) {
                    $h .= '<li><span style="color:' . (ITC_SEVERITY_COLORS[$f['severity']] ?? '#475569') . ';font-weight:700">'
                        . itcEsc(ITC_SEVERITY[$f['severity']]['label'] ?? $f['severity']) . ':</span> ' . itcEsc($f['title'])
                        . ($f['detail'] !== '' ? '<br><span style="color:#64748b">' . itcEsc(mb_substr($f['detail'], 0, 400)) . '</span>' : '') . '</li>';
                }
                $h .= '</ul>';
            }
            $top = $r['metrics']['top'] ?? [];
            if ($top) {
                $h .= '<details style="margin-top:6px"><summary style="cursor:pointer;color:#1d4ed8">Najczęstsze komunikaty (' . count($top) . ')</summary>'
                    . '<table style="width:100%;border-collapse:collapse;font:11px/1.4 monospace;margin-top:4px">';
                foreach ($top as $t) {
                    $h .= '<tr><td style="padding:2px 6px;color:' . (ITC_SEVERITY_COLORS[$t['level'] === 'fatal' ? 'critical' : ($t['level'] === 'error' ? 'high' : 'medium')]) . '">'
                        . itcEsc($t['level']) . '</td><td style="padding:2px 6px">×' . (int)$t['count'] . '</td>'
                        . '<td style="padding:2px 6px;word-break:break-all">' . itcEsc($t['sig']) . ' <span style="color:#94a3b8">[' . itcEsc($t['file']) . ']</span></td></tr>';
                }
                $h .= '</table></details>';
            }
            if (!empty($r['metrics']['last_24h']) && is_array($r['metrics']['last_24h'])) {
                $h .= itControlSparkTable($r['metrics']['last_24h']);
            }
            $h .= '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '<p style="color:#94a3b8;font-size:11px;text-align:center">Raport wygenerowany automatycznie przez moduł Kontroli IT systemu SZO.</p></div></body></html>';
    return $h;
}

function itControlKpiCell(string $label, int|string $value, ?string $color = null): string {
    return '<td style="padding:14px 8px;border-bottom:1px solid #e2e8f0;width:25%">'
         . '<div style="font-size:22px;font-weight:700;color:' . ($color ?? '#0f172a') . '">' . itcEsc($value) . '</div>'
         . '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">' . itcEsc($label) . '</div></td>';
}

/** Mini-wykres słupkowy 24 h jako tabela (działa w klientach poczty bez SVG). */
function itControlSparkTable(array $series): string {
    $max = max(1, max($series));
    $h = '<div style="margin-top:8px;font-size:11px;color:#64748b">Zdarzenia błędów w ostatnich 24 h (godzinowo, maks. ' . (int)$max . ')</div>'
       . '<table role="presentation" style="border-collapse:collapse;margin-top:2px"><tr style="vertical-align:bottom">';
    foreach ($series as $hour => $n) {
        $px = max(1, (int)round($n / $max * 36));
        $h .= '<td title="' . itcEsc($hour . ':00 — ' . $n) . '" style="padding:0 1px"><div style="width:8px;height:' . $px . 'px;background:'
            . ($n ? '#dc2626' : '#e2e8f0') . '"></div></td>';
    }
    return $h . '</tr></table>';
}

/** Wersja tekstowa raportu. */
function itControlReportText(array $runs, array $opts = []): string {
    $title = $opts['title'] ?? 'Raport kontroli IT';
    $lines = [$title, str_repeat('=', mb_strlen($title)), 'Wygenerowano: ' . date('Y-m-d H:i')];
    if (!empty($opts['period_label'])) $lines[] = 'Okres: ' . $opts['period_label'];
    $lines[] = 'STATUS CAŁOŚCIOWY: ' . itControlRunsStatus($runs);
    foreach ($runs as $run) {
        $lines[] = '';
        $head = (ITC_SUITES[$run['suite']]['label'] ?? $run['suite']) . ' — ' . $run['status'];
        $lines[] = $head;
        $lines[] = str_repeat('-', mb_strlen($head));
        $lines[] = sprintf('Przebieg: %s | zdarzeń: %d | krytycznych: %d | FAIL: %d | WARNING: %d | %d ms',
            $run['id'] ? '#' . $run['id'] : 'próbny', $run['events_processed'], $run['critical_count'], $run['fail_count'], $run['warning_count'], $run['duration_ms']);
        foreach ($run['results'] as $r) {
            $lines[] = sprintf('  [%-7s] %s — %s', $r['status'], $r['title'], $r['summary']);
            foreach ($r['findings'] as $f) {
                if ($f['severity'] === 'info') continue;
                $lines[] = '      ! ' . strtoupper($f['severity']) . ': ' . $f['title'] . ($f['detail'] !== '' ? ' — ' . mb_substr($f['detail'], 0, 200) : '');
            }
        }
        $recs = itControlRunRecommendations($run);
        if ($recs) {
            $lines[] = '  Rekomendowane akcje:';
            foreach ($recs as $i => $rec) $lines[] = '   ' . ($i + 1) . '. ' . $rec;
        }
    }
    if (!empty($opts['dashboard_url'])) { $lines[] = ''; $lines[] = 'Pulpit: ' . $opts['dashboard_url']; }
    return implode("\n", $lines) . "\n";
}
