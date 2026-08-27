<?php
/**
 * _tab_planner.php — Tab SZO Planner w panelu dydaktyka.
 *
 * Zmienne dostępne z index.php: $uid, $cur_course, $courses, $my_avail, dyd_token()
 */

ti_planner_migrate();
$pl_schedules = szo_schedules_list($uid);
$pl_blocks    = szo_blocks_list($uid);

$sel_sid      = (int)($_GET['sid'] ?? ($pl_schedules[0]['id'] ?? 0));
$sel_schedule = $sel_sid ? szo_schedule_get($sel_sid, $uid) : null;

// Dostępność prowadzącego z modułu TI
$pl_avail      = $my_avail ?? ti_instructor_availability($uid);
$avail_by_dow  = [];
foreach ($pl_avail as $a) { $avail_by_dow[(int)$a['day_of_week']][] = $a; }

// Statystyki obciążenia dla wybranego kursu
$pl_course_id   = (int)($sel_schedule['course_id'] ?? 0);
$pl_load_stats  = null;
if ($pl_course_id) {
    $pl_load_stats = db_one(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_min),0) AS total_min
         FROM k30_ti_sessions
         WHERE course_id=? AND status IN ('planned','held')
           AND lesson_date >= date('now') AND lesson_date <= date('now','+28 days')",
        [$pl_course_id]
    );
}

// Wzorzec zajęć wszystkich aktywnych kursów (sekcja Planowanie)
$pl_courses_plan = db_all(
    "SELECT c.id, c.name, u.name AS instructor_name,
            COUNT(s.id) AS sessions_4w,
            COALESCE(GROUP_CONCAT(s.duration_min ORDER BY s.duration_min), '') AS dur_list
     FROM k30_ti_courses c
     LEFT JOIN k30_ti_sessions s ON s.course_id=c.id
            AND s.status IN ('planned','held')
            AND s.lesson_date >= date('now')
            AND s.lesson_date <= date('now','+28 days')
     LEFT JOIN users u ON u.id=c.instructor_id
     WHERE c.status != 'cancelled' AND c.is_active = 1
     GROUP BY c.id
     ORDER BY c.name",
    []
);

// Helper: z listy minut → wzorzec NxXh, MxYh
function pl_pattern(string $dur_list): string {
    if ($dur_list === '') return '—';
    $mins = array_map('intval', explode(',', $dur_list));
    $counts = array_count_values($mins);
    arsort($counts);
    $parts = [];
    foreach ($counts as $min => $cnt) {
        $h = $min >= 60 ? round($min / 60, 1) . 'h' : $min . 'min';
        $parts[] = ($cnt > 1 ? $cnt . '×' : '') . $h;
    }
    return implode(' + ', $parts);
}

// Wspólne okno dostępności → domyślne daily_settings dla JS
$pl_start_min = 480; $pl_end_min = 1020;
if ($pl_avail) {
    $starts = array_map(fn($a) => ti_hm2min($a['time_from']), $pl_avail);
    $ends   = array_map(fn($a) => ti_hm2min($a['time_to']),   $pl_avail);
    $pl_start_min = min($starts) ?: 480;
    $pl_end_min   = max($ends)   ?: 1020;
}

// JSON dla JS
$js_blocks   = json_encode(array_values($pl_blocks), JSON_UNESCAPED_UNICODE);
$js_days     = json_encode($sel_schedule['days'] ?? [], JSON_UNESCAPED_UNICODE);
$js_sid      = $sel_sid;
$js_token    = h(dyd_token());
$ajax_url    = h(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/planner_ajax.php');
?>
<style>
/* ── SZO Planner ─────────────────────────────────────── */
.szo-layout{display:grid;grid-template-columns:220px 1fr;gap:0;border:1px solid var(--bs-border-color);border-radius:8px;overflow:hidden;background:var(--bs-body-bg)}
.szo-lib{border-right:1px solid var(--bs-border-color);padding:10px;overflow-y:auto;max-height:560px;display:flex;flex-direction:column;gap:5px;background:var(--bs-tertiary-bg)}
.szo-lib-header{font-size:.72rem;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:var(--bs-secondary-color);margin-bottom:4px;padding:0 2px}
.szo-days{display:grid;grid-template-columns:repeat(var(--szo-ncols,3),1fr)}
.szo-day{border-right:1px solid var(--bs-border-color);display:flex;flex-direction:column;min-height:480px}
.szo-day:last-child{border-right:none}
.szo-day-header{padding:9px 12px 7px;border-bottom:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg);position:sticky;top:0;z-index:5}
.szo-day-title{font-size:.8rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:3px}
.szo-day-phase{font-size:.7rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace);margin-bottom:5px}
.szo-ebar{height:4px;background:var(--bs-border-color);border-radius:2px;overflow:hidden;margin-bottom:5px}
.szo-ebar-fill{height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;background:var(--bs-success)}
.szo-ebar-fill.warn{background:var(--bs-warning)}
.szo-ebar-fill.over{background:var(--bs-danger)}
.szo-day-stats{display:flex;justify-content:space-between;font-size:.7rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace)}
.szo-blocks-list{flex:1;padding:6px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
/* Block card */
.szo-blk{border-radius:5px;padding:7px 9px;cursor:grab;position:relative;border:1px solid transparent;transition:opacity .15s,box-shadow .15s;user-select:none;background:var(--blk-bg,rgba(37,99,235,.12));border-color:var(--blk-border,rgba(37,99,235,.3))}
.szo-blk:active{cursor:grabbing}
.szo-blk.dragging{opacity:.3}
.szo-blk.drag-over{outline:2px solid #f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.2)}
.szo-blk-cat{font-size:.67rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--blk-label,#60a5fa);margin-bottom:2px}
.szo-blk-title{font-size:.8rem;font-weight:600;line-height:1.3;color:var(--bs-body-color)}
.szo-blk-meta{display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:.68rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace)}
.szo-blk-rm{position:absolute;top:5px;right:5px;background:rgba(220,53,69,.2);border:none;color:rgba(220,53,69,.8);width:15px;height:15px;border-radius:50%;cursor:pointer;font-size:8px;line-height:15px;text-align:center;display:none;padding:0}
.szo-blk:hover .szo-blk-rm{display:block}
.szo-blk-rm:hover{background:rgba(220,53,69,.5);color:#fff}
/* Drop zone */
.szo-dz{border:1.5px dashed var(--bs-border-color);border-radius:5px;padding:8px;text-align:center;font-size:.75rem;color:var(--bs-secondary-color);min-height:38px;display:flex;align-items:center;justify-content:center;transition:background .15s,border-color .15s}
.szo-dz.drag-over{background:rgba(245,158,11,.08);border-color:#f59e0b;color:#f59e0b}
/* Validation */
.szo-val{border-top:1px solid var(--bs-border-color);padding:7px 14px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:38px;background:var(--bs-tertiary-bg);font-size:.78rem}
.szo-val-ok{color:var(--bs-success);font-weight:600}
.szo-val-warn{color:var(--bs-warning);background:rgba(255,193,7,.1);padding:2px 8px;border-radius:4px}
.szo-val-err{color:var(--bs-danger);background:rgba(220,53,69,.1);padding:2px 8px;border-radius:4px}
/* Category colors */
[data-cat=theory]     {--blk-bg:rgba(37,99,235,.10);  --blk-border:rgba(37,99,235,.30);  --blk-label:#60a5fa}
[data-cat=workshop]   {--blk-bg:rgba(217,119,6,.10);   --blk-border:rgba(217,119,6,.30);   --blk-label:#fbbf24}
[data-cat=break]      {--blk-bg:rgba(5,150,105,.10);   --blk-border:rgba(5,150,105,.30);   --blk-label:#34d399}
[data-cat=buffer]     {--blk-bg:rgba(75,85,99,.12);    --blk-border:rgba(75,85,99,.30);    --blk-label:#9ca3af}
[data-cat=summary]    {--blk-bg:rgba(124,58,237,.10);  --blk-border:rgba(124,58,237,.30);  --blk-label:#a78bfa}
[data-cat=icebreaker] {--blk-bg:rgba(219,39,119,.10);  --blk-border:rgba(219,39,119,.30);  --blk-label:#f472b6}
[data-cat=qa]         {--blk-bg:rgba(6,182,212,.10);   --blk-border:rgba(6,182,212,.30);   --blk-label:#2dd4bf}
@media(max-width:768px){.szo-layout{grid-template-columns:1fr}.szo-lib{max-height:160px;flex-direction:row;flex-wrap:wrap}.szo-days{grid-template-columns:1fr}}
/* Siatka dostępności */
.szo-avail-grid{display:flex;gap:6px;flex-wrap:wrap}
.szo-avail-day{text-align:center;min-width:40px}
.szo-avail-day--off{opacity:.3;filter:grayscale(1)}
.szo-avail-day__label{font-size:.7rem;font-weight:700;letter-spacing:.04em;margin-bottom:3px;color:var(--bs-secondary-color)}
.szo-avail-day--on .szo-avail-day__label{color:var(--bs-success)}
.szo-avail-slot{font-size:.65rem;background:rgba(25,135,84,.15);border:1px solid rgba(25,135,84,.35);color:var(--bs-success);border-radius:4px;padding:2px 4px;margin-bottom:2px;white-space:nowrap}
.szo-avail-day--off .szo-avail-slot-none{width:20px;height:4px;background:var(--bs-border-color);border-radius:2px;margin:6px auto}
/* Sekcja planowania */
.szo-plan-tbl td,.szo-plan-tbl th{font-size:.8rem;vertical-align:middle}
.szo-pattern-badge{font-family:var(--bs-font-monospace);font-size:.72rem;background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.25);color:var(--bs-primary);border-radius:4px;padding:2px 7px;white-space:nowrap}
</style>

<?php
// Konfiguracja planera — URL aplikacji Angular
$planner_app_url = defined('SZO_PLANNER_URL') ? SZO_PLANNER_URL : 'http://localhost:4201';
$token_endpoint  = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/planner_token.php';
$cur_cid_for_planner = (int)($sel_schedule['course_id'] ?? ($courses[0]['id'] ?? 0));
?>
<div class="mt-3">

  <?php /* ── Kafelek nowego planera ────────────────────────────────────── */ ?>
  <div class="d-flex align-items-center gap-3 mb-4 p-3 rounded-3 border" style="background:linear-gradient(135deg,#1e2235 0%,#2d3252 100%)">
    <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-2 bg-primary bg-opacity-25" style="width:48px;height:48px">
      <i class="bi bi-calendar2-week text-primary fs-4" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1">
      <div class="fw-bold text-light" style="font-size:.95rem">SZO Planner 2.0</div>
      <div class="text-body-secondary small">Interaktywny kalendarz z drag &amp; drop, żetonami i wykrywaniem konfliktów</div>
    </div>
    <button class="btn btn-primary btn-sm fw-semibold flex-shrink-0" id="btnOpenNewPlanner"
            data-cid="<?= $cur_cid_for_planner ?>"
            data-token-url="<?= h($token_endpoint) ?>"
            data-planner-url="<?= h($planner_app_url) ?>">
      <i class="bi bi-calendar2-week me-1" aria-hidden="true"></i>Otwórz planer
    </button>
  </div>

  <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" role="note">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
    <span class="small"><strong>SZOPlanner służy wyłącznie do planowania nowego roku szkolnego.</strong>
    Bieżące zajęcia — ich dodawanie, odwoływanie i edytowanie — prowadź w zakładce <a href="index.php?course=<?= $cur_course ?>&tab=lekcje">Zajęcia</a>.</span>
  </div>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="szoPlanHarmTab" data-bs-toggle="tab" data-bs-target="#szoPaneHarm" type="button" role="tab" aria-controls="szoPaneHarm" aria-selected="true">
        <i class="bi bi-calendar3-week me-1" aria-hidden="true"></i>Harmonogramy
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="szoPlanZajTab" data-bs-toggle="tab" data-bs-target="#szoPaneZaj" type="button" role="tab" aria-controls="szoPaneZaj" aria-selected="false">
        <i class="bi bi-people me-1" aria-hidden="true"></i>Zajęcia
        <?php if ($pl_courses_plan): ?>
        <span class="badge text-bg-secondary ms-1" style="font-size:.65rem"><?= count($pl_courses_plan) ?> gr.</span>
        <?php endif; ?>
      </button>
    </li>
  </ul>

  <div class="tab-content">

  <div class="tab-pane fade show active" id="szoPaneHarm" role="tabpanel" aria-labelledby="szoPlanHarmTab">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-calendar3-week text-primary me-2" aria-hidden="true"></i>SZO Planner — Harmonogramy zajęć</h2>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#szoPlannerNewScheduleModal">
        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nowy harmonogram
      </button>
      <?php if ($sel_schedule): ?>
      <div class="btn-group" role="group" aria-label="Generowanie planu">
        <button class="btn btn-sm btn-warning" id="szoBtnAuto" title="Pełne zaplanowanie od zera przez silnik SZO">
          <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Auto-Plan
        </button>
        <button class="btn btn-sm btn-outline-warning" id="szoBtnMpp" title="MPP — zachowaj obecne rozmieszczenie, zoptymalizuj resztę">
          <i class="bi bi-pin-angle me-1" aria-hidden="true"></i>MPP
        </button>
      </div>
      <button class="btn btn-sm btn-success" id="szoBtnSave">
        <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz
      </button>
      <?php if ($sel_schedule && $sel_schedule['course_id']): ?>
      <button class="btn btn-sm btn-outline-info" id="szoBtnPush"
              data-bs-toggle="modal" data-bs-target="#szoPushModal"
              title="Wrzuć do SZO jako szkice zajęć">
        <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Wrzuć do SZO
      </button>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php /* ── Siatka dostępności prowadzącego ────────────────────────────── */ ?>
  <?php
  $dow_labels = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'Sb', 'Nd'];
  $avail_exists = !empty($pl_avail);
  ?>
  <div class="d-flex flex-wrap gap-3 mb-3 align-items-start">
    <div>
      <div class="small fw-semibold text-body-secondary mb-1">
        <i class="bi bi-clock-history me-1"></i>Dostępność prowadzącego
        <?php if (!$avail_exists): ?>
          <span class="text-warning ms-1" title="Brak ustawionych okien dostępności w zakładce Dostępność">(nie ustawiona)</span>
        <?php endif; ?>
      </div>
      <div class="szo-avail-grid">
        <?php for ($dow = 1; $dow <= 7; $dow++): $slots = $avail_by_dow[$dow % 7] ?? []; $has = !empty($slots); ?>
        <div class="szo-avail-day <?= $has ? 'szo-avail-day--on' : 'szo-avail-day--off' ?>">
          <div class="szo-avail-day__label"><?= $dow_labels[$dow - 1] ?></div>
          <?php if ($has): ?>
            <?php foreach ($slots as $sl): ?>
            <div class="szo-avail-slot"><?= h($sl['time_from']) ?>–<?= h(substr($sl['time_to'], 0, 5)) ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="szo-avail-slot-none"></div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
      <?php if ($avail_exists): ?>
      <div class="text-body-secondary mt-1" style="font-size:.7rem">
        Okno dzienne: <strong><?= gmdate('G:i', $pl_start_min * 60) ?>–<?= gmdate('G:i', $pl_end_min * 60) ?></strong>
        &nbsp;·&nbsp;maks. <strong><?= round(($pl_end_min - $pl_start_min - 60) / 60, 1) ?> h</strong>/dzień
      </div>
      <?php endif; ?>
    </div>

    <?php if ($pl_load_stats && $pl_course_id): ?>
    <div class="border-start ps-3">
      <div class="small fw-semibold text-body-secondary mb-1">
        <i class="bi bi-calendar-week me-1"></i>Obciążenie (następne 4 tygodnie)
      </div>
      <div class="small">
        <span class="fw-semibold"><?= (int)$pl_load_stats['cnt'] ?></span>
        <span class="text-body-secondary"> zapl. zajęć</span>
        &nbsp;·&nbsp;
        <span class="fw-semibold"><?= round((int)$pl_load_stats['total_min'] / 60, 1) ?> h</span>
        <span class="text-body-secondary"> łącznie</span>
        &nbsp;·&nbsp;
        <span class="fw-semibold">~<?= (int)ceil((int)$pl_load_stats['cnt'] / 4) ?></span>
        <span class="text-body-secondary"> zajęć/tydz.</span>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($sel_schedule && $sel_schedule['course_id']): ?>
    <?php $pl_cname = $sel_schedule['course_name'] ?? ('Kurs #' . $sel_schedule['course_id']); ?>
    <div class="border-start ps-3">
      <div class="small fw-semibold text-body-secondary mb-1">
        <i class="bi bi-people me-1"></i>Powiązana grupa
      </div>
      <span class="badge text-bg-primary"><?= h($pl_cname) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($pl_schedules): ?>
  <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <span class="text-body-secondary small fw-semibold">Harmonogram:</span>
    <?php foreach ($pl_schedules as $sch): ?>
    <a href="index.php?tab=planner&sid=<?= (int)$sch['id'] ?>"
       class="btn btn-sm <?= $sch['id'] == $sel_sid ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= h($sch['title']) ?>
      <span class="text-opacity-75 ms-1" style="font-size:.7rem"><?= (int)$sch['num_days'] ?>d</span>
    </a>
    <?php endforeach; ?>
    <?php if ($sel_schedule): ?>
    <button class="btn btn-sm btn-outline-secondary ms-2" id="szoBtnCloneSchedule"
            title="Duplikuj harmonogram <?= h($sel_schedule['title']) ?> (dni i bloki; daty do ustawienia)">
      <i class="bi bi-copy" aria-hidden="true"></i>
    </button>
    <button class="btn btn-sm btn-outline-danger" id="szoBtnDelSchedule"
            title="Usuń harmonogram <?= h($sel_schedule['title']) ?>">
      <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$sel_schedule): ?>
  <div class="alert alert-secondary">
    <i class="bi bi-info-circle me-2"></i>
    Utwórz pierwszy harmonogram przyciskiem <strong>Nowy harmonogram</strong>, a następnie przeciągaj bloki z biblioteki do dni szkolenia.
  </div>
  <?php else: ?>

  <div class="szo-layout" id="szoPlannerApp" style="--szo-ncols:<?= (int)$sel_schedule['num_days'] ?>">
    <!-- BIBLIOTEKA BLOKÓW -->
    <div class="szo-lib" id="szoLibrary">
      <div class="szo-lib-header">Biblioteka bloków</div>
      <button class="btn btn-sm btn-outline-primary w-100 mb-1" style="font-size:.78rem"
              data-bs-toggle="modal" data-bs-target="#szoBlockModal" onclick="szoOpenBlockModal(null)">
        <i class="bi bi-plus me-1"></i>Dodaj blok
      </button>
      <div id="szoLibBlocks"></div>
    </div>

    <!-- KOLUMNY DNI -->
    <div class="szo-days" id="szoDayGrid">
      <?php foreach ($sel_schedule['days'] as $day): ?>
      <div class="szo-day" id="szoDay<?= (int)$day['day_number'] ?>col">
        <div class="szo-day-header">
          <div class="szo-day-title">Dzień <?= (int)$day['day_number'] ?></div>
          <div class="szo-ebar"><div class="szo-ebar-fill" id="szoEbar<?= (int)$day['day_number'] ?>"></div></div>
          <div class="szo-day-stats">
            <span id="szoStat<?= (int)$day['day_number'] ?>">0 / 480 min</span>
            <span id="szoEnergy<?= (int)$day['day_number'] ?>">○ 0</span>
          </div>
          <div class="szo-day-load" id="szoCycLoad<?= (int)$day['day_number'] ?>" style="display:none;font-size:.65rem;color:var(--bs-warning);margin-top:2px"></div>
        </div>
        <div class="szo-blocks-list" id="szoDayBlocks<?= (int)$day['day_number'] ?>" data-day="<?= (int)$day['day_number'] ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- WALIDACJA -->
    <div class="szo-val" id="szoValidation" style="grid-column:1/-1">
      <span class="szo-val-ok"><i class="bi bi-check-circle me-1"></i>Gotowy do planowania</span>
    </div>
  </div>

  <?php endif; /* sel_schedule */ ?>
  </div><!-- /tab-pane harmonogramy -->

  <div class="tab-pane fade" id="szoPaneZaj" role="tabpanel" aria-labelledby="szoPlanZajTab">
    <?php if ($pl_courses_plan): ?>
    <div class="table-responsive">
      <table class="table table-sm szo-plan-tbl">
        <thead>
          <tr>
            <th>Grupa</th>
            <th>Prowadzący</th>
            <th>Zajęcia (4 tyg.)</th>
            <th>Wzorzec</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pl_courses_plan as $cp): ?>
          <?php $pat = pl_pattern($cp['dur_list']); ?>
          <tr>
            <td><?= h($cp['name']) ?></td>
            <td class="text-body-secondary"><?= h($cp['instructor_name'] ?? '—') ?></td>
            <td class="text-center">
              <?php if ($cp['sessions_4w'] > 0): ?>
              <span class="badge text-bg-secondary"><?= (int)$cp['sessions_4w'] ?> × (~<?= (int)ceil((int)$cp['sessions_4w'] / 4) ?>/tydz.)</span>
              <?php else: ?>
              <span class="text-body-secondary">brak</span>
              <?php endif; ?>
            </td>
            <td><span class="szo-pattern-badge"><?= h($pat) ?></span></td>
            <td>
              <button class="btn btn-sm btn-outline-primary py-0 px-2 szo-copy-course-btn"
                      data-course-id="<?= (int)$cp['id'] ?>"
                      data-course-name="<?= h($cp['name']) ?>"
                      data-bs-toggle="modal" data-bs-target="#szoPlannerNewScheduleModal"
                      title="Utwórz harmonogram dla tej grupy">
                <i class="bi bi-copy me-1" aria-hidden="true"></i>Utwórz harmonogram
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-body-secondary text-center py-4">
      <i class="bi bi-people fs-2 d-block mb-2 opacity-40" aria-hidden="true"></i>
      Brak aktywnych grup.
    </div>
    <?php endif; ?>
  </div><!-- /tab-pane zajecia -->

  </div><!-- /tab-content -->
</div>

<!-- ── MODAL: Nowy harmonogram ────────────────────── -->
<div class="modal fade" id="szoPlannerNewScheduleModal" tabindex="-1" aria-labelledby="szoNewSchedLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="szoNewSchedForm">
      <div class="modal-header">
        <h3 class="modal-title h6 fw-bold" id="szoNewSchedLabel">Nowy harmonogram</h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="szoSchedTitle">Nazwa harmonogramu</label>
          <input type="text" class="form-control form-control-sm" id="szoSchedTitle" name="title"
                 placeholder="np. Szkolenie przywódcze IX 2025" required maxlength="200">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="szoSchedCourse">
            Powiązana grupa <span class="text-body-secondary fw-normal">(opcjonalnie)</span>
          </label>
          <select class="form-select form-select-sm" id="szoSchedCourse" name="course_id">
            <option value="">— bez grupy —</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
                    data-pattern="<?= h(pl_pattern('')); /* brak sesji przy tworzeniu */ ?>"
              <?= ($pl_course_id && $pl_course_id === (int)$c['id']) ? 'selected' : '' ?>>
              <?= h($c['name']) ?>
              <?php if ($c['instructor_name'] ?? ''): ?>
                <span class="text-body-secondary">(<?= h($c['instructor_name']) ?>)</span>
              <?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text text-body-secondary" id="szoCoursePatternHint" style="font-size:.75rem"></div>
        </div>
        <div class="mb-0">
          <label class="form-label small fw-semibold" for="szoSchedDays">Liczba dni szkolenia</label>
          <select class="form-select form-select-sm" id="szoSchedDays" name="num_days">
            <option value="2">2 dni</option>
            <option value="3" selected>3 dni</option>
            <option value="4">4 dni</option>
            <option value="5">5 dni</option>
          </select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-sm btn-primary">Utwórz harmonogram</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Blok ────────────────────────────────── -->
<div class="modal fade" id="szoBlockModal" tabindex="-1" aria-labelledby="szoBlockModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="szoBlockForm">
      <input type="hidden" id="szoBlockId" name="block_id" value="">
      <div class="modal-header">
        <h3 class="modal-title h6 fw-bold" id="szoBlockModalLabel">Blok modułowy</h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body row g-2">
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkTitle">Nazwa bloku <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="szoBlkTitle" name="title" required maxlength="200">
        </div>
        <div class="col-sm-6">
          <label class="form-label small fw-semibold" for="szoBlkCat">Kategoria</label>
          <select class="form-select form-select-sm" id="szoBlkCat" name="category">
            <?php foreach (SZO_CATEGORIES as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="szoBlkDur">Czas (min)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkDur" name="duration_min"
                 value="60" min="5" max="480" step="5">
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="szoBlkDiff">Trudność (1–5)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkDiff" name="difficulty"
                 value="2" min="1" max="5">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold" for="szoBlkEnergy">Wpływ energii (−2..+2)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkEnergy" name="energy_impact"
                 value="0" min="-2" max="2">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold" for="szoBlkBreak">Przerwa po (min)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkBreak" name="min_break_after"
                 value="0" min="0" max="60" step="5">
        </div>
        <div class="col-sm-4 d-flex align-items-end">
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="szoBlkLocked" name="locked" value="1">
            <label class="form-check-label small" for="szoBlkLocked">Zablokowany</label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkTags">Tagi (przecinki)</label>
          <input type="text" class="form-control form-control-sm" id="szoBlkTags" name="tags"
                 placeholder="np. leadership, komunikacja">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkNotes">Notatki</label>
          <textarea class="form-control form-control-sm" id="szoBlkNotes" name="notes" rows="2" maxlength="500"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-outline-danger d-none" id="szoBlkDeleteBtn">Usuń blok</button>
        <button type="submit" class="btn btn-sm btn-primary">Zapisz blok</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
'use strict';

/* ── Stałe i dane startowe ──────────────────────────── */
const AJAX   = <?= json_encode($ajax_url) ?>;
const TOKEN  = <?= json_encode(dyd_token()) ?>;
const SID    = <?= (int)$js_sid ?>;
const CAT_LABELS = {
  theory:'Teoria', workshop:'Warsztat', break:'Przerwa',
  buffer:'Bufor', summary:'Synteza', icebreaker:'Icebreaker', qa:'Q&A'
};
/* Obciążenie cykliczne per dzień_tygodnia (z k30_ti_weekly_plan prowadzącego) */
const CYC_LOAD = <?= json_encode(
    array_column(
        db_all("SELECT day_of_week, COALESCE(SUM(duration_min),0) AS min_sum
                FROM k30_ti_weekly_plan WHERE instructor_id=? GROUP BY day_of_week", [$uid]),
        'min_sum', 'day_of_week'
    ) ?: new stdClass
) ?>;

/* ── Stan aplikacji ─────────────────────────────────── */
let ALL_BLOCKS = {};       // id => block object
let state = {
  library: [],             // [block_id, ...]
  days: {},                // day_number => [block_id, ...]
  dayDates: {},            // day_number => 'YYYY-MM-DD' | ''
};
let dragging = null;       // {blockId, source:'library'|'day', dayNum?}
let saveTimer = null;

/* ── Init ───────────────────────────────────────────── */
const SERVER_BLOCKS = <?= $js_blocks ?>;
const SERVER_DAYS   = <?= $js_days ?>;

function init() {
  // Zaindeksuj bloki
  SERVER_BLOCKS.forEach(b => { ALL_BLOCKS[b.id] = b; });

  // Stan dni z serwera
  SERVER_DAYS.forEach(d => {
    state.days[d.day_number]     = (d.block_order || []).map(Number);
    state.dayDates[d.day_number] = d.day_date || '';
  });

  // Pokaż obciążenie cykliczne per dzień
  SERVER_DAYS.forEach(d => updateCycLoad(d.day_number, d.day_date || ''));

  // Bloki w bibliotece = wszystkie bloki minus umieszczone w dniach
  const placed = new Set(Object.values(state.days).flat());
  state.library = SERVER_BLOCKS.map(b => b.id).filter(id => !placed.has(id));

  renderAll();
  attachGlobalDnD();
}

/* ── Obciążenie cykliczne ────────────────────────────── */
function updateCycLoad(dayNum, dayDate) {
  const el = document.getElementById('szoCycLoad' + dayNum);
  if (!el) return;
  if (!dayDate) { el.style.display = 'none'; return; }
  // day_of_week: 0=Nd,1=Pn..6=Sb
  const d = new Date(dayDate + 'T00:00:00');
  const dow = d.getDay();  // 0=Sun,1=Mon..6=Sat
  const cyc = CYC_LOAD[dow] || 0;
  if (!cyc) { el.style.display = 'none'; return; }
  const dh = (cyc / 45).toFixed(1);
  el.style.display = 'block';
  el.innerHTML = `<i class="bi bi-calendar-week me-1"></i>Cykliczne: ${cyc} min (${dh}gh)`;
}

/* ── Render ─────────────────────────────────────────── */
function renderAll() {
  renderLibrary();
  Object.keys(state.days).map(Number).forEach(dn => renderDay(dn));
  renderValidation();
}

function blockCard(blockId, source, dayNum) {
  const b = ALL_BLOCKS[blockId];
  if (!b) return '';
  const dots = '●'.repeat(b.difficulty) + '○'.repeat(5 - b.difficulty);
  const rmBtn = source === 'day'
    ? `<button class="szo-blk-rm" data-rm="1" data-day="${dayNum}" data-bid="${b.id}" tabindex="-1" aria-label="Usuń z dnia">✕</button>`
    : `<button class="szo-blk-rm" data-clone="1" data-bid="${b.id}" tabindex="-1" aria-label="Duplikuj blok" title="Duplikuj blok" style="right:1.9rem;color:#34d399;background:rgba(16,185,129,.15)">⧉</button>`
    + `<button class="szo-blk-rm" data-edit="1" data-bid="${b.id}" tabindex="-1" aria-label="Edytuj blok" style="color:#60a5fa;background:rgba(37,99,235,.2)">✎</button>`;
  return `<div class="szo-blk" draggable="true"
    data-bid="${b.id}" data-src="${source}" ${source==='day' ? `data-day="${dayNum}"` : ''}
    data-cat="${b.category}" role="listitem" tabindex="0"
    aria-label="${b.title}, ${b.duration_min} minut">
    <div class="szo-blk-cat">${CAT_LABELS[b.category]||b.category}</div>
    <div class="szo-blk-title">${esc(b.title)}</div>
    <div class="szo-blk-meta">
      <span>${b.duration_min} min</span>
      <span title="Trudność">${dots}</span>
    </div>
    ${rmBtn}
  </div>`;
}

function dropZone(dayNum, slotIdx) {
  return `<div class="szo-dz" data-dz="1" data-day="${dayNum}" data-slot="${slotIdx}">+ upuść tutaj</div>`;
}

function renderLibrary() {
  const el = document.getElementById('szoLibBlocks');
  if (!el) return;
  if (!state.library.length) {
    el.innerHTML = '<p class="small text-body-secondary text-center my-2">Brak bloków w bibliotece</p>';
    return;
  }
  el.innerHTML = state.library.map(id => blockCard(id, 'library', null)).join('');
}

function renderDay(dayNum) {
  const blocksEl = document.getElementById('szoDayBlocks' + dayNum);
  if (!blocksEl) return;
  const ids = state.days[dayNum] || [];
  blocksEl.innerHTML = ids.map((id, si) => blockCard(id, 'day', dayNum)).join('') + dropZone(dayNum, ids.length);

  // Stats
  const total = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.duration_min || 0), 0);
  const energy = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.energy_impact || 0), 0);
  const pct = Math.min(total / 480 * 100, 100);

  const ef = document.getElementById('szoEbar' + dayNum);
  if (ef) {
    ef.style.width = pct + '%';
    ef.className = 'szo-ebar-fill' + (total > 480 ? ' over' : total > 390 ? ' warn' : '');
  }
  const st = document.getElementById('szoStat' + dayNum);
  if (st) st.textContent = total + ' / 480 min';
  const en = document.getElementById('szoEnergy' + dayNum);
  if (en) en.textContent = energy > 0 ? '⚡ +' + energy : energy < 0 ? '🔋 ' + energy : '○ 0';
  updateCycLoad(dayNum, state.dayDates[dayNum] || '');
}

function renderValidation() {
  const panel = document.getElementById('szoValidation');
  if (!panel) return;
  const issues = [];

  Object.keys(state.days).map(Number).forEach(dn => {
    const ids = state.days[dn] || [];
    const total = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.duration_min || 0), 0);

    if (total > 480) issues.push({t:'err', m:`Dzień ${dn}: przekroczono limit 8h (${total} min)`});
    else if (total > 420) issues.push({t:'warn', m:`Dzień ${dn}: plan przekracza 7h`});

    let acc = 0, warned = false;
    ids.forEach(id => {
      const b = ALL_BLOCKS[id];
      if (!b) return;
      if (b.category === 'break' || b.category === 'buffer') { acc = 0; warned = false; }
      else {
        acc += b.duration_min;
        if (acc > 90 && !warned) {
          issues.push({t:'warn', m:`Dzień ${dn}: ponad 90 min bez przerwy`});
          warned = true; acc = 0;
        }
      }
    });

  });

  if (!issues.length) {
    panel.innerHTML = '<span class="szo-val-ok"><i class="bi bi-check-circle me-1"></i>Plan spójny — brak naruszeń reguł</span>';
  } else {
    panel.innerHTML = issues.map(i =>
      `<span class="szo-val-${i.t==='err'?'err':'warn'}">${i.t==='err'?'✕':'△'} ${esc(i.m)}</span>`
    ).join('');
  }
}

/* ── Drag & Drop ─────────────────────────────────────── */
function attachGlobalDnD() {
  const app = document.getElementById('szoPlannerApp');
  if (!app) return;

  app.addEventListener('dragstart', e => {
    const card = e.target.closest('.szo-blk');
    if (!card) return;
    dragging = {
      blockId: +card.dataset.bid,
      source:  card.dataset.src,
      dayNum:  card.dataset.day ? +card.dataset.day : null,
    };
    requestAnimationFrame(() => card.classList.add('dragging'));
    e.dataTransfer.effectAllowed = 'move';
  });

  app.addEventListener('dragend', () => {
    app.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    dragging = null;
  });

  app.addEventListener('dragover', e => {
    const tgt = e.target.closest('.szo-dz,.szo-blk[data-src="day"]');
    if (!tgt || !dragging) return;
    e.preventDefault();
    tgt.classList.add('drag-over');
  });

  app.addEventListener('dragleave', e => {
    const tgt = e.target.closest('.szo-dz,.szo-blk');
    if (tgt && !tgt.contains(e.relatedTarget)) tgt.classList.remove('drag-over');
  });

  app.addEventListener('drop', e => {
    e.preventDefault();
    const tgt = e.target.closest('.szo-dz,.szo-blk[data-src="day"]');
    if (!tgt || !dragging) return;
    tgt.classList.remove('drag-over');

    const targetDay  = +tgt.dataset.day;
    const targetSlot = +(tgt.dataset.slot ?? (tgt.dataset.day && state.days[targetDay].indexOf(+tgt.dataset.bid)));
    const { blockId, source, dayNum: srcDay } = dragging;

    // Remove from source
    if (source === 'library') {
      state.library = state.library.filter(id => id !== blockId);
    } else {
      const idx = state.days[srcDay].indexOf(blockId);
      if (idx !== -1) state.days[srcDay].splice(idx, 1);
    }

    // Insert at target slot
    const slot = +tgt.dataset.slot;
    if (!isNaN(slot)) {
      state.days[targetDay].splice(slot, 0, blockId);
    } else {
      // dropped on existing block — insert before it
      const existBid = +tgt.dataset.bid;
      const pos = state.days[targetDay].indexOf(existBid);
      state.days[targetDay].splice(pos >= 0 ? pos : state.days[targetDay].length, 0, blockId);
    }

    renderAll();
    scheduleSave();
  });

  // Click remove / edit buttons (event delegation)
  app.addEventListener('click', e => {
    const rmBtn = e.target.closest('[data-rm]');
    if (rmBtn) {
      const dayNum = +rmBtn.dataset.day;
      const bid    = +rmBtn.dataset.bid;
      state.days[dayNum] = (state.days[dayNum] || []).filter(id => id !== bid);
      state.library.push(bid);
      renderAll();
      scheduleSave();
      return;
    }
    const editBtn = e.target.closest('[data-edit]');
    if (editBtn) {
      szoOpenBlockModal(+editBtn.dataset.bid);
      return;
    }
    const cloneBtn = e.target.closest('[data-clone]');
    if (cloneBtn) {
      szoCloneBlock(+cloneBtn.dataset.bid);
    }
  });
}

/* ── Duplikowanie bloku w bibliotece ────────────────── */
async function szoCloneBlock(bid) {
  const fd = new FormData();
  fd.append('action', 'block_clone');
  fd.append('block_id', bid);
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (!j.ok) { alert('Błąd: ' + j.msg); return; }
  ALL_BLOCKS[j.block.id] = j.block;
  state.library.push(j.block.id);
  renderLibrary();
}

/* ── Server-Side Solve (silnik Python) ──────────────── */
async function szoServerSolve(mode) {
  if (mode === 'auto' && !confirm('Generuj Auto-Plan przez silnik SZO?\nIstniejące rozmieszczenie bloków zostanie zastąpione.')) return;

  const btnId = mode === 'auto' ? 'szoBtnAuto' : 'szoBtnMpp';
  const btn = document.getElementById(btnId);
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Planowanie…'; }

  try {
    const fd = new FormData();
    fd.append('action', 'schedule_solve');
    fd.append('schedule_id', SID);
    fd.append('mode', mode);
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    if (!j.ok) { alert('Błąd silnika: ' + j.msg); return; }

    // Zaktualizuj lokalny stan z serwera
    if (j.schedule && j.schedule.days) {
      j.schedule.days.forEach(d => {
        state.days[d.day_number] = (d.block_order || []).map(Number);
      });
      const placed = new Set(Object.values(state.days).flat());
      state.library = Object.keys(ALL_BLOCKS).map(Number).filter(id => !placed.has(id));
    }

    renderAll();
    // Pokaż wynik z silnika w panelu walidacji
    if (j.score !== null && j.score !== undefined) {
      szoRenderSolverResult(j.score, j.violations || [], j.placed || 0, j.unplaced || 0, j.mpp_moves, j.avail_window);
    }
  } catch(e) {
    alert('Błąd połączenia z silnikiem SZO: ' + e.message);
    console.error('szo solve error', e);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = mode === 'auto'
        ? '<i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Auto-Plan'
        : '<i class="bi bi-pin-angle me-1" aria-hidden="true"></i>MPP';
    }
  }
}

function szoRenderSolverResult(score, violations, placed, unplaced, mppMoves, availWin) {
  const panel = document.getElementById('szoValidation');
  if (!panel) return;
  const scoreClr = score >= 80 ? 'text-success' : score >= 50 ? 'text-warning' : 'text-danger';
  let html = `<span class="${scoreClr} fw-bold me-3">${Math.round(score)}/100</span>`;
  if (placed)  html += `<span class="szo-val-ok me-2">✓ ${placed} bloków</span>`;
  if (unplaced) html += `<span class="szo-val-warn me-2">⚠ ${unplaced} bez miejsca</span>`;
  if (mppMoves != null) html += `<span class="text-body-secondary me-2 small">MPP: ${mppMoves} przesunięć</span>`;
  if (availWin) html += `<span class="text-body-secondary small me-2">⏱ okno: ${fmt(availWin.start_min)}–${fmt(availWin.end_min)}</span>`;
  violations.forEach(v => {
    const cls = v.level === 'HARD' ? 'szo-val-err' : 'szo-val-warn';
    html += `<span class="${cls}">${v.level === 'HARD' ? '✕' : '△'} ${esc(v.message)}</span>`;
  });
  if (!violations.length && score >= 80) html += '<span class="szo-val-ok"><i class="bi bi-check-circle me-1"></i>Plan spójny</span>';
  panel.innerHTML = html;
}

function fmt(min) {
  return String(Math.floor(min/60)).padStart(2,'0') + ':' + String(min%60).padStart(2,'0');
}

/* ── Zapis (debounced) ──────────────────────────────── */
function scheduleSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(doSave, 1500);
}

async function doSave() {
  if (!SID) return;
  const days = Object.keys(state.days).map(Number).map(dn => ({
    day_number: dn,
    phase: 'foundation',
    block_order: state.days[dn] || [],
  }));
  try {
    const fd = new FormData();
    fd.append('action', 'schedule_save');
    fd.append('schedule_id', SID);
    fd.append('days_json', JSON.stringify(days));
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    const btn = document.getElementById('szoBtnSave');
    if (btn) {
      btn.classList.toggle('btn-success', j.ok);
      btn.classList.toggle('btn-danger', !j.ok);
      setTimeout(() => { btn.classList.add('btn-success'); btn.classList.remove('btn-danger'); }, 2000);
    }
  } catch(e) { console.error('szo save error', e); }
}

/* ── Przycisk Zapisz (ręczny) ───────────────────────── */
const saveBtn = document.getElementById('szoBtnSave');
if (saveBtn) saveBtn.addEventListener('click', () => { clearTimeout(saveTimer); doSave(); });

/* ── Przycisk Auto-Plan (silnik Python) ─────────────── */
const autoBtn = document.getElementById('szoBtnAuto');
if (autoBtn) autoBtn.addEventListener('click', () => szoServerSolve('auto'));

/* ── Przycisk MPP ───────────────────────────────────── */
const mppBtn = document.getElementById('szoBtnMpp');
if (mppBtn) mppBtn.addEventListener('click', () => szoServerSolve('mpp'));

/* ── Duplikuj harmonogram ───────────────────────────── */
const cloneSchedBtn = document.getElementById('szoBtnCloneSchedule');
if (cloneSchedBtn) cloneSchedBtn.addEventListener('click', async () => {
  if (!confirm('Zduplikować harmonogram razem z dniami i blokami? Daty dni w kopii będą do ustawienia.')) return;
  const fd = new FormData();
  fd.append('action', 'schedule_clone');
  fd.append('schedule_id', SID);
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (!j.ok) { alert('Błąd: ' + j.msg); return; }
  // Otwórz kopię — działa i w index.php?tab=planner, i na samodzielnym planner.php
  const u = new URL(window.location.href);
  u.searchParams.set('sid', j.schedule_id);
  window.location.href = u.toString();
});

/* ── Usuń harmonogram ───────────────────────────────── */
const delBtn = document.getElementById('szoBtnDelSchedule');
if (delBtn) delBtn.addEventListener('click', async () => {
  if (!confirm('Usunąć harmonogram? Tej akcji nie można cofnąć.')) return;
  const fd = new FormData();
  fd.append('action', 'schedule_delete');
  fd.append('schedule_id', SID);
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (j.ok) window.location.href = 'index.php?tab=planner';
  else alert('Błąd: ' + j.msg);
});

/* ── Nowy harmonogram (modal form) ──────────────────── */
const newSchedForm = document.getElementById('szoNewSchedForm');
if (newSchedForm) newSchedForm.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(newSchedForm);
  fd.append('action', 'schedule_create');
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (j.ok && j.schedule) {
    window.location.href = 'index.php?tab=planner&sid=' + j.schedule.id;
  } else {
    alert('Błąd: ' + (j.msg || 'Nieznany błąd'));
  }
});

/* ── Modal bloku ────────────────────────────────────── */
window.szoOpenBlockModal = function(blockId) {
  const form   = document.getElementById('szoBlockForm');
  const delBtn = document.getElementById('szoBlkDeleteBtn');
  if (!form) return;
  form.reset();
  const b = blockId ? ALL_BLOCKS[blockId] : null;
  document.getElementById('szoBlockId').value   = b ? b.id : '';
  document.getElementById('szoBlkTitle').value  = b ? b.title : '';
  document.getElementById('szoBlkCat').value    = b ? b.category : 'workshop';
  document.getElementById('szoBlkDur').value    = b ? b.duration_min : 60;
  document.getElementById('szoBlkDiff').value   = b ? b.difficulty : 2;
  document.getElementById('szoBlkEnergy').value = b ? b.energy_impact : 0;
  document.getElementById('szoBlkBreak').value  = b ? b.min_break_after : 0;
  document.getElementById('szoBlkNotes').value  = b ? (b.notes || '') : '';
  document.getElementById('szoBlkTags').value   = b ? (JSON.parse(b.tags||'[]').join(', ')) : '';
  document.getElementById('szoBlkLocked').checked = b ? !!b.locked : false;
  document.getElementById('szoBlockModalLabel').textContent = b ? 'Edytuj blok' : 'Nowy blok';
  if (delBtn) delBtn.classList.toggle('d-none', !b);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('szoBlockModal')).show();
};

const blockForm = document.getElementById('szoBlockForm');
if (blockForm) {
  blockForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(blockForm);
    fd.append('action', 'block_save');
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    if (j.ok && j.block) {
      ALL_BLOCKS[j.block.id] = j.block;
      if (!state.library.includes(j.block.id) && !Object.values(state.days).flat().includes(j.block.id)) {
        state.library.push(j.block.id);
      }
      bootstrap.Modal.getInstance(document.getElementById('szoBlockModal')).hide();
      renderAll();
    } else alert('Błąd: ' + (j.msg || 'Nieznany błąd'));
  });

  document.getElementById('szoBlkDeleteBtn')?.addEventListener('click', async () => {
    const bid = +document.getElementById('szoBlockId').value;
    if (!bid || !confirm('Usunąć blok z biblioteki?')) return;
    const fd = new FormData();
    fd.append('action', 'block_delete');
    fd.append('block_id', bid);
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    if (j.ok) {
      delete ALL_BLOCKS[bid];
      state.library = state.library.filter(id => id !== bid);
      Object.keys(state.days).forEach(dn => {
        state.days[dn] = (state.days[dn]||[]).filter(id => id !== bid);
      });
      bootstrap.Modal.getInstance(document.getElementById('szoBlockModal')).hide();
      renderAll();
      scheduleSave();
    } else alert('Błąd: ' + j.msg);
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

init();

/* ── "Kopiuj do harmonogramu" (sekcja planowania) ────── */
document.querySelectorAll('.szo-copy-course-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const cid  = btn.dataset.courseId;
    const name = btn.dataset.courseName;
    const sel = document.getElementById('szoSchedCourse');
    if (sel) sel.value = cid;
    const titleInp = document.getElementById('szoSchedTitle');
    if (titleInp && !titleInp.value) titleInp.value = 'Harmonogram — ' + name;
  });
});

/* ── Podpowiedź kursu w modalu nowego harmonogramu ───── */
const courseSelect = document.getElementById('szoSchedCourse');
if (courseSelect) {
  courseSelect.addEventListener('change', () => {
    // Placeholder: wzorzec zajęć pobieramy po stronie PHP już w <option>
    // — tu można rozszerzyć o AJAX
  });
}

/* ── Modal "Wrzuć do SZO" — wypełnij daty ──────────── */
const pushModal = document.getElementById('szoPushModal');
if (pushModal) {
  pushModal.addEventListener('show.bs.modal', () => {
    const cont = document.getElementById('szoPushDatesBody');
    if (!cont) return;
    const dayNums = Object.keys(state.days).map(Number).sort((a,b)=>a-b);
    cont.innerHTML = dayNums.map(dn => {
      const cnt = (state.days[dn] || []).length;
      const mins = (state.days[dn] || []).reduce((s, id) => s + (ALL_BLOCKS[id]?.duration_min || 0), 0);
      return `<div class="mb-2">
        <label class="form-label small fw-semibold" for="szoPushDate${dn}">
          Dzień ${dn} <span class="fw-normal text-body-secondary">${cnt} bloków · ${mins} min</span>
        </label>
        <input type="date" class="form-control form-control-sm" id="szoPushDate${dn}"
               name="day_date_${dn}" data-day="${dn}" required>
      </div>`;
    }).join('');
  });
}

/* ── Formularz "Wrzuć do SZO" ───────────────────────── */
const pushForm = document.getElementById('szoPushForm');
if (pushForm) {
  pushForm.querySelectorAll('input[type=date][data-day]').forEach(inp => {
    inp.addEventListener('change', () => {
      const dn = +inp.dataset.day;
      state.dayDates[dn] = inp.value;
      updateCycLoad(dn, inp.value);
    });
  });

  pushForm.addEventListener('submit', async e => {
    e.preventDefault();
    const dayDates = {};
    pushForm.querySelectorAll('input[type=date][data-day]').forEach(inp => {
      if (inp.value) dayDates[inp.dataset.day] = inp.value;
    });
    const courseId = pushForm.querySelector('[name=push_course_id]')?.value || '';

    const btn = pushForm.querySelector('[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'Wrzucam…'; }

    try {
      const fd = new FormData();
      fd.append('action', 'schedule_push');
      fd.append('schedule_id', SID);
      fd.append('course_id', courseId);
      fd.append('day_dates', JSON.stringify(dayDates));
      fd.append('_token', TOKEN);
      const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
      const j = await r.json();
      if (j.ok) {
        bootstrap.Modal.getInstance(pushModal).hide();
        alert('✓ ' + j.msg);
      } else {
        alert('Błąd: ' + j.msg);
      }
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Wrzuć do SZO'; }
    }
  });
}

})();
</script>

<?php if ($sel_schedule): ?>
<!-- ── MODAL: Wrzuć do SZO ──────────────────────────── -->
<div class="modal fade" id="szoPushModal" tabindex="-1" aria-labelledby="szoPushLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form class="modal-content" id="szoPushForm">
      <div class="modal-header">
        <h3 class="modal-title h6 fw-bold" id="szoPushLabel">
          <i class="bi bi-box-arrow-in-down me-2" aria-hidden="true"></i>Wrzuć do SZO jako Szkice
        </h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary mb-3">
          Każdy blok stanie się osobną lekcją ze statusem <strong>Szkic</strong>.
          Godziny wyznacza okno dostępności prowadzącego.
        </p>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="szoPushCourse">Grupa</label>
          <select class="form-select form-select-sm" id="szoPushCourse" name="push_course_id" required>
            <option value="">— wybierz —</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
              <?= ($pl_course_id && $pl_course_id === (int)$c['id']) ? 'selected' : '' ?>>
              <?= h($c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="szoPushDatesBody"><!-- daty wstrzykuje JS --></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-sm btn-info">Wrzuć do SZO</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
/* ── Otwieranie Nowego Planera SZO ─────────────────────────────────────── */
(function () {
  var btn = document.getElementById('btnOpenNewPlanner');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Otwieranie…';

    try {
      var cid        = btn.dataset.cid || '0';
      var tokenUrl   = btn.dataset.tokenUrl;
      var plannerUrl = btn.dataset.plannerUrl;

      var resp = await fetch(tokenUrl + '?course_id=' + encodeURIComponent(cid));
      if (!resp.ok) { var e = await resp.json().catch(function(){return{}}); throw new Error(e.error || 'HTTP ' + resp.status); }
      var data = await resp.json();

      var url = new URL(plannerUrl);
      url.searchParams.set('token',   data.token);
      url.searchParams.set('api_url', data.api_url);
      if (cid && cid !== '0') url.searchParams.set('course_id', cid);
      url.searchParams.set('source', 'ti');

      var iframe = document.getElementById('szoModalIframe');
      iframe.src = '';
      var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('szoModal'));
      modal.show();
      iframe.src = url.toString();
    } catch (err) {
      alert('Nie udało się otworzyć planera:\n' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = origHtml;
    }
  });

  // Wyczyść iframe po zamknięciu modala (zatrzymuje Angular app)
  document.getElementById('szoModal').addEventListener('hide.bs.modal', function () {
    document.getElementById('szoModalIframe').src = '';
  });
})();
</script>

<!-- Modal SZO Planner -->
<div class="modal fade" id="szoModal" tabindex="-1" aria-label="SZO Planner" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content border-0">
      <div class="modal-header py-2 px-3" style="background:#1e2235;border-bottom:1px solid #2d3252">
        <span class="fw-bold text-light d-flex align-items-center gap-2" style="font-size:.9rem">
          <i class="bi bi-calendar2-week text-primary" aria-hidden="true"></i>SZO Planner
        </span>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body p-0 position-relative">
        <div id="szoModalSpinner" class="position-absolute top-50 start-50 translate-middle text-center" style="z-index:10">
          <div class="spinner-border text-primary mb-2" role="status"></div>
          <div class="small text-muted">Ładowanie planera…</div>
        </div>
        <iframe id="szoModalIframe"
                src=""
                style="width:100%;height:100%;border:none;display:block"
                allow="clipboard-write"
                onload="document.getElementById('szoModalSpinner').style.display='none'">
        </iframe>
      </div>
    </div>
  </div>
</div>
