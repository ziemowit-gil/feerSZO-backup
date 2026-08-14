<?php
/**
 * _wizard.php — Kreator zajęć (2 kroki: obecność → temat/notatki).
 * Wymaga: $dyd_wizard_sessions (array lekcji z dziś), $cur_course, $uid, dyd_token().
 * Dołączany z index.php tuż przed </body>.
 */
// Wizard renderuje się zawsze — może być otwarty przez wizOpenExt() z przeszłych lekcji
// nawet gdy dziś nie ma zaplanowanych zajęć.
?>
<style>
  .wiz-step { display:none; }
  .wiz-step.active { display:block; }
  .wiz-sess-card {
    border:2px solid var(--bs-border-color); border-radius:.6rem;
    padding:.75rem 1rem; cursor:pointer; transition:border-color .12s,background .12s;
    margin-bottom:.5rem;
  }
  .wiz-sess-card:hover { border-color:#2563eb; background:rgba(37,99,235,.05); }
  .wiz-sess-card.selected { border-color:#2563eb; background:rgba(37,99,235,.08); }
  .wiz-att-row {
    display:flex; align-items:center; gap:.75rem;
    padding:.5rem .25rem; border-bottom:1px solid var(--bs-border-color);
  }
  .wiz-att-row:last-child { border-bottom:none; }
  .wiz-att-cb { width:1.3rem; height:1.3rem; cursor:pointer; flex-shrink:0; }
  .wiz-att-cb:checked { accent-color:#16a34a; }
  .wiz-progress { display:flex; gap:.4rem; margin-bottom:1.5rem; }
  .wiz-prog-step {
    flex:1; height:4px; border-radius:2px; background:var(--bs-border-color);
    transition:background .2s;
  }
  .wiz-prog-step.done { background:#2563eb; }
  .wiz-prog-step.active { background:#93c5fd; }
  .wiz-att-row-present { background:rgba(22,163,74,.08); border-radius:.3rem; }
</style>

<div class="modal fade" id="dydWizardModal" tabindex="-1" aria-labelledby="dydWizardTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="dyd-wizard-form">
        <input type="hidden" name="_token"  value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"     value="wizard_save">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <input type="hidden" name="session_id" id="wiz_sid" value="">

        <div class="modal-header pb-2">
          <div class="flex-grow-1">
            <h5 class="modal-title mb-0" id="dydWizardTitle">
              <i class="bi bi-magic me-2 text-primary" aria-hidden="true"></i>Kreator zajęć
            </h5>
            <div class="text-body-secondary small mt-1" id="wiz_sess_label"></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>

        <div class="modal-body">
          <!-- Pasek postępu -->
          <div class="wiz-progress" aria-hidden="true">
            <div class="wiz-prog-step active" id="wiz_pr1"></div>
            <div class="wiz-prog-step"        id="wiz_pr2"></div>
          </div>

          <?php if (count($dyd_wizard_sessions) > 1): ?>
          <!-- Krok 0: wybór lekcji (tylko gdy >1) -->
          <div class="wiz-step active" id="wiz_step0">
            <div class="fw-semibold mb-2"><i class="bi bi-calendar-event me-1 text-primary"></i>Krok 1 z 2 — Wybierz lekcję</div>
            <p class="text-body-secondary small mb-3">Dzisiaj masz kilka zaplanowanych lekcji. Wybierz, którą chcesz teraz uzupełnić.</p>
            <?php foreach ($dyd_wizard_sessions as $_ws):
              $tf = substr((string)($_ws['time_from']??''),0,5);
              $tt = substr((string)($_ws['time_to']??''),0,5);
            ?>
            <div class="wiz-sess-card" data-sid="<?= (int)$_ws['id'] ?>"
                 data-label="<?= h(($tf ? $tf.'–'.$tt.' · ' : '') . $_ws['course_name']) ?>"
                 onclick="wizSelectSess(this)">
              <div class="fw-semibold"><?= h($_ws['course_name']) ?></div>
              <?php if ($tf): ?>
              <div class="small text-body-secondary"><?= h($tf) ?>–<?= h($tt) ?></div>
              <?php endif; ?>
              <?php if (!empty($_ws['topic'])): ?>
              <div class="small text-body-secondary"><?= h($_ws['topic']) ?></div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="button" class="btn btn-primary mt-2" onclick="wizGoStep(1)" id="wiz_next0" disabled>
              Dalej <i class="bi bi-arrow-right ms-1"></i>
            </button>
          </div>
          <?php elseif (count($dyd_wizard_sessions) === 1): $_ws0 = $dyd_wizard_sessions[0]; ?>
          <input type="hidden" id="wiz_sid_init" value="<?= (int)$_ws0['id'] ?>">
          <?php endif; ?>

          <!-- Krok 1: obecność -->
          <div class="wiz-step <?= count($dyd_wizard_sessions) === 1 ? 'active' : '' ?>" id="wiz_step1">
            <div class="fw-semibold mb-1"><i class="bi bi-people me-1 text-primary"></i>Krok <?= count($dyd_wizard_sessions)>1?'2':'1' ?> z 2 — Obecność</div>
            <p class="text-body-secondary small mb-3">
              <i class="bi bi-info-circle me-1"></i><strong>Zaznacz kursantów, którzy uczestniczyli w zajęciach.</strong>
              Niezaznaczeni zostaną odnotowani jako nieobecni.
            </p>

            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small fw-semibold">
                <i class="bi bi-check2-circle text-success me-1"></i>Obecni: <span id="wiz_cnt">0</span> / <span id="wiz_tot">0</span>
              </span>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-success btn-sm py-0" onclick="wizAllPresent()">
                  <i class="bi bi-check-all me-1"></i>Wszyscy
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0" onclick="wizNone()">
                  <i class="bi bi-square me-1"></i>Wyczyść
                </button>
              </div>
            </div>
            <div style="height:4px;border-radius:2px;background:rgba(100,116,139,.15);margin-bottom:.75rem">
              <div id="wiz_att_bar" style="height:4px;border-radius:2px;background:#16a34a;width:0%;transition:width .18s"></div>
            </div>

            <div id="wiz_att_list" class="mb-2">
              <div class="text-body-secondary small text-center py-3">
                <i class="bi bi-hourglass-split me-1"></i>Ładowanie listy kursantów…
              </div>
            </div>

            <div class="d-flex gap-2 mt-3">
              <?php if (count($dyd_wizard_sessions) > 1): ?>
              <button type="button" class="btn btn-outline-secondary" onclick="wizGoStep(0)">
                <i class="bi bi-arrow-left me-1"></i>Wstecz
              </button>
              <?php endif; ?>
              <button type="button" class="btn btn-primary ms-auto" onclick="wizGoStep(2)">
                Dalej <i class="bi bi-arrow-right ms-1"></i>
              </button>
            </div>
          </div>

          <!-- Krok 2: temat i notatki -->
          <div class="wiz-step" id="wiz_step2">
            <div class="fw-semibold mb-1"><i class="bi bi-journal-text me-1 text-primary"></i>Krok <?= count($dyd_wizard_sessions)>1?'3':'2' ?> z <?= count($dyd_wizard_sessions)>1?'3':'2' ?> — Temat i notatki</div>
            <p class="text-body-secondary small mb-3">Opcjonalnie wpisz temat lekcji i swoje notatki — zostaną zapisane razem z obecnością.</p>

            <div class="mb-3">
              <label for="wiz_topic" class="form-label fw-semibold">Temat lekcji</label>
              <input type="text" class="form-control" id="wiz_topic" name="topic"
                     placeholder="np. Wprowadzenie do zmiennych, Ćwiczenia z czytania…" maxlength="300">
            </div>
            <div class="mb-3">
              <label for="wiz_notes" class="form-label fw-semibold">Notatki prowadzącego <span class="text-body-secondary fw-normal">(opcjonalne)</span></label>
              <textarea class="form-control" id="wiz_notes" name="notes" rows="3"
                        placeholder="np. Uwagi o przebiegu zajęć, materiały do uzupełnienia…"></textarea>
            </div>

            <div class="d-flex gap-2 mt-1">
              <button type="button" class="btn btn-outline-secondary" onclick="wizGoStep(1)">
                <i class="bi bi-arrow-left me-1"></i>Wstecz
              </button>
              <button type="submit" class="btn btn-success ms-auto">
                <i class="bi bi-check2-square me-1"></i>Zapisz zajęcia
              </button>
            </div>
          </div>
        </div><!-- /modal-body -->
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  // Dane kursantów per sesja (z PHP)
  var SESSIONS = <?= json_encode(array_map(function($s) {
      $att = db_all(
          "SELECT a.client_id AS id, COALESCE(cl.name, '') AS name,
                  COALESCE(a.attended,0) AS attended,
                  COALESCE(a.cancelled,0) AS cancelled,
                  COALESCE(a.cancel_pending,0) AS pending,
                  COALESCE(a.no_show,0) AS no_show
           FROM k30_ti_attendance a
           LEFT JOIN k30_clients cl ON cl.id=a.client_id
           WHERE a.session_id=?
           ORDER BY cl.name",
          [(int)$s['id']]
      );
      return [
          'id'      => (int)$s['id'],
          'label'   => ($s['time_from'] ? substr($s['time_from'],0,5).'–'.substr($s['time_to']??'',0,5).' · ' : '') . $s['course_name'],
          'topic'   => $s['topic'] ?? '',
          'attendees' => $att,
      ];
  }, $dyd_wizard_sessions)) ?>;

  var curSid   = <?= count($dyd_wizard_sessions) === 1 ? (int)$dyd_wizard_sessions[0]['id'] : 0 ?>;
  var curStep  = <?= count($dyd_wizard_sessions) > 1 ? 0 : 1 ?>;
  var numSteps = <?= count($dyd_wizard_sessions) > 1 ? 3 : 2 ?>;

  // Inicjalizuj sid gdy pojedyncza sesja
  <?php if (count($dyd_wizard_sessions) === 1): ?>
  document.getElementById('wiz_sid').value = <?= (int)$dyd_wizard_sessions[0]['id'] ?>;
  <?php endif; ?>

  function sessData(sid) {
    return SESSIONS.find(function(s){ return s.id === sid; }) || null;
  }

  window.wizSelectSess = function(card) {
    document.querySelectorAll('.wiz-sess-card').forEach(function(c){ c.classList.remove('selected'); });
    card.classList.add('selected');
    curSid = parseInt(card.getAttribute('data-sid'), 10);
    document.getElementById('wiz_sid').value = curSid;
    document.getElementById('wiz_next0').disabled = false;
  };

  function renderAttList(sid) {
    var s = sessData(sid); if (!s) return;
    var list = document.getElementById('wiz_att_list'); if (!list) return;
    var cnt  = document.getElementById('wiz_cnt');
    var tot  = document.getElementById('wiz_tot');
    var bar  = document.getElementById('wiz_att_bar');

    // Pre-fill topic if existing
    var topicInp = document.getElementById('wiz_topic');
    if (topicInp && !topicInp.value && s.topic) topicInp.value = s.topic;

    var active = s.attendees.filter(function(a){ return !a.cancelled && !a.no_show; });
    if (!active.length) {
      list.innerHTML = '<div class="text-body-secondary small text-center py-3"><i class="bi bi-person-x me-1"></i>Brak aktywnych kursantów.</div>';
      if (cnt) cnt.textContent = '0'; if (tot) tot.textContent = '0';
      return;
    }
    if (tot) tot.textContent = active.length;

    var html = '';
    active.forEach(function(a) {
      var checked = a.attended ? 'checked' : '';
      html += '<div class="wiz-att-row' + (a.attended ? ' wiz-att-row-present' : '') + '" id="wiz_row_' + a.id + '">' +
              '<input class="wiz-att-cb form-check-input" type="checkbox" name="attended[]" value="' + a.id + '" id="wiz_cb_' + a.id + '" ' + checked + ' onchange="wizAttChange()">' +
              '<label for="wiz_cb_' + a.id + '" style="cursor:pointer;flex-grow:1;margin-bottom:0">' + escHtml(a.name) + '</label>' +
              (a.attended ? '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle small"><i class="bi bi-check2-circle me-1"></i>obecny</span>' : '') +
              '</div>';
    });
    list.innerHTML = html;
    wizAttChange();
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  window.wizAttChange = function() {
    var boxes = document.querySelectorAll('#wiz_att_list input[name="attended[]"]');
    var cnt   = 0;
    boxes.forEach(function(cb) {
      var row = document.getElementById('wiz_row_' + cb.value);
      if (cb.checked) { cnt++; if (row) row.classList.add('wiz-att-row-present'); }
      else { if (row) row.classList.remove('wiz-att-row-present'); }
    });
    var cntEl = document.getElementById('wiz_cnt'); if (cntEl) cntEl.textContent = cnt;
    var bar   = document.getElementById('wiz_att_bar');
    var total = boxes.length;
    if (bar) bar.style.width = (total > 0 ? Math.round(cnt/total*100) : 0) + '%';
  };

  window.wizAllPresent = function() {
    document.querySelectorAll('#wiz_att_list input[name="attended[]"]').forEach(function(cb){ cb.checked = true; });
    wizAttChange();
  };

  window.wizNone = function() {
    document.querySelectorAll('#wiz_att_list input[name="attended[]"]').forEach(function(cb){ cb.checked = false; });
    wizAttChange();
  };

  window.wizGoStep = function(step) {
    // Schowaj aktualny krok
    var from = document.getElementById('wiz_step' + curStep);
    if (from) from.classList.remove('active');

    // Pokaż nowy
    curStep = step;
    var to = document.getElementById('wiz_step' + curStep);
    if (to) to.classList.add('active');

    // Pasek postępu
    [0,1,2].forEach(function(i) {
      var el = document.getElementById('wiz_pr' + (i+1)); if (!el) return;
      el.className = 'wiz-prog-step' + (i < curStep ? ' done' : (i === curStep ? ' active' : ''));
    });

    // Przy przejściu na krok 1 — załaduj listę kursantów
    if (step === 1 && curSid) {
      renderAttList(curSid);
      var lbl = document.getElementById('wiz_sess_label');
      var s = sessData(curSid);
      if (lbl && s) lbl.textContent = s.label;
    }
  };

  // Otwarcie modalu z zewnątrz z danymi z listy lekcji
  window.wizOpenExt = function(sid) {
    var ext = (window.DYD_EXT_SESSIONS || {})[String(sid)];
    if (!ext) return;
    if (!SESSIONS.find(function(s){ return s.id === sid; })) {
      SESSIONS.push({ id: sid, label: ext.label, topic: ext.topic, attendees: ext.attendees });
    }
    wizOpen(sid);
  };

  // Otwarcie modalu z zewnątrz: wizOpen(sid)
  window.wizOpen = function(sid) {
    if (sid) {
      curSid = sid;
      document.getElementById('wiz_sid').value = sid;
      curStep = -1; // wymusi przejście
      wizGoStep(1);
    } else {
      curStep = -1;
      wizGoStep(<?= count($dyd_wizard_sessions) > 1 ? 0 : 1 ?>);
    }
    new bootstrap.Modal(document.getElementById('dydWizardModal')).show();
  };

  // Auto-otwórz przy ?wizard=1
  document.addEventListener('DOMContentLoaded', function(){
    if ((new URLSearchParams(window.location.search)).get('wizard') === '1') {
      wizOpen(<?= count($dyd_wizard_sessions) === 1 ? (int)$dyd_wizard_sessions[0]['id'] : 'null' ?>);
    }
  });
})();
</script>
