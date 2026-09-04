<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_role('upload');
edok_migrate();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $typ_dokumentu    = $_POST['typ_dokumentu'] ?? '';
    $description      = trim($_POST['description'] ?? '');
    $kontrahent_nazwa = trim($_POST['kontrahent_nazwa'] ?? '');
    $kontrahent_nip   = preg_replace('/\D/', '', trim($_POST['kontrahent_nip'] ?? ''));
    $nr_faktury       = trim($_POST['nr_faktury'] ?? '');
    $data_wystawienia = trim($_POST['data_wystawienia'] ?? '');
    $data_sprzedazy   = trim($_POST['data_sprzedazy'] ?? '');
    $data_wplywu      = trim($_POST['data_wplywu'] ?? '') ?: date('Y-m-d');
    $kwota_netto      = trim($_POST['kwota_netto'] ?? '');
    $kwota_vat        = trim($_POST['kwota_vat'] ?? '');
    $kwota_brutto     = trim($_POST['kwota_brutto'] ?? '');
    $waluta           = $_POST['waluta'] ?? 'PLN';
    $rodzaj           = $_POST['rodzaj_dzialalnosci'] ?? '';
    $projekt          = trim($_POST['projekt'] ?? '');
    $mpk              = trim($_POST['mpk'] ?? '');

    if (!isset(EDOK_TYPES[$typ_dokumentu]))               $errors[] = 'Wybierz typ dokumentu.';
    if ($description === '')                              $errors[] = 'Uzupełnij opis wydatku — jest wymagany do kontroli merytorycznej.';
    if ($kontrahent_nazwa === '')                          $errors[] = 'Podaj nazwę kontrahenta.';
    if ($kontrahent_nip !== '' && !edok_nip_valid($kontrahent_nip)) $errors[] = 'NIP kontrahenta ma nieprawidłową sumę kontrolną.';
    if ($nr_faktury === '')                                $errors[] = 'Podaj numer dokumentu.';
    $brutto_num = (float) str_replace([' ', ','], ['', '.'], $kwota_brutto);
    if ($brutto_num <= 0)                                  $errors[] = 'Podaj kwotę brutto większą od zera.';

    // Dokument źródłowy: albo ręczny upload, albo XML pobrany z KSeF (edok/ksef_fetch.php)
    // i wskazany w ukrytym polu ksef_file_path — walidujemy, że wskazuje na plik faktycznie
    // zapisany w uploads/edok_docs/ (bez wychodzenia poza ten katalog).
    $ksef_file_path = trim($_POST['ksef_file_path'] ?? '');
    if ($ksef_file_path !== '') {
        $abs = realpath(UPLOAD_DIR . $ksef_file_path);
        $base = realpath(UPLOAD_DIR . 'edok_docs');
        if (!$abs || !$base || !str_starts_with($abs, $base)) $ksef_file_path = '';
    }
    if ($ksef_file_path === '' && empty($_FILES['file']['tmp_name'])) {
        $errors[] = 'Skan dokumentu źródłowego jest wymagany — bez niego kontrola merytoryczna nie może się rozpocząć.';
    }
    if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rodzaj]))          $errors[] = 'Wybierz rodzaj działalności (projekt/działanie, statutowa odpłatna lub nieodpłatna).';
    if ($rodzaj === 'projekt' && $projekt === '')           $errors[] = 'Przy rodzaju „Projekt / działanie” podaj nazwę projektu.';

    $file_path = null;
    if (!$errors) {
        $file_path = $ksef_file_path !== '' ? $ksef_file_path : handle_upload('file', 'edok_docs');
        if (!$file_path) $errors[] = 'Nie udało się zapisać pliku (dozwolone: PDF, JPG, PNG, DOCX, max 20 MB).';
    }

    if (!$errors) {
        $user = current_user();
        $number = edok_next_number();
        $doc_id = db_insert('edok_documents', [
            'number'              => $number,
            'title'               => $nr_faktury !== '' ? $nr_faktury : $number,
            'typ_dokumentu'       => $typ_dokumentu,
            'description'         => $description,
            'kontrahent_nazwa'    => $kontrahent_nazwa,
            'kontrahent_nip'      => $kontrahent_nip,
            'nr_faktury'          => $nr_faktury,
            'data_wystawienia'    => $data_wystawienia ?: null,
            'data_sprzedazy'      => $data_sprzedazy ?: null,
            'data_wplywu'         => $data_wplywu ?: null,
            'kwota_netto'         => $kwota_netto,
            'kwota_vat'           => $kwota_vat,
            'kwota_brutto'        => $kwota_brutto,
            'waluta'              => $waluta ?: 'PLN',
            'rodzaj_dzialalnosci' => $rodzaj,
            'projekt'             => $projekt,
            'mpk'                 => $mpk,
            'file_path'           => $file_path,
            'file_size'           => is_file(UPLOAD_DIR . $file_path) ? filesize(UPLOAD_DIR . $file_path) : null,
            'status'              => 'w_obiegu',
            'created_by'          => (int)$user['id'],
            'creator_name'        => $user['name'] ?? '',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        edok_log($doc_id, 'submit', '', 'draft', 'w_obiegu', 'Dokument ' . $number . ' złożony do obiegu akceptacji przez ' . ($user['name'] ?? '—') . '.');

        flash_set('success', 'Dokument ' . $number . ' złożony do obiegu akceptacji.');
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $doc_id);
        exit;
    }
}

$PAGE_TITLE = 'Nowy dokument — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-journal-plus"></i> Nowy dokument księgowy — EODoK</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:760px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" id="edok-add-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="ksef_file_path" id="ksef_file_path" value="">

      <?php if (org_setting('kdok_ksef_enabled') === '1'): ?>
      <div class="mb-3 p-2 rounded border bg-light">
        <label class="form-label small fw-semibold mb-1"><i class="bi bi-cloud-download"></i> Pobierz z KSeF</label>
        <div class="input-group input-group-sm">
          <input type="text" id="ksef_ref" class="form-control" placeholder="Numer referencyjny KSeF">
          <button type="button" class="btn btn-outline-primary" id="ksef_fetch_btn" onclick="edokKsefFetch()">Pobierz i uzupełnij</button>
        </div>
        <div id="ksef_fetch_status" class="form-text"></div>
      </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Typ dokumentu</label>
          <select name="typ_dokumentu" class="form-select" required>
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_TYPES as $k => $l): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['typ_dokumentu'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Numer dokumentu</label>
          <input type="text" name="nr_faktury" class="form-control" maxlength="100"
            value="<?= h($_POST['nr_faktury'] ?? '') ?>" placeholder="np. FV/2026/01/001" required>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Opis wydatku <span class="text-muted fw-normal">(kontrola merytoryczna)</span></label>
        <textarea name="description" class="form-control" rows="2" required
          placeholder="Cel wydatku, potwierdzenie wykonania usługi/dostawy…"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-8">
          <label class="form-label">Kontrahent</label>
          <input type="text" name="kontrahent_nazwa" class="form-control" maxlength="255"
            value="<?= h($_POST['kontrahent_nazwa'] ?? '') ?>" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label">NIP kontrahenta</label>
          <input type="text" name="kontrahent_nip" class="form-control" maxlength="13"
            value="<?= h($_POST['kontrahent_nip'] ?? '') ?>" placeholder="9999999999">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label">Data wystawienia</label>
          <input type="date" name="data_wystawienia" class="form-control" value="<?= h($_POST['data_wystawienia'] ?? '') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Data sprzedaży / wykonania</label>
          <input type="date" name="data_sprzedazy" class="form-control" value="<?= h($_POST['data_sprzedazy'] ?? '') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Data wpływu</label>
          <input type="date" name="data_wplywu" class="form-control" value="<?= h($_POST['data_wplywu'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="row g-3 mb-2">
        <div class="col-sm-4">
          <label class="form-label">Kwota netto</label>
          <input type="text" id="kwota_netto" name="kwota_netto" class="form-control text-end font-monospace"
            value="<?= h($_POST['kwota_netto'] ?? '') ?>" placeholder="0,00" oninput="edokRecalc()">
        </div>
        <div class="col-sm-4">
          <label class="form-label">Kwota VAT</label>
          <input type="text" id="kwota_vat" name="kwota_vat" class="form-control text-end font-monospace"
            value="<?= h($_POST['kwota_vat'] ?? '') ?>" placeholder="0,00" oninput="edokRecalc()">
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Kwota brutto</label>
          <div class="input-group">
            <input type="text" id="kwota_brutto" name="kwota_brutto" class="form-control text-end font-monospace fw-semibold"
              value="<?= h($_POST['kwota_brutto'] ?? '') ?>" placeholder="0,00">
            <select name="waluta" class="form-select" style="max-width:90px">
              <?php foreach (['PLN','EUR','USD','CHF','GBP'] as $w): ?>
              <option value="<?= $w ?>" <?= ($_POST['waluta'] ?? 'PLN') === $w ? 'selected' : '' ?>><?= $w ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="form-text mb-3"><i class="bi bi-magic"></i> Kwota brutto liczy się automatycznie z netto + VAT (można ją nadpisać ręcznie).</div>

      <hr>
      <h6 class="text-muted"><i class="bi bi-journal-bookmark"></i> Dekretacja</h6>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Rodzaj działalności</label>
          <select name="rodzaj_dzialalnosci" id="rodzaj_dzialalnosci" class="form-select" required onchange="edokToggleProjekt()">
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['rodzaj_dzialalnosci'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6" id="projekt_wrap">
          <label class="form-label">Nazwa projektu / działania</label>
          <input type="text" name="projekt" class="form-control" maxlength="200" value="<?= h($_POST['projekt'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">MPK <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" name="mpk" class="form-control" maxlength="100" value="<?= h($_POST['mpk'] ?? '') ?>">
      </div>

      <div class="mb-3" id="file_upload_wrap">
        <label class="form-label">Skan dokumentu źródłowego</label>
        <input type="file" name="file" id="file_input" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
        <div class="form-text">PDF, JPG, PNG lub DOCX, max 20 MB.</div>
      </div>
      <div class="mb-3 alert alert-success py-2" id="ksef_file_attached" style="display:none">
        <i class="bi bi-check-circle-fill"></i> Dokument źródłowy pobrany z KSeF (XML) — nie trzeba wgrywać skanu.
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Złóż do obiegu akceptacji</button>
    </form>
  </div>
</div>

<script>
function edokRecalc() {
  var netto = parseFloat((document.getElementById('kwota_netto').value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var vat   = parseFloat((document.getElementById('kwota_vat').value   || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var brutto = document.getElementById('kwota_brutto');
  if (netto + vat > 0) brutto.value = (netto + vat).toFixed(2).replace('.', ',');
}
function edokToggleProjekt() {
  var v = document.getElementById('rodzaj_dzialalnosci').value;
  document.getElementById('projekt_wrap').style.display = (v === 'projekt') ? '' : 'none';
}
edokToggleProjekt();

function edokKsefFetch() {
  var ref = document.getElementById('ksef_ref').value.trim();
  var status = document.getElementById('ksef_fetch_status');
  var btn = document.getElementById('ksef_fetch_btn');
  if (!ref) { status.textContent = 'Podaj numer referencyjny KSeF.'; status.className = 'form-text text-danger'; return; }

  btn.disabled = true;
  status.textContent = 'Pobieranie z KSeF…';
  status.className = 'form-text text-muted';

  var fd = new FormData();
  fd.append('_csrf', document.querySelector('#edok-add-form input[name="_csrf"]').value);
  fd.append('ksef_reference', ref);

  fetch('<?= APP_URL ?>/edok/ksef_fetch.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      btn.disabled = false;
      if (!res.ok) { status.textContent = res.error || 'Błąd pobierania.'; status.className = 'form-text text-danger'; return; }

      var d = res.data;
      var set = function (id, val) { var el = document.getElementById(id); if (el && val) el.value = val; };
      set('kwota_brutto', d.kwota_brutto);
      document.querySelector('[name="typ_dokumentu"]').value = d.typ_dokumentu || 'faktura_vat';
      document.querySelector('[name="nr_faktury"]').value = d.nr_faktury || '';
      document.querySelector('[name="kontrahent_nazwa"]').value = d.kontrahent_nazwa || '';
      document.querySelector('[name="kontrahent_nip"]').value = d.kontrahent_nip || '';
      document.querySelector('[name="waluta"]').value = d.waluta || 'PLN';
      document.querySelector('[name="data_wystawienia"]').value = d.data_wystawienia || '';
      document.querySelector('[name="description"]').value = d.description || '';

      document.getElementById('ksef_file_path').value = res.file_path || '';
      document.getElementById('file_input').required = false;
      document.getElementById('file_upload_wrap').style.display = 'none';
      document.getElementById('ksef_file_attached').style.display = '';

      status.textContent = 'Uzupełniono dane z KSeF. Sprawdź i uzupełnij netto/VAT oraz dekretację.';
      status.className = 'form-text text-success';
    })
    .catch(function () {
      btn.disabled = false;
      status.textContent = 'Błąd sieci przy pobieraniu z KSeF.';
      status.className = 'form-text text-danger';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
