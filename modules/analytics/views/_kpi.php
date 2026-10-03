<?php
/**
 * modules/analytics/views/_kpi.php — wskaźniki (KPI) Analityki TI: kafle, formy rozliczeń licencji, wnioski o zwrot kosztów.
 * Wejście: $an_kpi (AnalyticsManager::kpis()). Dostępność: kafle to lista definicji (dl), tabele mają podpisy i nagłówki
 * kolumn, wartość „brak danych” jest tekstem, a nie tylko szarym zerem.
 */
$_n   = fn($v) => $v === null ? 'brak danych' : number_format((int)$v, 0, ',', ' ');
$_zl  = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
$_lic = $an_kpi['licenses'];
$_eq  = $an_kpi['equipment'];
$_rb  = $an_kpi['reimbursements'];
$_fm  = $an_kpi['forms'];
?>
<section aria-labelledby="an-kpi-h">
  <h2 id="an-kpi-h" class="h5 mb-2">Wskaźniki</h2>
  <dl class="row g-3 mb-4 an-kpis">
    <div class="col-sm-6 col-xl-3"><div class="an-tile">
      <dt>Aktywni kursanci</dt>
      <dd class="an-num"><?= h($_n($an_kpi['students'])) ?></dd>
      <dd class="an-sub">zapisy aktywne w aktywnych grupach — liczone jak na pulpicie kierownika</dd>
    </div></div>
    <div class="col-sm-6 col-xl-3"><div class="an-tile">
      <dt>Aktywne licencje</dt>
      <dd class="an-num"><?= h($_n($_lic['valid'] ?? null)) ?></dd>
      <dd class="an-sub"><?php if ($_lic): ?>nieunieważnione i nieprzeterminowane; w tym wygasa w ciągu <?= (int)TiLicencjeManager::WARN_DAYS ?> dni: <?= h($_n($_lic['expiring'])) ?>. Poza tą liczbą: wygasłe <?= h($_n($_lic['expired'])) ?>, unieważnione <?= h($_n($_lic['revoked'])) ?><?php else: ?>moduł Licencje niedostępny<?php endif; ?></dd>
    </div></div>
    <div class="col-sm-6 col-xl-3"><div class="an-tile">
      <dt>Sprzęt w terenie</dt>
      <dd class="an-num"><?= h($_n($_eq['pool'] ?? null)) ?></dd>
      <dd class="an-sub">otwarte wypożyczenia laptopów z puli<?php if ($_eq && $_eq['registry'] !== null): ?> · w rejestrze karty kursanta jako wypożyczony: <?= h($_n($_eq['registry'])) ?> (osobno, bez sumowania)<?php endif; ?></dd>
    </div></div>
    <div class="col-sm-6 col-xl-3"><div class="an-tile">
      <dt>Otwarte wnioski o zwrot kosztów</dt>
      <dd class="an-num"><?= h($_n($_rb['open'] ?? null)) ?></dd>
      <dd class="an-sub"><?php if ($_rb): ?>na kwotę <?= h($_zl($_rb['amount'])) ?> — od „oczekuje” do „do wypłaty”<?php else: ?>moduł zwrotów kosztów niedostępny<?php endif; ?></dd>
    </div></div>
  </dl>
</section>

<div class="row g-4">
  <section class="col-lg-6" aria-labelledby="an-forms-h">
    <h2 id="an-forms-h" class="h5 mb-2">Formy rozliczenia licencji</h2>
    <?php if ($_fm === null): ?>
      <p class="text-body-secondary">Brak danych — moduł Licencje jest niedostępny.</p>
    <?php else:
      $_sum = array_sum($_fm); ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle an-table">
          <caption class="visually-hidden">Liczba bieżących (nieunieważnionych) przypisań licencji według formy rozliczenia</caption>
          <thead><tr><th scope="col">Forma</th><th scope="col" class="text-end">Przypisań</th><th scope="col" class="text-end">Udział</th></tr></thead>
          <tbody>
          <?php foreach ($_fm as $code => $cnt):
            $label = $code === 'none' ? 'Bez formy — do uzupełnienia' : $code . ' — ' . (TiLicencjeManager::BILLING_FORMS[$code]['label'] ?? $code); ?>
            <tr><th scope="row" class="fw-normal"><?= h($label) ?></th>
                <td class="text-end"><?= (int)$cnt ?></td>
                <td class="text-end"><?= $_sum > 0 ? h(number_format($cnt / $_sum * 100, 1, ',', ' ')) . ' %' : '—' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th scope="row">Razem</th><td class="text-end fw-semibold"><?= (int)$_sum ?></td><td class="text-end">100 %</td></tr></tfoot>
        </table>
      </div>
      <a class="btn btn-sm btn-outline-secondary" href="analitykaRaport.php?r=rozliczenia"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Raport rozliczeń licencji</a>
    <?php endif; ?>
  </section>

  <section class="col-lg-6" aria-labelledby="an-zw-h">
    <h2 id="an-zw-h" class="h5 mb-2">Wnioski o zwrot kosztów — otwarte wg statusu</h2>
    <?php if ($_rb === null): ?>
      <p class="text-body-secondary">Brak danych — moduł zwrotów kosztów jest niedostępny.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle an-table">
          <caption class="visually-hidden">Otwarte wnioski o zwrot kosztów (bez zarchiwizowanych) według statusu</caption>
          <thead><tr><th scope="col">Status</th><th scope="col" class="text-end">Wniosków</th><th scope="col" class="text-end">Kwota</th></tr></thead>
          <tbody>
          <?php foreach ($_rb['by_status'] as $st): ?>
            <tr><th scope="row" class="fw-normal"><?= h($st['label']) ?></th><td class="text-end"><?= (int)$st['n'] ?></td><td class="text-end"><?= h($_zl($st['amount'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th scope="row">Razem otwarte</th><td class="text-end fw-semibold"><?= (int)$_rb['open'] ?></td><td class="text-end fw-semibold"><?= h($_zl($_rb['amount'])) ?></td></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
