<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/moodle.php';

require_login();
require_module_enabled('moodle_enabled', 'Moduł kursów Moodle');

$PAGE_TITLE = 'Moje kursy';
$user = current_user();
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

// ── Obsługa POST: zapis na kurs ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action    = $_POST['action'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);

    if ($action === 'enroll' && $course_id) {
        $course = moodle_course_get($course_id);
        if (!$course || !$course['visible']) {
            flash_set('error', 'Kurs niedostępny.');
        } elseif (moodle_enrollment_exists($user['id'], $course_id)) {
            flash_set('error', 'Jesteś już zapisany/a na ten kurs.');
        } else {
            // Sprawdź limit uczestników
            if ($course['max_participants'] > 0) {
                $count = (int)db()->query("SELECT COUNT(*) FROM moodle_enrollments WHERE course_id=$course_id AND status IN ('oczekuje','zatwierdzony')")->fetchColumn();
                if ($count >= $course['max_participants']) {
                    flash_set('error', 'Brak wolnych miejsc na tym kursie.');
                    header('Location: ' . APP_URL . '/panel/moodle.php'); exit;
                }
            }

            $note   = trim($_POST['note'] ?? '');
            $status = $course['requires_approval'] ? 'oczekuje' : 'oczekuje';

            db_insert('moodle_enrollments', [
                'user_id'   => $user['id'],
                'course_id' => $course_id,
                'status'    => $status,
                'note'      => $note,
            ]);

            // Jeśli brak wymogu zatwierdzenia i API skonfigurowane — od razu zapisz
            if (!$course['requires_approval'] && moodle_configured()) {
                try {
                    $enr_id = (int)db()->lastInsertId();
                    moodle_approve_enrollment($enr_id);
                    flash_set('success', 'Zostałeś/aś zapisany/a na kurs. Miłej nauki!');
                } catch (\Throwable $e) {
                    flash_set('success', 'Wniosek o zapis przyjęty. Administrator wkrótce zatwierdzi dostęp.');
                }
            } else {
                flash_set('success', $course['requires_approval']
                    ? 'Wniosek o zapis na kurs wysłany. Poczekaj na zatwierdzenie przez administratora.'
                    : 'Wniosek o zapis przyjęty. Administrator wkrótce zatwierdzi dostęp.');
            }
        }
    }

    if ($action === 'cancel' && $course_id) {
        $enr = db_one("SELECT * FROM moodle_enrollments WHERE user_id=? AND course_id=? AND status='oczekuje'",
            [$user['id'], $course_id]);
        if ($enr) {
            db_update('moodle_enrollments', $enr['id'], ['status' => 'anulowany']);
            flash_set('success', 'Wniosek o zapis anulowany.');
        }
    }

    header('Location: ' . APP_URL . '/panel/moodle.php'); exit;
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$all_courses    = moodle_courses_all(visible_only: true);
$my_enrollments = moodle_user_enrollments($user['id']);
$enrolled_ids   = array_column($my_enrollments, 'course_id');

// Grupy kursów wg kategorii
$categories = [];
foreach ($all_courses as $c) {
    $cat = $c['category'] ?: 'Inne';
    $categories[$cat][] = $c;
}

$status_meta = [
    'oczekuje'    => ['label' => 'Oczekuje na zatwierdzenie', 'class' => 'warning text-dark', 'icon' => 'bi-hourglass-split'],
    'zatwierdzony'=> ['label' => 'Zatwierdzony — masz dostęp', 'class' => 'success',          'icon' => 'bi-check-circle-fill'],
    'odrzucony'   => ['label' => 'Odrzucony',                  'class' => 'danger',            'icon' => 'bi-x-circle-fill'],
    'anulowany'   => ['label' => 'Anulowany',                  'class' => 'secondary',         'icon' => 'bi-dash-circle'],
];
$enr_by_course = array_column($my_enrollments, null, 'course_id');

$moodle_url = rtrim(moodle_setting('url'), '/');

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>
<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Moje kursy</h1>
  <p class="pv-page-sub">Kursy e-learningowe</p>
</div>
<?php echo flash_html(); ?>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-mortarboard text-primary fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Moje kursy</h4>
    <div class="text-muted small">Przeglądaj dostępne szkolenia i zarządzaj swoimi zapisami</div>
  </div>
  <?php if ($moodle_url && count(array_filter($my_enrollments, fn($e) => $e['status'] === 'zatwierdzony'))): ?>
  <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-box-arrow-up-right me-1"></i>Przejdź do Moodle
  </a>
  <?php endif; ?>
</div>

<?php if (!$_is_volunteer_only): ?>
<?= flash_html() ?>
<?php endif; ?>

<!-- ── Moje zapisy (aktywne) ─────────────────────────────────────────────── -->
<?php $active_enr = array_filter($my_enrollments, fn($e) => in_array($e['status'], ['zatwierdzony', 'oczekuje'])); ?>
<?php if ($active_enr): ?>
<div class="mb-4">
  <h6 class="fw-bold text-muted text-uppercase small mb-3 letter-spacing-1">Moje zapisy</h6>
  <div class="row g-3">
    <?php foreach ($active_enr as $enr):
        $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle'];
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card shadow-sm h-100 <?= $enr['status'] === 'zatwierdzony' ? 'border-success border-opacity-50' : 'border-warning border-opacity-50' ?>">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-2">
            <i class="bi bi-book text-primary mt-1 flex-shrink-0 fs-5"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= h($enr['fullname']) ?></div>
              <?php if ($enr['category']): ?>
              <div class="text-muted small"><?= h($enr['category']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-<?= $sm['class'] ?>">
              <i class="bi <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
            </span>
          </div>
          <?php if ($enr['status'] === 'zatwierdzony' && $moodle_url && $enr['moodle_course_id']): ?>
          <a href="<?= h($moodle_url . '/course/view.php?id=' . $enr['moodle_course_id']) ?>"
             target="_blank" rel="noopener" class="btn btn-sm btn-success w-100">
            <i class="bi bi-play-circle me-1"></i>Otwórz kurs w Moodle
          </a>
          <?php elseif ($enr['status'] === 'oczekuje'): ?>
          <form method="post" class="mt-1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="course_id" value="<?= $enr['course_id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100"
                    onclick="return confirm('Anulować wniosek o zapis?')">
              <i class="bi bi-x-lg me-1"></i>Anuluj wniosek
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Dostępne kursy ─────────────────────────────────────────────────────── -->
<?php if (!$all_courses): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-mortarboard fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak dostępnych kursów. Administrator wkrótce doda ofertę szkoleniową.</p>
  </div>
</div>
<?php else: ?>

<h6 class="fw-bold text-muted text-uppercase small mb-3">Dostępne kursy</h6>

<?php foreach ($categories as $cat => $cat_courses): ?>
<?php if (count($categories) > 1): ?>
<div class="text-muted small fw-semibold mb-2 mt-3 d-flex align-items-center gap-2">
  <i class="bi bi-folder2 text-primary"></i><?= h($cat) ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ($cat_courses as $c):
      $enr    = $enr_by_course[$c['id']] ?? null;
      $is_enr = $enr && in_array($enr['status'], ['oczekuje', 'zatwierdzony']);
      // Liczba wolnych miejsc
      $spots_left = null;
      if ($c['max_participants'] > 0) {
          $taken = (int)db()->query("SELECT COUNT(*) FROM moodle_enrollments WHERE course_id={$c['id']} AND status IN ('oczekuje','zatwierdzony')")->fetchColumn();
          $spots_left = max(0, $c['max_participants'] - $taken);
      }
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100 <?= $is_enr ? 'opacity-75' : '' ?>">
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-start gap-2 mb-2">
          <i class="bi bi-book-half text-primary fs-5 flex-shrink-0 mt-1"></i>
          <div>
            <div class="fw-semibold"><?= h($c['fullname']) ?></div>
            <?php if ($c['shortname']): ?>
            <code class="text-muted small"><?= h($c['shortname']) ?></code>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($c['summary']): ?>
        <p class="small text-muted mb-2 flex-grow-1"><?= h(mb_strimwidth($c['summary'], 0, 160, '…')) ?></p>
        <?php else: ?>
        <div class="flex-grow-1"></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
          <?php if ($c['requires_approval']): ?>
          <span class="badge bg-light text-dark border" style="font-size:.7rem">
            <i class="bi bi-shield-check me-1"></i>Wymaga zatwierdzenia
          </span>
          <?php else: ?>
          <span class="badge bg-light text-dark border" style="font-size:.7rem">
            <i class="bi bi-lightning me-1"></i>Natychmiastowy zapis
          </span>
          <?php endif; ?>

          <?php if ($spots_left !== null): ?>
          <span class="badge bg-<?= $spots_left === 0 ? 'danger' : ($spots_left < 5 ? 'warning text-dark' : 'light text-dark border') ?>" style="font-size:.7rem">
            <?= $spots_left === 0 ? 'Brak miejsc' : "Wolne miejsca: $spots_left" ?>
          </span>
          <?php endif; ?>
        </div>

        <?php if ($is_enr): ?>
          <?php $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle']; ?>
          <span class="badge bg-<?= $sm['class'] ?> w-100 py-2">
            <i class="bi <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
          </span>
        <?php elseif ($spots_left === 0): ?>
          <button class="btn btn-secondary btn-sm w-100" disabled>Brak wolnych miejsc</button>
        <?php else: ?>
          <button class="btn btn-primary btn-sm w-100"
                  data-bs-toggle="modal"
                  data-bs-target="#enrollModal"
                  data-course-id="<?= $c['id'] ?>"
                  data-course-name="<?= h($c['fullname']) ?>"
                  data-requires-note="<?= $c['requires_approval'] ? '1' : '0' ?>">
            <i class="bi bi-plus-circle me-1"></i>Zapisz się
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<!-- ── Historia ──────────────────────────────────────────────────────────── -->
<?php $past_enr = array_filter($my_enrollments, fn($e) => in_array($e['status'], ['odrzucony', 'anulowany'])); ?>
<?php if ($past_enr): ?>
<details class="mt-4">
  <summary class="text-muted small fw-semibold" style="cursor:pointer">
    Historia (odrzucone / anulowane)
  </summary>
  <div class="list-group list-group-flush mt-2 card shadow-sm">
    <?php foreach ($past_enr as $enr):
        $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle'];
    ?>
    <div class="list-group-item d-flex align-items-center gap-3 py-2 px-3">
      <i class="bi bi-book text-muted flex-shrink-0"></i>
      <div class="flex-grow-1">
        <div class="small fw-semibold"><?= h($enr['fullname']) ?></div>
        <?php if ($enr['admin_note']): ?>
        <div class="text-muted" style="font-size:.72rem">Powód: <?= h($enr['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <span class="badge bg-<?= $sm['class'] ?> flex-shrink-0"><?= $sm['label'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<!-- ── Modal zapisu na kurs ───────────────────────────────────────────────── -->
<div class="modal fade" id="enrollModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-mortarboard me-2"></i>Zapisz się na kurs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="enroll">
        <input type="hidden" name="course_id" id="modal-course-id">
        <div class="modal-body">
          <p class="mb-3">Potwierdzasz zapis na kurs: <strong id="modal-course-name"></strong></p>
          <div id="modal-note-row" style="display:none">
            <label class="form-label fw-semibold">Uwagi / motywacja <span class="text-muted fw-normal">(opcjonalnie)</span></label>
            <textarea name="note" class="form-control" rows="3"
                      placeholder="Opisz dlaczego chcesz wziąć udział w tym szkoleniu..."></textarea>
          </div>
          <div id="modal-auto-info" class="alert alert-info py-2 small mb-0" style="display:none">
            <i class="bi bi-lightning me-1"></i>
            Ten kurs nie wymaga zatwierdzenia — jeśli Moodle jest skonfigurowane, dostęp zostanie przyznany od razu.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>Potwierdź zapis
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('enrollModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('modal-course-id').value   = btn.dataset.courseId;
    document.getElementById('modal-course-name').textContent = btn.dataset.courseName;
    const needsNote = btn.dataset.requiresNote === '1';
    document.getElementById('modal-note-row').style.display  = needsNote ? '' : 'none';
    document.getElementById('modal-auto-info').style.display = needsNote ? 'none' : '';
});
</script>


<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
