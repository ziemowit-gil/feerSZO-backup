<?php
/**
 * karty30/ti/dydaktyk/planner.php — Planner tygodniowy SZO (strona samodzielna).
 *
 * Wydzielony z panelu dydaktyka; auth i layout taki sam jak index.php.
 * Zmienne przekazywane do _tab_planner.php: $uid, $courses, $my_avail, dyd_token().
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner.php';

karty30_migrate();

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$courses = dyd_courses($uid);
$my_avail = ti_instructor_availability($uid);

// $cur_course potrzebny tylko dla linku "Zajęcia" w globalbarze
$course_ids = array_map(fn($c) => (int)$c['id'], $courses);
$cur_course = (int)($_GET['course'] ?? 0);
if (!in_array($cur_course, $course_ids, true)) $cur_course = $course_ids[0] ?? 0;

$KP_TITLE  = 'Planner — Panel dydaktyka';
$KP_TOPBAR = [
    'brand'  => 'Panel dydaktyka',
    'icon'   => 'easel2',
    'user'   => $me['name'] ?? '',
    'logout' => 'logout.php',
];
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<style>
  .dyd-wrap { max-width:100%; }
  .dyd-globalbar { background:var(--bs-body-bg); border-bottom:2px solid var(--bs-border-color); padding:.3rem 1rem; display:flex; align-items:center; gap:.25rem; flex-wrap:wrap; }
  .dyd-globalbar .dyd-gb-link { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .85rem; border-radius:6px; font-size:.88rem; font-weight:600; color:var(--bs-body-color); text-decoration:none; border:1.5px solid transparent; transition:background .12s,color .12s; min-height:40px; }
  .dyd-globalbar .dyd-gb-link:hover { background:var(--bs-tertiary-bg); border-color:var(--bs-border-color); }
  .dyd-globalbar .dyd-gb-link.active { background:#dbeafe; color:#1d4ed8; border-color:#93c5fd; font-weight:700; }
</style>

<nav class="dyd-globalbar" aria-label="Menu dydaktyka">
  <a class="dyd-gb-link" href="index.php?course=<?= $cur_course ?>&tab=lekcje">
    <i class="bi bi-pc-display" aria-hidden="true"></i>Zajęcia
    <?php if ($courses): ?>
    <span class="badge bg-secondary" style="font-size:.65rem"><?= count($courses) ?> gr.</span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link" href="index.php?tab=formalnosci">
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Formalności
  </a>
  <a class="dyd-gb-link" href="index.php?tab=dostepnosc">
    <i class="bi bi-clock-history" aria-hidden="true"></i>Dostępność
  </a>
  <a class="dyd-gb-link" href="index.php?tab=cykliczne">
    <i class="bi bi-calendar-week" aria-hidden="true"></i>Plan cykliczny
  </a>
  <a class="dyd-gb-link" href="index.php?tab=wiadomosci">
    <i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości
  </a>
  <a class="dyd-gb-link" href="index.php?tab=komunikaty">
    <i class="bi bi-megaphone" aria-hidden="true"></i>Komunikaty
  </a>
  <a class="dyd-gb-link" href="index.php?tab=dysk">
    <i class="bi bi-hdd-network" aria-hidden="true"></i>Mój dysk
  </a>
  <a class="dyd-gb-link active" href="planner.php" aria-current="page">
    <i class="bi bi-calendar3-week" aria-hidden="true"></i>Planner
  </a>
</nav>

<main id="main" class="container dyd-wrap py-4">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-calendar3-week text-primary me-2" aria-hidden="true"></i>Planner tygodniowy
    </h1>
    <a href="index.php?course=<?= $cur_course ?>&tab=lekcje" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Panel dydaktyka
    </a>
  </div>

  <?= flash_html() ?>

  <?php include __DIR__ . '/_tab_planner.php'; ?>

</main>

<?php $PRINT_TITLE = 'SZO Planner — harmonogram zajęć'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
