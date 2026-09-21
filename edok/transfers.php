<?php
/**
 * edok/transfers.php — Przelewy własne / przesunięcia po rachunkach i klasyfikacjach.
 * Zestawienie (nie obieg akceptacji): kto, kiedy, z jakiego rachunku/klasyfikacji
 * na jaki i dlaczego. Jeden wpis = jeden zarejestrowany fakt.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$user     = current_user();
$can_add  = is_admin() || edok_has_role('upload') || edok_has_role('dekretacja') || edok_has_role('ksiegowy');
$rachunki = edok_rachunki_list();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_add) {
        flash_set('danger', 'Brak uprawnień do rejestrowania przesunięć.');
        header('Location: ' . APP_URL . '/edok/transfers.php');
        exit;
    }

    $data_przelewu = trim($_POST['data_przelewu'] ?? '') ?: date('Y-m-d');
    $z_nrb  = trim($_POST['rachunek_z_nrb']  ?? '');
    $do_nrb = trim($_POST['rachunek_do_nrb'] ?? '');
    $kwota  = trim($_POST['kwota'] ?? '');
    $waluta = trim($_POST['waluta'] ?? 'PLN');
    $rz     = $_POST['rodzaj_dzialalnosci_z']  ?? '';
    $pz     = trim($_POST['projekt_z']  ?? '');
    $rd     = $_POST['rodzaj_dzialalnosci_do'] ?? '';
    $pd     = trim($_POST['projekt_do'] ?? '');
    $uzasadnienie = trim($_POST['uzasadnienie'] ?? '');

    $find_nazwa = function (string $nrb) use ($rachunki): string {
        foreach ($rachunki as $r) if ($r['nrb'] === $nrb) return ($r['nazwa'] ?: $r['bank']) . ' (' . substr($r['nrb'], -4) . ')';
        return '';
    };

    if ($z_nrb === '' || $do_nrb === '')  $errors[] = 'Wybierz rachunek źródłowy i docelowy.';
    if ($z_nrb !== '' && $z_nrb === $do_nrb) $errors[] = 'Rachunek źródłowy i docelowy muszą się różnić.';
    $kwota_num = (float) str_replace([' ', ','], ['', '.'], $kwota);
    if ($kwota_num <= 0) $errors[] = 'Podaj kwotę przelewu większą od zera.';
    if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rz]) && $rz !== '') $errors[] = 'Nieprawidłowa klasyfikacja źródłowa.';
    if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rd]) && $rd !== '') $errors[] = 'Nieprawidłowa klasyfikacja docelowa.';
    if ($uzasadnienie === '') $errors[] = 'Podaj uzasadnienie przesunięcia — dlaczego środki są przenoszone.';

    if (!$errors) {
        edok_transfer_add([
            'data_przelewu' => $data_przelewu,
            'rachunek_z_nrb' => $z_nrb, 'rachunek_z_nazwa' => $find_nazwa($z_nrb),
            'rachunek_do_nrb' => $do_nrb, 'rachunek_do_nazwa' => $find_nazwa($do_nrb),
            'kwota' => $kwota, 'waluta' => $waluta,
            'rodzaj_dzialalnosci_z' => $rz, 'projekt_z' => $pz,
            'rodzaj_dzialalnosci_do' => $rd, 'projekt_do' => $pd,
            'uzasadnienie' => $uzasadnienie,
        ], (int)$user['id']);
        flash_set('success', 'Przesunięcie zarejestrowane.');
        header('Location: ' . APP_URL . '/edok/transfers.php');
        exit;
    }
}

$transfers = db_all("SELECT * FROM edok_transfers ORDER BY id DESC LIMIT 300");

$PAGE_TITLE = 'Przelewy własne / przesunięcia po kontach — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-arrow-left-right"></i> Przelewy własne / przesunięcia po kontach</h4>
  </div>
</div>
<p class="text-muted small">Zestawienie przesunięć środków między rachunkami bankowymi organizacji — z jakiego na jaki rachunek, jaka klasyfikacja źródłowa i docelowa (projekt / statutowa odpłatna / nieodpłatna) oraz uzasadnienie.</p>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$rachunki): ?>
<div class="alert alert-warning">Brak skonfigurowanych rachunków bankowych organizacji. Dodaj je w <a href="<?= APP_URL ?>/admin/org_settings.php?tab=rachunki">Ustawieniach organizacji → Rachunki</a>.</div>
<?php endif; ?>

<?php if ($can_add && $rachunki): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header py-2"><strong><i class="bi bi-plus-lg"></i> Zarejestruj przesunięcie</strong></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label">Data</label>
          <input type="date" name="data_przelewu" class="form-control" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Kwota</label>
          <input type="text" name="kwota" class="form-control text-end font-monospace" placeholder="0,00">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Waluta</label>
          <select name="waluta" class="form-select">
            <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
            <option value="<?= $w ?>"><?= $w ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row g-3 mb-1">
        <div class="col-md-6">
          <div class="p-2 rounded border bg-light h-100">
            <div class="fw-semibold small text-muted mb-2"><i class="bi bi-arrow-up-right text-danger"></i> Z rachunku / klasyfikacji</div>
            <div class="mb-2">
              <select name="rachunek_z_nrb" id="rachunek_z_nrb" class="form-select form-select-sm" required onchange="this.dataset.autoOdplatna=''">
                <option value="">— wybierz rachunek —</option>
                <?php foreach ($rachunki as $r): ?>
                <option value="<?= h($r['nrb']) ?>" data-dla-odplatnej="<?= !empty($r['dla_odplatnej']) ? '1' : '0' ?>">
                  <?= h($r['nazwa'] ?: $r['bank']) ?> (…<?= h(substr($r['nrb'], -4)) ?>)<?= !empty($r['dla_odplatnej']) ? ' — konto dla działalności odpłatnej' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <select name="rodzaj_dzialalnosci_z" id="rodzaj_dzialalnosci_z" class="form-select form-select-sm" onchange="edokTrToggle(this,'projekt_z_wrap'); edokTrSuggestOdplatna('z')">
                <option value="">— klasyfikacja (opcjonalnie) —</option>
                <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
                <option value="<?= h($k) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="projekt_z_wrap" style="display:none">
              <input type="text" name="projekt_z" class="form-control form-control-sm" placeholder="Nazwa projektu / działania">
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-2 rounded border bg-light h-100">
            <div class="fw-semibold small text-muted mb-2"><i class="bi bi-arrow-down-left text-success"></i> Na rachunek / klasyfikację</div>
            <div class="mb-2">
              <select name="rachunek_do_nrb" id="rachunek_do_nrb" class="form-select form-select-sm" required onchange="this.dataset.autoOdplatna=''">
                <option value="">— wybierz rachunek —</option>
                <?php foreach ($rachunki as $r): ?>
                <option value="<?= h($r['nrb']) ?>" data-dla-odplatnej="<?= !empty($r['dla_odplatnej']) ? '1' : '0' ?>">
                  <?= h($r['nazwa'] ?: $r['bank']) ?> (…<?= h(substr($r['nrb'], -4)) ?>)<?= !empty($r['dla_odplatnej']) ? ' — konto dla działalności odpłatnej' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <select name="rodzaj_dzialalnosci_do" id="rodzaj_dzialalnosci_do" class="form-select form-select-sm" onchange="edokTrToggle(this,'projekt_do_wrap'); edokTrSuggestOdplatna('do')">
                <option value="">— klasyfikacja (opcjonalnie) —</option>
                <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
                <option value="<?= h($k) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="projekt_do_wrap" style="display:none">
              <input type="text" name="projekt_do" class="form-control form-control-sm" placeholder="Nazwa projektu / działania">
            </div>
          </div>
        </div>
      </div>

      <div class="mb-3 mt-3">
        <label class="form-label">Uzasadnienie <span class="text-muted fw-normal">(dlaczego środki są przesuwane)</span></label>
        <textarea name="uzasadnienie" class="form-control" rows="2" required></textarea>
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Zarejestruj przesunięcie</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Data</th>
        <th>Z rachunku / klasyfikacji</th>
        <th></th>
        <th>Na rachunek / klasyfikację</th>
        <th class="text-end">Kwota</th>
        <th>Uzasadnienie</th>
        <th>Kto / kiedy</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$transfers): ?>
      <tr><td colspan="7" class="text-center text-muted py-4">Brak zarejestrowanych przesunięć.</td></tr>
      <?php endif; ?>
      <?php foreach ($transfers as $t): ?>
      <tr>
        <td class="text-nowrap"><?= date_pl($t['data_przelewu']) ?></td>
        <td>
          <div class="font-monospace small"><?= h($t['rachunek_z_nazwa']) ?></div>
          <?php if ($t['rodzaj_dzialalnosci_z']): ?><div class="text-muted small"><?= h(edok_transfer_label_klasyfikacja($t['rodzaj_dzialalnosci_z'], $t['projekt_z'])) ?></div><?php endif; ?>
        </td>
        <td class="text-center text-muted"><i class="bi bi-arrow-right"></i></td>
        <td>
          <div class="font-monospace small"><?= h($t['rachunek_do_nazwa']) ?></div>
          <?php if ($t['rodzaj_dzialalnosci_do']): ?><div class="text-muted small"><?= h(edok_transfer_label_klasyfikacja($t['rodzaj_dzialalnosci_do'], $t['projekt_do'])) ?></div><?php endif; ?>
        </td>
        <td class="text-end font-monospace fw-semibold"><?= h($t['kwota']) ?> <?= h($t['waluta']) ?></td>
        <td class="small"><?= h($t['uzasadnienie']) ?></td>
        <td class="small text-nowrap"><?= h($t['creator_name']) ?><br><span class="text-muted"><?= date_pl($t['created_at']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function edokTrToggle(sel, wrapId) {
  document.getElementById(wrapId).style.display = (sel.value === 'projekt') ? '' : 'none';
}
// Uchwała 5/2026 §6 — gdy klasyfikacja to "działalność odpłatna", podpowiedz (nie wymuszaj)
// rachunek oznaczony jako "dla działalności odpłatnej" w Ustawieniach organizacji, o ile
// pole rachunku nie jest już ustawione ręcznie na inne konto.
function edokTrSuggestOdplatna(side) {
  var rodzaj = document.getElementById('rodzaj_dzialalnosci_' + side);
  var rachunek = document.getElementById('rachunek_' + side + '_nrb');
  if (!rodzaj || !rachunek || rodzaj.value !== 'odplatna') return;
  var match = Array.from(rachunek.options).find(function (o) { return o.dataset.dlaOdplatnej === '1'; });
  if (match && (rachunek.value === '' || rachunek.dataset.autoOdplatna === '1')) {
    rachunek.value = match.value;
    rachunek.dataset.autoOdplatna = '1';
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
