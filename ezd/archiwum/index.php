<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$user_id = (int)current_user()['id'];

// ── Utworzenie spisu (opcjonalnie z zaznaczonymi segregatorami) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'create_spis') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location:' . APP_URL . '/ezd/archiwum/index.php'); exit; }
    $typ = ($_POST['typ'] ?? '') === 'brakowanie' ? 'brakowanie' : 'zdawczo_odbiorczy';
    try {
        $sid = ezd_arch_spis_create([
            'typ'      => $typ,
            'rok'      => (int)date('Y'),
            'tytul'    => $_POST['tytul']    ?? '',
            'komorka'  => $_POST['komorka']  ?? '',
            'uwagi'    => $_POST['uwagi']    ?? '',
            'zgoda_ap' => $_POST['zgoda_ap'] ?? '',
        ], $user_id);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        foreach ($ids as $tid) { try { ezd_arch_spis_add_teczka($sid, $tid, $user_id); } catch (\Throwable $e) {} }
        flash_set('success', 'Utworzono spis. Uzupełnij pozycje i zatwierdź.');
        header('Location:' . APP_URL . '/ezd/archiwum/spis_view.php?id=' . $sid); exit;
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
        header('Location:' . APP_URL . '/ezd/archiwum/index.php'); exit;
    }
}

$PAGE_TITLE   = 'Archiwum zakładowe';
$stats        = ezd_arch_stats();
$doPrzekazania= ezd_teczki_do_przekazania();
$doBrakowania = ezd_teczki_do_brakowania();
$doEkspertyzy = ezd_teczki_do_ekspertyzy();
$spisy        = ezd_arch_spisy_all();
$rokTeraz     = (int)date('Y');

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Archiwum zakładowe</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-archive-fill text-dark me-2"></i>Archiwum zakładowe / składnica akt</h4>
  <?php if (can_edit()): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#spisModal" data-typ="zdawczo_odbiorczy">
      <i class="bi bi-box-arrow-in-down me-1"></i>Nowy spis zdawczo-odbiorczy
    </button>
    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#spisModal" data-typ="brakowanie">
      <i class="bi bi-trash3 me-1"></i>Nowy protokół brakowania
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- Statystyki -->
<div class="row g-2 mb-4">
  <?php
  $cards = [
    ['Do przekazania', $stats['do_przekazania'], 'secondary', 'bi-box-arrow-in-down'],
    ['W archiwum',      $stats['w_archiwum'],     'info',      'bi-archive'],
    ['Do brakowania',   $stats['do_brakowania'],  'danger',    'bi-trash3'],
    ['Do ekspertyzy',   $stats['do_ekspertyzy'],  'warning',   'bi-search'],
    ['Wybrakowane',     $stats['wybrakowane'],    'dark',      'bi-x-circle'],
    ['Spisy',           $stats['spisy'],          'primary',   'bi-journals'],
  ];
  foreach ($cards as [$lbl,$val,$cls,$ic]): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card shadow-sm h-100 border-0">
      <div class="card-body py-2 px-3">
        <div class="text-<?= $cls ?> mb-1"><i class="bi <?= $ic ?>"></i></div>
        <div class="h4 mb-0 fw-bold"><?= (int)$val ?></div>
        <div class="text-muted" style="font-size:.72rem"><?= h($lbl) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <!-- Segregatory do przekazania -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-box-arrow-in-down text-primary me-2"></i>Segregatory do przekazania do archiwum</div>
      <div class="card-body p-0">
        <?php if (!$doPrzekazania): ?>
          <p class="text-muted small p-3 mb-0">Brak zamkniętych segregatorów oczekujących na przekazanie.</p>
        <?php else: ?>
        <form method="post" action="<?= APP_URL ?>/ezd/archiwum/index.php">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="create_spis">
          <input type="hidden" name="typ" value="zdawczo_odbiorczy">
          <div class="table-responsive" style="max-height:340px;overflow:auto">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
              <thead class="table-light"><tr>
                <th style="width:32px"></th><th>Znak</th><th>Tytuł</th><th>Rok</th><th>Kat.</th>
              </tr></thead>
              <tbody>
              <?php foreach ($doPrzekazania as $t): ?>
                <tr>
                  <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" <?= can_edit()?'':'disabled' ?>></td>
                  <td class="font-monospace fw-bold text-primary"><?= h($t['symbol']) ?></td>
                  <td><?= h($t['title']) ?></td>
                  <td><?= (int)$t['rok'] ?></td>
                  <td><span class="badge bg-light text-dark border"><?= h($t['kat_arch'] ?: '—') ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (can_edit()): ?>
          <div class="p-2 border-top d-flex justify-content-end">
            <button class="btn btn-primary btn-sm"><i class="bi bi-list-check me-1"></i>Utwórz spis z zaznaczonych</button>
          </div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Segregatory do brakowania -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-trash3 text-danger me-2"></i>Do brakowania (upłynął okres przechowywania)</div>
      <div class="card-body p-0">
        <?php if (!$doBrakowania): ?>
          <p class="text-muted small p-3 mb-0">Brak dokumentacji, której okres przechowywania już upłynął.</p>
        <?php else: ?>
        <form method="post" action="<?= APP_URL ?>/ezd/archiwum/index.php">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="create_spis">
          <input type="hidden" name="typ" value="brakowanie">
          <div class="table-responsive" style="max-height:340px;overflow:auto">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
              <thead class="table-light"><tr>
                <th style="width:32px"></th><th>Znak</th><th>Tytuł</th><th>Kat.</th><th>Brakowanie</th>
              </tr></thead>
              <tbody>
              <?php foreach ($doBrakowania as $t): ?>
                <tr>
                  <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" <?= can_edit()?'':'disabled' ?>></td>
                  <td class="font-monospace fw-bold"><?= h($t['symbol']) ?></td>
                  <td><?= h($t['title']) ?></td>
                  <td><span class="badge bg-light text-dark border"><?= h($t['kat_arch'] ?: '—') ?></span></td>
                  <td><span class="badge bg-danger bg-opacity-10 text-danger"><?= (int)$t['rok_brakowania'] ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (can_edit()): ?>
          <div class="p-2 border-top d-flex justify-content-end">
            <button class="btn btn-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Protokół brakowania z zaznaczonych</button>
          </div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($doEkspertyzy): ?>
<div class="alert alert-warning mt-3 mb-0" role="alert">
  <i class="bi bi-search me-1"></i>
  <strong><?= count($doEkspertyzy) ?></strong> segregator(ów) kat. <strong>BE</strong> osiągnęło termin —
  wymagają ekspertyzy archiwum państwowego przed brakowaniem
  (<?= h(implode(', ', array_map(fn($t)=>$t['symbol'], array_slice($doEkspertyzy,0,8)))) ?><?= count($doEkspertyzy)>8?'…':'' ?>).
</div>
<?php endif; ?>

<!-- Lista spisów -->
<div class="card shadow-sm mt-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-journals text-success me-2"></i>Spisy i protokoły</div>
  <div class="card-body p-0">
    <?php if (!$spisy): ?>
      <p class="text-muted small p-3 mb-0">Brak spisów. Utwórz spis zdawczo-odbiorczy lub protokół brakowania.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr>
          <th>Sygnatura</th><th>Typ</th><th>Tytuł</th><th>Pozycji</th><th>Status</th><th>Utworzył</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($spisy as $s):
          $tm = EZD_ARCH_SPIS_TYPY[$s['typ']] ?? ['label'=>$s['typ'],'icon'=>'bi-journal','class'=>'secondary']; ?>
          <tr>
            <td class="font-monospace fw-bold"><?= h(ezd_arch_spis_sygnatura($s)) ?></td>
            <td><span class="badge bg-<?= $tm['class'] ?> bg-opacity-10 text-<?= $tm['class'] ?>"><i class="bi <?= $tm['icon'] ?> me-1"></i><?= h($tm['label']) ?></span></td>
            <td><?= h($s['tytul'] ?: '—') ?></td>
            <td><?= (int)$s['poz_count'] ?></td>
            <td><?= ezd_arch_spis_status_badge($s['status']) ?></td>
            <td class="text-muted"><?= h($s['created_name'] ?: '—') ?></td>
            <td class="text-end"><a href="<?= APP_URL ?>/ezd/archiwum/spis_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye me-1"></i>Otwórz</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: nowy spis -->
<?php if (can_edit()): ?>
<div class="modal fade" id="spisModal" tabindex="-1" aria-labelledby="spisModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="<?= APP_URL ?>/ezd/archiwum/index.php" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="create_spis">
      <input type="hidden" name="typ" id="spisTyp" value="zdawczo_odbiorczy">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="spisModalLabel">Nowy spis</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Tytuł / opis</label>
          <input type="text" name="tytul" class="form-control" placeholder="np. Akta kadrowe za 2015 r.">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Komórka organizacyjna przekazująca</label>
          <input type="text" name="komorka" class="form-control" placeholder="np. Dział Administracji">
        </div>
        <div class="mb-3" id="spisZgodaWrap" hidden>
          <label class="form-label fw-semibold">Nr zgody archiwum państwowego</label>
          <input type="text" name="zgoda_ap" class="form-control" placeholder="Zgoda na brakowanie (jeśli wymagana)">
        </div>
        <div class="mb-0">
          <label class="form-label fw-semibold">Uwagi</label>
          <textarea name="uwagi" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm">Utwórz</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('spisModal')?.addEventListener('show.bs.modal', function (ev) {
  var typ = ev.relatedTarget ? ev.relatedTarget.getAttribute('data-typ') : 'zdawczo_odbiorczy';
  var brak = typ === 'brakowanie';
  document.getElementById('spisTyp').value = typ;
  document.getElementById('spisModalLabel').textContent = brak ? 'Nowy protokół brakowania' : 'Nowy spis zdawczo-odbiorczy';
  document.getElementById('spisZgodaWrap').hidden = !brak;
});
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
