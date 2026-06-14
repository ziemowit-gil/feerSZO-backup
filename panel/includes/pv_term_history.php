<?php
/**
 * panel/includes/pv_term_history.php — „Moje wnioski o rozwiązanie umowy" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Filtr po statusie (chipy `.pv-hub-chip`), jak [[pv_cert_history.php]].
 * Progressive enhancement: pełna lista renderowana serwerowo (działa bez JS / przy awarii CDN).
 *
 * Wymaga w zasięgu: $_pv_terms (znormalizowana tablica, patrz panel/terminations.php), h().
 */
$_pv_terms = $_pv_terms ?? [];
if (!$_pv_terms) return;

$_term_st = [
    'oczekuje'      => ['Oczekuje',      'warning', 'bi-clock-history'],
    'zaakceptowany' => ['Zaakceptowany', 'success', 'bi-check-circle-fill'],
    'odrzucony'     => ['Odrzucony',     'danger',  'bi-x-circle-fill'],
];
$_term_st_js = [];
foreach ($_term_st as $k => $v) $_term_st_js[$k] = ['label' => $v[0], 'color' => $v[1], 'icon' => $v[2]];
?>
<div class="vol-detail-card"
     id="pvTermHistory"
     data-terms='<?= h(json_encode($_pv_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
     data-statuses='<?= h(json_encode($_term_st_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="vol-detail-header">
    <i class="bi bi-list-check me-2" aria-hidden="true"></i>Moje wnioski o rozwiązanie
    <span class="badge bg-secondary ms-auto"><?= count($_pv_terms) ?></span>
  </div>
  <?php foreach ($_pv_terms as $r):
    $m = $_term_st[$r['status']] ?? [$r['status'], 'secondary', 'bi-dot'];
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $m[1] ?> bg-opacity-15 text-<?= $m[1] ?>">
      <i class="bi <?= $m[2] ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold" style="font-size:.85rem"><?= h($r['type_label']) ?> · <?= h($r['nr']) ?></span>
        <span class="badge bg-<?= $m[1] ?>"><?= h($m[0]) ?></span>
      </div>
      <div class="text-muted" style="font-size:.78rem">Powód: <?= h($r['powod']) ?></div>
      <?php if ($r['decision_note']): ?>
      <div class="<?= $r['status'] === 'odrzucony' ? 'text-danger' : 'text-muted' ?>" style="font-size:.78rem">
        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($r['decision_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= h($r['created_pl']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvTermHistory');
  if (!mount) return;
  var terms, ST;
  try { terms = JSON.parse(mount.getAttribute('data-terms') || '[]'); ST = JSON.parse(mount.getAttribute('data-statuses') || '{}'); }
  catch (e) { return; }
  if (!Array.isArray(terms) || !terms.length) return;

  var useState = React.useState, useMemo = React.useMemo;
  function meta(s){ return ST[s] || {label:s, color:'secondary', icon:'bi-dot'}; }
  function pluralWniosek(n){ return n===1 ? ' wniosek' : (n>=2 && n<=4 ? ' wnioski' : ' wniosków'); }

  function History(){
    var fs = useState('all'), filter = fs[0], setFilter = fs[1];

    var present = useMemo(function(){
      var seen = {}; var order = ['oczekuje','zaakceptowany','odrzucony'];
      terms.forEach(function(t){ seen[t.status] = true; });
      return order.filter(function(s){ return seen[s]; });
    }, []);

    var shown = useMemo(function(){
      return filter === 'all' ? terms : terms.filter(function(t){ return t.status === filter; });
    }, [filter]);

    function Row(p){
      var r = p.r, m = meta(r.status);
      return html`
        <div class="vol-activity-row">
          <div class=${'vol-activity-icon bg-' + m.color + ' bg-opacity-15 text-' + m.color}>
            <i class=${'bi ' + m.icon} aria-hidden="true"></i>
          </div>
          <div class="flex-grow-1" style=${{minWidth:0}}>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-semibold" style=${{fontSize:'.85rem'}}>${r.type_label} · ${r.nr}</span>
              <span class=${'badge bg-' + m.color}>${m.label}</span>
            </div>
            <div class="text-muted" style=${{fontSize:'.78rem'}}>Powód: ${r.powod}</div>
            ${r.decision_note ? html`<div class=${r.status === 'odrzucony' ? 'text-danger' : 'text-muted'} style=${{fontSize:'.78rem'}}><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>${r.decision_note}</div>` : null}
          </div>
          <div class="text-muted text-nowrap" style=${{fontSize:'.77rem'}}>${r.created_pl}</div>
        </div>`;
    }

    return html`
      <div>
        <div class="vol-detail-header">
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Moje wnioski o rozwiązanie
          <span class="badge bg-secondary ms-auto">${terms.length}</span>
        </div>
        ${present.length > 1 ? html`
          <div role="group" aria-label="Filtruj po statusie" style=${{display:'flex',gap:'.35rem',flexWrap:'wrap',padding:'.6rem 1rem 0'}}>
            <button type="button" class="pv-hub-chip" aria-pressed=${filter==='all'} onClick=${function(){ setFilter('all'); }}>Wszystkie</button>
            ${present.map(function(s){ var m = meta(s); return html`
              <button type="button" key=${s} class="pv-hub-chip" aria-pressed=${filter===s} onClick=${function(){ setFilter(s); }}>${m.label}</button>`; })}
          </div>` : null}
        <div aria-live="polite" class="visually-hidden">${'Pokazano ' + shown.length + pluralWniosek(shown.length)}</div>
        <div>${shown.map(function(r, i){ return html`<${Row} key=${i} r=${r} />`; })}</div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${History} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
