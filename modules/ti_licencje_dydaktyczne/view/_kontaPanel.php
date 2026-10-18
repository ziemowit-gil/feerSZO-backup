<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_kontaPanel.php — panel „Zarządzanie licencjami” w kartotece uczestnika
 * (karty30/ti/dydaktyk/konta.php?selected={id}, zakładka Licencje): posiadane licencje (TI, AM5/AM7, Microsoft 365) z aktualnym
 * płatnikiem i skrótami operacji AM20 (zmiana płatnika — bez akceptacji) oraz AM21–AM24, otwarte operacje konta.
 * Wejście: $sa (wiersz k30_ti_student_accounts z client_id). Adresy względne do licencjeDydaktyczne.php (ten sam katalog).
 */
require_once dirname(__DIR__) . '/lib/LicenseOperationService.php';
$_ldAcc = LicenseResources::account('client', (int)($sa['client_id'] ?? 0));
if ($_ldAcc):
    $_ldRes = LicenseResources::forAccount($_ldAcc);
    $_ldOpen = db_all("SELECT id, number, form, status FROM license_operations WHERE account_kind = 'client' AND account_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC", [$_ldAcc['id']]);
    $_ldUrl = fn(array $q) => 'licencjeDydaktyczne.php?' . http_build_query($q);
    $_ldNew = fn(string $form, string $key = '') => $_ldUrl(array_filter(['_route' => '/operacje/nowa', 'konto' => 'client:' . $_ldAcc['id'], 'form' => $form, 'zasob' => $key]));
?>
<div class="border-top pt-2 mt-3" aria-labelledby="ld-panel-h">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <div class="small fw-semibold text-body-secondary" id="ld-panel-h">Zarządzanie licencjami <span class="fw-normal">(zmiana płatnika AM20, plan AM21, zawieszenie AM22, cofnięcie AM23, przedłużenie AM24)</span></div>
    <a class="small ms-auto" href="<?= h($_ldUrl(['_route' => '/konto/client/' . $_ldAcc['id']])) ?>"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Karta licencji osoby</a>
  </div>
  <?php if (!$_ldRes): ?><p class="text-body-secondary small mb-1">Brak posiadanych licencji — <b>AM20 (zmiana płatnika)</b> i AM21–AM24 pojawią się przy licencji po jej przypisaniu.</p><?php else: ?>
  <ul class="list-group list-group-flush mb-2" style="max-width:760px">
    <?php foreach ($_ldRes as $_r): ?>
    <li class="list-group-item d-flex align-items-center gap-2 px-0 py-1 small flex-wrap">
      <span class="fw-semibold"><?= h($_r['sku']) ?></span><span class="text-body-secondary"><?= h($_r['vendor']) ?></span>
      <span class="badge border text-body" title="Aktualny płatnik">płatnik: <?= h($_r['funding']['payer']) ?> (<?= h($_r['funding']['source']) ?>)</span>
      <?php if ($_r['funding']['mode'] === 'zajecia'): ?><span class="badge text-bg-light border">+<?= h(LicenseOperationService::money((float)$_r['funding']['amount'])) ?> do zajęć</span><?php endif; ?>
      <span class="ms-auto d-flex gap-1">
        <a class="btn btn-sm btn-outline-success py-0" href="<?= h($_ldNew('AM20', $_r['key'])) ?>" title="AM20 — zmiana płatnika (bez akceptacji)">Zmień płatnika</a>
        <?php foreach (['AM21' => 'Plan', 'AM22' => 'Zawieś', 'AM23' => 'Cofnij', 'AM24' => 'Przedłuż'] as $_f => $_l): ?>
          <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h($_ldNew($_f, $_r['key'])) ?>" title="<?= h($_f . ' — ' . OperationForm::from($_f)->title()) ?>"><?= h($_l) ?></a>
        <?php endforeach; ?>
      </span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($_ldOpen): ?><div class="small mb-1">Otwarte sprawy: <?php foreach ($_ldOpen as $_o): ?><a class="me-2" href="<?= h($_ldUrl(['_route' => '/operacje/' . (int)$_o['id']])) ?>"><?= h($_o['form']) ?> <?= h($_o['number']) ?></a><?php endforeach; ?></div><?php endif; ?>
  <div class="d-flex flex-wrap gap-1">
    <a class="btn btn-sm btn-outline-primary py-0" href="<?= h($_ldNew('AM11')) ?>">AM11 — licencja z budżetu FEER</a>
    <a class="btn btn-sm btn-outline-primary py-0" href="<?= h($_ldNew('AM12')) ?>">AM12 — zwrot kosztów / refaktura</a>
    <a class="btn btn-sm btn-outline-primary py-0" href="<?= h($_ldNew('AM13')) ?>">AM13 — licencja finansowana zewnętrznie</a>
  </div>
  <div class="small mt-1">Wzory do druku:
    <a href="<?= h($_ldUrl(['_route' => '/wzor-op/AM20'])) ?>" target="_blank" rel="noopener">AM20 — zmiana płatnika</a> ·
    <a href="<?= h($_ldUrl(['_route' => '/wzor-op/AM20-1'])) ?>" target="_blank" rel="noopener">AM20-1 — wykaz dokumentów księgowych</a></div>
</div>
<?php endif; ?>
