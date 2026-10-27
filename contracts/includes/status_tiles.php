<?php
/**
 * contracts/includes/status_tiles.php — kafle-skróty statusów nad listą umów (szata Metro jak w powierzeniu).
 *
 * contract_status_tiles($TYPE, $TABLE, $aktualny_status) → HTML: „Wszystkie” + jeden kafel na każdy status,
 * który ma umowy (kolejność, kolor i ikona ze słownika umowy_statusy), z licznikiem; klik = ?status=… (ten sam parametr co filtr listy).
 * Liczniki z uwzględnieniem ograniczeń dostępu (contract_access_where), bez pozostałych filtrów listy.
 */

function contract_status_tiles(string $type, string $table, string $current = ''): string {
    $where = function_exists('contract_access_where') ? contract_access_where($type) : '1=1';
    try {
        $rows = db_all("SELECT COALESCE(status,'') AS s, COUNT(*) AS n FROM {$table} WHERE {$where}" . contractHiddenSql($table) . " GROUP BY COALESCE(status,'')");
    } catch (\Throwable $e) { return ''; }
    $cnt = [];
    foreach ($rows as $r) $cnt[(string)$r['s']] = (int)$r['n'];
    $all = array_sum($cnt);

    $h  = '<nav class="cst-tiles" aria-label="Szybkie skróty: statusy umów">';
    $h .= '<a href="?" class="cst-tile is-dark' . ($current === '' ? ' is-on' : '') . '"' . ($current === '' ? ' aria-current="page"' : '') . '>'
        . '<span class="cst-lbl"><i class="bi bi-collection" aria-hidden="true"></i>Wszystkie</span><span class="cst-val">' . $all . '</span></a>';
    // kolejność i wygląd ze słownika umowy_statusy; wartości spoza słownika (stare dane) na końcu
    foreach (array_unique([...array_keys(contractStatuses()), ...array_keys($cnt)]) as $k) {
        $k = (string)$k;
        if ($k === '' || $k === 'aneks' || (empty($cnt[$k]) && $current !== $k)) continue;
        $s     = contractStatusCfg($k);
        $on    = $current === $k;
        $h .= '<a href="?status=' . urlencode($k) . '" class="cst-tile' . ($on ? ' is-on' : '') . '" style="--k:' . $s['color'] . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span class="cst-lbl"><i class="bi ' . $s['icon'] . '" aria-hidden="true"></i>' . htmlspecialchars($s['label']) . '</span>'
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
