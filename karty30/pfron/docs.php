<?php
/**
 * karty30/pfron/docs.php — Generowanie dokumentów PFRON (umowa + regulamin) do druku/PDF.
 *
 * Przyjmuje ?pfron_id=X (umowa PFRON) lub ?client_id=X (klient bez konkretnej umowy).
 * Pola formularza są wstępnie wypełniane z danych klienta i umowy PFRON.
 * Po wysłaniu zapisuje dane do sesji i otwiera stronę druku.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$pfron_id  = (int)($_GET['pfron_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);

$pfron  = $pfron_id  ? k30_pfron_contract_get($pfron_id) : null;
if ($pfron && !$client_id) $client_id = (int)($pfron['client_id'] ?? 0);

$client = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;

// Lista umów PFRON klienta (do wyboru jeśli brak pfron_id)
$pfron_list = $client_id ? k30_pfron_contracts_for_client($client_id) : [];

$PAGE_TITLE = 'Dokumenty PFRON — Karty 30';

// ── Zapis nowych pól z formularza do bazy (PESEL, skierowanie) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_fields') {
        $pid = (int)($_POST['pfron_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);

        if ($cid) {
            $pesel = trim($_POST['pesel'] ?? '');
            db()->prepare("UPDATE k30_clients SET pesel=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$pesel, $cid]);
        }
        if ($pid) {
            db()->prepare(
                "UPDATE k30_pfron_contracts SET main_contract_date=?, main_contract_sign=?,
                 hours_total=?, hours_training=?, updated_at=datetime('now') WHERE id=?"
            )->execute([
                trim($_POST['main_contract_date'] ?? ''),
                trim($_POST['main_contract_sign'] ?? ''),
                max(1, (int)($_POST['hours_total']    ?? 30)),
                max(1, (int)($_POST['hours_training'] ?? 25)),
                $pid,
            ]);
            // Odśwież dane po zapisie
            $pfron  = k30_pfron_contract_get($pid);
            $client = $cid ? db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]) : $client;
        }
    }

    // Zapis danych dokumentu do sesji + redirect na stronę druku
    if ($op === 'print_umowa' || $op === 'print_regulamin' || $op === 'print_oba') {
        $doc_data = [
            'client_name'        => trim($_POST['client_name']        ?? ''),
            'pesel'              => trim($_POST['pesel']               ?? ''),
            'address'            => trim($_POST['address']             ?? ''),
            'phone'              => trim($_POST['phone']               ?? ''),
            'email'              => trim($_POST['email']               ?? ''),
            'contract_date'      => trim($_POST['contract_date']       ?? date('Y-m-d')),
            'pfron_contract_no'  => trim($_POST['pfron_contract_no']   ?? ''),
            'main_contract_date' => trim($_POST['main_contract_date']  ?? ''),
            'main_contract_sign' => trim($_POST['main_contract_sign']  ?? ''),
            'hours_total'        => max(1, (int)($_POST['hours_total']    ?? 30)),
            'hours_training'     => max(1, (int)($_POST['hours_training'] ?? 25)),
            'penalty_amount'     => trim($_POST['penalty_amount']      ?? '100,00'),
            'penalty_words'      => trim($_POST['penalty_words']       ?? 'sto'),
        ];
        $_SESSION['k30_pfron_doc_draft'] = $doc_data;

        if ($op === 'print_regulamin') {
            header('Location: doc_print.php?type=regulamin');
        } else {
            header('Location: doc_print.php?type=umowa');
        }
        exit;
    }
}

// Wartości domyślne dla pól formularza
$f_name        = $client ? ($client['name']  ?? '') : '';
$f_pesel       = $client ? ($client['pesel'] ?? '') : '';
$f_address     = $client ? ($client['address'] ?? '') : '';
$f_phone       = $client ? ($client['phone'] ?? '') : '';
$f_email       = $client ? ($client['email'] ?? '') : '';
$f_pfron_no    = $pfron  ? ($pfron['contract_number'] ?? '') : '';
$f_mc_date     = $pfron  ? ($pfron['main_contract_date'] ?? '') : '';
$f_mc_sign     = $pfron  ? ($pfron['main_contract_sign'] ?? '') : '';
$f_hours_total = $pfron  ? (int)($pfron['hours_total']    ?? 30) : 30;
$f_hours_tr    = $pfron  ? (int)($pfron['hours_training'] ?? 25) : 25;
$f_date        = date('Y-m-d');

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <?php if ($client): ?>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $client_id ?>#pfron">Beneficjent</a></li>
    <?php endif; ?>
    <li class="breadcrumb-item active">Dokumenty PFRON</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <i class="bi bi-file-earmark-pdf fs-3 text-danger" aria-hidden="true"></i>
  <div>
    <h1 class="h5 fw-bold mb-0">Generowanie dokumentów PFRON</h1>
    <p class="text-body-secondary small mb-0">Umowa uczestnictwa w szkoleniu &amp; Regulamin</p>
  </div>
</div>

<?php flash_show(); ?>

<?php if ($client_id && !$pfron_id && $pfron_list): ?>
<div class="alert alert-info d-flex gap-2 align-items-start">
  <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
  <div>
    Wybierz umowę PFRON, aby wstępnie wypełnić pola:
    <?php foreach ($pfron_list as $pl): ?>
      <a href="?pfron_id=<?= (int)$pl['id'] ?>&client_id=<?= $client_id ?>"
         class="badge text-bg-primary text-decoration-none ms-1">
        <?= h($pl['contract_number']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<form method="post" id="pfron-doc-form" novalidate>
  <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_op"        value="" id="form-op">
  <input type="hidden" name="pfron_id"   value="<?= $pfron_id ?>">
  <input type="hidden" name="client_id"  value="<?= $client_id ?>">

  <div class="row g-4">
    <!-- Lewa kolumna: dane uczestnika -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header fw-semibold">
          <i class="bi bi-person me-1" aria-hidden="true"></i>Dane uczestnika
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="f_name">Imię i nazwisko <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="f_name" name="client_name"
                   value="<?= h($f_name) ?>" required autocomplete="name">
          </div>
          <div class="mb-3">
            <label class="form-label" for="f_pesel">PESEL</label>
            <input type="text" class="form-control font-monospace" id="f_pesel" name="pesel"
                   value="<?= h($f_pesel) ?>" maxlength="11" inputmode="numeric"
                   autocomplete="off" placeholder="11-cyfrowy numer PESEL">
            <div class="form-text">Przechowywany zaszyfrowany. Widoczny tylko w wygenerowanym dokumencie.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="f_address">Adres zamieszkania</label>
            <textarea class="form-control" id="f_address" name="address" rows="2"><?= h($f_address) ?></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="f_phone">Telefon</label>
              <input type="tel" class="form-control" id="f_phone" name="phone"
                     value="<?= h($f_phone) ?>" autocomplete="tel">
            </div>
            <div class="col-6">
              <label class="form-label" for="f_email">E-mail</label>
              <input type="email" class="form-control" id="f_email" name="email"
                     value="<?= h($f_email) ?>" autocomplete="email">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Prawa kolumna: dane umowy -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header fw-semibold">
          <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Dane umowy PFRON
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="f_date">Data zawarcia umowy <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="f_date" name="contract_date"
                     value="<?= h($f_date) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label" for="f_pfron_no">Numer umowy PFRON</label>
              <input type="text" class="form-control font-monospace" id="f_pfron_no" name="pfron_contract_no"
                     value="<?= h($f_pfron_no) ?>" placeholder="np. PFRON/2026/0001">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="f_mc_date">Data umowy głównej / skierowania</label>
              <input type="date" class="form-control" id="f_mc_date" name="main_contract_date"
                     value="<?= h($f_mc_date) ?>">
            </div>
            <div class="col-6">
              <label class="form-label" for="f_mc_sign">Znak sprawy</label>
              <input type="text" class="form-control" id="f_mc_sign" name="main_contract_sign"
                     value="<?= h($f_mc_sign) ?>" placeholder="np. ZS.042.12.2026">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label" for="f_ht">Łącznie godzin</label>
              <div class="input-group input-group-sm">
                <input type="number" class="form-control" id="f_ht" name="hours_total"
                       value="<?= $f_hours_total ?>" min="1" max="500">
                <span class="input-group-text">h</span>
              </div>
            </div>
            <div class="col-4">
              <label class="form-label" for="f_htr">Godzin szkolenia</label>
              <div class="input-group input-group-sm">
                <input type="number" class="form-control" id="f_htr" name="hours_training"
                       value="<?= $f_hours_tr ?>" min="1" max="500">
                <span class="input-group-text">h</span>
              </div>
            </div>
            <div class="col-4">
              <label class="form-label" for="f_penalty">Kara/h (zł)</label>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="f_penalty" name="penalty_amount"
                       value="100,00">
                <span class="input-group-text">zł</span>
              </div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="f_penalty_words">Kara słownie</label>
            <input type="text" class="form-control form-control-sm" id="f_penalty_words" name="penalty_words"
                   value="sto" placeholder="np. sto">
          </div>
          <?php if ($pfron_id): ?>
          <button type="submit" form="pfron-doc-form" class="btn btn-sm btn-outline-secondary mt-1"
                  onclick="setOp('save_fields')">
            <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz dane pomocnicze (PESEL, skierowanie)
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Przyciski generowania -->
  <div class="card mt-4">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <span class="fw-semibold me-1"><i class="bi bi-printer me-1" aria-hidden="true"></i>Generuj dokument:</span>
      <button type="submit" class="btn btn-danger" onclick="setOp('print_umowa')">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Umowa + Regulamin (do druku)
      </button>
      <button type="submit" class="btn btn-outline-secondary" onclick="setOp('print_regulamin')">
        <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Sam regulamin
      </button>
      <span class="text-body-secondary small ms-auto">Regulamin drukuje się automatycznie jako załącznik do umowy</span>
    </div>
  </div>
</form>

<script>
function setOp(op) {
  document.getElementById('form-op').value = op;
}
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
