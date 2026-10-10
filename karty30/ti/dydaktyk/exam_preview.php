<?php
/**
 * karty30/ti/dydaktyk/exam_preview.php
 * Equi Exams — „Podejrzyj pytanie”: pytanie dokładnie tak, jak zobaczy je kursant.
 *
 *   ?q=ID — podgląd jednego pytania (z przejściem do poprzedniego/następnego w zestawie).
 *
 * Renderuje ten sam szablon co arkusz kursanta (kursant/_exam_question_student.php),
 * więc podgląd nie rozjedzie się z tym, co widzi kursant. Formularz nie ma akcji —
 * można zaznaczać i wpisywać na próbę, ale nic nie jest zapisywane ani oceniane.
 *
 * Autoryzacja: dyd_require() + kurs egzaminu należy do prowadzącego (jak exam_build.php).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$my_cids = array_map(fn($c) => (int)$c['id'], dyd_courses($uid));

$q    = ti_exam_question_get((int)($_GET['q'] ?? 0));
$exam = $q ? ti_exam_get((int)$q['exam_id']) : null;
if (!$q || !$exam || !in_array((int)$exam['course_id'], $my_cids, true)) {
    http_response_code(403); exit('Brak uprawnień do tego pytania.');
}

// Pozycja pytania w zestawie + sąsiedzi do nawigacji.
$questions = ti_exam_questions((int)$exam['id']);
$ids = array_map(fn($x) => (int)$x['id'], $questions);
$pos = array_search((int)$q['id'], $ids, true);
$pos = $pos === false ? 0 : (int)$pos;
$prev = $ids[$pos - 1] ?? null;
$next = $ids[$pos + 1] ?? null;

// Zmienne wejściowe szablonu kursanta. Bez podejścia: brak odpowiedzi, kolejność
// neutralna (w prawdziwym podejściu warianty mogą być wylosowane — patrz uwaga niżej).
$qid       = (int)$q['id'];
$n         = $pos;
$payload   = [];
$qcfg      = ti_exam_config($q);
$orderMap  = [];
$immediate = false;
$languages = ti_exam_available_languages();

$KP_SKIP_TAB_MEMORY = true;
$KP_TITLE  = 'Podgląd pytania — ' . (string)$exam['title'];
$KP_TOPBAR = ['brand' => EQUI_EXAMS_NAME . ' — podgląd', 'icon' => 'eye',
              'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
include dirname(__DIR__) . '/kursant/_layout_head.php';
$back = 'exam_build.php?exam_id=' . (int)$exam['id'] . '&q=' . $qid;
?>
<main id="main" class="container py-4" style="max-width:56rem">

  <nav aria-label="Ścieżka nawigacji" class="mb-2">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="index.php?tab=egzaminy">Panel prowadzącego</a></li>
      <li class="breadcrumb-item"><a href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>"><?= h((string)$exam['title']) ?></a></li>
      <li class="breadcrumb-item active" aria-current="page">Podgląd pytania <?= $pos + 1 ?></li>
    </ol>
  </nav>

  <h1 class="h4 fw-bold mb-3">
    <i class="bi bi-eye text-primary me-2" aria-hidden="true"></i>Podgląd pytania <?= $pos + 1 ?> z <?= count($ids) ?>
  </h1>

  <div class="alert alert-info d-flex gap-2" role="note">
    <i class="bi bi-info-circle fs-5 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong>Tak to pytanie zobaczy kursant.</strong>
      Możesz zaznaczać i wpisywać odpowiedzi na próbę — nic nie jest zapisywane ani oceniane.
      <?php if ((int)($exam['shuffle_options'] ?? 0) === 1): ?>
      <br>W egzaminie włączone jest mieszanie odpowiedzi — kursant zobaczy warianty w losowej kolejności.
      <?php endif; ?>
    </div>
  </div>

  <form action="#" onsubmit="return false" aria-label="Podgląd pytania — odpowiedzi nie są zapisywane" autocomplete="off">
    <?php require dirname(__DIR__) . '/kursant/_exam_question_student.php'; ?>
  </form>

  <?php if (trim((string)($q['explanation'] ?? '')) !== ''): ?>
  <details class="mb-4">
    <summary class="fw-semibold">Wyjaśnienie po odpowiedzi (widoczne dla kursanta zależnie od ustawień egzaminu)</summary>
    <p class="mt-2 mb-0"><?= nl2br(h((string)$q['explanation'])) ?></p>
  </details>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary" href="<?= h($back) ?>">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Wróć do edycji pytania
    </a>
    <?php if ($prev): ?>
    <a class="btn btn-outline-secondary" href="exam_preview.php?q=<?= $prev ?>">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Poprzednie pytanie
    </a>
    <?php endif; ?>
    <?php if ($next): ?>
    <a class="btn btn-outline-secondary" href="exam_preview.php?q=<?= $next ?>">
      Następne pytanie<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
