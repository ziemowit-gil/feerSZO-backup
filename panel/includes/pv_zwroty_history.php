<?php
/**
 * panel/includes/pv_zwroty_history.php — „Historia wniosków o zwrot kosztów" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Filtr po statusie (chipy `.pv-hub-chip`), jak [[pv_cert_history.php]].
 * Progressive enhancement: pełna lista renderowana serwerowo (działa bez JS / przy awarii CDN).
 * Status-map wspólny dla fallbacku i Reacta → brak migotania przy hydratacji.
 *
 * Wymaga w zasięgu: $_pv_zwroty (znormalizowana tablica, patrz panel/zwroty.php), h().
 */
$_pv_zwroty = $_pv_zwroty ?? [];
if (!$_pv_zwroty) return;

// status => [label, kolor-badge, kolor-ikony, ikona]
$_zwr_st = [
    'oczekuje'     => ['Oczekuje',                'secondary', 'warning', 'bi-hourglass-split'],
    'weryfikacja'  => ['Weryfikacja merytoryczna', 'info',      'info',    'bi-search'],
    'zatwierdzony' => ['Zatwierdzony',            'success',   'success', 'bi-check-circle'],
    'zatwierdzone' => ['Zatwierdzone',            'success',   'success', 'bi-check-circle'],
    'do_wyplaty'   => ['Do wypłaty',              'primary',   'primary', 'bi-cash'],
    'wyplacono'    => ['Wypłacono',               'dark',      'success', 'bi-cash-stack'],
    'odrzucony'    => ['Odrzucony',               'danger',    'danger',  'bi-x-circle'],
];
$_zwr_st_js = [];
foreach ($_zwr_st as $k => $v) $_zwr_st_js[$k] = ['label' => $v[0], 'badge' => $v[1], 'color' => $v[2], 'icon' => $v[3]];
?>
<div class="vol-activity mb-4"
     id="pvZwrotyHistory"
     data-zwroty='<?= h(json_encode($_pv_zwroty, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
     data-statuses='<?= h(json_encode($_zwr_st_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="vol-activity-header">
    <span class="vol-activity-title"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia wniosków</span>
    <span class="badge bg-secondary"><?= count($_pv_zwroty) ?></span>
  </div>
  <?php foreach ($_pv_zwroty as $w):
    $m = $_zwr_st[$w['status']] ?? [$w['status'], 'secondary', 'secondary', 'bi-circle'];
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $m[2] ?> bg-opacity-15 text-<?= $m[2] ?>">
      <i class="bi <?= $m[3] ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold <?= $w['status'] === 'odrzucony' ? 'text-decoration-line-through' : '' ?>" style="font-size:.85rem"><?= h($w['tytul']) ?></span>
        <span class="badge bg-<?= $m[1] ?>"><?= h($m[0]) ?></span>
      </div>
      <div class="text-muted" style="font-size:.78rem">
        <?= h($w['nr']) ?> · <strong><?= h($w['kwota']) ?> PLN</strong><?= $w['data_wydatku'] ? ' · ' . h($w['data_wydatku']) : '' ?>
      </div>
      <?php if ($w['odrzucenie_powod']): ?>
      <div class="text-danger" style="font-size:.78rem">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h($w['odrzucenie_powod']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= h($w['created']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvZwrotyHistory');
  if (!mount) return;
  var rows, ST;
  try { rows = JSON.parse(mount.getAttribute('data-zwroty') || '[]'); ST = JSON.parse(mount.getAttribute('data-statuses') || '{}'); }
  catch (e) { return; }
  if (!Array.isArray(rows) || !rows.length) return;

  var useState = React.useState, useMemo = React.useMemo;
  function meta(s){ return ST[s] || {label:s, badge:'secondary', color:'secondary', icon:'bi-circle'}; }
  function pluralWniosek(n){ return n===1 ? ' wniosek' : (n>=2 && n<=4 ? ' wnioski' : ' wniosków'); }

  function History(){
    var fs = useState('all'), filter = fs[0], setFilter = fs[1];

    var present = useMemo(function(){
      var seen = {}; var order = ['oczekuje','weryfikacja','zatwierdzony','zatwierdzone','do_wyplaty','wyplacono','odrzucony'];
      rows.forEach(function(r){ seen[r.status] = true; });
      return order.filter(function(s){ return seen[s]; });
    }, []);

    var shown = useMemo(function(){
      return filter === 'all' ? rows : rows.filter(function(r){ return r.status === filter; });
    }, [filter]);

    function Row(p){
      var w = p.w, m = meta(w.status);
      return html`
        <div class="vol-activity-row">
          <div class=${'vol-activity-icon bg-' + m.color + ' bg-opacity-15 text-' + m.color}>
            <i class=${'bi ' + m.icon} aria-hidden="true"></i>
          </div>
          <div class="flex-grow-1" style=${{minWidth:0}}>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class=${'fw-semibold' + (w.status === 'odrzucony' ? ' text-decoration-line-through' : '')} style=${{fontSize:'.85rem'}}>${w.tytul}</span>
              <span class=${'badge bg-' + m.badge}>${m.label}</span>
            </div>
            <div class="text-muted" style=${{fontSize:'.78rem'}}>
              ${w.nr} · ${html`<strong>${w.kwota + ' PLN'}</strong>`}${w.data_wydatku ? ' · ' + w.data_wydatku : ''}
            </div>
            ${w.odrzucenie_powod ? html`<div class="text-danger" style=${{fontSize:'.78rem'}}><i class="bi bi-x-circle me-1" aria-hidden="true"></i>${w.odrzucenie_powod}</div>` : null}
          </div>
          <div class="text-muted text-nowrap" style=${{fontSize:'.77rem'}}>${w.created}</div>
        </div>`;
    }

    return html`
      <div>
        <div class="vol-activity-header">
          <span class="vol-activity-title"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia wniosków</span>
          <span class="badge bg-secondary">${rows.length}</span>
        </div>
        ${present.length > 1 ? html`
          <div role="group" aria-label="Filtruj po statusie" style=${{display:'flex',gap:'.35rem',flexWrap:'wrap',padding:'.6rem 1rem 0'}}>
            <button type="button" class="pv-hub-chip" aria-pressed=${filter==='all'} onClick=${function(){ setFilter('all'); }}>Wszystkie</button>
            ${present.map(function(s){ var m = meta(s); return html`
              <button type="button" key=${s} class="pv-hub-chip" aria-pressed=${filter===s} onClick=${function(){ setFilter(s); }}>${m.label}</button>`; })}
          </div>` : null}
        <div aria-live="polite" class="visually-hidden">${'Pokazano ' + shown.length + pluralWniosek(shown.length)}</div>
        <div>${shown.map(function(w, i){ return html`<${Row} key=${i} w=${w} />`; })}</div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${History} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
