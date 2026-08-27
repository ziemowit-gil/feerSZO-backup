<?php
/**
 * karty30/ti/kursant/_exam_result.php
 * Ekran wyniku oddanej pracy. Dołączany z exam.php przy ?attempt=ID.
 *
 * Zakres pokazywanych szczegółów zależy od ustawienia egzaminu:
 *   never        — sam wynik liczbowy,
 *   after_submit — wynik + rozstrzygnięcie i wyjaśnienie przy każdym pytaniu,
 *   immediate    — jak wyżej (kursant widział je już w trakcie).
 *
 * Zmienne wejściowe: $exam, $view_attempt, $client_id.
 */
$att      = $view_attempt;
$pct      = ti_exam_pct($att);
$passed   = ti_exam_passed($exam, $att);
$pending  = (int)$att['needs_review'] === 1;
$detailed = (string)$exam['show_feedback'] !== 'never';
$engineDown = ($_GET['engine'] ?? '') === 'down' || (string)$att['engine_status'] === 'pending';
$fmt      = fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
?>
<a class="btn btn-sm btn-outline-secondary mb-3" href="index.php?tab=egzaminy">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy testów
</a>

<h1 class="h4 fw-bold mb-1"><?= h((string)$exam['title']) ?></h1>
<p class="text-body-secondary">
  Podejście nr <?= (int)$att['attempt_no'] ?><?php
  if ($att['submitted_at']): ?> · oddane <?= h(date('d.m.Y H:i', strtotime((string)$att['submitted_at']))) ?><?php endif; ?>
</p>

<?php if ($engineDown): ?>
  <div class="alert alert-warning d-flex gap-2" role="status">
    <i class="bi bi-hourglass-split fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span><strong>Twoja praca została zapisana.</strong> Ocena pojawi się nieco później —
      sprawdzanie odbywa się po stronie serwera i chwilowo nie jest dostępne.
      Nie musisz nic robić ani rozwiązywać testu ponownie.</span>
  </div>
<?php elseif ($pending): ?>
  <div class="alert alert-info d-flex gap-2" role="status">
    <i class="bi bi-person-check fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span>Część odpowiedzi ocenia prowadzący. Wynik poniżej jest wstępny i może się jeszcze zmienić.</span>
  </div>
<?php endif; ?>

<?php /* Wynik: nigdy nie sam kolor — zawsze ikona + słowny opis. */ ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body text-center py-4">
    <p class="display-6 fw-bold mb-1"><?= $pct ?>%</p>
    <p class="mb-2 text-body-secondary">
      <?= h($fmt((float)$att['score'])) ?> z <?= h($fmt((float)$att['max_score'])) ?> punktów
    </p>
    <?php if ($pending || $engineDown): ?>
      <p class="fs-5 mb-0"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Wynik wstępny</p>
    <?php elseif ($passed): ?>
      <p class="fs-5 mb-0 text-success"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Test zaliczony</p>
    <?php else: ?>
      <p class="fs-5 mb-0 text-danger"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i>Test niezaliczony</p>
    <?php endif; ?>
    <?php if ((int)$exam['pass_pct'] > 0): ?>
      <p class="small text-body-secondary mb-0 mt-1">Próg zaliczenia: <?= (int)$exam['pass_pct'] ?>%</p>
    <?php endif; ?>
    <?php if ((int)$att['is_late'] === 1): ?>
      <p class="small mb-0 mt-2"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Praca oddana po upływie czasu.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($detailed):
  $questions = ti_exam_attempt_questions($att);
  $answers   = ti_exam_answers((int)$att['id']);
  $orderMap  = ti_exam_attempt_option_order($att); ?>
  <h2 class="h5 fw-semibold mb-3">Twoje odpowiedzi</h2>

  <?php foreach ($questions as $n => $q):
    $qid     = (int)$q['id'];
    $ans     = $answers[$qid] ?? null;
    $result  = $ans && $ans['result'] ? json_decode((string)$ans['result'], true) : null;
    $details = (array)($result['details'] ?? []);
    $awarded = $ans && $ans['points_awarded'] !== null ? (float)$ans['points_awarded'] : null;
    $maxp    = (float)$q['points'];
    $review  = $ans ? (int)$ans['needs_review'] === 1 : true;
    $full    = $awarded !== null && $maxp > 0 && $awarded >= $maxp - 1e-9; ?>
  <section class="card border-0 shadow-sm mb-3" aria-labelledby="res<?= $qid ?>">
    <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
      <h3 class="h6 mb-0 fw-semibold" id="res<?= $qid ?>">Pytanie <?= (int)$n + 1 ?></h3>
      <span class="ms-auto">
        <?php if ($review): ?>
          <span class="badge text-bg-info"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>czeka na ocenę</span>
        <?php elseif ($full): ?>
          <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>poprawnie</span>
        <?php elseif ($awarded !== null && $awarded > 0): ?>
          <span class="badge text-bg-warning"><i class="bi bi-dash-lg me-1" aria-hidden="true"></i>częściowo</span>
        <?php else: ?>
          <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>niepoprawnie</span>
        <?php endif; ?>
        <span class="badge text-bg-light text-dark border">
          <?= $awarded === null ? '—' : h($fmt($awarded)) ?> / <?= h($fmt($maxp)) ?> pkt
        </span>
      </span>
    </div>
    <div class="card-body">
      <p><?= nl2br(h((string)$q['prompt'])) ?></p>

      <?php if (trim((string)($ans['feedback'] ?? '')) !== ''): ?>
        <p class="mb-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h((string)$ans['feedback']) ?></p>
      <?php endif; ?>

      <?php if (!empty($details['cases'])): ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ((array)$details['cases'] as $c): ?>
        <li>
          <?php if (!empty($c['passed'])): ?>
            <i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i>
          <?php else: ?>
            <i class="bi bi-x-circle text-danger me-1" aria-hidden="true"></i>
          <?php endif; ?>
          <?= h((string)($c['name'] ?? '')) ?> —
          <?= !empty($c['passed']) ? 'zdany' : (!empty($c['timedOut']) ? 'przekroczony czas' : 'niezdany') ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if (!empty($details['compileError'])): ?>
      <details class="mb-2">
        <summary>Komunikat kompilatora</summary>
        <pre class="small mb-0"><code><?= h((string)$details['compileError']) ?></code></pre>
      </details>
      <?php endif; ?>

      <?php if (trim((string)($ans['teacher_note'] ?? '')) !== ''): ?>
      <p class="border-start border-3 ps-2 mb-2">
        <span class="fw-semibold">Komentarz prowadzącego:</span> <?= h((string)$ans['teacher_note']) ?>
      </p>
      <?php endif; ?>

      <?php if (trim((string)($result['explanation'] ?? '')) !== ''): ?>
      <p class="alert alert-light border mb-0">
        <i class="bi bi-lightbulb me-1" aria-hidden="true"></i><?= nl2br(h((string)$result['explanation'])) ?>
      </p>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php
  $why = null;
  if (ti_exam_can_start($exam, $client_id, $why)): ?>
<form method="post" class="mt-3">
  <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
  <input type="hidden" name="op" value="start">
  <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
  <button class="btn btn-primary" type="submit">
    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Rozwiąż ponownie
  </button>
</form>
<?php endif; ?>
