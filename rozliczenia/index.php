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
<div class="tz-h">
  <h1>Pulpit rozliczeń</h1>
  <p>Model kombinowany — każda grupa (przedmiot) ma osobne rozliczenie, własny model naliczania i własne saldo.</p>
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

<div class="rz-note">
  <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
  <div>
    Salda (nadpłaty/niedopłaty) są <strong>bieżące</strong> i liczone per grupa: wpłata zaksięgowana na grupę
    pokrywa tylko jej należności, a wpłata ogólna spłaca najstarsze należności niezależnie od grupy.
    Faktury wystawiamy w systemie <strong><?= h(k30_ti_invoice_system()) ?></strong> — tutaj rejestrujemy numer i skan.
  </div>
</div>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-collection" aria-hidden="true"></i>Grupy w tym miesiącu
    <span class="sp"></span>
    <a class="tz-btn tz-btn--ghost tz-btn--sm" href="grupy.php?m=<?= h($rz_ym) ?>">Wszystkie grupy</a>
  </div>
  <?php if ($ov['groups']): ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Rozliczenia grup w wybranym miesiącu</caption>
      <thead>
        <tr>
          <th scope="col">Grupa / przedmiot</th>
          <th scope="col" class="num">Uczestnicy</th>
          <th scope="col" class="num">Należności</th>
          <th scope="col" class="num">Pokryte</th>
          <th scope="col" class="num">Saldo grupy</th>
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
            <div><span class="tz-badge tz-badge--off"><?= h($g['group_code']) ?></span></div>
            <?php endif; ?>
            <?php else: ?>
            <span class="rz-zero"><?= h($g['course_name']) ?></span>
            <?php endif; ?>
          </th>
          <td class="num"><?= (int)$g['participants'] ?></td>
          <td class="num"><?= h(rz_zl($g['charges'])) ?></td>
          <td class="num"><?= h(rz_zl($g['paid'])) ?></td>
          <td class="num"><?= rz_saldo((float)$g['credit'], (float)$g['debt']) ?></td>
          <td>
            <?php if ($g['no_invoice'] > 0): ?>
            <span class="tz-badge tz-badge--warn" title="Rozliczenia bez wgranego skanu faktury">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>brak skanu: <?= (int)$g['no_invoice'] ?>
            </span>
            <?php else: ?>
            <span class="tz-badge tz-badge--ok"><i class="bi bi-check2" aria-hidden="true"></i>komplet</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tz-card__ft">
    „Pokryte" to wpłaty zaliczone na należności tej grupy (również z wpłat ogólnych). Saldo grupy jest bieżące — obejmuje wszystkie okresy.
  </div>
  <?php else: ?>
  <div class="rz-empty">Brak wystawionych rozliczeń w tym miesiącu.
    <div class="mt-2"><a class="tz-btn tz-btn--sm" href="grupy.php?m=<?= h($rz_ym) ?>">Przejdź do grup i wystaw rozliczenia</a></div>
  </div>
  <?php endif; ?>
</div>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Uczestnicy z niedopłatą
    <span class="sp"></span>
    <span class="tz-badge tz-badge--<?= count($ov['debtors']) ? 'bad' : 'ok' ?>"><?= count($ov['debtors']) ?></span>
  </div>
  <?php if ($ov['debtors']): ?>
  <div class="table-responsive">
    <table class="rz-tbl">
      <caption class="visually-hidden">Uczestnicy z zaległościami</caption>
      <thead><tr>
        <th scope="col">Uczestnik</th>
        <th scope="col" class="num">Niedopłata</th>
        <th scope="col" class="num">Nadpłata</th>
        <th scope="col" class="num">Akcje</th>
      </tr></thead>
      <tbody>
        <?php foreach (array_slice($ov['debtors'], 0, 25, true) as $dbt): ?>
        <tr>
          <th scope="row" style="font-weight:600">
            <a href="uczestnik.php?client_id=<?= (int)$dbt['client_id'] ?>&amp;m=<?= h($rz_ym) ?>"><?= h($dbt['client_name']) ?></a>
          </th>
          <td class="num rz-neg"><?= h(rz_zl($dbt['debt'])) ?></td>
          <td class="num"><?= $dbt['credit'] > 0.005 ? '<span class="rz-pos">' . h(rz_zl($dbt['credit'])) . '</span>' : '<span class="rz-zero">—</span>' ?></td>
          <td class="num">
            <a class="tz-btn tz-btn--ghost tz-btn--sm" target="_blank" rel="noopener"
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
  <div class="tz-card__ft">Pokazano 25 z <?= count($ov['debtors']) ?> — pełna lista w zakładce <a href="uczestnicy.php?m=<?= h($rz_ym) ?>">Uczestnicy</a>.</div>
  <?php endif; ?>
  <?php else: ?>
  <div class="rz-empty">Brak zaległości — wszystkie należności uczestników rozliczonych w tym miesiącu są pokryte.</div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
