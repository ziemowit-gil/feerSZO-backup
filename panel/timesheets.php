<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timesheets.php';

require_login();
panel_require_enabled('godziny', 'Ewidencja godzin');
require_module_enabled('timesheets_enabled', 'Ewidencja godzin');

$PAGE_TITLE = 'Ewidencja godzin';
$user       = current_user();
$uid        = (int)$user['id'];
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);
$errors     = [];
$success    = '';

// ── Obsługa formularza POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Złóż / zapisz wpis
    if (in_array($action, ['save', 'submit'], true)) {
        $ts_id      = (int)($_POST['ts_id'] ?? 0);
        $contract_id= (int)($_POST['contract_id'] ?? 0);
        $rok        = (int)($_POST['rok'] ?? 0);
        $miesiac    = (int)($_POST['miesiac'] ?? 0);
        $godziny    = (float)str_replace(',', '.', $_POST['godziny'] ?? '0');
        $opis       = trim($_POST['opis'] ?? '');
        $new_status = ($action === 'submit') ? 'złożone' : 'szkic';

        // Walidacja
        if (!$contract_id) $errors[] = 'Wybierz umowę.';
        if ($rok < 2020 || $rok > (int)date('Y') + 1) $errors[] = 'Nieprawidłowy rok.';
        if ($miesiac < 1 || $miesiac > 12) $errors[] = 'Nieprawidłowy miesiąc.';
        if ($godziny < 0 || $godziny > 744) $errors[] = 'Nieprawidłowa liczba godzin.';
        if ($action === 'submit' && $godziny <= 0) $errors[] = 'Liczba godzin musi być większa od 0.';

        // Czy ta umowa należy do użytkownika?
        if (!$errors) {
            $allowed = ts_user_contracts($uid);
            $ok = array_filter($allowed, fn($c) => (int)$c['id'] === $contract_id);
            if (!$ok) $errors[] = 'Wybrana umowa nie należy do Twojego konta.';
        }

        if (!$errors) {
            if ($ts_id) {
                // Edycja — tylko własny wpis w statusie szkic/odrzucone
                $existing = db_one("SELECT * FROM timesheets WHERE id=?", [$ts_id]);
                if (!$existing || (int)$existing['user_id'] !== $uid) {
                    $errors[] = 'Brak dostępu do tego wpisu.';
                } elseif (!in_array($existing['status'], ['szkic', 'odrzucone'], true)) {
                    $errors[] = 'Nie można edytować wpisu o statusie: ' . $existing['status'];
                } else {
                    db()->prepare(
                        "UPDATE timesheets SET contract_id=?, rok=?, miesiac=?, godziny=?,
                         opis=?, status=?, updated_at=datetime('now'), uwagi_admin=NULL WHERE id=?"
                    )->execute([$contract_id, $rok, $miesiac, $godziny, $opis, $new_status, $ts_id]);
                    $success = $action === 'submit' ? 'Godziny złożone do zatwierdzenia.' : 'Zapisano szkic.';
                }
            } else {
                // Nowy wpis
                try {
                    db()->prepare(
                        "INSERT INTO timesheets (contract_id, user_id, rok, miesiac, godziny, opis, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    )->execute([$contract_id, $uid, $rok, $miesiac, $godziny, $opis, $new_status]);
                    $success = $action === 'submit' ? 'Godziny złożone do zatwierdzenia.' : 'Zapisano szkic.';
                } catch (\Throwable $e) {
                    $errors[] = 'Wpis za ' . ts_month_label($rok, $miesiac) . ' dla tej umowy już istnieje.';
                }
            }
        }
    }

    // Cofnij złożenie → szkic
    if ($action === 'retract') {
        $ts_id = (int)($_POST['ts_id'] ?? 0);
        $existing = $ts_id ? db_one("SELECT * FROM timesheets WHERE id=?", [$ts_id]) : null;
        if ($existing && (int)$existing['user_id'] === $uid && $existing['status'] === 'złożone') {
            db()->prepare("UPDATE timesheets SET status='szkic', updated_at=datetime('now') WHERE id=?")
                ->execute([$ts_id]);
            $success = 'Cofnięto — wpis jest teraz szkicem.';
        }
    }

    // Usuń szkic
    if ($action === 'delete') {
        $ts_id = (int)($_POST['ts_id'] ?? 0);
        $existing = $ts_id ? db_one("SELECT * FROM timesheets WHERE id=?", [$ts_id]) : null;
        if ($existing && (int)$existing['user_id'] === $uid && $existing['status'] === 'szkic') {
            db()->prepare("DELETE FROM timesheets WHERE id=?")->execute([$ts_id]);
            $success = 'Wpis usunięty.';
        }
    }

    if (!$errors) {
        header('Location: ' . APP_URL . '/panel/timesheets.php' . ($success ? '?ok=1' : '')); exit;
    }
}

$ok_msg = (isset($_GET['ok']) && !$errors) ? ($success ?: 'Operacja zakończona pomyślnie.') : '';

// ── Dane ──────────────────────────────────────────────────────────────────────
$contracts   = ts_user_contracts($uid);
$timesheets  = ts_user_timesheets($uid);
$edit_ts     = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_ts = db_one("SELECT * FROM timesheets WHERE id=?", [$edit_id]);
    if (!$edit_ts || (int)$edit_ts['user_id'] !== $uid) $edit_ts = null;
}

$show_form = isset($_GET['new']) || $edit_ts !== null || $errors;

// Domyślny miesiąc/rok = aktualny, ale nie przyszły
$def_rok    = (int)date('Y');
$def_miesiac= (int)date('n');
if ($def_miesiac === 0) { $def_miesiac = 12; $def_rok--; }

// ── Grupowanie wpisów wg umowy ────────────────────────────────────────────────
$grouped = [];
foreach ($timesheets as $ts) {
    $grouped[$ts['contract_id']]['numer']  = $ts['numer_umowy'];
    $grouped[$ts['contract_id']]['rows'][] = $ts;
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
      <h1 class="pv-page-title"><i class="bi bi-clock-history" aria-hidden="true"></i>Karty czasu pracy</h1>
      <p class="pv-page-sub">Ewidencja godzin wolontariackich</p>
    </div>
    <?php if ($contracts && !$show_form): ?>
    <div class="pv-page-head-actions">
      <a href="?new=1" class="tz-btn"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wpis</a>
    </div>
    <?php endif; ?>
  </div>

  <?php echo flash_html(); ?>

  <?php if ($ok_msg): ?>
  <div class="tz-note tz-note--success" role="alert">
    <i class="bi bi-check-circle me-1" aria-hidden="true"></i><?= h($ok_msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($errors): ?>
  <div class="tz-note tz-note--danger" role="alert">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$contracts): ?>
  <div class="tz-note tz-note--info">
    <i class="bi bi-info-circle me-2" aria-hidden="true"></i>
    Nie masz aktywnych umów wolontariackich. Ewidencja godzin jest dostępna po podpisaniu umowy.
  </div>
  <?php else: ?>

  <?php
  // ── Statystyki ─────────────────────────────────────────────────────────────
  $total_approved = 0; $total_all = 0; $pending_count = 0;
  foreach ($contracts as $c) {
      $total_approved += ts_total_approved((int)$c['id']);
      $total_all      += ts_total_all((int)$c['id']);
  }
  $pending_count = count(array_filter($timesheets, fn($t) => $t['status'] === 'złożone'));
  ?>
  <div class="tz-tiles-row">
    <div class="tz-tile">
      <span class="tz-tile__lbl">Zatwierdzone godziny</span>
      <span class="tz-tile__val tz-tile__val--ok"><?= number_format($total_approved, 1, ',', ' ') ?> h</span>
    </div>
    <div class="tz-tile">
      <span class="tz-tile__lbl">Wszystkie (łącznie)</span>
      <span class="tz-tile__val"><?= number_format($total_all, 1, ',', ' ') ?> h</span>
    </div>
    <?php if ($pending_count): ?>
    <div class="tz-tile tz-tile--warn">
      <span class="tz-tile__lbl">Oczekujące</span>
      <span class="tz-tile__val tz-tile__val--warn"><?= $pending_count ?></span>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($show_form): ?>
  <!-- ── Formularz dodawania/edycji ─────────────────────────────────────── -->
  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-pencil-square" aria-hidden="true"></i>
      <?= $edit_ts ? 'Edytuj wpis — ' . ts_month_label((int)$edit_ts['rok'], (int)$edit_ts['miesiac']) : 'Nowy wpis godzin' ?>
    </div>
    <div class="tz-card__bd">
      <form method="post" id="tsForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php if ($edit_ts): ?>
        <input type="hidden" name="ts_id" value="<?= (int)$edit_ts['id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
          <!-- Umowa -->
          <div class="col-md-12">
            <label class="form-label fw-semibold small" for="ts_contract_id">Umowa <span class="text-danger" aria-hidden="true">*</span></label>
            <select name="contract_id" id="ts_contract_id" class="form-select" required aria-required="true">
              <option value="">— wybierz umowę —</option>
              <?php foreach ($contracts as $c):
                $sel = ($edit_ts ? (int)$edit_ts['contract_id'] : (int)($_POST['contract_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '';
                $info = $c['numer_umowy'];
                if ($c['data_zawarcia']) $info .= ' (od ' . date_pl($c['data_zawarcia']) . ')';
              ?>
              <option value="<?= $c['id'] ?>" <?= $sel ?>><?= h($info) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Miesiąc / Rok -->
          <div class="col-6 col-md-4">
            <label class="form-label fw-semibold small" for="ts_miesiac">Miesiąc <span class="text-danger" aria-hidden="true">*</span></label>
            <select name="miesiac" id="ts_miesiac" class="form-select" required aria-required="true" aria-label="Wybierz miesiąc">
              <?php
              $cur_m = $edit_ts ? (int)$edit_ts['miesiac'] : (int)($_POST['miesiac'] ?? $def_miesiac);
              foreach (MIESIAC_PL as $num => $name):
                $sel = $cur_m === $num ? 'selected' : '';
              ?>
              <option value="<?= $num ?>" <?= $sel ?>><?= $name ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small" for="ts_rok">Rok <span class="text-danger" aria-hidden="true">*</span></label>
            <select name="rok" id="ts_rok" class="form-select" required aria-required="true" aria-label="Wybierz rok">
              <?php
              $cur_y = $edit_ts ? (int)$edit_ts['rok'] : (int)($_POST['rok'] ?? $def_rok);
              for ($y = $def_rok; $y >= 2020; $y--):
                $sel = $cur_y === $y ? 'selected' : '';
              ?>
              <option value="<?= $y ?>" <?= $sel ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold small" for="ts_godziny">Liczba godzin <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="input-group">
              <input name="godziny" id="ts_godziny" type="number" step="0.5" min="0" max="744"
                     class="form-control fw-bold"
                     value="<?= h($edit_ts ? $edit_ts['godziny'] : ($_POST['godziny'] ?? '')) ?>"
                     placeholder="np. 20" required aria-required="true">
              <span class="input-group-text text-muted" aria-hidden="true">h</span>
            </div>
          </div>

          <!-- Opis -->
          <div class="col-12">
            <label class="form-label fw-semibold small" for="ts_opis">Opis działań <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <textarea name="opis" id="ts_opis" class="form-control" rows="3"
                      placeholder="Krótki opis czynności wykonanych w tym miesiącu…"><?= h($edit_ts ? $edit_ts['opis'] : ($_POST['opis'] ?? '')) ?></textarea>
            <div class="form-text">Możesz wymienić działania, projekty lub zadania, przy których pracowałeś/aś.</div>
          </div>

          <?php if ($edit_ts && $edit_ts['status'] === 'odrzucone' && $edit_ts['uwagi_admin']): ?>
          <div class="col-12">
            <div class="tz-note tz-note--danger" role="alert">
              <strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Powód odrzucenia:</strong>
              <?= h($edit_ts['uwagi_admin']) ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="d-flex gap-2 mt-3 flex-wrap">
          <button type="submit" name="_action" value="save" class="tz-btn tz-btn--ghost">
            <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz szkic
          </button>
          <button type="submit" name="_action" value="submit" class="tz-btn">
            <i class="bi bi-send-check me-1" aria-hidden="true"></i>Złóż do zatwierdzenia
          </button>
          <a href="<?= APP_URL ?>/panel/timesheets.php" class="tz-btn tz-btn--ghost ms-auto">
            <i class="bi bi-x me-1" aria-hidden="true"></i>Anuluj
          </a>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Lista wpisów ─────────────────────────────────────────────────────── -->
  <?php if ($grouped): foreach ($grouped as $cid => $group): ?>
  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-heart" aria-hidden="true"></i>
      <?= h($group['numer']) ?>
    </div>
    <div class="tz-card__bd">
      <div class="pv-table-wrap">
        <table class="pv-table">
          <thead>
            <tr>
              <th scope="col">Miesiąc</th>
              <th scope="col">Godziny</th>
              <th scope="col">Status</th>
              <th scope="col"><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group['rows'] as $ts):
              $rejected = $ts['status'] === 'odrzucone';
            ?>
            <tr class="<?= $rejected ? 'tz-row--rejected' : '' ?>">
              <td>
                <div class="fw-semibold"><?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?></div>
                <?php if ($ts['opis']): ?>
                <div class="tz-row__note text-muted small" title="<?= h($ts['opis']) ?>"><?= h($ts['opis']) ?></div>
                <?php endif; ?>
                <?php if ($rejected && $ts['uwagi_admin']): ?>
                <div class="text-danger small mt-1">
                  <i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= h($ts['uwagi_admin']) ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="fw-bold text-primary text-nowrap"><?= number_format((float)$ts['godziny'], 1, ',', ' ') ?> h</td>
              <td><?= ts_badge($ts['status']) ?></td>
              <td>
                <div class="d-flex gap-1 flex-nowrap">
                  <?php if (in_array($ts['status'], ['szkic', 'odrzucone'])): ?>
                  <a href="?edit=<?= $ts['id'] ?>" class="tz-btn tz-btn--ghost tz-btn--sm"
                     title="Edytuj" aria-label="Edytuj wpis <?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?>">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                  </a>
                  <?php endif; ?>
                  <?php if ($ts['status'] === 'złożone'): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_action" value="retract">
                    <input type="hidden" name="ts_id" value="<?= $ts['id'] ?>">
                    <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm"
                            title="Cofnij do szkicu"
                            aria-label="Cofnij <?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?> do szkicu">
                      <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if ($ts['status'] === 'szkic'): ?>
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Usunąć ten wpis?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="ts_id" value="<?= $ts['id'] ?>">
                    <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm tz-btn--danger"
                            title="Usuń"
                            aria-label="Usuń wpis <?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?>">
                      <i class="bi bi-trash3" aria-hidden="true"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; else: ?>
  <div class="tz-card">
    <div class="tz-card__bd">
      <div class="tz-empty">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <p class="mt-2 mb-0">Nie masz jeszcze żadnych wpisów godzin.</p>
        <?php if (!$show_form): ?>
        <a href="?new=1" class="tz-btn mt-2">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj pierwszy wpis
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$show_form && $contracts): ?>
  <div class="d-flex justify-content-end mt-2">
    <a href="?new=1" class="tz-btn">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wpis za kolejny miesiąc
    </a>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div><!-- /.pv-wrap -->
<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
