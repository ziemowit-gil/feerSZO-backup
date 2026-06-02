<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/termination.php';

require_login();
$PAGE_TITLE = 'Wniosek o rozwiązanie umowy';
$user = current_user();

$_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// ── Umowy użytkownika (aktywne / rozwiązywalne) ───────────────────────────────
function panel_terminable_contracts(array $user): array {
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
        $placeholders = implode(' OR ', $conds);
        $status_in    = "'" . implode("','", TERMINABLE_STATUSES) . "'";
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status,
                    {$name_col} AS strona, data_zawarcia, {$end_col} AS data_zakonczenia
             FROM   umowy_{$type}
             WHERE  ({$placeholders}) AND status IN ({$status_in})",
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

$contracts    = panel_terminable_contracts($user);
$my_requests  = get_user_termination_requests($user['id']);

// Indeks oczekujących wniosków per umowa
$pending_map = [];
foreach ($my_requests as $r) {
    if ($r['status'] === 'oczekuje') {
        $pending_map[$r['contract_type'] . ':' . $r['contract_id']] = true;
    }
}

// ── POST ──────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw           = $_POST['contract_type'] ?? '';
    [$type, $_cid_s] = array_pad(explode(':', $raw, 2), 2, '');
    $cid           = (int)$_cid_s;
    $name          = trim($_POST['requester_name'] ?? '');
    $powod         = trim($_POST['powod'] ?? '');
    $proposed_date = trim($_POST['proposed_date'] ?? '') ?: null;

    $allowed = array_filter($contracts, fn($c) => $c['contract_type'] === $type && $c['id'] === $cid);
    if (!$allowed) $errors[] = 'Wybrana umowa nie należy do Twojego konta lub nie podlega rozwiązaniu.';
    if (!$name)   $errors[] = 'Podaj imię i nazwisko.';
    if (!$powod)  $errors[] = 'Podaj powód rozwiązania umowy.';

    if (!$errors && get_pending_termination_for_contract($type, $cid)) {
        $errors[] = 'Istnieje już oczekujący wniosek o rozwiązanie tej umowy.';
    }

    if (!$errors) {
        $TABLE = table_for_type($type);
        $row   = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$cid]);
        $req_id = create_termination_request($type, $cid, $user['id'], $name, $powod, $proposed_date);

        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $cid, $user['id'], 'termination_request',
            'Złożono wniosek o rozwiązanie przez: ' . $name);

        _termination_notify_admins($type, $row ?? [], $name, $powod, $proposed_date);

        require_once dirname(__DIR__) . '/includes/notifications.php';
        $admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
        foreach ($admins as $adm) {
            notif_create((int)$adm['id'], 'system', 'Wniosek o rozwiązanie umowy — ' . $user['name'], $powod, APP_URL . '/admin/terminations.php');
        }
        flash_set('success', 'Wniosek o rozwiązanie umowy został złożony. Administrator rozpatrzy go i skontaktuje się z Tobą.');
        header('Location: ' . APP_URL . '/panel/terminations.php');
        exit;
    }
}

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-file-earmark-x me-2" aria-hidden="true"></i>Rozwiązanie umowy</h1>
  <p class="pv-page-sub">Złóż wniosek o rozwiązanie umowy</p>
</div>
<?php echo flash_html(); ?>

<div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div class="small">
    <strong>To jest poważna decyzja.</strong> Upewnij się, że chcesz rozwiązać umowę przed złożeniem wniosku.
    Wniosek wymaga akceptacji administratora — umowa nie zostanie rozwiązana automatycznie.
  </div>
</div>

<?php if ($my_requests): ?>
<div class="vol-data-grid mb-4">
  <?php foreach ($my_requests as $r):
      $r_color = $r['status'] === 'oczekuje' ? '#F59E0B' : ($r['status'] === 'zaakceptowany' ? '#10B981' : '#EF4444');
  ?>
  <div class="vol-data-item">
    <div class="vol-data-lbl">Status wniosku</div>
    <div class="vol-data-val" style="color:<?= $r_color ?>;font-size:.8rem"><?= termination_status_badge($r['status']) ?></div>
  </div>
  <?php break; // pokazuj tylko ostatni ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-file-earmark-x me-2" aria-hidden="true"></i>Złóż wniosek o rozwiązanie</div>
  <div class="vol-detail-body">

<?php if (!$contracts): ?>
<div class="text-center py-3 text-muted">
  <i class="bi bi-info-circle" style="font-size:2rem;opacity:.25" aria-hidden="true"></i>
  <p class="mt-2 mb-0 small">Brak aktywnych umów, które można rozwiązać (status: Podpisana, W realizacji lub Obowiązująca).</p>
</div>
<?php else: ?>

<?php if ($errors): ?>
<div class="alert alert-danger small">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="mb-3">
    <label class="form-label fw-semibold">Umowa do rozwiązania <span class="text-danger">*</span></label>
    <select name="contract_type" id="sel_type" class="form-select" required>
      <option value="">— wybierz —</option>
      <?php
      $by_type = [];
      foreach ($contracts as $c) $by_type[$c['contract_type']][] = $c;
      foreach ($by_type as $typ => $list):
      ?>
      <optgroup label="<?= h(CONTRACT_TYPES[$typ] ?? $typ) ?>">
        <?php foreach ($list as $c):
            $key     = $typ . ':' . $c['id'];
            $has_p   = isset($pending_map[$key]);
            $opt_val = $typ . ':' . $c['id'];
            $sel_c   = ($_POST['contract_type'] ?? '') === $opt_val;
        ?>
        <option value="<?= h($opt_val) ?>"
                <?= $sel_c ? 'selected' : '' ?>
                <?= $has_p ? 'disabled' : '' ?>>
          <?= h($c['numer_umowy']) ?> — <?= h(STATUS_LABELS[$c['status']]['label'] ?? $c['status']) ?>
          <?= $has_p ? ' (wniosek w toku)' : '' ?>
        </option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
    <input type="text" name="requester_name" class="form-control"
           value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Powód rozwiązania <span class="text-danger">*</span></label>
    <textarea name="powod" class="form-control" rows="4" required
              placeholder="Opisz krótko powód złożenia wniosku o rozwiązanie umowy..."><?= h($_POST['powod'] ?? '') ?></textarea>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Proponowana data rozwiązania <span class="text-muted fw-normal small">(opcjonalnie)</span></label>
    <input type="date" name="proposed_date" class="form-control"
           value="<?= h($_POST['proposed_date'] ?? '') ?>"
           min="<?= date('Y-m-d') ?>">
    <div class="form-text">Pozostaw puste, jeśli data ma zostać ustalona przez administratora.</div>
  </div>
  <button type="submit" style="background:#DC2626;color:#fff;border:none;border-radius:8px;padding:.55rem 1.25rem;font-weight:600">
    <i class="bi bi-send me-1" aria-hidden="true"></i> Złóż wniosek o rozwiązanie
  </button>
</form>

<?php endif; ?>

  </div>
</div>

<?php if ($my_requests): ?>
<div class="vol-detail-card">
  <div class="vol-detail-header">
    <i class="bi bi-list-check me-2" aria-hidden="true"></i>Moje wnioski o rozwiązanie
    <span class="badge bg-secondary ms-auto"><?= count($my_requests) ?></span>
  </div>
  <?php foreach ($my_requests as $r):
      try {
          $c_row = db_one("SELECT numer_umowy, status FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
          $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
          $c_status = $c_row['status'] ?? '';
      } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; $c_status = ''; }
      $r_icon  = $r['status'] === 'oczekuje' ? 'bi-clock-history' : ($r['status'] === 'zaakceptowany' ? 'bi-check-circle-fill' : 'bi-x-circle-fill');
      $r_color = $r['status'] === 'oczekuje' ? 'warning' : ($r['status'] === 'zaakceptowany' ? 'success' : 'danger');
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $r_color ?> bg-opacity-15 text-<?= $r_color ?>">
      <i class="bi <?= $r_icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold" style="font-size:.85rem">
          <?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?> · <?= h($c_nr) ?>
        </span>
        <?= termination_status_badge($r['status']) ?>
      </div>
      <div class="text-muted" style="font-size:.78rem">Powód: <?= h(mb_strimwidth($r['powod'], 0, 80, '…')) ?></div>
      <?php if ($r['decision_note']): ?>
      <div class="<?= $r['status'] === 'odrzucony' ? 'text-danger' : 'text-muted' ?>" style="font-size:.78rem">
        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($r['decision_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.77rem"><?= date_pl($r['created_at']) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-file-earmark-x text-danger fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Wniosek o rozwiązanie umowy</h4>
    <div class="text-muted small"><?= h($user['name']) ?></div>
  </div>
  <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left"></i> Mój panel
  </a>
</div>

<?= flash_html() ?>

<div class="row g-4">

<!-- ── Formularz ─────────────────────────────────────────────────────────── -->
<div class="col-xl-5">
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-file-earmark-x text-danger"></i> Nowy wniosek
  </div>
  <div class="card-body">

    <?php if (!$contracts): ?>
    <div class="alert alert-info small mb-0">
      <i class="bi bi-info-circle"></i>
      Brak aktywnych umów powiązanych z Twoim kontem, które można rozwiązać
      (status: Podpisana, W realizacji lub Obowiązująca).
    </div>

    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger small">
      <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-3">
        <label class="form-label">Umowa do rozwiązania <span class="text-danger">*</span></label>
        <select name="contract_type" id="sel_type" class="form-select" required>
          <option value="">— wybierz —</option>
          <?php
          $by_type = [];
          foreach ($contracts as $c) $by_type[$c['contract_type']][] = $c;
          foreach ($by_type as $typ => $list):
          ?>
          <optgroup label="<?= h(CONTRACT_TYPES[$typ] ?? $typ) ?>">
            <?php foreach ($list as $c):
                $key     = $typ . ':' . $c['id'];
                $has_p   = isset($pending_map[$key]);
                $opt_val = $typ . ':' . $c['id'];
                $sel_c   = ($_POST['contract_type'] ?? '') === $opt_val;
            ?>
            <option value="<?= h($opt_val) ?>"
                    <?= $sel_c ? 'selected' : '' ?>
                    <?= $has_p ? 'disabled' : '' ?>>
              <?= h($c['numer_umowy']) ?> — <?= h(STATUS_LABELS[$c['status']]['label'] ?? $c['status']) ?>
              <?= $has_p ? ' (wniosek w toku)' : '' ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Imię i nazwisko <span class="text-danger">*</span></label>
        <input type="text" name="requester_name" class="form-control"
               value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Proponowana data rozwiązania <span class="text-muted small">(opcjonalnie)</span></label>
        <input type="date" name="proposed_date" class="form-control"
               value="<?= h($_POST['proposed_date'] ?? '') ?>"
               min="<?= date('Y-m-d') ?>">
        <div class="form-text">Pozostaw puste, jeśli data ma zostać ustalona przez administratora.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Powód rozwiązania <span class="text-danger">*</span></label>
        <textarea name="powod" class="form-control" rows="4" required
                  placeholder="Opisz krótko powód złożenia wniosku o rozwiązanie umowy..."><?= h($_POST['powod'] ?? '') ?></textarea>
      </div>

      <div class="alert alert-warning small py-2">
        <i class="bi bi-exclamation-triangle"></i>
        Wniosek zostanie rozpatrzony przez administratora. Umowa zostanie rozwiązana
        dopiero po formalnej akceptacji.
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-danger">
          <i class="bi bi-send"></i> Złóż wniosek o rozwiązanie
        </button>
      </div>
    </form>

    <?php endif; ?>
  </div>
</div>
</div>

<!-- ── Historia wniosków ──────────────────────────────────────────────────── -->
<div class="col-xl-7">
<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-check"></i>
    Moje wnioski o rozwiązanie
    <?php if ($my_requests): ?>
    <span class="badge bg-secondary ms-1"><?= count($my_requests) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$my_requests): ?>
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
    Brak złożonych wniosków o rozwiązanie.
  </div>
  <?php else: ?>
  <div class="list-group list-group-flush">
  <?php foreach ($my_requests as $r):
      try {
          $c_row = db_one("SELECT numer_umowy, status FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
          $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
          $c_status = $c_row['status'] ?? '';
      } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; $c_status = ''; }
  ?>
  <div class="list-group-item px-4 py-3">
    <div class="d-flex align-items-start gap-3">
      <div class="mt-1">
        <?php if ($r['status'] === 'oczekuje'): ?>
        <i class="bi bi-clock-history text-warning fs-5"></i>
        <?php elseif ($r['status'] === 'zaakceptowany'): ?>
        <i class="bi bi-check-circle-fill text-success fs-5"></i>
        <?php else: ?>
        <i class="bi bi-x-circle-fill text-danger fs-5"></i>
        <?php endif; ?>
      </div>
      <div class="flex-grow-1 min-w-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="fw-semibold small">
            <?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?>
            · <?= h($c_nr) ?>
          </span>
          <?= termination_status_badge($r['status']) ?>
          <?php if ($c_status): ?>
          <?= status_badge($c_status) ?>
          <?php endif; ?>
        </div>
        <div class="small text-muted mt-1">Powód: <?= h($r['powod']) ?></div>
        <?php if ($r['proposed_date']): ?>
        <div class="small text-muted">Proponowana data: <?= date_pl($r['proposed_date']) ?></div>
        <?php endif; ?>
        <div class="small text-muted">
          Złożono: <?= date_pl($r['created_at']) ?>
          <?php if ($r['decided_at']): ?>
          · Rozpatrzono: <?= date_pl($r['decided_at']) ?>
          <?php if ($r['decided_by_name']): ?>
          przez <?= h($r['decided_by_name']) ?>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($r['decision_note']): ?>
        <div class="small mt-1 <?= $r['status'] === 'odrzucony' ? 'text-danger' : 'text-muted' ?>">
          <i class="bi bi-chat-left-text"></i> <?= h($r['decision_note']) ?>
        </div>
        <?php endif; ?>
      </div>
      <a href="<?= h(contract_url($r['contract_type'], $r['contract_id'])) ?>"
         class="btn btn-sm btn-outline-secondary flex-shrink-0">
        <i class="bi bi-eye"></i>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>

</div>

<?php endif; ?>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
