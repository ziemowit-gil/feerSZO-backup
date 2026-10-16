<?php
/**
 * modules/it_control/_ui.php — wspólne elementy widoków Kontroli IT (Bootstrap 5).
 * Dołączane przez modules/it_control/index.php i modules/log_control/index.php.
 */

const ITC_UI_STATUS = [
    'PASS'    => ['cls' => 'success',   'icon' => 'bi-check-circle-fill',        'label' => 'PASS'],
    'WARNING' => ['cls' => 'warning',   'icon' => 'bi-exclamation-triangle-fill','label' => 'WARNING'],
    'FAIL'    => ['cls' => 'danger',    'icon' => 'bi-x-octagon-fill',           'label' => 'FAIL'],
    'SKIPPED' => ['cls' => 'secondary', 'icon' => 'bi-dash-circle',              'label' => 'POMINIĘTE'],
];
const ITC_UI_SEVERITY = ['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'secondary', 'info' => 'light'];

function itcUiBadge(string $status, string $extra = ''): string {
    $s = ITC_UI_STATUS[$status] ?? ITC_UI_STATUS['SKIPPED'];
    $txt = $s['cls'] === 'warning' ? ' text-dark' : '';
    return '<span class="badge bg-' . $s['cls'] . $txt . ' ' . h($extra) . '"><i class="bi ' . $s['icon'] . ' me-1" aria-hidden="true"></i>' . h($s['label']) . '</span>';
}

function itcUiSeverity(string $sev): string {
    $cls = ITC_UI_SEVERITY[$sev] ?? 'secondary';
    return '<span class="badge bg-' . $cls . ($cls === 'warning' || $cls === 'light' ? ' text-dark border' : '') . '">' . h(ITC_SEVERITY[$sev]['label'] ?? $sev) . '</span>';
}

function itcUiAgo(?string $dt): string {
    if (!$dt) return '—';
    $s = time() - strtotime($dt);
    if ($s < 90) return 'przed chwilą';
    if ($s < 5400) return (int)round($s / 60) . ' min temu';
    if ($s < 172800) return (int)round($s / 3600) . ' h temu';
    return (int)round($s / 86400) . ' dni temu';
}

/** Tabela wyników sprawdzeń jednego przebiegu (ze znaleziskami w rozwijanych wierszach). */
function itcUiResultsTable(array $run): string {
    $h = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr>'
       . '<th scope="col">Sprawdzenie</th><th scope="col">Status</th><th scope="col" class="text-end">Zdarzeń</th><th scope="col">Wynik</th></tr></thead><tbody>';
    foreach ($run['results'] as $r) {
        $h .= '<tr><td><strong>' . h($r['title']) . '</strong><div class="small text-muted font-monospace">' . h($r['check_id']) . '</div></td>'
            . '<td>' . itcUiBadge($r['status']) . '</td><td class="text-end">' . (int)$r['events_processed'] . '</td><td>' . h($r['summary']);
        if ($r['findings']) {
            $h .= '<ul class="list-unstyled small mb-0 mt-1">';
            foreach ($r['findings'] as $f) {
                $h .= '<li class="mb-1">' . itcUiSeverity($f['severity']) . ' ' . h($f['title'])
                    . ($f['detail'] !== '' ? '<div class="text-muted text-break">' . h(mb_substr($f['detail'], 0, 400)) . '</div>' : '') . '</li>';
            }
            $h .= '</ul>';
        }
        $h .= '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
