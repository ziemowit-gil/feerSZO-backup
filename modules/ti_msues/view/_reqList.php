<?php
/**
 * modules/ti_msues/view/_reqList.php — lista wymagań MSUES usługi (wynik MsuesRequirements::evaluate) z wezwaniami do uzupełnienia.
 * Używana w ekranie usługi (msues.php), w kurs.php (wezwania) i na liście wymagań. Adresy w pozycjach są względne do katalogu dydaktyka.
 */
if (!function_exists('msuesReqList')) {
    function msuesReqStateIcon(string $state): string
    {
        return match ($state) {
            'ok'      => '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i><span class="visually-hidden">spełnione</span>',
            'warn'    => '<i class="bi bi-exclamation-circle-fill text-warning" aria-hidden="true"></i><span class="visually-hidden">do odświeżenia</span>',
            'na'      => '<i class="bi bi-dash-circle text-secondary" aria-hidden="true"></i><span class="visually-hidden">nie dotyczy</span>',
            default   => '<i class="bi bi-x-circle-fill text-danger" aria-hidden="true"></i><span class="visually-hidden">do uzupełnienia</span>',
        };
    }

    /** Tabela wymagań pogrupowana obszarami standardu; $onlyOpen = tylko braki i ostrzeżenia (wezwania). */
    function msuesReqList(array $e, bool $onlyOpen = false): string
    {
        $o = '';
        $isOrg = fn($i) => str_starts_with($i['code'], 'msues_doc_');
        // W trybie wezwań dokumenty instytucji (wspólne dla wszystkich usług) zwijamy do jednego wiersza na końcu
        $orgOpen = $onlyOpen ? array_filter($e['items'], fn($i) => $isOrg($i) && $i['state'] === 'missing') : [];
        foreach (MsuesRequirements::AREAS as $ak => $al) {
            $rows = array_filter($e['items'], fn($i) => $i['area'] === $ak && (!$onlyOpen || (in_array($i['state'], ['missing', 'warn'], true) && !$isOrg($i))));
            if (!$rows) continue;
            $o .= '<tr class="table-light"><th colspan="3" class="small text-uppercase">' . h($al) . '</th></tr>';
            foreach ($rows as $i) {
                $open = in_array($i['state'], ['missing', 'warn'], true);
                $o .= '<tr><td style="width:1.6rem">' . msuesReqStateIcon($i['state']) . '</td>'
                    . '<td><div class="fw-semibold small">' . h($i['label']) . '</div><div class="small text-body-secondary">' . h($i['msg']) . '</div></td>'
                    . '<td class="text-end text-nowrap" style="width:1%">' . ($i['href'] !== '' ? '<a class="btn btn-sm ' . ($open ? 'btn-warning' : 'btn-outline-secondary') . '" href="' . h($i['href']) . '">' . h($open ? $i['cta'] : 'Otwórz') . '</a>' : '') . '</td></tr>';
            }
        }
        if ($orgOpen) {
            $o .= '<tr class="table-light"><th colspan="3" class="small text-uppercase">Dokumenty instytucji (wspólne dla wszystkich usług)</th></tr>'
                . '<tr><td>' . msuesReqStateIcon('missing') . '</td><td><div class="fw-semibold small">Brakuje ' . count($orgOpen) . ' z ' . count(MsuesRequirements::ORG_DOCS) . '</div>'
                . '<div class="small text-body-secondary">' . h(implode('; ', array_column($orgOpen, 'label'))) . '</div></td>'
                . '<td class="text-end text-nowrap"><a class="btn btn-sm btn-warning" href="msues.php?_route=' . rawurlencode('/instytucja') . '">Uzupełnij raz dla wszystkich</a></td></tr>';
        }
        return $o === '' ? '' : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>' . $o . '</tbody></table></div>';
    }

    /** Pasek: ile wymagań spełnionych. */
    function msuesReqSummary(array $e): string
    {
        $pct = $e['total'] ? (int)floor(100 * $e['ok'] / $e['total']) : 0;
        $c = $e['missing'] ? ($pct >= 50 ? 'bg-warning' : 'bg-danger') : 'bg-success';
        return '<div class="progress" style="height:1rem;min-width:140px" role="progressbar" aria-label="Spełnione wymagania MSUES" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
             . '<div class="progress-bar ' . $c . '" style="width:' . max(4, $pct) . '%">' . (int)$e['ok'] . '/' . (int)$e['total'] . '</div></div>';
    }
}
