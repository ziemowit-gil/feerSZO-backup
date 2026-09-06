<?php
/**
 * modules/srs/kalendarz.php — Interaktywny kalendarz SRS (dzień/tydzień)
 * z systemem filtrów (kategoria, zasób, status rezerwacji).
 *
 * Powłoka jak w modules/srs/index.php: wolontariusz dostaje powłokę panelu,
 * edytor/admin powłokę SZO.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

require_login();
resources_migrate();

$PAGE_TITLE = 'Kalendarz rezerwacji';
$user       = current_user();
$categories = res_categories();
$resources  = res_list(0);

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__, 2) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__, 2) . '/includes/header.php';
}
require_once dirname(__DIR__, 2) . '/panel/includes/pv_ui.php';

pv_page_header('Kalendarz rezerwacji', [
    'icon'    => 'bi-calendar3',
    'sub'     => 'Wszystkie zdarzenia SRS w wybranym dniu / tygodniu — filtruj wg kategorii, zasobu i statusu',
    'actions' => '<a href="' . APP_URL . '/modules/srs/" class="tz-btn tz-btn--ghost">'
               . '<i class="bi bi-grid" aria-hidden="true"></i>Lista zasobów</a> '
               . '<a href="' . APP_URL . '/modules/srs/my.php" class="tz-btn tz-btn--ghost">'
               . '<i class="bi bi-list-check" aria-hidden="true"></i>Moje rezerwacje</a>',
]);
?>

<div class="pv-wrap">
<?= flash_html() ?>

<div class="tz-card mb-3" aria-label="Filtry kalendarza">
  <div class="tz-card__bd">
    <div class="row g-3 align-items-end">
      <div class="col-sm-4 col-lg-3">
        <label class="form-label small fw-semibold mb-1" for="f-cat">Kategoria</label>
        <select id="f-cat" class="form-select form-select-sm">
          <option value="0">Wszystkie</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-lg-3">
        <label class="form-label small fw-semibold mb-1" for="f-res">Zasób</label>
        <select id="f-res" class="form-select form-select-sm">
          <option value="0">Wszystkie</option>
          <?php foreach ($resources as $r): ?>
          <option value="<?= (int)$r['id'] ?>" data-cat="<?= (int)($r['category_id'] ?? 0) ?>"><?= h($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-6">
        <span class="form-label small fw-semibold mb-1 d-block">Status</span>
        <div class="d-flex flex-wrap gap-2" id="f-status">
          <?php foreach (RES_STATUSES as $key => $st):
            $checked = !in_array($key, ['odmowa', 'anulowana'], true);
          ?>
          <label class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill border small"
                 data-bg="<?= h($st['bg']) ?>"
                 style="cursor:pointer;border-color:<?= h($st['color']) ?>66;background:<?= $checked ? h($st['bg']) : 'transparent' ?>">
            <input type="checkbox" class="form-check-input mt-0 f-status-cb" value="<?= h($key) ?>" <?= $checked ? 'checked' : '' ?>
                   style="accent-color:<?= h($st['color']) ?>">
            <span style="color:<?= h($st['color']) ?>"><?= h($st['label']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="tz-card mb-0">
  <div class="tz-card__bd">
    <div id="srs-calendar"></div>
  </div>
</div>

</div><!-- /pv-wrap -->

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
(function() {
    var catSel  = document.getElementById('f-cat');
    var resSel  = document.getElementById('f-res');
    var resOpts = Array.prototype.slice.call(resSel.options);

    function applyResourceFilter() {
        var cat = catSel.value;
        resOpts.forEach(function(o) {
            o.hidden = cat !== '0' && o.value !== '0' && o.dataset.cat !== cat;
        });
        if (resSel.selectedOptions[0] && resSel.selectedOptions[0].hidden) resSel.value = '0';
    }

    function currentStatuses() {
        return Array.prototype.slice.call(document.querySelectorAll('.f-status-cb:checked')).map(function(c) { return c.value; });
    }

    var calendarEl = document.getElementById('srs-calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'pl',
        height: 'auto',
        initialView: 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridWeek,timeGridDay' },
        firstDay: 1,
        nowIndicator: true,
        events: function(info, successCallback, failureCallback) {
            var params = new URLSearchParams({
                start: info.startStr,
                end: info.endStr,
                cat: catSel.value,
                resource: resSel.value,
            });
            currentStatuses().forEach(function(s) { params.append('status[]', s); });
            fetch('<?= APP_URL ?>/modules/srs/calendar.php?' + params.toString())
                .then(function(r) { return r.json(); })
                .then(successCallback)
                .catch(failureCallback);
        },
        eventDidMount: function(info) {
            var p = info.event.extendedProps;
            info.el.setAttribute('title', info.event.title + ' · ' + p.status + ' · ' + p.user);
        },
    });
    calendar.render();

    catSel.addEventListener('change', function() { applyResourceFilter(); calendar.refetchEvents(); });
    resSel.addEventListener('change', function() { calendar.refetchEvents(); });
    document.querySelectorAll('.f-status-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var label = cb.closest('label');
            label.style.background = cb.checked ? label.dataset.bg : 'transparent';
            calendar.refetchEvents();
        });
    });
    applyResourceFilter();
})();
</script>

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__, 2) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
<?php endif; ?>
