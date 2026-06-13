<?php
/**
 * panel/includes/pv_tasks_panel.php — Panel „Moje zadania" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Optymistyczne UI dla akcji „Weź"/„Ukończ"
 * (reużywa endpointów /tasks/api/claim.php, /tasks/api/task.php) oraz podgląd
 * szczegółów w Bootstrapowym offcanvas (/tasks/detail.php).
 *
 * Progressive enhancement: fallback serwerowy = statyczna lista z linkami do
 * giełdy zadań (działa bez JS / przy awarii CDN).
 *
 * Wymaga w zasięgu: $_pv_tasks_mine, $_pv_tasks_open (array), $_open_tasks_total,
 *   $_task_inbox_unread, $csrf_panel, APP_URL, h().
 */
$_pv_tasks_mine = $_pv_tasks_mine ?? [];
$_pv_tasks_open = $_pv_tasks_open ?? [];
$_jt = fn($v) => h(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?>
<div id="panel-tasks-card"
     data-base="<?= h(rtrim(APP_URL, '/')) ?>"
     data-csrf="<?= h($csrf_panel) ?>"
     data-mine='<?= $_jt($_pv_tasks_mine) ?>'
     data-open='<?= $_jt($_pv_tasks_open) ?>'
     data-open-total="<?= (int)$_open_tasks_total ?>"
     data-inbox-unread="<?= (int)$_task_inbox_unread ?>">
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 px-3">
    <i class="bi bi-table text-success" aria-hidden="true"></i>
    <h2 class="h6 fw-bold mb-0 flex-grow-1">Moje zadania</h2>
    <?php if ($_task_inbox_unread > 0): ?>
    <a href="<?= APP_URL ?>/tasks/inbox.php" class="badge bg-primary text-decoration-none"
       aria-label="<?= (int)$_task_inbox_unread ?> nieprzeczytanych wiadomości">
      <i class="bi bi-chat me-1" aria-hidden="true"></i><?= (int)$_task_inbox_unread ?> nowych
    </a>
    <?php endif; ?>
    <?php if ($_open_tasks_total > 0): ?>
    <span class="badge" style="background:#dcfce7;color:#15803d;font-size:.72rem"><?= (int)$_open_tasks_total ?> dostępnych</span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-outline-success ms-1" aria-label="Otwórz giełdę zadań">
      <i class="bi bi-grid-3x2-gap me-1" aria-hidden="true"></i>Zadania
    </a>
  </div>
  <div class="card-body p-3">
    <?php if (!$_pv_tasks_mine && !$_pv_tasks_open): ?>
    <div class="text-center py-3 text-muted">
      <i class="bi bi-inbox d-block mb-2" style="font-size:1.8rem;opacity:.25" aria-hidden="true"></i>
      <p class="small mb-2">Nie masz przypisanych zadań.</p>
      <a href="<?= APP_URL ?>/tasks/index.php?status=open" class="btn btn-sm btn-outline-success">
        <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Przeglądaj dostępne zadania
      </a>
    </div>
    <?php else: ?>
    <ul class="list-unstyled mb-0" role="list" aria-label="Moje zadania">
      <?php foreach (array_merge($_pv_tasks_mine, $_pv_tasks_open) as $t): ?>
      <li class="py-2 border-bottom" style="font-size:.86rem">
        <a href="<?= APP_URL ?>/tasks/index.php" class="fw-semibold text-decoration-none text-dark"><?= h($t['title']) ?></a>
        <div class="text-muted" style="font-size:.73rem"><?= h($t['ws_name'] ?? '') ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  </div>
</div>

<!-- SR announce -->
<div id="pv-tasks-sr" aria-live="polite" aria-atomic="true" class="visually-hidden"></div>

<!-- Offcanvas szczegółów zadania -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="panelTaskOffcanvas"
     role="dialog" aria-labelledby="panelTaskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header border-bottom py-2">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="panelTaskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-success" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto" id="panelTaskOffcanvasBody" aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('panel-tasks-card');
  if (!mount) return;

  var BASE = mount.getAttribute('data-base') || '';
  var CSRF = mount.getAttribute('data-csrf') || '';
  var MINE0, OPEN0;
  try { MINE0 = JSON.parse(mount.getAttribute('data-mine') || '[]'); OPEN0 = JSON.parse(mount.getAttribute('data-open') || '[]'); }
  catch (e) { return; }
  var INBOX = parseInt(mount.getAttribute('data-inbox-unread'), 10) || 0;
  var OPEN_TOTAL0 = parseInt(mount.getAttribute('data-open-total'), 10) || 0;

  var useState = React.useState, useRef = React.useRef;
  var PRI = {4:'#dc2626', 3:'#f59e0b', 2:'#3b82f6', 1:'#94a3b8'};

  function announce(msg){
    var el = document.getElementById('pv-tasks-sr');
    if (!el) return; el.textContent = ''; setTimeout(function(){ el.textContent = msg; }, 50);
  }
  function dueInfo(ymd){
    if (!ymd) return null;
    var d = new Date(ymd + 'T00:00:00');
    if (isNaN(d)) return null;
    var today = new Date(); today.setHours(0,0,0,0);
    var diff = Math.round((d - today) / 86400000);
    if (diff < 0)  return {txt:'Po terminie', color:'#dc2626', overdue:true};
    if (diff === 0) return {txt:'Dzisiaj', color:'#d97706'};
    if (diff === 1) return {txt:'Jutro', color:'#d97706'};
    var dd = ('0'+d.getDate()).slice(-2), mm = ('0'+(d.getMonth()+1)).slice(-2);
    return {txt: dd+'.'+mm, color: diff <= 3 ? '#d97706' : '#94a3b8'};
  }
  function openDetail(id){
    var oc = document.getElementById('panelTaskOffcanvas');
    var body = document.getElementById('panelTaskOffcanvasBody');
    if (!oc || !window.bootstrap) { window.location = BASE + '/tasks/index.php'; return; }
    body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(oc).show();
    fetch(BASE + '/tasks/detail.php?id=' + id)
      .then(function(r){ return r.text(); })
      .then(function(htmlStr){ body.innerHTML = ''; body.appendChild(document.createRange().createContextualFragment(htmlStr)); })
      .catch(function(){ body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>'; });
  }

  function Dot(p){ return html`<span style=${{width:'9px',height:'9px',borderRadius:'50%',background:(PRI[p.pri]||'#94a3b8'),flexShrink:0}} aria-hidden="true"></span>`; }

  function Panel(){
    var ms = useState(MINE0), mine = ms[0], setMine = ms[1];
    var os = useState(OPEN0), open = os[0], setOpen = os[1];
    var bs = useState({}),    busy = bs[0], setBusy = bs[1];   // id -> true podczas żądania

    function setBusyId(id, v){ setBusy(function(b){ var n = Object.assign({}, b); if (v) n[id]=true; else delete n[id]; return n; }); }

    function complete(t){
      setBusyId(t.id, true);
      var prev = mine;
      setMine(function(arr){ return arr.filter(function(x){ return x.id !== t.id; }); }); // optymistycznie
      announce('Zadanie „' + t.title + '" oznaczone jako ukończone.');
      fetch(BASE + '/tasks/api/task.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({_csrf:CSRF, action:'complete', id:t.id})})
        .then(function(r){ return r.json(); })
        .then(function(r){ if (!r.ok) throw new Error(r.error || 'err'); })
        .catch(function(){ setMine(prev); announce('Nie udało się ukończyć zadania.'); })
        .then(function(){ setBusyId(t.id, false); });
    }
    function claim(t){
      setBusyId(t.id, true);
      var prevO = open, prevM = mine;
      setOpen(function(arr){ return arr.filter(function(x){ return x.id !== t.id; }); }); // optymistycznie
      setMine(function(arr){ return arr.concat([t]); });
      announce('Wzięto zadanie „' + t.title + '".');
      fetch(BASE + '/tasks/api/claim.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({_csrf:CSRF, task_id:t.id, action:'add'})})
        .then(function(r){ return r.json(); })
        .then(function(r){ if (!r.ok) throw new Error(r.error || 'err'); })
        .catch(function(){ setOpen(prevO); setMine(prevM); announce('Nie udało się wziąć zadania.'); })
        .then(function(){ setBusyId(t.id, false); });
    }

    function MineRow(p){
      var t = p.t, di = dueInfo(t.due_date);
      return html`
        <li role="listitem" style=${{display:'flex',alignItems:'center',gap:'.65rem',padding:'.55rem 0',borderBottom:'1px solid #f8fafc'}}>
          <${Dot} pri=${t.priority} />
          <div style=${{flex:1,minWidth:0}}>
            <button type="button" onClick=${function(){ openDetail(t.id); }}
                    style=${{all:'unset',cursor:'pointer',fontSize:'.86rem',fontWeight:600,color:'#0f172a',display:'block',whiteSpace:'nowrap',overflow:'hidden',textOverflow:'ellipsis'}}
                    aria-label=${'Otwórz szczegóły: ' + t.title}>${t.title}</button>
            <div style=${{fontSize:'.72rem',color:'#94a3b8',display:'flex',alignItems:'center',gap:'.35rem'}}>
              <span style=${{width:'6px',height:'6px',borderRadius:'50%',background:(t.ws_color||'#cbd5e1')}} aria-hidden="true"></span>
              ${(t.ws_name||'') + (t.list_name ? ' › ' + t.list_name : '')}
            </div>
          </div>
          ${di ? html`<span style=${{fontSize:'.72rem',fontWeight:600,whiteSpace:'nowrap',color:di.color}}>${di.overdue ? html`<i class="bi bi-alarm" aria-hidden="true"></i> ` : null}${di.txt}</span>` : null}
          <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                  disabled=${!!busy[t.id]} onClick=${function(){ complete(t); }}
                  style=${{fontSize:'.72rem',whiteSpace:'nowrap',flexShrink:0}}
                  aria-label=${'Oznacz jako ukończone: ' + t.title}>
            <i class="bi bi-check2" aria-hidden="true"></i>
          </button>
        </li>`;
    }
    function OpenRow(p){
      var t = p.t, di = dueInfo(t.due_date);
      return html`
        <li role="listitem" style=${{display:'flex',alignItems:'center',gap:'.65rem',padding:'.5rem .5rem',borderBottom:'1px solid #f8fafc',background:'#fafffe',borderRadius:'6px'}}>
          <${Dot} pri=${t.priority} />
          <div style=${{flex:1,minWidth:0}}>
            <div style=${{fontSize:'.85rem',fontWeight:500,color:'#0f172a',whiteSpace:'nowrap',overflow:'hidden',textOverflow:'ellipsis'}}>${t.title}</div>
            <div style=${{fontSize:'.71rem',color:'#94a3b8'}}>
              <span style=${{width:'6px',height:'6px',borderRadius:'50%',background:(t.ws_color||'#cbd5e1'),display:'inline-block',marginRight:'.2rem'}} aria-hidden="true"></span>
              ${t.ws_name||''}${di ? html` · ${di.txt}` : null}
            </div>
          </div>
          <button type="button" class="btn btn-sm py-0 px-2" disabled=${!!busy[t.id]}
                  onClick=${function(){ claim(t); }}
                  style=${{background:'#059669',color:'#fff',border:'none',fontSize:'.74rem',whiteSpace:'nowrap',flexShrink:0}}
                  aria-label=${'Weź zadanie: ' + t.title}>
            <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Weź
          </button>
        </li>`;
    }

    var empty = !mine.length && !open.length;
    return html`
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 px-3">
          <i class="bi bi-table text-success" aria-hidden="true"></i>
          <h2 class="h6 fw-bold mb-0 flex-grow-1">Moje zadania${mine.length ? ' (' + mine.length + ')' : ''}</h2>
          ${INBOX > 0 ? html`<a href=${BASE + '/tasks/inbox.php'} class="badge bg-primary text-decoration-none" aria-label=${INBOX + ' nieprzeczytanych wiadomości'}><i class="bi bi-chat me-1" aria-hidden="true"></i>${INBOX} nowych</a>` : null}
          ${open.length ? html`<span class="badge" style=${{background:'#dcfce7',color:'#15803d',fontSize:'.72rem'}}>${open.length} dostępnych</span>` : null}
          <a href=${BASE + '/tasks/index.php'} class="btn btn-sm btn-outline-success ms-1" aria-label="Otwórz giełdę zadań"><i class="bi bi-grid-3x2-gap me-1" aria-hidden="true"></i>Zadania</a>
        </div>
        <div class="card-body p-3">
          ${empty ? html`
            <div class="text-center py-3 text-muted">
              <i class="bi bi-inbox d-block mb-2" style=${{fontSize:'1.8rem',opacity:.25}} aria-hidden="true"></i>
              <p class="small mb-2">Brak zadań — wszystko ogarnięte! 🎉</p>
              <a href=${BASE + '/tasks/index.php?status=open'} class="btn btn-sm btn-outline-success"><i class="bi bi-hand-index me-1" aria-hidden="true"></i>Przeglądaj dostępne zadania</a>
            </div>` : html`
            <div>
              ${mine.length ? html`
                <p class="text-muted" style=${{fontSize:'.72rem',fontWeight:700,textTransform:'uppercase',letterSpacing:'.07em',marginBottom:'.3rem'}}>Przypisane do mnie</p>
                <ul class="list-unstyled mb-2" role="list" aria-label="Moje zadania">${mine.map(function(t){ return html`<${MineRow} key=${'m'+t.id} t=${t} />`; })}</ul>
              ` : null}
              ${open.length ? html`
                <p class="text-muted" style=${{fontSize:'.72rem',fontWeight:700,textTransform:'uppercase',letterSpacing:'.07em',margin:'.5rem 0 .3rem'}}>Dostępne — możesz wziąć</p>
                <ul class="list-unstyled mb-0" role="list" aria-label="Dostępne zadania do wzięcia">${open.map(function(t){ return html`<${OpenRow} key=${'o'+t.id} t=${t} />`; })}</ul>
              ` : null}
            </div>`}
        </div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${Panel} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
