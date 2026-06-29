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

        // Blokada: jeśli umowa jest już podpisana — nie pozwól nadpisać
        if ($pid) {
            $lock_check = db_one("SELECT signed_at, doc_number FROM k30_pfron_contracts WHERE id=?", [$pid]);
            if (!empty($lock_check['signed_at'])) {
                flash_set('warning', 'Umowa jest już podpisana (nr ' . $lock_check['doc_number'] . ') i nie może być ponownie wygenerowana.');
                header('Location: doc_print.php?type=umowa&preview=1');
                exit;
            }
        }

        // Zapisz PESEL do klienta jeśli podany
        if ($cid && trim($_POST['pesel'] ?? '') !== '') {
            db()->prepare("UPDATE k30_clients SET pesel=?, updated_at=datetime('now') WHERE id=?")
                ->execute([trim($_POST['pesel']), $cid]);
        }

        // Utwórz umowę PFRON jeśli nie istnieje (kreator tworzy od zera)
        if (!$pid && $cid) {
            $cn = trim($_POST['pfron_contract_no'] ?? '');
            if (!$cn) $cn = 'PFRON/' . date('Y') . '/brak-numeru';
            $pid = k30_pfron_contract_save([
                'client_id'       => $cid,
                'contract_number' => $cn,
                'hours_limit'     => max(1, (int)($_POST['hours_total'] ?? 30)),
                'valid_from'      => trim($_POST['contract_date'] ?? date('Y-m-d')) ?: null,
                'valid_to'        => null,
                'status'          => 'active',
                'notes'           => '',
            ]);
        }

        // Zapisz pola skierowania do umowy PFRON
        if ($pid) {
            db()->prepare(
                "UPDATE k30_pfron_contracts SET main_contract_date=?, main_contract_sign=?,
                 hours_total=?, hours_training=?, penalty_amount=?, penalty_words=?,
                 updated_at=datetime('now') WHERE id=?"
            )->execute([
                trim($_POST['main_contract_date'] ?? ''),
                trim($_POST['main_contract_sign'] ?? ''),
                max(1, (int)($_POST['hours_total']    ?? 30)),
                max(1, (int)($_POST['hours_training'] ?? 25)),
                trim($_POST['penalty_amount'] ?? '100,00'),
                trim($_POST['penalty_words']  ?? 'sto'),
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
            // IKA — zapis danych osobowych wymaga aktywnej sesji
            $_ika_ts = (int)($_SESSION['_ika_ts'] ?? 0);
            if (function_exists('ika_require') && $_ika_ts > 0 && (time() - $_ika_ts) >= 1800) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'ika_expired' => true, 'error' => 'Sesja IKA wygasła.']);
                exit;
            }
            // Przypisz numer dokumentu już teraz — pojawi się w PDF
            $doc_number = '';
            if ($pid) {
                $existing = db_one("SELECT doc_number FROM k30_pfron_contracts WHERE id=?", [$pid]);
                $doc_number = $existing['doc_number'] ?? '';
                if (empty($doc_number)) {
                    $doc_number = k30_pfron_next_doc_number();
                    db()->prepare(
                        "UPDATE k30_pfron_contracts SET doc_number=?, updated_at=datetime('now') WHERE id=?"
                    )->execute([$doc_number, $pid]);
                }
            }
            // Zaktualizuj draft sesji o numer dokumentu i pfron_id (dla doc_print.php)
            if (isset($_SESSION['k30_pfron_doc_draft'])) {
                $_SESSION['k30_pfron_doc_draft']['doc_number'] = $doc_number;
                $_SESSION['k30_pfron_doc_draft']['pfron_id']   = $pid;
            }
            echo json_encode(['ok' => true, 'pfron_id' => $pid, 'doc_number' => $doc_number]);
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

$doc_locked    = !empty($pfron['signed_at']);   // blokada dopiero po złożeniu podpisu
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

<?php
$signed_list = [];
if (!$pfron_id && !$client_id) {
    $signed_list = db()->query(
        "SELECT pc.id, pc.doc_number, pc.contract_number, pc.signed_at, pc.board_approval_status,
                pc.signed_doc_path, c.name AS client_name, c.id AS client_id_val
         FROM k30_pfron_contracts pc
         LEFT JOIN k30_clients c ON c.id = pc.client_id
         WHERE pc.doc_number != '' OR pc.signed_at IS NOT NULL
         ORDER BY COALESCE(pc.signed_at, pc.created_at) DESC
         LIMIT 200"
    )->fetchAll(\PDO::FETCH_ASSOC);
}
?>
<?php if (!$pfron_id && !$client_id): ?>
<!-- Strona startowa bez kontekstu -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <div>
    <h1 class="h5 fw-bold mb-0">Dokumenty PFRON</h1>
    <p class="text-body-secondary small mb-0">Generowanie umów uczestnictwa i regulaminów.</p>
  </div>
  <button class="btn btn-danger ms-auto" id="btn-start-wizard">
    <i class="bi bi-magic me-2" aria-hidden="true"></i>Nowa umowa
  </button>
</div>

<?php if ($signed_list): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-patch-check-fill text-success" aria-hidden="true"></i>
    Umowy PFRON — zarejestrowane (<?= count($signed_list) ?>)
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light">
        <tr>
          <th>Nr dokumentu</th>
          <th>Beneficjent</th>
          <th>Nr umowy PFRON</th>
          <th>Data podpisania</th>
          <th>Zarząd</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($signed_list as $sl): ?>
        <tr>
          <td class="font-monospace fw-semibold"><?= h($sl['doc_number'] ?: '—') ?></td>
          <td>
            <?php if ($sl['client_id_val']): ?>
              <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$sl['client_id_val'] ?>#pfron" class="text-decoration-none">
                <?= h($sl['client_name'] ?: 'nieznany') ?>
              </a>
            <?php else: ?>
              <?= h($sl['client_name'] ?: '—') ?>
            <?php endif; ?>
          </td>
          <td class="text-body-secondary"><?= h($sl['contract_number'] ?: '—') ?></td>
          <td class="text-nowrap">
            <?= $sl['signed_at'] ? date('d.m.Y', strtotime($sl['signed_at'])) : '<span class="text-warning">oczekuje</span>' ?>
          </td>
          <td>
            <?php
            $bs = $sl['board_approval_status'];
            if ($bs === 'approved')
                echo '<span class="badge text-bg-success">zatwierdzona</span>';
            elseif ($bs === 'pending')
                echo '<span class="badge text-bg-warning text-dark">oczekuje</span>';
            else
                echo '<span class="text-body-secondary">—</span>';
            ?>
          </td>
          <td class="text-end">
            <a href="docs.php?pfron_id=<?= (int)$sl['id'] ?>&client_id=<?= (int)$sl['client_id_val'] ?>"
               class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd
            </a>
            <?php if ($sl['signed_doc_path']): ?>
            <a href="<?= h(APP_URL . '/uploads/' . $sl['signed_doc_path']) ?>" target="_blank"
               class="btn btn-outline-primary btn-sm ms-1">
              <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Plik
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="text-center py-5 text-body-secondary">
  <i class="bi bi-file-earmark-pdf display-4 mb-3" aria-hidden="true"></i>
  <p class="mb-0">Brak zarejestrowanych umów PFRON.</p>
</div>
<?php endif; ?>
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
            3 => ['icon' => 'clipboard2-check',    'label' => 'Potwierdź'],
            4 => ['icon' => 'file-earmark-pdf',    'label' => 'Dokumenty'],
            5 => ['icon' => 'pen',                 'label' => 'Podpisz'],
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
        <input type="hidden" name="client_id" value="<?= $client_id ?>" id="client-id-input">
        <input type="hidden" name="doc_type"  value="umowa" id="doc-type-input">

        <div class="modal-body">

          <!-- ── Krok 1: Dane uczestnika ───────────────────────────────── -->
          <div class="wizard-panel" id="wizard-panel-1" role="tabpanel" aria-labelledby="wizard-tab-1">
            <p class="text-body-secondary small mb-3">
              Dane osobowe uczestnika do umowy. Pola wstępnie uzupełnione z karty beneficjenta.
            </p>
            <?php if (!$client_id): ?>
            <!-- Wyszukiwarka beneficjenta — gdy brak kontekstu client_id -->
            <div class="mb-3 position-relative" id="client-search-wrap">
              <label class="form-label fw-semibold" for="w_client_search">
                Beneficjent <span class="text-danger" aria-label="wymagane">*</span>
              </label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="w_client_search"
                       placeholder="Szukaj po nazwisku lub PESEL…" autocomplete="off">
              </div>
              <ul id="client-search-results" class="list-group position-absolute w-100 shadow-sm z-3 mt-1" style="display:none;max-height:220px;overflow-y:auto"></ul>
              <div id="client-selected-info" class="alert alert-success py-2 small mt-2" style="display:none">
                <i class="bi bi-person-check me-1"></i>
                <span id="client-selected-name"></span>
                <button type="button" class="btn-close btn-close-sm float-end" id="client-deselect" aria-label="Zmień"></button>
              </div>
            </div>
            <?php endif; ?>
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

          <!-- ── Krok 3: Potwierdź dane + IKA ──────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-3" role="tabpanel" aria-labelledby="wizard-tab-3">
            <p class="text-body-secondary small mb-3">Sprawdź dane przed zapisaniem. Wymagane potwierdzenie tożsamości (IKA).</p>
            <div class="card bg-body-secondary border-0 mb-3">
              <div class="card-body py-3">
                <dl class="row mb-0 small" id="wizard-summary">
                  <dt class="col-sm-4">Uczestnik</dt>      <dd class="col-sm-8" id="sum-name">—</dd>
                  <dt class="col-sm-4">PESEL</dt>           <dd class="col-sm-8" id="sum-pesel">—</dd>
                  <dt class="col-sm-4">Adres</dt>           <dd class="col-sm-8" id="sum-address">—</dd>
                  <dt class="col-sm-4">Telefon / e-mail</dt><dd class="col-sm-8" id="sum-contact">—</dd>
                  <dt class="col-sm-4">Data umowy</dt>      <dd class="col-sm-8" id="sum-date">—</dd>
                  <dt class="col-sm-4">Nr PFRON</dt>        <dd class="col-sm-8" id="sum-pfron-no">—</dd>
                  <dt class="col-sm-4">Skierowanie</dt>     <dd class="col-sm-8" id="sum-mc">—</dd>
                  <dt class="col-sm-4">Godziny</dt>         <dd class="col-sm-8" id="sum-hours">—</dd>
                </dl>
              </div>
            </div>
            <div id="step3-save-status" style="display:none"></div>
          </div>

          <!-- ── Krok 4: Dokumenty PDF ─────────────────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-4" role="tabpanel" aria-labelledby="wizard-tab-4">
            <div id="wiz-doc-number-badge" class="mb-3" style="display:none">
              <span class="badge text-bg-success fs-6 px-3 py-2 d-inline-flex align-items-center gap-2">
                <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
                Nadany numer: <span id="wiz-doc-number-val" class="font-monospace fw-bold"></span>
              </span>
            </div>
            <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3">
              <i class="bi bi-arrow-right-circle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
              <div class="small">
                <strong>Wydrukuj umowę i daj uczestnikowi do podpisania.</strong><br>
                Gdy masz podpisany dokument — kliknij <strong>Dalej</strong>, aby przejść do kroku Podpisz.
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <a id="btn-pdf-umowa-wiz" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=umowa" target="_blank"
                 class="btn btn-danger" data-pfron-link>
                <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Pobierz do podpisu (Umowa + Regulamin)
              </a>
              <a href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=regulamin" target="_blank"
                 class="btn btn-outline-secondary btn-sm align-self-center" data-pfron-link>
                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Sam regulamin
              </a>
            </div>
          </div>

          <!-- ── Krok 5: Podpisz i wgraj ──────────────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-5" role="tabpanel" aria-labelledby="wizard-tab-5">
            <p class="text-body-secondary small mb-3">
              Wydrukuj umowę, daj uczestnikowi do podpisania, następnie wgraj skan poniżej.
              Możesz też zebrać podpis bezpośrednio na tablecie / ekranie.
            </p>

            <!-- Linki PDF (powtórzone dla wygody) -->
            <div class="d-flex gap-2 mb-4 flex-wrap">
              <a id="btn-pdf-umowa-s4" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=umowa" target="_blank"
                 class="btn btn-outline-danger btn-sm" data-pfron-link>
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
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Wgraj egzemplarz nr 1 (dla Beneficjenta)
              </button>
              <span id="wiz-upload-status" class="text-body-secondary small"></span>
            </div>
            <div id="wiz-upload-result" class="mt-2" style="display:none"></div>

            <!-- Egzemplarz nr 2 — pojawia się po wgraniu egz. 1 -->
            <div id="wiz-egz2-section" class="mt-4 border-top pt-4" style="display:none">
              <div class="alert alert-success d-flex gap-2 align-items-start py-2 mb-3">
                <i class="bi bi-check-circle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
                <div class="small">
                  <strong>Egzemplarz nr 1 (dla Beneficjenta) wgrany.</strong>
                  Wydrukuj egzemplarz nr 2 przeznaczony dla Fundacji, podpisz go i wgraj skan.
                </div>
              </div>
              <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                <a id="btn-egz2-pdf" href="<?= APP_URL ?>/karty30/pfron/doc_print.php?type=umowa2" target="_blank"
                   class="btn btn-danger" data-pfron-link>
                  <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz egzemplarz nr 2 — dla Fundacji
                </a>
                <span class="text-body-secondary small">z kodem QR, barkodem i przebiegiem rejestracji</span>
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold" for="wiz-doc2-file">
                  Wgraj podpisany egzemplarz nr 2 (dla Fundacji) <span class="text-body-secondary fw-normal">(PDF, JPG, PNG — max 20 MB)</span>
                </label>
                <input type="file" class="form-control" id="wiz-doc2-file" accept=".pdf,.jpg,.jpeg,.png,.webp">
              </div>
              <div id="wiz-doc2-preview" class="mb-2" style="display:none">
                <img id="wiz-doc2-img" style="max-height:100px;max-width:100%;border:1px solid #ccc;border-radius:4px" alt="Podgląd egz. 2">
                <span id="wiz-doc2-name" class="d-block text-body-secondary small mt-1"></span>
              </div>
              <div class="d-flex gap-2 align-items-center flex-wrap">
                <button type="button" class="btn btn-outline-primary" id="wiz-upload2-btn" disabled>
                  <i class="bi bi-upload me-1" aria-hidden="true"></i>Wgraj egzemplarz nr 2
                </button>
                <span id="wiz-upload2-status" class="text-body-secondary small"></span>
              </div>
              <div id="wiz-upload2-result" class="mt-2" style="display:none"></div>
              <div id="wiz-egz2-done" class="mt-3" style="display:none">
                <hr class="my-3">
                <p class="text-body-secondary small mb-2">
                  <i class="bi bi-check2-all me-1 text-success" aria-hidden="true"></i>
                  Proces rejestracji zakończony. Oba egzemplarze wgrane do systemu.
                </p>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij kreator</button>
              </div>
            </div>
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
  let confirmed = false;   // true po zapisaniu kroku 3
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
    if (!isLast) nextBtn.innerHTML = 'Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
    stepLabel.textContent = `Krok ${n} z ${TOTAL}`;
    if (n === 3) fillSummary();
    if (n === 5) initStep5();
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
    // Wymagaj wybrania beneficjenta z wyszukiwarki gdy brak pre-ustawionego client_id
    if (n === 1) {
      const cid = parseInt(document.getElementById('client-id-input')?.value || '0');
      const searchWrap = document.getElementById('client-search-wrap');
      if (searchWrap && !cid) {
        searchWrap.querySelector('input')?.classList.add('is-invalid');
        ok = false;
      }
    }
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
        if (data.pfron_id) {
          savedPfronId = data.pfron_id;
          const inp = document.getElementById('pfron-id-input');
          if (inp) inp.value = savedPfronId;
          // Zaktualizuj hrefs linków PDF o pfron_id
          document.querySelectorAll('a[data-pfron-link]').forEach(a => {
            try {
              const url = new URL(a.href, location.href);
              url.searchParams.set('pfron_id', savedPfronId);
              a.href = url.toString();
            } catch(e) {}
          });
        }
        if (data.doc_number) {
          const badge = document.getElementById('wiz-doc-number-badge');
          const val   = document.getElementById('wiz-doc-number-val');
          if (badge && val) { val.textContent = data.doc_number; badge.style.display = ''; }
        }
        // Zablokuj kroki 1 i 2 — dane zatwierdzone i zapisane
        confirmed = true;
        stepBtns().forEach(b => {
          const n = parseInt(b.dataset.step);
          if (n <= 2) { b.disabled = true; b.setAttribute('aria-disabled', 'true'); }
        });
        status.style.display = 'none';
        nextBtn.disabled = false;
        return true;
      } else if (data.ika_expired) {
        const gate = <?= json_encode(APP_URL . '/contracts/ika_gate.php?to=' . urlencode(APP_URL . $_SERVER['REQUEST_URI'])) ?>;
        status.className = 'alert alert-warning py-2 small';
        status.style.display = 'block';
        status.innerHTML = '<i class="bi bi-shield-exclamation me-1"></i>Sesja bezpieczeństwa (IKA) wygasła. '
          + '<a href="' + gate + '" class="alert-link">Kliknij tutaj, aby się ponownie uwierzytelnić</a> — dane kreatora zostaną zachowane.';
        nextBtn.disabled = false;
        return false;
      } else {
        status.className = 'alert alert-danger py-2 small';
        status.style.display = 'block';
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
  let step5Inited = false;
  let wizCanvasData = null;   // 'canvas' gdy są kreski
  let wizScanFile   = null;   // File ze skanu

  function initStep5() {
    if (step5Inited) return;
    step5Inited = true;

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

    // Upload button egz. 1
    document.getElementById('wiz-upload-btn')?.addEventListener('click', doUpload);

    // Egzemplarz nr 2 — plik i upload
    const file2Input  = document.getElementById('wiz-doc2-file');
    const upload2Btn  = document.getElementById('wiz-upload2-btn');
    const status2     = document.getElementById('wiz-upload2-status');
    const result2     = document.getElementById('wiz-upload2-result');
    const preview2    = document.getElementById('wiz-doc2-preview');
    const preview2Img = document.getElementById('wiz-doc2-img');
    const preview2Nm  = document.getElementById('wiz-doc2-name');
    let   scan2File   = null;

    file2Input?.addEventListener('change', function() {
      scan2File = this.files[0] || null;
      if (!scan2File) { upload2Btn.disabled = true; preview2.style.display='none'; return; }
      preview2Nm.textContent = scan2File.name + ' (' + (scan2File.size/1024).toFixed(0) + ' KB)';
      if (scan2File.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => { preview2Img.src = e.target.result; preview2Img.style.display=''; preview2.style.display='block'; };
        reader.readAsDataURL(scan2File);
      } else { preview2Img.style.display='none'; preview2.style.display='block'; }
      upload2Btn.disabled = false;
    });

    upload2Btn?.addEventListener('click', async function() {
      if (!scan2File) return;
      upload2Btn.disabled = true; status2.textContent = 'Przesyłanie…';
      const fd = new FormData();
      fd.append('pfron_id', savedPfronId);
      fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
      fd.append('v', '2');
      fd.append('signed_doc', scan2File);
      try {
        const res  = await fetch('upload_doc.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) {
          result2.className = 'alert alert-success'; result2.style.display='block';
          result2.innerHTML = 'Egzemplarz nr 2 (dla Fundacji) wgrany: <a href="' + data.url + '" target="_blank" class="fw-semibold">' + data.name + '</a>';
          status2.textContent = ''; upload2Btn.disabled = true;
          const done = document.getElementById('wiz-egz2-done');
          if (done) done.style.display = '';
        } else if (data.ika_expired) {
          result2.className = 'alert alert-warning'; result2.style.display='block';
          result2.innerHTML = 'Sesja IKA wygasła. <a href="<?= h(APP_URL . '/contracts/ika_gate.php?to=' . urlencode(APP_URL . $_SERVER['REQUEST_URI'])) ?>">Zaloguj się ponownie</a>.';
          status2.textContent = ''; upload2Btn.disabled = false;
        } else {
          result2.className = 'alert alert-danger'; result2.style.display='block';
          result2.textContent = 'Błąd: ' + (data.error || 'nieznany');
          status2.textContent = ''; upload2Btn.disabled = false;
        }
      } catch(e) {
        result2.className = 'alert alert-danger'; result2.style.display='block';
        result2.textContent = 'Błąd sieci: ' + e.message;
        status2.textContent = ''; upload2Btn.disabled = false;
      }
    });
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
        ? 'Egzemplarz nr 1 wgrany. Numer: <strong class="font-monospace">' + data.doc_number + '</strong>'
        : 'Plik wgrany: <a href="' + data.url + '" target="_blank" class="fw-semibold">' + data.name + '</a>';
      status.textContent = ''; btn.disabled = true;
      // Pokaż sekcję egzemplarza nr 2
      if (!data.is_egz2) {
        const s = document.getElementById('wiz-egz2-section');
        if (s) s.style.display = '';
      }
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


  // ── Nawigacja ────────────────────────────────────────────────────────────
  nextBtn.addEventListener('click', async () => {
    if (!validateStep(current)) return;
    if (current === 3) {   // Potwierdź → IKA check + zapis
      const ok = await saveStep3();
      if (!ok) return;
    }
    if (current < TOTAL) showStep(current + 1);
  });

  backBtn.addEventListener('click', () => {
    const minStep = confirmed ? 3 : 1;
    if (current > minStep) showStep(current - 1);
  });

  stepBtns().forEach(btn => {
    btn.addEventListener('click', () => {
      const n = parseInt(btn.dataset.step);
      const minStep = confirmed ? 3 : 1;
      if (n >= minStep && (n < current || btn.classList.contains('done'))) showStep(n);
    });
  });

  document.getElementById('btn-start-wizard')?.addEventListener('click', () => {
    showStep(1); bsModal.show();
  });

  if (<?= $auto_start ?>) { showStep(1); bsModal.show(); }
})();
</script>

<?php if (!$client_id): ?>
<script>
(function() {
  const searchInput  = document.getElementById('w_client_search');
  const resultsList  = document.getElementById('client-search-results');
  const selectedInfo = document.getElementById('client-selected-info');
  const selectedName = document.getElementById('client-selected-name');
  const deselect     = document.getElementById('client-deselect');
  const clientIdInp  = document.getElementById('client-id-input');
  const nameInp      = document.getElementById('w_name');
  const peselInp     = document.getElementById('w_pesel');
  const addrInp      = document.getElementById('w_address');
  const phoneInp     = document.getElementById('w_phone');
  const emailInp     = document.getElementById('w_email');

  if (!searchInput) return;

  let debounce = null;

  searchInput.addEventListener('input', function() {
    clearTimeout(debounce);
    const q = this.value.trim();
    if (q.length < 2) { resultsList.style.display = 'none'; return; }
    debounce = setTimeout(() => fetchClients(q), 260);
  });

  async function fetchClients(q) {
    try {
      const res  = await fetch('<?= APP_URL ?>/karty30/pfron/client_search.php?q=' + encodeURIComponent(q));
      const data = await res.json();
      resultsList.innerHTML = '';
      if (!data.length) {
        resultsList.innerHTML = '<li class="list-group-item text-body-secondary small">Brak wyników</li>';
        resultsList.style.display = 'block';
        return;
      }
      data.forEach(c => {
        const li = document.createElement('li');
        li.className = 'list-group-item list-group-item-action py-2 small';
        li.innerHTML = '<strong>' + c.name + '</strong>'
          + (c.pesel ? ' <span class="text-body-secondary font-monospace">' + c.pesel + '</span>' : '');
        li.addEventListener('mousedown', e => { e.preventDefault(); selectClient(c); });
        resultsList.appendChild(li);
      });
      resultsList.style.display = 'block';
    } catch(e) {}
  }

  function selectClient(c) {
    clientIdInp.value  = c.id;
    nameInp.value      = c.name  || '';
    peselInp.value     = c.pesel || '';
    addrInp.value      = c.address || '';
    phoneInp.value     = c.phone || '';
    emailInp.value     = c.email || '';
    searchInput.classList.remove('is-invalid');
    searchInput.value  = '';
    resultsList.style.display = 'none';
    selectedName.textContent  = c.name;
    selectedInfo.style.display = 'block';
    searchInput.closest('.input-group').style.display = 'none';
  }

  deselect?.addEventListener('click', () => {
    clientIdInp.value = '0';
    nameInp.value = peselInp.value = addrInp.value = phoneInp.value = emailInp.value = '';
    selectedInfo.style.display = 'none';
    searchInput.closest('.input-group').style.display = '';
    searchInput.value = '';
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('#client-search-wrap')) resultsList.style.display = 'none';
  });
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
