<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko');

$PAGE_TITLE = 'Koszulki';
$user_id    = (int)current_user()['id'];

$status_f   = $_GET['status']    ?? '';
$priority_f = $_GET['priority']  ?? '';
$owner_f    = (int)($_GET['owner_id'] ?? 0);
$mine_f     = !empty($_GET['mine']);
$dod        = $_GET['deadline_od'] ?? '';
$ddo        = $_GET['deadline_do'] ?? '';
$q          = trim($_GET['q']    ?? '');
$hide_ciagla_f = !empty($_GET['hide_ciagla']);
$hide_old_f    = !empty($_GET['hide_old']);

$filters = [
    'status' => $status_f, 'priority' => $priority_f, 'q' => $q,
    'owner_id' => $owner_f, 'deadline_od' => $dod, 'deadline_do' => $ddo,
    'mine_or_shared' => $mine_f, 'hide_ciagla' => $hide_ciagla_f, 'hide_old' => $hide_old_f,
];
// ── Masowe przerejestrowanie zaznaczonych koszulek ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'bulk_reregister') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

    $ids       = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $targetRaw = trim((string)($_POST['target'] ?? ''));
    $target    = null;
    if (str_starts_with($targetRaw, 'teczka:'))  $target = ['teczka_id' => (int)substr($targetRaw, 7)];
    elseif (str_starts_with($targetRaw, 'jrwa:')) $target = ['jrwa' => substr($targetRaw, 5)];

    if (!$ids)          flash_set('danger', 'Zaznacz przynajmniej jedną koszulkę.');
    elseif (!$target)   flash_set('danger', 'Wskaż, gdzie przenieść zaznaczone koszulki.');
    else {
        $ok = 0; $fail = [];
        foreach ($ids as $sid) {
            $sp = ezd_sprawa_get($sid);
            if (!$sp || !ezd_sprawa_access($sp, $user_id)) { $fail[] = "#{$sid}: brak dostępu"; continue; }
            $r = ezd_sprawa_reregister($sid, $target, $user_id);
            if (!empty($r['ok'])) $ok++;
            else $fail[] = ($sp['znak_sprawy'] ?: "#{$sid}") . ': ' . $r['error'];
        }
        $msg = "Przerejestrowano koszulek: {$ok}.";
        if ($fail) $msg .= ' Pominięto ' . count($fail) . ' — ' . implode('; ', array_slice($fail, 0, 6)) . (count($fail) > 6 ? '…' : '');
        flash_set($ok ? 'success' : 'warning', $msg);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$sprawy  = ezd_sprawy_all($filters, $user_id);
$owners  = db_all("SELECT DISTINCT u.id, u.name FROM ezd_sprawy s JOIN users u ON u.id=s.owner_id ORDER BY u.name");

// Cele masowego przeniesienia (otwarte segregatory + klasy JRWA) — tylko dla edytujących
$teczki_cele = $jrwa_cele = [];
if (can_edit()) {
    $teczki_cele = db_all(
        "SELECT t.id, t.symbol, t.title, t.rok, j.symbol AS jrwa_symbol
         FROM ezd_teczki t LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.status='open' ORDER BY t.symbol, t.rok DESC"
    );
    $jrwa_cele = ezd_jrwa_all();
}

// Eksport CSV bieżących wyników (respektuje filtry)
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sprawy_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM dla Excela
    fputcsv($out, ['Znak koszulki','Tytuł','Segregator','Status','Priorytet','Etap','Właściciel','Termin'], ';');
    foreach ($sprawy as $s) {
        fputcsv($out, [
            $s['znak_sprawy'], $s['title'], $s['teczka_symbol'].' — '.$s['teczka_title'],
            EZD_STATUSES_SPRAWA[$s['status']]['label'] ?? $s['status'],
            EZD_PRIORITIES[$s['priority']]['label'] ?? $s['priority'],
            ezd_etap_label_map()[$s['etap'] ?? '']['label'] ?? ($s['etap'] ?? ''),
            $s['owner_name'] ?? '', $s['deadline'] ?? '',
        ], ';');
    }
    fclose($out); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.sprawa-line{display:flex;align-items:center;border-bottom:1px solid #f1f5f9;}
.sprawa-line:last-child{border-bottom:none;}
.sprawa-line:hover{background:#f8fafc;}
.sprawa-row{display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;text-decoration:none;color:inherit;transition:background .12s;}
.sprawa-znak{font-family:monospace;font-size:.8rem;font-weight:700;color:#2563eb;white-space:nowrap;}
.ezd-search .input-group{box-shadow:0 4px 14px rgba(15,23,42,.08);border-radius:14px;overflow:hidden;}
.ezd-search .input-group-text{background:#fff;border:1px solid #e2e8f0;border-right:none;font-size:1.15rem;color:#64748b;padding:.7rem .55rem .7rem 1rem;}
.ezd-search input{border:1px solid #e2e8f0;border-left:none;border-right:none;font-size:.95rem;padding:.7rem .5rem;}
.ezd-search input:focus{box-shadow:none;border-color:#e2e8f0;}
.ezd-search .btn{padding:.7rem 1.4rem;font-weight:600;}
.dekr-cell{white-space:nowrap;font-size:.72rem;text-align:right;min-width:112px;}
.dekr-cell .dekr-who{font-weight:600;color:#334155;}
.dekr-cell .dekr-lezy{font-size:.66rem;}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-folder2-open text-primary me-2"></i>Koszulki</h4>
  <div class="d-flex gap-2">
    <?php if(can_edit()): ?>
    <button type="button" id="bulkTrigger" class="btn btn-success btn-sm" disabled
            data-bs-toggle="modal" data-bs-target="#bulkModal" title="Przerejestruj zaznaczone koszulki">
      <i class="bi bi-arrow-left-right me-1"></i>Przerejestruj zaznaczone <span class="badge bg-light text-success ms-1" id="bulkCount">0</span>
    </button>
    <?php endif; ?>
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Eksport CSV</a>
    <?php if(can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-stars me-1"></i>Przerejestrowanie (AI)</a>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="btn btn-primary btn-sm"><i class="bi bi-folder-plus me-1"></i>Nowa koszulka</a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<form method="get" class="mb-3">
<!-- Duża listwa wyszukiwania -->
<div class="ezd-search mb-3">
  <div class="input-group input-group-lg">
    <span class="input-group-text"><i class="bi bi-search"></i></span>
    <input type="search" name="q" value="<?= h($q) ?>" autofocus
           placeholder="Szukaj po numerze dokumentu, numerze koszulki lub tytule…">
    <button class="btn btn-primary" type="submit">Szukaj</button>
  </div>
</div>
<div class="row g-2 align-items-end">
  <div class="col-md-3">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach(EZD_STATUSES_SPRAWA as $sv=>$sl): ?>
      <option value="<?= $sv ?>" <?= $status_f===$sv?'selected':'' ?>><?= h($sl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="priority" class="form-select form-select-sm">
      <option value="">Wszystkie priorytety</option>
      <?php foreach(EZD_PRIORITIES as $pv=>$pl): ?>
      <option value="<?= $pv ?>" <?= $priority_f===$pv?'selected':'' ?>><?= h($pl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="owner_id" class="form-select form-select-sm">
      <option value="">Wszyscy właściciele</option>
      <?php foreach($owners as $o): ?>
      <option value="<?= $o['id'] ?>" <?= $owner_f===(int)$o['id']?'selected':'' ?>><?= h($o['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-1">
    <input type="date" name="deadline_od" class="form-control form-control-sm" value="<?= h($dod) ?>" title="Termin od">
  </div>
  <div class="col-md-1">
    <input type="date" name="deadline_do" class="form-control form-control-sm" value="<?= h($ddo) ?>" title="Termin do">
  </div>
  <div class="col-md-1">
    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filtruj</button>
  </div>
</div>
<div class="d-flex flex-wrap gap-3 mt-2">
  <div class="form-check">
    <input class="form-check-input" type="checkbox" name="mine" value="1" id="f-mine" <?= $mine_f?'checked':'' ?> onchange="this.form.submit()">
    <label class="form-check-label" for="f-mine" style="font-size:.82rem">Tylko moje i współdzielone ze mną</label>
  </div>
  <div class="form-check">
    <input class="form-check-input" type="checkbox" name="hide_ciagla" value="1" id="f-hide-ciagla" <?= $hide_ciagla_f?'checked':'' ?> onchange="this.form.submit()">
    <label class="form-check-label" for="f-hide-ciagla" style="font-size:.82rem"><i class="bi bi-infinity me-1 text-info"></i>Ukryj ciągle otwarte</label>
  </div>
  <div class="form-check">
    <input class="form-check-input" type="checkbox" name="hide_old" value="1" id="f-hide-old" <?= $hide_old_f?'checked':'' ?> onchange="this.form.submit()">
    <label class="form-check-label" for="f-hide-old" style="font-size:.82rem"><i class="bi bi-clock-history me-1 text-muted"></i>Ukryj starsze niż 14 dni</label>
  </div>
</div>
</form>

<!-- Tabela -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-folder2 me-1 text-primary"></i>Wyniki (<?= count($sprawy) ?>)</span>
    <?php if(can_edit() && $sprawy): ?>
    <div class="form-check mb-0">
      <input type="checkbox" id="bulkAll" class="form-check-input">
      <label for="bulkAll" class="form-check-label" style="font-size:.8rem">Zaznacz wszystkie</label>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <?php foreach($sprawy as $s):
      $lz = ezd_lezy_since($s['dekr_since'] ?? null);
    ?>
    <div class="sprawa-line">
      <?php if(can_edit()): ?>
      <div class="ps-3 pe-1"><input type="checkbox" class="form-check-input bulk-cb" name="ids[]" value="<?= (int)$s['id'] ?>" form="bulkForm" aria-label="Zaznacz koszulkę <?= h($s['znak_sprawy']) ?>"></div>
      <?php endif; ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="sprawa-row flex-grow-1">
      <div class="flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate" style="font-size:.88rem"><?= h($s['title']) ?></div>
        <div class="text-muted" style="font-size:.74rem"><span class="font-monospace"><?= h($s['znak_sprawy']) ?></span> · <i class="bi bi-archive me-1"></i><?= h($s['teczka_symbol'].' — '.$s['teczka_title']) ?></div>
      </div>
      <div class="dekr-cell">
        <?php if(!empty($s['dekr_wykonawca_name'])): ?>
        <div class="dekr-who"><i class="bi bi-person-fill me-1 text-warning"></i><?= h($s['dekr_wykonawca_name']) ?></div>
        <div class="dekr-lezy text-<?= $lz['class'] ?>"><i class="bi bi-hourglass-split me-1"></i>leży: <?= h($lz['label']) ?></div>
        <?php else: ?>
        <span class="text-muted" style="font-size:.7rem"><i class="bi bi-dash-circle me-1"></i>nieprzekazana</span>
        <?php endif; ?>
      </div>
      <?= ezd_etap_badge($s['etap'] ?? 'wszczeta') ?>
      <?= ezd_priority_badge($s['priority']) ?>
      <?= ezd_status_badge_sprawa($s['status']) ?>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap">
        <?php if($s['deadline']): ?>
        <span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'' ?>"><i class="bi bi-calendar-event me-1"></i><?= date_pl($s['deadline']) ?></span>
        <?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:.73rem;white-space:nowrap" title="Właściciel"><?= h($s['owner_name']??'—') ?></div>
      <i class="bi bi-chevron-right text-muted" style="font-size:.75rem"></i>
    </a>
    </div>
    <?php endforeach; ?>
    <?php if(!$sprawy): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-folder2" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Brak koszulek pasujących do filtrów.
      <?php if(can_edit()): ?><br><a href="<?= APP_URL ?>/ezd/sprawy/add.php">Załóż pierwszą koszulkę.</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if(can_edit() && $sprawy): ?>
<!-- Modal: masowe przerejestrowanie -->
<div class="modal fade" id="bulkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="bulkForm" method="post" action="<?= h($_SERVER['REQUEST_URI']) ?>" onsubmit="return bulkConfirm()">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2 text-success"></i>Przerejestruj zaznaczone koszulki</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="bulk_reregister">
          <p class="small mb-3">Zaznaczono: <span class="fw-bold" id="bulkModalCount">0</span> koszulek. Wskaż, gdzie je przenieść — każda otrzyma nowy znak w docelowym segregatorze.</p>
          <label class="form-label fw-semibold small">Przenieś do:</label>
          <select name="target" class="form-select" required>
            <option value="">— segregator lub klasa JRWA —</option>
            <?php if($teczki_cele): ?>
            <optgroup label="Istniejące segregatory (otwarte)">
              <?php foreach($teczki_cele as $t): ?>
              <option value="teczka:<?= (int)$t['id'] ?>"><?= h($t['symbol']) ?> · <?= h($t['title']) ?> (<?= (int)$t['rok'] ?>)<?= $t['jrwa_symbol'] ? ' — JRWA '.h($t['jrwa_symbol']) : '' ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <optgroup label="Utwórz/uzupełnij segregator w klasie JRWA">
              <?php foreach($jrwa_cele as $j): ?>
              <option value="jrwa:<?= h($j['symbol']) ?>"><?= h($j['symbol']) ?> — <?= h($j['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
          <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Pomijane: podkoszulki, koszulki z podkoszulkami oraz te już w wskazanym segregatorze. Operacja nieodwracalna.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-arrow-left-right me-1"></i>Przenieś zaznaczone</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const cbs     = () => Array.from(document.querySelectorAll('.bulk-cb'));
  const all     = document.getElementById('bulkAll');
  const trigger = document.getElementById('bulkTrigger');
  const badge   = document.getElementById('bulkCount');
  const mCount  = document.getElementById('bulkModalCount');
  const sel     = document.querySelector('#bulkForm select[name="target"]');
  function selected(){ return cbs().filter(c=>c.checked).length; }
  function refresh(){
    const n = selected();
    if(badge)   badge.textContent = n;
    if(mCount)  mCount.textContent = n;
    if(trigger) trigger.disabled = (n===0);
    if(all){ const total=cbs().length; all.checked = n>0 && n===total; all.indeterminate = n>0 && n<total; }
  }
  cbs().forEach(c=>c.addEventListener('change', refresh));
  if(all) all.addEventListener('change', ()=>{ cbs().forEach(c=>c.checked=all.checked); refresh(); });
  window.bulkConfirm = function(){
    const n = selected();
    if(n===0){ alert('Zaznacz przynajmniej jedną koszulkę.'); return false; }
    if(!sel || sel.value===''){ alert('Wskaż, gdzie przenieść.'); return false; }
    const label = sel.options[sel.selectedIndex].text.trim();
    return confirm('Przenieść '+n+' zaznaczonych koszulek do:\n'+label+'\n\nKażda otrzyma NOWY znak sprawy. Operacja nieodwracalna.');
  };
  refresh();
})();
</script>
<?php endif; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
