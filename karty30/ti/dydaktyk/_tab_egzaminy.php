<?php /* ═══════════════════ ZAKŁADKA: EGZAMINY (Equi Exams) ═══════════════════ */ ?>
<?php
/**
 * Skrót do modułu Equi Exams w panelu prowadzącego: przegląd egzaminów kursu,
 * to co czeka na ocenę i wejście do kreatora. Pełna praca (układanie pytań,
 * ocena, statystyki) dzieje się w exam_build.php i exam_review.php.
 */
$egz_list   = ti_exams_list($cur_course);
$egz_review = ti_exam_pending_review_count($cur_course);
$egz_health = ti_exam_engine_health();
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h5 class="mb-0 fw-semibold">
    <i class="bi bi-patch-question me-1" aria-hidden="true"></i>Egzaminy
    <span class="text-body-secondary fw-normal small">· <?= h(EQUI_EXAMS_NAME) ?></span>
  </h5>
  <a class="btn btn-sm btn-primary ms-auto" href="exam_build.php?course_id=<?= (int)$cur_course ?>">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz egzamin
  </a>
</div>

<?php if (empty($egz_health['ok'])): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 small" role="alert">
  <i class="bi bi-plug fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span><strong>Silnik Equi Exams nie odpowiada.</strong> Kursanci mogą rozwiązywać testy — prace zostaną
    zapisane i ocenione, gdy usługa wróci. Tworzenie i edycja pytań są w tym czasie wstrzymane.
    <span class="d-block text-body-secondary"><?= h((string)($egz_health['error'] ?? '')) ?></span></span>
</div>
<?php endif; ?>

<?php if ($egz_review > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 small">
  <i class="bi bi-clipboard-check fs-5 flex-shrink-0" aria-hidden="true"></i>
  <span><?= $egz_review ?> <?= $egz_review === 1 ? 'podejście czeka' : 'podejść czeka' ?> na Twoją ocenę.</span>
</div>
<?php endif; ?>

<?php if (!$egz_list): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body d-flex flex-column align-items-center justify-content-center text-center py-5" style="min-height:220px">
    <i class="bi bi-patch-question mb-3" style="font-size:3rem;opacity:.3" aria-hidden="true"></i>
    <h6 class="fw-semibold mb-1">Brak egzaminów w tej grupie</h6>
    <p class="text-body-secondary small mb-3" style="max-width:34rem">
      Equi Exams obsługuje pytania zamknięte, prawda/fałsz, luki, krótkie odpowiedzi z kryteriami
      oraz zadania z kodem sprawdzane uruchomieniowo w piaskownicy.
    </p>
    <a class="btn btn-primary btn-sm" href="exam_build.php?course_id=<?= (int)$cur_course ?>">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz pierwszy egzamin
    </a>
  </div>
</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-hover align-middle">
    <caption class="visually-hidden">Egzaminy w wybranej grupie</caption>
    <thead><tr>
      <th scope="col">Tytuł</th><th scope="col">Formuła</th>
      <th scope="col" class="text-end">Pytań</th><th scope="col" class="text-end">Podejść</th>
      <th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($egz_list as $e): ?>
      <tr>
        <th scope="row" class="fw-normal">
          <a href="exam_build.php?exam_id=<?= (int)$e['id'] ?>"><?= h((string)$e['title']) ?></a>
          <?php if (trim((string)$e['description']) !== ''): ?>
            <span class="d-block small text-body-secondary"><?= h(mb_strimwidth((string)$e['description'], 0, 90, '…')) ?></span>
          <?php endif; ?>
        </th>
        <td class="small"><?= h(ti_exam_mode_label((string)$e['mode'])) ?></td>
        <td class="text-end"><?= (int)$e['n_questions'] ?></td>
        <td class="text-end"><?= (int)$e['n_attempts'] ?></td>
        <td>
          <?php if ((int)$e['n_review'] > 0): ?>
            <span class="badge text-bg-warning"><?= (int)$e['n_review'] ?> do oceny</span>
          <?php elseif (empty($e['is_active'])): ?>
            <span class="badge text-bg-secondary">ukryty</span>
          <?php else: ?>
            <span class="badge text-bg-success">udostępniony</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary" href="exam_build.php?exam_id=<?= (int)$e['id'] ?>">Pytania</a>
            <a class="btn btn-outline-primary"   href="exam_review.php?exam_id=<?= (int)$e['id'] ?>">Podejścia</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?= equi_exams_footer_html() ?>
