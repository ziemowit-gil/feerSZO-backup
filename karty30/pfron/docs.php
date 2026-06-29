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

        $doc_type = ($_POST['doc_type'] ?? 'umowa') === 'regulamin' ? 'regulamin' : 'umowa';
        header('Location: doc_print.php?type=' . $doc_type);
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

// Uruchom kreator od razu jeśli mamy pfron_id lub client_id
$auto_start = ($pfron_id || $client_id) ? 'true' : 'false';

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
<!-- Przycisk do ponownego otwarcia gdy mamy kontekst -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div>
    <h1 class="h5 fw-bold mb-0">Dokumenty PFRON</h1>
    <?php if ($pfron): ?>
    <p class="text-body-secondary small mb-0">Umowa PFRON: <strong><?= h($pfron['contract_number']) ?></strong></p>
    <?php elseif ($client): ?>
    <p class="text-body-secondary small mb-0">Beneficjent: <strong><?= h($client['name']) ?></strong></p>
    <?php endif; ?>
  </div>
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
</div>
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
            1 => ['icon' => 'person', 'label' => 'Uczestnik'],
            2 => ['icon' => 'file-earmark-text', 'label' => 'Umowa'],
            3 => ['icon' => 'check2-circle', 'label' => 'Generuj'],
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
        <input type="hidden" name="pfron_id"  value="<?= $pfron_id ?>">
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
                     autocomplete="off" placeholder="11 cyfr">
              <div class="form-text">Zapisywany w karcie beneficjenta. Pojawi się tylko w dokumencie.</div>
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

          <!-- ── Krok 3: Podsumowanie i generowanie ────────────────────── -->
          <div class="wizard-panel d-none" id="wizard-panel-3" role="tabpanel" aria-labelledby="wizard-tab-3">
            <p class="text-body-secondary small mb-3">
              Sprawdź dane i wybierz dokument do wygenerowania.
            </p>
            <div class="card bg-body-secondary border-0 mb-4">
              <div class="card-body py-3">
                <dl class="row mb-0 small" id="wizard-summary">
                  <dt class="col-sm-4">Uczestnik</dt>
                  <dd class="col-sm-8" id="sum-name">—</dd>
                  <dt class="col-sm-4">PESEL</dt>
                  <dd class="col-sm-8" id="sum-pesel">—</dd>
                  <dt class="col-sm-4">Adres</dt>
                  <dd class="col-sm-8" id="sum-address">—</dd>
                  <dt class="col-sm-4">Telefon / e-mail</dt>
                  <dd class="col-sm-8" id="sum-contact">—</dd>
                  <dt class="col-sm-4">Data umowy</dt>
                  <dd class="col-sm-8" id="sum-date">—</dd>
                  <dt class="col-sm-4">Nr PFRON</dt>
                  <dd class="col-sm-8" id="sum-pfron-no">—</dd>
                  <dt class="col-sm-4">Skierowanie</dt>
                  <dd class="col-sm-8" id="sum-mc">—</dd>
                  <dt class="col-sm-4">Godziny</dt>
                  <dd class="col-sm-8" id="sum-hours">—</dd>
                </dl>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button type="submit" class="btn btn-danger flex-fill"
                      onclick="document.getElementById('doc-type-input').value='umowa'">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Umowa + Regulamin
              </button>
              <button type="submit" class="btn btn-outline-secondary"
                      onclick="document.getElementById('doc-type-input').value='regulamin'">
                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Sam regulamin
              </button>
            </div>
            <p class="text-body-secondary mt-2 mb-0" style="font-size:.8rem">
              <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
              Regulamin drukuje się automatycznie jako załącznik do umowy.
              Dane skierowania i godziny zostaną zapisane w kartotece PFRON.
            </p>
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
  const TOTAL = 3;
  let current = 1;

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
    backBtn.disabled = (n === 1);
    nextBtn.textContent = n === TOTAL ? '' : 'Dalej';
    nextBtn.innerHTML   = n === TOTAL
      ? ''  // hidden on last step (submit buttons used)
      : 'Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>';
    nextBtn.style.display = n === TOTAL ? 'none' : '';
    stepLabel.textContent = `Krok ${n} z ${TOTAL}`;
    if (n === TOTAL) fillSummary();
    // Fokus na pierwszy input w kroku
    const first = document.querySelector(`#wizard-panel-${n} input, #wizard-panel-${n} textarea`);
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
    return ok;
  }

  function fillSummary() {
    const g = id => document.getElementById(id)?.value || '—';
    const blank = v => v || '<span class="text-body-secondary fst-italic">nie podano</span>';
    document.getElementById('sum-name').innerHTML    = blank(g('w_name'));
    document.getElementById('sum-pesel').innerHTML   = blank(g('w_pesel'));
    document.getElementById('sum-address').innerHTML = blank(g('w_address'));
    const ph = [g('w_phone'), g('w_email')].filter(v => v && v !== '—').join(' / ');
    document.getElementById('sum-contact').innerHTML  = blank(ph);
    document.getElementById('sum-date').innerHTML     = blank(g('w_date'));
    document.getElementById('sum-pfron-no').innerHTML = blank(g('w_pfron_no'));
    const mc = [g('w_mc_date'), g('w_mc_sign')].filter(v => v && v !== '—').join(' · znak: ');
    document.getElementById('sum-mc').innerHTML    = blank(mc);
    const ht  = g('w_ht')  || '30';
    const htr = g('w_htr') || '25';
    document.getElementById('sum-hours').textContent = `${ht} łącznie, ${htr} właściwych`;
  }

  nextBtn.addEventListener('click', () => {
    if (!validateStep(current)) return;
    if (current < TOTAL) showStep(current + 1);
  });

  backBtn.addEventListener('click', () => {
    if (current > 1) showStep(current - 1);
  });

  // Kliknięcie w zakładkę kroku (tylko do ukończonych)
  stepBtns().forEach(btn => {
    btn.addEventListener('click', () => {
      const n = parseInt(btn.dataset.step);
      if (n < current || btn.classList.contains('done')) showStep(n);
    });
  });

  // Otwieranie z przycisku
  document.getElementById('btn-start-wizard')?.addEventListener('click', () => {
    showStep(1);
    bsModal.show();
  });

  // Auto-start jeśli mamy kontekst
  if (<?= $auto_start ?>) {
    showStep(1);
    bsModal.show();
  }
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
