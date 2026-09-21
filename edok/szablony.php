<?php
/**
 * edok/szablony.php
 * Szablony dokumentów EODoK — powtarzalne wydatki/przychody (stały czynsz,
 * cykliczna darowizna itp.). Szablon tylko wypełnia formularz edok/add.php
 * (patrz edokApplyTemplate() tam) — nie tworzy dokumentu samodzielnie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/crm_offers.php';

edok_require_role('upload');
edok_migrate();
edok_templates_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('error', 'Podaj nazwę szablonu.');
        } else {
            $existing = $id > 0 ? edok_get_template($id) : null;
            if ($id > 0 && !$existing) {
                flash_set('error', 'Szablon nie istnieje.');
            } else {
                edok_template_save($_POST, $id);
                flash_set('success', $id > 0 ? 'Szablon zaktualizowany.' : 'Szablon utworzony.');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $t  = edok_get_template($id);
        if ($t) edok_template_set_active($id, !$t['is_active']);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        edok_template_delete($id);
        flash_set('success', 'Szablon usunięty.');
    }

    header('Location: ' . APP_URL . '/edok/szablony.php');
    exit;
}

$templates = edok_get_templates(false);

$PAGE_TITLE = 'Szablony dokumentów — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>

<style type="text/tailwindcss">
.es-card { @apply tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-rounded-xl tw-p-3 tw-flex tw-flex-col tw-gap-2; }
.es-card.es-inactive { @apply tw-opacity-55; }
.es-badge-kierunek { @apply tw-inline-flex tw-items-center tw-gap-1 tw-text-[.72rem] tw-font-semibold tw-px-2 tw-py-[.15rem] tw-rounded-full; }
.es-badge-wydatek  { @apply tw-bg-red-50 tw-text-red-700; }
.es-badge-przychod { @apply tw-bg-emerald-50 tw-text-emerald-700; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-file-earmark-richtext"></i> Szablony dokumentów</h4>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Gotowe wzorce dla powtarzalnych wydatków i przychodów (np. stały czynsz, cykliczna darowizna) — w <a href="<?= APP_URL ?>/edok/add.php">Nowym dokumencie</a> wystarczy wybrać szablon z listy.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Wróć do EODoK</a>
    <button type="button" class="btn btn-primary btn-sm" onclick="esOpenModal()"><i class="bi bi-plus-lg"></i> Nowy szablon</button>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$templates): ?>
<div class="tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-rounded-xl tw-p-8 tw-text-center tw-text-slate-500">
  <i class="bi bi-file-earmark-richtext tw-text-3xl tw-block tw-mb-2 tw-opacity-40"></i>
  Brak szablonów. Utwórz pierwszy, np. "Czynsz biuro — miesięczny".
</div>
<?php else: ?>
<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-3">
  <?php foreach ($templates as $t): ?>
  <div class="es-card <?= $t['is_active'] ? '' : 'es-inactive' ?>">
    <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
      <div>
        <span class="es-badge-kierunek <?= $t['kierunek'] === 'przychod' ? 'es-badge-przychod' : 'es-badge-wydatek' ?>">
          <i class="bi bi-<?= $t['kierunek'] === 'przychod' ? 'piggy-bank' : 'cash-stack' ?>"></i>
          <?= $t['kierunek'] === 'przychod' ? 'Przychód' : 'Wydatek' ?>
        </span>
        <div class="tw-font-semibold tw-text-slate-900 tw-mt-1"><?= h($t['name']) ?></div>
        <div class="tw-text-[.8rem] tw-text-slate-500"><?= h(EDOK_TYPES[$t['typ_dokumentu']] ?? '—') ?><?= $t['kontrahent_nazwa'] !== '' ? ' · ' . h($t['kontrahent_nazwa']) : '' ?></div>
        <?php if ($t['kwota_brutto'] !== ''): ?>
        <div class="tw-text-[.8rem] tw-text-slate-500">Domyślna kwota: <span class="tw-font-mono"><?= h($t['kwota_brutto']) ?> <?= h($t['waluta']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php if (!$t['is_active']): ?>
      <span class="badge bg-secondary" style="font-size:.65rem">Nieaktywny</span>
      <?php endif; ?>
    </div>

    <?php if ($t['description'] !== ''): ?>
    <div class="tw-text-[.8rem] tw-text-slate-600 tw-bg-slate-50 tw-rounded-lg tw-px-2 tw-py-1"><?= h($t['description']) ?></div>
    <?php endif; ?>

    <div class="tw-flex tw-gap-2 tw-pt-2 tw-border-t tw-border-solid tw-border-slate-100">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick='esOpenModal(<?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>)'>
        <i class="bi bi-pencil"></i> Edytuj
      </button>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>-fill"></i> <?= $t['is_active'] ? 'Wyłącz' : 'Włącz' ?></button>
      </form>
      <form method="post" class="d-inline tw-ml-auto" onsubmit="return confirm('Usunąć szablon &quot;<?= h(addslashes($t['name'])) ?>&quot;?');">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal: dodaj/edytuj szablon ─────────────────────────────────────────── -->
<div class="modal fade" id="esModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="esForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="es_id" value="0">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold mb-0" id="esModalTitle"><i class="bi bi-file-earmark-richtext me-1"></i>Nowy szablon</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-8">
              <label class="form-label">Nazwa szablonu <span class="text-danger">*</span></label>
              <input type="text" name="name" id="es_name" class="form-control" maxlength="150" placeholder="np. Czynsz biuro — miesięczny" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Kierunek</label>
              <select name="kierunek" id="es_kierunek" class="form-select" onchange="esToggleKierunek()">
                <option value="wydatek">Wydatek</option>
                <option value="przychod">Przychód</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label">Typ dokumentu</label>
              <select name="typ_dokumentu" id="es_typ_dokumentu" class="form-select">
                <option value="">— wybierz —</option>
                <?php foreach (EDOK_TYPES as $k => $l): ?>
                <option value="<?= h($k) ?>" data-kierunek="<?= in_array($k, EDOK_TYPES_PRZYCHOD, true) ? 'przychod' : 'wydatek' ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Rodzaj działalności</label>
              <select name="rodzaj_dzialalnosci" id="es_rodzaj_dzialalnosci" class="form-select">
                <option value="">— wybierz —</option>
                <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
                <option value="<?= h($k) ?>"><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" id="es_description_label">Opis</label>
            <textarea name="description" id="es_description" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3" id="es_zrodlo_wrap" style="display:none">
            <label class="form-label">Źródło przychodu</label>
            <input type="text" name="zrodlo_przychodu" id="es_zrodlo_przychodu" class="form-control" maxlength="255">
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="form-label">Kontrahent</label>
              <input type="text" name="kontrahent_nazwa" id="es_kontrahent_nazwa" class="form-control" maxlength="255">
            </div>
            <div class="col-sm-4">
              <label class="form-label">NIP kontrahenta</label>
              <input type="text" name="kontrahent_nip" id="es_kontrahent_nip" class="form-control" maxlength="13">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Nr rachunku kontrahenta</label>
              <input type="text" name="rachunek_bankowy" id="es_rachunek_bankowy" class="form-control" maxlength="34">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-3">
              <label class="form-label">Domyślna kwota netto</label>
              <input type="text" name="kwota_netto" id="es_kwota_netto" class="form-control text-end font-monospace" placeholder="opcjonalnie">
            </div>
            <div class="col-sm-3">
              <label class="form-label">Stawka VAT</label>
              <select name="stawka_vat" id="es_stawka_vat" class="form-select">
                <option value="">—</option>
                <?php foreach (CRM_OFFER_VAT_RATES as $k => $r): ?>
                <option value="<?= h($k) ?>"><?= h($r['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label">Domyślna kwota VAT</label>
              <input type="text" name="kwota_vat" id="es_kwota_vat" class="form-control text-end font-monospace" placeholder="opcjonalnie">
            </div>
            <div class="col-sm-3">
              <label class="form-label">Domyślna kwota brutto</label>
              <div class="input-group">
                <input type="text" name="kwota_brutto" id="es_kwota_brutto" class="form-control text-end font-monospace" placeholder="opcjonalnie">
                <select name="waluta" id="es_waluta" class="form-select" style="max-width:90px">
                  <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
                  <option value="<?= $w ?>"><?= $w ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="form-text mb-3">Kwoty są opcjonalne — zostaw puste, gdy zmieniają się przy każdym wystąpieniu (np. rachunek za media); wypełnij, gdy kwota jest stała (np. czynsz ryczałtowy).</div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label">Nazwa projektu / działania</label>
              <input type="text" name="projekt" id="es_projekt" class="form-control" maxlength="200">
            </div>
            <div class="col-sm-6">
              <label class="form-label">MPK <span class="text-muted fw-normal">(opcjonalnie)</span></label>
              <input type="text" name="mpk" id="es_mpk" class="form-control" maxlength="100">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var esModal = new bootstrap.Modal(document.getElementById('esModal'));
var ES_TYPY_PRZYCHOD = <?= json_encode(EDOK_TYPES_PRZYCHOD) ?>;

function esToggleKierunek() {
  var kierunek = document.getElementById('es_kierunek').value;
  var typSel = document.getElementById('es_typ_dokumentu');
  Array.from(typSel.options).forEach(function (o) {
    if (!o.value) return;
    var match = o.dataset.kierunek === kierunek;
    o.hidden = !match;
    if (!match && o.selected) typSel.value = '';
  });
  document.getElementById('es_description_label').textContent = kierunek === 'przychod' ? 'Opis przychodu' : 'Opis wydatku';
  document.getElementById('es_zrodlo_wrap').style.display = kierunek === 'przychod' ? '' : 'none';
}

function esOpenModal(t) {
  document.getElementById('esForm').reset();
  var fields = ['id','name','kierunek','typ_dokumentu','description','zrodlo_przychodu','kontrahent_nazwa',
    'kontrahent_nip','rachunek_bankowy','kwota_netto','stawka_vat','kwota_vat','kwota_brutto','waluta',
    'rodzaj_dzialalnosci','projekt','mpk'];
  fields.forEach(function (f) {
    var el = document.getElementById('es_' + f);
    if (el) el.value = (t && t[f] !== undefined) ? t[f] : (f === 'kierunek' ? 'wydatek' : (f === 'waluta' ? 'PLN' : ''));
  });
  document.getElementById('es_id').value = t ? t.id : 0;
  document.getElementById('esModalTitle').innerHTML = '<i class="bi bi-file-earmark-richtext me-1"></i>' + (t ? 'Edytuj szablon' : 'Nowy szablon');
  esToggleKierunek();
  esModal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
