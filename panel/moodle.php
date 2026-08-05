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
            db_update('moodle_enrollments', ['status' => 'anulowany'], $enr['id']);
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

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-mortarboard" aria-hidden="true"></i>Platforma e-learningowa</h1>
    <p class="pv-page-sub">Twoje kursy i szkolenia online</p>
  </div>
</div>

<?= flash_html() ?>

<?php if ($moodle_url && count(array_filter($my_enrollments, fn($e) => $e['status'] === 'zatwierdzony'))): ?>
<div class="mb-4">
  <a href="<?= h($moodle_url) ?>" target="_blank" rel="noopener" class="tz-btn tz-btn--ghost"
     aria-label="Przejdź do platformy Moodle (otwiera nową kartę)">
    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Przejdź do Moodle
  </a>
</div>
<?php endif; ?>

<!-- ── Moje zapisy (aktywne) ─────────────────────────────────────────────── -->
<?php $active_enr = array_filter($my_enrollments, fn($e) => in_array($e['status'], ['zatwierdzony', 'oczekuje'])); ?>
<?php if ($active_enr): ?>
<section class="mb-4" aria-labelledby="sect-moje-zapisy">
  <h2 class="fw-bold text-muted text-uppercase small mb-3" id="sect-moje-zapisy">Moje zapisy</h2>
  <div class="row g-3">
    <?php foreach ($active_enr as $enr):
        $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle'];
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="tz-card h-100">
        <div class="tz-card__bd">
          <div class="d-flex align-items-start gap-2 mb-2">
            <i class="bi bi-book text-primary mt-1 flex-shrink-0 fs-5" aria-hidden="true"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= h($enr['fullname']) ?></div>
              <?php if ($enr['category']): ?>
              <div class="text-muted small"><?= h($enr['category']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="mb-2">
            <span class="tz-badge tz-badge--<?= explode(' ', $sm['class'])[0] ?>">
              <i class="bi <?= $sm['icon'] ?> me-1" aria-hidden="true"></i><?= $sm['label'] ?>
            </span>
          </div>
          <?php if ($enr['status'] === 'zatwierdzony' && $moodle_url && $enr['moodle_course_id']): ?>
          <a href="<?= h($moodle_url . '/course/view.php?id=' . $enr['moodle_course_id']) ?>"
             target="_blank" rel="noopener" class="tz-btn w-100"
             aria-label="Otwórz kurs <?= h($enr['fullname']) ?> w Moodle (nowa karta)">
            <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Otwórz kurs w Moodle
          </a>
          <?php elseif ($enr['status'] === 'oczekuje'): ?>
          <form method="post" class="mt-1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="course_id" value="<?= $enr['course_id'] ?>">
            <button type="submit" class="tz-btn tz-btn--ghost w-100"
                    onclick="return confirm('Anulować wniosek o zapis?')"
                    aria-label="Anuluj wniosek o zapis na kurs <?= h($enr['fullname']) ?>">
              <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Anuluj wniosek
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── Dostępne kursy ─────────────────────────────────────────────────────── -->
<?php if (!$all_courses): ?>
<div class="tz-empty">
  <i class="bi bi-mortarboard fs-1 d-block mb-2" aria-hidden="true"></i>
  <p class="mb-0">Brak dostępnych kursów. Administrator wkrótce doda ofertę szkoleniową.</p>
</div>
<?php else: ?>

<section aria-labelledby="sect-dostepne-kursy">
  <h2 class="fw-bold text-muted text-uppercase small mb-3" id="sect-dostepne-kursy">Dostępne kursy</h2>

<?php foreach ($categories as $cat => $cat_courses): ?>
<?php if (count($categories) > 1): ?>
<div class="text-muted small fw-semibold mb-2 mt-3 d-flex align-items-center gap-2">
  <i class="bi bi-folder2 text-primary" aria-hidden="true"></i><?= h($cat) ?>
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
    <div class="tz-card h-100 <?= $is_enr ? 'opacity-75' : '' ?>">
      <div class="tz-card__bd d-flex flex-column">
        <div class="d-flex align-items-start gap-2 mb-2">
          <i class="bi bi-book-half text-primary fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
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
          <span class="tz-badge">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Wymaga zatwierdzenia
          </span>
          <?php else: ?>
          <span class="tz-badge">
            <i class="bi bi-lightning me-1" aria-hidden="true"></i>Natychmiastowy zapis
          </span>
          <?php endif; ?>

          <?php if ($spots_left !== null): ?>
          <span class="tz-badge <?= $spots_left === 0 ? 'tz-badge--danger' : ($spots_left < 5 ? 'tz-badge--warning' : '') ?>">
            <?= $spots_left === 0 ? 'Brak miejsc' : "Wolne miejsca: $spots_left" ?>
          </span>
          <?php endif; ?>
        </div>

        <?php if ($is_enr): ?>
          <?php $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle']; ?>
          <span class="tz-badge tz-badge--<?= explode(' ', $sm['class'])[0] ?> d-block text-center py-2">
            <i class="bi <?= $sm['icon'] ?> me-1" aria-hidden="true"></i><?= $sm['label'] ?>
          </span>
        <?php elseif ($spots_left === 0): ?>
          <button class="tz-btn w-100" disabled aria-disabled="true">Brak wolnych miejsc</button>
        <?php else: ?>
          <button class="tz-btn w-100"
                  data-bs-toggle="modal"
                  data-bs-target="#enrollModal"
                  data-course-id="<?= $c['id'] ?>"
                  data-course-name="<?= h($c['fullname']) ?>"
                  data-requires-note="<?= $c['requires_approval'] ? '1' : '0' ?>"
                  aria-label="Zapisz się na kurs <?= h($c['fullname']) ?>">
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Zapisz się
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
</section>

<?php endif; ?>

<!-- ── Historia ──────────────────────────────────────────────────────────── -->
<?php $past_enr = array_filter($my_enrollments, fn($e) => in_array($e['status'], ['odrzucony', 'anulowany'])); ?>
<?php if ($past_enr): ?>
<details class="mt-4">
  <summary class="text-muted small fw-semibold">
    Historia (odrzucone / anulowane)
  </summary>
  <div class="tz-card mt-2">
    <div class="tz-card__bd p-0">
      <ul class="list-unstyled mb-0" role="list">
        <?php foreach ($past_enr as $enr):
            $sm = $status_meta[$enr['status']] ?? ['label' => $enr['status'], 'class' => 'secondary', 'icon' => 'bi-circle'];
        ?>
        <li class="d-flex align-items-center gap-3 py-2 px-3 border-bottom">
          <i class="bi bi-book text-muted flex-shrink-0" aria-hidden="true"></i>
          <div class="flex-grow-1">
            <div class="small fw-semibold"><?= h($enr['fullname']) ?></div>
            <?php if ($enr['admin_note']): ?>
            <div class="text-muted small">Powód: <?= h($enr['admin_note']) ?></div>
            <?php endif; ?>
          </div>
          <span class="tz-badge tz-badge--<?= explode(' ', $sm['class'])[0] ?> flex-shrink-0"><?= $sm['label'] ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</details>
<?php endif; ?>

<!-- ── Modal zapisu na kurs ───────────────────────────────────────────────── -->
<div class="modal fade" id="enrollModal" tabindex="-1" aria-labelledby="enrollModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="enrollModalLabel"><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Zapisz się na kurs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
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
          <div id="modal-auto-info" class="pv-alert pv-alert-info py-2 small mb-0" style="display:none">
            <i class="bi bi-lightning me-1" aria-hidden="true"></i>
            Ten kurs nie wymaga zatwierdzenia — jeśli Moodle jest skonfigurowane, dostęp zostanie przyznany od razu.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="tz-btn tz-btn--ghost" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="tz-btn">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Potwierdź zapis
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

</div><!-- /.pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
