<?php
/**
 * Edytor procesów (workflow BPM) per JRWA.
 * Definiuje wykonywalną ścieżkę etapów dla spraw danej klasyfikacji JRWA;
 * podgląd w notacji zbliżonej do BPMN + eksport BPMN 2.0 XML.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_role('admin');
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$PAGE_TITLE = 'Edytor procesów (workflow)';
$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $jid = (int)($_POST['jrwa_id'] ?? 0);
    $action = $_POST['_action'] ?? '';
    try {
        if (!$jid || !ezd_jrwa_get($jid)) throw new \RuntimeException('Nie wskazano hasła JRWA.');
        if ($action === 'save') {
            $steps = json_decode($_POST['steps_json'] ?? '[]', true);
            if (!is_array($steps)) throw new \RuntimeException('Nieprawidłowe dane kroków.');
            ezd_workflow_save($jid, trim($_POST['name'] ?? ''), $steps, $user_id);
            flash_set('success', 'Workflow zapisany.');
        } elseif ($action === 'reset') {
            ezd_workflow_delete($jid, $user_id);
            flash_set('success', 'Przywrócono domyślny workflow.');
        }
    } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
    header('Location: ezd_workflows.php?jrwa_id=' . $jid); exit;
}

$jrwa_all = ezd_jrwa_all();
$jrwa_id  = (int)($_GET['jrwa_id'] ?? 0);
$jrwa     = $jrwa_id ? ezd_jrwa_get($jrwa_id) : null;
$has_custom = $jrwa ? (bool)ezd_workflow_get($jrwa_id) : false;
$steps    = $jrwa ? ezd_workflow_steps($jrwa_id) : [];
$wf       = $jrwa ? ezd_workflow_get($jrwa_id) : null;
$wf_name  = $wf['name'] ?? ($jrwa ? ('Obieg ' . $jrwa['symbol']) : '');

// Zbiór JRWA z własnym workflow
$custom_ids = array_column(db_all("SELECT jrwa_id FROM ezd_workflows WHERE jrwa_id IS NOT NULL"), 'jrwa_id');
$custom_ids = array_map('intval', $custom_ids);

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-diagram-2 text-primary me-2"></i>Edytor procesów (workflow BPM)</h4>
  <a href="<?= APP_URL ?>/ezd/index.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>EZD Wirtualne biurko</a>
</div>
<?= flash_html() ?>

<div class="row g-3">
  <!-- Lista JRWA -->
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.84rem"><i class="bi bi-tags me-1 text-primary"></i>Hasła JRWA</div>
      <div class="list-group list-group-flush" style="max-height:70vh;overflow:auto">
        <?php foreach ($jrwa_all as $j): $cust = in_array((int)$j['id'], $custom_ids, true); ?>
        <a href="?jrwa_id=<?= $j['id'] ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $jrwa_id===(int)$j['id']?'active':'' ?>" style="font-size:.82rem">
          <span class="font-monospace fw-bold"><?= h($j['symbol']) ?></span>
          <span class="text-truncate flex-grow-1 <?= $jrwa_id===(int)$j['id']?'':'text-muted' ?>"><?= h($j['title']) ?></span>
          <?php if($cust): ?><span class="badge bg-info" style="font-size:.6rem">własny</span><?php else: ?><span class="badge bg-light text-muted border" style="font-size:.6rem">domyślny</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Edytor -->
  <div class="col-lg-8">
    <?php if (!$jrwa): ?>
    <div class="card shadow-sm"><div class="card-body text-center text-muted py-5">
      <i class="bi bi-diagram-2" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Wybierz hasło JRWA z listy, aby zdefiniować lub edytować jego proces obiegu.
      <div class="mt-2" style="font-size:.82rem">Sprawy bez własnego workflow używają domyślnej ścieżki: Wszczęcie → Dekretacja → Realizacja → Akceptacja → Podpis → Wysyłka → Zakończenie.</div>
    </div></div>
    <?php else: ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.84rem"><i class="bi bi-pencil me-1 text-primary"></i>Proces dla JRWA <span class="font-monospace"><?= h($jrwa['symbol']) ?></span> — <?= h($jrwa['title']) ?></span>
        <span class="badge bg-<?= $has_custom?'info':'secondary' ?>"><?= $has_custom?'własny workflow':'domyślny' ?></span>
      </div>
      <div class="card-body">
        <form method="post" id="wf-form">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">
          <input type="hidden" name="jrwa_id" value="<?= $jrwa_id ?>">
          <input type="hidden" name="steps_json" id="steps_json">

          <div class="mb-3" style="max-width:420px">
            <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Nazwa procesu</label>
            <input type="text" name="name" class="form-control form-control-sm" value="<?= h($wf_name) ?>" placeholder="np. Obieg pism KOR">
          </div>

          <label class="form-label fw-semibold mb-1" style="font-size:.8rem">Etapy obiegu</label>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-2" style="font-size:.82rem">
              <thead class="table-light"><tr>
                <th style="width:30px">#</th><th>Etap</th><th style="width:120px">Kolor</th>
                <th style="width:170px">Dyspozycja (auto)</th><th style="width:90px">SLA (dni)</th><th style="width:90px"></th>
              </tr></thead>
              <tbody id="wf-rows"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="wf-add"><i class="bi bi-plus-lg me-1"></i>Dodaj etap</button>

          <div class="d-flex gap-2 mt-3 pt-3 border-top flex-wrap">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz workflow</button>
            <a href="<?= APP_URL ?>/admin/ezd_workflow_bpmn.php?jrwa_id=<?= $jrwa_id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Pobierz BPMN (.bpmn)</a>
            <?php if($has_custom): ?>
            <button type="submit" form="wf-reset" class="btn btn-outline-danger btn-sm ms-auto" onclick="return confirm('Przywrócić domyślny workflow dla tej JRWA? Usunie definicję własną.')"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny</button>
            <?php endif; ?>
          </div>
        </form>
        <form method="post" id="wf-reset" class="d-none">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="reset">
          <input type="hidden" name="jrwa_id" value="<?= $jrwa_id ?>">
        </form>
      </div>
    </div>

    <!-- Podgląd procesu (notacja zbliżona do BPMN) -->
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.84rem"><i class="bi bi-eye me-1 text-primary"></i>Podgląd procesu</div>
      <div class="card-body" style="overflow-x:auto">
        <div id="wf-preview"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($jrwa): ?>
<script>
(function(){
  var STEPS = <?= json_encode(array_values($steps), JSON_UNESCAPED_UNICODE) ?>;
  var DYSP  = <?= json_encode(EZD_DYSPOZYCJE, JSON_UNESCAPED_UNICODE) ?>;
  var COLORS = ['secondary','primary','info','warning','success','danger','dark'];
  var COLORHEX = {secondary:'#94a3b8',primary:'#2563eb',info:'#0891b2',warning:'#d97706',success:'#16a34a',danger:'#dc2626',dark:'#334155'};
  var tbody = document.getElementById('wf-rows');
  var preview = document.getElementById('wf-preview');

  function rowHtml(s, i){
    var dysp = '<option value="">— brak —</option>';
    for (var k in DYSP) dysp += '<option value="'+k+'"'+(s.dyspozycja===k?' selected':'')+'>'+DYSP[k]+'</option>';
    var col = '';
    COLORS.forEach(function(c){ col += '<option value="'+c+'"'+(s.class===c?' selected':'')+'>'+c+'</option>'; });
    return '<tr data-i="'+i+'">'
      + '<td class="text-muted">'+(i+1)+'</td>'
      + '<td><input class="form-control form-control-sm wf-label" value="'+(s.label||'').replace(/"/g,'&quot;')+'" placeholder="Nazwa etapu"></td>'
      + '<td><select class="form-select form-select-sm wf-class">'+col+'</select></td>'
      + '<td><select class="form-select form-select-sm wf-dysp">'+dysp+'</select></td>'
      + '<td><input type="number" min="0" class="form-control form-control-sm wf-sla" value="'+(parseInt(s.sla_days)||0)+'"></td>'
      + '<td class="text-nowrap">'
        + '<button type="button" class="btn btn-xs btn-outline-secondary btn-sm wf-up" title="W górę"><i class="bi bi-arrow-up"></i></button> '
        + '<button type="button" class="btn btn-xs btn-outline-secondary btn-sm wf-down" title="W dół"><i class="bi bi-arrow-down"></i></button> '
        + '<button type="button" class="btn btn-xs btn-outline-danger btn-sm wf-del" title="Usuń"><i class="bi bi-trash3"></i></button>'
      + '</td></tr>';
  }

  function readDom(){
    var arr=[];
    tbody.querySelectorAll('tr').forEach(function(tr){
      var label=tr.querySelector('.wf-label').value.trim();
      if(!label) return;
      arr.push({key:(STEPS[tr.dataset.i]&&STEPS[tr.dataset.i].key)||'', label:label,
        class:tr.querySelector('.wf-class').value, dyspozycja:tr.querySelector('.wf-dysp').value,
        sla_days:parseInt(tr.querySelector('.wf-sla').value)||0});
    });
    return arr;
  }

  function render(){
    tbody.innerHTML='';
    STEPS.forEach(function(s,i){ tbody.insertAdjacentHTML('beforeend', rowHtml(s,i)); });
    drawPreview();
  }

  function syncFromDom(){ STEPS = readDom(); }

  function drawPreview(){
    var steps = readDom();
    var node = function(x,cx,label,color,shape){
      if(shape==='circle') return '<circle cx="'+(x+18)+'" cy="40" r="16" fill="#fff" stroke="'+color+'" stroke-width="2"/>';
      return '<rect x="'+x+'" y="18" width="120" height="44" rx="8" fill="'+color+'22" stroke="'+color+'" stroke-width="1.5"/>';
    };
    var W=60, gap=40, taskW=120, x=10, parts=[], cxs=[];
    // start
    parts.push('<circle cx="'+(x+16)+'" cy="40" r="15" fill="#fff" stroke="#16a34a" stroke-width="2"/>');
    cxs.push([x,x+32]); x+=32+gap;
    steps.forEach(function(s){
      var col=COLORHEX[s.class]||'#94a3b8';
      parts.push('<rect x="'+x+'" y="18" width="'+taskW+'" height="44" rx="8" fill="'+col+'1f" stroke="'+col+'" stroke-width="1.5"/>');
      parts.push('<text x="'+(x+taskW/2)+'" y="44" text-anchor="middle" font-size="11" fill="#1e293b">'+escapeXml(trunc(s.label,16))+'</text>');
      cxs.push([x,x+taskW]); x+=taskW+gap;
    });
    parts.push('<circle cx="'+(x+16)+'" cy="40" r="15" fill="#fff" stroke="#dc2626" stroke-width="2.5"/>');
    cxs.push([x,x+32]); x+=32;
    // arrows
    var arrows='';
    for(var i=0;i<cxs.length-1;i++){ arrows+='<line x1="'+cxs[i][1]+'" y1="40" x2="'+cxs[i+1][0]+'" y2="40" stroke="#94a3b8" stroke-width="1.5" marker-end="url(#arr)"/>'; }
    var w=Math.max(x+10,300);
    preview.innerHTML='<svg width="'+w+'" height="80" xmlns="http://www.w3.org/2000/svg">'
      +'<defs><marker id="arr" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="#94a3b8"/></marker></defs>'
      +arrows+parts.join('')+'</svg>'
      + (steps.length? '' : '<div class="text-muted" style="font-size:.8rem">Dodaj etapy, aby zobaczyć podgląd.</div>');
  }
  function trunc(s,n){ return s.length>n? s.slice(0,n-1)+'…':s; }
  function escapeXml(s){ return s.replace(/[<>&]/g,function(c){return{'<':'&lt;','>':'&gt;','&':'&amp;'}[c];}); }

  tbody.addEventListener('click', function(e){
    var btn=e.target.closest('button'); if(!btn) return;
    var tr=btn.closest('tr'); var i=parseInt(tr.dataset.i);
    syncFromDom();
    if(btn.classList.contains('wf-del')) STEPS.splice(i,1);
    else if(btn.classList.contains('wf-up') && i>0){ var t=STEPS[i]; STEPS[i]=STEPS[i-1]; STEPS[i-1]=t; }
    else if(btn.classList.contains('wf-down') && i<STEPS.length-1){ var t2=STEPS[i]; STEPS[i]=STEPS[i+1]; STEPS[i+1]=t2; }
    render();
  });
  tbody.addEventListener('input', drawPreview);
  tbody.addEventListener('change', drawPreview);
  document.getElementById('wf-add').addEventListener('click', function(){ syncFromDom(); STEPS.push({key:'',label:'Nowy etap',class:'secondary',dyspozycja:'',sla_days:0}); render(); });
  document.getElementById('wf-form').addEventListener('submit', function(){ document.getElementById('steps_json').value = JSON.stringify(readDom()); });

  render();
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
