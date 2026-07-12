<?php
/**
 * Ewidencja wsparcia zewnętrznego OU — rejestr godzin wsparcia świadczonego przez
 * podmioty zewnętrzne (identyfikowane po KRS), per miesiąc, z zatwierdzaniem/odrzucaniem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/wsparcie_ou.php';

require_login();
require_module_enabled('wsparcie_ou_enabled', 'Ewidencja wsparcia zewnętrznego OU');
if (!can_read('wsparcie_ou')) { flash_set('error', 'Brak uprawnień do ewidencji wsparcia zewnętrznego (rola „Rozliczanie OU").'); header('Location:' . APP_URL . '/index.php'); exit; }

$user_id   = (int)current_user()['id'];
$can_write = can_write('wsparcie_ou');   // dodatkowa rola „Rozliczanie OU"
$self      = APP_URL . '/wsparcie_ou/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('error', 'Brak uprawnień do rozliczania OU.'); header('Location:' . $self); exit; }
    $op = $_POST['_op'] ?? '';
    try {
        if ($op === 'create') {
            if (trim((string)($_POST['podmiot_nazwa'] ?? '')) === '' && preg_replace('/\D/', '', (string)($_POST['podmiot_krs'] ?? '')) === '') {
                throw new \RuntimeException('Wskaż podmiot (wyszukaj po KRS).');
            }
            wsparcie_ou_create($_POST, $user_id);
            flash_set('success', 'Dodano wpis ewidencji.');
        } elseif ($op === 'update') {
            wsparcie_ou_update((int)($_POST['id'] ?? 0), $_POST, $user_id);
            flash_set('success', 'Zapisano zmiany.');
        } elseif ($op === 'approve') {
            wsparcie_ou_set_status((int)($_POST['id'] ?? 0), 'zatwierdzony', '', $user_id);
            flash_set('success', 'Wpis zatwierdzony.');
        } elseif ($op === 'reject') {
            wsparcie_ou_set_status((int)($_POST['id'] ?? 0), 'odrzucony', (string)($_POST['powod_odrzucenia'] ?? ''), $user_id);
            flash_set('success', 'Wpis odrzucony.');
        } elseif ($op === 'delete') {
            if (!is_admin()) throw new \RuntimeException('Usuwanie wymaga uprawnień administratora.');
            wsparcie_ou_delete((int)($_POST['id'] ?? 0), $user_id);
            flash_set('success', 'Wpis usunięty.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    $qs = http_build_query(array_filter(['miesiac' => $_POST['f_miesiac'] ?? '', 'status' => $_POST['f_status'] ?? '']));
    header('Location:' . $self . ($qs ? '?' . $qs : '')); exit;
}

$f_miesiac = (string)($_GET['miesiac'] ?? '');
$f_status  = (string)($_GET['status'] ?? '');
$q         = trim((string)($_GET['q'] ?? ''));
// Domyślnie poprzedni miesiąc, o ile nie wskazano „wszystkie"
if ($f_miesiac === '' && !isset($_GET['miesiac'])) $f_miesiac = wsparcie_ou_default_month();

$rows   = wsparcie_ou_all(['miesiac' => $f_miesiac, 'status' => $f_status, 'q' => $q]);
$stats  = wsparcie_ou_stats($f_miesiac ?: null);
$months = wsparcie_ou_months();
if ($f_miesiac && !in_array($f_miesiac, $months, true)) array_unshift($months, $f_miesiac);
$fmtG   = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ' '), '0'), ',');

$PAGE_TITLE = 'Ewidencja wsparcia zewnętrznego OU';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-building-add text-primary me-2"></i>Ewidencja wsparcia zewnętrznego OU</h4>
  <?php if ($can_write): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Nowy wpis</button>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-2 mb-3">
  <?php foreach ([
    ['Wpisy', $stats['cnt'], 'secondary', 'bi-list-ul'],
    ['Oczekuje', $stats['oczekuje'], 'warning', 'bi-hourglass-split'],
    ['Zatwierdzone', $stats['zatw'], 'success', 'bi-check-circle'],
    ['Odrzucone', $stats['odrz'], 'danger', 'bi-x-circle'],
    ['Godz. zatwierdzone', $fmtG($stats['godz_ok']), 'primary', 'bi-clock-history'],
  ] as [$lbl,$val,$cls,$ic]): ?>
  <div class="col-6 col-md">
    <div class="card shadow-sm border-0 h-100"><div class="card-body py-2 px-3">
      <div class="text-<?= $cls ?> mb-1"><i class="bi <?= $ic ?>"></i></div>
      <div class="h5 mb-0 fw-bold"><?= is_numeric($val) ? (int)$val : h($val) ?></div>
      <div class="text-muted" style="font-size:.72rem"><?= h($lbl) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <div class="col-auto"><label class="form-label small mb-1">Miesiąc</label>
    <select name="miesiac" class="form-select form-select-sm">
      <option value="">— wszystkie —</option>
      <?php foreach ($months as $m): ?><option value="<?= h($m) ?>" <?= $f_miesiac===$m?'selected':'' ?>><?= h($m) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><label class="form-label small mb-1">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">— dowolny —</option>
      <?php foreach (WSPARCIE_OU_STATUSES as $sv=>$sm): ?><option value="<?= $sv ?>" <?= $f_status===$sv?'selected':'' ?>><?= h($sm['label']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-md-4"><label class="form-label small mb-1">Szukaj podmiotu</label>
    <input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="nazwa / KRS / NIP"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Filtruj</button></div>
</div></form>

<!-- Tabela -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <?php if (!$rows): ?>
      <p class="text-muted small p-3 mb-0">Brak wpisów dla wybranych kryteriów.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:.87rem">
        <thead class="table-light"><tr>
          <th>Miesiąc</th><th>Podmiot</th><th>KRS / NIP</th><th class="text-end">Godziny</th><th>Status</th><th class="text-end">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="font-monospace"><?= h($r['miesiac']) ?></td>
            <td>
              <div class="fw-semibold"><?= h($r['podmiot_nazwa'] ?: '—') ?></div>
              <?php if ($r['podmiot_adres']): ?><div class="text-muted" style="font-size:.75rem"><?= h($r['podmiot_adres']) ?></div><?php endif; ?>
              <?php if ($r['status']==='odrzucony' && $r['powod_odrzucenia']): ?>
              <div class="text-danger" style="font-size:.75rem"><i class="bi bi-exclamation-circle me-1"></i><?= h($r['powod_odrzucenia']) ?></div>
              <?php endif; ?>
            </td>
            <td class="font-monospace" style="font-size:.78rem">
              <?php if ($r['podmiot_krs']): ?>KRS <?= h($r['podmiot_krs']) ?><br><?php endif; ?>
              <?php if ($r['podmiot_nip']): ?><span class="text-muted">NIP <?= h($r['podmiot_nip']) ?></span><?php endif; ?>
            </td>
            <td class="text-end fw-semibold"><?= $fmtG($r['liczba_godzin']) ?> h</td>
            <td><?= wsparcie_ou_status_badge($r['status']) ?></td>
            <td class="text-end text-nowrap">
              <?php if (!$can_write): ?><span class="text-muted small">—</span><?php endif; ?>
              <?php if ($can_write): ?>
              <?php if ($r['status'] !== 'zatwierdzony'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="approve">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="f_miesiac" value="<?= h($f_miesiac) ?>"><input type="hidden" name="f_status" value="<?= h($f_status) ?>">
                <button class="btn btn-outline-success btn-sm py-0 px-2" title="Zatwierdzam"><i class="bi bi-check-lg"></i></button>
              </form>
              <?php endif; ?>
              <?php if ($r['status'] !== 'odrzucony'): ?>
              <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Odrzucam"
                      data-bs-toggle="modal" data-bs-target="#rejectModal"
                      data-id="<?= (int)$r['id'] ?>" data-lbl="<?= h($r['podmiot_nazwa'].' · '.$r['miesiac']) ?>"><i class="bi bi-x-lg"></i></button>
              <?php endif; ?>
              <button class="btn btn-outline-secondary btn-sm py-0 px-2" title="Edytuj"
                      data-bs-toggle="modal" data-bs-target="#addModal"
                      data-edit='<?= h(json_encode(["id"=>(int)$r["id"],"miesiac"=>$r["miesiac"],"podmiot_krs"=>$r["podmiot_krs"],"podmiot_nazwa"=>$r["podmiot_nazwa"],"podmiot_nip"=>$r["podmiot_nip"],"podmiot_regon"=>$r["podmiot_regon"],"podmiot_adres"=>$r["podmiot_adres"],"liczba_godzin"=>$r["liczba_godzin"]], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
              <?php if (is_admin()): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć wpis?');">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="f_miesiac" value="<?= h($f_miesiac) ?>"><input type="hidden" name="f_status" value="<?= h($f_status) ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
              <?php endif; /* can_write */ ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_write): ?>
<!-- Modal: dodaj / edytuj -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content" id="ouForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="create" id="ouOp">
      <input type="hidden" name="id" value="" id="ouId">
      <input type="hidden" name="f_miesiac" value="<?= h($f_miesiac) ?>"><input type="hidden" name="f_status" value="<?= h($f_status) ?>">
      <input type="hidden" name="podmiot_nip" id="ouNip">
      <input type="hidden" name="podmiot_regon" id="ouRegon">
      <input type="hidden" name="podmiot_adres" id="ouAdres">
      <div class="modal-header py-2"><h2 class="modal-title h6 mb-0" id="ouTitle">Nowy wpis</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold small">Podmiot — wyszukaj po KRS</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">KRS</span>
            <input type="text" class="form-control" id="ouKrs" name="podmiot_krs" inputmode="numeric" maxlength="10" placeholder="np. 0000123456">
            <button type="button" class="btn btn-outline-primary" id="ouKrsBtn"><i class="bi bi-search me-1"></i>Szukaj</button>
          </div>
          <div id="ouKrsMsg" class="small mt-1"></div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Nazwa podmiotu</label>
          <input type="text" class="form-control form-control-sm" name="podmiot_nazwa" id="ouNazwa" placeholder="uzupełni się po wyszukaniu KRS" required>
        </div>
        <div class="row g-2 mb-1">
          <div class="col-6">
            <label class="form-label fw-semibold small">Miesiąc</label>
            <input type="month" class="form-control form-control-sm" name="miesiac" id="ouMies" value="<?= h(wsparcie_ou_default_month()) ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small">Liczba godzin</label>
            <input type="number" step="0.25" min="0" class="form-control form-control-sm" name="liczba_godzin" id="ouGodz" value="0">
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm">Zapisz</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: odrzuć -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="reject">
      <input type="hidden" name="id" id="rejId"><input type="hidden" name="f_miesiac" value="<?= h($f_miesiac) ?>"><input type="hidden" name="f_status" value="<?= h($f_status) ?>">
      <div class="modal-header py-2"><h2 class="modal-title h6 mb-0">Odrzucenie wpisu</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Wpis: <strong id="rejLbl"></strong></p>
        <label class="form-label fw-semibold small">Powód odrzucenia <span class="text-danger">*</span></label>
        <textarea name="powod_odrzucenia" class="form-control" rows="3" required></textarea>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-lg me-1"></i>Odrzuć</button>
      </div>
    </form>
  </div>
</div>
<?php endif; /* can_write — modale */ ?>

<script>
(function () {
  var APP = <?= json_encode(APP_URL) ?>;
  // Wyszukiwarka KRS
  var krsBtn = document.getElementById('ouKrsBtn');
  function fillEmpty(v){ return v || ''; }
  krsBtn && krsBtn.addEventListener('click', function () {
    var krs = (document.getElementById('ouKrs').value || '').replace(/\D/g, '');
    var msg = document.getElementById('ouKrsMsg');
    if (!krs) { msg.innerHTML = '<span class="text-danger">Podaj numer KRS.</span>'; return; }
    msg.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Szukam w KRS…</span>';
    fetch(APP + '/api/krs.php?krs=' + encodeURIComponent(krs), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || res.j.error) { msg.innerHTML = '<span class="text-danger">' + (res.j.error || 'Nie znaleziono.') + '</span>'; return; }
        var d = res.j;
        document.getElementById('ouNazwa').value = fillEmpty(d.nazwa);
        document.getElementById('ouNip').value   = fillEmpty(d.nip);
        document.getElementById('ouRegon').value = fillEmpty(d.regon);
        document.getElementById('ouAdres').value = fillEmpty(d.adres);
        document.getElementById('ouKrs').value   = fillEmpty(d.krs);
        msg.innerHTML = '<span class="text-success"><i class="bi bi-check-lg me-1"></i>' + fillEmpty(d.nazwa) + (d.rejestr_label ? ' · ' + d.rejestr_label : '') + '</span>';
      })
      .catch(function () { msg.innerHTML = '<span class="text-danger">Błąd połączenia z API KRS.</span>'; });
  });

  // Dodaj vs edytuj
  var addModal = document.getElementById('addModal');
  addModal && addModal.addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget, ed = b && b.getAttribute('data-edit');
    var msg = document.getElementById('ouKrsMsg'); msg.innerHTML = '';
    if (ed) {
      var d = JSON.parse(ed);
      document.getElementById('ouTitle').textContent = 'Edytuj wpis';
      document.getElementById('ouOp').value = 'update';
      document.getElementById('ouId').value = d.id;
      document.getElementById('ouKrs').value = d.podmiot_krs || '';
      document.getElementById('ouNazwa').value = d.podmiot_nazwa || '';
      document.getElementById('ouNip').value = d.podmiot_nip || '';
      document.getElementById('ouRegon').value = d.podmiot_regon || '';
      document.getElementById('ouAdres').value = d.podmiot_adres || '';
      document.getElementById('ouMies').value = d.miesiac || '';
      document.getElementById('ouGodz').value = d.liczba_godzin || 0;
    } else {
      document.getElementById('ouTitle').textContent = 'Nowy wpis';
      document.getElementById('ouOp').value = 'create';
      document.getElementById('ouId').value = '';
      document.getElementById('ouForm').reset();
      document.getElementById('ouMies').value = <?= json_encode(wsparcie_ou_default_month()) ?>;
    }
  });

  // Odrzuć
  var rejModal = document.getElementById('rejectModal');
  rejModal && rejModal.addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget;
    document.getElementById('rejId').value = b ? b.getAttribute('data-id') : '';
    document.getElementById('rejLbl').textContent = b ? b.getAttribute('data-lbl') : '';
  });
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
