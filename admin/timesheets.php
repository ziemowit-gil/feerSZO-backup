<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timesheets.php';

require_role('admin', 'editor');
require_module_enabled('timesheets_enabled', 'Ewidencja godzin');

$PAGE_TITLE = 'Ewidencja godzin wolontariatu';
$success = ''; $errors = [];

// ── POST: zatwierdzenie / odrzucenie ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $ts_id  = (int)($_POST['ts_id'] ?? 0);
    $ts = $ts_id ? db_one("SELECT * FROM timesheets WHERE id=?", [$ts_id]) : null;

    if ($ts && $action === 'approve') {
        db()->prepare(
            "UPDATE timesheets SET status='zatwierdzone', uwagi_admin=NULL, updated_at=datetime('now') WHERE id=?"
        )->execute([$ts_id]);
        $success = 'Wpis zatwierdzony.';
    }

    if ($ts && $action === 'reject') {
        $uwagi = trim($_POST['uwagi_admin'] ?? '');
        db()->prepare(
            "UPDATE timesheets SET status='odrzucone', uwagi_admin=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$uwagi ?: null, $ts_id]);
        $success = 'Wpis odrzucony.';
    }

    if ($ts && $action === 'reset') {
        db()->prepare(
            "UPDATE timesheets SET status='szkic', uwagi_admin=NULL, updated_at=datetime('now') WHERE id=?"
        )->execute([$ts_id]);
        $success = 'Wpis cofnięty do szkicu.';
    }

    flash_set($success ? 'success' : 'danger', $success ?: 'Błąd operacji.');
    header('Location: ' . APP_URL . '/admin/timesheets.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
    exit;
}

// ── Filtry ────────────────────────────────────────────────────────────────────
$f_status  = $_GET['status']  ?? '';
$f_rok     = (int)($_GET['rok']     ?? 0);
$f_miesiac = (int)($_GET['miesiac'] ?? 0);
$f_search  = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 30;

$where = ['1=1']; $params = [];
if ($f_status)  { $where[] = 't.status=?';        $params[] = $f_status; }
if ($f_rok)     { $where[] = 't.rok=?';            $params[] = $f_rok; }
if ($f_miesiac) { $where[] = 't.miesiac=?';        $params[] = $f_miesiac; }
if ($f_search)  {
    $where[] = '(u.numer_umowy LIKE ? OR u.imie_nazwisko LIKE ?)';
    $params[] = "%{$f_search}%"; $params[] = "%{$f_search}%";
}
$where_sql = implode(' AND ', $where);

$total = (int)(db_one(
    "SELECT COUNT(*) AS c
     FROM timesheets t
     JOIN umowy_wolontariat u ON u.id=t.contract_id
     WHERE {$where_sql}",
    $params
)['c'] ?? 0);

$pag = paginate($total, $per_page, $page, APP_URL . '/admin/timesheets.php?' . http_build_query(array_filter([
    'status'  => $f_status,
    'rok'     => $f_rok ?: null,
    'miesiac' => $f_miesiac ?: null,
    'q'       => $f_search,
])));

$rows = db_all(
    "SELECT t.*, u.numer_umowy, u.imie_nazwisko, u.email AS wol_email
     FROM timesheets t
     JOIN umowy_wolontariat u ON u.id=t.contract_id
     WHERE {$where_sql}
     ORDER BY
       CASE t.status WHEN 'złożone' THEN 0 ELSE 1 END,
       t.rok DESC, t.miesiac DESC, t.id DESC
     LIMIT {$per_page} OFFSET {$pag['offset']}",
    $params
);

// ── Statsy globalne ───────────────────────────────────────────────────────────
try {
    $global_stats = db_one(
        "SELECT
           SUM(CASE WHEN status='złożone'      THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN status='zatwierdzone' THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN status='zatwierdzone' THEN godziny ELSE 0 END) AS total_h,
           COUNT(*) AS all_count
         FROM timesheets"
    );
} catch (\Throwable $e) { $global_stats = []; }

$cur_year = (int)date('Y');

include dirname(__DIR__) . '/includes/header.php';
echo flash_html();
?>
<style>
.ts-admin-row td { vertical-align:middle; }
.ts-status-col   { width:110px; }
.ts-hours-col    { width:80px; font-weight:700; color:#2563eb; text-align:right; }
.ts-action-col   { width:130px; }
.ts-pending-row  { background:#fffbeb; }
.ts-rejected-row { background:#fff5f5; }
.reject-form     { display:none; }
.reject-form.show{ display:block; }
.ts-stat-mini { background:#fff; border:1px solid #e2e8f0; border-radius:.5rem;
  padding:.5rem .85rem; text-align:center; min-width:110px; }
.ts-stat-mini-lbl { font-size:.65rem; text-transform:uppercase; letter-spacing:.09em;
  color:#94a3b8; font-weight:700; }
.ts-stat-mini-val { font-size:1.25rem; font-weight:800; color:#1e293b; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Ewidencja godzin</h4>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/modules_settings.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-gear me-1"></i>Ustawienia
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Statsy -->
<?php if ($global_stats): ?>
<div class="d-flex gap-2 flex-wrap mb-3">
  <div class="ts-stat-mini" style="border-color:#fde68a;background:#fefce8">
    <div class="ts-stat-mini-lbl">Oczekujące</div>
    <div class="ts-stat-mini-val text-warning"><?= (int)($global_stats['pending'] ?? 0) ?></div>
  </div>
  <div class="ts-stat-mini" style="border-color:#bbf7d0;background:#f0fdf4">
    <div class="ts-stat-mini-lbl">Zatwierdzone</div>
    <div class="ts-stat-mini-val text-success"><?= (int)($global_stats['approved'] ?? 0) ?></div>
  </div>
  <div class="ts-stat-mini">
    <div class="ts-stat-mini-lbl">Zatwierdzonych h</div>
    <div class="ts-stat-mini-val"><?= number_format((float)($global_stats['total_h'] ?? 0), 0, ',', ' ') ?></div>
  </div>
  <div class="ts-stat-mini">
    <div class="ts-stat-mini-lbl">Wpisów łącznie</div>
    <div class="ts-stat-mini-val"><?= (int)($global_stats['all_count'] ?? 0) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- Filtry -->
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-sm-4 col-md-3">
    <label class="form-label small fw-semibold mb-1">Wolontariusz / umowa</label>
    <input name="q" class="form-control form-control-sm" placeholder="Szukaj…" value="<?= h($f_search) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">— wszystkie —</option>
      <?php foreach (TS_STATUS as $sv => $sl): ?>
      <option value="<?= h($sv) ?>" <?= $f_status === $sv ? 'selected' : '' ?>><?= h($sl['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Miesiąc</label>
    <select name="miesiac" class="form-select form-select-sm">
      <option value="">— miesiąc —</option>
      <?php foreach (MIESIAC_PL as $mn => $ml): ?>
      <option value="<?= $mn ?>" <?= $f_miesiac === $mn ? 'selected' : '' ?>><?= h($ml) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Rok</label>
    <select name="rok" class="form-select form-select-sm">
      <option value="">— rok —</option>
      <?php for ($y = $cur_year; $y >= 2020; $y--): ?>
      <option value="<?= $y ?>" <?= $f_rok === $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-sm btn-primary">Filtruj</button>
    <a href="<?= APP_URL ?>/admin/timesheets.php" class="btn btn-sm btn-outline-secondary ms-1">Wyczyść</a>
  </div>
</form>

<?php if (!$rows): ?>
<div class="alert alert-secondary text-center py-4">
  <i class="bi bi-clock-history" style="font-size:2rem;opacity:.3"></i>
  <div class="mt-2">Brak wpisów dla wybranych filtrów.</div>
</div>
<?php else: ?>

<div class="card border-0 shadow-sm">
<table class="table table-hover table-sm mb-0 align-middle">
<thead class="table-light">
  <tr>
    <th>Wolontariusz</th>
    <th>Umowa</th>
    <th>Miesiąc</th>
    <th class="text-end">Godziny</th>
    <th class="ts-status-col">Status</th>
    <th>Opis</th>
    <th class="ts-action-col">Akcja</th>
  </tr>
</thead>
<tbody>
<?php foreach ($rows as $ts):
  $is_pending  = $ts['status'] === 'złożone';
  $is_rejected = $ts['status'] === 'odrzucone';
  $row_class   = $is_pending ? 'ts-pending-row' : ($is_rejected ? 'ts-rejected-row' : '');
?>
<tr class="ts-admin-row <?= $row_class ?>">
  <td>
    <div class="fw-semibold small"><?= h($ts['imie_nazwisko']) ?></div>
    <?php if ($ts['wol_email']): ?>
    <div class="text-muted" style="font-size:.72rem"><?= h($ts['wol_email']) ?></div>
    <?php endif; ?>
  </td>
  <td>
    <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $ts['contract_id'] ?>"
       class="small text-decoration-none fw-mono"><?= h($ts['numer_umowy']) ?></a>
  </td>
  <td class="small fw-semibold"><?= ts_month_label((int)$ts['rok'], (int)$ts['miesiac']) ?></td>
  <td class="ts-hours-col"><?= number_format((float)$ts['godziny'], 1, ',', ' ') ?> h</td>
  <td><?= ts_badge($ts['status']) ?></td>
  <td>
    <?php if ($ts['opis']): ?>
    <span class="text-muted small" title="<?= h($ts['opis']) ?>">
      <?= h(mb_strimwidth($ts['opis'], 0, 60, '…')) ?>
    </span>
    <?php endif; ?>
    <?php if ($is_rejected && $ts['uwagi_admin']): ?>
    <div class="text-danger small"><i class="bi bi-x-circle"></i> <?= h(mb_strimwidth($ts['uwagi_admin'], 0, 50, '…')) ?></div>
    <?php endif; ?>
  </td>
  <td>
    <div class="d-flex gap-1 flex-wrap">
      <?php if ($is_pending || $is_rejected): ?>
      <!-- Zatwierdź -->
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"  value="approve">
        <input type="hidden" name="ts_id"    value="<?= $ts['id'] ?>">
        <button type="submit" class="btn btn-xs btn-success"
                title="Zatwierdź"
                style="font-size:.72rem;padding:.2rem .5rem">
          <i class="bi bi-check-lg"></i> Zatwierdź
        </button>
      </form>
      <!-- Odrzuć -->
      <button type="button" class="btn btn-xs btn-outline-danger"
              style="font-size:.72rem;padding:.2rem .5rem"
              onclick="toggleReject(<?= $ts['id'] ?>)"
              title="Odrzuć">
        <i class="bi bi-x-lg"></i>
      </button>
      <?php elseif ($ts['status'] === 'zatwierdzone'): ?>
      <!-- Reset do szkicu -->
      <form method="post" class="d-inline"
            onsubmit="return confirm('Cofnąć do szkicu?')">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="reset">
        <input type="hidden" name="ts_id"   value="<?= $ts['id'] ?>">
        <button type="submit" class="btn btn-xs btn-outline-secondary"
                style="font-size:.72rem;padding:.2rem .5rem" title="Cofnij do szkicu">
          <i class="bi bi-arrow-counterclockwise"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <!-- Formularz odrzucenia (ukryty) -->
    <div id="reject-form-<?= $ts['id'] ?>" class="reject-form mt-2">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="reject">
        <input type="hidden" name="ts_id"   value="<?= $ts['id'] ?>">
        <input name="uwagi_admin" class="form-control form-control-sm mb-1"
               placeholder="Powód odrzucenia (opcjonalnie)">
        <div class="d-flex gap-1">
          <button type="submit" class="btn btn-danger btn-sm flex-fill py-0" style="font-size:.72rem">
            <i class="bi bi-x-circle me-1"></i>Odrzuć
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm py-0" style="font-size:.72rem"
                  onclick="toggleReject(<?= $ts['id'] ?>)">Anuluj</button>
        </div>
      </form>
    </div>
  </td>
  <?php if (is_admin()): ?>
  <td class="text-end align-top pt-2">
    <?= delete_btn('timesheets', (int)$ts['id'], ($ts['user_name'] ?? '') . ' ' . ($ts['rok'] ?? '') . '/' . ($ts['miesiac'] ?? '')) ?>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($pag['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-3">
  <?= pagination_html($pag) ?>
</div>
<?php endif; ?>

<div class="text-muted small mt-2">
  Wyświetlono <?= count($rows) ?> z <?= $total ?> wpisów
</div>

<?php endif; ?>

<script>
function toggleReject(id) {
  var f = document.getElementById('reject-form-' + id);
  if (f) f.classList.toggle('show');
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
