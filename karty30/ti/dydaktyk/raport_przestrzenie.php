<?php
/**
 * karty30/ti/dydaktyk/raport_przestrzenie.php — Raport przestrzeni (panel kierownika).
 *
 * Dwa widoki:
 *   - Per przestrzeń (?room=ID): dedykowany raport JEDNEJ sali — harmonogram
 *     zajęć w zadanym zakresie dat + podsumowanie (liczba lekcji, godzin, grup).
 *   - Per budynek (domyślny, bez ?room=): zbiorcze zestawienie wszystkich
 *     budynków z ich salami i obłożeniem — sale zdalne (mode_support='remote')
 *     są wyraźnie oznaczone i NIE liczą się do sum fizycznego obłożenia
 *     (patrz includes/ti_room_reports.php, ta sama zasada co harmonogram_lokalizacje.php).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';

karty30_migrate();
ti_planner_ext_migrate();

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }

$room_id = (int)($_GET['room'] ?? 0);
$weeks   = max(1, min(52, (int)($_GET['weeks'] ?? 8)));
$from    = date('Y-m-d', strtotime('-1 week'));
$to      = date('Y-m-d', strtotime("+{$weeks} weeks"));

$rooms     = pl_rooms_list();
$buildings = pl_buildings_list();

$KP_TITLE  = 'Raport przestrzeni — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'sale.php'; $KIER_LABEL = 'Raport przestrzeni';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-geo-alt me-2 text-primary"></i>Raport przestrzeni</h1>
  <a href="sale.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-list-ul me-1"></i>Sale / lokalizacje</a>
</div>

<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-4">
  <div>
    <label class="form-label small mb-1">Przestrzeń (sala)</label>
    <select class="form-select form-select-sm" name="room" style="min-width:260px" onchange="this.form.submit()">
      <option value="0">— zestawienie budynków (wszystkie sale) —</option>
      <?php foreach ($rooms as $r): ?>
      <option value="<?= (int)$r['id'] ?>" <?= $room_id === (int)$r['id'] ? 'selected' : '' ?>>
        <?= h($r['name']) ?><?= trim((string)($r['building_name'] ?? '')) !== '' ? ' — ' . h($r['building_name']) : '' ?>
        <?= $r['mode_support'] === 'remote' ? ' (zdalna)' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1">Zakres (tygodnie w przód)</label>
    <input type="number" class="form-control form-control-sm" name="weeks" value="<?= $weeks ?>" min="1" max="52" style="width:100px">
  </div>
  <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Odśwież</button>
</form>

<?php if ($room_id > 0):
    $room = null;
    foreach ($rooms as $r) { if ((int)$r['id'] === $room_id) { $room = $r; break; } }
    if (!$room) { echo '<div class="alert alert-danger">Nie znaleziono sali.</div>'; include dirname(__DIR__) . '/kursant/_layout_foot.php'; exit; }

    $sessions = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status,
                c.name AS course_name, u.name AS instr_name, cu.name AS course_instr_name
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN users u  ON u.id = s.instructor_id
           LEFT JOIN users cu ON cu.id = c.instructor_id
          WHERE s.room_id = ? AND s.lesson_date BETWEEN ? AND ? AND s.status NOT IN ('cancelled')
          ORDER BY s.lesson_date, s.time_from",
        [$room_id, $from, $to]
    );
    $total_min = 0; $courses_seen = [];
    foreach ($sessions as $s) { $total_min += (int)$s['duration_min']; $courses_seen[$s['course_name']] = true; }
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-body-tertiary">
    <i class="bi bi-geo-alt-fill me-1 text-primary"></i><?= h($room['name']) ?>
    <?php if (trim((string)($room['operator_label'] ?? '')) !== ''): ?>
    <span class="text-body-secondary fw-normal small">(„<?= h($room['operator_label']) ?>")</span>
    <?php endif; ?>
    <?php if ($room['mode_support'] === 'remote'): ?>
    <span class="badge text-bg-info ms-1">sala zdalna</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-auto"><div class="text-body-secondary small">Budynek</div><div class="fw-semibold"><?= trim((string)($room['building_name'] ?? '')) !== '' ? h($room['building_name']) : '—' ?></div></div>
      <div class="col-auto"><div class="text-body-secondary small">Lekcje w zakresie</div><div class="fw-semibold"><?= count($sessions) ?></div></div>
      <div class="col-auto"><div class="text-body-secondary small">Łączny czas</div><div class="fw-semibold"><?= round($total_min / 60, 1) ?> h</div></div>
      <div class="col-auto"><div class="text-body-secondary small">Różnych grup</div><div class="fw-semibold"><?= count($courses_seen) ?></div></div>
    </div>
    <?php if (!$sessions): ?>
    <div class="text-body-secondary small">Brak lekcji w tej sali w wybranym zakresie dat.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Godziny</th><th>Grupa</th><th>Prowadzący</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($sessions as $s): ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d.m.Y', strtotime((string)$s['lesson_date']))) ?></td>
            <td class="text-nowrap small"><?= h(substr((string)$s['time_from'], 0, 5)) ?>–<?= h(substr((string)$s['time_to'], 0, 5)) ?></td>
            <td class="small"><?= h($s['course_name']) ?></td>
            <td class="small"><?= h($s['instr_name'] ?: $s['course_instr_name'] ?: '—') ?></td>
            <td class="small"><?= h($s['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: /* ── Zestawienie budynków ─────────────────────────────────────── */
    $rooms_by_building = [];
    foreach ($rooms as $r) { $rooms_by_building[(int)($r['building_id'] ?: 0)][] = $r; }

    // Obłożenie per sala w zakresie dat, żeby nie liczyć całej historii.
    $counts = [];
    foreach (db_all(
        "SELECT room_id, COUNT(*) AS n FROM k30_ti_sessions
          WHERE room_id IS NOT NULL AND lesson_date BETWEEN ? AND ? AND status NOT IN ('cancelled')
          GROUP BY room_id",
        [$from, $to]
    ) as $c) { $counts[(int)$c['room_id']] = (int)$c['n']; }
?>
<?php foreach ($buildings as $b):
    $brooms = $rooms_by_building[(int)$b['id']] ?? [];
    if (!$brooms) continue;
    $phys = array_filter($brooms, fn($r) => $r['mode_support'] !== 'remote');
    $b_total = array_sum(array_map(fn($r) => $counts[(int)$r['id']] ?? 0, $phys));
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-body-tertiary d-flex align-items-center gap-2">
    <i class="bi bi-building me-1 text-primary"></i><?= h($b['name']) ?>
    <span class="badge bg-secondary"><?= count($phys) ?> <?= count($phys) === 1 ? 'sala' : 'sal' ?></span>
    <span class="text-body-secondary small ms-auto"><?= $b_total ?> lekcji w zakresie (fizyczne sale)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Sala</th><th class="text-center">Pojemność</th><th>Tryb</th><th class="text-center">Lekcje w zakresie</th></tr></thead>
      <tbody>
        <?php foreach ($brooms as $r): ?>
        <tr class="<?= $r['mode_support'] === 'remote' ? 'text-body-secondary' : '' ?>">
          <td>
            <a href="?room=<?= (int)$r['id'] ?>&weeks=<?= $weeks ?>"><?= h($r['name']) ?></a>
            <?php if ($r['mode_support'] === 'remote'): ?><span class="badge text-bg-info ms-1">zdalna</span><?php endif; ?>
          </td>
          <td class="text-center"><?= (int)$r['capacity'] ?></td>
          <td class="small"><?= h(['onsite'=>'stacjonarny','remote'=>'zdalny','hybrid'=>'hybrydowy','all'=>'dowolny'][$r['mode_support']] ?? $r['mode_support']) ?></td>
          <td class="text-center"><?= $r['mode_support'] === 'remote' ? '<span class="text-muted">—</span>' : ($counts[(int)$r['id']] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php $no_building = $rooms_by_building[0] ?? []; if ($no_building): $phys0 = array_filter($no_building, fn($r) => $r['mode_support'] !== 'remote'); ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-body-tertiary d-flex align-items-center gap-2">
    <i class="bi bi-geo-alt me-1 text-muted"></i>Bez przypisanego budynku
    <span class="badge bg-secondary"><?= count($phys0) ?> <?= count($phys0) === 1 ? 'sala' : 'sal' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Sala</th><th class="text-center">Pojemność</th><th>Tryb</th><th class="text-center">Lekcje w zakresie</th></tr></thead>
      <tbody>
        <?php foreach ($no_building as $r): ?>
        <tr class="<?= $r['mode_support'] === 'remote' ? 'text-body-secondary' : '' ?>">
          <td>
            <a href="?room=<?= (int)$r['id'] ?>&weeks=<?= $weeks ?>"><?= h($r['name']) ?></a>
            <?php if ($r['mode_support'] === 'remote'): ?><span class="badge text-bg-info ms-1">zdalna</span><?php endif; ?>
          </td>
          <td class="text-center"><?= (int)$r['capacity'] ?></td>
          <td class="small"><?= h(['onsite'=>'stacjonarny','remote'=>'zdalny','hybrid'=>'hybrydowy','all'=>'dowolny'][$r['mode_support']] ?? $r['mode_support']) ?></td>
          <td class="text-center"><?= $r['mode_support'] === 'remote' ? '<span class="text-muted">—</span>' : ($counts[(int)$r['id']] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php if (!$rooms): ?>
<div class="text-body-secondary"><i class="bi bi-info-circle me-1"></i>Brak zdefiniowanych sal. Dodaj je w <a href="sale.php">Sale / lokalizacje</a>.</div>
<?php endif; ?>
<?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
