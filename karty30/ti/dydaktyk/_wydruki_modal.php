<?php
/**
 * karty30/ti/dydaktyk/_wydruki_modal.php — jedno okno „Wydruki” dla zakładki Lekcje.
 * Zbiera wszystkie opcje wydruków grupy (plan, frekwencja, dziennik, raporty miesięczne, wersje robocze,
 * mój plan) — każda z wyborem formatu. Wymaga: $cur_course, $uid, $course, $_n_drafts.
 */
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerExport.php';
$_pm_c   = (int)$cur_course;
$_pm_staff = dyd_is_staff();
$_pm_btn = fn(string $href, string $label, string $icon, string $cls = 'outline-secondary', bool $blank = false, string $extra = ''): string =>
    '<a class="btn btn-sm btn-' . $cls . '" href="' . h($href) . '"' . ($blank ? ' target="_blank" rel="noopener"' : '') . ' ' . $extra . '><i class="bi bi-' . $icon . ' me-1" aria-hidden="true"></i>' . h($label) . '</a>';
?>
<div class="modal fade" id="dydPrintModal" tabindex="-1" aria-labelledby="dydPrintTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered"><div class="modal-content" id="dydPrintRoot" data-course="<?= $_pm_c ?>">
    <div class="modal-header">
      <h2 class="modal-title h5" id="dydPrintTitle"><i class="bi bi-printer me-2" aria-hidden="true"></i>Wydruki — <?= h((string)($course['name'] ?? '')) ?></h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <section class="border rounded p-3 mb-3" aria-labelledby="pm-plan">
            <h3 class="h6 text-uppercase text-body-secondary small fw-bold mb-2" id="pm-plan"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Plan zajęć (uczeń / rodzic)</h3>
            <p class="small text-body-secondary mb-2">Siatka tygodniowa i lista wszystkich lekcji, w tym zaplanowanych.</p>
            <div class="d-flex flex-wrap gap-1">
              <?= $_pm_btn('harmonogram_pdf.php?course_id=' . $_pm_c, 'PDF', 'file-earmark-pdf', 'primary', true) ?>
              <?= $_pm_btn('harmonogram_docx.php?course_id=' . $_pm_c, 'Word', 'file-earmark-word') ?>
              <?= $_pm_btn('harmonogram_xlsx.php?course_id=' . $_pm_c, 'Excel', 'file-earmark-spreadsheet') ?>
              <?= $_pm_btn('plan_librus.php?course_id=' . $_pm_c, 'Siatka', 'grid-3x3', 'outline-secondary', true) ?>
              <?= $_pm_btn('plan_librus_pdf.php?course_id=' . $_pm_c, 'Siatka PDF', 'file-earmark-pdf', 'outline-secondary', true) ?>
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2 align-items-center"><span class="small text-body-secondary me-1">Lista lekcji tekstem (bez tabeli, czytnik ekranu):</span>
              <?= $_pm_btn('harmonogramTekst.php?course_id=' . $_pm_c . '&fmt=html', 'Tekst', 'file-text', 'outline-secondary', true) ?>
              <?= $_pm_btn('harmonogramTekst.php?course_id=' . $_pm_c . '&fmt=txt', 'TXT', 'file-earmark-text') ?>
            </div>
          </section>
          <section class="border rounded p-3 mb-3" aria-labelledby="pm-fr">
            <h3 class="h6 text-uppercase text-body-secondary small fw-bold mb-2" id="pm-fr"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Frekwencja i dokumentacja</h3>
            <div class="d-flex flex-wrap gap-1 mb-2">
              <?= $_pm_btn('attendance_pdf.php?course_id=' . $_pm_c, 'Lista obecności (cały kurs)', 'table', 'primary') ?>
              <?php if ($_pm_staff): ?>
              <?= $_pm_btn('dziennik_pdf.php?course_id=' . $_pm_c, 'Dziennik zajęć PDF', 'journal-bookmark', 'outline-secondary', true) ?>
              <?= $_pm_btn('dziennik_pdf.php?course_id=' . $_pm_c . '&dane=1', 'Dziennik z danymi osobowymi', 'journal-bookmark', 'outline-secondary', true) ?>
              <?php endif; ?>
            </div>
            <label class="form-label small mb-1" for="pmMonth">Raport miesięczny</label>
            <div class="d-flex flex-wrap gap-1 align-items-center">
              <input type="month" class="form-control form-control-sm" style="max-width:10rem" id="pmMonth" value="<?= date('Y-m') ?>">
              <a class="btn btn-sm btn-outline-secondary pm-month" data-tpl="attendance_monthly.php?course_id=<?= $_pm_c ?>&month={m}" target="_blank" rel="noopener"><i class="bi bi-calendar-month me-1" aria-hidden="true"></i>Raport</a>
              <a class="btn btn-sm btn-outline-secondary pm-month" data-tpl="attendance_csv.php?course_id=<?= $_pm_c ?>&month={m}"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
              <a class="btn btn-sm btn-outline-secondary pm-month" data-tpl="self_work.php?month={m}" target="_blank" rel="noopener"><i class="bi bi-person-workspace me-1" aria-hidden="true"></i>Praca własna</a>
            </div>
          </section>
        </div>
        <div class="col-lg-6">
          <section class="border rounded p-3 mb-3" aria-labelledby="pm-dr">
            <h3 class="h6 text-uppercase text-body-secondary small fw-bold mb-2" id="pm-dr"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Wersje robocze lekcji <span class="badge text-bg-secondary ms-1"><?= (int)$_n_drafts ?></span></h3>
            <?php if ((int)$_n_drafts > 0): ?>
            <p class="small text-body-secondary mb-2">Z typem kursu i formą lekcji (stacjonarnie / online). Wybierz kolumny:</p>
            <div class="row row-cols-2 g-0 mb-2">
              <?php foreach (SC_DRAFT_COLS as $_ck => $_cl): ?>
              <div class="col"><div class="form-check"><input class="form-check-input pm-dcol" type="checkbox" id="pmcol_<?= h($_ck) ?>" value="<?= h($_ck) ?>" checked><label class="form-check-label small" for="pmcol_<?= h($_ck) ?>"><?= h($_cl) ?></label></div></div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap gap-1 align-items-center">
              <a class="btn btn-sm btn-primary pm-draft" data-fmt="pdf" href="roboczeExport.php?course_id=<?= $_pm_c ?>&fmt=pdf" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
              <a class="btn btn-sm btn-outline-secondary pm-draft" data-fmt="docx" href="roboczeExport.php?course_id=<?= $_pm_c ?>&fmt=docx"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Word</a>
              <a class="btn btn-sm btn-outline-secondary pm-draft" data-fmt="xlsx" href="roboczeExport.php?course_id=<?= $_pm_c ?>&fmt=xlsx"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel</a>
              <a class="btn btn-sm btn-outline-secondary pm-draft" data-fmt="html" href="roboczeExport.php?course_id=<?= $_pm_c ?>&fmt=html" target="_blank" rel="noopener" title="Wersja tekstowa bez tabeli — dla czytników ekranu"><i class="bi bi-file-text me-1" aria-hidden="true"></i>Tekst</a>
              <a class="btn btn-sm btn-outline-secondary pm-draft" data-fmt="txt" href="roboczeExport.php?course_id=<?= $_pm_c ?>&fmt=txt" title="Plik TXT bez tabeli"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>TXT</a>
              <button type="button" class="btn btn-sm btn-link" id="pmColAll">wszystkie</button><button type="button" class="btn btn-sm btn-link" id="pmColNone">żadne</button>
            </div>
            <?php else: ?><p class="small text-body-secondary mb-0">Ta grupa nie ma wersji roboczych.</p><?php endif; ?>
          </section>
          <section class="border rounded p-3 mb-3" aria-labelledby="pm-my">
            <h3 class="h6 text-uppercase text-body-secondary small fw-bold mb-2" id="pm-my"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Plan prowadzącego</h3>
            <div class="d-flex flex-wrap gap-1 align-items-center mb-2">
              <label class="small" for="pmWeeks">Zakres:</label>
              <select id="pmWeeks" class="form-select form-select-sm" style="width:auto"><option value="4">4 tyg.</option><option value="8" selected>8 tyg.</option><option value="12">12 tyg.</option><option value="26">26 tyg.</option><option value="0">ogólny (bez końca)</option></select>
            </div>
            <?php
              $_pm_people = [[$uid, 'Mój plan']];
              if ($_pm_staff && !empty($course['instructor_id']) && (int)$course['instructor_id'] !== $uid) $_pm_people[] = [(int)$course['instructor_id'], 'Plan — ' . (string)($course['instructor_name'] ?? 'prowadzący')];
              foreach ($_pm_people as [$_pid, $_plab]): ?>
            <div class="d-flex flex-wrap gap-1 align-items-center mb-1"><span class="small me-1"><?= h($_plab) ?>:</span>
              <a class="btn btn-sm btn-outline-primary pm-weeks" data-tpl="plan_pdf.php?instructor_id=<?= (int)$_pid ?>&weeks={w}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
              <a class="btn btn-sm btn-outline-secondary pm-weeks" data-tpl="plan_docx.php?instructor_id=<?= (int)$_pid ?>&weeks={w}"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Word</a>
              <a class="btn btn-sm btn-outline-secondary pm-weeks" data-tpl="plan_print.php?instructor_id=<?= (int)$_pid ?>&weeks={w}" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Do druku</a>
            </div>
            <?php endforeach; ?>
          </section>
        </div>
      </div>
      <p class="small text-body-secondary mb-0">Więcej zestawień (rachunki, protokoły, wydruki zbiorcze): zakładka <a href="index.php?course=<?= $_pm_c ?>&tab=wydruki">Wydruki</a>.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button></div>
  </div></div>
</div>
<script>
(function () {
  var root = document.getElementById('dydPrintRoot'); if (!root) return;
  var course = root.dataset.course, key = 'dydDraftCols';
  function month() { var m = document.getElementById('pmMonth').value; return /^\d{4}-\d{2}$/.test(m) ? m : ''; }
  function syncMonth() { root.querySelectorAll('.pm-month').forEach(function (a) { a.href = a.dataset.tpl.replace('{m}', month()); }); }
  function syncWeeks() { var w = document.getElementById('pmWeeks').value; root.querySelectorAll('.pm-weeks').forEach(function (a) { a.href = a.dataset.tpl.replace('{w}', w); }); }
  var boxes = root.querySelectorAll('.pm-dcol');
  try { var saved = JSON.parse(localStorage.getItem(key) || 'null'); if (Array.isArray(saved)) boxes.forEach(function (b) { b.checked = saved.indexOf(b.value) !== -1; }); } catch (e) {}
  function syncDraft() {
    var on = []; boxes.forEach(function (b) { if (b.checked) on.push(b.value); });
    try { localStorage.setItem(key, JSON.stringify(on)); } catch (e) {}
    root.querySelectorAll('.pm-draft').forEach(function (a) {
      a.href = 'roboczeExport.php?course_id=' + course + '&fmt=' + a.dataset.fmt + (on.length && on.length < boxes.length ? '&cols=' + encodeURIComponent(on.join(',')) : '');
    });
  }
  document.getElementById('pmMonth').addEventListener('change', syncMonth);
  document.getElementById('pmWeeks').addEventListener('change', syncWeeks);
  boxes.forEach(function (b) { b.addEventListener('change', syncDraft); });
  var all = document.getElementById('pmColAll'), none = document.getElementById('pmColNone');
  if (all) all.onclick = function () { boxes.forEach(function (b) { b.checked = true; }); syncDraft(); };
  if (none) none.onclick = function () { boxes.forEach(function (b) { b.checked = false; }); syncDraft(); };
  syncMonth(); syncWeeks(); syncDraft();
})();
</script>
