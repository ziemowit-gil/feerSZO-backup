<?php
/**
 * karty30/ti/dydaktyk/sale_rezerwacje.php — Wykaz sal do rezerwacji.
 *
 * Lista kontrolna dla koordynatora logistycznego: wszystkie terminy z
 * przypisaną salą w wybranym okresie (tydzień / miesiąc / 3 miesiące),
 * pogrupowane dniami, ze statusem zgłoszenia (Do rezerwacji / Potwierdzone
 * — k30_ti_sessions.room_reservation_status).
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

$range = in_array($_GET['range'] ?? '', ['week', 'month', 'quarter'], true) ? $_GET['range'] : 'week';
$w = (string)($_GET['w'] ?? '');
$RR = ti_room_reservation_range($w, $range);
$from = $RR['from']; $to = $RR['to']; $prev = $RR['prev']; $next = $RR['next']; $range_label = $RR['label'];

$by_day = ti_room_reservation_report($from, $to);
$total  = array_sum(array_map('count', $by_day));
$pending = 0;
foreach ($by_day as $day_rows) foreach ($day_rows as $r) if ($r['room_reservation_status'] !== 'potwierdzone') $pending++;

ti_print_log_add('sale_rezerwacje', 'Wykaz sal do rezerwacji — ' . $from . ' – ' . $to, 0, 0, ['range' => $range], $me);

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
  <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
    <select class="form-select form-select-sm" style="width:auto" aria-label="Zakres"
            onchange="location.href='?range='+this.value+'&w=<?= h(date('Y-m-d')) ?>'">
      <option value="week"    <?= $range==='week'    ? 'selected' : '' ?>>Tydzień</option>
      <option value="month"   <?= $range==='month'   ? 'selected' : '' ?>>Miesiąc</option>
      <option value="quarter" <?= $range==='quarter' ? 'selected' : '' ?>>3 miesiące</option>
    </select>
    <a href="?range=<?= h($range) ?>&w=<?= h($prev) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni okres"><i class="bi bi-chevron-left"></i></a>
    <span class="small fw-semibold"><?= h($range_label) ?></span>
    <a href="?range=<?= h($range) ?>&w=<?= h($next) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny okres"><i class="bi bi-chevron-right"></i></a>
    <a href="?range=<?= h($range) ?>&w=<?= h(date('Y-m-d')) ?>" class="btn btn-sm btn-outline-primary">Dziś</a>
    <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Drukuj</button>
    <a href="sale_rezerwacje_pdf.php?range=<?= h($range) ?>&w=<?= h($from) ?>" target="_blank" class="btn btn-sm btn-primary">
      <i class="bi bi-file-earmark-pdf me-1"></i>Pobierz PDF
    </a>
  </div>
</div>

<p class="text-body-secondary small usos-noprint">
  Terminy z przypisaną salą w wybranym okresie. Zaznacz „Potwierdzone" po zgłoszeniu rezerwacji do administracji budynku —
  status jest niezależny od statusu samej lekcji. Sale zarządzane w <a href="sale.php">wykazie sal</a>.
</p>

<?php if ($total): ?>
<div class="alert <?= $pending ? 'alert-warning' : 'alert-success' ?> py-2 small usos-noprint">
  <?= $total ?> termin(y/ów) z salą w wybranym okresie, w tym <strong><?= $pending ?></strong> jeszcze „Do rezerwacji".
</div>
<?php endif; ?>

<?php if (!$by_day): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak terminów z przypisaną salą w wybranym okresie.</div></div>
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
