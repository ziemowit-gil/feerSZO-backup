<?php
/**
 * modules/pivot_table/lib/pivotTable.php — wspólny widżet „Tabela przestawna” dla raportów (bez zależności, czysty JS po stronie przeglądarki).
 *
 * pivotTableWidget($id, $dims, $measures, $facts, $opt): drukuje sekcję z wyborem wierszy, kolumn, miary i funkcji (suma / liczba / średnia /
 * min / max), tabelę z sumami wierszy i kolumn oraz eksport CSV (średnik + BOM — Excel PL) i druk. Dane to płaska lista FAKTÓW
 * (wiersz = np. jedna lekcja albo jeden uczestnik), przetwarzana w przeglądarce — przełączanie układu nie odpytuje serwera.
 *
 *   $dims     [['k' => 'grupa', 'l' => 'Grupa'], …]                         — wymiary (pola tekstowe faktu)
 *   $measures [['k' => 'godz', 'l' => 'Godziny', 'd' => 1], …]              — miary (pola liczbowe; d = miejsca po przecinku)
 *   $facts    [['grupa' => 'A', 'godz' => 1.0, …], …]
 *   $opt      ['row' => 'grupa', 'col' => 'status'|'', 'measure' => 'godz', 'agg' => 'sum', 'title' => '…', 'file' => 'nazwa-pliku']
 * Dostępność: etykiety pól, podpis i nagłówki tabeli (scope), komunikat aria-live o wyniku. Wygląd: klasy .rg-table (styl Metro w ti_metro_dyd.css).
 */
function pivotTableWidget(string $id, array $dims, array $measures, array $facts, array $opt = []): void
{
    $id = preg_replace('/[^a-z0-9_-]/i', '', $id) ?: 'pivot';
    $cfg = [
        'dims' => array_values($dims), 'measures' => array_values($measures), 'facts' => array_values($facts),
        'def'  => ['row' => $opt['row'] ?? ($dims[0]['k'] ?? ''), 'col' => $opt['col'] ?? '', 'measure' => $opt['measure'] ?? ($measures[0]['k'] ?? ''), 'agg' => $opt['agg'] ?? 'sum'],
        'file' => (string)($opt['file'] ?? 'tabela-przestawna'),
    ];
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $sel = function (string $name, string $label, array $items, string $cur, bool $none = false) use ($id, $h): void { ?>
      <div class="pv-f">
        <label class="form-label small mb-0" for="<?= $h($id . '-' . $name) ?>"><?= $h($label) ?></label>
        <select class="form-select form-select-sm" id="<?= $h($id . '-' . $name) ?>" data-pv="<?= $h($name) ?>">
          <?php if ($none): ?><option value="">— brak —</option><?php endif; ?>
          <?php foreach ($items as $it): ?><option value="<?= $h($it['k']) ?>" <?= $it['k'] === $cur ? 'selected' : '' ?>><?= $h($it['l']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php };
    $aggs = [['k' => 'sum', 'l' => 'Suma'], ['k' => 'count', 'l' => 'Liczba wierszy'], ['k' => 'avg', 'l' => 'Średnia'], ['k' => 'min', 'l' => 'Minimum'], ['k' => 'max', 'l' => 'Maksimum']];
    ?>
<section class="pv" id="<?= $h($id) ?>" aria-labelledby="<?= $h($id) ?>-t" data-cfg='<?= $json ?>'>
  <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
    <h2 class="rg-sec mb-0" id="<?= $h($id) ?>-t"><i class="bi bi-table me-1" aria-hidden="true"></i><?= $h($opt['title'] ?? 'Tabela przestawna') ?></h2>
    <span class="small text-body-secondary">wybierz wiersze, kolumny i miarę — tabela przelicza się od razu</span>
    <div class="ms-auto d-flex gap-1 pv-actions">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-pv-act="csv"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV tabeli</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-pv-act="print"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</button>
    </div>
  </div>
  <div class="pv-controls d-flex flex-wrap align-items-end gap-2 mb-2">
    <?php $sel('row', 'Wiersze', $dims, $cfg['def']['row']); ?>
    <?php $sel('col', 'Kolumny', $dims, $cfg['def']['col'], true); ?>
    <?php $sel('measure', 'Miara', $measures, $cfg['def']['measure']); ?>
    <?php $sel('agg', 'Funkcja', $aggs, $cfg['def']['agg']); ?>
  </div>
  <p class="visually-hidden" role="status" aria-live="polite" data-pv-status></p>
  <div class="table-responsive"><table class="table table-sm align-middle rg-table pv-table"><caption class="visually-hidden" data-pv-cap></caption><thead></thead><tbody></tbody></table></div>
</section>
<style>
  .pv-f { min-width: 11rem; } .pv-table th, .pv-table td { white-space: nowrap; } .pv-table .num { text-align: right; font-variant-numeric: tabular-nums; }
  .pv-table tr.pv-tot > *, .pv-table td.pv-tot, .pv-table th.pv-tot { font-weight: 700; } .pv-table tr.pv-tot > * { border-top: 2px solid #1d1d1d; }
  .pv-table td.pv-tot { border-left: 2px solid #1d1d1d; }
  @media print { .pv-controls, .pv-actions { display: none !important; } }
</style>
<script>
(function () {
  var root = document.getElementById(<?= json_encode($id) ?>); if (!root || root.dataset.pvInit) return; root.dataset.pvInit = '1';
  var cfg = JSON.parse(root.getAttribute('data-cfg')), KEY = 'pv:' + root.id, st = Object.assign({}, cfg.def);
  try { var sv = JSON.parse(localStorage.getItem(KEY) || 'null'); if (sv) { ['row','col','measure','agg'].forEach(function (k) { if (sv[k] !== undefined && root.querySelector('[data-pv="' + k + '"] option[value="' + sv[k] + '"]')) st[k] = sv[k]; }); } } catch (e) {}
  var coll = new Intl.Collator('pl', { numeric: true, sensitivity: 'base' });
  function dimLabel(k) { for (var i = 0; i < cfg.dims.length; i++) if (cfg.dims[i].k === k) return cfg.dims[i].l; return k; }
  function measure(k) { for (var i = 0; i < cfg.measures.length; i++) if (cfg.measures[i].k === k) return cfg.measures[i]; return cfg.measures[0]; }
  function fmt(v, d) { return v === null || v === undefined || isNaN(v) ? '—' : new Intl.NumberFormat('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }).format(v); }
  function acc() { return { n: 0, sum: 0, min: null, max: null }; }
  function add(a, v) { a.n++; if (typeof v === 'number' && !isNaN(v)) { a.sum += v; a.min = a.min === null ? v : Math.min(a.min, v); a.max = a.max === null ? v : Math.max(a.max, v); } }
  function val(a, agg) { if (!a || a.n === 0) return null; return agg === 'count' ? a.n : agg === 'avg' ? a.sum / a.n : agg === 'min' ? a.min : agg === 'max' ? a.max : a.sum; }
  function build() {
    var rk = st.row, ck = st.col && st.col !== st.row ? st.col : '', m = measure(st.measure), agg = st.agg, d = agg === 'count' ? 0 : (m.d || 0);
    var R = {}, C = {}, cell = {}, rowT = {}, colT = {}, all = acc();
    cfg.facts.forEach(function (f) {
      var r = String(f[rk] === undefined || f[rk] === null || f[rk] === '' ? '—' : f[rk]), c = ck ? String(f[ck] === undefined || f[ck] === null || f[ck] === '' ? '—' : f[ck]) : '';
      var v = f[m.k]; R[r] = 1; if (ck) C[c] = 1;
      var key = r + '\u0001' + c; add(cell[key] = cell[key] || acc(), v); add(rowT[r] = rowT[r] || acc(), v); if (ck) add(colT[c] = colT[c] || acc(), v); add(all, v);
    });
    var rows = Object.keys(R).sort(coll.compare), cols = ck ? Object.keys(C).sort(coll.compare) : [''];
    return { rk: rk, ck: ck, m: m, agg: agg, d: d, rows: rows, cols: cols, cell: cell, rowT: rowT, colT: colT, all: all };
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function matrix(p) {   // tablica wartości (tekst) do CSV i rysowania
    var head = [dimLabel(p.rk) + (p.ck ? ' \\ ' + dimLabel(p.ck) : '')].concat(p.ck ? p.cols : [p.m.l + ' (' + aggName(p.agg) + ')']); if (p.ck) head.push('Razem');
    var body = p.rows.map(function (r) {
      var line = [r]; p.cols.forEach(function (c) { line.push(fmt(val(p.cell[r + '\u0001' + c], p.agg), p.d)); });
      if (p.ck) line.push(fmt(val(p.rowT[r], p.agg), p.d)); return line;
    });
    var tot = ['Razem']; p.cols.forEach(function (c) { tot.push(fmt(val(p.ck ? p.colT[c] : p.all, p.agg), p.d)); }); if (p.ck) tot.push(fmt(val(p.all, p.agg), p.d));
    return { head: head, body: body, tot: tot };
  }
  function aggName(a) { return { sum: 'suma', count: 'liczba wierszy', avg: 'średnia', min: 'minimum', max: 'maksimum' }[a] || a; }
  function render() {
    var p = build(), mx = matrix(p), t = root.querySelector('table'), thead = t.tHead, tb = t.tBodies[0];
    thead.innerHTML = '<tr>' + mx.head.map(function (h, i) { return '<th scope="col" class="' + (i ? 'num' : '') + (p.ck && i === mx.head.length - 1 ? ' pv-tot' : '') + '">' + esc(h) + '</th>'; }).join('') + '</tr>';
    tb.innerHTML = mx.body.map(function (l) { return '<tr>' + l.map(function (c, i) { return i === 0 ? '<th scope="row" class="fw-normal">' + esc(c) + '</th>' : '<td class="num' + (p.ck && i === l.length - 1 ? ' pv-tot' : '') + '">' + esc(c) + '</td>'; }).join('') + '</tr>'; }).join('')
      + '<tr class="pv-tot">' + mx.tot.map(function (c, i) { return i === 0 ? '<th scope="row">' + esc(c) + '</th>' : '<td class="num' + (p.ck && i === mx.tot.length - 1 ? ' pv-tot' : '') + '">' + esc(c) + '</td>'; }).join('') + '</tr>';
    var desc = p.m.l + ' (' + aggName(p.agg) + ') wg: ' + dimLabel(p.rk) + (p.ck ? ' i ' + dimLabel(p.ck) : '');
    t.querySelector('[data-pv-cap]').textContent = 'Tabela przestawna — ' + desc;
    root.querySelector('[data-pv-status]').textContent = 'Tabela przeliczona: ' + desc + '; wierszy ' + p.rows.length + (p.ck ? ', kolumn ' + p.cols.length : '') + '; razem ' + fmt(val(p.all, p.agg), p.d) + '.';
    root.__pv = mx;
  }
  root.querySelectorAll('[data-pv]').forEach(function (s) { s.addEventListener('change', function () { st[s.getAttribute('data-pv')] = s.value; try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} render(); }); });
  root.querySelectorAll('[data-pv-act]').forEach(function (b) { b.addEventListener('click', function () {
    if (b.getAttribute('data-pv-act') === 'print') { window.print(); return; }
    var mx = root.__pv, rows = [mx.head].concat(mx.body, [mx.tot]);
    var csv = '﻿' + rows.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';'); }).join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = cfg.file + '.csv'; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
  }); });
  // zgodność stanu z listami po odczycie z localStorage
  ['row', 'col', 'measure', 'agg'].forEach(function (k) { var s = root.querySelector('[data-pv="' + k + '"]'); if (s) s.value = st[k]; });
  render();
})();
</script>
<?php
}
