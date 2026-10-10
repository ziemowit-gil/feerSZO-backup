<?php
/**
 * karty30/ti/kursant/_exam_sheet.php
 * Arkusz z pytaniami. Dołączany z exam.php, gdy trwa podejście.
 *
 * Zmienne wejściowe: $exam, $attempt, $student, $client_id.
 *
 * Dwa tryby pracy:
 *   • BEZ JavaScriptu — wszystkie pytania na jednej stronie i zwykły przycisk
 *     „Oddaj test” na końcu (formularz działa jak zawsze),
 *   • z JavaScriptem (domyślnie) — jedno pytanie na ekran z przyciskami
 *     Wstecz/Dalej, paskiem postępu i nawigatorem numerów; przełącznik „Pokaż
 *     wszystkie pytania” wraca do widoku jednej strony (zapamiętany w przeglądarce).
 *
 * Dostępność (WCAG 2.1 AA):
 *   • po przejściu do pytania fokus trafia na jego <legend> (tabindex="-1"),
 *     a zmiana jest ogłaszana w regionie aria-live="polite" — raz na przejście,
 *   • nawigator podaje stan pytania słownie („odpowiedziano”, „odpowiedź niepełna”,
 *     „bez odpowiedzi”, „do sprawdzenia”) i ikoną, nie samym kolorem,
 *   • licznik czasu ma role="timer"; ostrzeżenia tylko na progach (10, 5, 1 min, 30 s),
 *   • stan autozapisu: „Zapisano” w osobnym regionie aria-live, błąd w role="alert",
 *   • przed oddaniem ekran podsumowania z listą pytań bez odpowiedzi (z linkami)
 *     i potwierdzenie; przyciski nawigacji mają co najmniej 44×44 px.
 * Wygląd wyłącznie z klas Bootstrapa — skórkę METRO dokłada osobny plik CSS.
 */
$questions  = ti_exam_attempt_questions($attempt);
$answers    = ti_exam_answers((int)$attempt['id']);
$orderMap   = ti_exam_attempt_option_order($attempt);
$secondsLeft = ti_exam_seconds_left($attempt);
$immediate  = (string)$exam['show_feedback'] === 'immediate';
$languages  = K30_TI_EXAM_LANGS;
$total      = count($questions);
$touch      = 'min-width:44px;min-height:44px';   // rozmiar celu dotykowego (nie kolor)
?>
<header class="mb-4">
  <h1 class="h4 fw-bold mb-1"><?= h((string)$exam['title']) ?></h1>
  <p class="text-body-secondary mb-2">
    Pytań w Twoim zestawie: <strong><?= $total ?></strong>
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

  <?php /* Osobne regiony ogłoszeń: czas, zapis i nawigacja nie kasują sobie nawzajem komunikatów. */ ?>
  <p class="visually-hidden" id="timeAnnounce" aria-live="polite" role="status"></p>
  <p class="visually-hidden" id="saveAnnounce" aria-live="polite" role="status"></p>
  <p class="visually-hidden" id="navAnnounce" aria-live="polite" role="status"></p>

  <div class="alert alert-danger d-flex gap-2 mt-3" id="saveError" role="alert" hidden>
    <i class="bi bi-exclamation-triangle fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span id="saveErrorText"></span>
  </div>

  <?php if ($secondsLeft !== null): ?>
  <div class="alert alert-warning d-flex gap-2 mt-3" id="timeWarning" hidden role="alert">
    <i class="bi bi-hourglass-split fs-5 flex-shrink-0" aria-hidden="true"></i>
    <span id="timeWarningText"></span>
  </div>
  <?php endif; ?>
</header>

<?php /* Nawigator — tylko z JavaScriptem (bez JS wszystkie pytania są widoczne naraz). */ ?>
<nav class="mb-4" id="examNav" aria-label="Nawigacja po pytaniach" hidden>
  <p class="fw-semibold mb-1" id="examProgressText">Pytanie 1 z <?= $total ?></p>
  <div class="progress mb-3" id="examProgress" role="progressbar" aria-labelledby="examProgressText"
       aria-valuemin="1" aria-valuemax="<?= max(1, $total) ?>" aria-valuenow="1">
    <div class="progress-bar" id="examProgressBar" style="width:<?= $total ? round(100 / $total, 2) : 100 ?>%"></div>
  </div>

  <h2 class="h6 fw-semibold mb-2" id="examNavH">Przejdź do pytania</h2>
  <ol class="list-unstyled d-flex flex-wrap gap-2 mb-2" aria-labelledby="examNavH">
    <?php foreach ($questions as $n => $q): ?>
    <li>
      <button type="button" class="btn btn-outline-secondary" style="<?= $touch ?>" data-goto="<?= (int)$n ?>">
        <span aria-hidden="true" data-state-icon></span>
        <span class="visually-hidden">Pytanie </span><?= (int)$n + 1 ?><span class="visually-hidden" data-state-text>, bez odpowiedzi</span>
      </button>
    </li>
    <?php endforeach; ?>
    <li>
      <button type="button" class="btn btn-outline-secondary" style="<?= $touch ?>" data-goto="summary">
        <i class="bi bi-list-check me-1" aria-hidden="true"></i>Podsumowanie
      </button>
    </li>
  </ol>
  <p class="small text-body-secondary mb-2">
    Oznaczenia: <i class="bi bi-check-lg" aria-hidden="true"></i> odpowiedziano,
    <i class="bi bi-circle-half" aria-hidden="true"></i> odpowiedź niepełna,
    <i class="bi bi-flag" aria-hidden="true"></i> do sprawdzenia; bez znaku — bez odpowiedzi.
  </p>

  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch" id="examShowAll">
    <label class="form-check-label" for="examShowAll">Pokaż wszystkie pytania na jednej stronie</label>
  </div>
</nav>

<form method="post" id="examForm" novalidate>
  <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
  <input type="hidden" name="op" value="submit">
  <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
  <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>" id="examAttemptId">

  <?php $sheet_issues = 0;
  foreach ($questions as $n => $q):
    $qid     = (int)$q['id'];
    $payload = ti_exam_answer_payload($answers[$qid] ?? null);
    if (ti_exam_answer_issue($q, $payload) !== null) $sheet_issues++;
    $qcfg    = ti_exam_config($q); ?>
  <div class="exam-q" id="pyt<?= (int)$n + 1 ?>" data-qn="<?= (int)$n ?>" data-qid="<?= $qid ?>">
    <?php require __DIR__ . '/_exam_question_student.php'; ?>
    <?php /* Znacznik „wróć później” — tylko w przeglądarce: pole bez name, nie trafia do serwera. */ ?>
    <div class="form-check mb-3" data-flag-wrap hidden>
      <input class="form-check-input" type="checkbox" id="flag<?= $qid ?>" data-flag="<?= $qid ?>">
      <label class="form-check-label" for="flag<?= $qid ?>">
        <i class="bi bi-flag me-1" aria-hidden="true"></i>Wróć do tego później (oznacz pytanie <?= (int)$n + 1 ?> do sprawdzenia)
      </label>
    </div>
  </div>
  <?php endforeach; ?>

  <?php /* Wstecz / Dalej — tylko w trybie jednego pytania na ekran. */ ?>
  <div class="d-flex flex-wrap gap-2 mb-4" id="examStepper" hidden>
    <button type="button" class="btn btn-lg btn-outline-secondary" style="<?= $touch ?>" id="examPrev">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz
    </button>
    <button type="button" class="btn btn-lg btn-primary" style="<?= $touch ?>" id="examNext">
      <span id="examNextLabel">Dalej</span><i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
    </button>
  </div>

  <section class="border-top pt-3 mt-4" id="examSummary" aria-labelledby="examSummaryH">
    <h2 class="h5 fw-semibold" id="examSummaryH" tabindex="-1">Podsumowanie przed oddaniem</h2>
    <?php /* Treść uzupełnia skrypt (stan odpowiedzi znany jest tylko w przeglądarce). */ ?>
    <div id="examSummaryBody"></div>
    <p class="mb-3">Po oddaniu nie będzie już można zmienić odpowiedzi.</p>

    <?php /* Potwierdzenie — pokazywane przez skrypt po pierwszym „Oddaj test”. */ ?>
    <div class="alert alert-warning" id="examConfirm" hidden>
      <h3 class="h6 fw-semibold" id="examConfirmH" tabindex="-1">Czy na pewno oddać test?</h3>
      <p class="mb-3" id="examConfirmText"></p>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-lg btn-primary" type="submit" style="<?= $touch ?>" id="examConfirmYes" name="confirmed" value="1">
          <i class="bi bi-send-check me-1" aria-hidden="true"></i>Tak, oddaj test
        </button>
        <button class="btn btn-lg btn-outline-secondary" type="button" style="<?= $touch ?>" id="examConfirmNo">
          Nie, wróć do pytań
        </button>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center" id="examSubmitRow">
      <button class="btn btn-lg btn-primary" type="submit" style="<?= $touch ?>" id="examSubmit">
        <i class="bi bi-send-check me-1" aria-hidden="true"></i>Oddaj test
      </button>
      <?php if ($sheet_issues > 0): ?>
      <button class="btn btn-lg btn-outline-secondary" type="submit" style="<?= $touch ?>" name="force_submit" value="1">
        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Oddaj mimo to
      </button>
      <?php endif; ?>
      <button class="btn btn-lg btn-outline-secondary" type="button" style="<?= $touch ?>" id="examBackToQuestions" hidden>
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do pytań
      </button>
    </div>
  </section>
</form>

<script>
// ── Arkusz Equi Exams: nawigacja, autozapis, licznik czasu, sprawdzanie ─────
// Wszystko poniżej jest dodatkiem do działającego formularza. Bez JavaScriptu
// arkusz pokazuje wszystkie pytania, wysyła się normalnie, a limitu czasu
// pilnuje serwer przy przyjęciu pracy.
(function () {
  var form      = document.getElementById('examForm');
  if (!form) return;
  var attemptId = document.getElementById('examAttemptId').value;
  var token     = form.querySelector('input[name="_token"]').value;

  function store(op, key, val) {
    try {
      if (op === 'get') return window.localStorage.getItem(key);
      window.localStorage.setItem(key, val);
    } catch (e) { /* tryb prywatny / zablokowane — działamy bez pamięci */ }
    return null;
  }

  // ── Stan zapisu: „Zapisano” w aria-live, błąd w role="alert" ─────────────
  var saveWrap   = document.getElementById('saveStatusWrap');
  var saveText   = document.getElementById('saveStatusText');
  var saveIcon   = document.getElementById('saveIcon');
  var saveSay    = document.getElementById('saveAnnounce');
  var saveErr    = document.getElementById('saveError');
  var saveErrTxt = document.getElementById('saveErrorText');
  if (saveWrap) saveWrap.hidden = false;

  function setSaveState(state, message, announce) {
    if (!saveText) return;
    saveText.textContent = message;
    if (saveIcon) {
      saveIcon.className = state === 'saving' ? 'bi bi-arrow-repeat'
                         : state === 'error'  ? 'bi bi-exclamation-triangle'
                         : state === 'warn'   ? 'bi bi-exclamation-circle'
                         : 'bi bi-cloud-check';
    }
    if (state === 'error') {
      if (saveErr && saveErrTxt) { saveErrTxt.textContent = message; saveErr.hidden = false; }
      return;   // role="alert" ogłosi sam — bez dublowania w aria-live
    }
    if (state !== 'saving' && saveErr) saveErr.hidden = true;
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
        if (d && d.ok && d.warning) setSaveState('warn', 'Zapisano, ale: ' + d.warning, true);
        else if (d && d.ok) setSaveState('ok', 'Zapisano ' + new Date().toLocaleTimeString('pl-PL'), true);
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
    refreshStates();
  });
  form.addEventListener('change', function (e) {
    var m = e.target.name && e.target.name.match(/^a\[(\d+)\]/);
    if (!m) return;
    clearTimeout(timers[m[1]]);
    save(m[1]);
    refreshStates();
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

  // ── Nawigacja: jedno pytanie na ekran ─────────────────────────────────────
  var qs        = Array.prototype.slice.call(document.querySelectorAll('.exam-q'));
  var total     = qs.length;
  var nav       = document.getElementById('examNav');
  var stepper   = document.getElementById('examStepper');
  var summary   = document.getElementById('examSummary');
  var sumBody   = document.getElementById('examSummaryBody');
  var showAllCb = document.getElementById('examShowAll');
  var navSay    = document.getElementById('navAnnounce');
  var progText  = document.getElementById('examProgressText');
  var prog      = document.getElementById('examProgress');
  var progBar   = document.getElementById('examProgressBar');
  var btnPrev   = document.getElementById('examPrev');
  var btnNext   = document.getElementById('examNext');
  var nextLabel = document.getElementById('examNextLabel');
  var backBtn   = document.getElementById('examBackToQuestions');
  var flagKey   = 'equiExamFlags:' + attemptId;
  var flags     = {};
  try { flags = JSON.parse(store('get', flagKey) || '{}') || {}; } catch (e) { flags = {}; }

  var current = 0;           // indeks pytania albo total = podsumowanie
  var showAll = store('get', 'equiExamShowAll') === '1';

  if (nav) nav.hidden = false;
  document.querySelectorAll('[data-flag-wrap]').forEach(function (w) { w.hidden = false; });
  document.querySelectorAll('[data-flag]').forEach(function (cb) {
    var qid = cb.getAttribute('data-flag');
    cb.checked = !!flags[qid];
    cb.addEventListener('change', function () {
      if (cb.checked) flags[qid] = 1; else delete flags[qid];
      store('set', flagKey, JSON.stringify(flags));
      refreshStates();
    });
  });

  /** Stan pytania: 'done' | 'partial' | 'none' — liczony z pól odpowiedzi (grupy po nazwie). */
  function questionState(box) {
    var groups = {};
    box.querySelectorAll('[name^="a["]').forEach(function (el) {
      var key = el.name;
      if (!(key in groups)) groups[key] = false;
      var filled;
      if (el.type === 'radio' || el.type === 'checkbox') filled = el.checked;
      else if (el.hasAttribute('data-starter')) filled = el.value.trim() !== '' && el.value !== el.getAttribute('data-starter');
      else filled = (el.value || '').trim() !== '';
      if (filled) groups[key] = true;
    });
    var names = Object.keys(groups), done = 0;
    names.forEach(function (k) { if (groups[k]) done++; });
    if (!names.length || done === 0) return 'none';
    return done === names.length ? 'done' : 'partial';
  }
  var stateWords = { done: 'odpowiedziano', partial: 'odpowiedź niepełna', none: 'bez odpowiedzi' };
  var stateIcons = { done: 'bi bi-check-lg me-1', partial: 'bi bi-circle-half me-1', none: '' };

  function info(i) {
    var box = qs[i];
    var st  = questionState(box);
    var qid = box.getAttribute('data-qid');
    return { state: st, flagged: !!flags[qid], words: stateWords[st] + (flags[qid] ? ', do sprawdzenia' : '') };
  }

  function refreshStates() {
    document.querySelectorAll('#examNav [data-goto]').forEach(function (b) {
      var g = b.getAttribute('data-goto');
      if (g === 'summary') {
        b.className = 'btn ' + (current === total && !showAll ? 'btn-primary' : 'btn-outline-secondary');
        if (current === total && !showAll) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
        return;
      }
      var i = parseInt(g, 10), inf = info(i);
      var icon = b.querySelector('[data-state-icon]');
      var txt  = b.querySelector('[data-state-text]');
      if (icon) icon.className = inf.flagged ? 'bi bi-flag me-1' : stateIcons[inf.state];
      if (txt)  txt.textContent = ', ' + inf.words;
      var isCur = !showAll && i === current;
      b.className = 'btn ' + (isCur ? 'btn-primary' : 'btn-outline-secondary');
      if (isCur) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    if (current === total || showAll) renderSummary();
  }

  function link(i, extra) {
    var a = document.createElement('a');
    a.href = '#pyt' + (i + 1);
    a.textContent = 'Pytanie ' + (i + 1) + (extra ? ' — ' + extra : '');
    a.addEventListener('click', function (e) { e.preventDefault(); go(i, true); });
    return a;
  }

  function renderSummary() {
    if (!sumBody) return;
    var none = [], partial = [], flagged = [], done = 0;
    for (var i = 0; i < total; i++) {
      var inf = info(i);
      if (inf.state === 'done') done++;
      if (inf.state === 'none') none.push(i);
      if (inf.state === 'partial') partial.push(i);
      if (inf.flagged) flagged.push(i);
    }
    sumBody.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'fw-semibold';
    p.textContent = 'Odpowiedziano na ' + done + ' z ' + total + ' pytań.' +
      (none.length ? ' Bez odpowiedzi: ' + none.length + '.' : ' Wszystkie pytania mają odpowiedź.');
    sumBody.appendChild(p);
    [[none, 'Pytania bez odpowiedzi', 'bi bi-x-circle'],
     [partial, 'Pytania z niepełną odpowiedzią', 'bi bi-circle-half'],
     [flagged, 'Pytania oznaczone do sprawdzenia', 'bi bi-flag']].forEach(function (grp) {
      if (!grp[0].length) return;
      var h = document.createElement('h3');
      h.className = 'h6 fw-semibold mt-3';
      var ic = document.createElement('i'); ic.className = grp[2] + ' me-1'; ic.setAttribute('aria-hidden', 'true');
      h.appendChild(ic); h.appendChild(document.createTextNode(grp[1] + ' (' + grp[0].length + ')'));
      var ul = document.createElement('ul');
      grp[0].forEach(function (i) { var li = document.createElement('li'); li.appendChild(link(i)); ul.appendChild(li); });
      sumBody.appendChild(h); sumBody.appendChild(ul);
    });
  }

  function layout() {
    if (stepper) stepper.hidden = showAll;
    if (backBtn) backBtn.hidden = showAll;
    qs.forEach(function (box, i) { box.hidden = !showAll && i !== current; });
    if (summary) summary.hidden = !showAll && current !== total;
    var shown = Math.min(current, total - 1) + 1;
    var label = current === total ? 'Podsumowanie — ' + total + ' z ' + total + ' pytań' : 'Pytanie ' + shown + ' z ' + total;
    if (progText) progText.textContent = showAll ? 'Wszystkie pytania (' + total + ')' : label;
    if (prog) {
      prog.setAttribute('aria-valuenow', String(shown));
      prog.setAttribute('aria-valuetext', showAll ? 'Wszystkie pytania' : label);
    }
    if (progBar) progBar.style.width = (total ? Math.round(100 * shown / total) : 100) + '%';
    if (btnPrev) btnPrev.disabled = current === 0;
    if (nextLabel) nextLabel.textContent = current >= total - 1 ? 'Przejdź do podsumowania' : 'Dalej';
    refreshStates();
  }

  /** Przejście do pytania (albo podsumowania przy i === total); fokus na nagłówek + ogłoszenie. */
  function go(i, focus) {
    current = Math.max(0, Math.min(total, i));
    hideConfirm();
    layout();
    var target;
    if (current === total) {
      target = document.getElementById('examSummaryH');
      if (navSay) navSay.textContent = 'Podsumowanie przed oddaniem.';
    } else {
      target = qs[current].querySelector('legend');
      if (navSay) navSay.textContent = 'Pytanie ' + (current + 1) + ' z ' + total + ', ' + info(current).words + '.';
      if (showAll) qs[current].scrollIntoView({ block: 'start' });
    }
    if (focus && target) target.focus();
  }

  document.querySelectorAll('#examNav [data-goto]').forEach(function (b) {
    b.addEventListener('click', function () {
      var g = b.getAttribute('data-goto');
      go(g === 'summary' ? total : parseInt(g, 10), true);
    });
  });
  if (btnPrev) btnPrev.addEventListener('click', function () { go(current - 1, true); });
  if (btnNext) btnNext.addEventListener('click', function () { go(current + 1, true); });
  if (backBtn) backBtn.addEventListener('click', function () { go(0, true); });
  if (showAllCb) {
    showAllCb.checked = showAll;
    showAllCb.addEventListener('change', function () {
      showAll = showAllCb.checked;
      store('set', 'equiExamShowAll', showAll ? '1' : '0');
      layout();
      if (navSay) navSay.textContent = showAll ? 'Widoczne są wszystkie pytania.' : 'Widoczne jest jedno pytanie: ' + (Math.min(current, total - 1) + 1) + ' z ' + total + '.';
    });
  }

  // Enter w polu tekstowym nie może oddać całego testu (domyślny przycisk formularza).
  form.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.key === 'Enter' && t && t.tagName === 'INPUT' && t.type !== 'submit' && t.type !== 'button') e.preventDefault();
  });

  // ── Potwierdzenie oddania ─────────────────────────────────────────────────
  var confirmBox  = document.getElementById('examConfirm');
  var confirmText = document.getElementById('examConfirmText');
  var submitRow   = document.getElementById('examSubmitRow');
  var submitted   = false;
  function hideConfirm() {
    if (confirmBox) confirmBox.hidden = true;
    if (submitRow) submitRow.hidden = false;
  }
  document.getElementById('examConfirmNo').addEventListener('click', function () {
    hideConfirm();
    document.getElementById('examSubmit').focus();
  });
  form.addEventListener('submit', function (e) {
    var by = e.submitter;
    // Potwierdzenie przy zwykłym „Oddaj test”; „Tak, oddaj” i „Oddaj mimo to” przechodzą od razu.
    if (!submitted && by && by.id === 'examSubmit' && confirmBox) {
      e.preventDefault();
      var none = [];
      for (var i = 0; i < total; i++) if (info(i).state === 'none') none.push(i + 1);
      confirmText.textContent = none.length
        ? 'Bez odpowiedzi ' + (none.length === 1 ? 'zostaje pytanie ' : 'zostają pytania: ') + none.join(', ') + '. Po oddaniu nie będzie już można zmienić odpowiedzi.'
        : 'Wszystkie pytania mają odpowiedź. Po oddaniu nie będzie już można zmienić odpowiedzi.';
      confirmBox.hidden = false;
      if (submitRow) submitRow.hidden = true;
      document.getElementById('examConfirmH').focus();
      return;
    }
    submitted = true;
  });

  // Po powrocie z serwera z komunikatem (np. powtórzone pozycje) pokaż pierwsze pytanie z ostrzeżeniem.
  var firstIssue = 0;
  qs.some(function (box, i) { if (box.querySelector('[id^="oi"].alert')) { firstIssue = i; return true; } return false; });
  current = firstIssue;
  layout();

  // ── Licznik czasu ─────────────────────────────────────────────────────────
  var timerEl = document.getElementById('examTimer');
  if (!timerEl) return;
  var left    = parseInt(timerEl.getAttribute('data-seconds'), 10) || 0;
  var say     = document.getElementById('timeAnnounce');
  var warnBox = document.getElementById('timeWarning');
  var warnTxt = document.getElementById('timeWarningText');
  // Progi ogłoszeń — czytnik ekranu nie może dostawać komunikatu co sekundę.
  var thresholds = [600, 300, 60, 30];

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
      while (thresholds.length && left <= thresholds[0]) t = thresholds.shift();   // wejście po kilku progach — jeden komunikat
      var msg = 'Pozostało ' + human(left) + ' do końca testu.';
      // Od 5 minut widoczne ostrzeżenie (role="alert" ogłasza się samo), wcześniej tylko komunikat polite.
      if (warnBox && warnTxt && t <= 300) { warnTxt.textContent = msg; warnBox.hidden = false; }
      else if (say) say.textContent = msg;
    }
    left--;
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
