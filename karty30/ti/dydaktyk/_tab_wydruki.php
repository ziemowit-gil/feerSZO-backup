<?php
/**
 * _tab_wydruki.php — „Wydruki” prowadzącego (Mój panel → Wydruki, tab=wydruki).
 *
 * Zebrane w jednym miejscu wszystko, co prowadzący może wydrukować sam —
 * bez przechodzenia do Wydruków kierownika (wydruki.php, tylko staff):
 *   • mój plan zajęć (lista PDF/DOCX/druk, siatka PDF, kalendarz .ics),
 *   • dla wybranej grupy: plan dla ucznia/rodzica (PDF/DOCX/XLSX), siatka,
 *     lista obecności, raport miesięczny frekwencji (PDF, CSV), protokoły.
 * Każdy link prowadzi do istniejącego skryptu, który sam pilnuje dostępu
 * (dyd_owns_course / własny plan) — ta zakładka niczego nie omija.
 *
 * Wymaga z index.php: $courses, $courses_archived, $cur_course, $course, $me.
 */
$_wd_courses = array_merge($courses, $courses_archived ?? []);
$_wd_cid     = (int)($_GET['wd_course'] ?? $cur_course);
$_wd_ok      = false;
foreach ($_wd_courses as $_c) if ((int)$_c['id'] === $_wd_cid) { $_wd_ok = true; $_wd_c = $_c; break; }
if (!$_wd_ok) { $_wd_cid = 0; $_wd_c = null; }
$_wd_month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['wd_month'] ?? '')) ? (string)$_GET['wd_month'] : date('Y-m');
$_wd_weeks = in_array((int)($_GET['wd_weeks'] ?? 8), [0, 4, 8, 12, 26], true) ? (int)($_GET['wd_weeks'] ?? 8) : 8;
$_q  = fn(array $p) => http_build_query($p);
$_lnk = function (string $href, string $label, string $icon, string $kind = 'outline-secondary', bool $blank = true): string {
    return '<a class="btn btn-sm btn-' . $kind . '" href="' . h($href) . '"' . ($blank ? ' target="_blank" rel="noopener"' : '') . '>'
         . '<i class="bi bi-' . $icon . '" aria-hidden="true"></i>' . h($label) . '</a>';
};
?>
<div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-printer me-2" aria-hidden="true"></i>Wydruki</h1>
  <span class="small text-body-secondary">Wszystko, co możesz wydrukować lub pobrać dla siebie i swoich grup.</span>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <section class="card mb-0 h-100" aria-labelledby="wd-plan">
      <div class="card-header" id="wd-plan"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Mój plan zajęć</div>
      <div class="card-body">
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap mb-2">
          <input type="hidden" name="tab" value="wydruki">
          <?php if ($_wd_cid): ?><input type="hidden" name="wd_course" value="<?= $_wd_cid ?>"><?php endif; ?>
          <div>
            <label class="form-label small mb-0" for="wd-weeks">Zakres</label>
            <select id="wd-weeks" name="wd_weeks" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php foreach ([4 => 'najbliższe 4 tygodnie', 8 => 'najbliższe 8 tygodni', 12 => 'najbliższe 12 tygodni', 26 => 'najbliższe 26 tygodni', 0 => 'ogólny — od dziś, bez końca'] as $_w => $_l): ?>
              <option value="<?= $_w ?>"<?= $_w === $_wd_weeks ? ' selected' : '' ?>><?= h($_l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <div class="small text-body-secondary mb-1">Lista terminów (dzień, godzina, grupa, kursanci):</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?= $_lnk('plan_pdf.php?' . $_q(['weeks' => $_wd_weeks]), 'PDF', 'file-earmark-pdf', 'primary') ?>
          <?= $_lnk('plan_docx.php?' . $_q(['weeks' => $_wd_weeks]), 'DOCX', 'file-earmark-word') ?>
          <?= $_lnk('plan_print.php?' . $_q(['weeks' => $_wd_weeks]), 'Do druku', 'printer') ?>
        </div>
        <div class="small text-body-secondary mb-1">Siatka tygodniowa (jak w e-dzienniku):</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?= $_lnk('plan_librus_instructor.php?' . $_q(['weeks' => $_wd_weeks ?: 8]), 'Pokaż', 'grid-3x3') ?>
          <?= $_lnk('plan_librus_instructor_pdf.php?' . $_q(['weeks' => $_wd_weeks ?: 8]), 'PDF', 'file-earmark-pdf') ?>
        </div>
        <div class="small text-body-secondary mb-1">Do kalendarza w telefonie lub Outlooku:</div>
        <?= $_lnk('ical_export.php', 'Plik kalendarza (.ics)', 'calendar-plus', 'outline-secondary', false) ?>
      </div>
    </section>
  </div>

  <div class="col-lg-6">
    <section class="card mb-0 h-100" aria-labelledby="wd-grupa">
      <div class="card-header" id="wd-grupa"><i class="bi bi-people me-1" aria-hidden="true"></i>Wydruki grupy</div>
      <div class="card-body">
        <?php if (!$_wd_courses): ?>
        <p class="small text-body-secondary mb-0">Nie masz przypisanych grup.</p>
        <?php else: ?>
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap mb-2">
          <input type="hidden" name="tab" value="wydruki">
          <input type="hidden" name="wd_weeks" value="<?= $_wd_weeks ?>">
          <div class="flex-grow-1">
            <label class="form-label small mb-0" for="wd-course">Grupa</label>
            <select id="wd-course" name="wd_course" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php if (!$_wd_cid): ?><option value="">— wybierz grupę —</option><?php endif; ?>
              <?php foreach ($_wd_courses as $_c): $_arch = ($_c['status'] ?? '') === 'archived'; ?>
              <option value="<?= (int)$_c['id'] ?>"<?= (int)$_c['id'] === $_wd_cid ? ' selected' : '' ?>><?= h((string)$_c['name']) ?><?= $_arch ? ' (archiwum)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label small mb-0" for="wd-month">Miesiąc</label>
            <input type="month" id="wd-month" name="wd_month" value="<?= h($_wd_month) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
          </div>
          <noscript><button class="btn btn-sm btn-outline-secondary">Pokaż</button></noscript>
        </form>

        <?php if ($_wd_cid): ?>
        <div class="small text-body-secondary mb-1">Plan zajęć dla ucznia / rodzica:</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?= $_lnk('harmonogram_pdf.php?' . $_q(['course_id' => $_wd_cid]), 'PDF', 'file-earmark-pdf', 'primary') ?>
          <?= $_lnk('harmonogram_docx.php?' . $_q(['course_id' => $_wd_cid]), 'DOCX', 'file-earmark-word', 'outline-secondary', false) ?>
          <?= $_lnk('harmonogram_xlsx.php?' . $_q(['course_id' => $_wd_cid]), 'XLSX', 'file-earmark-spreadsheet', 'outline-secondary', false) ?>
          <?= $_lnk('plan_librus.php?' . $_q(['course_id' => $_wd_cid, 'weeks' => $_wd_weeks ?: 8]), 'Siatka', 'grid-3x3') ?>
          <?= $_lnk('plan_librus_pdf.php?' . $_q(['course_id' => $_wd_cid, 'weeks' => $_wd_weeks ?: 8]), 'Siatka PDF', 'file-earmark-pdf') ?>
        </div>
        <div class="small text-body-secondary mb-1">Obecności:</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?= $_lnk('attendance_pdf.php?' . $_q(['course_id' => $_wd_cid]), 'Lista obecności (PDF)', 'clipboard-check', 'primary') ?>
          <?= $_lnk('attendance_monthly.php?' . $_q(['course_id' => $_wd_cid, 'month' => $_wd_month]), 'Raport za ' . $_wd_month, 'calendar-month') ?>
          <?= $_lnk('attendance_csv.php?' . $_q(['course_id' => $_wd_cid, 'month' => $_wd_month]), 'CSV za ' . $_wd_month, 'filetype-csv', 'outline-secondary', false) ?>
        </div>
        <div class="small text-body-secondary mb-1">Dokumenty zajęć:</div>
        <div class="d-flex flex-wrap gap-1">
          <?= $_lnk('protokoly_moje.php', 'Protokoły (PDF w kreatorze)', 'journal-check', 'outline-secondary', false) ?>
          <?= $_lnk('index.php?' . $_q(['course' => $_wd_cid, 'tab' => 'lekcje']), 'Karta lekcji — z listy zajęć', 'card-text', 'outline-secondary', false) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
