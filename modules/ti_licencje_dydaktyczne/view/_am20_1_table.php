<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_am20_1_table.php — elektroniczny formularz AM20-1 (wykaz dokumentów księgowych
 * wystawionych dotąd przez FEER w związku z opłatą za licencję): tabela wierszy rodzaj / numer / data / kwota + „brak dokumentów”.
 * Wejście: $docs (lista wierszy), $brak (bool), $errAm (komunikat błędu albo ''). Pola: am20_1[i][typ|numer|data|kwota|zrodlo], am20_1_brak.
 */
$docs = array_values($docs);
$docsTarget = max(5, count($docs) + 3);
while (count($docs) < $docsTarget) $docs[] = ['typ' => '', 'numer' => '', 'data' => '', 'kwota' => '', 'zrodlo' => ''];
$sumAm = LicenseResources::accountingSummary(array_filter($docs, fn($d) => trim((string)$d['numer']) !== ''));
?>
<?php if (!empty($errAm)): ?><div class="alert alert-danger py-1 small" role="alert"><?= h($errAm) ?></div><?php endif; ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-2">
  <thead><tr><th style="width:4%">Lp.</th><th style="width:20%">Rodzaj</th><th>Numer dokumentu</th><th style="width:17%">Data wystawienia</th><th style="width:16%">Kwota brutto (zł)</th><th class="small text-secondary">Źródło</th></tr></thead>
  <tbody>
  <?php foreach ($docs as $i => $dd): ?>
    <tr>
      <td class="small text-secondary"><?= $i + 1 ?></td>
      <td><label class="visually-hidden" for="dt<?= $i ?>">Rodzaj dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dt<?= $i ?>" name="am20_1[<?= $i ?>][typ]" value="<?= h($dd['typ']) ?>" list="am20-typy" placeholder="faktura"></td>
      <td><label class="visually-hidden" for="dn<?= $i ?>">Numer dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dn<?= $i ?>" name="am20_1[<?= $i ?>][numer]" value="<?= h($dd['numer']) ?>"></td>
      <td><label class="visually-hidden" for="dd<?= $i ?>">Data dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" type="date" id="dd<?= $i ?>" name="am20_1[<?= $i ?>][data]" value="<?= h($dd['data']) ?>"></td>
      <td><label class="visually-hidden" for="dk<?= $i ?>">Kwota dokumentu <?= $i + 1 ?></label><input class="form-control form-control-sm" id="dk<?= $i ?>" name="am20_1[<?= $i ?>][kwota]" value="<?= h($dd['kwota']) ?>" inputmode="decimal"></td>
      <td class="small text-secondary"><?= h($dd['zrodlo'] ?: 'ręcznie') ?><input type="hidden" name="am20_1[<?= $i ?>][zrodlo]" value="<?= h($dd['zrodlo'] ?: 'ręcznie') ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="small"><td colspan="6">Razem: <b><?= h(LicenseOperationService::money($sumAm['sum'])) ?></b> · pierwsza faktura: <b><?= h(ldDate($sumAm['first_invoice']) ?: '—') ?></b> · ostatnia faktura: <b><?= h(ldDate($sumAm['last_invoice']) ?: '—') ?></b> <span class="text-secondary">(przeliczane przy zapisie)</span></td></tr></tfoot>
</table></div>
<datalist id="am20-typy"><option value="faktura"><option value="faktura zbiorcza"><option value="faktura korygująca"><option value="rachunek"><option value="nota księgowa"><option value="rozliczenie TI (dopłata)"></datalist>
<div class="form-check"><input class="form-check-input" type="checkbox" id="am20b" name="am20_1_brak" value="1"<?= !empty($brak) ? ' checked' : '' ?>>
  <label class="form-check-label small" for="am20b">FEER nie wystawiała dotąd dokumentów księgowych za tę licencję</label></div>
<p class="small text-secondary mb-0">Puste wiersze są pomijane; po zapisie pojawią się kolejne wolne wiersze.</p>
