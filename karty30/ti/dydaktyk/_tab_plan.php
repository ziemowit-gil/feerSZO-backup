<?php /* ═══════════════════════ TAB: PLAN ZAJĘĆ — siatka tygodnia + terminy (widok USOS) ═══════════════════════ */ ?>
<?php
/**
 * Plan zajęć kursu: siatka tygodniowa (poniedziałek–niedziela) i lista terminów
 * miesiąca — odpowiednik „Planu zajęć” w USOSweb.
 *
 * Zmienne z index.php: $cur_course, $course.
 */
$pl_week = (string)($_GET['w'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pl_week)) $pl_week = date('Y-m-d');
$pl_mon  = date('Y-m-d', strtotime('monday this week', strtotime($pl_week)));
$pl_sun  = date('Y-m-d', strtotime($pl_mon . ' +6 days'));
$pl_prev = date('Y-m-d', strtotime($pl_mon . ' -7 days'));
$pl_next = date('Y-m-d', strtotime($pl_mon . ' +7 days'));

$pl_rows = db_all(
    "SELECT s.*, r.name AS room_name, r.location AS room_location
       FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
      WHERE s.course_id = ? AND s.lesson_date BETWEEN ? AND ?
      ORDER BY s.lesson_date, s.time_from",
    [$cur_course, $pl_mon, $pl_sun]
);
$pl_by_day = [];
foreach ($pl_rows as $r) $pl_by_day[(string)$r['lesson_date']][] = $r;

// Lista terminów: bieżący miesiąc od poniedziałku tygodnia
$pl_m_from = date('Y-m-01', strtotime($pl_mon));
$pl_m_to   = date('Y-m-t',  strtotime($pl_mon));
$pl_month  = db_all(
    "SELECT s.*, r.name AS room_name, r.location AS room_location
       FROM k30_ti_sessions s LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
      WHERE s.course_id = ? AND s.lesson_date BETWEEN ? AND ?
      ORDER BY s.lesson_date, s.time_from",
    [$cur_course, $pl_m_from, $pl_m_to]
);

$pl_status_badge = [
    'planned'           => ['secondary', 'zaplanowana'],
    'held'              => ['success',   'odbyta'],
    'individual_change' => ['info',      'zmiana indywidualna'],
    'remote_material'   => ['primary',   'praca własna'],
    'cancelled'         => ['danger',    'odwołana'],
    'draft'             => ['light',     'szkic'],
];
$pl_method = ['stacjonarna' => 'stacjonarna', 'zdalna_zoom' => 'Zoom', 'zdalna_inne' => 'zdalna'];
$pl_days   = ['Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Plan zajęć — <?= h($course['name']) ?></h1>
  <div class="ms-auto d-flex gap-2 align-items-center usos-noprint">
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=plan&w=<?= h($pl_prev) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni tydzień"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
    <span class="small fw-semibold"><?= h(date('d.m', strtotime($pl_mon))) ?>–<?= h(date('d.m.Y', strtotime($pl_sun))) ?></span>
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=plan&w=<?= h($pl_next) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny tydzień"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=plan&w=<?= h(date('Y-m-d')) ?>" class="btn btn-sm btn-outline-primary">Bieżący tydzień</a>
  </div>
</div>

<div class="card">
  <div class="card-header">Tydzień <?= h(date('d.m.Y', strtotime($pl_mon))) ?> – <?= h(date('d.m.Y', strtotime($pl_sun))) ?></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0 usos-week">
        <caption class="visually-hidden">Siatka tygodnia z terminami zajęć kursu <?= h($course['name']) ?></caption>
        <thead><tr>
          <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($pl_mon . " +$i days")); ?>
          <th scope="col" class="text-center small<?= $d === date('Y-m-d') ? ' table-warning' : '' ?>">
            <?= h($pl_days[$i]) ?><br><span class="fw-normal"><?= h(date('d.m', strtotime($d))) ?></span>
          </th>
          <?php endfor; ?>
        </tr></thead>
        <tbody><tr>
          <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($pl_mon . " +$i days")); $day = $pl_by_day[$d] ?? []; ?>
          <td<?= $d === date('Y-m-d') ? ' class="table-warning"' : '' ?>>
            <?php if (!$day): ?><span class="text-body-secondary small">—</span><?php endif; ?>
            <?php foreach ($day as $s):
              $bad = $pl_status_badge[(string)$s['status']] ?? ['secondary', (string)$s['status']];
              $dfl = K30_TI_DATE_FLAGS[(string)($s['date_flag'] ?? '')] ?? null;
            ?>
            <span class="usos-slot">
              <strong><?= h(substr((string)$s['time_from'], 0, 5)) ?><?= $s['time_to'] ? '–' . h(substr((string)$s['time_to'], 0, 5)) : '' ?></strong>
              <?php if ($dfl && (string)($s['date_flag'] ?? '') !== ''): ?>
              <span class="badge <?= h($dfl['class']) ?>" title="<?= h($dfl['label']) ?>"><?= h($dfl['badge']) ?></span>
              <?php endif; ?>
              <?php if (trim((string)$s['topic']) !== ''): ?><br><?= h(mb_strimwidth((string)$s['topic'], 0, 40, '…', 'UTF-8')) ?><?php endif; ?>
              <?php if (!empty($s['lesson_method'])): ?><br><span class="text-body-secondary"><?= h($pl_method[(string)$s['lesson_method']] ?? (string)$s['lesson_method']) ?></span><?php endif; ?>
              <?php if (!empty($s['room_name'])): ?><br><span class="text-body-secondary"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= h($s['room_name']) ?></span><?php endif; ?>
              <?php if ((string)$s['status'] !== 'planned'): ?><br><span class="badge text-bg-<?= h($bad[0]) ?>"><?= h($bad[1]) ?></span><?php endif; ?>
            </span>
            <?php endforeach; ?>
          </td>
          <?php endfor; ?>
        </tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Terminy w miesiącu: <?= h(date('m.Y', strtotime($pl_mon))) ?> <span class="badge bg-secondary ms-1"><?= count($pl_month) ?></span></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <caption class="visually-hidden">Terminy zajęć w miesiącu, chronologicznie</caption>
      <thead><tr>
        <th scope="col">Data</th>
        <th scope="col" class="text-nowrap">Godziny</th>
        <th scope="col">Temat</th>
        <th scope="col">Forma</th>
        <th scope="col">Sala / lokalizacja</th>
        <th scope="col">Status</th>
      </tr></thead>
      <tbody>
        <?php if (!$pl_month): ?>
        <tr><td colspan="6" class="text-center text-muted py-3">Brak terminów w tym miesiącu.</td></tr>
        <?php endif; ?>
        <?php foreach ($pl_month as $s):
          $bad = $pl_status_badge[(string)$s['status']] ?? ['secondary', (string)$s['status']];
          $dfl = K30_TI_DATE_FLAGS[(string)($s['date_flag'] ?? '')] ?? null;
        ?>
        <tr>
          <td class="text-nowrap small">
            <?= h(date('d.m.Y', strtotime((string)$s['lesson_date']))) ?>
            <span class="text-body-secondary"><?= h($pl_days[(int)date('N', strtotime((string)$s['lesson_date'])) - 1]) ?></span>
          </td>
          <td class="text-nowrap small"><?= h(substr((string)$s['time_from'], 0, 5)) ?><?= $s['time_to'] ? '–' . h(substr((string)$s['time_to'], 0, 5)) : '' ?></td>
          <td class="small"><?= trim((string)$s['topic']) !== '' ? h($s['topic']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= !empty($s['lesson_method']) ? h($pl_method[(string)$s['lesson_method']] ?? (string)$s['lesson_method']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= !empty($s['room_name']) ? h($s['room_name']) . (trim((string)($s['room_location'] ?? '')) !== '' ? ' <span class="text-body-secondary">(' . h($s['room_location']) . ')</span>' : '') : '<span class="text-muted">—</span>' ?></td>
          <td class="small">
            <span class="badge text-bg-<?= h($bad[0]) ?>"><?= h($bad[1]) ?></span>
            <?php if ($dfl && (string)($s['date_flag'] ?? '') !== ''): ?>
            <span class="badge <?= h($dfl['class']) ?>" title="<?= h($dfl['label']) ?>"><?= h($dfl['badge']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small text-body-secondary mt-2 usos-noprint">
  Terminy dodaje się i zmienia w zakładce <a href="index.php?course=<?= (int)$cur_course ?>&tab=lekcje">Zajęcia</a>;
  ten widok jest zestawieniem. Zajęcia zdalne przez Zoom podlegają zajętości konta —
  patrz <a href="index.php?tab=zoom">Zajętość Zoom</a>.
</p>
