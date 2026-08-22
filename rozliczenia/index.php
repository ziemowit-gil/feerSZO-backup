<?php
/**
 * rozliczenia/index.php — Pulpit modułu Rozliczenia (nowy styl panelu).
 * Podsumowanie miesiąca: należności, wpłaty, nadpłaty i niedopłaty; grupy i dłużnicy.
 */
require_once __DIR__ . '/_boot.php';

$ov  = ti_month_overview($rz_year, $rz_month);
$kpi = $ov['kpi'];

$PAGE_TITLE = 'Pulpit rozliczeń';
$RZ_ACTIVE  = 'pulpit';
include __DIR__ . '/_head.php';
?>
<div class="mb-3">
  <h1 class="h4 fw-bold mb-1">Pulpit rozliczeń</h1>
  <p class="text-body-secondary small mb-0">Model kombinowany — każda grupa (przedmiot) ma osobne rozliczenie, własny model naliczania i własne saldo.</p>
</div>

<?= rz_month_bar('index.php', $rz_year, $rz_month, $rz_month_label) ?>

<dl class="rz-kpis">
  <div class="rz-kpi">
    <dt>Należności <?= h(mb_strtolower($rz_month_label)) ?></dt>
    <dd><?= h(rz_zl($kpi['charges'])) ?></dd>
    <small><?= (int)$kpi['billings'] ?> rozliczeń · <?= (int)$kpi['clients'] ?> uczestników</small>
  </div>
  <div class="rz-kpi rz-kpi--ok">
    <dt>Pokryte wpłatami</dt>
    <dd><?= h(rz_zl($kpi['paid'])) ?></dd>
    <small><?= $kpi['charges'] > 0.005 ? round(100 * $kpi['paid'] / $kpi['charges']) : 0 ?>% należności miesiąca</small>
  </div>
  <div class="rz-kpi rz-kpi--bad">
    <dt>Niedopłaty (bieżące)</dt>
    <dd><?= h(rz_zl($kpi['debt'])) ?></dd>
    <small><?= count($ov['debtors']) ?> uczestników z zaległością</small>
  </div>
  <div class="rz-kpi rz-kpi--info">
    <dt>Nadpłaty (bieżące)</dt>
    <dd><?= h(rz_zl($kpi['credit'])) ?></dd>
    <small>zaliczane na kolejne zajęcia</small>
  </div>
</dl>

<div class="alert alert-primary d-flex gap-2 align-items-start py-2 px-3 small" role="note">
  <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
  <div>
    Salda (nadpłaty/niedopłaty) są <strong>bieżące</strong> i liczone per grupa: wpłata zaksięgowana na grupę
    pokrywa tylko jej należności, a wpłata ogólna spłaca najstarsze należności niezależnie od grupy.
    Faktury wystawiamy w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong> — tutaj rejestrujemy numer i skan.
  </div>
</div>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-collection" aria-hidden="true"></i>Grupy w tym miesiącu
    <span class="sp"></span>
    <a class="btn btn-sm btn-outline-secondary" href="grupy.php?m=<?= h($rz_ym) ?>">Wszystkie grupy</a>
  </div>
  <?php if ($ov['groups']): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Rozliczenia grup w wybranym miesiącu</caption>
      <thead>
        <tr>
          <th scope="col">Grupa / przedmiot</th>
          <th scope="col" class="rz-num">Uczestnicy</th>
          <th scope="col" class="rz-num">Należności</th>
          <th scope="col" class="rz-num">Pokryte</th>
          <th scope="col" class="rz-num">Saldo grupy</th>
          <th scope="col">Faktury</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ov['groups'] as $g): ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <?php if ($g['course_id'] > 0): ?>
            <a href="grupa.php?id=<?= (int)$g['course_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($g['course_name']) ?></a>
            <?php if ($g['group_code'] !== ''): ?>
            <div><span class="badge bg-light text-secondary border"><?= h($g['group_code']) ?></span></div>
            <?php endif; ?>
            <?php else: ?>
            <span class="rz-zero"><?= h($g['course_name']) ?></span>
            <?php endif; ?>
          </th>
          <td class="rz-num"><?= (int)$g['participants'] ?></td>
          <td class="rz-num"><?= h(rz_zl($g['charges'])) ?></td>
          <td class="rz-num"><?= h(rz_zl($g['paid'])) ?></td>
          <td class="rz-num"><?= rz_saldo((float)$g['credit'], (float)$g['debt']) ?></td>
          <td>
            <?php if ($g['no_invoice'] > 0): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Rozliczenia bez wgranego skanu faktury">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>brak skanu: <?= (int)$g['no_invoice'] ?>
            </span>
            <?php else: ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check2" aria-hidden="true"></i>komplet</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-body-secondary">
    „Pokryte" to wpłaty zaliczone na należności tej grupy (również z wpłat ogólnych). Saldo grupy jest bieżące — obejmuje wszystkie okresy.
  </div>
  <?php else: ?>
  <div class="rz-empty">Brak wystawionych rozliczeń w tym miesiącu.
    <div class="mt-2"><a class="btn btn-sm btn-primary" href="grupy.php?m=<?= h($rz_ym) ?>">Przejdź do grup i wystaw rozliczenia</a></div>
  </div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Uczestnicy z niedopłatą
    <span class="sp"></span>
    <span class="badge <?= count($ov['debtors'])
          ? 'bg-danger-subtle text-danger-emphasis border border-danger-subtle'
          : 'bg-success-subtle text-success-emphasis border border-success-subtle' ?>"><?= count($ov['debtors']) ?></span>
  </div>
  <?php if ($ov['debtors']): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy z zaległościami</caption>
      <thead><tr>
        <th scope="col">Uczestnik</th>
        <th scope="col" class="rz-num">Niedopłata</th>
        <th scope="col" class="rz-num">Nadpłata</th>
        <th scope="col" class="rz-num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach (array_slice($ov['debtors'], 0, 25, true) as $dbt): ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="uczestnik.php?client_id=<?= (int)$dbt['client_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($dbt['client_name']) ?></a>
          </th>
          <td class="num rz-neg"><?= h(rz_zl($dbt['debt'])) ?></td>
          <td class="rz-num"><?= $dbt['credit'] > 0.005 ? '<span class="rz-pos">' . h(rz_zl($dbt['credit'])) . '</span>' : '<span class="rz-zero">—</span>' ?></td>
          <td class="rz-num">
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/karty30/ti/hours_pdf.php?client_id=<?= (int)$dbt['client_id'] ?>&amp;month=<?= $rz_month ?>&amp;year=<?= $rz_year ?>">
              <i class="bi bi-clock-history" aria-hidden="true"></i>Rozpiska
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($ov['debtors']) > 25): ?>
  <div class="card-footer small text-body-secondary">Pokazano 25 z <?= count($ov['debtors']) ?> — pełna lista w zakładce <a href="uczestnicy.php?m=<?= h($rz_ym) ?>">Uczestnicy</a>.</div>
  <?php endif; ?>
  <?php else: ?>
  <div class="rz-empty">Brak zaległości — wszystkie należności uczestników rozliczonych w tym miesiącu są pokryte.</div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
