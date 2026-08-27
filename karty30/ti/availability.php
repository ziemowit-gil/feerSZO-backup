<?php
/**
 * karty30/ti/availability.php — Dostępność prowadzących w tygodniu (admin).
 *
 * Bez wybranego prowadzącego administrator widzi WSZYSTKIE dostępności naraz
 * (macierz prowadzący × dni tygodnia) i swobodnie przełącza się między
 * grupami — filtr zawęża macierz do prowadzących danej grupy: instruktora
 * kursu oraz przypisanych grupie w turach zapisów (rekrutacja TI).
 * Po wybraniu prowadzącego — dotychczasowy widok edycji jego okien.
 * Zajęcia można dodawać tylko w tych oknach (egzekwowane przy tworzeniu lekcji).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_rekrutacja.php';

k30_require_access();
karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();
if (!is_admin()) { http_response_code(403); die('Tylko administrator.'); }

$PAGE_TITLE = 'Dostępność prowadzących — TI';
$instructor_id = (int)($_GET['instructor'] ?? 0);
$course_id     = (int)($_GET['course'] ?? 0);   // 0 = wszystkie grupy

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $iid = (int)($_POST['instructor_id'] ?? 0);
    if ($op === 'avail_add') {
        $dw = (int)($_POST['day_of_week'] ?? -1);
        $vf = trim($_POST['valid_from'] ?? '');
        $vt = trim($_POST['valid_to'] ?? '');
        if ($vf !== '' && $vf === $vt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf)) {
            $dw = (int)date('w', strtotime($vf));   // jednorazowa: dzień z daty
        }
        if (!ti_avail_add($iid, $dw, $_POST['time_from'] ?? '', $_POST['time_to'] ?? '', 'approved', $vf, $vt)) {
            flash_set('danger', 'Podaj poprawny dzień, godziny od–do (od < do) i zakres dat (od ≤ do).');
        } else { flash_set('success', 'Dodano okno dostępności.'); }
        header('Location: availability.php?instructor=' . $iid); exit;
    }

    if ($op === 'avail_approve') {
        ti_avail_set_status((int)($_POST['avail_id'] ?? 0), $iid, 'approved');
        flash_set('success', 'Okno zatwierdzone.');
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

// Grupy do przełącznika + zawężenie prowadzących do wybranej grupy:
// instruktor kursu oraz przypisani grupie w turach zapisów.
$courses = db_all("SELECT id, name FROM k30_ti_courses WHERE is_active=1 ORDER BY name COLLATE NOCASE");
if ($course_id > 0) {
    $ids = array_column(db_all(
        "SELECT instructor_id AS iid FROM k30_ti_courses WHERE id=? AND instructor_id IS NOT NULL
         UNION
         SELECT DISTINCT instructor_id FROM k30_rk_round_course_instructors WHERE course_id=?",
        [$course_id, $course_id]), 'iid');
    $instructors = array_values(array_filter($instructors,
        fn($i) => in_array((int)$i['id'], array_map('intval', $ids), true)));
}

$instructor = $instructor_id ? db_one("SELECT id, name, email FROM users WHERE id=?", [$instructor_id]) : null;
$avail      = $instructor ? ti_instructor_availability($instructor_id) : [];
$by_day = [];
foreach ($avail as $w) { $by_day[(int)$w['day_of_week']][] = $w; }

// Macierz wszystkich dostępności (widok bez wybranego prowadzącego)
$matrix = [];   // [instructor_id][dow] => [okna]
if (!$instructor && $instructors) {
    $ph = implode(',', array_fill(0, count($instructors), '?'));
    foreach (db_all(
        "SELECT instructor_id, day_of_week, time_from, time_to, status
           FROM k30_ti_instructor_availability
          WHERE is_active=1 AND instructor_id IN ($ph)
          ORDER BY time_from", array_map(fn($i) => (int)$i['id'], $instructors)) as $w) {
        $matrix[(int)$w['instructor_id']][(int)$w['day_of_week']][] = $w;
    }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
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

<!-- Przełącznik grup — swobodne przełączanie zawęża macierz do prowadzących grupy -->
<nav class="d-flex gap-1 flex-wrap mb-3" aria-label="Filtr grup">
  <a class="btn btn-sm <?= $course_id === 0 ? 'btn-primary' : 'btn-outline-secondary' ?>"
     href="availability.php" <?= $course_id === 0 ? 'aria-current="page"' : '' ?>>Wszystkie grupy</a>
  <?php foreach ($courses as $c): ?>
  <a class="btn btn-sm <?= $course_id === (int)$c['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>"
     href="availability.php?course=<?= (int)$c['id'] ?>"
     <?= $course_id === (int)$c['id'] ? 'aria-current="page"' : '' ?>><?= h($c['name']) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="card border-0 shadow-sm mb-4">
  <input type="hidden" name="course" value="<?= $course_id ?>">
  <div class="card-body d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label fw-semibold mb-1" for="instr-select">Prowadzący (edycja okien)</label>
      <select class="form-select" name="instructor" id="instr-select" onchange="this.form.submit()" style="min-width:280px">
        <option value="">— wszyscy: macierz dostępności —</option>
        <?php foreach ($instructors as $i): ?>
        <option value="<?= (int)$i['id'] ?>" <?= $instructor_id===(int)$i['id']?'selected':'' ?>><?= h($i['name'] ?: $i['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-primary">Pokaż</button></noscript>
  </div>
</form>

<?php if (!$instructor): ?>

<?php if (!$instructors): ?>
<div class="alert alert-secondary">Brak prowadzących<?= $course_id ? ' powiązanych z tą grupą' : '' ?> —
  przypisz instruktora do kursu TI<?= $course_id ? ' albo dodaj przypisania w turach zapisów' : '' ?>.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-grid-3x3 me-2" aria-hidden="true"></i>Dostępność — wszyscy prowadzący
    <?php if ($course_id): $cn = array_values(array_filter($courses, fn($c) => (int)$c['id'] === $course_id)); ?>
    <span class="badge text-bg-primary ms-2">grupa: <?= h($cn[0]['name'] ?? ('#' . $course_id)) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Macierz dostępności prowadzących w tygodniu</caption>
        <thead>
          <tr>
            <th scope="col" style="min-width:180px">Prowadzący</th>
            <?php foreach ([1,2,3,4,5,6,0] as $dw): ?>
            <th scope="col"><?= h(mb_substr(K30_TI_DAYS[$dw], 0, 3)) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($instructors as $i): $rows = $matrix[(int)$i['id']] ?? []; ?>
          <tr>
            <th scope="row" class="fw-semibold">
              <a href="availability.php?course=<?= $course_id ?>&instructor=<?= (int)$i['id'] ?>"
                 title="Edytuj okna dostępności"><?= h($i['name'] ?: $i['email']) ?></a>
              <?php if (!$rows): ?>
              <div class="text-muted fw-normal" style="font-size:.75rem">bez ograniczeń</div>
              <?php endif; ?>
            </th>
            <?php foreach ([1,2,3,4,5,6,0] as $dw): ?>
            <td>
              <?php foreach ($rows[$dw] ?? [] as $w): ?>
              <span class="badge <?= ($w['status'] ?? 'approved') === 'approved' ? 'text-bg-primary' : 'text-bg-secondary' ?> d-block mb-1"
                    <?= ($w['status'] ?? '') !== 'approved' ? 'title="oczekuje na zatwierdzenie"' : '' ?>>
                <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>
              </span>
              <?php endforeach; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="text-muted small mt-2">
  Kliknięcie w nazwisko otwiera edycję okien. Szare odznaki czekają na zatwierdzenie.
  Terminy zapisów generowane z dostępności ustawia kierownik w panelu dydaktyka
  (Zapisy na zajęcia → Prowadzący dla grup).
</p>
<?php endif; ?>

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
          <?php foreach ($wins as $w): $w_draft = ($w['status'] ?? 'approved') === 'draft'; ?>
          <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="badge text-bg-<?= $w_draft ? 'secondary' : 'primary' ?>"
                  <?= $w_draft ? 'title="szkic — czeka na zatwierdzenie"' : '' ?>>
              <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?></span>
            <?php if (!empty($w['valid_from']) || !empty($w['valid_to'])): ?>
            <span class="text-muted" style="font-size:.72rem">
              <?= !empty($w['valid_from']) && $w['valid_from'] === ($w['valid_to'] ?? '')
                  ? 'jednorazowo ' . h($w['valid_from'])
                  : trim(($w['valid_from'] ? 'od ' . h($w['valid_from']) : '') . ($w['valid_to'] ? ' do ' . h($w['valid_to']) : '')) ?>
            </span>
            <?php endif; ?>
            <?php if ($w_draft): ?>
            <form method="post" class="ms-auto">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="avail_approve">
              <input type="hidden" name="instructor_id" value="<?= $instructor_id ?>">
              <input type="hidden" name="avail_id" value="<?= (int)$w['id'] ?>">
              <button class="btn btn-sm btn-outline-success py-0 px-2" title="Zatwierdź okno"
                      aria-label="Zatwierdź okno <?= h(K30_TI_DAYS[$dw]) ?> <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>">
                <i class="bi bi-check-circle" aria-hidden="true"></i></button>
            </form>
            <?php endif; ?>
            <form method="post" class="<?= $w_draft ? '' : 'ms-auto' ?>" onsubmit="return confirm('Usunąć to okno dostępności?')">
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
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_vf">Obowiązuje od</label>
        <input type="date" class="form-control" id="av_vf" name="valid_from">
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold" for="av_vt">do</label>
        <input type="date" class="form-control" id="av_vt" name="valid_to">
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj</button>
      </div>
    </form>
    <p class="form-text mb-0 mt-2">
      Puste daty = okno stałe. Różna dostępność w różnych tygodniach = osobne okna
      z rozłącznymi zakresami dat; jednorazowa: od = do. Z zatwierdzonych okien
      generator tworzy terminy zapisów.
    </p>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
