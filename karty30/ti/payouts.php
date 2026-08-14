<?php
/**
 * karty30/ti/payouts.php — Miesięczne sumy wypłat per prowadzący (TI).
 * Liczy lekcje odbyte (status='held') × stała kwota brutto-brutto kursu,
 * z rozbiciem na składki / podatek / netto wg konfigurowalnych stawek.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();
if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }

$PAGE_TITLE = 'Wypłaty prowadzących — TI';

// Miesiąc (YYYY-MM) — domyślnie bieżący
$ym = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$next = date('Y-m', strtotime($ym . '-01 +1 month'));
$_msc = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$ym_label = ($_msc[(int)substr($ym,5,2)] ?? '') . ' ' . substr($ym,0,4);

$rows = k30_ti_payouts_by_instructor($ym);

// Sumy zbiorcze
$tot = _k30_ti_payout_zero();
foreach ($rows as $r) {
    foreach (['lessons','brutto_brutto','zus_employer','brutto','skladki','pit','netto'] as $k) $tot[$k] += $r[$k];
}
$f = fn($x) => number_format((float)$x, 2, ',', ' ');

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Wypłaty prowadzących</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-wallet2 text-primary me-2"></i>Wypłaty prowadzących</h4>
  <form method="get" class="ms-auto d-flex align-items-center gap-1">
    <a href="?m=<?= h($prev) ?>" class="btn btn-outline-secondary btn-sm" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left"></i></a>
    <input type="month" name="m" value="<?= h($ym) ?>" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
    <a href="?m=<?= h($next) ?>" class="btn btn-outline-secondary btn-sm" aria-label="Następny miesiąc"><i class="bi bi-chevron-right"></i></a>
  </form>
</div>

<div class="text-body-secondary mb-3"><i class="bi bi-calendar3 me-1"></i><?= h(ucfirst($ym_label)) ?> · uwzględniono lekcje odbyte z kursów z ustaloną stawką brutto-brutto.</div>

<?php if (!$rows): ?>
<div class="alert alert-light border"><i class="bi bi-info-circle me-1"></i>Brak odbytych lekcji z ustaloną stawką wynagrodzenia w tym miesiącu.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Prowadzący</th>
          <th class="text-end">Lekcje</th>
          <th class="text-end">Brutto-brutto</th>
          <th class="text-end">Koszt płatnika</th>
          <th class="text-end">Brutto</th>
          <th class="text-end">Składki</th>
          <th class="text-end">Podatek</th>
          <th class="text-end">Na rękę</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="table-group-divider">
          <td class="fw-semibold"><?= h($r['name']) ?><?php if (!empty($r['is_student'])): ?> <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size:.7rem" title="Brak ZUS/PIT — BB=netto">student</span><?php endif; ?></td>
          <td class="text-end"><?= (int)$r['lessons'] ?></td>
          <td class="text-end"><?= $f($r['brutto_brutto']) ?></td>
          <td class="text-end text-body-secondary"><?= $f($r['zus_employer']) ?></td>
          <td class="text-end"><?= $f($r['brutto']) ?></td>
          <td class="text-end"><?= $f($r['skladki']) ?></td>
          <td class="text-end"><?= $f($r['pit']) ?></td>
          <td class="text-end fw-semibold text-success"><?= $f($r['netto']) ?></td>
        </tr>
        <?php foreach ($r['courses'] as $c): ?>
        <tr class="text-body-secondary" style="font-size:.85rem">
          <td class="ps-4"><i class="bi bi-arrow-return-right me-1 opacity-50"></i><?= h($c['name']) ?></td>
          <td class="text-end"><?= (int)$c['lessons'] ?></td>
          <td class="text-end"><?= $f($c['brutto_brutto']) ?></td>
          <td class="text-end"><?= $f($c['zus_employer']) ?></td>
          <td class="text-end"><?= $f($c['brutto']) ?></td>
          <td class="text-end"><?= $f($c['skladki']) ?></td>
          <td class="text-end"><?= $f($c['pit']) ?></td>
          <td class="text-end"><?= $f($c['netto']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr class="fw-bold">
          <td>Razem (<?= count($rows) ?> prowadzących)</td>
          <td class="text-end"><?= (int)$tot['lessons'] ?></td>
          <td class="text-end"><?= $f($tot['brutto_brutto']) ?></td>
          <td class="text-end"><?= $f($tot['zus_employer']) ?></td>
          <td class="text-end"><?= $f($tot['brutto']) ?></td>
          <td class="text-end"><?= $f($tot['skladki']) ?></td>
          <td class="text-end"><?= $f($tot['pit']) ?></td>
          <td class="text-end text-success"><?= $f($tot['netto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<p class="text-body-secondary small mt-2">Wszystkie kwoty w zł. „Brutto-brutto" to całkowity koszt; „na rękę" to kwota po odliczeniu składek pracownika i zaliczki PIT. Stawki potrąceń zmienisz w <a href="index.php">ustawieniach kursów</a>.</p>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
