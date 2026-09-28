<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin', 'editor');

// ── IKA — przetwarzanie danych osobowych wymaga weryfikacji ───────────────────
auth_start();
$_return = APP_URL . '/rodo/new.php?' . http_build_query($_GET);
ika_require($_return);

// Prefill z umowy (jeśli przekazano parametry)
$_ct   = rodo_clean_type($_GET['contract_type'] ?? '');
$_cid  = (int)($_GET['contract_id'] ?? 0);
$_from_edit = !empty($_GET['_from_edit']); // Przekierowanie z edit.php
$_crow = $_ct && $_cid ? rodo_contract_fetch($_ct, $_cid) : null; // znormalizowany wiersz umowy
if (!$_crow) $_cid = 0;
$_meta = rodo_type_meta($_ct ?: null);
$_prefill = [];
$_contract_max_date = ''; // max authorized_until (data zakończenia umowy)
$_bezterminowa = false;

if ($_crow) {
    $_bezterminowa      = $_crow['open_ended'];
    $_contract_max_date = $_bezterminowa ? '' : $_crow['end_date'];
    $_prefill = [
        'person_name'     => $_crow['person_name'],
        'person_pesel'    => $_crow['person_pesel'],
        'contract_number' => $_crow['contract_number'],
        'contract_date'   => $_crow['contract_date'],
        'authorized_from' => $_crow['start_date'] ?: date('Y-m-d'),
        'authorized_until'=> $_contract_max_date,
    ];
}
$_org = rodo_org_data();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $person_name     = trim($_POST['person_name']      ?? '');
    $person_pesel    = preg_replace('/\D/', '', $_POST['person_pesel'] ?? '');
    $scope_items     = array_filter((array)($_POST['scope_items'] ?? []));
    $scope_custom    = trim($_POST['scope_custom']     ?? '');
    $authorized_from = trim($_POST['authorized_from']  ?? '');
    $authorized_until= trim($_POST['authorized_until'] ?? '');
    // Typ z powiązanej umowy ma pierwszeństwo; bez umowy — z selecta w formularzu
    $contract_type   = $_cid ? $_ct : (rodo_clean_type($_POST['contract_type'] ?? '') ?: 'wolontariat');
    $contract_id     = $_cid ?: null;
    $contract_number = trim($_POST['contract_number']  ?? '');
    $contract_date   = trim($_POST['contract_date']    ?? '');
    $signed_by_name  = trim($_POST['signed_by_name']   ?? '');
    $signed_at       = trim($_POST['signed_at']         ?? '');
    $notes           = trim($_POST['notes']             ?? '');

    if (!$person_name)     $errors[] = 'Imię i nazwisko osoby upoważnionej jest wymagane.';
    if (!$authorized_from) $errors[] = 'Data upoważnienia jest wymagana.';
    if (empty($scope_items) && !$scope_custom) $errors[] = 'Wybierz co najmniej jeden zakres (§ 2).';
    if ($authorized_from && $authorized_until && $authorized_until < $authorized_from) {
        $errors[] = 'Data końcowa upoważnienia nie może być wcześniejsza niż data początkowa.';
    }

    // Upoważnienie nie dłuższe niż umowa (każdy typ umowy)
    if ($authorized_until && $contract_id) {
        $period_err = rodo_validate_period($contract_type, $contract_id, $authorized_until);
        if ($period_err) $errors[] = $period_err;
    }
    // Nie może zaczynać się przed datą umowy
    if ($authorized_from && $_crow && $_crow['contract_date'] && $authorized_from < $_crow['contract_date']) {
        $errors[] = 'Data upoważnienia nie może być wcześniejsza niż data zawarcia ' . $_meta['doc_contract']
                  . ' (' . date('d.m.Y', strtotime($_crow['contract_date'])) . ').';
    }

    if (!$errors) {
        // „Bez umowy” — pole numeru to opis podstawy (np. uchwała), więc numer sekwencyjny
        $number   = rodo_next_number($contract_type === 'bez_umowy' ? '' : $contract_number);
        $uid      = (int)current_user()['id'];
        $org      = rodo_org_data();

        $id = db_insert('rodo_authorizations', [
            'number'          => $number,
            'contract_type'   => $contract_type,
            'contract_id'     => $contract_id,
            'person_name'     => $person_name,
            'person_pesel'    => $person_pesel ?: null,
            'org_name'        => $_POST['org_name']    ?? $org['name'],
            'org_address'     => $_POST['org_address']  ?? trim($org['address'] . ', ' . $org['city'], ', '),
            'org_nip'         => $_POST['org_nip']     ?? $org['nip'],
            'scope_items'     => json_encode(array_values($scope_items), JSON_UNESCAPED_UNICODE),
            'scope_custom'    => $scope_custom ?: null,
            'authorized_from' => $authorized_from,
            'authorized_until'=> $authorized_until ?: null,
            'contract_number' => $contract_number ?: null,
            'contract_date'   => $contract_date   ?: null,
            'status'          => 'aktywne',
            'signed_by_id'    => $uid,
            'signed_by_name'  => $signed_by_name ?: (current_user()['name'] ?? ''),
            'signed_at'       => $signed_at ?: null,
            'notes'           => $notes ?: null,
            'created_by'      => $uid,
        ]);

        if (isset($_POST['nie_mam_drukarki'])) {
            require_once dirname(__DIR__) . '/contracts/includes/pdf_queue.php';
            pdf_queue_add('rodo_authorization', $id, $number, $person_name, $uid);
        }
        flash_set('success', "Upoważnienie {$number} zostało zarejestrowane. Wydrukuj dokument i odbierz podpisy.");
        // Powróć do widoku umowy jeśli przyszło z edit.php
        if ($_from_edit && $contract_id) {
            header('Location: ' . rodo_contract_url($contract_type, (int)$contract_id)); exit;
        }
        header('Location: ' . APP_URL . '/rodo/view.php?id=' . $id); exit;
    }
}

$PAGE_TITLE = 'Nowe upoważnienie RODO';
include dirname(__DIR__) . '/includes/header.php';

// Dane formularza (POST lub prefill)
$_d = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : array_merge([
    'person_name'     => '', 'person_pesel' => '',
    'contract_type'   => $_ct ?: 'wolontariat', 'contract_id' => $_cid,
    'contract_number' => '', 'contract_date' => '',
    'authorized_from' => date('Y-m-d'), 'authorized_until' => '',
    'org_name'        => $_org['name'],
    'org_address'     => trim($_org['address'] . ', ' . $_org['city'], ', '),
    'org_nip'         => $_org['nip'],
    'scope_items'     => [], 'scope_custom' => '',
    'signed_by_name'  => current_user()['name'] ?? '',
    'signed_at'       => '', 'notes' => '',
], $_prefill);
?>

<!-- Alert kontekstowy gdy przyszło z edit.php -->
<?php if ($_from_edit): ?>
<div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:.88rem">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Kod IKA zweryfikowany.</strong>
    Dane zostały przeniesione z <?= h($_meta['doc_contract']) ?>. Uzupełnij zakres § 2, a następnie zarejestruj upoważnienie i wydrukuj dokument.
  </div>
</div>
<?php endif; ?>

<?php if ($_contract_max_date && !$_bezterminowa): ?>
<div class="alert alert-warning d-flex gap-2 py-2 mb-3" style="font-size:.88rem">
  <i class="bi bi-calendar-x flex-shrink-0 mt-1"></i>
  <div>
    <strong>Zasada RODO:</strong> Upoważnienie nie może być dłuższe niż okres <?= h($_meta['doc_contract']) ?>.
    Maksymalna data zakończenia: <strong><?= date('d.m.Y', strtotime($_contract_max_date)) ?></strong>.
  </div>
</div>
<?php elseif ($_bezterminowa): ?>
<div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:.88rem">
  <i class="bi bi-infinity flex-shrink-0 mt-1"></i>
  <div><?= h($_meta['doc_contract_short']) ?> zawarta na czas nieokreślony — upoważnienie RODO może być bezterminowe (zostaw datę końcową pustą).</div>
</div>
<?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3">
  <?php if ($_cid): ?>
  <a href="<?= h(rodo_contract_url($_ct, $_cid)) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do umowy">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>
  </a>
  <?php else: ?>
  <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <?php endif; ?>
  <h4 class="mb-0 fw-bold"><i class="bi bi-shield-plus text-primary me-2"></i>Nowe upoważnienie RODO</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
<?php if ($_cid): ?>
<input type="hidden" name="contract_type" value="<?= h($_ct) ?>">
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-8">

<!-- ── Dane administratora ─────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
    <i class="bi bi-building me-1 text-primary"></i>Dane administratora danych
  </div>
  <div class="card-body row g-3">
    <div class="col-md-8">
      <label class="form-label small fw-semibold">Nazwa organizacji</label>
      <input name="org_name" class="form-control" value="<?= h($_d['org_name']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">NIP</label>
      <input name="org_nip" class="form-control font-monospace" value="<?= h($_d['org_nip']) ?>">
    </div>
    <div class="col-12">
      <label class="form-label small fw-semibold">Adres siedziby</label>
      <input name="org_address" class="form-control" value="<?= h($_d['org_address']) ?>">
    </div>
  </div>
</div>

<!-- ── Osoba upoważniana ────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
    <i class="bi bi-person-badge me-1 text-success"></i>Osoba upoważniana
  </div>
  <div class="card-body row g-3">
    <div class="col-12">
      <label class="form-label small fw-semibold" for="rodo_contract_type">Podstawa współpracy</label>
      <?php if ($_cid): ?>
      <div class="form-control-plaintext py-0 fw-semibold" id="rodo_contract_type">
        <i class="bi bi-link-45deg text-success" aria-hidden="true"></i> <?= h($_meta['label']) ?>
      </div>
      <?php else: ?>
      <select name="contract_type" id="rodo_contract_type" class="form-select" aria-describedby="rodo_contract_type_help">
        <?php foreach (RODO_CONTRACT_TYPES as $_tk => $_tm): ?>
        <option value="<?= h($_tk) ?>" <?= ($_d['contract_type'] ?? '') === $_tk ? 'selected' : '' ?>><?= h($_tm['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text" id="rodo_contract_type_help">
        Aby powiązać upoważnienie z konkretną umową, nadaj je z widoku tej umowy (karta „Upoważnienia RODO”).
        „Bez umowy” — np. członek zarządu lub rady, stażysta, praktykant.
      </div>
      <?php endif; ?>
    </div>
    <div class="col-md-7">
      <label class="form-label small fw-semibold" for="rodo_person_name">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(wymagane)</span></label>
      <input name="person_name" id="rodo_person_name" class="form-control" required autocomplete="off" value="<?= h($_d['person_name']) ?>">
    </div>
    <div class="col-md-5">
      <label class="form-label small fw-semibold" for="rodo_person_pesel">PESEL</label>
      <input name="person_pesel" id="rodo_person_pesel" class="form-control font-monospace" maxlength="11" inputmode="numeric" autocomplete="off"
             value="<?= h($_d['person_pesel']) ?>">
    </div>
    <div class="col-md-5">
      <label class="form-label small fw-semibold" for="rodo_contract_number">Nr umowy / podstawa</label>
      <?php if ($_cid && !empty($_d['contract_number'])): ?>
      <input name="contract_number" id="rodo_contract_number" class="form-control font-monospace fw-bold"
             value="<?= h($_d['contract_number']) ?>" readonly aria-describedby="rodo_contract_number_help"
             title="Numer pobierany automatycznie z powiązanej umowy">
      <div class="form-text text-success" id="rodo_contract_number_help"><i class="bi bi-link-45deg" aria-hidden="true"></i> Przeniesione z umowy</div>
      <?php else: ?>
      <input name="contract_number" id="rodo_contract_number" class="form-control font-monospace"
             value="<?= h($_d['contract_number']) ?>" aria-describedby="rodo_contract_number_help"
             placeholder="np. ZL/2026/0001 albo uchwała zarządu nr 3/2026">
      <div class="form-text" id="rodo_contract_number_help">Numer umowy jest podstawą numeru upoważnienia. Dla „bez umowy” wpisz podstawę (np. uchwałę) — numer upoważnienia będzie sekwencyjny.</div>
      <?php endif; ?>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold" for="rodo_contract_date">Data umowy / podstawy</label>
      <input name="contract_date" id="rodo_contract_date" type="date" class="form-control" value="<?= h($_d['contract_date']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">Upoważnienie od <span class="text-danger">*</span></label>
      <input name="authorized_from" type="date" class="form-control" required value="<?= h($_d['authorized_from']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">
        Upoważnienie do
        <?php if ($_contract_max_date && !$_bezterminowa): ?>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">
          max <?= date('d.m.Y', strtotime($_contract_max_date)) ?>
        </span>
        <?php else: ?>
        <span class="text-muted fw-normal">(puste = do wygaśnięcia umowy)</span>
        <?php endif; ?>
      </label>
      <input name="authorized_until" type="date" class="form-control"
             value="<?= h($_d['authorized_until']) ?>"
             <?= $_contract_max_date && !$_bezterminowa ? 'max="' . h($_contract_max_date) . '"' : '' ?>>
      <?php if ($_contract_max_date && !$_bezterminowa): ?>
      <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Upoważnienie nie może być dłuższe niż umowa</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── § 2 Zakres upoważnienia ─────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
    <i class="bi bi-list-check me-1 text-warning"></i>§ 2 Zakres upoważnienia
    <span class="text-muted fw-normal ms-1">(wybierz co najmniej jeden)</span>
  </div>
  <div class="card-body">
    <div class="row g-2">
      <?php
      $selected_items = (array)($_d['scope_items'] ?? []);
      foreach (RODO_SCOPE_ITEMS as $key => $desc):
        $checked = in_array($key, $selected_items, true);
      ?>
      <div class="col-md-6">
        <label class="d-flex align-items-start gap-2 p-2 rounded border <?= $checked ? 'bg-primary-subtle border-primary-subtle' : '' ?>"
               style="cursor:pointer;font-size:.86rem;transition:background .1s">
          <input type="checkbox" name="scope_items[]" value="<?= h($key) ?>"
                 class="form-check-input mt-0 flex-shrink-0" <?= $checked ? 'checked' : '' ?>>
          <div><?= h($desc) ?></div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-3">
      <label class="form-label small fw-semibold">Dodatkowy zakres (własny opis)</label>
      <textarea name="scope_custom" class="form-control font-monospace" rows="2"
                placeholder="np. obsługi konkretnego projektu…"><?= h($_d['scope_custom']) ?></textarea>
    </div>
  </div>
</div>

</div><!-- /col-8 -->

<!-- Sidebar -->
<div class="col-lg-4">

  <!-- Podpisanie -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-pen me-1"></i>Podpisanie
    </div>
    <div class="card-body row g-2">
      <div class="col-12">
        <label class="form-label small fw-semibold">Podpisuje (administrator)</label>
        <input name="signed_by_name" class="form-control form-control-sm"
               value="<?= h($_d['signed_by_name']) ?>" placeholder="Imię i nazwisko">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold">Data podpisania</label>
        <input name="signed_at" type="date" class="form-control form-control-sm"
               value="<?= h($_d['signed_at']) ?>">
      </div>
    </div>
  </div>

  <!-- Uwagi -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-chat-left-text me-1"></i>Uwagi wewnętrzne
    </div>
    <div class="card-body">
      <textarea name="notes" class="form-control form-control-sm" rows="3"
                placeholder="Notatki widoczne tylko dla administratorów…"><?= h($_d['notes']) ?></textarea>
    </div>
  </div>

  <!-- Zapisz -->
  <div class="card border-0 shadow-sm">
    <div class="card-body d-grid gap-2">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki_rodo" value="1">
        <label class="form-check-label text-muted" for="nie_mam_drukarki_rodo">
          <i class="bi bi-printer"></i> Nie mam drukarki — zapisz upoważnienie jako PDF do późniejszego wydruku
        </label>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Zarejestruj upoważnienie
      </button>
      <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-outline-secondary btn-sm text-center">
        Anuluj
      </a>
    </div>
    <div class="card-footer bg-light py-2" style="font-size:.75rem;color:#6B7280">
      <i class="bi bi-info-circle me-1"></i>
      Upoważnienie otrzyma automatyczny numer w formacie RODO/RRRR/NNNN.
    </div>
  </div>

</div><!-- /col-4 -->
</div><!-- /row -->
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
