<?php
/**
 * panel/includes/pv_apps_activity.php — „Ostatnie wnioski i pisma" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Dodaje filtr po statusie (chipy) do listy wniosków.
 * Progressive enhancement: pełna lista renderowana serwerowo (działa bez JS).
 *
 * Wymaga w zasięgu: $_pv_apps (array: tytul, type_label, type_icon, status,
 *   created_at, odpowiedz), APP_URL, h().
 */
$_pv_apps = $_pv_apps ?? [];
if (!$_pv_apps) return;

$_app_st = [
    'nowy'        => ['Nowy',        'var(--vol-color)', '#EFF6FF'],
    'w_trakcie'   => ['W trakcie',   '#D97706',          '#FEF3E2'],
    'rozpatrzony' => ['Rozpatrzony', '#16A34A',          '#F0FDF4'],
    'odrzucony'   => ['Odrzucony',   '#DC2626',          '#FEF2F2'],
];
$_app_st_js = [];
foreach ($_app_st as $k => $v) $_app_st_js[$k] = ['label'=>$v[0], 'color'=>$v[1], 'bg'=>$v[2]];
?>
<section class="vol-activity mb-3" aria-labelledby="pvp-activity-heading"
         id="pvAppsActivity"
         data-base="<?= h(rtrim(APP_URL, '/')) ?>"
         data-apps='<?= h(json_encode($_pv_apps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
         data-statuses='<?= h(json_encode($_app_st_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="vol-activity-header">
    <h2 id="pvp-activity-heading" class="vol-activity-title">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Ostatnie wnioski i pisma
    </h2>
    <a href="<?= APP_URL ?>/panel/apply.php" class="btn btn-sm py-0 px-2"
       style="background:var(--vol-color);color:#fff;font-size:.75rem;border-radius:5px" aria-label="Złóż nowy wniosek">
      <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowy
    </a>
  </div>
  <?php foreach ($_pv_apps as $app):
    $_c = $_app_st[$app['status']][1] ?? '#9CA3AF';
    $_b = $_app_st[$app['status']][2] ?? '#F3F4F6';
    $_l = $_app_st[$app['status']][0] ?? $app['status'];
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon" style="background:<?= $_b ?>;color:<?= $_c ?>">
      <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?>" aria-hidden="true"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($app['tytul']) ?></div>
      <div style="font-size:.73rem;color:#9CA3AF"><?= h($app['type_label'] ?? '') ?> · <?= h(substr((string)$app['created_at'],0,10)) ?></div>
      <?php if (!empty($app['odpowiedz']) && $app['status'] !== 'nowy'): ?>
      <div style="font-size:.78rem;color:#374151;margin-top:.15rem;font-style:italic"><?= h(mb_substr($app['odpowiedz'],0,80)) ?><?= mb_strlen($app['odpowiedz'])>80?'…':'' ?></div>
      <?php endif; ?>
    </div>
    <span style="display:inline-flex;align-items:center;padding:.15rem .55rem;border-radius:2rem;font-size:.72rem;font-weight:600;background:<?= $_b ?>;color:<?= $_c ?>;white-space:nowrap;flex-shrink:0"><?= h($_l) ?></span>
  </div>
  <?php endforeach; ?>
</section>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvAppsActivity');
  if (!mount) return;
  var apps, ST;
  try { apps = JSON.parse(mount.getAttribute('data-apps') || '[]'); ST = JSON.parse(mount.getAttribute('data-statuses') || '{}'); }
  catch (e) { return; }
  if (!Array.isArray(apps) || !apps.length) return;

  var BASE = mount.getAttribute('data-base') || '';
  var useState = React.useState, useMemo = React.useMemo;
  function meta(s){ return ST[s] || {label:s, color:'#9CA3AF', bg:'#F3F4F6'}; }

  function Activity(){
    var fs = useState('all'), filter = fs[0], setFilter = fs[1];

    // statusy obecne w danych — tylko one dostają chip
    var present = useMemo(function(){
      var seen = {}; var order = ['nowy','w_trakcie','rozpatrzony','odrzucony'];
      apps.forEach(function(a){ seen[a.status] = true; });
      return order.filter(function(s){ return seen[s]; });
    }, []);

    var shown = useMemo(function(){
      return filter === 'all' ? apps : apps.filter(function(a){ return a.status === filter; });
    }, [filter]);

    function Row(p){
      var a = p.a, m = meta(a.status);
      return html`
        <div class="vol-activity-row">
          <div class="vol-activity-icon" style=${{background:m.bg, color:m.color}}>
            <i class=${'bi ' + (a.type_icon || 'bi-file-text')} aria-hidden="true"></i>
          </div>
          <div style=${{flex:1, minWidth:0}}>
            <div style=${{fontWeight:600, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>${a.tytul}</div>
            <div style=${{fontSize:'.73rem', color:'#9CA3AF'}}>${(a.type_label||'')} · ${(a.created_at||'').slice(0,10)}</div>
            ${(a.odpowiedz && a.status !== 'nowy') ? html`<div style=${{fontSize:'.78rem',color:'#374151',marginTop:'.15rem',fontStyle:'italic'}}>${a.odpowiedz.slice(0,80)}${a.odpowiedz.length>80?'…':''}</div>` : null}
          </div>
          <span style=${{display:'inline-flex',alignItems:'center',padding:'.15rem .55rem',borderRadius:'2rem',fontSize:'.72rem',fontWeight:600,background:m.bg,color:m.color,whiteSpace:'nowrap',flexShrink:0}}>${m.label}</span>
        </div>`;
    }

    return html`
      <div>
        <div class="vol-activity-header">
          <h2 id="pvp-activity-heading" class="vol-activity-title">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Ostatnie wnioski i pisma
          </h2>
          <a href=${BASE + '/panel/apply.php'} class="btn btn-sm py-0 px-2"
             style=${{background:'var(--vol-color)',color:'#fff',fontSize:'.75rem',borderRadius:'5px'}} aria-label="Złóż nowy wniosek">
            <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowy
          </a>
        </div>
        ${present.length > 1 ? html`
          <div role="group" aria-label="Filtruj po statusie" style=${{display:'flex',gap:'.35rem',flexWrap:'wrap',padding:'.6rem 1rem 0'}}>
            <button type="button" class="pv-hub-chip" aria-pressed=${filter==='all'} onClick=${function(){ setFilter('all'); }}>Wszystkie</button>
            ${present.map(function(s){ var m = meta(s); return html`
              <button type="button" key=${s} class="pv-hub-chip" aria-pressed=${filter===s} onClick=${function(){ setFilter(s); }}>${m.label}</button>`; })}
          </div>` : null}
        <div aria-live="polite" class="visually-hidden">${'Pokazano ' + shown.length + (shown.length===1?' wniosek':(shown.length>=2&&shown.length<=4?' wnioski':' wniosków'))}</div>
        <div>${shown.map(function(a, i){ return html`<${Row} key=${i} a=${a} />`; })}</div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${Activity} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
