<?php
/**
 * karty30/ti/holidays.php — Kalendarz pracy TI: dni wolne i przerwy w działalności.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

if (!can_write('karty30') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do zapisu.');
}
$can_write = can_write('karty30') || is_admin();

// ── Migracja ──────────────────────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_holidays (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        date_from   TEXT NOT NULL,
        date_to     TEXT NOT NULL,
        name        TEXT NOT NULL,
        type        TEXT NOT NULL DEFAULT 'holiday',
        note        TEXT,
        created_by  INTEGER,
        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
} catch (\Throwable $e) {}

// ── Akcje ─────────────────────────────────────────────────────────────────────
$op = $_POST['_op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $df   = trim($_POST['date_from'] ?? '');
        $dt   = trim($_POST['date_to']   ?? '');
        $name = trim($_POST['name']      ?? '');
        $type = in_array($_POST['type'] ?? '', ['holiday','break','other']) ? $_POST['type'] : 'holiday';
        $note = trim($_POST['note'] ?? '');

        $errors = [];
        if (!$df || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) $errors[] = 'Podaj datę od.';
        if (!$dt || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $errors[] = 'Podaj datę do.';
        if ($df && $dt && $dt < $df) $errors[] = 'Data do musi być ≥ dacie od.';
        if (!$name) $errors[] = 'Podaj nazwę.';

        if (!$errors) {
            if ($id) {
                db_query("UPDATE k30_ti_holidays SET date_from=?, date_to=?, name=?, type=?, note=?, updated_at=datetime('now') WHERE id=?",
                    [$df, $dt, $name, $type, $note, $id]);
                flash_set('success', 'Zaktualizowano wpis.');
            } else {
                db_insert('k30_ti_holidays', ['date_from'=>$df,'date_to'=>$dt,'name'=>$name,'type'=>$type,'note'=>$note,'created_by'=>$uid]);
                flash_set('success', 'Dodano wpis do kalendarza.');
            }
            header('Location: holidays.php'); exit;
        }
        $form_err = compact('id','df','dt','name','type','note','errors');
    } elseif ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db_query("DELETE FROM k30_ti_holidays WHERE id=?", [$id]);
        flash_set('success', 'Usunięto wpis.');
        header('Location: holidays.php'); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$year  = (int)($_GET['year'] ?? date('Y'));
$year  = max(2020, min(2035, $year));
$items = db_all(
    "SELECT * FROM k30_ti_holidays WHERE strftime('%Y', date_from)=? OR strftime('%Y', date_to)=? ORDER BY date_from",
    [(string)$year, (string)$year]
);
$form_err = $form_err ?? null;

$TYPE_LABELS = [
    'holiday' => ['label'=>'Dzień wolny / święto',    'icon'=>'bi-calendar-x',     'color'=>'#dc2626','bg'=>'#fef2f2'],
    'break'   => ['label'=>'Przerwa w działalności',  'icon'=>'bi-door-closed',     'color'=>'#d97706','bg'=>'#fffbeb'],
    'other'   => ['label'=>'Inne',                    'icon'=>'bi-calendar-minus',  'color'=>'#6b7280','bg'=>'#f9fafb'],
];

$PAGE_TITLE = 'Kalendarz pracy TI — dni wolne';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:900px">
<?= flash_html() ?>

<?php if ($form_err && !empty($form_err['errors'])): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($form_err['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-calendar-x me-2 text-danger"></i>Kalendarz pracy TI — dni wolne</h1>
  <div class="ms-auto d-flex align-items-center gap-2">
    <a href="?year=<?= $year-1 ?>" class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni rok"><i class="bi bi-chevron-left"></i></a>
    <span class="fw-semibold"><?= $year ?></span>
    <a href="?year=<?= $year+1 ?>" class="btn btn-outline-secondary btn-sm" aria-label="Następny rok"><i class="bi bi-chevron-right"></i></a>
    <?php if ($can_write): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg me-1"></i>Dodaj
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($items): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Od</th><th>Do</th><th>Dni</th><th>Nazwa</th><th>Typ</th><th>Uwagi</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($items as $h):
        $hdf = new DateTime($h['date_from']); $hdt = new DateTime($h['date_to']);
        $days = (int)$hdf->diff($hdt)->days + 1;
        $ti = $TYPE_LABELS[$h['type']] ?? $TYPE_LABELS['other'];
        $is_now = $h['date_from'] <= date('Y-m-d') && $h['date_to'] >= date('Y-m-d');
      ?>
      <tr class="<?= $is_now ? 'table-warning' : '' ?>">
        <td class="text-nowrap"><?= $hdf->format('d.m.Y') ?></td>
        <td class="text-nowrap"><?= $hdt->format('d.m.Y') ?></td>
        <td class="text-center"><?= $days ?></td>
        <td class="fw-semibold"><?= h($h['name']) ?><?= $is_now ? ' <span class="badge text-bg-warning ms-1">Teraz</span>' : '' ?></td>
        <td>
          <span class="badge" style="background:<?= h($ti['bg']) ?>;color:<?= h($ti['color']) ?>;border:1px solid <?= h($ti['color']) ?>44">
            <i class="<?= h($ti['icon']) ?> me-1"></i><?= h($ti['label']) ?>
          </span>
        </td>
        <td class="text-body-secondary small"><?= $h['note'] ? h($h['note']) : '—' ?></td>
        <?php if ($can_write): ?>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                  onclick="holEdit(<?= (int)$h['id'] ?>,<?= htmlspecialchars(json_encode($h['date_from']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($h['date_to']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($h['name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($h['type']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($h['note']??''),ENT_QUOTES) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten wpis?')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="delete">
            <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="alert alert-light border text-body-secondary">
  <i class="bi bi-calendar-check me-1"></i>Brak wpisów w kalendarzu dla roku <?= $year ?>. <?php if ($can_write): ?>Kliknij „Dodaj", aby wpisać pierwszy dzień wolny lub przerwę.<?php endif; ?>
</div>
<?php endif; ?>

<!-- Nadchodzące -->
<?php
$upcoming = db_all("SELECT * FROM k30_ti_holidays WHERE date_from >= ? ORDER BY date_from LIMIT 5", [date('Y-m-d')]);
if ($upcoming):
?>
<h2 class="h6 text-body-secondary mt-2 mb-2"><i class="bi bi-clock-history me-1"></i>Nadchodzące</h2>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($upcoming as $u):
    $ti2 = $TYPE_LABELS[$u['type']] ?? $TYPE_LABELS['other'];
    $udf = new DateTime($u['date_from']); $udt = new DateTime($u['date_to']);
    $days2 = (int)$udf->diff($udt)->days + 1;
  ?>
  <div class="card border-0 px-3 py-2" style="background:<?= h($ti2['bg']) ?>;border-left:4px solid <?= h($ti2['color']) ?>!important;min-width:180px">
    <div class="fw-semibold small" style="color:<?= h($ti2['color']) ?>"><?= h($u['name']) ?></div>
    <div class="text-body-secondary" style="font-size:.8rem"><?= $udf->format('d.m') ?><?= $udf != $udt ? '–'.$udt->format('d.m') : '' ?> · <?= $days2 ?> <?= $days2===1?'dzień':'dni' ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php if ($can_write): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" id="hol_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="addModalLabel"><i class="bi bi-calendar-plus me-2"></i><span id="hol_modal_title">Dodaj wpis</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Data od <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_from" id="hol_df" required value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Data do <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_to" id="hol_dt" required value="<?= h(date('Y-m-d')) ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="hol_name" required placeholder="np. Boże Narodzenie, przerwa wakacyjna">
          </div>
          <div class="mb-2">
            <label class="form-label">Typ</label>
            <select class="form-select" name="type" id="hol_type">
              <option value="holiday">Dzień wolny / święto</option>
              <option value="break">Przerwa w działalności</option>
              <option value="other">Inne</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Uwagi</label>
            <input type="text" class="form-control" name="note" id="hol_note" placeholder="Opcjonalnie">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function holEdit(id, df, dt, name, type, note) {
  document.getElementById('hol_id').value = id;
  document.getElementById('hol_df').value = df;
  document.getElementById('hol_dt').value = dt;
  document.getElementById('hol_name').value = name;
  document.getElementById('hol_type').value = type;
  document.getElementById('hol_note').value = note;
  document.getElementById('hol_modal_title').textContent = 'Edytuj wpis';
  new bootstrap.Modal(document.getElementById('addModal')).show();
}
document.getElementById('addModal').addEventListener('show.bs.modal', function(e) {
  if (!e.relatedTarget) return;
  document.getElementById('hol_id').value = '0';
  document.getElementById('hol_name').value = '';
  document.getElementById('hol_note').value = '';
  document.getElementById('hol_type').value = 'holiday';
  document.getElementById('hol_modal_title').textContent = 'Dodaj wpis';
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('hol_df').value = today;
  document.getElementById('hol_dt').value = today;
});
</script>
<?php endif; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
