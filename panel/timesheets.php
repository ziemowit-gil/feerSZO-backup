<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timesheets.php';

require_login();
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
<style>
.ts-card { background:#fff; border:1px solid #e2e8f0; border-radius:.6rem; overflow:hidden; margin-bottom:1.25rem; }
.ts-card-head {
  padding:.6rem 1rem; background:#f8fafc; border-bottom:1px solid #e2e8f0;
  display:flex; align-items:center; gap:.5rem; font-size:.8rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em; color:#475569;
}
.ts-card-head i { color:#2563eb; font-size:1rem; }
.ts-card-body { padding:1rem; }
.ts-row {
  display:grid; grid-template-columns: 1fr auto auto auto;
  align-items:center; gap:.75rem;
  padding:.55rem .75rem; border-radius:.4rem; margin-bottom:.35rem;
  background:#f8fafc; border:1px solid #e9ecef;
}
.ts-row:last-child { margin-bottom:0; }
.ts-month { font-weight:600; font-size:.88rem; color:#1e293b; }
.ts-hours { font-size:1rem; font-weight:700; color:#2563eb; white-space:nowrap; }
.ts-note  { font-size:.75rem; color:#64748b; margin-top:.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; }
.ts-actions { display:flex; gap:.35rem; flex-shrink:0; }
.ts-empty { text-align:center; padding:2rem 1rem; color:#94a3b8; }
.ts-rejected { background:#fff5f5; border-color:#fca5a5; }
.ts-rejected .ts-month { color:#dc2626; }
.ts-form-card { background:#fff; border:1px solid #bfdbfe; border-radius:.7rem; padding:1.25rem; margin-bottom:1.5rem; border-left:4px solid #2563eb; }
.ts-form-title { font-weight:700; font-size:.95rem; color:#1e3a8a; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.ts-summary-row { display:flex; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.ts-stat { background:#fff; border:1px solid #e2e8f0; border-radius:.5rem; padding:.6rem 1rem; min-width:140px; }
.ts-stat-lbl { font-size:.67rem; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; font-weight:700; }
.ts-stat-val { font-size:1.3rem; font-weight:800; color:#1e293b; }
</style>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Ewidencja godzin</h1>
  <p class="pv-page-sub">Rejestruj przepracowane godziny</p>
</div>
<?php echo flash_html(); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Ewidencja godzin</h4>
    <div class="text-muted small mt-1">Miesięczne raporty przepracowanych godzin wolontariackich</div>
  </div>
  <?php if ($contracts && !$show_form): ?>
  <a href="?new=1" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Dodaj wpis
  </a>
  <?php endif; ?>
</div>

<?php if ($ok_msg): ?>
<div class="alert alert-success alert-dismissible py-2 fade show">
  <i class="bi bi-check-circle me-1"></i><?= h($ok_msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
  <ul class="mb-0 ps-3">
    <?php foreach ($errors as $e) echo '<li class="small">' . h($e) . '</li>'; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$contracts): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
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
<div class="ts-summary-row">
  <div class="ts-stat">
    <div class="ts-stat-lbl">Zatwierdzone godziny</div>
    <div class="ts-stat-val text-success"><?= number_format($total_approved, 1, ',', ' ') ?> h</div>
  </div>
  <div class="ts-stat">
    <div class="ts-stat-lbl">Wszystkie (łącznie)</div>
    <div class="ts-stat-val"><?= number_format($total_all, 1, ',', ' ') ?> h</div>
  </div>
  <?php if ($pending_count): ?>
  <div class="ts-stat" style="border-color:#fde68a;background:#fefce8">
    <div class="ts-stat-lbl">Oczekujące</div>
    <div class="ts-stat-val text-warning"><?= $pending_count ?></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($show_form): ?>
<!-- ── Formularz dodawania/edycji ─────────────────────────────────────── -->
<div class="ts-form-card">
  <div class="ts-form-title">
    <i class="bi bi-pencil-square text-primary"></i>
    <?= $edit_ts ? 'Edytuj wpis — ' . ts_month_label((int)$edit_ts['rok'], (int)$edit_ts['miesiac']) : 'Nowy wpis godzin' ?>
  </div>
  <form method="post" id="tsForm">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php if ($edit_ts): ?>
    <input type="hidden" name="ts_id" value="<?= (int)$edit_ts['id'] ?>">
    <?php endif; ?>

    <div class="row g-3">
      <!-- Umowa -->
      <div class="col-md-12">
        <label class="form-label fw-semibold small">Umowa <span class="text-danger">*</span></label>
        <select name="contract_id" class="form-select" required>
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
        <label class="form-label fw-semibold small">Miesiąc <span class="text-danger">*</span></label>
        <select name="miesiac" class="form-select" required>
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
        <label class="form-label fw-semibold small">Rok <span class="text-danger">*</span></label>
        <select name="rok" class="form-select" required>
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
        <label class="form-label fw-semibold small">Liczba godzin <span class="text-danger">*</span></label>
        <div class="input-group">
          <input name="godziny" type="number" step="0.5" min="0" max="744"
                 class="form-control fw-bold"
                 value="<?= h($edit_ts ? $edit_ts['godziny'] : ($_POST['godziny'] ?? '')) ?>"
                 placeholder="np. 20" required>
          <span class="input-group-text text-muted">h</span>
        </div>
      </div>

      <!-- Opis -->
      <div class="col-12">
        <label class="form-label fw-semibold small">Opis działań <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <textarea name="opis" class="form-control" rows="3"
                  placeholder="Krótki opis czynności wykonanych w tym miesiącu…"><?= h($edit_ts ? $edit_ts['opis'] : ($_POST['opis'] ?? '')) ?></textarea>
        <div class="form-text">Możesz wymienić działania, projekty lub zadania, przy których pracowałeś/aś.</div>
      </div>

      <?php if ($edit_ts && $edit_ts['status'] === 'odrzucone' && $edit_ts['uwagi_admin']): ?>
      <div class="col-12">
        <div class="alert alert-danger py-2 small">
          <strong><i class="bi bi-exclamation-triangle me-1"></i>Powód odrzucenia:</strong>
          <?= h($edit_ts['uwagi_admin']) ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button type="submit" name="_action" value="save" class="btn btn-outline-secondary">
        <i class="bi bi-floppy me-1"></i>Zapisz szkic
      </button>
      <button type="submit" name="_action" value="submit" class="btn btn-primary">
        <i class="bi bi-send-check me-1"></i>Złóż do zatwierdzenia
      </button>
      <a href="<?= APP_URL ?>/panel/timesheets.php" class="btn btn-outline-secondary ms-auto">
        <i class="bi bi-x me-1"></i>Anuluj
      </a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── Lista wpisów ─────────────────────────────────────────────────────── -->
<?php if ($grouped): foreach ($grouped as $cid => $group): ?>
<div class="ts-card">
  <div class="ts-card-head">
    <i class="bi bi-heart"></i>
    <?= h($group['numer']) ?>
  </div>
  <div class="ts-card-body">
    <?php foreach ($group['rows'] as $ts):
      $rejected = $ts['status'] === 'odrzucone';
    ?>
    <div class="ts-row <?= $rejected ? 'ts-rejected' : '' ?>">
      <div>
        <div class="ts-month"><?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?></div>
        <?php if ($ts['opis']): ?>
        <div class="ts-note" title="<?= h($ts['opis']) ?>"><?= h($ts['opis']) ?></div>
        <?php endif; ?>
        <?php if ($rejected && $ts['uwagi_admin']): ?>
        <div class="text-danger small mt-1">
          <i class="bi bi-exclamation-circle"></i> <?= h($ts['uwagi_admin']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="ts-hours"><?= number_format((float)$ts['godziny'], 1, ',', ' ') ?> h</div>
      <div><?= ts_badge($ts['status']) ?></div>
      <div class="ts-actions">
        <?php if (in_array($ts['status'], ['szkic', 'odrzucone'])): ?>
        <a href="?edit=<?= $ts['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj">
          <i class="bi bi-pencil"></i>
        </a>
        <?php endif; ?>
        <?php if ($ts['status'] === 'złożone'): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="retract">
          <input type="hidden" name="ts_id" value="<?= $ts['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-warning" title="Cofnij do szkicu">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php if ($ts['status'] === 'szkic'): ?>
        <form method="post" class="d-inline"
              onsubmit="return confirm('Usunąć ten wpis?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="ts_id" value="<?= $ts['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń">
            <i class="bi bi-trash3"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; else: ?>
<div class="ts-card">
  <div class="ts-card-body ts-empty">
    <i class="bi bi-clock-history" style="font-size:2rem;opacity:.3"></i>
    <div class="mt-2">Nie masz jeszcze żadnych wpisów godzin.</div>
    <?php if (!$show_form): ?>
    <a href="?new=1" class="btn btn-primary btn-sm mt-2">
      <i class="bi bi-plus-lg me-1"></i>Dodaj pierwszy wpis
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$show_form && $contracts): ?>
<div class="text-end mt-2">
  <a href="?new=1" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Dodaj wpis za kolejny miesiąc
  </a>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
