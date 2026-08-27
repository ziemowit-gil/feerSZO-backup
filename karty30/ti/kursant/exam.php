<?php
/**
 * karty30/ti/kursant/exam.php
 * Equi Exams — rozwiązywanie testu przez kursanta.
 *
 * Ekrany:
 *   ?exam=ID            — ekran startowy (opis, zasady, przycisk „Rozpocznij"),
 *   ?exam=ID&solve=1    — arkusz z pytaniami (po rozpoczęciu podejścia),
 *   ?attempt=ID         — wynik oddanej pracy.
 *
 * Dostępność (WCAG 2.1 AA):
 *   • każde pytanie w <fieldset> z <legend>; każdy wariant ma własną <label>,
 *   • licznik czasu w regionie role="timer"; ostrzeżenia i stan zapisu ogłaszane
 *     przez osobne regiony aria-live="polite" (nie co sekundę, tylko na progach),
 *   • stan odpowiedzi nigdy nie jest przekazywany samym kolorem — zawsze towarzyszy
 *     mu ikona i tekst,
 *   • pełna obsługa bez JavaScriptu: arkusz to zwykły formularz, a limit czasu
 *     pilnuje serwer przy przyjmowaniu pracy.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student   = student_require();
$client_id = (int)$student['client_id'];

$enrolled = array_map(fn($c) => (int)$c['course_id'], k30_ti_client_courses($client_id));

/** Egzamin musi istnieć, być udostępniony i dotyczyć kursu, na który kursant jest zapisany. */
$load_exam = function (int $id) use ($enrolled): array {
    $e = $id ? ti_exam_get($id) : null;
    if (!$e || empty($e['is_active']) || !in_array((int)$e['course_id'], $enrolled, true)) {
        header('Location: index.php?tab=egzaminy&err=unavailable');
        exit;
    }
    return $e;
};

// ═══════════════════════════════ OBSŁUGA POST ═══════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        http_response_code(403); exit('Nieprawidłowy token sesji.');
    }
    $op   = (string)($_POST['op'] ?? '');
    $exam = $load_exam((int)($_POST['exam_id'] ?? 0));

    if ($op === 'start') {
        $why = null;
        if (!ti_exam_can_start($exam, $client_id, $why)) {
            header('Location: exam.php?exam=' . (int)$exam['id'] . '&err=' . urlencode((string)$why)); exit;
        }
        $att = ti_exam_start_attempt((int)$exam['id'], $client_id);
        if (!$att) {
            header('Location: exam.php?exam=' . (int)$exam['id'] . '&err=' . urlencode('Nie udało się rozpocząć testu.')); exit;
        }
        header('Location: exam.php?exam=' . (int)$exam['id'] . '&solve=1'); exit;
    }

    if ($op === 'submit') {
        $att = ti_exam_open_attempt((int)$exam['id'], $client_id);
        if (!$att) { header('Location: exam.php?exam=' . (int)$exam['id']); exit; }

        // Zapisujemy komplet odpowiedzi z arkusza (autozapis mógł nie zdążyć).
        foreach (ti_exam_attempt_questions($att) as $q) {
            $qid = (int)$q['id'];
            ti_exam_answer_save((int)$att['id'], $qid, ti_exam_payload_from_post($_POST['a'][$qid] ?? []));
        }
        $r = ti_exam_submit((int)$att['id']);
        $suffix = $r['ok'] ? '' : '&engine=down';
        header('Location: exam.php?attempt=' . (int)$att['id'] . '&done=1' . $suffix); exit;
    }

    header('Location: index.php?tab=egzaminy'); exit;
}

// ═══════════════════════════════ WIDOK ══════════════════════════════════════
$view_attempt = null;
if (isset($_GET['attempt'])) {
    $view_attempt = ti_exam_attempt_get((int)$_GET['attempt']);
    if (!$view_attempt || (int)$view_attempt['client_id'] !== $client_id) {
        header('Location: index.php?tab=egzaminy&err=unavailable'); exit;
    }
    $exam = ti_exam_get((int)$view_attempt['exam_id']);
    if (!$exam) { header('Location: index.php?tab=egzaminy&err=unavailable'); exit; }
} else {
    $exam = $load_exam((int)($_GET['exam'] ?? 0));
}

$solving = isset($_GET['solve']) && !$view_attempt;
$attempt = $solving ? ti_exam_open_attempt((int)$exam['id'], $client_id) : null;
if ($solving && !$attempt) { header('Location: exam.php?exam=' . (int)$exam['id']); exit; }

$err_msg = trim((string)($_GET['err'] ?? ''));

$KP_SKIP_TAB_MEMORY = true;
$KP_TITLE  = EQUI_EXAMS_STUDENT_LABEL . ': ' . (string)$exam['title'];
$KP_TOPBAR = ['brand' => EQUI_EXAMS_STUDENT_LABEL, 'icon' => 'card-checklist'];
include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="container py-4" style="max-width:56rem">

<?php if ($err_msg !== ''): ?>
  <div class="alert alert-warning d-flex gap-2" role="alert">
    <i class="bi bi-exclamation-triangle fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span><?= h($err_msg) ?></span>
  </div>
<?php endif; ?>

<?php if ($view_attempt): require __DIR__ . '/_exam_result.php'; ?>

<?php elseif (!$solving): /* ══════════════ EKRAN STARTOWY ══════════════ */
  $why   = null;
  $can   = ti_exam_can_start($exam, $client_id, $why);
  $best  = ti_exam_best_attempt((int)$exam['id'], $client_id);
  $open  = ti_exam_open_attempt((int)$exam['id'], $client_id);
  $count = count(ti_exam_questions((int)$exam['id']));
  $mode  = K30_TI_EXAM_MODES[(string)$exam['mode']] ?? K30_TI_EXAM_MODES['exam'];
?>
  <a class="btn btn-sm btn-outline-secondary mb-3" href="index.php?tab=egzaminy">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy testów
  </a>

  <h1 class="h3 fw-bold mb-2"><?= h((string)$exam['title']) ?></h1>
  <p class="text-body-secondary">
    <i class="bi bi-<?= h((string)$mode['icon']) ?> me-1" aria-hidden="true"></i><?= h((string)$mode['label']) ?>
  </p>

  <?php if (trim((string)$exam['description']) !== ''): ?>
  <p class="lead"><?= nl2br(h((string)$exam['description'])) ?></p>
  <?php endif; ?>

  <h2 class="h5 fw-semibold mt-4">Zasady tego testu</h2>
  <ul class="list-group list-group-flush mb-4">
    <li class="list-group-item bg-transparent d-flex gap-2">
      <i class="bi bi-list-ol text-body-secondary" aria-hidden="true"></i>
      <span>Liczba pytań: <strong><?= $count ?></strong><?php
        $draw = (int)$exam['bank_draw'] + (int)$exam['fixed_draw'];
        if ($draw > 0): ?> (w Twoim zestawie znajdzie się losowany podzbiór)<?php endif; ?></span>
    </li>
    <li class="list-group-item bg-transparent d-flex gap-2">
      <i class="bi bi-clock text-body-secondary" aria-hidden="true"></i>
      <span>Czas: <strong><?= (int)$exam['time_limit_min'] > 0 ? (int)$exam['time_limit_min'] . ' min' : 'bez limitu' ?></strong></span>
    </li>
    <li class="list-group-item bg-transparent d-flex gap-2">
      <i class="bi bi-arrow-repeat text-body-secondary" aria-hidden="true"></i>
      <span>Podejścia: <strong><?= (int)$exam['max_attempts'] > 0 ? (int)$exam['max_attempts'] : 'bez ograniczeń' ?></strong></span>
    </li>
    <?php if ((int)$exam['pass_pct'] > 0): ?>
    <li class="list-group-item bg-transparent d-flex gap-2">
      <i class="bi bi-award text-body-secondary" aria-hidden="true"></i>
      <span>Próg zaliczenia: <strong><?= (int)$exam['pass_pct'] ?>%</strong></span>
    </li>
    <?php endif; ?>
    <?php if ((string)$exam['show_feedback'] === 'immediate'): ?>
    <li class="list-group-item bg-transparent d-flex gap-2">
      <i class="bi bi-lightbulb text-body-secondary" aria-hidden="true"></i>
      <span>Po każdej odpowiedzi zobaczysz od razu wynik i wyjaśnienie.</span>
    </li>
    <?php endif; ?>
  </ul>

  <?php if ($best): ?>
  <div class="alert alert-info" role="status">
    Twój najlepszy dotychczasowy wynik: <strong><?= ti_exam_pct($best) ?>%</strong>.
    <a href="exam.php?attempt=<?= (int)$best['id'] ?>">Zobacz szczegóły</a>
  </div>
  <?php endif; ?>

  <?php if ($open): ?>
    <form method="get" class="d-inline">
      <input type="hidden" name="exam" value="<?= (int)$exam['id'] ?>">
      <input type="hidden" name="solve" value="1">
      <button class="btn btn-lg btn-primary" type="submit">
        <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Wróć do rozpoczętego testu
      </button>
    </form>
  <?php elseif ($can): ?>
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
      <input type="hidden" name="op" value="start">
      <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
      <button class="btn btn-lg btn-primary" type="submit">
        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Rozpocznij test
      </button>
      <?php if ((int)$exam['time_limit_min'] > 0): ?>
      <p class="form-text mt-2">
        Po kliknięciu ruszy licznik <?= (int)$exam['time_limit_min'] ?> minut. Przygotuj się przed startem.
      </p>
      <?php endif; ?>
    </form>
  <?php else: ?>
    <div class="alert alert-secondary d-flex gap-2" role="status">
      <i class="bi bi-info-circle fs-5 flex-shrink-0" aria-hidden="true"></i>
      <span><?= h((string)$why) ?></span>
    </div>
  <?php endif; ?>

<?php else: require __DIR__ . '/_exam_sheet.php'; endif; ?>

  <?= equi_exams_footer_html() ?>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
