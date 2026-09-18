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
    $variant       = ($type === 'wolontariat') ? (trim($_POST['variant'] ?? '') ?: 'standard_14') : null;
    if ($variant !== null && !isset(TERMINATION_VARIANTS[$variant])) $variant = 'standard_14';

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
        $req_id = create_termination_request(
            $type, $cid, $user['id'], $name, $powod, $proposed_date,
            $type === 'wolontariat' ? 'wolontariusz' : null, $variant
        );

        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $cid, $user['id'], 'termination_request',
            'Złożono wniosek o rozwiązanie przez: ' . $name);

        $_req_saved = db_one("SELECT * FROM contract_termination_requests WHERE id=?", [$req_id]);
        _termination_notify_admins(
            $type, $row ?? [], $name, $powod, $proposed_date,
            $_req_saved['initiator'] ?? null, $_req_saved['variant'] ?? null, $_req_saved['effective_date'] ?? null
        );
        if ($type === 'wolontariat' && $row) {
            _termination_notify_volunteer_new_request($req_id, $row);
        }

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

<div class="pv-wrap">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
      <h1 class="pv-page-title"><i class="bi bi-file-earmark-x" aria-hidden="true"></i>Rozwiązanie umowy</h1>
      <p class="pv-page-sub">Wniosek o rozwiązanie umowy wolontariackiej</p>
    </div>
  </div>

  <?= flash_html() ?>

  <?php if ($_is_volunteer_only): ?>

  <div class="tz-note">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <div>
      <strong>To jest poważna decyzja.</strong> Upewnij się, że chcesz rozwiązać umowę przed złożeniem wniosku.
      Wniosek wymaga akceptacji administratora — umowa nie zostanie rozwiązana automatycznie.
    </div>
  </div>

  <?php if ($my_requests): ?>
  <div class="vol-data-grid mb-4">
    <?php foreach ($my_requests as $r): ?>
    <div class="vol-data-item">
      <div class="vol-data-lbl">Status wniosku</div>
      <div class="vol-data-val"><?= termination_status_badge($r['status']) ?></div>
    </div>
    <?php break; // pokazuj tylko ostatni ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="tz-card mb-4">
    <div class="tz-card__hd"><i class="bi bi-file-earmark-x" aria-hidden="true"></i>Złóż wniosek o rozwiązanie</div>
    <div class="tz-card__bd">

      <?php if (!$contracts): ?>
      <div class="tz-empty">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <p>Brak aktywnych umów, które można rozwiązać (status: Podpisana, W realizacji lub Obowiązująca).</p>
      </div>
      <?php else: ?>

      <?php if ($errors): ?>
      <div class="pv-alert pv-alert-err" role="alert">
        <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
      </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3">
          <label for="sel_type" class="form-label fw-semibold">Umowa do rozwiązania <span class="text-danger" aria-hidden="true">*</span></label>
          <select name="contract_type" id="sel_type" class="form-select" required aria-required="true">
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
          <label for="requester_name" class="form-label fw-semibold">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="text" name="requester_name" id="requester_name" class="form-control"
                 value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required aria-required="true">
        </div>
        <div class="mb-3">
          <label for="powod" class="form-label fw-semibold">Powód rozwiązania <span class="text-danger" aria-hidden="true">*</span></label>
          <textarea name="powod" id="powod" class="form-control" rows="4" required aria-required="true"
                    placeholder="Opisz krótko powód złożenia wniosku o rozwiązanie umowy..."><?= h($_POST['powod'] ?? '') ?></textarea>
        </div>
        <div class="mb-3" id="terminationVariantWrap" style="display:none">
          <label for="variant" class="form-label fw-semibold">Tryb rozwiązania</label>
          <select name="variant" id="variant" class="form-select">
            <?php foreach (TERMINATION_VARIANTS as $vk => $vdef): ?>
            <option value="<?= h($vk) ?>" <?= ($_POST['variant'] ?? 'standard_14') === $vk ? 'selected' : '' ?>>
              <?= h($vdef['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Okres wypowiedzenia zgodny z § 7 porozumienia wolontariackiego.</div>
        </div>
        <div class="mb-4">
          <label for="proposed_date" class="form-label fw-semibold">Proponowana data rozwiązania <span class="text-muted fw-normal small">(opcjonalnie)</span></label>
          <input type="date" name="proposed_date" id="proposed_date" class="form-control"
                 value="<?= h($_POST['proposed_date'] ?? '') ?>"
                 min="<?= date('Y-m-d') ?>">
          <div class="form-text">Pozostaw puste, aby datę wyliczyć automatycznie z wybranego trybu (lub ustaliło ją administrator).</div>
        </div>
        <button type="submit" class="tz-btn">
          <i class="bi bi-send" aria-hidden="true"></i> Złóż wniosek o rozwiązanie
        </button>
      </form>
      <script>
      (function () {
        var sel = document.getElementById('sel_type');
        var wrap = document.getElementById('terminationVariantWrap');
        if (!sel || !wrap) return;
        function toggle() { wrap.style.display = sel.value.indexOf('wolontariat:') === 0 ? '' : 'none'; }
        sel.addEventListener('change', toggle);
        toggle();
      })();
      </script>

      <?php endif; ?>

    </div>
  </div>

  <?php if ($my_requests): ?>
  <?php
  // Znormalizuj wnioski dla listy z filtrem (panel/includes/pv_term_history.php)
  $_pv_terms = array_map(function ($r) {
      try {
          $c_row = db_one("SELECT numer_umowy FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
          $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
      } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; }
      return [
          'status'        => $r['status'],
          'type_label'    => CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type'],
          'nr'            => $c_nr,
          'powod'         => mb_strimwidth((string)$r['powod'], 0, 80, '…'),
          'decision_note' => $r['decision_note'] ?? '',
          'created_pl'    => date_pl($r['created_at']),
          'variant_label' => termination_variant_label($r['variant'] ?? null),
          'effective_pl'  => !empty($r['effective_date']) ? date_pl($r['effective_date']) : '',
      ];
  }, $my_requests);
  include __DIR__ . '/includes/pv_term_history.php';
  ?>
  <?php endif; ?>

  <?php else: ?>

  <div class="row g-4">

    <!-- ── Formularz ─────────────────────────────────────────────────────────── -->
    <div class="col-xl-5">
    <div class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-file-earmark-x" aria-hidden="true"></i> Nowy wniosek
      </div>
      <div class="tz-card__bd">

        <?php if (!$contracts): ?>
        <div class="tz-note mb-0">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          <span>Brak aktywnych umów powiązanych z Twoim kontem, które można rozwiązać
          (status: Podpisana, W realizacji lub Obowiązująca).</span>
        </div>

        <?php else: ?>

        <?php if ($errors): ?>
        <div class="pv-alert pv-alert-err" role="alert">
          <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
        </div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label for="sel_type" class="form-label">Umowa do rozwiązania <span class="text-danger" aria-hidden="true">*</span></label>
            <select name="contract_type" id="sel_type" class="form-select" required aria-required="true">
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
            <label for="requester_name" class="form-label">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" name="requester_name" id="requester_name" class="form-control"
                   value="<?= h($_POST['requester_name'] ?? $user['name']) ?>" required aria-required="true">
          </div>

          <div class="mb-3">
            <label for="proposed_date" class="form-label">Proponowana data rozwiązania <span class="text-muted small">(opcjonalnie)</span></label>
            <input type="date" name="proposed_date" id="proposed_date" class="form-control"
                   value="<?= h($_POST['proposed_date'] ?? '') ?>"
                   min="<?= date('Y-m-d') ?>">
            <div class="form-text">Pozostaw puste, jeśli data ma zostać ustalona przez administratora.</div>
          </div>

          <div class="mb-3">
            <label for="powod" class="form-label">Powód rozwiązania <span class="text-danger" aria-hidden="true">*</span></label>
            <textarea name="powod" id="powod" class="form-control" rows="4" required aria-required="true"
                      placeholder="Opisz krótko powód złożenia wniosku o rozwiązanie umowy..."><?= h($_POST['powod'] ?? '') ?></textarea>
          </div>

          <div class="mb-3" id="terminationVariantWrap" style="display:none">
            <label for="variant" class="form-label">Tryb rozwiązania</label>
            <select name="variant" id="variant" class="form-select">
              <?php foreach (TERMINATION_VARIANTS as $vk => $vdef): ?>
              <option value="<?= h($vk) ?>" <?= ($_POST['variant'] ?? 'standard_14') === $vk ? 'selected' : '' ?>>
                <?= h($vdef['label']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Okres wypowiedzenia zgodny z § 7 porozumienia wolontariackiego.</div>
          </div>

          <div class="tz-note mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>Wniosek zostanie rozpatrzony przez administratora. Umowa zostanie rozwiązana
            dopiero po formalnej akceptacji.</span>
          </div>

          <div class="d-grid">
            <button type="submit" class="tz-btn">
              <i class="bi bi-send" aria-hidden="true"></i> Złóż wniosek o rozwiązanie
            </button>
          </div>
        </form>
        <script>
        (function () {
          var sel = document.getElementById('sel_type');
          var wrap = document.getElementById('terminationVariantWrap');
          if (!sel || !wrap) return;
          function toggle() { wrap.style.display = sel.value.indexOf('wolontariat:') === 0 ? '' : 'none'; }
          sel.addEventListener('change', toggle);
          toggle();
        })();
        </script>

        <?php endif; ?>
      </div>
    </div>
    </div>

    <!-- ── Historia wniosków ──────────────────────────────────────────────────── -->
    <div class="col-xl-7">
    <div class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-list-check" aria-hidden="true"></i>
        Moje wnioski o rozwiązanie
        <?php if ($my_requests): ?>
        <span class="tz-badge ms-1"><?= count($my_requests) ?></span>
        <?php endif; ?>
      </div>

      <?php if (!$my_requests): ?>
      <div class="tz-card__bd">
        <div class="tz-empty">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          <p>Brak złożonych wniosków o rozwiązanie.</p>
        </div>
      </div>
      <?php else: ?>
      <div class="pv-table-wrap">
        <table class="pv-table">
          <thead>
            <tr>
              <th scope="col">Status</th>
              <th scope="col">Umowa</th>
              <th scope="col">Szczegóły</th>
              <th scope="col"><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($my_requests as $r):
              try {
                  $c_row = db_one("SELECT numer_umowy, status FROM " . table_for_type($r['contract_type']) . " WHERE id=?", [$r['contract_id']]);
                  $c_nr  = $c_row['numer_umowy'] ?? "#{$r['contract_id']}";
                  $c_status = $c_row['status'] ?? '';
              } catch (\Exception $e) { $c_nr = "#{$r['contract_id']}"; $c_status = ''; }
          ?>
          <tr>
            <td><?= termination_status_badge($r['status']) ?></td>
            <td>
              <div class="fw-semibold small"><?= h(CONTRACT_TYPES[$r['contract_type']] ?? $r['contract_type']) ?> · <?= h($c_nr) ?></div>
              <?php if ($c_status): ?><?= status_badge($c_status) ?><?php endif; ?>
            </td>
            <td>
              <div class="small">Powód: <?= h($r['powod']) ?></div>
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
                <i class="bi bi-chat-left-text" aria-hidden="true"></i> <?= h($r['decision_note']) ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= h(contract_url($r['contract_type'], $r['contract_id'])) ?>"
                 class="tz-btn tz-btn--ghost">
                <i class="bi bi-eye" aria-hidden="true"></i>
                <span class="visually-hidden">Pokaż umowę</span>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    </div>

  </div>

  <?php endif; ?>

</div>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
