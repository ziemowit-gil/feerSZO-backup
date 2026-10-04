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
$view = ($_GET['view'] ?? '') === 'operator' ? 'operator' : 'day';
$status = ti_room_reservation_status_param((string)($_GET['status'] ?? ''));
$RR = ti_room_reservation_range($w, $range);
$from = $RR['from']; $to = $RR['to']; $prev = $RR['prev']; $next = $RR['next']; $range_label = $RR['label'];

$by_group = $view === 'operator' ? ti_room_reservation_report_by_operator($from, $to) : ti_room_reservation_report($from, $to);

// Zbiorcze zatwierdzenie roboczych („Do rezerwacji" → „Potwierdzone") w bieżącym okresie;
// opcjonalnie tylko jeden operator (widok wg operatora). Działa na tym, co raport realnie pokazuje.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'approve_drafts') {
    csrf_check();
    $only_op = trim((string)($_POST['operator'] ?? ''));
    $ids = [];
    foreach ($by_group as $gk => $grows) {
        if ($only_op !== '' && (string)$gk !== $only_op) continue;
        foreach ($grows as $gr) {
            if ($view === 'operator') { if ((int)$gr['pending'] > 0) $ids = array_merge($ids, $gr['session_ids']); }
            elseif (($gr['room_reservation_status'] ?? '') !== 'potwierdzone') $ids[] = (int)$gr['id'];
        }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    foreach ($ids as $sid) pl_room_reservation_set($sid, 'potwierdzone');
    ti_print_log_add('sale_rezerwacje_approve', 'Zatwierdzono robocze rezerwacje sal (' . count($ids) . ') — ' . $from . ' – ' . $to, 0, 0,
        ['range' => $range, 'view' => $view, 'operator' => $only_op, 'count' => count($ids)], $me);
    flash_set('success', $ids ? 'Zatwierdzono rezerwacje robocze: ' . count($ids) . '.' : 'Brak rezerwacji roboczych do zatwierdzenia.');
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$by_group = ti_room_reservation_filter_status($by_group, $status);
$total  = array_sum(array_map('count', $by_group));
$pending = 0;
foreach ($by_group as $rows) foreach ($rows as $r) $pending += $view === 'operator' ? (int)$r['pending'] : (($r['room_reservation_status'] ?? '') !== 'potwierdzone' ? 1 : 0);

ti_print_log_add('sale_rezerwacje', 'Wykaz sal do rezerwacji — ' . $from . ' – ' . $to, 0, 0, ['range' => $range, 'view' => $view], $me);

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
    <div class="btn-group btn-group-sm" role="group" aria-label="Widok">
      <a href="?range=<?= h($range) ?>&w=<?= h($from) ?>&view=day&status=<?= h($status) ?>" class="btn <?= $view==='day' ? 'btn-primary' : 'btn-outline-primary' ?>">Wg dnia</a>
      <a href="?range=<?= h($range) ?>&w=<?= h($from) ?>&view=operator&status=<?= h($status) ?>" class="btn <?= $view==='operator' ? 'btn-primary' : 'btn-outline-primary' ?>">Wg operatora</a>
    </div>
    <div class="btn-group btn-group-sm" role="group" aria-label="Status rezerwacji">
      <?php foreach (['all' => 'Wszystkie', 'do_rezerwacji' => 'Robocze (do rezerwacji)', 'potwierdzone' => 'Zatwierdzone'] as $_sk => $_sl): ?>
      <a href="?range=<?= h($range) ?>&w=<?= h($from) ?>&view=<?= h($view) ?>&status=<?= h($_sk) ?>" class="btn <?= $status===$_sk ? 'btn-primary' : 'btn-outline-primary' ?>"<?= $status===$_sk ? ' aria-current="true"' : '' ?>><?= h($_sl) ?></a>
      <?php endforeach; ?>
    </div>
    <select class="form-select form-select-sm" style="width:auto" aria-label="Zakres"
            onchange="location.href='?range='+this.value+'&w=<?= h(date('Y-m-d')) ?>&view=<?= h($view) ?>&status=<?= h($status) ?>'">
      <option value="week"    <?= $range==='week'    ? 'selected' : '' ?>>Tydzień</option>
      <option value="month"   <?= $range==='month'   ? 'selected' : '' ?>>Miesiąc</option>
      <option value="quarter" <?= $range==='quarter' ? 'selected' : '' ?>>3 miesiące</option>
    </select>
    <a href="?range=<?= h($range) ?>&w=<?= h($prev) ?>&view=<?= h($view) ?>&status=<?= h($status) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni okres"><i class="bi bi-chevron-left"></i></a>
    <span class="small fw-semibold"><?= h($range_label) ?></span>
    <a href="?range=<?= h($range) ?>&w=<?= h($next) ?>&view=<?= h($view) ?>&status=<?= h($status) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny okres"><i class="bi bi-chevron-right"></i></a>
    <a href="?range=<?= h($range) ?>&w=<?= h(date('Y-m-d')) ?>&view=<?= h($view) ?>&status=<?= h($status) ?>" class="btn btn-sm btn-outline-primary">Dziś</a>
    <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Drukuj</button>
    <a href="sale_rezerwacje_pdf.php?range=<?= h($range) ?>&w=<?= h($from) ?>&view=<?= h($view) ?>&status=<?= h($status) ?>" target="_blank" class="btn btn-sm btn-primary">
      <i class="bi bi-file-earmark-pdf me-1"></i>Pobierz PDF
    </a>
    <a href="sale_rezerwacje_export.php?format=docx&amp;range=<?= h($range) ?>&amp;w=<?= h($from) ?>&amp;view=<?= h($view) ?>&amp;status=<?= h($status) ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>Pobierz Word
    </a>
    <a href="sale_rezerwacje_export.php?format=xlsx&amp;range=<?= h($range) ?>&amp;w=<?= h($from) ?>&amp;view=<?= h($view) ?>&amp;status=<?= h($status) ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Pobierz Excel
    </a>
    <?php if ($pending > 0): ?>
    <form method="post" class="d-inline usos-noprint" onsubmit="return confirm('Zatwierdzić wszystkie rezerwacje robocze w tym okresie (<?= (int)$pending ?>)?')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="approve_drafts">
      <button class="btn btn-sm btn-success"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Zatwierdź wszystkie robocze (<?= (int)$pending ?>)</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<p class="text-body-secondary small usos-noprint">
  Terminy z przypisaną salą w wybranym okresie. Zaznacz „Potwierdzone" po zgłoszeniu rezerwacji do administracji budynku —
  status jest niezależny od statusu samej lekcji. Sale zarządzane w <a href="sale.php">wykazie sal</a>.
  <?php if ($view === 'operator'): ?>
  Widok „Wg operatora" grupuje po „nazwie zwyczajowej operatora przestrzeni" — do wydruku wysyłanego bezpośrednio
  do zewnętrznego operatora, pod nazwą, jaką on rozpoznaje.
  <?php endif; ?>
</p>

<?php if ($total): ?>
<div class="alert <?= $pending ? 'alert-warning' : 'alert-success' ?> py-2 small usos-noprint">
  <?= $total ?> termin(y/ów) z salą w wybranym okresie, w tym <strong><?= $pending ?></strong> jeszcze „Do rezerwacji".
</div>
<?php endif; ?>

<?php if (!$by_group): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak terminów z przypisaną salą w wybranym okresie.</div></div>
<?php endif; ?>

<?php foreach ($by_group as $group_key => $rows): ?>
<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
    <?php if ($view === 'operator'): ?>
      <i class="bi bi-building me-1 text-primary" aria-hidden="true"></i><?= h($group_key) ?>
    <?php else: $dow = (int)date('N', strtotime($group_key)); ?>
      <?= h(TI_DAYS_PL_FULL[$dow] ?? '') ?>, <?= h(date('d.m.Y', strtotime($group_key))) ?>
    <?php endif; ?>
    <span class="badge bg-secondary"><?= count($rows) ?></span>
    <?php if ($view === 'operator'): ?>
    <a href="sale_rezerwacje_pdf.php?range=<?= h($range) ?>&w=<?= h($from) ?>&view=operator&status=<?= h($status) ?>&operator=<?= urlencode($group_key) ?>"
       target="_blank" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2 ms-auto usos-noprint"
       title="PDF tylko dla tego operatora — bez rezerwacji pozostałych">
      <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF dla tego operatora
    </a>
    <a href="sale_rezerwacje_export.php?format=docx&amp;range=<?= h($range) ?>&amp;w=<?= h($from) ?>&amp;view=operator&amp;status=<?= h($status) ?>&amp;operator=<?= urlencode($group_key) ?>" class="btn btn-sm btn-outline-primary py-0 px-2 usos-noprint">Word<span class="visually-hidden"> dla operatora <?= h($group_key) ?></span></a>
    <a href="sale_rezerwacje_export.php?format=xlsx&amp;range=<?= h($range) ?>&amp;w=<?= h($from) ?>&amp;view=operator&amp;status=<?= h($status) ?>&amp;operator=<?= urlencode($group_key) ?>" class="btn btn-sm btn-outline-primary py-0 px-2 usos-noprint">Excel<span class="visually-hidden"> dla operatora <?= h($group_key) ?></span></a>
    <?php $_gp = array_sum(array_map(fn($x) => (int)$x['pending'], $rows)); if ($_gp > 0): ?>
    <form method="post" class="d-inline usos-noprint" onsubmit="return confirm('Zatwierdzić robocze rezerwacje operatora (<?= (int)$_gp ?>)?')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="approve_drafts">
      <input type="hidden" name="operator" value="<?= h($group_key) ?>">
      <button class="btn btn-sm btn-success py-0 px-2">Zatwierdź robocze<span class="visually-hidden"> operatora <?= h($group_key) ?></span> (<?= (int)$_gp ?>)</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <?php if ($view === 'operator'): ?>
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col">Dzień</th>
          <th scope="col" class="text-nowrap">Od</th>
          <th scope="col" class="text-nowrap">Do</th>
          <th scope="col" class="text-nowrap">Godziny</th>
          <th scope="col">Sala (nazwa operatora)</th>
          <th scope="col">Grupa</th>
          <th scope="col">Prowadzący</th>
          <th scope="col" class="text-center">Terminów</th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small"><?= h(TI_DAYS_PL_FULL[$r['dow']] ?? '') ?></td>
          <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime($r['date_from']))) ?></td>
          <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime($r['date_to']))) ?></td>
          <td class="text-nowrap small"><?= h($r['time_from']) ?>–<?= h($r['time_to']) ?></td>
          <td class="small"><?= h($r['room_customary']) ?></td>
          <td class="small"><?= h($r['course_name']) ?></td>
          <td class="small"><?= h($r['instructor_label']) ?></td>
          <td class="text-center"><span class="badge bg-secondary"><?= (int)$r['count'] ?></span></td>
          <td class="small">
            <?php if ($r['pending'] === 0): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-circle-fill me-1"></i>wszystkie potwierdzone</span>
            <?php else: ?>
            <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i><?= (int)$r['pending'] ?>/<?= (int)$r['count'] ?> do rezerwacji</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
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
        <?php foreach ($rows as $r): $confirmed = $r['room_reservation_status'] === 'potwierdzone'; ?>
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
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</main>
<style>@media print { .usos-noprint { display: none !important; } }</style>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
