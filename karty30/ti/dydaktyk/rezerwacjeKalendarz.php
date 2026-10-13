<?php
/**
 * karty30/ti/dydaktyk/rezerwacjeKalendarz.php — kalendarz rezerwacji terminów konsultacji (przeniesione z
 * karty30/schedules/calendar.php). FullCalendar 6 (jak zakładka Zajęcia panelu): miesiąc / tydzień / dzień / lista,
 * filtry konsultanta i beneficjenta, klik w wolne pole → nowa rezerwacja z datą i godziną. ?api=1&start&end — JSON.
 */
require __DIR__ . '/_rezerwacje_nav.php';
$f = ['consultant' => (int)($_GET['consultant'] ?? 0), 'client_id' => (int)($_GET['client_id'] ?? 0)];
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(TiBookings::events((string)($_GET['start'] ?? date('Y-m-01')), (string)($_GET['end'] ?? date('Y-m-t')), $f), JSON_UNESCAPED_UNICODE);
    exit;
}
$consultants = k30_get_consultants();
$KP_FULLCALENDAR = true;
$RZ_TITLE = 'Kalendarz rezerwacji';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-calendar3 text-primary me-2" aria-hidden="true"></i>Kalendarz rezerwacji</h1>
<?php rz_nav('kalendarz'); ?>
<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-2">
  <div><label class="form-label small mb-0" for="kc-c">Konsultant</label>
    <select class="form-select form-select-sm" id="kc-c" name="consultant" onchange="this.form.submit()"><option value="0">wszyscy</option><?php foreach ($consultants as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $f['consultant'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['display_name']) ?></option><?php endforeach; ?></select></div>
  <?php if ($f['client_id']): ?><input type="hidden" name="client_id" value="<?= $f['client_id'] ?>"><span class="small">filtr beneficjenta · <a href="rezerwacjeKalendarz.php">wyczyść</a></span><?php endif; ?>
</form>
<p class="small text-body-secondary">Kliknij wolne pole w widoku tygodnia lub dnia, aby zarezerwować termin. Kolory jak statusy na liście; widok „Lista” jest wygodniejszy dla czytników ekranu.</p>
<div id="rz-cal" class="bg-white p-2 rounded shadow-sm"></div>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.FullCalendar) return;
  var qs = <?= json_encode(http_build_query(array_filter($f))) ?>;
  var cal = new FullCalendar.Calendar(document.getElementById('rz-cal'), {
    locale: 'pl', firstDay: 1, initialView: window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek', slotMinTime: '07:00:00', slotMaxTime: '21:00:00',
    nowIndicator: true, height: 'auto', allDaySlot: false,
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
    buttonText: { today: 'dziś', month: 'miesiąc', week: 'tydzień', day: 'dzień', list: 'lista' },
    events: 'rezerwacjeKalendarz.php?api=1' + (qs ? '&' + qs : ''),
    eventDidMount: function (i) { i.el.title = i.event.title + ' — ' + i.event.extendedProps.status + (i.event.extendedProps.series ? ' (seria)' : ''); },
    dateClick: function (i) { if (i.view.type === 'dayGridMonth') { cal.changeView('timeGridDay', i.dateStr); return; }
      var d = i.dateStr.slice(0, 10), t = i.dateStr.slice(11, 16); location.href = 'rezerwacja.php?nowa=1&date=' + d + '&time=' + t; }
  });
  cal.render();
});
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
