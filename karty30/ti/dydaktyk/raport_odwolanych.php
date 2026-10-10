<?php
/**
 * karty30/ti/dydaktyk/raport_odwolanych.php — Raport odwołanych lekcji (kierownik).
 * Dwa rodzaje: odwołane lekcje (status „odwołana") oraz usunięte do kosza (w tym odwołane z powodu błędu).
 * GET: od, do (YYYY-MM-DD), csv=1 (eksport CSV).
 */
require_once __DIR__ . '/auth.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_session_trash_migrate();

$valid_date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$from = $valid_date($_GET['od'] ?? null) ? $_GET['od'] : date('Y-m-01');
$to   = $valid_date($_GET['do'] ?? null) ? $_GET['do'] : date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }

$cancelled = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.cancel_reason, s.cancelled_by_role, s.cancelled_by, s.cancelled_at,
            c.name AS course_name
       FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
      WHERE s.status = 'cancelled' AND s.lesson_date BETWEEN ? AND ?
      ORDER BY s.lesson_date, s.time_from",
    [$from, $to]
);
$trashed = db_all(
    "SELECT t.id, t.lesson_date, t.reason, t.deleted_at, t.deleted_by_name, t.snapshot, c.name AS course_name
       FROM k30_ti_session_trash t JOIN k30_ti_courses c ON c.id = t.course_id
      WHERE t.restored_at IS NULL AND t.lesson_date BETWEEN ? AND ?
      ORDER BY t.lesson_date, t.id",
    [$from, $to]
);
$trash_time = function (string $snap): string {
    $s = json_decode($snap, true);
    return (string)($s['session']['time_from'] ?? '');
};

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="odwolane_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Rodzaj', 'Data', 'Godzina', 'Grupa', 'Powód', 'Kto', 'Kiedy'], ';');
    foreach ($cancelled as $r) {
        fputcsv($out, ['Odwołana', $r['lesson_date'], substr((string)$r['time_from'], 0, 5), $r['course_name'], $r['cancel_reason'], $r['cancelled_by'] ?: $r['cancelled_by_role'], $r['cancelled_at']], ';');
    }
    foreach ($trashed as $r) {
        fputcsv($out, ['Usunięta (kosz)', $r['lesson_date'], $trash_time((string)$r['snapshot']), $r['course_name'], $r['reason'], $r['deleted_by_name'], $r['deleted_at']], ';');
    }
    fclose($out);
    exit;
}

$KP_TITLE  = 'Raport odwołanych lekcji — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'raport_odwolanych.php'; $KIER_LABEL = 'Raport odwołanych'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-calendar-x me-2 text-danger"></i>Raport odwołanych lekcji</h1>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end ms-auto">
    <div><label class="form-label small mb-0" for="ro_od">Od</label><input type="date" class="form-control form-control-sm" id="ro_od" name="od" value="<?= h($from) ?>"></div>
    <div><label class="form-label small mb-0" for="ro_do">Do</label><input type="date" class="form-control form-control-sm" id="ro_do" name="do" value="<?= h($to) ?>"></div>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Pokaż</button>
    <a class="btn btn-sm btn-outline-primary" href="?od=<?= h($from) ?>&do=<?= h($to) ?>&csv=1"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
  </form>
</div>

<div class="card mb-4">
  <div class="card-header"><strong>Odwołane lekcje</strong> <span class="badge bg-secondary ms-1"><?= count($cancelled) ?></span></div>
  <div class="card-body p-0">
    <?php if (!$cancelled): ?>
      <div class="p-3 text-body-secondary small">Brak odwołanych lekcji w tym okresie.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Data</th><th>Godz.</th><th>Grupa</th><th>Powód</th><th>Kto</th><th>Kiedy odwołano</th></tr></thead>
      <tbody>
      <?php foreach ($cancelled as $r): ?>
        <tr>
          <td><?= h(date('d.m.Y', strtotime((string)$r['lesson_date']))) ?></td>
          <td><?= h(substr((string)$r['time_from'], 0, 5)) ?></td>
          <td><?= h((string)$r['course_name']) ?></td>
          <td class="small"><?= h((string)$r['cancel_reason']) ?></td>
          <td class="small"><?= h((string)($r['cancelled_by'] ?: $r['cancelled_by_role'])) ?></td>
          <td class="small"><?= h((string)$r['cancelled_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Usunięte do kosza</strong> <span class="badge bg-secondary ms-1"><?= count($trashed) ?></span>
    <span class="small text-body-secondary ms-2">w tym odwołane z powodu błędu — nie liczą się do obecności ani statystyk</span></div>
  <div class="card-body p-0">
    <?php if (!$trashed): ?>
      <div class="p-3 text-body-secondary small">Brak usuniętych lekcji w tym okresie.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Data</th><th>Godz.</th><th>Grupa</th><th>Powód</th><th>Kto</th><th>Kiedy usunięto</th></tr></thead>
      <tbody>
      <?php foreach ($trashed as $r): ?>
        <tr>
          <td><?= h(date('d.m.Y', strtotime((string)$r['lesson_date']))) ?></td>
          <td><?= h(substr($trash_time((string)$r['snapshot']), 0, 5)) ?></td>
          <td><?= h((string)$r['course_name']) ?></td>
          <td class="small"><?= h((string)$r['reason']) ?></td>
          <td class="small"><?= h((string)$r['deleted_by_name']) ?></td>
          <td class="small"><?= h((string)$r['deleted_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
