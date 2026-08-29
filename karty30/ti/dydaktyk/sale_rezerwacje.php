<?php
/**
 * karty30/ti/dydaktyk/sale_rezerwacje.php — Wykaz sal do rezerwacji.
 *
 * Lista kontrolna dla koordynatora logistycznego: wszystkie terminy z
 * przypisaną salą w danym tygodniu, pogrupowane dniami, ze statusem
 * zgłoszenia (Do rezerwacji / Potwierdzone — k30_ti_sessions.room_reservation_status).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();
ti_planner_ext_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'toggle_reservation') {
    csrf_check();
    $sid    = (int)($_POST['session_id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'potwierdzone' ? 'potwierdzone' : 'do_rezerwacji';
    pl_room_reservation_set($sid, $status);
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$w = (string)($_GET['w'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $w)) $w = date('Y-m-d');
$mon  = date('Y-m-d', strtotime('monday this week', strtotime($w)));
$sun  = date('Y-m-d', strtotime($mon . ' +6 days'));
$prev = date('Y-m-d', strtotime($mon . ' -7 days'));
$next = date('Y-m-d', strtotime($mon . ' +7 days'));

$by_day = ti_room_reservation_report($mon, $sun);
$total  = array_sum(array_map('count', $by_day));
$pending = 0;
foreach ($by_day as $day_rows) foreach ($day_rows as $r) if ($r['room_reservation_status'] !== 'potwierdzone') $pending++;

ti_print_log_add('sale_rezerwacje', 'Wykaz sal do rezerwacji — ' . $mon . ' – ' . $sun, 0, 0, [], $me);

$KP_TITLE  = 'Wykaz sal do rezerwacji — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me['name'] ?? '', 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'sale_rezerwacje.php'; $KIER_LABEL = 'Wykaz sal do rezerwacji';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap usos-noprint">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-clipboard-check text-primary me-2" aria-hidden="true"></i>Wykaz sal do rezerwacji</h1>
  <div class="ms-auto d-flex gap-2 align-items-center">
    <a href="?w=<?= h($prev) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni tydzień"><i class="bi bi-chevron-left"></i></a>
    <span class="small fw-semibold"><?= h(date('d.m', strtotime($mon))) ?>–<?= h(date('d.m.Y', strtotime($sun))) ?></span>
    <a href="?w=<?= h($next) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny tydzień"><i class="bi bi-chevron-right"></i></a>
    <a href="?w=<?= h(date('Y-m-d')) ?>" class="btn btn-sm btn-outline-primary">Bieżący tydzień</a>
    <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Drukuj</button>
  </div>
</div>

<p class="text-body-secondary small usos-noprint">
  Terminy z przypisaną salą w wybranym tygodniu. Zaznacz „Potwierdzone" po zgłoszeniu rezerwacji do administracji budynku —
  status jest niezależny od statusu samej lekcji. Sale zarządzane w <a href="sale.php">wykazie sal</a>.
</p>

<?php if ($total): ?>
<div class="alert <?= $pending ? 'alert-warning' : 'alert-success' ?> py-2 small usos-noprint">
  <?= $total ?> termin(y/ów) z salą w tym tygodniu, w tym <strong><?= $pending ?></strong> jeszcze „Do rezerwacji".
</div>
<?php endif; ?>

<?php if (!$by_day): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak terminów z przypisaną salą w tym tygodniu.</div></div>
<?php endif; ?>

<?php foreach ($by_day as $date => $day_rows): $dow = (int)date('N', strtotime($date)); ?>
<div class="card mb-3">
  <div class="card-header fw-semibold">
    <?= h(TI_DAYS_PL_FULL[$dow] ?? '') ?>, <?= h(date('d.m.Y', strtotime($date))) ?>
    <span class="badge bg-secondary ms-1"><?= count($day_rows) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col" class="text-nowrap">Godziny</th>
          <th scope="col">Sala / lokalizacja</th>
          <th scope="col">Grupa</th>
          <th scope="col">Prowadzący</th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($day_rows as $r): $confirmed = $r['room_reservation_status'] === 'potwierdzone'; ?>
        <tr>
          <td class="text-nowrap small"><?= h(substr((string)$r['time_from'], 0, 5)) ?>–<?= h(substr((string)$r['time_to'], 0, 5)) ?></td>
          <td class="small"><?= h($r['room_label']) ?></td>
          <td class="small"><?= h($r['course_name']) ?></td>
          <td class="small"><?= h($r['instructor_label']) ?></td>
          <td class="small">
            <form method="post" class="d-inline usos-noprint">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op" value="toggle_reservation">
              <input type="hidden" name="session_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="status" value="<?= $confirmed ? 'do_rezerwacji' : 'potwierdzone' ?>">
              <button class="btn btn-sm <?= $confirmed ? 'btn-success' : 'btn-outline-warning' ?>">
                <i class="bi bi-<?= $confirmed ? 'check-circle-fill' : 'hourglass-split' ?> me-1"></i><?= $confirmed ? 'Potwierdzone' : 'Do rezerwacji' ?>
              </button>
            </form>
            <span class="badge d-none d-print-inline <?= $confirmed ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $confirmed ? 'Potwierdzone' : 'Do rezerwacji' ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
</main>
<style>@media print { .usos-noprint { display: none !important; } }</style>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
