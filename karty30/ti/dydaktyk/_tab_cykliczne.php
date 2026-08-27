<?php
/**
 * _tab_cykliczne.php — Tygodniowy plan zajęć cyklicznych (Planer IT).
 *
 * Limity: TI_WEEKLY_MAX_MIN = 4 × 45 min = 180 min dziennie.
 * Dostępność: wyłącznie sloty ze statusem 'approved'.
 */

$wpl_all    = ti_weekly_plan_list($uid);
$avail_all  = ti_instructor_availability($uid);  // wszystkie (approved + draft)

// Zbuduj indeksy
$wpl_by_dow   = [];
foreach ($wpl_all as $w) { $wpl_by_dow[(int)$w['day_of_week']][] = $w; }

$avail_by_dow = [];
foreach ($avail_all as $a) { $avail_by_dow[(int)$a['day_of_week']][] = $a; }

// Suma minut per dzień
$day_used = [];
foreach ($wpl_all as $w) {
    $d = (int)$w['day_of_week'];
    $day_used[$d] = ($day_used[$d] ?? 0) + (int)$w['duration_min'];
}

// Kursy bez slotu w ogóle
$assigned_cids = array_unique(array_column($wpl_all, 'course_id'));
$all_courses   = db_all(
    "SELECT c.id, c.name, c.duration_min FROM k30_ti_courses c
     WHERE c.instructor_id=? AND c.is_active=1 AND c.status!='cancelled'
     ORDER BY c.name",
    [$uid]
);
$unassigned_courses = array_filter($all_courses, fn($c) => !in_array((int)$c['id'], array_map('intval', $assigned_cids)));

// Kolory dla kursów (deterministyczne)
$palette = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#ec4899','#6366f1'];
$course_colors = [];
foreach ($all_courses as $i => $c) {
    $course_colors[(int)$c['id']] = $palette[$i % count($palette)];
}

$DOW_ORDER = [1,2,3,4,5,6,0];  // Pn..Sb, Nd
$DAY_MAX   = TI_WEEKLY_MAX_MIN;
$DH_MIN    = TI_WEEKLY_DH_MIN;
?>
<style>
/* ── Planer cykliczny ─────────────────────────────────── */
.wpl-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:0;border:1px solid var(--bs-border-color);border-radius:8px;overflow:hidden}
.wpl-col{border-right:1px solid var(--bs-border-color);min-width:0}
.wpl-col:last-child{border-right:none}
.wpl-col-hdr{padding:8px 10px;background:var(--bs-tertiary-bg);border-bottom:1px solid var(--bs-border-color);text-align:center}
.wpl-col-hdr-name{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.wpl-col-hdr-bar{margin-top:5px;height:5px;background:var(--bs-border-color);border-radius:3px;overflow:hidden}
.wpl-col-hdr-fill{height:100%;border-radius:3px;transition:width .3s;background:var(--bs-success)}
.wpl-col-hdr-fill.warn{background:var(--bs-warning)}
.wpl-col-hdr-fill.over{background:var(--bs-danger)}
.wpl-col-hdr-stat{font-size:.67rem;color:var(--bs-secondary-color);margin-top:2px;font-family:var(--bs-font-monospace)}
.wpl-col-body{padding:6px;min-height:120px;display:flex;flex-direction:column;gap:4px}
.wpl-slot{cursor:grab}
.wpl-slot.ui-draggable-dragging{cursor:grabbing;box-shadow:0 6px 18px rgba(0,0,0,.35)}
.wpl-drop-hint{outline:1px dashed var(--bs-border-color);outline-offset:-3px}
.wpl-drop-over{background:rgba(37,99,235,.10);outline:2px dashed #2563eb;outline-offset:-3px}
.wpl-avail-badge{font-size:.63rem;padding:2px 5px;border-radius:3px;font-family:var(--bs-font-monospace)}
.wpl-avail-app{background:rgba(16,185,129,.12);color:#065f46;border:1px solid rgba(16,185,129,.3)}
.wpl-avail-dft{background:rgba(245,158,11,.12);color:#92400e;border:1px solid rgba(245,158,11,.3)}
.wpl-slot{border-radius:5px;padding:6px 8px;font-size:.78rem;color:#fff;position:relative;cursor:default}
.wpl-slot-name{font-weight:600;line-height:1.2;margin-bottom:2px}
.wpl-slot-meta{font-size:.67rem;opacity:.85;font-family:var(--bs-font-monospace)}
.wpl-slot-badge{font-size:.6rem;padding:1px 5px;border-radius:10px;background:rgba(255,255,255,.25);font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.wpl-slot-actions{position:absolute;top:4px;right:4px;display:none;gap:3px}
.wpl-slot:hover .wpl-slot-actions{display:flex}
.wpl-col-empty{font-size:.72rem;color:var(--bs-secondary-color);text-align:center;padding:10px 0;font-style:italic}
.wpl-col-noavail{background:repeating-linear-gradient(-45deg,transparent,transparent 4px,rgba(0,0,0,.03) 4px,rgba(0,0,0,.03) 8px)}
.wpl-add-btn{width:100%;border:1.5px dashed var(--bs-border-color);background:none;color:var(--bs-secondary-color);border-radius:5px;padding:5px;font-size:.72rem;cursor:pointer;transition:all .15s}
.wpl-add-btn:hover{border-color:var(--bs-primary);color:var(--bs-primary)}
.wpl-unassigned{display:flex;flex-wrap:wrap;gap:6px}
.wpl-unassigned-pill{background:var(--bs-tertiary-bg);border:1px solid var(--bs-border-color);border-radius:20px;padding:4px 12px;font-size:.78rem;display:flex;align-items:center;gap:6px}
@media(max-width:768px){.wpl-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h2 class="h5 fw-bold mb-0"><i class="bi bi-calendar-week me-2" aria-hidden="true"></i>Tygodniowy plan zajęć cyklicznych</h2>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <form method="post" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="weekly_autoassign">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <button type="submit" class="btn btn-sm btn-outline-primary"
              onclick="return confirm('Auto-rozkład przypisze nieprzypisane kursy do wolnych okien dostępności.\nKontynuować?')"
              title="Greedy auto-przypisz kursy do dostępnych okien">
        <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Auto-rozkład
      </button>
    </form>
    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#wplHelp">
      <i class="bi bi-question-circle" aria-hidden="true"></i>
    </button>
  </div>
</div>

<div class="collapse mb-3" id="wplHelp">
  <div class="alert alert-info mb-0 small">
    <strong>Tygodniowy plan zajęć cyklicznych</strong> — przypisz każdy kurs do jednego lub kilku dni tygodnia.
    Limit: <strong>4 godziny dydaktyczne = <?= $DAY_MAX ?> min</strong> dziennie per prowadzący.
    1 godz. dydaktyczna = <?= $DH_MIN ?> min.<br>
    <strong>Kolory dostępności:</strong>
    <span class="wpl-avail-badge wpl-avail-app me-1">Zatwierdzona</span>
    <span class="wpl-avail-badge wpl-avail-dft">Planowana (szkic)</span> — szkic nie jest brany pod uwagę przy Auto-rozkładzie.
  </div>
</div>

<?= flash_html() ?>

<!-- Generowanie lekcji z plannera godzin -->
<div class="card mb-4" id="generowanie">
  <div class="card-header fw-semibold"><i class="bi bi-magic me-2" aria-hidden="true"></i>Generuj lekcje z tego planu</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Planner godzin opisuje wzorzec tygodnia. Tu zamieniasz go na konkretne lekcje w wybranym
      zakresie dat. Pomijane są: dni wolne, zamknięte okresy, terminy poza Twoją dostępnością,
      terminy z zajętym kontem Zoom oraz lekcje, które już istnieją — dlatego generowanie można
      bezpiecznie powtórzyć.
    </p>
    <form id="wplGenForm" class="row g-2 align-items-end">
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="wplGenFrom">Od</label>
        <input type="date" class="form-control form-control-sm" id="wplGenFrom" name="date_from"
               value="<?= h(date('Y-m-d')) ?>" required>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="wplGenTo">Do</label>
        <input type="date" class="form-control form-control-sm" id="wplGenTo" name="date_to"
               value="<?= h(date('Y-m-d', strtotime('+3 months'))) ?>" required>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="wplGenCourse">Grupa</label>
        <select class="form-select form-select-sm" id="wplGenCourse" name="course_id">
          <option value="0">— wszystkie moje sloty —</option>
          <?php foreach ($all_courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label fw-semibold" for="wplGenStatus">Status lekcji</label>
        <select class="form-select form-select-sm" id="wplGenStatus" name="status">
          <option value="planned">Zaplanowana</option>
          <option value="draft">Szkic</option>
        </select>
      </div>
      <div class="col-12">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="wplGenApproved" checked>
          <label class="form-check-label small" for="wplGenApproved">tylko slajdy zatwierdzone</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="wplGenSkipOff" checked>
          <label class="form-check-label small" for="wplGenSkipOff">pomijaj dni wolne i przerwy</label>
        </div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary btn-sm" id="wplGenBtn">Generuj lekcje</button>
        <span class="form-text ms-2">Lekcje trafiają do zakładki „Zajęcia” wybranej grupy.</span>
      </div>
    </form>
    <div id="wplGenResult" class="mt-3" style="display:none" role="status" aria-live="polite"></div>
  </div>
</div>

<div id="wplStatus" class="alert py-2 small" style="display:none" role="status" aria-live="polite"></div>

<!-- Siatka tygodniowa -->
<div class="wpl-grid mb-4" role="grid" aria-label="Tygodniowy plan zajęć">

<?php foreach ($DOW_ORDER as $dow):
    $wins   = $avail_by_dow[$dow] ?? [];
    $slots  = $wpl_by_dow[$dow]   ?? [];
    $used   = $day_used[$dow]     ?? 0;
    $pct    = min(100, round($used / $DAY_MAX * 100));
    $fill_class = $used > $DAY_MAX ? 'over' : ($used > $DAY_MAX * .75 ? 'warn' : '');
    $has_avail  = (bool)$wins;
    $dname = K30_TI_DAYS[$dow];
?>
<div class="wpl-col<?= $has_avail ? '' : ' wpl-col-noavail' ?>" role="gridcell" aria-label="<?= h($dname) ?>" data-dow="<?= (int)$dow ?>">
  <div class="wpl-col-hdr">
    <div class="wpl-col-hdr-name"><?= h($dname) ?></div>
    <div class="wpl-col-hdr-bar"><div class="wpl-col-hdr-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div></div>
    <div class="wpl-col-hdr-stat">
      <?= $used ?>/<?= $DAY_MAX ?> min
      <?php if ($used > $DAY_MAX): ?><span class="text-danger fw-bold"> !</span><?php endif ?>
    </div>
  </div>
  <div class="wpl-col-body" data-dow="<?= (int)$dow ?>">
    <!-- Okna dostępności -->
    <?php foreach ($wins as $w):
        $cls = $w['status'] === 'approved' ? 'wpl-avail-app' : 'wpl-avail-dft';
    ?>
    <span class="wpl-avail-badge <?= $cls ?>">
      <?= h(substr($w['time_from'],0,5)) ?>–<?= h(substr($w['time_to'],0,5)) ?>
      <?= $w['status'] === 'draft' ? ' (szkic)' : '' ?>
    </span>
    <?php endforeach; ?>
    <?php if (!$has_avail): ?>
    <div class="wpl-col-empty">brak<br>dostępności</div>
    <?php endif; ?>

    <!-- Przypisane kursy -->
    <?php foreach ($slots as $sl):
        $col = $course_colors[(int)$sl['course_id']] ?? '#6b7280';
        $st  = TI_WEEKLY_STATUS[$sl['status']] ?? TI_WEEKLY_STATUS['draft'];
        $dh  = round((int)$sl['duration_min'] / $DH_MIN, 1);
    ?>
    <div class="wpl-slot" style="background:<?= h($col) ?>" aria-label="<?= h($sl['course_name']) ?>"
         data-slot-id="<?= (int)$sl['id'] ?>" data-course-id="<?= (int)$sl['course_id'] ?>"
         data-from="<?= h(substr((string)$sl['time_from'], 0, 5)) ?>" data-dur="<?= (int)$sl['duration_min'] ?>"
         data-status="<?= h((string)$sl['status']) ?>" data-notes="<?= h((string)($sl['notes'] ?? '')) ?>">
      <div class="wpl-slot-actions">
        <button type="button" class="btn btn-sm p-0 text-white" style="width:18px;height:18px;font-size:9px;line-height:18px"
                data-wpl-status="1" title="<?= $sl['status']==='draft' ? 'Zatwierdź' : 'Cofnij do szkicu' ?>">
          <i class="bi bi-<?= $sl['status']==='draft'?'check-circle':'arrow-counterclockwise' ?>"></i>
        </button>
        <button type="button" class="btn btn-sm p-0 text-white" style="width:18px;height:18px;font-size:9px;line-height:18px"
                data-wpl-edit="1" title="Edytuj slot">
          <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="btn btn-sm p-0 text-white" style="width:18px;height:18px;font-size:9px;line-height:18px"
                data-wpl-del="1" title="Usuń slot">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="wpl-slot-name"><?= h($sl['course_name']) ?></div>
      <div class="wpl-slot-meta">
        <?= h(substr($sl['time_from'],0,5)) ?> · <?= (int)$sl['duration_min'] ?>min (<?= $dh ?>gh)
      </div>
      <span class="wpl-slot-badge" style="background:rgba(0,0,0,.2)">
        <?= h($st['label']) ?>
      </span>
    </div>
    <?php endforeach; ?>

    <!-- Przycisk dodania -->
    <?php if ($has_avail): ?>
    <button type="button" class="wpl-add-btn mt-1" data-wpl-add="1" data-dow="<?= (int)$dow ?>"
            aria-label="Dodaj slot: <?= h($dname) ?>">
      <i class="bi bi-plus" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div><!-- /wpl-grid -->

<!-- Nieprzypisane kursy -->
<?php if ($unassigned_courses): ?>
<div class="card border-warning mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle text-warning" aria-hidden="true"></i>
    <span class="fw-semibold">Kursy bez przypisanego tygodniowego slotu (<?= count($unassigned_courses) ?>)</span>
  </div>
  <div class="card-body">
    <div class="wpl-unassigned">
      <?php foreach ($unassigned_courses as $uc):
          $col = $course_colors[(int)$uc['id']] ?? '#6b7280';
          $dur = (int)($uc['duration_min'] ?: 90);
          $dh  = round($dur / $DH_MIN, 1);
      ?>
      <div class="wpl-unassigned-pill">
        <span style="width:10px;height:10px;border-radius:50%;background:<?= h($col) ?>;flex-shrink:0"></span>
        <span><?= h($uc['name']) ?></span>
        <span class="text-muted small"><?= $dur ?>min (<?= $dh ?>gh)</span>
        <button type="button" class="btn btn-sm btn-link p-0 text-primary ms-1" style="font-size:.72rem"
                data-wpl-add="1" data-course-id="<?= (int)$uc['id'] ?>">
          <i class="bi bi-plus-circle" aria-hidden="true"></i>Przypisz
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-success d-flex gap-2 align-items-center mb-4">
  <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
  Wszystkie aktywne kursy mają przynajmniej jeden tygodniowy slot.
</div>
<?php endif; ?>

<!-- Legenda -->
<div class="d-flex flex-wrap gap-3 text-muted small mb-2">
  <span><span class="badge text-bg-success">Zatwierdzona</span> — slot zatwierdzony, trafia do planu</span>
  <span><span class="badge text-bg-warning text-dark">Szkic</span> — wstępny, nie trafia do harmonogramu kursanta</span>
  <span><i class="bi bi-slash-circle me-1"></i>Kratka tła = dzień bez dostępności</span>
  <span><i class="bi bi-arrows-move me-1"></i>Slot przeciągasz myszą na inny dzień — zapis dzieje się od razu</span>
</div>

<!-- Modal: Dodaj / Edytuj slot -->
<div class="modal fade" id="wplSlotModal" tabindex="-1" aria-labelledby="wplSlotModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="wplSlotModalLabel">Slot zajęć</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form id="wplSlotForm">
        <div class="modal-body">
          <input type="hidden" id="wplSlotId" name="slot_id" value="">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="wplCourseId">Kurs</label>
            <select class="form-select" id="wplCourseId" name="course_id" required>
              <?php foreach ($all_courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-dur="<?= (int)($c['duration_min']?:90) ?>">
                <?= h($c['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="wplDow">Dzień</label>
            <select class="form-select" id="wplDow" name="day_of_week" required>
              <?php foreach ([1,2,3,4,5,6,0] as $d): ?>
              <option value="<?= $d ?>"><?= h(K30_TI_DAYS[$d]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="wplTimeFrom">Godzina rozpoczęcia</label>
            <select class="form-select" id="wplTimeFrom" name="time_from">
              <?= ti_time_options('09:00') ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="wplDuration">Czas trwania (min)</label>
            <input type="number" class="form-control" id="wplDuration" name="duration_min"
                   value="90" min="15" max="<?= $DAY_MAX ?>" step="15" required>
            <div class="form-text" id="wplDurationDh"></div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="wplStatus">Status</label>
            <select class="form-select" id="wplStatus" name="status">
              <option value="draft">Planowana (szkic)</option>
              <option value="approved">Zatwierdzona</option>
            </select>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold" for="wplNotes">Uwagi</label>
            <input type="text" class="form-control" id="wplNotes" name="notes" maxlength="200">
          </div>
          <div class="small text-muted mt-2" id="wplDayLoad"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary" id="wplSlotSubmit">Zapisz slot</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- jQuery + jQuery UI: przeciąganie slotów między dniami. Ładowane tylko na tej
     zakładce — reszta panelu jest bez jQuery i taka zostaje. -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.13.3/jquery-ui.min.js"></script>
<script>
jQuery(function ($) {
  'use strict';

  var AJAX      = <?= json_encode(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/planner_ajax.php') ?>;
  var TOKEN     = <?= json_encode(dyd_token()) ?>;
  var DAY_MAX   = <?= (int)$DAY_MAX ?>;
  var DH_MIN    = <?= (int)$DH_MIN ?>;
  var DAY_NAMES = <?= json_encode(array_combine([1,2,3,4,5,6,0], array_map(fn($d) => K30_TI_DAYS[$d], [1,2,3,4,5,6,0]))) ?>;

  var $modal = $('#wplSlotModal');
  var modal  = $modal.length ? new bootstrap.Modal($modal[0]) : null;

  /** Jedno miejsce na wywołania endpointu — wszystkie akcje plannera idą tędy. */
  function api(action, data) {
    return $.post(AJAX, $.extend({ action: action, _token: TOKEN }, data || []), null, 'json');
  }

  function say(msg, kind) {
    var $box = $('#wplStatus');
    if (!$box.length) { if (kind === 'err') { window.alert(msg); } return; }
    $box.attr('class', 'alert alert-' + (kind === 'err' ? 'danger' : (kind === 'warn' ? 'warning' : 'success')) + ' py-2 small')
        .text(msg).show();
    if (kind !== 'err') { window.setTimeout(function () { $box.fadeOut(400); }, 6000); }
  }

  // ── Sumy dnia liczone z DOM, żeby po przeciągnięciu nie przeładowywać strony ──
  function recalcDay(dow) {
    var $body = $('.wpl-col-body[data-dow="' + dow + '"]');
    var used  = 0;
    $body.find('.wpl-slot').each(function () { used += parseInt($(this).data('dur'), 10) || 0; });
    var $hdr  = $('.wpl-col[data-dow="' + dow + '"] .wpl-col-hdr');
    var pct   = Math.min(100, Math.round(used / DAY_MAX * 100));
    $hdr.find('.wpl-col-hdr-fill')
        .css('width', pct + '%')
        .removeClass('warn over')
        .addClass(used > DAY_MAX ? 'over' : (used > DAY_MAX * 0.75 ? 'warn' : ''));
    $hdr.find('.wpl-col-hdr-stat').html(used + '/' + DAY_MAX + ' min' + (used > DAY_MAX ? ' <span class="text-danger fw-bold">!</span>' : ''));
    return used;
  }

  function recalcAll() { $('.wpl-col-body').each(function () { recalcDay($(this).data('dow')); }); }

  // ── Przeciąganie slotów między dniami (jQuery UI) ───────────────────────────
  function initDnD() {
    $('.wpl-slot').draggable({
      revert: 'invalid',
      revertDuration: 150,
      helper: 'clone',
      opacity: 0.75,
      zIndex: 1080,
      cursor: 'grabbing',
      start: function () { $('.wpl-col-body').addClass('wpl-drop-hint'); },
      stop:  function () { $('.wpl-col-body').removeClass('wpl-drop-hint'); }
    });

    $('.wpl-col-body').droppable({
      accept: '.wpl-slot',
      hoverClass: 'wpl-drop-over',
      drop: function (ev, ui) {
        var $slot   = ui.draggable;
        var $target = $(this);
        var fromDow = parseInt($slot.closest('.wpl-col-body').data('dow'), 10);
        var toDow   = parseInt($target.data('dow'), 10);
        if (fromDow === toDow) { return; }

        var payload = {
          slot_id:      $slot.data('slot-id'),
          course_id:    $slot.data('course-id'),
          day_of_week:  toDow,
          time_from:    $slot.data('from'),
          duration_min: $slot.data('dur'),
          status:       $slot.data('status'),
          notes:        $slot.data('notes') || ''
        };
        // Przenosimy od razu, a przy odmowie serwera wracamy — użytkownik widzi skutek natychmiast
        $slot.css({ top: 0, left: 0 }).appendTo($target);
        recalcDay(fromDow); recalcDay(toDow);

        api('weekly_slot_save', payload)
          .done(function (j) {
            if (j && j.ok) {
              $slot.closest('.wpl-col-body').data('dow', toDow);
              say(DAY_NAMES[toDow] + ': slot przeniesiony.', 'ok');
            } else {
              revert(j && j.msg);
            }
          })
          .fail(function (xhr) {
            var j = xhr.responseJSON;
            revert(j && j.msg ? j.msg : 'Nie udało się przenieść slotu.');
          });

        function revert(msg) {
          $slot.appendTo($('.wpl-col-body[data-dow="' + fromDow + '"]'));
          recalcDay(fromDow); recalcDay(toDow);
          say(msg || 'Serwer odrzucił zmianę.', 'err');
        }
      }
    });
  }

  // ── Formularz slotu (Bootstrap modal + jQuery) ───────────────────────────────
  function durDh() {
    var dur = parseInt($('#wplDuration').val(), 10) || 0;
    $('#wplDurationDh').text((dur / DH_MIN).toFixed(1) + ' godz. dydaktycznych');
    dayLoad();
  }

  function dayLoad() {
    var dow  = parseInt($('#wplDow').val(), 10);
    var dur  = parseInt($('#wplDuration').val(), 10) || 0;
    var sid  = parseInt($('#wplSlotId').val(), 10) || 0;
    var used = recalcDay(dow);
    var left = DAY_MAX - used;
    var over = (used + (sid ? 0 : dur)) > DAY_MAX;
    $('#wplDayLoad').html(
      (DAY_NAMES[dow] || '') + ': zajęte <strong>' + used + '</strong>/' + DAY_MAX + ' min (zostało <strong>' +
      left + '</strong> min = ' + (left / DH_MIN).toFixed(1) + ' gh)' +
      (over ? ' <span class="text-danger fw-bold">— przekroczony limit!</span>' : '')
    );
  }

  $('#wplDuration').on('input', durDh);
  $('#wplDow').on('change', dayLoad);
  $('#wplCourseId').on('change', function () {
    var dur = $(this).find('option:selected').data('dur');
    if (dur) { $('#wplDuration').val(dur); durDh(); }
  });

  $('#wplSlotForm').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#wplSlotSubmit').prop('disabled', true);
    api('weekly_slot_save', $(this).serializeArray().reduce(function (acc, f) { acc[f.name] = f.value; return acc; }, {}))
      .done(function (j) {
        if (j && j.ok) { window.location.reload(); }
        else { say((j && j.msg) || 'Błąd zapisu.', 'err'); }
      })
      .fail(function (xhr) { say((xhr.responseJSON && xhr.responseJSON.msg) || 'Błąd zapisu.', 'err'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  // Otwieranie formularza — z przycisków w kolumnach i z listy nieprzypisanych kursów
  $(document).on('click', '[data-wpl-add]', function () {
    var dow = $(this).data('dow');
    var cid = $(this).data('course-id');
    $('#wplSlotId').val('');
    $('#wplSlotModalLabel').text('Nowy slot zajęć');
    if (dow !== undefined && dow !== null && dow !== '') { $('#wplDow').val(dow); }
    if (cid) {
      $('#wplCourseId').val(cid);
      var dur = $('#wplCourseId').find('option:selected').data('dur');
      if (dur) { $('#wplDuration').val(dur); }
    }
    durDh();
    if (modal) { modal.show(); }
  });

  $(document).on('click', '[data-wpl-edit]', function () {
    var $s = $(this).closest('.wpl-slot');
    $('#wplSlotId').val($s.data('slot-id'));
    $('#wplSlotModalLabel').text('Edytuj slot zajęć');
    $('#wplCourseId').val($s.data('course-id'));
    $('#wplDow').val($s.closest('.wpl-col-body').data('dow'));
    $('#wplTimeFrom').val($s.data('from'));
    $('#wplDuration').val($s.data('dur'));
    $('#wplStatus').val($s.data('status'));
    $('#wplNotes').val($s.data('notes') || '');
    durDh();
    if (modal) { modal.show(); }
  });

  $(document).on('click', '[data-wpl-del]', function () {
    var $s = $(this).closest('.wpl-slot');
    if (!window.confirm('Usunąć ten slot zajęć?')) { return; }
    var dow = $s.closest('.wpl-col-body').data('dow');
    api('weekly_slot_delete', { slot_id: $s.data('slot-id') })
      .done(function (j) {
        if (j && j.ok) { $s.fadeOut(150, function () { $s.remove(); recalcDay(dow); }); say('Slot usunięty.', 'ok'); }
        else { say((j && j.msg) || 'Błąd.', 'err'); }
      })
      .fail(function () { say('Nie udało się usunąć slotu.', 'err'); });
  });

  $(document).on('click', '[data-wpl-status]', function () {
    var $s  = $(this).closest('.wpl-slot');
    var cur = $s.data('status');
    var nxt = cur === 'draft' ? 'approved' : 'draft';
    api('weekly_slot_status', { slot_id: $s.data('slot-id'), status: nxt })
      .done(function (j) {
        if (j && j.ok) {
          $s.data('status', nxt);
          $s.find('.wpl-slot-badge').text(nxt === 'approved' ? 'Zatwierdzona' : 'Szkic');
          $(this).attr('title', nxt === 'draft' ? 'Zatwierdź' : 'Cofnij do szkicu')
                 .find('i').attr('class', 'bi bi-' + (nxt === 'draft' ? 'check-circle' : 'arrow-counterclockwise'));
          say('Status slotu: ' + (nxt === 'approved' ? 'zatwierdzona' : 'szkic') + '.', 'ok');
        } else { say((j && j.msg) || 'Błąd.', 'err'); }
      }.bind(this))
      .fail(function () { say('Nie udało się zmienić statusu.', 'err'); });
  });

  // ── Generowanie lekcji z plannera godzin ────────────────────────────────────
  $('#wplGenForm').on('submit', function (e) {
    e.preventDefault();
    var $btn  = $('#wplGenBtn');
    var $out  = $('#wplGenResult');
    var data  = $(this).serializeArray().reduce(function (acc, f) { acc[f.name] = f.value; return acc; }, {});
    data.only_approved = $('#wplGenApproved').is(':checked') ? '1' : '0';
    data.skip_off_days = $('#wplGenSkipOff').is(':checked') ? '1' : '0';

    $btn.prop('disabled', true).text('Generuję…');
    $out.hide();

    api('weekly_generate', data)
      .done(function (j) {
        if (!j || !j.ok) { say((j && j.msg) || 'Nie udało się wygenerować lekcji.', 'err'); return; }
        var html = '<div class="fw-semibold mb-1">' + j.msg + '</div>';
        if (j.reasons && j.reasons.length) {
          html += '<ul class="mb-0 small">' + j.reasons.map(function (r) {
            return '<li>' + $('<div>').text(r).html() + '</li>';
          }).join('') + '</ul>';
        }
        if (j.created > 0) {
          html += '<div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="index.php?tab=lekcje">Zobacz zajęcia</a></div>';
        }
        $out.attr('class', 'alert alert-' + (j.created > 0 ? 'success' : 'warning') + ' py-2 small').html(html).show();
      })
      .fail(function (xhr) { say((xhr.responseJSON && xhr.responseJSON.msg) || 'Błąd generowania.', 'err'); })
      .always(function () { $btn.prop('disabled', false).text('Generuj lekcje'); });
  });

  initDnD();
  recalcAll();
  durDh();
});
</script>
