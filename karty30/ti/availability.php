<?php
/**
 * karty30/ti/availability.php — Dostępność prowadzących w tygodniu (admin).
 * Administrator wybiera prowadzącego i zarządza jego oknami dostępności.
 * Zajęcia można dodawać tylko w tych oknach (egzekwowane przy tworzeniu lekcji).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();
if (!is_admin()) { http_response_code(403); die('Tylko administrator.'); }

$PAGE_TITLE = 'Dostępność prowadzących — TI';
$instructor_id = (int)($_GET['instructor'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $iid = (int)($_POST['instructor_id'] ?? 0);
    if ($op === 'avail_add') {
        if (!ti_avail_add($iid, (int)($_POST['day_of_week'] ?? -1), $_POST['time_from'] ?? '', $_POST['time_to'] ?? '')) {
            flash_set('danger', 'Podaj poprawny dzień oraz godziny od–do (od < do).');
        } else { flash_set('success', 'Dodano okno dostępności.'); }
        header('Location: availability.php?instructor=' . $iid); exit;
    }
    if ($op === 'avail_delete') {
        ti_avail_delete((int)($_POST['avail_id'] ?? 0), $iid);
        flash_set('success', 'Usunięto okno dostępności.');
        header('Location: availability.php?instructor=' . $iid); exit;
    }
}

// Prowadzący = osoby przypisane do kursów TI lub posiadające konto dydaktyka
$instructors = db_all(
    "SELECT u.id, u.name, u.email FROM users u
     WHERE u.id IN (SELECT instructor_id FROM k30_ti_courses WHERE instructor_id IS NOT NULL)
        OR u.id IN (SELECT user_id FROM k30_ti_instructor_accounts)
     ORDER BY u.name COLLATE NOCASE"
);
$instructor = $instructor_id ? db_one("SELECT id, name, email FROM users WHERE id=?", [$instructor_id]) : null;
$avail      = $instructor ? ti_instructor_availability($instructor_id) : [];
$by_day = [];
foreach ($avail as $w) { $by_day[(int)$w['day_of_week']][] = $w; }

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Dostępność prowadzących</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Dostępność prowadzących</h4>
  <a href="urlopy.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-airplane me-1"></i>Urlopy</a>
</div>

<?= flash_html() ?>

<div class="alert alert-info">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Zajęcia można dodawać tylko w godzinach dostępności prowadzącego. Gdy prowadzący nie ma żadnego okna — zajęcia bez ograniczeń. Administrator może wymusić dodanie poza dostępnością przy tworzeniu lekcji.
</div>

<form method="get" class="card border-0 shadow-sm mb-4">
  <div class="card-body d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label fw-semibold mb-1" for="instr-select">Prowadzący</label>
      <select class="form-select" name="instructor" id="instr-select" onchange="this.form.submit()" style="min-width:280px">
        <option value="">— wybierz prowadzącego —</option>
        <?php foreach ($instructors as $i): ?>
        <option value="<?= (int)$i['id'] ?>" <?= $instructor_id===(int)$i['id']?'selected':'' ?>><?= h($i['name'] ?: $i['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-primary">Pokaż</button></noscript>
  </div>
</form>

<?php if (!$instructor): ?>
<div class="alert alert-secondary">Wybierz prowadzącego, aby zarządzać jego dostępnością.</div>
<?php if (!$instructors): ?><p class="text-muted">Brak prowadzących — przypisz instruktora do kursu TI.</p><?php endif; ?>
<?php else: ?>

<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-person-badge me-2" aria-hidden="true"></i><?= h($instructor['name'] ?: $instructor['email']) ?></div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ([1,2,3,4,5,6,0] as $dw): $wins = $by_day[$dw] ?? []; ?>
      <div class="col-md-6 col-lg-4">
        <div class="border rounded p-2 h-100">
          <div class="fw-semibold mb-2"><i class="bi bi-calendar-day me-1 text-primary" aria-hidden="true"></i><?= h(K30_TI_DAYS[$dw]) ?></div>
          <?php if (!$wins): ?><div class="text-muted small mb-2">— niedostępny —</div><?php endif; ?>
          <?php foreach ($wins as $w): ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge text-bg-primary"><?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?></span>
            <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć to okno dostępności?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="avail_delete">
              <input type="hidden" name="instructor_id" value="<?= $instructor_id ?>">
              <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń okno" aria-label="Usuń okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <hr>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="avail_add">
      <input type="hidden" name="instructor_id" value="<?= $instructor_id ?>">
      <div class="col-sm-4">
        <label class="form-label fw-semibold" for="av_dow">Dzień tygodnia</label>
        <select class="form-select" id="av_dow" name="day_of_week" required>
          <?php foreach ([1,2,3,4,5,6,0] as $dw): ?>
          <option value="<?= $dw ?>"><?= h(K30_TI_DAYS[$dw]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_from">Od</label>
        <select class="form-select" id="av_from" name="time_from"><?= ti_time_options('09:00') ?></select>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_to">Do</label>
        <select class="form-select" id="av_to" name="time_to"><?= ti_time_options('13:00') ?></select>
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
