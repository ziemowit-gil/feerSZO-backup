<?php
/**
 * contracts/includes/status_tiles.php — kafle-skróty statusów nad listą umów (szata Metro jak w powierzeniu).
 *
 * contract_status_tiles($TYPE, $TABLE, $aktualny_status) → HTML: „Wszystkie” + jeden kafel na każdy status,
 * który ma umowy (kolejność wg STATUS_LABELS), z licznikiem; klik = ?status=… (ten sam parametr co filtr listy).
 * Liczniki z uwzględnieniem ograniczeń dostępu (contract_access_where), bez pozostałych filtrów listy.
 */

function contract_status_tiles(string $type, string $table, string $current = ''): string {
    $where = function_exists('contract_access_where') ? contract_access_where($type) : '1=1';
    try {
        $rows = db_all("SELECT COALESCE(status,'') AS s, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY COALESCE(status,'')");
    } catch (\Throwable $e) { return ''; }
    $cnt = [];
    foreach ($rows as $r) $cnt[(string)$r['s']] = (int)$r['n'];
    $all = array_sum($cnt);

    $icons = ['projekt' => 'pencil-square', 'do podpisu' => 'pen', 'jednostronnie' => 'pen', 'w podpisie w instytucji' => 'building',
              'podpisana' => 'check2-square', 'w realizacji' => 'play-circle', 'zawieszona' => 'pause-circle',
              'do rozliczenia' => 'calculator', 'zakończona' => 'flag', 'rozwiązana' => 'x-octagon', 'anulowana' => 'x-circle',
              'obowiązująca' => 'shield-check', 'wygasła' => 'hourglass-bottom', 'zablokowana' => 'lock'];

    $h  = '<nav class="cst-tiles" aria-label="Szybkie skróty: statusy umów">';
    $h .= '<a href="?" class="cst-tile is-dark' . ($current === '' ? ' is-on' : '') . '"' . ($current === '' ? ' aria-current="page"' : '') . '>'
        . '<span class="cst-lbl"><i class="bi bi-collection" aria-hidden="true"></i>Wszystkie</span><span class="cst-val">' . $all . '</span></a>';
    foreach (STATUS_LABELS as $k => $s) {
        if ($k === 'aneks' || (empty($cnt[$k]) && $current !== $k)) continue;
        $on    = $current === $k;
        $color = STATUS_CUSTOM_COLORS[$s['class']] ?? ['primary' => '#0d6efd', 'secondary' => '#6c757d', 'success' => '#198754', 'info' => '#0aa2c0',
                    'warning' => '#b45309', 'danger' => '#dc3545'][$s['class']] ?? '#6c757d';
        $h .= '<a href="?status=' . urlencode($k) . '" class="cst-tile' . ($on ? ' is-on' : '') . '" style="--k:' . $color . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span class="cst-lbl"><i class="bi bi-' . ($icons[$k] ?? 'circle') . '" aria-hidden="true"></i>' . htmlspecialchars($s['label']) . '</span>'
            . '<span class="cst-val">' . (int)($cnt[$k] ?? 0) . '</span></a>';
    }
    $h .= '</nav>';

    static $css = false;
    if (!$css) {
        $css = true;
        $h = '<style>
.cst-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.4rem;margin-bottom:1rem}
.cst-tile{--k:#6c757d;display:flex;flex-direction:column;justify-content:space-between;min-height:84px;padding:.6rem .8rem;background:var(--k);color:#fff!important;text-decoration:none;outline:3px solid transparent;outline-offset:-3px}
.cst-tile.is-dark{--k:#1f2937}
.cst-tile:hover{filter:brightness(1.1)}
.cst-tile:focus-visible{outline-color:#111;outline-offset:2px}
.cst-tile.is-on{outline-color:#fbbf24}
.cst-lbl{font-size:.8rem;font-weight:600;display:flex;gap:.4rem;align-items:center}
.cst-val{font-size:1.6rem;font-weight:300;line-height:1}
</style>' . $h;
    }
    return $h;
}
