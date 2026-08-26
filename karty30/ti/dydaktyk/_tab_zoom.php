<?php
/**
 * karty30/ti/dydaktyk/_tab_zoom.php — zakładka „Zajętość Zoom" w panelu dydaktyka.
 *
 * Pokazuje, dlaczego system nie pozwala ustawić niektórych terminów zajęć zdalnych,
 * i gdzie są wolne okna. Nazwy kursów widoczne tylko dla własnych grup — pozostała
 * zajętość jako „Inne zajęcia zdalne" (dla prowadzącego liczy się wolne/zajęte).
 *
 * Zmienne z index.php: $course_ids (własne kursy), $tab.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_zoom_calendar.php';

$zc_month = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $zc_month)) $zc_month = date('Y-m');
$zc_first = strtotime($zc_month . '-01');
$zc_from  = date('Y-m-01', $zc_first);
$zc_to    = date('Y-m-t',  $zc_first);
$zc_prev  = date('Y-m', strtotime($zc_from . ' -1 month'));
$zc_next  = date('Y-m', strtotime($zc_from . ' +1 month'));

$zc_months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
                 7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$zc_label = $zc_months_pl[(int)date('n', $zc_first)] . ' ' . date('Y', $zc_first);

$zc_busy    = ti_zoom_busy_range($zc_from, $zc_to);
$zc_visible = dyd_is_staff() ? null : ($course_ids ?? []);
$zc_total   = array_sum(array_map('count', $zc_busy['days']));
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-camera-video me-2" aria-hidden="true"></i>Zajętość Zoom</h1>
  <span class="badge bg-secondary"><?= (int)$zc_total ?> zajętych terminów w miesiącu</span>
</div>

<?php if (!zoom_enabled()): ?>
<div class="alert alert-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Integracja Zoom nie jest włączona, więc terminy zajęć nie są ograniczane zajętością Zooma.
</div>
<?php else: ?>

<?php if (!$zc_busy['ok']): ?>
<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
  Nie udało się teraz odczytać kalendarza Zoom. Widzisz tylko lekcje zaplanowane w systemie —
  spotkania utworzone bezpośrednio w Zoomie mogą być nieznane.
</div>
<?php endif; ?>

<?= ti_zoom_explain_html() ?>

<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <a href="index.php?tab=zoom&m=<?= h($zc_prev) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
    <span class="fw-semibold"><?= h(mb_strtoupper(mb_substr($zc_label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($zc_label, 1, null, 'UTF-8')) ?></span>
    <a href="index.php?tab=zoom&m=<?= h($zc_next) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny miesiąc"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    <a href="index.php?tab=zoom&m=<?= h(date('Y-m')) ?>" class="btn btn-sm btn-outline-primary">Dziś</a>
  </div>
  <div class="card-body">
    <?= ti_zoom_calendar_month_html($zc_month, $zc_busy['days'], $zc_visible) ?>
    <?= ti_zoom_calendar_legend_html() ?>
  </div>
</div>
<?php endif; ?>
