<?php
/**
 * panel/includes/pv_cert_history.php — „Historia wniosków o zaświadczenie" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Dodaje filtr po statusie (chipy `.pv-hub-chip`) do listy,
 * dokładnie jak [[pv_apps_activity.php]]. Progressive enhancement: pełna lista
 * renderowana serwerowo (działa bez JS / przy awarii CDN).
 *
 * Wymaga w zasięgu: $_pv_certs (znormalizowana tablica, patrz panel/certificates.php),
 *   APP_URL, h().
 */
$_pv_certs = $_pv_certs ?? [];

// Status → etykieta + kolor (Bootstrap) + ikona. Spójne z CERTIFICATE_STATUSES.
$_cert_st = [
    'oczekuje'       => ['Oczekuje',            'warning', 'bi-clock-history'],
    'gotowe'         => ['Gotowe',              'info',    'bi-hourglass-split'],
    'esign_oczekuje' => ['Oczekuje na podpis',  'primary', 'bi-pen'],
    'wydane'         => ['Wydane',              'success', 'bi-award-fill'],
    'odrzucone'      => ['Odrzucone',           'danger',  'bi-x-circle'],
];
$_cert_st_js = [];
foreach ($_cert_st as $k => $v) $_cert_st_js[$k] = ['label' => $v[0], 'color' => $v[1], 'icon' => $v[2]];
?>
<div class="vol-detail-card"
     id="pvCertHistory"
     data-base="<?= h(rtrim(APP_URL, '/')) ?>"
     data-certs='<?= h(json_encode($_pv_certs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
     data-statuses='<?= h(json_encode($_cert_st_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="vol-detail-header">
    <i class="bi bi-list-check me-2" aria-hidden="true"></i>Historia wniosków
    <?php if ($_pv_certs): ?>
    <span class="badge bg-secondary ms-auto"><?= count($_pv_certs) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$_pv_certs): ?>
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-award" style="font-size:2.5rem;opacity:.2" aria-hidden="true"></i>
    <p class="mt-3 mb-0 small">Nie masz jeszcze żadnych wniosków o zaświadczenie.</p>
  </div>
  <?php else: ?>
  <?php foreach ($_pv_certs as $r):
    $m = $_cert_st[$r['status']] ?? [$r['status'], 'secondary', 'bi-dot'];
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
      <div class="text-muted" style="font-size:.78rem">Cel: <?= h($r['cel']) ?></div>
      <?php if ($r['rejection_note']): ?>
      <div class="text-danger" style="font-size:.78rem">
        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($r['rejection_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
      <span class="text-muted" style="font-size:.77rem"><?= h($r['created_pl']) ?></span>
      <?php if ($r['status'] === 'wydane'): ?>
      <a href="<?= APP_URL ?>/certificates/print.php?id=<?= (int)$r['id'] ?>"
         target="_blank" class="btn btn-sm btn-success py-0 px-2" title="Pobierz / drukuj PDF"
         aria-label="Pobierz lub drukuj PDF zaświadczenia">
        <i class="bi bi-printer" aria-hidden="true"></i>
      </a>
      <a href="<?= APP_URL ?>/certificates/download_docx.php?id=<?= (int)$r['id'] ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2" title="Pobierz DOCX (Word)"
         aria-label="Pobierz zaświadczenie w formacie Word">
        <i class="bi bi-file-earmark-word" aria-hidden="true"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvCertHistory');
  if (!mount) return;
  var certs, ST;
  try { certs = JSON.parse(mount.getAttribute('data-certs') || '[]'); ST = JSON.parse(mount.getAttribute('data-statuses') || '{}'); }
  catch (e) { return; }
  if (!Array.isArray(certs) || !certs.length) return;

  var BASE = mount.getAttribute('data-base') || '';
  var useState = React.useState, useMemo = React.useMemo;
  function meta(s){ return ST[s] || {label:s, color:'secondary', icon:'bi-dot'}; }
  function pluralWniosek(n){ return n===1 ? ' wniosek' : (n>=2 && n<=4 ? ' wnioski' : ' wniosków'); }

  function History(){
    var fs = useState('all'), filter = fs[0], setFilter = fs[1];

    var present = useMemo(function(){
      var seen = {}; var order = ['oczekuje','esign_oczekuje','gotowe','wydane','odrzucone'];
      certs.forEach(function(c){ seen[c.status] = true; });
      return order.filter(function(s){ return seen[s]; });
    }, []);

    var shown = useMemo(function(){
      return filter === 'all' ? certs : certs.filter(function(c){ return c.status === filter; });
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
            <div class="text-muted" style=${{fontSize:'.78rem'}}>Cel: ${r.cel}</div>
            ${r.rejection_note ? html`<div class="text-danger" style=${{fontSize:'.78rem'}}><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>${r.rejection_note}</div>` : null}
          </div>
          <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
            <span class="text-muted" style=${{fontSize:'.77rem'}}>${r.created_pl}</span>
            ${r.status === 'wydane' ? html`
              <a href=${BASE + '/certificates/print.php?id=' + r.id} target="_blank"
                 class="btn btn-sm btn-success py-0 px-2" title="Pobierz / drukuj PDF"
                 aria-label="Pobierz lub drukuj PDF zaświadczenia"><i class="bi bi-printer" aria-hidden="true"></i></a>
              <a href=${BASE + '/certificates/download_docx.php?id=' + r.id}
                 class="btn btn-sm btn-outline-secondary py-0 px-2" title="Pobierz DOCX (Word)"
                 aria-label="Pobierz zaświadczenie w formacie Word"><i class="bi bi-file-earmark-word" aria-hidden="true"></i></a>` : null}
          </div>
        </div>`;
    }

    return html`
      <div>
        <div class="vol-detail-header">
          <i class="bi bi-list-check me-2" aria-hidden="true"></i>Historia wniosków
          <span class="badge bg-secondary ms-auto">${certs.length}</span>
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
