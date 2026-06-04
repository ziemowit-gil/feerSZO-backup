<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin', 'editor');

// Prefill z umowy (jeśli przekazano parametry)
$_ct   = preg_replace('/[^a-z]/', '', $_GET['contract_type'] ?? '');
$_cid  = (int)($_GET['contract_id'] ?? 0);
$_prefill = [];
if ($_ct && $_cid) {
    try {
        $_table = 'umowy_' . $_ct;
        $_crow  = db_one("SELECT * FROM {$_table} WHERE id=?", [$_cid]);
        if ($_crow) {
            $_prefill = [
                'person_name'     => $_crow['imie_nazwisko'] ?? '',
                'person_pesel'    => $_crow['pesel'] ?? '',
                'contract_number' => $_crow['numer_umowy'] ?? '',
                'contract_date'   => $_crow['data_zawarcia'] ?? '',
                'authorized_from' => $_crow['data_rozpoczecia'] ?? ($_crow['data_zawarcia'] ?? date('Y-m-d')),
                'authorized_until'=> $_crow['data_zakonczenia'] ?? '',
            ];
        }
    } catch (\Throwable $e) {}
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
    $contract_type   = preg_replace('/[^a-z]/', '', $_POST['contract_type'] ?? $_ct);
    $contract_id     = (int)($_POST['contract_id']     ?? $_cid) ?: null;
    $contract_number = trim($_POST['contract_number']  ?? '');
    $contract_date   = trim($_POST['contract_date']    ?? '');
    $signed_by_name  = trim($_POST['signed_by_name']   ?? '');
    $signed_at       = trim($_POST['signed_at']         ?? '');
    $notes           = trim($_POST['notes']             ?? '');

    if (!$person_name)     $errors[] = 'Imię i nazwisko osoby upoważnionej jest wymagane.';
    if (!$authorized_from) $errors[] = 'Data upoważnienia jest wymagana.';
    if (empty($scope_items) && !$scope_custom) $errors[] = 'Wybierz co najmniej jeden zakres (§ 2).';

    if (!$errors) {
        $number   = rodo_next_number();
        $uid      = (int)current_user()['id'];
        $org      = rodo_org_data();

        $id = db_insert('rodo_authorizations', [
            'number'          => $number,
            'contract_type'   => $contract_type ?: 'wolontariat',
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

        flash_set('success', "Upoważnienie {$number} zostało zarejestrowane.");
        header('Location: ' . APP_URL . '/rodo/view.php?id=' . $id); exit;
    }
}

$PAGE_TITLE = 'Nowe upoważnienie RODO';
include dirname(__DIR__) . '/includes/header.php';

// Dane formularza (POST lub prefill)
$_d = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : array_merge([
    'person_name'     => '', 'person_pesel' => '',
    'contract_type'   => $_ct, 'contract_id' => $_cid,
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

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="mb-0 fw-bold"><i class="bi bi-shield-plus text-primary me-2"></i>Nowe upoważnienie RODO</h4>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2"><ul class="mb-0 ps-3">
  <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
</ul></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
<input type="hidden" name="contract_type" value="<?= h($_ct ?: $_d['contract_type']) ?>">
<input type="hidden" name="contract_id"   value="<?= (int)($_cid ?: (int)($_d['contract_id'] ?? 0)) ?>">

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
    <div class="col-md-7">
      <label class="form-label small fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
      <input name="person_name" class="form-control" required value="<?= h($_d['person_name']) ?>">
    </div>
    <div class="col-md-5">
      <label class="form-label small fw-semibold">PESEL</label>
      <input name="person_pesel" class="form-control font-monospace" maxlength="11"
             value="<?= h($_d['person_pesel']) ?>">
    </div>
    <div class="col-md-5">
      <label class="form-label small fw-semibold">Nr porozumienia / umowy</label>
      <input name="contract_number" class="form-control" value="<?= h($_d['contract_number']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold">Data porozumienia</label>
      <input name="contract_date" type="date" class="form-control" value="<?= h($_d['contract_date']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">Upoważnienie od <span class="text-danger">*</span></label>
      <input name="authorized_from" type="date" class="form-control" required value="<?= h($_d['authorized_from']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">Upoważnienie do
        <span class="text-muted fw-normal">(puste = do wygaśnięcia umowy)</span>
      </label>
      <input name="authorized_until" type="date" class="form-control" value="<?= h($_d['authorized_until']) ?>">
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
