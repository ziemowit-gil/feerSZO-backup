<?php
/**
 * karty30/ti/urlopy.php — Urlopy / dostępność prowadzących TI.
 * Dashboard (trwające / nadchodzące) + zarządzanie zakresami dat. WCAG 2.1 AA.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_leaves.php';

k30_require_access();
karty30_migrate();
ti_leaves_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Urlopy prowadzących — TI';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_leave') {
        $lid  = (int)($_POST['leave_id'] ?? 0);
        $iid  = (int)($_POST['instructor_id'] ?? 0);
        $from = trim($_POST['date_from'] ?? '');
        $to   = trim($_POST['date_to'] ?? '');
        $type = array_key_exists($_POST['type'] ?? '', TI_LEAVE_TYPES) ? $_POST['type'] : 'urlop';
        $note = trim($_POST['note'] ?? '');
        // walidacja zakresu dat
        $okFrom = DateTime::createFromFormat('Y-m-d', $from);
        $okTo   = DateTime::createFromFormat('Y-m-d', $to);
        if (!$iid || !$okFrom || !$okTo) {
            flash_set('danger', 'Wybierz prowadzącego oraz poprawny zakres dat.');
            header('Location: urlopy.php'); exit;
        }
        if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; } // zamień, gdy odwrócone
        if ($lid) {
            db()->prepare("UPDATE k30_ti_instructor_leaves SET instructor_id=?, date_from=?, date_to=?, type=?, note=? WHERE id=?")
               ->execute([$iid, $from, $to, $type, $note, $lid]);
            flash_set('success', 'Urlop zaktualizowany.');
        } else {
            db_insert('k30_ti_instructor_leaves', [
                'instructor_id' => $iid, 'date_from' => $from, 'date_to' => $to,
                'type' => $type, 'note' => $note, 'created_by' => current_user()['id'] ?? null,
            ]);
            flash_set('success', 'Urlop dodany.');
        }
        header('Location: urlopy.php'); exit;
    }

    if ($op === 'delete_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        if ($lid) db()->prepare("DELETE FROM k30_ti_instructor_leaves WHERE id=?")->execute([$lid]);
        flash_set('success', 'Urlop usunięty.');
        header('Location: urlopy.php'); exit;
    }
}

$instructors = k30_get_consultants();
$current  = ti_leaves_current();
$upcoming = ti_leaves_upcoming(30);
$all      = ti_leaves_all();

$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? ti_leave_get($edit_id) : null;
$ef = $edit_row ?: ['id'=>0,'instructor_id'=>0,'date_from'=>'','date_to'=>'','type'=>'urlop','note'=>''];

/** Czytelny zakres dat. */
function _leave_range(array $l): string {
    $f = date('d.m.Y', strtotime($l['date_from']));
    $t = date('d.m.Y', strtotime($l['date_to']));
    return $f === $t ? $f : "$f – $t";
}
/** Liczba dni urlopu (włącznie). */
function _leave_days(array $l): int {
    $d1 = new DateTime($l['date_from']); $d2 = new DateTime($l['date_to']);
    return (int)$d1->diff($d2)->days + 1;
}
$type_badge = ['urlop'=>'primary','chorobowe'=>'danger','okolicznosc'=>'info','inne'=>'secondary'];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Urlopy prowadzących</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-airplane text-primary me-2" aria-hidden="true"></i>Urlopy / dostępność prowadzących</h4>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Dashboard dostępności -->
  <div class="col-lg-7">
    <!-- Trwające teraz -->
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="cur-h">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-person-x text-danger me-2" aria-hidden="true"></i><span id="cur-h">Nieobecni teraz</span>
        <span class="badge bg-danger ms-2"><?= count($current) ?></span>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$current): ?>
        <li class="list-group-item text-body-secondary"><i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i>Wszyscy prowadzący są dziś dostępni.</li>
        <?php endif; ?>
        <?php foreach ($current as $l):
          $left = (new DateTime(date('Y-m-d')))->diff(new DateTime($l['date_to']))->days; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
          <span class="fw-semibold"><?= h($l['instructor_name']) ?></span>
          <span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span>
          <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h(_leave_range($l)) ?></span>
          <span class="badge bg-warning text-dark ms-auto">wraca za <?= $left === 0 ? 'dziś (ostatni dzień)' : ($left.' dn.') ?></span>
          <?php if ($l['note']): ?><span class="w-100 text-body-secondary small"><?= h($l['note']) ?></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <!-- Nadchodzące 30 dni -->
    <section class="card border-0 shadow-sm" aria-labelledby="up-h">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-calendar-event text-warning me-2" aria-hidden="true"></i><span id="up-h">Nadchodzące (30 dni)</span>
        <span class="badge bg-secondary ms-2"><?= count($upcoming) ?></span>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$upcoming): ?>
        <li class="list-group-item text-body-secondary">Brak zaplanowanych urlopów w najbliższych 30 dniach.</li>
        <?php endif; ?>
        <?php foreach ($upcoming as $l):
          $inDays = (new DateTime(date('Y-m-d')))->diff(new DateTime($l['date_from']))->days; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
          <span class="fw-semibold"><?= h($l['instructor_name']) ?></span>
          <span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span>
          <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h(_leave_range($l)) ?> (<?= _leave_days($l) ?> dn.)</span>
          <span class="badge bg-light text-secondary border ms-auto">za <?= $inDays ?> dn.</span>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <!-- Formularz + pełna lista -->
  <div class="col-lg-5">
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_row ? 'Edytuj urlop' : 'Dodaj urlop' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"        value="save_leave">
          <input type="hidden" name="leave_id"   value="<?= (int)$ef['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="l_instr">Prowadzący <span class="text-danger">*</span></label>
            <select class="form-select" name="instructor_id" id="l_instr" required>
              <option value="">— wybierz —</option>
              <?php foreach ($instructors as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)$ef['instructor_id']===(int)$u['id']?'selected':'' ?>><?= h($u['display_name'] ?? $u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_from">Od <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_from" id="l_from" value="<?= h($ef['date_from']) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="l_to">Do <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_to" id="l_to" value="<?= h($ef['date_to']) ?>" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="l_type">Rodzaj</label>
            <select class="form-select" name="type" id="l_type">
              <?php foreach (TI_LEAVE_TYPES as $tk => $tl): ?>
              <option value="<?= h($tk) ?>" <?= ($ef['type'] ?? 'urlop')===$tk?'selected':'' ?>><?= h($tl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="l_note">Notatka</label>
            <input type="text" class="form-control" name="note" id="l_note" value="<?= h($ef['note'] ?? '') ?>" placeholder="np. zastępstwo: …">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz' : 'Dodaj urlop' ?></button>
            <?php if ($edit_row): ?><a href="urlopy.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>Wszystkie urlopy <span class="badge bg-secondary ms-1"><?= count($all) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <caption class="visually-hidden">Lista wszystkich urlopów prowadzących</caption>
          <thead class="table-light"><tr><th>Prowadzący</th><th>Zakres</th><th>Rodzaj</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$all): ?><tr><td colspan="4" class="text-center text-muted py-3">Brak urlopów.</td></tr><?php endif; ?>
            <?php $today = date('Y-m-d'); foreach ($all as $l):
              $past = $l['date_to'] < $today; ?>
            <tr class="<?= $past ? 'opacity-50' : '' ?>">
              <td class="fw-semibold"><?= h($l['instructor_name']) ?></td>
              <td class="small text-nowrap"><?= h(_leave_range($l)) ?></td>
              <td><span class="badge text-bg-<?= $type_badge[$l['type']] ?? 'secondary' ?>"><?= h(ti_leave_type_label($l['type'])) ?></span></td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <a href="?edit=<?= (int)$l['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj" aria-label="Edytuj urlop"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten urlop?')">
                  <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"        value="delete_leave">
                  <input type="hidden" name="leave_id"   value="<?= (int)$l['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń urlop"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
