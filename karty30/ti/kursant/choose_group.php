<?php
/**
 * karty30/ti/kursant/choose_group.php — wybór aktywnej grupy kursanta.
 *
 * Kursant zapisany do WIĘCEJ NIŻ JEDNEJ aktywnej grupy musi tu wybrać jedną —
 * wybór trafia do sesji ($_SESSION['k30_ti_ctx']['course_id']) i scopuje
 * TYLKO zadania/oceny w index.php (patrz $ti_ctx_course_id tam). Kursant z
 * jedną (albo zero) aktywną grupą nigdy tu nie trafia — index.php przepuszcza
 * go od razu. Dostępne też jako przełącznik z linku „Zmień grupę" w tych
 * zakładkach, nie tylko raz po zalogowaniu.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
karty30_migrate();

$active_courses = array_values(array_filter(
    k30_ti_client_courses($student['client_id']),
    fn($c) => ($c['status'] ?? 'active') === 'active'
));

$next_tab = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['tab'] ?? $_POST['tab'] ?? 'dane')) ?: 'dane';

// Nic do wyboru (0 albo 1 aktywna grupa) — nie ma po co tu być.
if (count($active_courses) <= 1) {
    header('Location: index.php?tab=' . urlencode($next_tab)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    student_token_check();
    $cid = (int)($_POST['course_id'] ?? 0);
    if ($cid && in_array($cid, array_column($active_courses, 'course_id'), true)) {
        $_SESSION['k30_ti_ctx'] = ['course_id' => $cid];
        header('Location: index.php?tab=' . urlencode($next_tab)); exit;
    }
    $error = 'Wybierz jedną z grup poniżej.';
}

$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];
$name    = trim((string)($client['name'] ?? $student['login'] ?? 'Kursant'));

$KP_TITLE  = 'Wybierz grupę';
$KP_TOPBAR = ['brand' => 'Panel kursanta', 'icon' => 'mortarboard', 'user' => $name, 'logout' => 'index.php?logout=1'];
$KP_BODY_CLASS = '';
include __DIR__ . '/_layout_head.php';
?>

<main id="main" class="kp-auth-wrap mx-auto my-5">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="card-body p-4 p-lg-5">
      <h1 class="h4 fw-bold mb-2"><i class="bi bi-people-fill text-primary me-2" aria-hidden="true"></i>Wybierz grupę</h1>
      <p class="text-body-secondary mb-4">
        Jesteś zapisany/a do więcej niż jednej grupy. Zadania i oceny pokazują się dla jednej grupy naraz —
        wybierz, której dotyczy dalsza praca. Możesz to zmienić w każdej chwili linkiem „Zmień grupę".
      </p>
      <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($error) ?></div>
      <?php endif; ?>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
        <input type="hidden" name="tab" value="<?= h($next_tab) ?>">
        <?php foreach ($active_courses as $c): ?>
        <button type="submit" name="course_id" value="<?= (int)$c['course_id'] ?>"
                class="btn btn-outline-primary btn-lg text-start d-flex align-items-center gap-2">
          <i class="bi bi-mortarboard fs-5" aria-hidden="true"></i>
          <span>
            <span class="d-block fw-semibold"><?= h($c['course_name']) ?></span>
            <?php if (!empty($c['instructor_name'])): ?>
            <span class="d-block small text-body-secondary">Prowadzący: <?= h($c['instructor_name']) ?></span>
            <?php endif; ?>
          </span>
        </button>
        <?php endforeach; ?>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/_layout_foot.php'; ?>
