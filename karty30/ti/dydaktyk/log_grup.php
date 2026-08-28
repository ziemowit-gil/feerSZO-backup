<?php
/**
 * karty30/ti/dydaktyk/log_grup.php — Log operacji na grupach (kierownik).
 *
 * Dziennik zdarzeń zapisywany przez ti_course_log() przy tworzeniu, edycji,
 * aktywacji/dezaktywacji oraz „Wyłącz i usuń grupę" / przywróceniu (patrz
 * includes/karty30.php: k30_ti_course_log, K30_TI_COURSE_LOG_ACTIONS).
 * Widok globalny (wszystkie grupy) z filtrem po jednej grupie (?course=).
 */
require_once __DIR__ . '/auth.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_course_log_migrate();

$lg_course_id = (int)($_GET['course'] ?? 0);
$lg_course    = $lg_course_id ? k30_ti_course_get($lg_course_id) : null;
$lg_rows      = ti_course_log_list($lg_course_id, 500);
$lg_courses   = k30_ti_courses(false, true);   // do selecta filtra — w tym anulowane

$KP_TITLE  = 'Log operacji na grupach — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'log_grup.php'; $KIER_LABEL = 'Log operacji na grupach';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Log operacji na grupach</h1>
    <p class="text-body-secondary small mb-0">Kto i kiedy utworzył, zmienił, wyłączył lub przywrócił grupę</p>
  </div>
  <div class="ms-auto" style="min-width:240px">
    <form method="get" class="d-flex gap-2">
      <select name="course" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="0">— wszystkie grupy —</option>
        <?php foreach ($lg_courses as $lc): ?>
        <option value="<?= (int)$lc['id'] ?>" <?= $lg_course_id === (int)$lc['id'] ? 'selected' : '' ?>>
          <?= h($lc['name']) ?><?= ($lc['status'] ?? '') === 'cancelled' ? ' (anulowana)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($lg_course_id): ?>
      <a href="log_grup.php" class="btn btn-sm btn-outline-secondary" title="Wyczyść filtr">
        <i class="bi bi-x-lg" aria-hidden="true"></i></a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?= flash_html() ?>

<?php if ($lg_course_id && $lg_course): ?>
<div class="alert alert-light border small d-flex align-items-center gap-2" role="note">
  <i class="bi bi-funnel text-primary flex-shrink-0" aria-hidden="true"></i>
  <span>Log wyłącznie grupy <strong><?= h($lg_course['name']) ?></strong>.</span>
  <a href="kurs.php?id=<?= $lg_course_id ?>" class="ms-auto btn btn-sm btn-outline-primary">
    <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Podgląd grupy</a>
</div>
<?php elseif ($lg_course_id && !$lg_course): ?>
<div class="alert alert-warning small">Grupa o podanym id nie istnieje — pokazano log wszystkich grup.</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Log operacji na grupach TI: data, grupa, operacja, kto wykonał, szczegóły</caption>
      <thead class="table-light">
        <tr>
          <th scope="col">Data</th>
          <?php if (!$lg_course_id): ?><th scope="col">Grupa</th><?php endif; ?>
          <th scope="col">Operacja</th>
          <th scope="col">Kto</th>
          <th scope="col">Szczegóły</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lg_rows): ?>
        <tr><td colspan="<?= $lg_course_id ? 4 : 5 ?>" class="text-center text-body-secondary py-4">Brak zapisanych operacji.</td></tr>
        <?php endif; ?>
        <?php foreach ($lg_rows as $r):
          $ract = K30_TI_COURSE_LOG_ACTIONS[$r['action']] ?? ['label' => $r['action'], 'icon' => 'dot', 'color' => 'secondary'];
        ?>
        <tr>
          <td class="text-nowrap small"><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
          <?php if (!$lg_course_id): ?>
          <td class="small">
            <?php if (!empty($r['course_id'])): ?>
            <a href="kurs.php?id=<?= (int)$r['course_id'] ?>"><?= h($r['course_name'] ?? ('#' . $r['course_id'])) ?></a>
            <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="text-nowrap">
            <span class="badge text-bg-<?= h($ract['color']) ?>">
              <i class="bi bi-<?= h($ract['icon']) ?> me-1" aria-hidden="true"></i><?= h($ract['label']) ?></span>
          </td>
          <td class="small text-nowrap"><?= h((string)($r['by_name'] ?: '—')) ?></td>
          <td class="small"><?= h((string)($r['detail'] ?: '')) ?: '<span class="text-body-secondary">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white small text-body-secondary">
    Ostatnie <?= count($lg_rows) ?> wpisów<?= $lg_course_id ? ' tej grupy' : ' ze wszystkich grup' ?>. Log zapisuje się automatycznie
    przy tworzeniu, edycji, aktywacji/dezaktywacji i operacji „Wyłącz i usuń grupę" / „Przywróć".
  </div>
</div>

</main>
<?php $PRINT_TITLE = 'Log operacji na grupach'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
