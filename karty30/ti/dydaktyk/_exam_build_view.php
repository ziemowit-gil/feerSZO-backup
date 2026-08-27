<?php
/**
 * karty30/ti/dydaktyk/_exam_build_view.php
 * Widok kreatora Equi Exams. Dołączany wyłącznie z exam_build.php, który
 * dostarcza: $me, $uid, $exam, $exams, $course, $course_id, $questions,
 * $edit_q, $new_type, $health, $languages, $my_courses.
 */

/** Wartość z konfiguracji edytowanego pytania (albo domyślna przy nowym). */
$cfg = $edit_q ? ti_exam_config($edit_q) : [];
$cv  = function (string $key, $default = '') use ($cfg) { return $cfg[$key] ?? $default; };

/** Lista wartości z konfiguracji jako tekst wielolinijkowy do <textarea>. */
$cl = function (string $key) use ($cfg): string {
    $v = $cfg[$key] ?? [];
    return is_array($v) ? implode("\n", array_map('strval', $v)) : (string)$v;
};

$q_type    = $edit_q ? (string)$edit_q['type'] : $new_type;
$q_meta    = $q_type !== '' ? K30_TI_EXAM_TYPES[$q_type] : null;
$editing   = $edit_q !== null;
$form_open = $q_type !== '';
?>
<main id="main" class="container-xl py-4">

  <nav aria-label="Ścieżka nawigacji" class="mb-2">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="index.php?tab=egzaminy">Panel prowadzącego</a></li>
      <li class="breadcrumb-item"><a href="exam_build.php?course_id=<?= (int)$course_id ?>"><?= h((string)($course['name'] ?? 'Kurs')) ?></a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= $exam ? h((string)$exam['title']) : 'Egzaminy' ?></li>
    </ol>
  </nav>

  <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-patch-question text-primary me-2" aria-hidden="true"></i>
      <?= $exam ? h((string)$exam['title']) : EQUI_EXAMS_STAFF_LABEL ?>
    </h1>
    <?php if ($exam): ?>
      <span class="badge text-bg-<?= empty($exam['is_active']) ? 'secondary' : 'success' ?>">
        <?= empty($exam['is_active']) ? 'ukryty' : 'udostępniony' ?>
      </span>
      <span class="badge text-bg-light text-dark border"><?= h(ti_exam_mode_label((string)$exam['mode'])) ?></span>
    <?php endif; ?>
    <span class="ms-auto small">
      <?php if (!empty($health['ok'])): ?>
        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Silnik oceniania działa</span>
      <?php else: ?>
        <span class="badge text-bg-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Silnik oceniania niedostępny</span>
      <?php endif; ?>
    </span>
  </div>

  <?= flash_html() ?>

  <?php if (empty($health['ok'])): ?>
  <div class="alert alert-warning d-flex gap-2" role="alert">
    <i class="bi bi-plug fs-5 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong>Silnik ocen nie odpowiada.</strong>
      Możesz dalej układać pytania, ale oddane prace nie zostaną ocenione, dopóki usługa nie wróci —
      zostaną odłożone jako „do oceny" i wystarczy je wtedy ocenić ponownie jednym przyciskiem.
      <div class="small text-body-secondary mt-1"><?= h((string)($health['error'] ?? '')) ?></div>
    </div>
  </div>
  <?php endif; ?>

<?php if (!$exam): /* ══════════════ LISTA EGZAMINÓW KURSU ══════════════ */ ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <h2 class="h6 fw-semibold mb-2">Egzaminy w kursie</h2>
      <?php if (!$exams): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
          <i class="bi bi-patch-question mb-3 d-block" style="font-size:2.5rem;opacity:.3" aria-hidden="true"></i>
          <p class="mb-0 text-body-secondary">Nie ma jeszcze żadnego egzaminu. Utwórz pierwszy formularzem obok.</p>
        </div></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <caption class="visually-hidden">Lista egzaminów w kursie wraz z liczbą pytań i podejść</caption>
          <thead><tr>
            <th scope="col">Tytuł</th><th scope="col">Formuła</th>
            <th scope="col" class="text-end">Pytań</th><th scope="col" class="text-end">Podejść</th>
            <th scope="col">Stan</th>
          </tr></thead>
          <tbody>
          <?php foreach ($exams as $e): ?>
            <tr>
              <th scope="row" class="fw-normal">
                <a href="exam_build.php?exam_id=<?= (int)$e['id'] ?>"><?= h((string)$e['title']) ?></a>
              </th>
              <td><?= h(ti_exam_mode_label((string)$e['mode'])) ?></td>
              <td class="text-end"><?= (int)$e['n_questions'] ?></td>
              <td class="text-end"><?= (int)$e['n_attempts'] ?></td>
              <td>
                <?php if ((int)$e['n_review'] > 0): ?>
                  <a class="badge text-bg-warning text-decoration-none" href="exam_review.php?exam_id=<?= (int)$e['id'] ?>">
                    <?= (int)$e['n_review'] ?> do oceny
                  </a>
                <?php elseif (empty($e['is_active'])): ?>
                  <span class="badge text-bg-secondary">ukryty</span>
                <?php else: ?>
                  <span class="badge text-bg-success">udostępniony</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-5">
      <?php $exam = null; include __DIR__ . '/_exam_meta_form.php'; ?>
    </div>
  </div>

<?php else: /* ══════════════ EDYCJA KONKRETNEGO EGZAMINU ══════════════ */ ?>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="exam_build.php?course_id=<?= (int)$course_id ?>">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wszystkie egzaminy
    </a>
    <a class="btn btn-sm btn-outline-primary" href="exam_review.php?exam_id=<?= (int)$exam['id'] ?>">
      <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Podejścia i ocenianie
    </a>
    <form method="post" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="toggle_active">
      <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
      <button class="btn btn-sm btn-outline-<?= empty($exam['is_active']) ? 'success' : 'warning' ?>" type="submit">
        <i class="bi bi-<?= empty($exam['is_active']) ? 'eye' : 'eye-slash' ?> me-1" aria-hidden="true"></i>
        <?= empty($exam['is_active']) ? 'Udostępnij kursantom' : 'Ukryj przed kursantami' ?>
      </button>
    </form>
    <form method="post" class="d-inline"
          onsubmit="return confirm('Usunąć egzamin wraz ze wszystkimi pytaniami i podejściami? Tej operacji nie da się cofnąć.');">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="delete_exam">
      <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
      <button class="btn btn-sm btn-outline-danger" type="submit">
        <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń egzamin
      </button>
    </form>
  </div>

  <div class="row g-4">
    <div class="col-xl-5">
      <?php include __DIR__ . '/_exam_meta_form.php'; ?>

      <h2 class="h6 fw-semibold mt-4 mb-2">Pytania w zestawie (<?= count($questions) ?>)</h2>
      <?php if (!$questions): ?>
        <p class="text-body-secondary small">Zestaw jest pusty — wybierz typ pytania po prawej stronie.</p>
      <?php else: ?>
      <ol class="list-group list-group-numbered mb-3">
        <?php foreach ($questions as $i => $q):
          $qm = K30_TI_EXAM_TYPES[(string)$q['type']] ?? null; ?>
        <li class="list-group-item d-flex gap-2 align-items-start <?= ($edit_q && (int)$edit_q['id'] === (int)$q['id']) ? 'border-primary border-2' : '' ?>">
          <div class="me-auto">
            <a class="fw-semibold text-decoration-none" href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>&amp;q=<?= (int)$q['id'] ?>">
              <?= h(mb_strimwidth(trim(strip_tags((string)$q['prompt'])), 0, 90, '…')) ?>
            </a>
            <div class="small text-body-secondary">
              <i class="bi bi-<?= h((string)($qm['icon'] ?? 'question')) ?> me-1" aria-hidden="true"></i>
              <?= h(ti_exam_type_label((string)$q['type'])) ?>
              · <?= h(rtrim(rtrim(number_format((float)$q['points'], 2, ',', ' '), '0'), ',')) ?> pkt
              <?php if ((int)$q['in_bank'] === 1): ?>
                · <span class="badge text-bg-light text-dark border">bank pytań</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="btn-group btn-group-sm" role="group" aria-label="Kolejność i usuwanie pytania <?= $i + 1 ?>">
            <form method="post"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="move_question">
              <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="dir" value="-1">
              <button class="btn btn-outline-secondary" type="submit" <?= $i === 0 ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-up" aria-hidden="true"></i><span class="visually-hidden">Przenieś wyżej</span>
              </button>
            </form>
            <form method="post"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="move_question">
              <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="dir" value="1">
              <button class="btn btn-outline-secondary" type="submit" <?= $i === count($questions) - 1 ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-down" aria-hidden="true"></i><span class="visually-hidden">Przenieś niżej</span>
              </button>
            </form>
            <form method="post" onsubmit="return confirm('Usunąć to pytanie?');">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="delete_question">
              <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <button class="btn btn-outline-danger" type="submit">
                <i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Usuń pytanie <?= $i + 1 ?></span>
              </button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <p class="small text-body-secondary">
        Suma punktów zestawu: <strong><?= h(rtrim(rtrim(number_format(ti_exam_max_score((int)$exam['id']), 2, ',', ' '), '0'), ',')) ?></strong>
      </p>
      <?php endif; ?>

      <?php
        $legacy = function_exists('k30_ti_tests_list') ? k30_ti_tests_list((int)$exam['course_id']) : [];
        if ($legacy): ?>
      <details class="mb-3">
        <summary class="small">Zaimportuj pytania ze starszego modułu „Testy"</summary>
        <form method="post" class="mt-2 d-flex gap-2 align-items-end flex-wrap">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="import_test">
          <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
          <div class="flex-grow-1">
            <label class="form-label small mb-1" for="importTest">Test źródłowy</label>
            <select class="form-select form-select-sm" id="importTest" name="test_id">
              <?php foreach ($legacy as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= h((string)$t['title']) ?> (<?= (int)$t['n_questions'] ?> pytań)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-sm btn-outline-primary" type="submit">Importuj</button>
        </form>
        <p class="form-text mb-0">Pytania otwarte trafią jako „krótka odpowiedź" z oceną prowadzącego — uzupełnij im kryteria.</p>
      </details>
      <?php endif; ?>
    </div>

    <div class="col-xl-7">
      <h2 class="h6 fw-semibold mb-2"><?= $editing ? 'Edycja pytania' : 'Nowe pytanie' ?></h2>

      <?php if (!$form_open): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <p class="mb-3">Wybierz rodzaj pytania — formularz dopasuje się do niego.</p>
          <div class="row row-cols-1 row-cols-md-2 g-2">
            <?php foreach (K30_TI_EXAM_TYPES as $key => $m): ?>
            <div class="col">
              <a class="btn btn-outline-primary w-100 text-start h-100 d-flex gap-2 align-items-start"
                 href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>&amp;new=<?= h($key) ?>">
                <i class="bi bi-<?= h($m['icon']) ?> fs-5 mt-1" aria-hidden="true"></i>
                <span>
                  <span class="fw-semibold d-block"><?= h($m['label']) ?></span>
                  <span class="small text-body-secondary"><?= h($m['hint']) ?></span>
                </span>
              </a>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php else: include __DIR__ . '/_exam_question_form.php'; endif; ?>
    </div>
  </div>

<?php endif; ?>

  <?= equi_exams_footer_html() ?>
</main>
