<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

kdok_require_access();
kdok_migrate();

// Tylko rola "zatwierdza" lub admin może widzieć preliminarz
if (!kdok_has_role('zatwierdza')) {
    flash_set('danger', 'Brak dostępu do Preliminarza Płatności.');
    header('Location: ' . APP_URL . '/ksiegowosc/index.php');
    exit;
}

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_termin_od = trim($_GET['termin_od'] ?? '');
$f_termin_do = trim($_GET['termin_do'] ?? date('Y-m-d', strtotime('+30 days')));
$f_status    = trim($_GET['status'] ?? '');
$f_waluta    = trim($_GET['waluta'] ?? '');
$f_mpp       = !empty($_GET['mpp']);
$f_ck        = trim($_GET['centrum_kosztow'] ?? '');
$f_q         = trim($_GET['q'] ?? '');

$filters = array_filter([
    'termin_od'       => $f_termin_od,
    'termin_do'       => $f_termin_do ?: null,
    'status_platnosci'=> $f_status,
    'waluta'          => $f_waluta,
    'mpp'             => $f_mpp ?: null,
    'centrum_kosztow' => $f_ck,
    'q'               => $f_q,
]);

$docs = kdok_preliminarz_query($filters);

$rachunki_pli = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];

// ── Eksport CSV ───────────────────────────────────────────────────────────────
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="KDOK_Preliminarz_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, ['Nr KDOK','Typ','Tytuł','Nr faktury','NIP dostawcy','Nr rachunku','Kwota netto','VAT','Kwota brutto','Waluta','Termin płatności','Centrum kosztów','Projekt','Status płatności','MPP'], ';');
    foreach ($docs as $d) {
        fputcsv($out, [
            $d['number'],
            KDOK_TYPES[$d['type']]['label'] ?? $d['type'],
            $d['title'],
            $d['nr_faktury'] ?? '',
            $d['nip_dostawcy'] ?? '',
            $d['rachunek_bankowy'] ?? '',
            $d['kwota_netto'] ?? '',
            $d['kwota_vat'] ?? '',
            $d['kwota_brutto'] ?: $d['kwota'] ?: '',
            $d['waluta'] ?: 'PLN',
            $d['termin_platnosci'] ? substr($d['termin_platnosci'], 0, 10) : '',
            $d['centrum_kosztow'] ?? '',
            $d['projekt'] ?? '',
            $d['status_platnosci'] ?: 'nowy',
            !empty($d['wymaga_mpp']) ? 'MPP' : '',
        ], ';');
    }
    fclose($out);
    exit;
}

// Sumy kontrolne dla zaznaczonych walut
$sumy = [];
foreach ($docs as $d) {
    $w = $d['waluta'] ?: 'PLN';
    $b = (float) str_replace([' ', ','], ['', '.'], $d['kwota_brutto'] ?: $d['kwota'] ?: '0');
    $v = (float) str_replace([' ', ','], ['', '.'], $d['kwota_vat'] ?: '0');
    $sumy[$w]['brutto'] = ($sumy[$w]['brutto'] ?? 0) + $b;
    $sumy[$w]['vat']    = ($sumy[$w]['vat']    ?? 0) + $v;
}

// ── Lista centrum kosztów do filtra ──────────────────────────────────────────
$ck_list = kdok_all(
    "SELECT DISTINCT centrum_kosztow FROM kdok_documents WHERE centrum_kosztow != '' ORDER BY centrum_kosztow"
);

$PAGE_TITLE = 'Preliminarz Płatności';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-calendar-check"></i> Preliminarz Płatności</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/ksiegowosc/preliminarz.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
       class="btn btn-sm btn-outline-success" title="Eksport do CSV">
      <i class="bi bi-filetype-csv"></i> CSV
    </a>
    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalPliExport"
            title="Eksport do GOonline Biznes — MultiCash PLI">
      <i class="bi bi-bank"></i> PLI
    </button>
    <a href="<?= APP_URL ?>/ksiegowosc/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> EOD
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Filtry -->
<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-auto">
        <label class="form-label mb-1 small fw-semibold">Termin płatności</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="date" name="termin_od" class="form-control form-control-sm" value="<?= h($f_termin_od) ?>" placeholder="od">
          <span class="text-muted">–</span>
          <input type="date" name="termin_do" class="form-control form-control-sm" value="<?= h($f_termin_do) ?>" placeholder="do">
        </div>
      </div>
      <div class="col-sm-2">
        <label class="form-label mb-1 small fw-semibold">Status płatności</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (KDOK_STATUS_PLATNOSCI as $k => $s): ?>
          <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label mb-1 small fw-semibold">Centrum kosztów</label>
        <select name="centrum_kosztow" class="form-select form-select-sm">
          <option value="">Wszystkie CK</option>
          <?php foreach ($ck_list as $r): ?>
          <option value="<?= h($r['centrum_kosztow']) ?>" <?= $f_ck === $r['centrum_kosztow'] ? 'selected' : '' ?>>
            <?= h($r['centrum_kosztow']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-1">
        <label class="form-label mb-1 small fw-semibold">Waluta</label>
        <select name="waluta" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
          <option value="<?= $w ?>" <?= $f_waluta === $w ? 'selected' : '' ?>><?= $w ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label mb-1 small fw-semibold">Szukaj</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="NIP / nr faktury / tytuł"
               value="<?= h($f_q) ?>">
      </div>
      <div class="col-auto">
        <div class="form-check form-check-inline mt-3">
          <input class="form-check-input" type="checkbox" name="mpp" id="f-mpp" value="1" <?= $f_mpp ? 'checked' : '' ?>>
          <label class="form-check-label small" for="f-mpp">Tylko MPP</label>
        </div>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <a href="<?= APP_URL ?>/ksiegowosc/preliminarz.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a>
      </div>
    </div>
  </div>
</form>

<!-- Sumy kontrolne -->
<?php if ($sumy): ?>
<div class="d-flex flex-wrap gap-3 mb-3">
  <?php foreach ($sumy as $w => $s): ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:160px">
    <div class="small text-muted">Łącznie brutto <strong><?= h($w) ?></strong></div>
    <div class="fw-bold fs-6 font-monospace"><?= number_format($s['brutto'], 2, ',', ' ') ?></div>
    <?php if ($s['vat'] > 0): ?>
    <div class="small text-muted">w tym VAT: <?= number_format($s['vat'], 2, ',', ' ') ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="card border-0 shadow-sm px-3 py-2 text-center" style="min-width:120px">
    <div class="small text-muted">Liczba pozycji</div>
    <div class="fw-bold fs-6"><?= count($docs) ?></div>
  </div>
</div>
<?php endif; ?>

<?php if (!$docs): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-check-circle display-4 d-block mb-2"></i>
  Brak dokumentów spełniających kryteria.
</div>
<?php else: ?>

<!-- Masowe zatwierdzenie -->
<div id="bulk-prelim-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:1050;
     background:#1e3a5f;color:#f0f4ff;padding:.5rem 1.25rem;box-shadow:0 -3px 14px rgba(0,0,0,.3)">
  <div class="d-flex align-items-center gap-2 flex-wrap" style="max-width:1400px;margin:0 auto">
    <span class="fw-semibold"><i class="bi bi-check2-square me-1"></i><span id="bp-count">0</span> zaznaczonych</span>
    <button type="button" class="btn btn-sm btn-warning" onclick="bulkPrelimAction('do_realizacji')">
      <i class="bi bi-play-circle"></i> Zatwierdź do realizacji
    </button>
    <button type="button" class="btn btn-sm btn-success" onclick="bulkPrelimAction('oplacony')">
      <i class="bi bi-check-lg"></i> Oznacz jako opłacone
    </button>
    <button type="button" class="btn btn-sm btn-secondary" onclick="bulkPrelimAction('wstrzymany')">
      <i class="bi bi-pause-circle"></i> Wstrzymaj
    </button>
    <button type="button" class="btn btn-sm btn-link text-white-50 ms-auto p-0" onclick="bulkPrelimClear()">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.83rem">
      <thead class="table-dark">
        <tr>
          <th style="width:2rem"><input type="checkbox" id="bp-all" class="form-check-input" aria-label="Zaznacz wszystkie"></th>
          <th>Nr KDOK</th>
          <th>Nr faktury</th>
          <th>Kontrahent / NIP</th>
          <th>Nr konta</th>
          <th class="text-end">Netto</th>
          <th class="text-end">VAT</th>
          <th class="text-end">Brutto</th>
          <th>Waluta</th>
          <th>Termin</th>
          <th class="text-center">Dni</th>
          <th class="text-center">MPP</th>
          <th>CK / Projekt</th>
          <th>Status</th>
          <th class="text-center">P.</th>
          <th>Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($docs as $doc): ?>
        <?php
        $p     = (int)($doc['priorytet'] ?? 5);
        $today = date('Y-m-d');
        $termin = $doc['termin_platnosci'] ?? '';
        $diff   = $termin ? (int)round((strtotime($termin) - strtotime($today)) / 86400) : null;
        $rowClass = match(true) {
            $p === 1 => 'table-danger',
            $p === 2 => 'table-warning',
            default  => '',
        };
        $prioColors = ['','danger','warning','info','success','secondary'];
        $prioLabels = ['','Krytyczny','Pilny','Wkrótce','Normalny','Oczekujący'];
        $mpp = !empty($doc['wymaga_mpp']);
        $ezd_url = ($doc['ezd_sprawa_id'] && module_enabled('ezd_enabled'))
            ? APP_URL . '/ezd/sprawy/view.php?id=' . (int)$doc['ezd_sprawa_id']
            : null;
        ?>
        <tr class="<?= $rowClass ?>">
          <td>
            <input type="checkbox" class="form-check-input bp-row" value="<?= $doc['id'] ?>"
                   aria-label="Zaznacz <?= h($doc['number']) ?>">
          </td>
          <td>
            <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $doc['id'] ?>" class="fw-semibold text-decoration-none">
              <code><?= h($doc['number']) ?></code>
            </a>
            <?php if ($ezd_url): ?>
            <a href="<?= h($ezd_url) ?>" class="ms-1 text-muted" title="Koszulka EZD" target="_blank">
              <i class="bi bi-folder2-open"></i>
            </a>
            <?php endif; ?>
          </td>
          <td><?= h($doc['nr_faktury'] ?: '—') ?></td>
          <td>
            <div><?= h($doc['title'] ?: '—') ?></div>
            <?php if ($doc['nip_dostawcy']): ?>
            <div class="text-muted small font-monospace"><?= h($doc['nip_dostawcy']) ?></div>
            <?php endif; ?>
          </td>
          <td class="font-monospace small text-muted">
            <?php if ($doc['rachunek_bankowy']): ?>
              <?= h(chunk_split($doc['rachunek_bankowy'], 4, ' ')) ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-end font-monospace"><?= $doc['kwota_netto'] ? h($doc['kwota_netto']) : '—' ?></td>
          <td class="text-end font-monospace"><?= $doc['kwota_vat']   ? h($doc['kwota_vat'])   : '—' ?></td>
          <td class="text-end font-monospace fw-semibold"><?= $doc['kwota_brutto'] ? h($doc['kwota_brutto']) : (h($doc['kwota']) ?: '—') ?></td>
          <td><?= h($doc['waluta'] ?: 'PLN') ?></td>
          <td>
            <?php if ($termin): ?>
              <span class="<?= $p <= 2 ? 'fw-semibold' : '' ?>"><?= h(substr($termin, 0, 10)) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($diff !== null): ?>
              <span class="badge <?= $diff < 0 ? 'bg-danger' : ($diff <= 3 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                <?= $diff < 0 ? abs($diff) . ' po' : $diff ?>
              </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-center">
            <?= $mpp ? '<span class="badge bg-warning text-dark" title="Split payment wymagany">MPP</span>' : '' ?>
          </td>
          <td>
            <?php if ($doc['centrum_kosztow']): ?>
            <span class="badge bg-secondary"><?= h($doc['centrum_kosztow']) ?></span>
            <?php endif; ?>
            <?php if ($doc['projekt']): ?>
            <span class="badge bg-info text-dark ms-1"><?= h($doc['projekt']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= kdok_status_platnosci_badge($doc['status_platnosci'] ?: 'nowy') ?></td>
          <td class="text-center">
            <span class="badge bg-<?= $prioColors[$p] ?? 'secondary' ?>" title="<?= $prioLabels[$p] ?>">
              P<?= $p ?>
            </span>
          </td>
          <td>
            <div class="d-flex gap-1 flex-nowrap">
              <?php if (in_array($doc['status_platnosci'] ?? 'nowy', ['nowy','wstrzymany'], true)): ?>
              <button class="btn btn-xs btn-outline-warning py-0 px-1 prelim-action"
                      data-id="<?= $doc['id'] ?>" data-action="do_realizacji"
                      title="Zatwierdź do realizacji"><i class="bi bi-play-circle"></i></button>
              <?php endif; ?>
              <?php if (in_array($doc['status_platnosci'] ?? 'nowy', ['nowy','do_realizacji','zlecony'], true)): ?>
              <button class="btn btn-xs btn-outline-success py-0 px-1 prelim-action"
                      data-id="<?= $doc['id'] ?>" data-action="oplacony"
                      title="Oznacz jako opłacone"><i class="bi bi-check-circle"></i></button>
              <?php endif; ?>
              <?php if (!in_array($doc['status_platnosci'] ?? 'nowy', ['oplacony','anulowany'], true)): ?>
              <button class="btn btn-xs btn-outline-secondary py-0 px-1 prelim-action"
                      data-id="<?= $doc['id'] ?>" data-action="wstrzymany"
                      title="Wstrzymaj"><i class="bi bi-pause-circle"></i></button>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/ksiegowosc/view.php?id=<?= $doc['id'] ?>"
                 class="btn btn-xs btn-outline-primary py-0 px-1" title="Podgląd dokumentu">
                <i class="bi bi-eye"></i>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
.btn-xs { font-size:.75rem; line-height:1.4; }
</style>

<script>
(function () {
  var bar   = document.getElementById('bulk-prelim-bar');
  var cbAll = document.getElementById('bp-all');

  function getChecked() {
    return Array.from(document.querySelectorAll('.bp-row:checked')).map(function (c) { return c.value; });
  }
  function updateBar() {
    var n = getChecked().length, all = document.querySelectorAll('.bp-row').length;
    if (bar) bar.style.display = n ? '' : 'none';
    document.getElementById('bp-count').textContent = n;
    if (cbAll) { cbAll.checked = n > 0 && n === all; cbAll.indeterminate = n > 0 && n < all; }
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'bp-all') {
      document.querySelectorAll('.bp-row').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target && (e.target.id === 'bp-all' || e.target.classList.contains('bp-row'))) updateBar();
  });

  window.bulkPrelimClear = function () {
    document.querySelectorAll('.bp-row').forEach(function (c) { c.checked = false; });
    if (cbAll) { cbAll.checked = false; cbAll.indeterminate = false; }
    updateBar();
  };

  function doAction(ids, action) {
    var fd = new FormData();
    fd.append('_csrf', '<?= csrf_token() ?>');
    fd.append('action', action);
    ids.forEach(function (id) { fd.append('ids[]', id); });
    return fetch('<?= APP_URL ?>/ksiegowosc/preliminarz_action.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) { location.reload(); }
        else { alert(data.message || 'Błąd.'); }
      })
      .catch(function () { alert('Błąd połączenia.'); });
  }

  window.bulkPrelimAction = function (action) {
    var ids = getChecked();
    if (!ids.length) return;
    doAction(ids, action);
  };

  document.querySelectorAll('.prelim-action').forEach(function (btn) {
    btn.addEventListener('click', function () {
      doAction([btn.dataset.id], btn.dataset.action);
    });
  });
})();
</script>

<!-- ── Modal: Eksport PLI (GOonline Biznes) ──────────────────────────────── -->
<div class="modal fade" id="modalPliExport" tabindex="-1" aria-labelledby="modalPliLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalPliLabel"><i class="bi bi-bank me-2"></i>Eksport PLI — GOonline Biznes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form action="<?= APP_URL ?>/ksiegowosc/export_pli.php" method="get">
        <!-- Propaguj aktywne filtry do export_pli.php -->
        <?php foreach ($_GET as $gk => $gv):
            if ($gk === 'export') continue;
            if (is_array($gv)): foreach ($gv as $gvi): ?>
          <input type="hidden" name="<?= h($gk) ?>[]" value="<?= h($gvi) ?>">
        <?php endforeach; else: ?>
          <input type="hidden" name="<?= h($gk) ?>" value="<?= h($gv) ?>">
        <?php endif; endforeach; ?>
        <!-- Propaguj zaznaczone ID (z bulk-bar) przez JS -->
        <div id="pli-ids-container"></div>
        <div class="modal-body">
          <p class="small text-muted mb-3">
            Generuje plik <strong>MultiCash PLI</strong> (CP852) gotowy do importu w GOonline Biznes.<br>
            Eksportowane są tylko dokumenty z uzupełnionym 26-cyfrowym NRB odbiorcy.
            Dokumenty z MPP otrzymują strukturę Split Payment (pole 15 = 53).
          </p>
          <div class="mb-2">
            <label class="form-label fw-semibold mb-1">Rachunek organizacji (nadawca)</label>
            <?php if ($rachunki_pli): ?>
            <?php $saved_nrb = org_setting('kdok_rachunek_wlasny'); ?>
            <select name="nrb" id="pli-nrb" class="form-select form-select-sm font-monospace" required>
              <?php foreach ($rachunki_pli as $ra): ?>
              <option value="<?= h($ra['nrb']) ?>"
                      data-nazwa="<?= h($ra['nazwa'] ?? '') ?>"
                      data-waluta="<?= h($ra['waluta'] ?? 'PLN') ?>"
                      <?= ($ra['nrb'] === $saved_nrb) ? 'selected' : '' ?>>
                <?= h(chunk_split($ra['nrb'], 4, ' ')) ?>
                (<?= h($ra['waluta'] ?: 'PLN') ?>)
                <?= $ra['opis'] ? '— ' . h(mb_substr($ra['opis'], 0, 30)) : '' ?>
              </option>
              <?php endforeach; ?>
              <option value="">— wpisz ręcznie —</option>
            </select>
            <div id="pli-nrb-custom-wrap" class="mt-2" style="display:none">
              <input type="text" id="pli-nrb-custom"
                     class="form-control form-control-sm font-monospace"
                     maxlength="26" pattern="\d{26}"
                     placeholder="26 cyfr NRB">
            </div>
            <div class="form-text">
              Rachunki bankowe konfigurujesz w
              <a href="<?= APP_URL ?>/admin/org_settings.php?tab=rachunki" target="_blank">Dane organizacji → Rachunki bankowe</a>.
            </div>
            <?php else: ?>
            <input type="text" name="nrb" id="pli-nrb"
                   class="form-control form-control-sm font-monospace"
                   maxlength="26" pattern="\d{26}"
                   placeholder="26 cyfr, np. 61109010140000071219812874"
                   value="<?= h(org_setting('kdok_rachunek_wlasny')) ?>"
                   required>
            <div class="form-text">
              Numer zostanie zapamiętany. Skonfiguruj rachunki w
              <a href="<?= APP_URL ?>/admin/org_settings.php?tab=rachunki" target="_blank">Dane organizacji</a>.
            </div>
            <?php endif; ?>
          </div>
          <div class="mb-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="z_sprawa" id="pli-z-sprawa" value="1" checked>
              <label class="form-check-label small" for="pli-z-sprawa">
                Dodaj numer sprawy EZD do tytułu przelewu
              </label>
            </div>
          </div>
          <?php
          $bez_rachunku = count(array_filter($docs, fn($d) => strlen(preg_replace('/[\s\-]/', '', $d['rachunek_bankowy'] ?? '')) !== 26));
          if ($bez_rachunku): ?>
          <div class="alert alert-warning small py-2 mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= $bez_rachunku ?> z <?= count($docs) ?> dokumentów nie ma NRB odbiorcy — zostaną pominięte.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-download me-1"></i>Pobierz plik PLI
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Przy otwarciu modalu — wstrzyknij zaznaczone ID jako hidden inputs
document.getElementById('modalPliExport').addEventListener('show.bs.modal', function () {
    var cont = document.getElementById('pli-ids-container');
    cont.innerHTML = '';
    var checked = Array.from(document.querySelectorAll('.bp-row:checked'));
    checked.forEach(function (cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = cb.value;
        cont.appendChild(inp);
    });
});

// Dropdown select z opcją ręcznego wpisania NRB
(function() {
    var sel    = document.getElementById('pli-nrb');
    var wrap   = document.getElementById('pli-nrb-custom-wrap');
    var custom = document.getElementById('pli-nrb-custom');
    if (sel && sel.tagName === 'SELECT') {
        sel.addEventListener('change', function() {
            if (!this.value) {
                if (wrap) wrap.style.display = 'block';
                if (custom) custom.required = true;
            } else {
                if (wrap) wrap.style.display = 'none';
                if (custom) { custom.required = false; custom.value = ''; }
            }
        });
    }
})();

// Walidacja + normalizacja NRB przed submittem
document.querySelector('#modalPliExport form').addEventListener('submit', function (e) {
    var selEl  = document.getElementById('pli-nrb');
    var custom = document.getElementById('pli-nrb-custom');
    var nrb;
    if (selEl && selEl.tagName === 'SELECT') {
        nrb = (selEl.value || (custom ? custom.value : '')).replace(/[\s\-]/g, '');
        // Jeśli wybrano "ręcznie" i brak wpisu w polu custom — blokuj
        if (!selEl.value && (!custom || !/^\d{26}$/.test(nrb))) {
            e.preventDefault();
            if (custom) { custom.setCustomValidity('Podaj 26-cyfrowy NRB.'); custom.reportValidity(); }
            return;
        }
        if (selEl.value) {
            nrb = selEl.value;
        }
        // Wstrzyknij NRB jako hidden field (select przesyła nrb= bezpośrednio)
    } else if (selEl) {
        nrb = selEl.value.replace(/[\s\-]/g, '');
        if (!/^\d{26}$/.test(nrb)) {
            e.preventDefault();
            selEl.setCustomValidity('NRB musi składać się z dokładnie 26 cyfr.');
            selEl.reportValidity();
            return;
        }
        selEl.setCustomValidity('');
        selEl.value = nrb;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
