<?php
/**
 * karty30/pfron/docs.php — Kreator dokumentów PFRON (umowa + regulamin).
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

$client     = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;
$pfron_list = $client_id ? k30_pfron_contracts_for_client($client_id) : [];

$PAGE_TITLE = 'Dokumenty PFRON — Karty 30';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'generate') {
        $pid = (int)($_POST['pfron_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);

        // Walidacja PESEL
        $pesel_raw = trim($_POST['pesel'] ?? '');
        if ($pesel_raw !== '') {
            if (!pesel_valid($pesel_raw)) {
                flash_set('danger', 'Podany PESEL jest nieprawidłowy. Sprawdź liczbę cyfr i sumę kontrolną.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }

        // Blokada: jeśli umowa ma już numer dokumentu — nie pozwól nadpisać
        if ($pid) {
            $lock_check = db_one("SELECT doc_number FROM k30_pfron_contracts WHERE id=?", [$pid]);
            if (!empty($lock_check['doc_number'])) {
                flash_set('warning', 'Umowa jest już zarejestrowana (nr ' . $lock_check['doc_number'] . ') i nie może być ponownie wygenerowana.');
                header('Location: doc_print.php?type=umowa&preview=1');
                exit;
            }
        }

        // Zapisz PESEL do klienta jeśli podany
        if ($cid && trim($_POST['pesel'] ?? '') !== '') {
            db()->prepare("UPDATE k30_clients SET pesel=?, updated_at=datetime('now') WHERE id=?")
                ->execute([trim($_POST['pesel']), $cid]);
        }
        // Zapisz pola skierowania do umowy PFRON
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
        }

        $_SESSION['k30_pfron_doc_draft'] = [
            'pfron_id'           => (int)($_POST['pfron_id']          ?? 0),
            'client_id'          => (int)($_POST['client_id']         ?? 0),
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

        // Tryb AJAX (krok 3 kreatora) — zwróć JSON zamiast redirect
        if (!empty($_POST['_ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'pfron_id' => $pid]);
            exit;
        }

        $doc_type = ($_POST['doc_type'] ?? 'umowa') === 'regulamin' ? 'regulamin' : 'umowa';
        if ($doc_type === 'umowa') {
            header('Location: doc_print.php?type=umowa&preview=1');
        } else {
            header('Location: doc_print.php?type=regulamin');
        }
        exit;
    }
}

$f_name        = $client['name']             ?? '';
$f_pesel       = $client['pesel']            ?? '';
$f_address     = $client['address']          ?? '';
$f_phone       = $client['phone']            ?? '';
$f_email       = $client['email']            ?? '';
$f_pfron_no    = $pfron['contract_number']   ?? '';
$f_mc_date     = $pfron['main_contract_date']?? '';
$f_mc_sign     = $pfron['main_contract_sign']?? '';
$f_hours_total = (int)($pfron['hours_total']    ?? 30);
$f_hours_tr    = (int)($pfron['hours_training'] ?? 25);

$doc_locked    = !empty($pfron['doc_number']);
$doc_number    = $pfron['doc_number']  ?? '';
$doc_signed_at = $pfron['signed_at']   ?? '';

// Uruchom kreator od razu tylko gdy mamy kontekst I umowa NIE jest zablokowana
$auto_start = (!$doc_locked && ($pfron_id || $client_id)) ? 'true' : 'false';

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <?php if ($client): ?>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= $client_id ?>#pfron"><?= h($client['name']) ?></a></li>
    <?php endif; ?>
    <li class="breadcrumb-item active">Dokumenty PFRON</li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if (!$pfron_id && !$client_id): ?>
<!-- Strona startowa bez kontekstu -->
<div class="text-center py-5">
  <i class="bi bi-file-earmark-pdf display-4 text-danger mb-3" aria-hidden="true"></i>
  <h1 class="h4 fw-bold mb-2">Dokumenty PFRON</h1>
  <p class="text-body-secondary mb-4">Generowanie umowy uczestnictwa w szkoleniu i regulaminu.</p>
  <button class="btn btn-danger btn-lg" id="btn-start-wizard">
    <i class="bi bi-magic me-2" aria-hidden="true"></i>Uruchom kreator
  </button>
</div>
<?php else: ?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <div>
    <h1 class="h5 fw-bold mb-0">Dokumenty PFRON</h1>
    <?php if ($pfron): ?>
    <p class="text-body-secondary small mb-0">Umowa PFRON: <strong><?= h($pfron['contract_number']) ?></strong></p>
    <?php elseif ($client): ?>
    <p class="text-body-secondary small mb-0">Beneficjent: <strong><?= h($client['name']) ?></strong></p>
    <?php endif; ?>
  </div>

  <?php if ($doc_locked): ?>
  <!-- Zablokowany — umowa zarejestrowana -->
  <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
    <span class="badge text-bg-success d-inline-flex align-items-center gap-1 fs-6 px-3 py-2">
      <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
      <?= h($doc_number) ?>
    </span>
    <?php if ($doc_signed_at): ?>
    <span class="text-body-secondary small"><?= date('d.m.Y H:i', strtotime($doc_signed_at)) ?></span>
    <?php endif; ?>
    <a href="doc_print.php?type=umowa&preview=1" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd i PDF
    </a>
  </div>
  <div class="w-100">
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-0" role="status">
      <i class="bi bi-lock-fill fs-5 flex-shrink-0" aria-hidden="true"></i>
      <div>
        <strong>Umowa zablokowana.</strong>
        Dokument został już podpisany i zarejestrowany — nie można go ponownie wygenerować ani edytować.
        Możesz pobrać PDF lub wgrać nowy skan podpisanego dokumentu na stronie podglądu.
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- Odblokowany — można generować -->
  <button class="btn btn-danger ms-auto" id="btn-start-wizard">
    <i class="bi bi-magic me-2" aria-hidden="true"></i>Generuj dokumenty
  </button>
  <?php if ($client_id && !$pfron_id && $pfron_list): ?>
  <div class="d-flex gap-1 flex-wrap align-items-center">
    <span class="text-body-secondary small">Wybierz umowę:</span>
    <?php foreach ($pfron_list as $pl): ?>
      <a href="?pfron_id=<?= (int)$pl['id'] ?>&client_id=<?= $client_id ?>"
         class="badge text-bg-primary text-decoration-none">
        <?= h($pl['contract_number']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($doc_locked): ?>
<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
<?php exit; ?>
<?php endif; ?>

<!-- ═══════════════════════════ MODAL KREATOR ═══════════════════════════ -->
<div class="modal fade" id="pfronWizard" tabindex="-1" aria-labelledby="wizardLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <!-- Nagłówek z wskaźnikiem kroków -->
      <div class="modal-header pb-0 flex-column align-items-stretch">
        <div class="d-flex align-items-center justify-content-between w-100 mb-2">
          <h2 class="modal-title h6 fw-bold" id="wizardLabel">Kreator dokumentów PFRON</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <!-- Pasek kroków -->
        <div class="d-flex gap-0 mb-0" role="tablist" aria-label="Kroki kreatora" id="wizard-steps">
          <?php
          $steps = [
            1 => ['icon' => 'person',              'label' => 'Uczestnik'],
            2 => ['icon' => 'file-earmark-text',   'label' => 'Umowa'],
            3 => ['icon' => 'file-earmark-pdf',    'label' => 'Dokumenty'],
            4 => ['icon' => 'pen',                 'label' => 'Podpisz'],
            5 => ['icon' => 'shield-check',        'label' => 'Zarząd'],
          ];
          foreach ($steps as $n => $s): ?>
          <button type="button" role="tab"
                  class="wizard-step-btn flex-fill d-flex flex-column align-items-center gap-1 py-2 px-1 border-0 bg-transparent"
                  data-step="<?= $n ?>" aria-selected="<?= $n === 1 ? 'true' : 'false' ?>"
                  aria-controls="wizard-panel-<?= $n ?>" id="wizard-tab-<?= $n ?>">
            <span class="wizard-step-circle d-flex align-items-center justify-content-center rounded-circle"
                  style="width:32px;height:32px;font-size:.85rem">
              <i class="bi bi-<?= $s['icon'] ?>" aria-hidden="true"></i>
            </span>
            <span class="wizard-step-label" style="font-size:.75rem"><?= $s['label'] ?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="post" id="pfron-wizard-form" novalidate>
        <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"       value="generate">
        <input type="hidden" name="pfron_id"  value="<?= $pfron_id ?>" id="pfron-id-input">
        <input type="hidden" name="client_id" value="<?= $client_id ?>">
        <input type="hidden" name="doc_type"  value="umowa" id="doc-type-input">

        <div class="modal-body">

          <!-- ── Krok 1: Dane uczestnika ───────────────────────────────── -->
          <div class="wizard-panel" id="wizard-panel-1" role="tabpanel" aria-labelledby="wizard-tab-1">
            <p class="text-body-secondary small mb-3">
              Dane osobowe uczestnika do umowy. Pola wstępnie uzupełnione z karty beneficjenta.
            </p>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="w_name">Imię i nazwisko <span class="text-danger" aria-label="wymagane">*</span></label>
              <input type="text" class="form-control" id="w_name" name="client_name"
                     value="<?= h($f_name) ?>" required autocomplete="name">
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="w_pesel">PESEL</label>
              <input type="text" class="form-control font-monospace" id="w_pesel" name="pesel"
                     value="<?= h($f_pesel) ?>" maxlength="11" inputmode="numeric"
                     autocomplete="off" placeholder="11 cyfr" pattern="\d{11}">
              <div class="valid-feedback" id="pesel-ok">PESEL poprawny</div>
              <div class="invalid-feedback" id="pesel-err">Nieprawidłowy PESEL (błędna suma kontrolna lub liczba cyfr).</div>
              <div class="form-text mt-1">Zapisywany w karcie beneficjenta. Pojawi się tylko w dokumencie.</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="w_address">Adres zamieszkania</label>
              <textarea class="form-control" id="w_address" name="address" rows="2"><?= h($f_address) ?></textarea>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label fw-semibold" for="w_phone">Telefon</label>
                <input type="tel" class="form-control" id="w_phone" name="phone"
                       value="<?= h($f_phone) ?>" autocomplete="tel">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold" for="w_email">E-mail</label>
                <input type="email" class="form-control" id="w_email" name="email"
                       value="<?= h($f_email) ?>" autocomplete="email">
              </div>
            </div>
          </div>

          <!-- ── Krok 2: Dane umowy ────────────────────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-2" role="tabpanel" aria-labelledby="wizard-tab-2">
            <p class="text-body-secondary small mb-3">
              Parametry umowy PFRON i skierowania.
            </p>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label fw-semibold" for="w_date">Data zawarcia umowy <span class="text-danger" aria-label="wymagane">*</span></label>
                <input type="date" class="form-control" id="w_date" name="contract_date"
                       value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-sm-6">
                <label class="form-label fw-semibold" for="w_pfron_no">Numer umowy PFRON</label>
                <input type="text" class="form-control font-monospace" id="w_pfron_no" name="pfron_contract_no"
                       value="<?= h($f_pfron_no) ?>" placeholder="np. PFRON/2026/0001">
              </div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label fw-semibold" for="w_mc_date">Data umowy głównej / skierowania</label>
                <input type="date" class="form-control" id="w_mc_date" name="main_contract_date"
                       value="<?= h($f_mc_date) ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label fw-semibold" for="w_mc_sign">Znak sprawy</label>
                <input type="text" class="form-control" id="w_mc_sign" name="main_contract_sign"
                       value="<?= h($f_mc_sign) ?>" placeholder="np. ZS.042.12.2026">
              </div>
            </div>
            <hr class="my-3">
            <p class="fw-semibold small mb-2">Parametry szkolenia</p>
            <div class="row g-2 mb-3">
              <div class="col-4">
                <label class="form-label" for="w_ht">Łącznie godzin</label>
                <div class="input-group">
                  <input type="number" class="form-control" id="w_ht" name="hours_total"
                         value="<?= $f_hours_total ?>" min="1" max="500">
                  <span class="input-group-text">h</span>
                </div>
              </div>
              <div class="col-4">
                <label class="form-label" for="w_htr">Godzin szkolenia</label>
                <div class="input-group">
                  <input type="number" class="form-control" id="w_htr" name="hours_training"
                         value="<?= $f_hours_tr ?>" min="1" max="500">
                  <span class="input-group-text">h</span>
                </div>
              </div>
              <div class="col-4">
                <label class="form-label" for="w_penalty">Kara / h</label>
                <div class="input-group">
                  <input type="text" class="form-control" id="w_penalty" name="penalty_amount" value="100,00">
                  <span class="input-group-text">zł</span>
                </div>
              </div>
            </div>
            <div class="row g-2">
              <div class="col-sm-6">
                <label class="form-label" for="w_penalty_words">Kara słownie</label>
                <input type="text" class="form-control" id="w_penalty_words" name="penalty_words"
                       value="sto" placeholder="np. sto">
              </div>
            </div>
          </div>

          <!-- ── Krok 3: Podsumowanie i pobieranie PDF ───────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-3" role="tabpanel" aria-labelledby="wizard-tab-3">
            <p class="text-body-secondary small mb-3">Sprawdź dane i pobierz dokumenty do wydruku.</p>
            <div class="card bg-body-secondary border-0 mb-3">
              <div class="card-body py-3">
                <dl class="row mb-0 small" id="wizard-summary">
                  <dt class="col-sm-4">Uczestnik</dt>   <dd class="col-sm-8" id="sum-name">—</dd>
                  <dt class="col-sm-4">PESEL</dt>        <dd class="col-sm-8" id="sum-pesel">—</dd>
                  <dt class="col-sm-4">Adres</dt>        <dd class="col-sm-8" id="sum-address">—</dd>
                  <dt class="col-sm-4">Telefon / e-mail</dt><dd class="col-sm-8" id="sum-contact">—</dd>
                  <dt class="col-sm-4">Data umowy</dt>   <dd class="col-sm-8" id="sum-date">—</dd>
                  <dt class="col-sm-4">Nr PFRON</dt>     <dd class="col-sm-8" id="sum-pfron-no">—</dd>
                  <dt class="col-sm-4">Skierowanie</dt>  <dd class="col-sm-8" id="sum-mc">—</dd>
                  <dt class="col-sm-4">Godziny</dt>      <dd class="col-sm-8" id="sum-hours">—</dd>
                </dl>
              </div>
            </div>
            <div id="step3-save-status" class="mb-3" style="display:none"></div>

            <!-- Przyciski PDF — widoczne po zapisaniu danych -->
            <div id="step3-pdf-btns" style="display:none">
              <div class="d-flex gap-2 flex-wrap mb-3">
                <a id="btn-pdf-umowa-wiz" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=umowa" target="_blank"
                   class="btn btn-danger">
                  <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Pobierz do podpisu (Umowa + Regulamin)
                </a>
                <a id="btn-pdf-reg-wiz" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=regulamin" target="_blank"
                   class="btn btn-outline-secondary btn-sm align-self-center">
                  <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Sam regulamin
                </a>
              </div>
              <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-0">
                <i class="bi bi-arrow-right-circle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
                <div class="small">
                  <strong>Wydrukuj umowę i daj uczestnikowi do podpisania.</strong><br>
                  Gdy masz podpisany dokument — kliknij <strong>Dalej</strong>, aby przejść do kroku 4 (wgranie skanu lub podpis na ekranie).
                </div>
              </div>
            </div>

            <p class="text-body-secondary mt-3 mb-0 small" id="step3-hint-before-save">
              <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
              Kliknij „Dalej" — dane zostaną zapisane i pojawi się przycisk pobierania PDF.
            </p>
          </div>

          <!-- ── Krok 4: Podpisz i wgraj ──────────────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-4" role="tabpanel" aria-labelledby="wizard-tab-4">
            <p class="text-body-secondary small mb-3">
              Wydrukuj umowę, daj uczestnikowi do podpisania, następnie wgraj skan poniżej.
              Możesz też zebrać podpis bezpośrednio na tablecie / ekranie.
            </p>

            <!-- Linki PDF (powtórzone dla wygody) -->
            <div class="d-flex gap-2 mb-4 flex-wrap">
              <a id="btn-pdf-umowa-s4" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=umowa" target="_blank"
                 class="btn btn-outline-danger btn-sm">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF
              </a>
            </div>

            <!-- Upload podpisanego dokumentu -->
            <div class="mb-3">
              <label class="form-label fw-semibold" for="wiz-doc-file">
                Wgraj podpisaną umowę <span class="text-body-secondary fw-normal">(PDF, JPG, PNG — max 20 MB)</span>
              </label>
              <input type="file" class="form-control" id="wiz-doc-file" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
            <div id="wiz-doc-preview" class="mb-2" style="display:none">
              <img id="wiz-doc-img" style="max-height:120px;max-width:100%;border:1px solid #ccc;border-radius:4px" alt="Podgląd">
              <span id="wiz-doc-name" class="d-block text-body-secondary small mt-1"></span>
            </div>

            <!-- Lub podpis odręczny na ekranie -->
            <div class="mt-3 border-top pt-3">
              <p class="small fw-semibold mb-2">
                <i class="bi bi-pen me-1" aria-hidden="true"></i>Alternatywnie: podpis odręczny na ekranie
              </p>
              <canvas id="wiz-sig-canvas" style="width:100%;height:130px;border:1px solid #ccc;border-radius:4px;cursor:crosshair;touch-action:none;background:#fafafa;display:block"
                      role="img" aria-label="Pole podpisu odręcznego"></canvas>
              <div class="d-flex gap-2 mt-1">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="wiz-sig-clear">Wyczyść</button>
                <span class="text-body-secondary small align-self-center">Mysz, rysik lub palec</span>
              </div>
            </div>

            <div class="d-flex gap-2 mt-3 flex-wrap align-items-center" id="wiz-upload-row">
              <button type="button" class="btn btn-primary" id="wiz-upload-btn" disabled>
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Wgraj i zarejestruj
              </button>
              <span id="wiz-upload-status" class="text-body-secondary small"></span>
            </div>
            <div id="wiz-upload-result" class="mt-2" style="display:none"></div>
          </div>

          <!-- ── Krok 5: Akceptacja Zarządu ──────────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-5" role="tabpanel" aria-labelledby="wizard-tab-5">
            <p class="text-body-secondary small mb-3">
              Prześlij umowę do akceptacji przez Zarząd. Administrator otrzyma e-mail z linkiem do dokumentu.
            </p>

            <?php
            $board_status = $pfron['board_approval_status'] ?? '';
            $board_at     = $pfron['board_notified_at']     ?? '';
            ?>

            <?php if ($board_status === 'approved'): ?>
            <div class="alert alert-success d-flex gap-2 align-items-center mb-3">
              <i class="bi bi-shield-fill-check fs-4" aria-hidden="true"></i>
              <div><strong>Zaakceptowana przez Zarząd.</strong>
              <?= $board_at ? ' Powiadomienie wysłano ' . date('d.m.Y H:i', strtotime($board_at)) . '.' : '' ?></div>
            </div>
            <?php elseif ($board_status === 'pending'): ?>
            <div class="alert alert-warning d-flex gap-2 align-items-center mb-3">
              <i class="bi bi-hourglass-split fs-4" aria-hidden="true"></i>
              <div><strong>Oczekuje na akceptację Zarządu.</strong>
              <?= $board_at ? ' Powiadomienie wysłano ' . date('d.m.Y H:i', strtotime($board_at)) . '.' : '' ?>
              Możesz ponownie wysłać powiadomienie.</div>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary d-flex gap-2 align-items-center mb-3">
              <i class="bi bi-envelope fs-4" aria-hidden="true"></i>
              <div>Powiadomienie do Zarządu jeszcze nie wysłane.</div>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2 align-items-center flex-wrap">
              <button type="button" class="btn btn-primary" id="wiz-notify-btn">
                <i class="bi bi-send me-1" aria-hidden="true"></i>
                <?= $board_status === 'pending' ? 'Wyślij ponownie' : 'Wyślij do Zarządu' ?>
              </button>
              <span id="wiz-notify-status" class="text-body-secondary small"></span>
            </div>
            <div id="wiz-notify-result" class="mt-3" style="display:none"></div>

            <hr class="my-4">
            <p class="text-body-secondary small mb-0">
              <i class="bi bi-check2-all me-1" aria-hidden="true"></i>
              Proces zakończony. Możesz zamknąć kreator lub wrócić do poprzednich kroków.
            </p>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-bs-dismiss="modal">
              Zamknij
            </button>
          </div>

        </div><!-- /modal-body -->

        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-secondary" id="wizard-back" disabled>
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz
          </button>
          <div class="d-flex gap-2">
            <span class="text-body-secondary align-self-center small" id="wizard-step-label">Krok 1 z 3</span>
            <button type="button" class="btn btn-primary" id="wizard-next">
              Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<style>
.wizard-step-btn {
  cursor: pointer;
  border-bottom: 3px solid transparent !important;
  transition: border-color .15s, color .15s;
  color: var(--bs-secondary-color);
}
.wizard-step-btn[aria-selected="true"] {
  border-bottom-color: var(--bs-danger) !important;
  color: var(--bs-danger);
}
.wizard-step-btn[aria-selected="true"] .wizard-step-circle {
  background: var(--bs-danger);
  color: #fff;
}
.wizard-step-circle {
  background: var(--bs-secondary-bg);
  transition: background .15s, color .15s;
}
.wizard-step-btn.done .wizard-step-circle {
  background: var(--bs-success);
  color: #fff;
}
.wizard-step-btn.done { color: var(--bs-success); }
</style>

<script>
(function () {
  // ── PESEL ────────────────────────────────────────────────────────────────
  function peselValid(p) {
    p = p.replace(/\D/g, '');
    if (p.length !== 11) return false;
    const w = [1,3,7,9,1,3,7,9,1,3];
    let sum = 0;
    for (let i = 0; i < 10; i++) sum += w[i] * parseInt(p[i]);
    return (10 - (sum % 10)) % 10 === parseInt(p[10]);
  }
  const peselInput = document.getElementById('w_pesel');
  function validatePesel() {
    const v = peselInput.value.replace(/\D/g, '');
    if (!v) { peselInput.classList.remove('is-valid','is-invalid'); return true; }
    const ok = peselValid(v);
    peselInput.classList.toggle('is-valid', ok);
    peselInput.classList.toggle('is-invalid', !ok);
    return ok;
  }
  peselInput?.addEventListener('input', validatePesel);
  peselInput?.addEventListener('blur',  validatePesel);

  // ── Wizard core ──────────────────────────────────────────────────────────
  const TOTAL = 5;
  let current = 1;
  let savedPfronId = parseInt(document.getElementById('pfron-id-input')?.value || '0') || <?= $pfron_id ?: 0 ?>;

  const modal     = document.getElementById('pfronWizard');
  const bsModal   = new bootstrap.Modal(modal);
  const panels    = () => document.querySelectorAll('.wizard-panel');
  const stepBtns  = () => document.querySelectorAll('.wizard-step-btn');
  const backBtn   = document.getElementById('wizard-back');
  const nextBtn   = document.getElementById('wizard-next');
  const stepLabel = document.getElementById('wizard-step-label');

  function showStep(n) {
    current = n;
    panels().forEach((p, i) => p.classList.toggle('d-none', i + 1 !== n));
    stepBtns().forEach((b, i) => {
      const idx = i + 1;
      b.setAttribute('aria-selected', idx === n ? 'true' : 'false');
      b.classList.toggle('done', idx < n);
    });
    backBtn.disabled  = (n === 1);
    const isLast = n === TOTAL;
    nextBtn.style.display = isLast ? 'none' : '';
    if (!isLast) nextBtn.innerHTML = n === 3 && document.getElementById('step3-pdf-btns').style.display !== 'none'
      ? 'Dalej — Podpisz<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>'
      : 'Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
    stepLabel.textContent = `Krok ${n} z ${TOTAL}`;
    if (n === 3) fillSummary();
    if (n === 4) initStep4();
    const first = document.querySelector(`#wizard-panel-${n} input:not([type=file]), #wizard-panel-${n} textarea`);
    if (first) setTimeout(() => first.focus(), 80);
  }

  function validateStep(n) {
    const panel = document.getElementById(`wizard-panel-${n}`);
    const required = panel.querySelectorAll('[required]');
    let ok = true;
    required.forEach(el => {
      el.classList.toggle('is-invalid', !el.value.trim());
      if (!el.value.trim()) ok = false;
    });
    if (n === 1 && !validatePesel()) ok = false;
    return ok;
  }

  function fillSummary() {
    const g = id => document.getElementById(id)?.value || '';
    const blank = v => v || '<span class="text-body-secondary fst-italic">nie podano</span>';
    document.getElementById('sum-name').innerHTML    = blank(g('w_name'));
    document.getElementById('sum-pesel').innerHTML   = blank(g('w_pesel'));
    document.getElementById('sum-address').innerHTML = blank(g('w_address'));
    const ph = [g('w_phone'), g('w_email')].filter(Boolean).join(' / ');
    document.getElementById('sum-contact').innerHTML  = blank(ph);
    document.getElementById('sum-date').innerHTML     = blank(g('w_date'));
    document.getElementById('sum-pfron-no').innerHTML = blank(g('w_pfron_no'));
    const mc = [g('w_mc_date'), g('w_mc_sign')].filter(Boolean).join(' · znak: ');
    document.getElementById('sum-mc').innerHTML    = blank(mc);
    document.getElementById('sum-hours').textContent =
      `${g('w_ht')||'30'} łącznie, ${g('w_htr')||'25'} właściwych`;
  }

  // ── Krok 3 → AJAX save + pokaż przyciski PDF ────────────────────────────
  async function saveStep3() {
    const status = document.getElementById('step3-save-status');
    const pdfRow = document.getElementById('step3-pdf-btns');
    status.style.display = 'block';
    status.className = 'alert alert-info py-2 small';
    status.textContent = 'Zapisywanie danych…';
    nextBtn.disabled = true;

    const fd = new FormData(document.getElementById('pfron-wizard-form'));
    fd.set('_ajax', '1');

    try {
      const res  = await fetch('', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        savedPfronId = data.pfron_id || savedPfronId;
        status.className = 'alert alert-success py-2 small';
        status.textContent = 'Dane zapisane. Pobierz PDF, wydrukuj i daj do podpisania.';
        pdfRow.style.display = 'block';
        document.getElementById('step3-hint-before-save')?.remove();
        nextBtn.innerHTML = 'Dalej — Podpisz<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
        nextBtn.disabled = false;
        return true;
      } else {
        status.className = 'alert alert-danger py-2 small';
        status.textContent = 'Błąd: ' + (data.error || 'nieznany');
        nextBtn.disabled = false;
        return false;
      }
    } catch(e) {
      status.className = 'alert alert-danger py-2 small';
      status.textContent = 'Błąd sieci: ' + e.message;
      nextBtn.disabled = false;
      return false;
    }
  }

  // ── Krok 4: canvas + upload ──────────────────────────────────────────────
  let step4Inited = false;
  let wizCanvasData = null;   // 'canvas' gdy są kreski
  let wizScanFile   = null;   // File ze skanu

  function initStep4() {
    if (step4Inited) return;
    step4Inited = true;

    // Canvas
    const canvas = document.getElementById('wiz-sig-canvas');
    const ctx    = canvas.getContext('2d');
    function resizeCanvas() {
      const r = canvas.getBoundingClientRect(), dpr = devicePixelRatio;
      canvas.width = r.width * dpr; canvas.height = r.height * dpr;
      ctx.scale(dpr, dpr);
      ctx.strokeStyle = '#111'; ctx.lineWidth = 2;
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    let drawing = false;
    function pos(e) {
      const r = canvas.getBoundingClientRect();
      const s = e.touches ? e.touches[0] : e;
      return { x: s.clientX - r.left, y: s.clientY - r.top };
    }
    canvas.addEventListener('mousedown',  e => { e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
    canvas.addEventListener('mousemove',  e => { if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); wizCanvasData='canvas'; checkUploadReady(); });
    canvas.addEventListener('mouseup',    () => drawing=false);
    canvas.addEventListener('mouseleave', () => drawing=false);
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); }, {passive:false});
    canvas.addEventListener('touchmove',  e => { if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); wizCanvasData='canvas'; checkUploadReady(); }, {passive:false});
    canvas.addEventListener('touchend',   () => drawing=false);

    document.getElementById('wiz-sig-clear')?.addEventListener('click', () => {
      ctx.clearRect(0, 0, canvas.width/devicePixelRatio, canvas.height/devicePixelRatio);
      wizCanvasData = null; checkUploadReady();
    });

    // File input
    const fileInput = document.getElementById('wiz-doc-file');
    const preview   = document.getElementById('wiz-doc-preview');
    const previewImg= document.getElementById('wiz-doc-img');
    const previewNm = document.getElementById('wiz-doc-name');
    fileInput?.addEventListener('change', function() {
      wizScanFile = this.files[0] || null;
      if (!wizScanFile) { preview.style.display='none'; checkUploadReady(); return; }
      previewNm.textContent = wizScanFile.name + ' (' + (wizScanFile.size/1024).toFixed(0) + ' KB)';
      if (wizScanFile.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => { previewImg.src = e.target.result; previewImg.style.display=''; preview.style.display='block'; };
        reader.readAsDataURL(wizScanFile);
      } else {
        previewImg.style.display='none'; preview.style.display='block';
      }
      checkUploadReady();
    });

    // Upload button
    document.getElementById('wiz-upload-btn')?.addEventListener('click', doUpload);
  }

  function checkUploadReady() {
    const btn = document.getElementById('wiz-upload-btn');
    if (btn) btn.disabled = !(wizScanFile || wizCanvasData);
  }

  async function doUpload() {
    const btn    = document.getElementById('wiz-upload-btn');
    const status = document.getElementById('wiz-upload-status');
    const result = document.getElementById('wiz-upload-result');
    btn.disabled = true; status.textContent = 'Przesyłanie…';

    const csrf = <?= json_encode(csrf_token()) ?>;
    const pid  = savedPfronId;

    // Przygotuj dane
    const fd = new FormData();
    fd.append('pfron_id', pid);
    fd.append('_csrf', csrf);

    if (wizScanFile) {
      // Priorytet: plik
      fd.append('signed_doc', wizScanFile);
      try {
        const res  = await fetch('upload_doc.php', { method:'POST', body:fd });
        const data = await res.json();
        handleUploadResult(data, result, status, btn);
      } catch(e) { handleNetErr(e, result, status, btn); }
    } else if (wizCanvasData) {
      // Canvas → base64 → sign.php
      const sigData = document.getElementById('wiz-sig-canvas').toDataURL('image/png');
      const body = JSON.stringify({ pfron_id: pid, signature_data: sigData, _csrf: csrf });
      try {
        const res  = await fetch('sign.php', { method:'POST', headers:{'Content-Type':'application/json'}, body });
        const data = await res.json();
        if (data.ok) {
          result.className = 'alert alert-success'; result.style.display='block';
          result.innerHTML = 'Podpis zapisany. Numer umowy: <strong class="font-monospace">' + data.doc_number + '</strong>';
          status.textContent = ''; btn.disabled = true;
        } else handleUploadResult(data, result, status, btn);
      } catch(e) { handleNetErr(e, result, status, btn); }
    }
  }

  function handleUploadResult(data, result, status, btn) {
    if (data.ok) {
      result.className = 'alert alert-success'; result.style.display='block';
      result.innerHTML = data.doc_number
        ? 'Podpis zapisany. Numer: <strong class="font-monospace">' + data.doc_number + '</strong>'
        : 'Plik wgrany: <a href="' + data.url + '" target="_blank" class="fw-semibold">' + data.name + '</a>';
      status.textContent = ''; btn.disabled = true;
    } else if (data.ika_expired) {
      result.className = 'alert alert-warning'; result.style.display='block';
      result.innerHTML = 'Sesja IKA wygasła. <a href="<?= h(APP_URL . '/contracts/ika_gate.php?to=' . urlencode(APP_URL . $_SERVER['REQUEST_URI'])) ?>">Zaloguj się ponownie</a>.';
      status.textContent = ''; btn.disabled = false;
    } else {
      result.className = 'alert alert-danger'; result.style.display='block';
      result.textContent = 'Błąd: ' + (data.error || 'nieznany');
      status.textContent = ''; btn.disabled = false;
    }
  }

  function handleNetErr(e, result, status, btn) {
    result.className = 'alert alert-danger'; result.style.display='block';
    result.textContent = 'Błąd sieci: ' + e.message;
    status.textContent = ''; btn.disabled = false;
  }

  // ── Krok 5: Powiadomienie Zarządu ────────────────────────────────────────
  document.getElementById('wiz-notify-btn')?.addEventListener('click', async function() {
    const btn    = this;
    const status = document.getElementById('wiz-notify-status');
    const result = document.getElementById('wiz-notify-result');
    const pid    = savedPfronId;
    if (!pid) {
      result.className = 'alert alert-warning'; result.style.display='block';
      result.textContent = 'Brak powiązanej umowy PFRON — nie można wysłać powiadomienia.';
      return;
    }
    btn.disabled = true; status.textContent = 'Wysyłanie…';
    const csrf = <?= json_encode(csrf_token()) ?>;
    try {
      const res  = await fetch('notify_board.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ pfron_id: pid, _csrf: csrf }),
      });
      const data = await res.json();
      if (data.ok) {
        result.className = 'alert alert-success'; result.style.display='block';
        result.textContent = `Powiadomienie wysłane do ${data.notified_count} administratora/-ów.`;
        status.textContent = '';
        btn.textContent = 'Wyślij ponownie';
        btn.disabled = false;
      } else if (data.ika_expired) {
        result.className = 'alert alert-warning'; result.style.display='block';
        result.innerHTML = 'Sesja IKA wygasła. <a href="<?= h(APP_URL . '/contracts/ika_gate.php?to=' . urlencode(APP_URL . $_SERVER['REQUEST_URI'])) ?>">Zaloguj się ponownie</a>.';
        status.textContent = ''; btn.disabled = false;
      } else {
        result.className = 'alert alert-danger'; result.style.display='block';
        result.textContent = 'Błąd: ' + (data.error || 'nieznany');
        status.textContent = ''; btn.disabled = false;
      }
    } catch(e) {
      result.className = 'alert alert-danger'; result.style.display='block';
      result.textContent = 'Błąd sieci: ' + e.message;
      status.textContent = ''; btn.disabled = false;
    }
  });

  // ── Nawigacja ────────────────────────────────────────────────────────────
  nextBtn.addEventListener('click', async () => {
    if (!validateStep(current)) return;
    if (current === 3) {
      if (!savedPfronId) {
        const s = document.getElementById('step3-save-status');
        s.style.display='block'; s.className='alert alert-warning py-2 small';
        s.textContent = 'Aby wygenerować dokumenty i podpisać umowę, wybierz konkretną umowę PFRON z karty beneficjenta.';
        return;
      }
      const ok = await saveStep3();
      if (!ok) return;
    }
    if (current < TOTAL) showStep(current + 1);
  });

  backBtn.addEventListener('click', () => {
    if (current > 1) showStep(current - 1);
  });

  stepBtns().forEach(btn => {
    btn.addEventListener('click', () => {
      const n = parseInt(btn.dataset.step);
      if (n < current || btn.classList.contains('done')) showStep(n);
    });
  });

  document.getElementById('btn-start-wizard')?.addEventListener('click', () => {
    showStep(1); bsModal.show();
  });

  if (<?= $auto_start ?>) { showStep(1); bsModal.show(); }
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
