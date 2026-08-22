<?php
/**
 * rozliczenia/grupy.php — Lista grup z ich rozliczeniami w wybranym miesiącu.
 * Akcje zbiorcze per grupa: wystawienie rozliczeń i wysyłka maili z rozliczeniem.
 */
require_once __DIR__ . '/_boot.php';
$rz_redirect = 'grupy.php?m=' . $rz_ym;
require __DIR__ . '/_actions.php';

$courses = k30_ti_courses(false);
$ov      = ti_month_overview($rz_year, $rz_month);

// Uczestnicy (aktywne zapisy) per grupa — do akcji zbiorczych
$enr = db_all("SELECT e.course_id, e.client_id FROM k30_ti_enrollments e WHERE e.status='active'");
$by_course = [];
foreach ($enr as $e) $by_course[(int)$e['course_id']][] = (int)$e['client_id'];

// Rozliczenia miesiąca per grupa — do wysyłki maili
$bills = db_all("SELECT id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing
                 WHERE year=? AND month=? AND status IN ('issued','paid')", [$rz_year, $rz_month]);
$bills_by_course = [];
foreach ($bills as $b) $bills_by_course[(int)$b['course_id']][] = (int)$b['id'];

$PAGE_TITLE = 'Grupy';
$RZ_ACTIVE  = 'grupy';
include __DIR__ . '/_head.php';
?>
<div class="mb-3">
  <h1 class="h4 fw-bold mb-1">Grupy</h1>
  <p class="text-body-secondary small mb-0">Osobne rozliczenie na każdą grupę — wystaw je i rozeslij maile jednym kliknięciem.</p>
</div>

<?= rz_month_bar('grupy.php', $rz_year, $rz_month, $rz_month_label) ?>
<?= flash_html() ?>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-collection" aria-hidden="true"></i>Grupy aktywne
    <span class="sp"></span><span class="badge bg-light text-secondary border"><?= count($courses) ?></span></div>
  <?php if (!$courses): ?>
  <div class="rz-empty">Brak aktywnych grup.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Grupy i stan ich rozliczeń w wybranym miesiącu</caption>
      <thead><tr>
        <th scope="col">Grupa / przedmiot</th>
        <th scope="col">Model</th>
        <th scope="col" class="rz-num">Uczestnicy</th>
        <th scope="col" class="rz-num">Należności <?= h(mb_strtolower(RZ_MONTHS_PL[$rz_month] ?? '')) ?></th>
        <th scope="col" class="rz-num">Saldo grupy</th>
        <th scope="col" class="rz-num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($courses as $c):
          $cid   = (int)$c['id'];
          $g     = $ov['groups'][$cid] ?? null;
          $mem   = $by_course[$cid] ?? [];
          $bids  = $bills_by_course[$cid] ?? [];
          $model = k30_ti_billing_model_label((int)($c['billing_model'] ?? 2) ?: 2);
        ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="grupa.php?id=<?= $cid ?>&amp;m=<?= h($rz_ym) ?>"><?= h($c['name']) ?></a>
            <?php if (!empty($c['group_code'])): ?>
            <div><span class="badge bg-light text-secondary border"><?= h($c['group_code']) ?></span></div>
            <?php endif; ?>
          </th>
          <td><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?= h($model) ?></span></td>
          <td class="rz-num"><?= count($mem) ?></td>
          <td class="rz-num"><?= $g ? h(rz_zl($g['charges'])) : '<span class="rz-zero">—</span>' ?></td>
          <td class="rz-num"><?= $g ? rz_saldo((float)$g['credit'], (float)$g['debt']) : '<span class="rz-zero">—</span>' ?></td>
          <td class="rz-num">
            <?php if ($rz_can_write && $mem): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="issue">
              <input type="hidden" name="notify" value="1">
              <?php foreach ($mem as $mid): ?><input type="hidden" name="client_ids[]" value="<?= $mid ?>"><?php endforeach; ?>
              <button class="btn btn-sm btn-primary" title="Wystaw rozliczenia dla uczestników tej grupy i wyślij maile">
                <i class="bi bi-receipt" aria-hidden="true"></i>Wystaw + mail
              </button>
            </form>
            <?php endif; ?>
            <?php if ($rz_can_write && $bids): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="mail">
              <?php foreach ($bids as $bid): ?><input type="hidden" name="billing_ids[]" value="<?= $bid ?>"><?php endforeach; ?>
              <button class="btn btn-sm btn-outline-secondary" title="Wyślij mail z rozliczeniem do uczestników tej grupy">
                <i class="bi bi-envelope" aria-hidden="true"></i>Mail (<?= count($bids) ?>)
              </button>
            </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/karty30/ti/billing_fv_summary.php?course_id=<?= $cid ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>"
               title="Pozycje do faktury dla całej grupy">
              <i class="bi bi-printer" aria-hidden="true"></i>FVAT
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-body-secondary">
    „Wystaw + mail" tworzy osobne rozliczenie dla każdej grupy uczestnika (także innej niż ta) i wysyła zestawienie należności na e-mail płatnika.
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
