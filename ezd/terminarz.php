<?php
/**
 * Terminarz EZD — widok miesięczny terminów koszulek i dekretacji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$user_id = (int)current_user()['id'];
$today   = date('Y-m-d');
$year    = max(2020, min(2040, (int)($_GET['year']  ?? date('Y'))));
$month   = max(1, min(12,      (int)($_GET['month'] ?? date('m'))));
$view    = in_array($_GET['view'] ?? 'month', ['month','list']) ? ($_GET['view'] ?? 'month') : 'month';

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end   = date('Y-m-t', strtotime($month_start));
$prev = date('Y-m', strtotime('-1 month', strtotime($month_start)));
$next = date('Y-m', strtotime('+1 month', strtotime($month_start)));

// ── Pobierz zdarzenia ─────────────────────────────────────────────────────────

// 1. Koszulki z terminem w tym miesiącu (dostępne dla user)
$sprawy_events = db_all(
    "SELECT s.id, s.znak_sprawy, s.title, s.deadline, s.status, s.owner_id,
            u.name AS owner_name
     FROM ezd_sprawy s
     LEFT JOIN users u ON u.id=s.owner_id
     WHERE s.deadline >= ? AND s.deadline <= ?
       AND s.ciagla = 0
       AND (s.owner_id = ? OR s.created_by = ?
            OR EXISTS(SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?)
            OR EXISTS(SELECT 1 FROM role_permissions rp
                      JOIN users_roles ur ON ur.role_id=rp.role_id AND ur.user_id=?
                      WHERE rp.module='ezd' AND rp.can_read=1))
     ORDER BY s.deadline",
    [$month_start, $month_end, $user_id, $user_id, $user_id, $user_id]
);

// 2. Dekretacje z terminem w tym miesiącu dla tego użytkownika
$dekr_events = db_all(
    "SELECT d.id, d.deadline, d.opis, d.status, d.sprawa_id,
            s.znak_sprawy, s.title AS sprawa_title
     FROM ezd_dekretacje d
     LEFT JOIN ezd_sprawy s ON s.id=d.sprawa_id
     WHERE d.assigned_to = ? AND d.deadline >= ? AND d.deadline <= ?
     ORDER BY d.deadline",
    [$user_id, $month_start, $month_end]
);

// Indeksuj po dniu
$events_by_day = [];
foreach ($sprawy_events as $s) {
    $day = substr($s['deadline'], 0, 10);
    $events_by_day[$day][] = ['type' => 'sprawa', 'data' => $s];
}
foreach ($dekr_events as $d) {
    $day = substr($d['deadline'], 0, 10);
    $events_by_day[$day][] = ['type' => 'dekretacja', 'data' => $d];
}
ksort($events_by_day);

$PAGE_TITLE = 'Terminarz EZD — ' . sprintf('%s %04d', ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'][$month], $year);
include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:#e2e8f0; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
.cal-cell { background:#fff; min-height:90px; padding:6px 8px; position:relative; }
.cal-cell.other-month { background:#f8fafc; }
.cal-cell.today { background:#fffbeb; }
.cal-cell.today .cal-day-num { background:#f59e0b; color:#fff; border-radius:50%; width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center; }
.cal-day-num { font-size:.78rem; font-weight:700; color:#64748b; margin-bottom:4px; }
.cal-event { font-size:.65rem; line-height:1.2; border-radius:3px; padding:1px 4px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; display:block; text-decoration:none; }
.cal-event.sprawa { background:#dbeafe; color:#1d4ed8; }
.cal-event.sprawa.overdue { background:#fee2e2; color:#b91c1c; }
.cal-event.dekr { background:#d1fae5; color:#065f46; }
.cal-event.dekr.overdue { background:#fef3c7; color:#92400e; }
.cal-dow { text-align:center; font-size:.73rem; font-weight:700; color:#64748b; padding:6px 0; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
@media (max-width:600px) { .cal-cell { min-height:60px; } .cal-event { display:none; } .cal-cell .cal-dot { display:inline-block!important; } }
.cal-dot { display:none; width:6px;height:6px;border-radius:50%;background:#f59e0b;margin:1px; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Terminarz</li>
</ol></nav>

<?php flash_render(); ?>

<!-- Nagłówek nawigacja -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <a href="?year=<?= $year ?>&month=<?= $month ?>&view=<?= $view ?>&year=<?= date('Y',strtotime($prev)) ?>&month=<?= date('n',strtotime($prev)) ?>"
     class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
  <h5 class="mb-0 fw-semibold"><?= h($PAGE_TITLE) ?></h5>
  <a href="?year=<?= date('Y',strtotime($next)) ?>&month=<?= date('n',strtotime($next)) ?>&view=<?= $view ?>"
     class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
  <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>&view=<?= $view ?>"
     class="btn btn-outline-primary btn-sm ms-auto">Dzisiaj</a>
  <div class="btn-group btn-group-sm">
    <a href="?year=<?= $year ?>&month=<?= $month ?>&view=month" class="btn btn-<?= $view==='month'?'primary':'outline-secondary' ?>">Miesiąc</a>
    <a href="?year=<?= $year ?>&month=<?= $month ?>&view=list"  class="btn btn-<?= $view==='list' ?'primary':'outline-secondary' ?>">Lista</a>
  </div>
</div>

<?php if($view === 'month'): ?>
<!-- ════ Widok miesięczny ═════════════════════════════════════════════════════ -->
<div class="cal-grid mb-4">
  <?php foreach(['Pon','Wt','Śr','Czw','Pt','Sob','Nie'] as $d): ?>
  <div class="cal-dow"><?= $d ?></div>
  <?php endforeach; ?>

  <?php
  // Pierwszy dzień miesiąca → jaki dzień tygodnia (0=Pon)
  $first_dow = (date('N', strtotime($month_start)) - 1); // 0-Mon
  $days_in_month = (int)date('t', strtotime($month_start));
  $prev_month_days = (int)date('t', strtotime($prev . '-01'));

  // Puste komórki przed pierwszym dniem
  for ($i = 0; $i < $first_dow; $i++) {
      $pd = $prev_month_days - $first_dow + 1 + $i;
      $pdate = date('Y', strtotime($prev . '-01')) . '-' . str_pad(date('m', strtotime($prev . '-01')), 2,'0',STR_PAD_LEFT) . '-' . str_pad($pd, 2,'0',STR_PAD_LEFT);
      $pevents = $events_by_day[$pdate] ?? [];
      echo '<div class="cal-cell other-month"><div class="cal-day-num">' . $pd . '</div>';
      foreach(array_slice($pevents, 0, 2) as $ev) echo '<span class="cal-event ' . $ev['type'] . '">...</span>';
      echo '</div>';
  }

  // Dni miesiąca
  for ($d = 1; $d <= $days_in_month; $d++) {
      $date  = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $evs   = $events_by_day[$date] ?? [];
      $is_today = $date === $today;
      $classes = 'cal-cell' . ($is_today ? ' today' : '');
      echo '<div class="' . $classes . '">';
      echo '<div class="cal-day-num">' . $d . '</div>';
      $shown = 0;
      foreach ($evs as $ev) {
          $overdue = $date < $today ? ' overdue' : '';
          if ($ev['type'] === 'sprawa') {
              $zn = h($ev['data']['znak_sprawy']);
              $ti = h(mb_substr($ev['data']['title'] ?? '', 0, 22));
              echo '<a href="' . APP_URL . '/ezd/sprawy/view.php?id=' . $ev['data']['id'] . '" class="cal-event sprawa' . $overdue . '" title="' . $zn . ': ' . h($ev['data']['title'] ?? '') . '">'
                 . '<i class="bi bi-folder2 me-1"></i>' . $zn . ' ' . $ti . '</a>';
          } else {
              $ti = h(mb_substr($ev['data']['opis'] ?? $ev['data']['sprawa_title'] ?? '', 0, 22));
              echo '<a href="' . APP_URL . '/ezd/sprawy/view.php?id=' . $ev['data']['sprawa_id'] . '" class="cal-event dekr' . $overdue . '" title="Zadanie: ' . h($ev['data']['opis'] ?? '') . '">'
                 . '<i class="bi bi-person-check me-1"></i>' . $ti . '</a>';
          }
          $shown++;
          if ($shown >= 3 && count($evs) > 3) {
              echo '<span class="cal-event" style="color:#64748b;background:#f1f5f9">+' . (count($evs) - 3) . ' więcej</span>';
              break;
          }
      }
      echo '</div>';
  }

  // Puste komórki na końcu
  $last_dow = (int)date('N', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $days_in_month))); // 1-7
  $tail = 7 - $last_dow;
  for ($i = 1; $i <= $tail; $i++) {
      echo '<div class="cal-cell other-month"><div class="cal-day-num">' . $i . '</div></div>';
  }
  ?>
</div>

<?php else: ?>
<!-- ════ Widok lista ════════════════════════════════════════════════════════════ -->
<?php if(!$events_by_day): ?>
<div class="text-center text-muted py-5" style="font-size:.9rem">
  <i class="bi bi-calendar-check fs-2 d-block mb-2 opacity-25"></i>Brak terminów w tym miesiącu.
</div>
<?php endif; ?>

<?php foreach($events_by_day as $date => $evs): ?>
<?php $d_ts = strtotime($date); $overdue_day = $date < $today; ?>
<div class="card shadow-sm mb-2">
  <div class="card-header py-2 d-flex align-items-center gap-2 <?= $overdue_day ? 'bg-danger bg-opacity-10' : ($date===$today ? 'bg-warning bg-opacity-10' : '') ?>">
    <span class="fw-semibold" style="font-size:.88rem">
      <?= date('j', $d_ts) ?> <?= ['','Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota','Niedziela'][(int)date('N',$d_ts)] ?>
    </span>
    <?php if($overdue_day): ?><span class="badge bg-danger" style="font-size:.65rem">PRZETERMINOWANE</span><?php elseif($date===$today): ?><span class="badge bg-warning text-dark" style="font-size:.65rem">DZISIAJ</span><?php endif; ?>
    <span class="badge bg-secondary bg-opacity-15 text-secondary ms-auto" style="font-size:.68rem"><?= count($evs) ?> zdarzeni<?= count($evs)===1?'e':'a/??' ?></span>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach($evs as $ev): ?>
    <?php if($ev['type']==='sprawa'): $s=$ev['data']; ?>
    <li class="list-group-item d-flex align-items-start gap-2 py-2">
      <span class="badge bg-primary bg-opacity-15 text-primary flex-shrink-0 mt-1" style="font-size:.65rem">KOSZULKA</span>
      <div class="flex-grow-1" style="font-size:.83rem">
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="fw-semibold text-decoration-none">
          <span class="font-monospace"><?= h($s['znak_sprawy']) ?></span> — <?= h($s['title'] ?? '') ?>
        </a>
        <div class="text-muted" style="font-size:.72rem">Referent: <?= h($s['owner_name'] ?? '—') ?> · Status: <?= h($s['status'] ?? '') ?></div>
      </div>
    </li>
    <?php else: $dk=$ev['data']; ?>
    <li class="list-group-item d-flex align-items-start gap-2 py-2">
      <span class="badge bg-success bg-opacity-15 text-success flex-shrink-0 mt-1" style="font-size:.65rem">ZADANIE</span>
      <div class="flex-grow-1" style="font-size:.83rem">
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $dk['sprawa_id'] ?>" class="fw-semibold text-decoration-none">
          <span class="font-monospace"><?= h($dk['znak_sprawy']) ?></span> — <?= h($dk['opis'] ?? $dk['sprawa_title'] ?? '') ?>
        </a>
        <div class="text-muted" style="font-size:.72rem">Status zadania: <?= h($dk['status'] ?? '') ?></div>
      </div>
    </li>
    <?php endif; ?>
    <?php endforeach; ?>
  </ul>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
