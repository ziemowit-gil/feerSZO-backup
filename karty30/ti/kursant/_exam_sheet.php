<?php
/**
 * karty30/ti/kursant/_exam_sheet.php
 * Arkusz z pytaniami. Dołączany z exam.php, gdy trwa podejście.
 *
 * Zmienne wejściowe: $exam, $attempt, $student, $client_id.
 *
 * Dostępność:
 *   • pasek postępu i licznik czasu są w <header> arkusza, licznik ma role="timer",
 *   • komunikaty o kończącym się czasie i o stanie zapisu idą do DWÓCH osobnych
 *     regionów aria-live="polite" — dzięki temu ogłoszenie o zapisie nie kasuje
 *     ostrzeżenia o czasie,
 *   • ostrzeżenia mają ikonę i tekst, nie tylko kolor.
 */
$questions  = ti_exam_attempt_questions($attempt);
$answers    = ti_exam_answers((int)$attempt['id']);
$orderMap   = ti_exam_attempt_option_order($attempt);
$secondsLeft = ti_exam_seconds_left($attempt);
$immediate  = (string)$exam['show_feedback'] === 'immediate';
$languages  = K30_TI_EXAM_LANGS;
?>
<header class="mb-4">
  <h1 class="h4 fw-bold mb-1"><?= h((string)$exam['title']) ?></h1>
  <p class="text-body-secondary mb-2">
    Pytań w Twoim zestawie: <strong><?= count($questions) ?></strong>
    · podejście nr <?= (int)$attempt['attempt_no'] ?>
  </p>

  <div class="d-flex flex-wrap gap-3 align-items-center">
    <?php if ($secondsLeft !== null): ?>
    <p class="mb-0 d-flex align-items-center gap-2">
      <i class="bi bi-clock-history fs-5" aria-hidden="true"></i>
      <span>Pozostały czas:
        <strong id="examTimer" role="timer" aria-live="off"
                data-seconds="<?= (int)$secondsLeft ?>"><?= gmdate('i:s', min(3599, (int)$secondsLeft)) ?></strong>
      </span>
    </p>
    <?php endif; ?>

    <p class="mb-0 d-flex align-items-center gap-2 small text-body-secondary" id="saveStatusWrap" hidden>
      <i class="bi bi-cloud-check" aria-hidden="true" id="saveIcon"></i>
      <span id="saveStatusText">Odpowiedzi zapisują się automatycznie.</span>
    </p>
  </div>

  <?php /* Dwa niezależne regiony ogłoszeń — patrz komentarz na górze pliku. */ ?>
  <p class="visually-hidden" id="timeAnnounce" aria-live="polite" role="status"></p>
  <p class="visually-hidden" id="saveAnnounce" aria-live="polite" role="status"></p>

  <?php if ($secondsLeft !== null): ?>
  <div class="alert alert-warning d-flex gap-2 mt-3" id="timeWarning" hidden role="alert">
    <i class="bi bi-hourglass-split fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span id="timeWarningText"></span>
  </div>
  <?php endif; ?>
</header>

<form method="post" id="examForm" novalidate>
  <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
  <input type="hidden" name="op" value="submit">
  <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
  <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>" id="examAttemptId">

  <?php foreach ($questions as $n => $q):
    $qid     = (int)$q['id'];
    $payload = ti_exam_answer_payload($answers[$qid] ?? null);
    $qcfg    = ti_exam_config($q);
    require __DIR__ . '/_exam_question_student.php';
  endforeach; ?>

  <div class="border-top pt-3 mt-4 d-flex flex-wrap gap-2 align-items-center">
    <button class="btn btn-lg btn-primary" type="submit" id="examSubmit">
      <i class="bi bi-send-check me-1" aria-hidden="true"></i>Oddaj pracę
    </button>
    <span class="text-body-secondary small">
      Po oddaniu nie będzie już można zmienić odpowiedzi.
    </span>
  </div>
</form>

<script>
// ── Arkusz Equi Exams: autozapis, licznik czasu, sprawdzanie treningowe ──────
// Wszystko poniżej jest dodatkiem do działającego formularza. Bez JavaScriptu
// arkusz nadal się wysyła, a limitu czasu pilnuje serwer przy przyjęciu pracy.
(function () {
  var form      = document.getElementById('examForm');
  if (!form) return;
  var attemptId = document.getElementById('examAttemptId').value;
  var token     = form.querySelector('input[name="_token"]').value;

  var saveWrap   = document.getElementById('saveStatusWrap');
  var saveText   = document.getElementById('saveStatusText');
  var saveIcon   = document.getElementById('saveIcon');
  var saveSay    = document.getElementById('saveAnnounce');
  if (saveWrap) saveWrap.hidden = false;

  function setSaveState(state, message, announce) {
    if (!saveText) return;
    saveText.textContent = message;
    if (saveIcon) {
      saveIcon.className = state === 'saving' ? 'bi bi-arrow-repeat'
                         : state === 'error'  ? 'bi bi-exclamation-triangle text-danger'
                         : 'bi bi-cloud-check';
    }
    if (announce && saveSay) saveSay.textContent = message;
  }

  // ── Autozapis pojedynczej odpowiedzi ──────────────────────────────────────
  var timers = {};
  function collect(qid) {
    var data = {};
    form.querySelectorAll('[name^="a[' + qid + ']"]').forEach(function (el) {
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      var m = el.name.match(/^a\[\d+\]\[(\w+)\](?:\[([^\]]*)\])?/);
      if (!m) return;
      var field = m[1], key = m[2];
      if (field === 'optionIds') { (data.optionIds = data.optionIds || []).push(el.value); }
      else if (key !== undefined && key !== '') { (data[field] = data[field] || {})[key] = el.value; }
      else { data[field] = el.value; }
    });
    return data;
  }

  function save(qid) {
    setSaveState('saving', 'Zapisuję…', false);
    fetch('exam_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ op: 'save', _token: token, attempt_id: attemptId,
                             question_id: qid, answer: collect(qid) })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) setSaveState('ok', 'Zapisano ' + new Date().toLocaleTimeString('pl-PL'), true);
        else setSaveState('error', 'Nie udało się zapisać — odpowiedzi zostaną wysłane przy oddaniu pracy.', true);
      })
      .catch(function () {
        setSaveState('error', 'Brak połączenia — odpowiedzi zostaną wysłane przy oddaniu pracy.', true);
      });
  }

  form.addEventListener('input', function (e) {
    var m = e.target.name && e.target.name.match(/^a\[(\d+)\]/);
    if (!m) return;
    var qid = m[1];
    clearTimeout(timers[qid]);
    timers[qid] = setTimeout(function () { save(qid); }, 900);
  });
  form.addEventListener('change', function (e) {
    var m = e.target.name && e.target.name.match(/^a\[(\d+)\]/);
    if (!m) return;
    clearTimeout(timers[m[1]]);
    save(m[1]);
  });

  // ── Sprawdzanie treningowe pojedynczego pytania ───────────────────────────
  document.querySelectorAll('[data-check]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var qid = btn.getAttribute('data-check');
      var box = document.getElementById('fb' + qid);
      if (!box) return;
      btn.disabled = true;
      box.hidden = false;
      box.className = 'alert alert-secondary mt-3';
      box.textContent = 'Sprawdzam odpowiedź…';
      fetch('exam_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'check', _token: token, attempt_id: attemptId,
                               question_id: qid, answer: collect(qid) })
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false;
          if (!d || !d.ok) {
            box.className = 'alert alert-warning mt-3';
            box.textContent = (d && d.error) ? d.error : 'Nie udało się sprawdzić odpowiedzi.';
            return;
          }
          box.className = 'alert ' + (d.correct ? 'alert-success' : 'alert-danger') + ' mt-3';
          box.innerHTML = '';
          var head = document.createElement('p');
          head.className = 'fw-semibold mb-1';
          head.textContent = (d.correct ? '✓ Poprawnie' : '✗ Jeszcze nie') +
                             ' · ' + d.points + ' / ' + d.maxPoints + ' pkt';
          box.appendChild(head);
          if (d.feedback) { var p = document.createElement('p'); p.className = 'mb-1'; p.textContent = d.feedback; box.appendChild(p); }
          if (d.explanation) { var e = document.createElement('p'); e.className = 'mb-0 small'; e.textContent = d.explanation; box.appendChild(e); }
        })
        .catch(function () {
          btn.disabled = false;
          box.className = 'alert alert-warning mt-3';
          box.textContent = 'Brak połączenia z serwerem.';
        });
    });
  });

  // ── Licznik czasu ─────────────────────────────────────────────────────────
  var timerEl = document.getElementById('examTimer');
  if (!timerEl) return;
  var left    = parseInt(timerEl.getAttribute('data-seconds'), 10) || 0;
  var say     = document.getElementById('timeAnnounce');
  var warnBox = document.getElementById('timeWarning');
  var warnTxt = document.getElementById('timeWarningText');
  // Progi ogłoszeń — czytnik ekranu nie może dostawać komunikatu co sekundę.
  var thresholds = [600, 300, 60, 30];
  var submitted  = false;

  function human(s) {
    var m = Math.floor(s / 60), r = s % 60;
    return m + ' min ' + (r < 10 ? '0' : '') + r + ' s';
  }

  function tick() {
    if (submitted) return;
    if (left <= 0) {
      submitted = true;
      timerEl.textContent = '00:00';
      if (say) say.textContent = 'Czas minął. Praca jest wysyłana.';
      form.submit();
      return;
    }
    var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
    timerEl.textContent = (h > 0 ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;

    if (thresholds.length && left <= thresholds[0]) {
      var t = thresholds.shift();
      var msg = 'Pozostało ' + human(left) + ' do końca testu.';
      if (say) say.textContent = msg;
      if (warnBox && warnTxt && t <= 300) { warnTxt.textContent = msg; warnBox.hidden = false; }
    }
    left--;
    setTimeout(tick, 1000);
  }
  tick();

  form.addEventListener('submit', function () { submitted = true; });
})();
</script>
