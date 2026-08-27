<?php
/**
 * karty30/ti/dydaktyk/_exam_meta_form.php
 * Formularz ustawień egzaminu (Equi Exams). Dołączany z _exam_build_view.php
 * dwa razy: raz jako „nowy egzamin" ($exam === null), raz jako edycja.
 *
 * Formularz niczego nie waliduje — wartości sprawdza i normalizuje silnik Java
 * (pl.feer.exam.authoring.ExamAuthoring). Atrybuty min/max są tu tylko
 * podpowiedzią dla przeglądarki, nie regułą systemu.
 */
$ex   = $exam ?? null;
$v    = function (string $key, $def = '') use ($ex) { return $ex ? ($ex[$key] ?? $def) : $def; };
$mode = (string)($ex['mode'] ?? 'exam');

$lessons = $course_id ? db_all(
    "SELECT id, lesson_date, topic FROM k30_ti_sessions WHERE course_id=? ORDER BY lesson_date DESC LIMIT 60",
    [$course_id]) : [];
?>
<form method="post" class="card border-0 shadow-sm">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="save_exam">
  <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
  <?php if ($ex): ?><input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>"><?php endif; ?>

  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-sliders me-1" aria-hidden="true"></i><?= $ex ? 'Ustawienia egzaminu' : 'Nowy egzamin' ?>
  </div>
  <div class="card-body">

    <div class="mb-3">
      <label class="form-label" for="exTitle">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
      <input class="form-control" id="exTitle" name="title" required maxlength="200"
             value="<?= h((string)$v('title')) ?>">
    </div>

    <div class="mb-3">
      <label class="form-label" for="exDesc">Opis dla kursanta</label>
      <textarea class="form-control" id="exDesc" name="description" rows="2"
                aria-describedby="exDescHelp"><?= h((string)$v('description')) ?></textarea>
      <div class="form-text" id="exDescHelp">Widoczny na ekranie startowym przed rozpoczęciem.</div>
    </div>

    <fieldset class="mb-3">
      <legend class="form-label fs-6 mb-2">Formuła testu</legend>
      <?php foreach (K30_TI_EXAM_MODES as $key => $m): ?>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="mode" id="exMode<?= h($key) ?>"
               value="<?= h($key) ?>" <?= $mode === $key ? 'checked' : '' ?>
               aria-describedby="exMode<?= h($key) ?>Help">
        <label class="form-check-label" for="exMode<?= h($key) ?>">
          <i class="bi bi-<?= h($m['icon']) ?> me-1" aria-hidden="true"></i><?= h($m['label']) ?>
        </label>
        <div class="form-text mt-0" id="exMode<?= h($key) ?>Help"><?= h($m['hint']) ?></div>
      </div>
      <?php endforeach; ?>
    </fieldset>

    <div class="row g-2 mb-3">
      <div class="col-6 col-md-4">
        <label class="form-label" for="exTime">Limit czasu (min)</label>
        <input class="form-control" id="exTime" name="time_limit_min" type="number" min="0" max="600"
               value="<?= (int)$v('time_limit_min', 45) ?>" aria-describedby="exTimeHelp">
        <div class="form-text" id="exTimeHelp">0 = bez limitu</div>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="exPass">Próg zaliczenia (%)</label>
        <input class="form-control" id="exPass" name="pass_pct" type="number" min="0" max="100"
               value="<?= (int)$v('pass_pct', 0) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="exAttempts">Liczba podejść</label>
        <input class="form-control" id="exAttempts" name="max_attempts" type="number" min="0" max="50"
               value="<?= (int)$v('max_attempts', 1) ?>" aria-describedby="exAttemptsHelp">
        <div class="form-text" id="exAttemptsHelp">0 = bez limitu</div>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-6">
        <label class="form-label" for="exFixed">Losuj z pytań stałych</label>
        <input class="form-control" id="exFixed" name="fixed_draw" type="number" min="0" max="500"
               value="<?= (int)$v('fixed_draw', 0) ?>" aria-describedby="exDrawHelp">
      </div>
      <div class="col-6">
        <label class="form-label" for="exBank">Losuj z banku pytań</label>
        <input class="form-control" id="exBank" name="bank_draw" type="number" min="0" max="500"
               value="<?= (int)$v('bank_draw', 0) ?>" aria-describedby="exDrawHelp">
      </div>
      <div class="col-12">
        <div class="form-text" id="exDrawHelp">
          0 = weź wszystkie pytania z danej puli. Pytania oznaczone jako „bank" nie wchodzą do zestawu
          na stałe — każdy kursant dostaje z nich losowany podzbiór.
        </div>
      </div>
    </div>

    <div class="mb-3">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="exShufQ" name="shuffle_questions"
               <?= (int)$v('shuffle_questions', 1) === 1 ? 'checked' : '' ?>>
        <label class="form-check-label" for="exShufQ">Losowa kolejność pytań</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="exShufO" name="shuffle_options"
               <?= (int)$v('shuffle_options', 1) === 1 ? 'checked' : '' ?>>
        <label class="form-check-label" for="exShufO">Losowa kolejność wariantów odpowiedzi</label>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="exFeedback">Pokazywanie poprawnych odpowiedzi</label>
        <select class="form-select" id="exFeedback" name="show_feedback">
          <?php foreach ([
              'never'        => 'Nigdy — kursant widzi tylko wynik',
              'after_submit' => 'Po oddaniu pracy',
              'immediate'    => 'Od razu po każdej odpowiedzi (trening)',
          ] as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= (string)$v('show_feedback', 'never') === $key ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="exNeg">Punktacja pytań wielokrotnych</label>
        <select class="form-select" id="exNeg" name="neg_marking">
          <?php foreach (K30_TI_EXAM_NEG as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= (string)$v('neg_marking', 'partial') === $key ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-6">
        <label class="form-label" for="exOpen">Otwarcie</label>
        <input class="form-control" id="exOpen" name="open_at" type="datetime-local"
               value="<?= h(str_replace(' ', 'T', substr((string)$v('open_at', ''), 0, 16))) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="exClose">Zamknięcie</label>
        <input class="form-control" id="exClose" name="close_at" type="datetime-local"
               value="<?= h(str_replace(' ', 'T', substr((string)$v('close_at', ''), 0, 16))) ?>">
      </div>
    </div>

    <?php if ($lessons): ?>
    <div class="mb-3">
      <label class="form-label" for="exSession">Powiązana lekcja (nieobowiązkowo)</label>
      <select class="form-select" id="exSession" name="session_id">
        <option value="">— brak powiązania —</option>
        <?php foreach ($lessons as $l): ?>
        <option value="<?= (int)$l['id'] ?>" <?= (int)$v('session_id', 0) === (int)$l['id'] ? 'selected' : '' ?>>
          <?= h(date('d.m.Y', strtotime((string)$l['lesson_date']))) ?> — <?= h(mb_strimwidth((string)$l['topic'], 0, 50, '…')) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="row g-2 align-items-end">
      <div class="col-md-7">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="exActive" name="is_active"
                 <?= (int)$v('is_active', 0) === 1 ? 'checked' : '' ?>>
          <label class="form-check-label" for="exActive">Udostępnij kursantom</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="exSync" name="sync_grade"
                 <?= (int)$v('sync_grade', 0) === 1 ? 'checked' : '' ?>
                 aria-describedby="exSyncHelp">
          <label class="form-check-label" for="exSync">Zapisuj wynik do e-dziennika</label>
          <div class="form-text mt-0" id="exSyncHelp">Ocena 1–6 wyliczana z procentów, kategoria „sprawdzian".</div>
        </div>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="exWeight">Waga oceny</label>
        <input class="form-control" id="exWeight" name="grade_weight" type="number" min="1" max="10"
               value="<?= (int)$v('grade_weight', 3) ?>">
      </div>
    </div>
  </div>

  <div class="card-footer bg-transparent">
    <button class="btn btn-primary" type="submit">
      <i class="bi bi-save me-1" aria-hidden="true"></i><?= $ex ? 'Zapisz ustawienia' : 'Utwórz egzamin' ?>
    </button>
  </div>
</form>
