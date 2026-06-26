<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();
panel_require_enabled('zaswiadczenia', 'Zaświadczenia');
$PAGE_TITLE = 'Moje zaświadczenia';
$user = current_user();

$_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

// ── Pobierz umowy użytkownika (ta sama logika co panel/index.php) ─────────────
function panel_contracts_cert(array $user): array {
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];
    $results = [];
    $tables = [
        ['zlecenie',    'imie_nazwisko', ['m365_user_id', 'm365_login'],           'data_zakonczenia'],
        ['wolontariat', 'imie_nazwisko', ['m365_user_id', 'm365_login', 'email'],  'data_zakonczenia'],
        ['dzielo',      'imie_nazwisko', ['m365_user_id', 'm365_login'],           'termin_oddania'],
        ['praca',       'imie_nazwisko', ['email_login'],                          'data_zakonczenia'],
    ];
    foreach ($tables as [$type, $name_col, $fields, $end_col]) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id' && !$ms_id) continue;
            if (($f === 'email' || $f === 'm365_login') && !$email) continue;
            $conds[]  = "{$f} = ?";
            $params[] = ($f === 'm365_user_id') ? $ms_id : $email;
        }
        if (!$conds) continue;
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status, {$name_col} AS strona,
                    data_zawarcia, {$end_col} AS data_zakonczenia
             FROM   umowy_{$type} WHERE (" . implode(' OR ', $conds) . ")",
            $params
        );
        $results = array_merge($results, $rows);
    }
    $seen = [];
    return array_values(array_filter($results, function ($r) use (&$seen) {
        $k = $r['contract_type'] . ':' . $r['id'];
        if (isset($seen[$k])) return false;
        return $seen[$k] = true;
    }));
}

$contracts = panel_contracts_cert($user);

// ── Moje wnioski o zaświadczenie ──────────────────────────────────────────────
$my_requests = get_user_certificate_requests($user['id']);

// ── Obsługa POST ──────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw   = $_POST['contract_type'] ?? '';
    [$type, $_cid_s] = array_pad(explode(':', $raw, 2), 2, '');
    $cid   = (int)$_cid_s;
    $name  = trim($_POST['requester_name'] ?? '');
    $email = trim($_POST['requester_email'] ?? '');
    $cel   = trim($_POST['cel'] ?? '');

    // Weryfikacja — umowa musi być na liście użytkownika
    $allowed = array_filter($contracts, fn($c) => $c['contract_type'] === $type && $c['id'] === $cid);
    if (!$allowed) $errors[] = 'Wybrana umowa nie należy do Twojego konta.';
    if (!$name)   $errors[] = 'Podaj imię i nazwisko.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj poprawny adres e-mail.';
    if (!$cel)    $errors[] = 'Podaj cel wydania zaświadczenia.';

    // Sprawdź czy nie ma już oczekującego wniosku dla tej umowy
    $existing = get_certificate_requests($type, $cid);
    $has_pending = !empty(array_filter($existing, fn($r) => $r['status'] === 'oczekuje'));
    if ($has_pending) $errors[] = 'Istnieje już oczekujący wniosek dla tej umowy. Poczekaj na decyzję.';

    if (!$errors) {
        $TABLE  = table_for_type($type);
        $row    = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);
        $req_id = create_certificate_request($type, $cid, $user['id'], $name, $email, $cel);

        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $cid, $user['id'], 'certificate_request',
            'Złożono wniosek o zaświadczenie (panel) dla: ' . $name);

        $new_req = ['requester_name' => $name, 'requester_email' => $email, 'cel' => $cel];
        _certificate_notify_admins($type, $row ?? [], $new_req);

        require_once dirname(__DIR__) . '/includes/notifications.php';
        $admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
        foreach ($admins as $adm) {
            notif_create((int)$adm['id'], 'system', 'Wniosek o zaświadczenie — ' . $user['name'], $cel, APP_URL . '/admin/certificates.php');
        }
        flash_set('success', 'Wniosek o zaświadczenie został złożony. Otrzymasz e-mail, gdy zostanie rozpatrzony.');
        header('Location: ' . APP_URL . '/panel/certificates.php');
        exit;
    }
}

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-award me-2" aria-hidden="true"></i>Zaświadczenia</h1>
  <p class="pv-page-sub">Wnioskuj o zaświadczenia z organizacji</p>
</div>

<?= flash_html() ?>

<div class="row g-4">

<div class="col-xl-5">
<div class="vol-detail-card mb-3">
  <div class="vol-detail-header"><i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Złóż wniosek</div>
  <div class="vol-detail-body">

    <?php if (!$contracts): ?>
    <div class="text-center py-3 text-muted">
      <i class="bi bi-exclamation-circle" style="font-size:2rem;opacity:.25" aria-hidden="true"></i>
      <p class="mt-2 mb-0 small">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    </div>
    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger small">
      <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" id="form-cert-request">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold">Umowa <span class="text-danger">*</span></label>
        <select name="contract_type" id="sel_type" class="form-select" required>
          <option value="">— wybierz umowę —</option>
          <?php
          $by_type = [];
          foreach ($contracts as $c) $by_type[$c['contract_type']][] = $c;
          foreach ($by_type as $typ => $list):
          ?>
          <optgroup label="<?= h(CONTRACT_TYPES[$typ] ?? $typ) ?>">
            <?php foreach ($list as $c):
                $opt_val = $typ . ':' . $c['id'];
                $sel_c   = ($_POST['contract_type'] ?? '') === $opt_val;
            ?>
            <option value="<?= h($opt_val) ?>" <?= $sel_c ? 'selected' : '' ?>>
              <?= h($c['numer_umowy']) ?>
              (<?= date_pl($c['data_zawarcia']) ?>)
              <?= $c['data_zakonczenia'] ? '– ' . date_pl($c['data_zakonczenia']) : '' ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Twoje imię i nazwisko <span class="text-danger">*</span></label>
        <input type="text" name="requester_name" class="form-control"
               value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">E-mail do odbioru zaświadczenia <span class="text-danger">*</span></label>
        <input type="email" name="requester_email" class="form-control"
               value="<?= h($_POST['requester_email'] ?? $user['email']) ?>" required>
        <div class="form-text">Na ten adres zostanie wysłane gotowe zaświadczenie.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Cel wydania zaświadczenia <span class="text-danger">*</span></label>
        <textarea name="cel" class="form-control" rows="3" required
                  placeholder="np. do urzędu skarbowego, dla banku, do ZUS…"><?= h($_POST['cel'] ?? '') ?></textarea>
      </div>

      <button type="submit" style="background:var(--vol-color);color:#fff;border:none;border-radius:8px;padding:.55rem 1.25rem;font-weight:600">
        <i class="bi bi-send me-1" aria-hidden="true"></i> Złóż wniosek
      </button>
    </form>

    <?php endif; ?>
  </div>
</div>

<div class="vol-detail-card">
  <div class="vol-detail-header"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Jak to działa?</div>
  <div class="vol-detail-body small text-muted">
    <ol class="ps-3 mb-0">
      <li class="mb-1">Złóż wniosek wskazując umowę i cel zaświadczenia.</li>
      <li class="mb-1">Fundacja rozpatrzy wniosek i przygotuje zaświadczenie.</li>
      <li>Gotowe zaświadczenie otrzymasz e-mailem z linkiem do pobrania PDF.</li>
    </ol>
  </div>
</div>
</div>

<div class="col-xl-7">
<?php
// Znormalizuj wnioski dla listy z filtrem (panel/includes/pv_cert_history.php)
$_pv_certs = array_map(function ($r) {
    try {
        $c_row = db_one("SELECT numer_umowy FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
        $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
    } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; }
    return [
        'id'             => (int)$r['id'],
        'status'         => $r['status'],
        'type_label'     => CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type'],
        'nr'             => $c_nr,
        'cel'            => $r['cel'],
        'rejection_note' => $r['rejection_note'] ?? '',
        'created_pl'     => date_pl($r['created_at']),
    ];
}, $my_requests);
include __DIR__ . '/includes/pv_cert_history.php';
?>
</div>

</div><!-- /row -->

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-award text-success fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Moje zaświadczenia</h4>
    <div class="text-muted small"><?= h($user['name']) ?></div>
  </div>
  <div class="ms-auto">
  </div>
</div>

<?= flash_html() ?>

<div class="row g-4">

<!-- ── Formularz wniosku ──────────────────────────────────────────────────── -->
<div class="col-xl-5">
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-plus-circle text-primary"></i> Złóż wniosek o zaświadczenie
  </div>
  <div class="card-body">

    <?php if (!$contracts): ?>
    <div class="alert alert-warning small mb-0">
      <i class="bi bi-exclamation-triangle"></i>
      Nie znaleziono umów powiązanych z Twoim kontem..
    </div>
    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger small">
      <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" id="form-cert-request">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label">Umowa <span class="text-danger">*</span></label>
        <select name="contract_type" id="sel_type" class="form-select" required>
          <option value="">— wybierz typ —</option>
          <?php
          // Grupuj po typie
          $by_type = [];
          foreach ($contracts as $c) $by_type[$c['contract_type']][] = $c;
          foreach ($by_type as $typ => $list):
          ?>
          <optgroup label="<?= h(CONTRACT_TYPES[$typ] ?? $typ) ?>">
            <?php foreach ($list as $c):
                $opt_val = $typ . ':' . $c['id'];
                $sel_c   = ($_POST['contract_type'] ?? '') === $opt_val;
            ?>
            <option value="<?= h($opt_val) ?>"
                    <?= $sel_c ? 'selected' : '' ?>>
              <?= h($c['numer_umowy']) ?>
              (<?= date_pl($c['data_zawarcia']) ?>)
              <?= $c['data_zakonczenia'] ? '– ' . date_pl($c['data_zakonczenia']) : '' ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Twoje imię i nazwisko <span class="text-danger">*</span></label>
        <input type="text" name="requester_name" class="form-control"
               value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label class="form-label">E-mail do odbioru zaświadczenia <span class="text-danger">*</span></label>
        <input type="email" name="requester_email" class="form-control"
               value="<?= h($_POST['requester_email'] ?? $user['email']) ?>" required>
        <div class="form-text">Na ten adres zostanie wysłane gotowe zaświadczenie.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Cel wydania zaświadczenia <span class="text-danger">*</span></label>
        <textarea name="cel" class="form-control" rows="3" required
                  placeholder="np. do urzędu skarbowego, dla banku, do ZUS, na potrzeby przetargu..."><?= h($_POST['cel'] ?? '') ?></textarea>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-send"></i> Złóż wniosek
        </button>
      </div>
    </form>

    <?php endif; ?>
  </div>
</div>

<!-- Informacja -->
<div class="card shadow-sm mt-3">
  <div class="card-body small text-muted">
    <p class="mb-2"><i class="bi bi-info-circle text-primary"></i> <strong>Jak to działa?</strong></p>
    <ol class="ps-3 mb-0">
      <li class="mb-1">Złóż wniosek wskazując umowę i cel zaświadczenia.</li>
      <li class="mb-1">Fundacja rozpatrzy wniosek i przygotuje zaświadczenie
          (wygenerowane lub skan/ePodpis).</li>
      <li>Gotowe zaświadczenie otrzymasz e-mailem z linkiem do wydruku lub pliku do pobrania.</li>
    </ol>
  </div>
</div>
</div>

<!-- ── Moje wnioski ─────────────────────────────────────────────────────────── -->
<div class="col-xl-7">
<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-check text-success"></i>
    Historia wniosków
    <?php if ($my_requests): ?>
    <span class="badge bg-secondary ms-1"><?= count($my_requests) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$my_requests): ?>
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-award fs-1 d-block mb-2 opacity-25"></i>
    Nie masz jeszcze żadnych wniosków o zaświadczenie.
  </div>
  <?php else: ?>
  <div class="list-group list-group-flush">
  <?php foreach ($my_requests as $r):
      $stat = CERTIFICATE_STATUSES[$r['status']] ?? ['label' => $r['status'], 'class' => 'secondary'];
      // Pobierz numer umowy
      try {
          $c_row = db_one("SELECT numer_umowy FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
          $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
      } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; }
  ?>
  <div class="list-group-item px-4 py-3">
    <div class="d-flex align-items-start gap-3">
      <div class="mt-1">
        <?php if ($r['status'] === 'oczekuje'): ?>
        <i class="bi bi-clock-history text-warning fs-5"></i>
        <?php elseif ($r['status'] === 'wydane'): ?>
        <i class="bi bi-award-fill text-success fs-5"></i>
        <?php else: ?>
        <i class="bi bi-x-circle text-danger fs-5"></i>
        <?php endif; ?>
      </div>
      <div class="flex-grow-1 min-w-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="fw-semibold small">
            <?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?>
            · <?= h($c_nr) ?>
          </span>
          <?= certificate_status_badge($r['status']) ?>
        </div>
        <div class="small text-muted mt-1">
          Cel: <?= h($r['cel']) ?>
        </div>
        <div class="small text-muted">
          Złożono: <?= date_pl($r['created_at']) ?>
          <?php if ($r['issued_at']): ?>
          · Rozpatrzono: <?= date_pl($r['issued_at']) ?>
          <?php endif; ?>
        </div>
        <?php if ($r['rejection_note']): ?>
        <div class="small text-danger mt-1">
          <i class="bi bi-chat-left-text"></i> <?= h($r['rejection_note']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="flex-shrink-0 d-flex flex-column gap-1 align-items-end">
        <?php if ($r['status'] === 'wydane'): ?>
        <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $r['id'] ?>"
           target="_blank" class="btn btn-sm btn-success">
          <i class="bi bi-download"></i> Odbierz
        </a>
        <?php elseif ($r['status'] === 'oczekuje'): ?>
        <span class="text-muted small">W trakcie&hellip;</span>
        <?php endif; ?>
        <a href="<?= h(contract_url($r['contract_type'], $r['contract_id'])) ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-eye"></i>
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>

</div><!-- /row -->

<?php endif; /* $_is_volunteer_only */ ?>

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
