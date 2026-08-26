<?php
/**
 * karty30/ti/zoom_calendar.php — kalendarz zajętości konta Zoom (administracja).
 *
 * Pokazuje to, na czym opiera się blokada ustawiania zajęć zdalnych: lekcje SZO
 * korzystające z Zooma oraz spotkania z terminem na koncie hosta (API Zoom).
 * Ten sam widok w panelu dydaktyka: zakładka „Zajętość Zoom".
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_zoom_calendar.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Zajętość Zoom — TI';

// Miesiąc z URL (Y-m), domyślnie bieżący
$month = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$first = strtotime($month . '-01');
$from  = date('Y-m-01', $first);
$to    = date('Y-m-t',  $first);
$prev  = date('Y-m', strtotime($from . ' -1 month'));
$next  = date('Y-m', strtotime($from . ' +1 month'));

$months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$month_label = $months_pl[(int)date('n', $first)] . ' ' . date('Y', $first);

$busy      = ti_zoom_busy_range($from, $to);
$n_slots   = array_sum(array_map('count', $busy['days']));
$n_busy_dt = count($busy['days']);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Zajętość Zoom</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-camera-video text-primary me-2" aria-hidden="true"></i>Zajętość konta Zoom</h4>
  <a href="online_admin.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia Zoom</a>
</div>

<?= flash_html() ?>

<?php if (!zoom_enabled()): ?>
<div class="alert alert-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Integracja Zoom jest wyłączona — sprawdzanie zajętości i blokada terminów nie działają.
  Włącz ją w <a href="online_admin.php">ustawieniach Nauki online</a>.
</div>
<?php else: ?>

<?php if (!$busy['ok']): ?>
<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
  API Zoom nie odpowiedziało (<?= h($busy['error']) ?>). Kalendarz pokazuje tylko lekcje zaplanowane w SZO —
  spotkania utworzone poza systemem mogą być w tym czasie nieznane.
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex align-items-center flex-wrap gap-2">
        <a href="?m=<?= h($prev) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
        <span class="fw-semibold"><?= h(mb_strtoupper(mb_substr($month_label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($month_label, 1, null, 'UTF-8')) ?></span>
        <a href="?m=<?= h($next) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Następny miesiąc"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        <a href="?m=<?= h(date('Y-m')) ?>" class="btn btn-sm btn-outline-primary">Dziś</a>
        <span class="ms-auto small text-body-secondary">
          <?= (int)$n_slots ?> zajętych terminów w <?= (int)$n_busy_dt ?> dniach
        </span>
      </div>
      <div class="card-body">
        <?= ti_zoom_calendar_month_html($month, $busy['days']) ?>
        <?= ti_zoom_calendar_legend_html() ?>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <?= ti_zoom_explain_html() ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>Lista terminów</div>
      <div class="card-body p-0">
        <?php if (!$busy['days']): ?>
        <div class="p-3 text-muted small">Brak zajętych terminów w tym miesiącu.</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:520px;overflow:auto">
          <table class="table table-sm mb-0">
            <caption class="visually-hidden">Zajęte terminy konta Zoom w miesiącu, chronologicznie</caption>
            <thead class="table-light"><tr><th scope="col">Dzień</th><th scope="col">Godziny</th><th scope="col">Opis</th></tr></thead>
            <tbody>
              <?php foreach ($busy['days'] as $d => $slots): foreach ($slots as $i => $s): ?>
              <tr>
                <td class="text-nowrap small"><?= $i === 0 ? h(date('d.m', strtotime($d))) . ' <span class="text-body-secondary">' . h(['Nd','Pn','Wt','Śr','Cz','Pt','Sb'][date('w', strtotime($d))]) . '</span>' : '' ?></td>
                <td class="text-nowrap small"><?= h(ti_zoom_slot_hours($s)) ?></td>
                <td class="small">
                  <span class="badge <?= $s['src'] === 'szo' ? 'text-bg-primary' : 'text-bg-dark' ?>" style="font-size:.6rem"><?= $s['src'] === 'szo' ? 'SZO' : 'Zoom' ?></span>
                  <?= h(ti_zoom_slot_label($s)) ?>
                </td>
              </tr>
              <?php endforeach; endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
