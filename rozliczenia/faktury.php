<?php
/**
 * rozliczenia/faktury.php — Rejestr faktur do rozliczeń: numery, skany, braki.
 * Faktury wystawiane są poza panelem (system fakturujący); tutaj pilnujemy kompletu skanów.
 * GET: ?m=YYYY-MM&brak=1 (tylko rozliczenia bez skanu)
 */
require_once __DIR__ . '/_boot.php';
$rz_redirect = 'faktury.php?m=' . $rz_ym . (!empty($_GET['brak']) ? '&brak=1' : '');
require __DIR__ . '/_actions.php';

$only_missing = !empty($_GET['brak']);
$rows = db_all(
    "SELECT b.*, cl.name AS client_name, c.name AS course_name
     FROM k30_ti_billing b
     JOIN k30_clients cl ON cl.id=b.client_id
     LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
     WHERE b.year=? AND b.month=? AND b.status IN ('issued','paid')"
    . ($only_missing ? " AND COALESCE(b.invoice_path,'')=''" : '') . "
     ORDER BY cl.name, c.name", [$rz_year, $rz_month]
);
$missing = 0;
foreach ($rows as $r) if (empty($r['invoice_path'])) $missing++;

$PAGE_TITLE = 'Faktury';
$RZ_ACTIVE  = 'faktury';
include __DIR__ . '/_head.php';
?>
<div class="mb-3">
  <h1 class="h4 fw-bold mb-1">Faktury</h1>
  <p class="text-body-secondary small mb-0">Wystawiamy je w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong> — tutaj rejestrujemy numer i obowiązkowy skan PDF.</p>
</div>

<?= rz_month_bar('faktury.php', $rz_year, $rz_month, $rz_month_label, $only_missing ? 'brak=1' : '') ?>
<?= flash_html() ?>

<dl class="rz-kpis">
  <div class="rz-kpi"><dt>Rozliczenia w miesiącu</dt><dd><?= count($rows) ?></dd><small><?= h($rz_month_label) ?></small></div>
  <div class="rz-kpi rz-kpi--bad"><dt>Bez skanu faktury</dt><dd><?= $missing ?></dd>
    <small><a href="faktury.php?m=<?= h($rz_ym) ?><?= $only_missing ? '' : '&amp;brak=1' ?>"><?= $only_missing ? 'pokaż wszystkie' : 'pokaż tylko braki' ?></a></small></div>
</dl>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Rejestr</div>
  <?php if (!$rows): ?>
  <div class="rz-empty"><?= $only_missing ? 'Wszystkie rozliczenia mają wgrany skan faktury.' : 'Brak rozliczeń w tym miesiącu.' ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Faktury przypisane do rozliczeń</caption>
      <thead><tr>
        <th scope="col">Uczestnik</th><th scope="col">Grupa</th>
        <th scope="col" class="rz-num">Kwota</th><th scope="col">Numer faktury</th>
        <th scope="col">Rodzaj</th><th scope="col">Skan</th><th scope="col" class="rz-num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $b): $tot = (float)$b['amount'] + (float)($b['adjustment'] ?? 0); ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="uczestnik.php?client_id=<?= (int)$b['client_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($b['client_name']) ?></a>
          </th>
          <td><?= $b['course_name'] ? h($b['course_name']) : '<span class="rz-zero">łączne</span>' ?></td>
          <td class="rz-num"><?= h(rz_zl($tot)) ?></td>
          <td><?= !empty($b['invoice_no']) ? h($b['invoice_no']) : '<span class="rz-zero">—</span>' ?>
            <?php if (!empty($b['invoice_issued_on'])): ?>
            <div class="rz-zero" style="font-size:.75rem"><?= h(date('d.m.Y', strtotime((string)$b['invoice_issued_on']))) ?></div>
            <?php endif; ?>
          </td>
          <td><?= ($b['invoice_kind'] ?? '') === 'oneoff'
                 ? '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">jednorazowa</span>'
                 : '<span class="badge bg-light text-secondary border">cykliczna</span>' ?></td>
          <td>
            <?php if (!empty($b['invoice_path'])): ?>
            <a href="<?= APP_URL ?>/karty30/ti/billing_invoice.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener">
              <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?= h($b['invoice_name'] ?: 'faktura.pdf') ?></a>
            <?php else: ?>
            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>brak</span>
            <?php endif; ?>
          </td>
          <td class="rz-num">
            <a class="btn btn-sm btn-outline-secondary" href="uczestnik.php?client_id=<?= (int)$b['client_id'] ?>&amp;m=<?= h($rz_ym) ?>#fv<?= (int)$b['id'] ?>">
              <i class="bi bi-upload" aria-hidden="true"></i>Skan / numer
            </a>
            <?php if ($rz_can_write): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"   value="mail">
              <input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" title="Wyślij mail z rozliczeniem"><i class="bi bi-envelope" aria-hidden="true"></i>Mail</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-body-secondary">Bez wgranego skanu nie da się zapisać danych faktury — to celowa blokada.</div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
