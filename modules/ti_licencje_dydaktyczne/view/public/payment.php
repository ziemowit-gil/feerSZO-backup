<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/payment.php — portal: potwierdzenie wniosku AM20 (zmiana płatnika licencji) przez osobę.
 * Pokazuje dyspozycję biura, sposób płatności (doliczenie do zajęć z kwotą albo rachunek i kwota), załącznik AM20-1 i skutek zmiany.
 * Zmienne: $op, $token, $errors, $old, $org.
 */
$title = 'Potwierdzenie zmiany płatnika licencji';
$r = $op['request'];
$am = (array)($r['am20_1'] ?? []);
$sum = LicenseResources::accountingSummary($am);
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
include __DIR__ . '/_top.php';
?>
<?php if ($errors): ?><div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy"><p class="tw-font-semibold">Popraw zaznaczone pola (<?= count($errors) ?>).</p></div>
<script>document.getElementById('bledy').focus();</script><?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4" aria-label="Sprawa">
  <div class="tw-bg-m-green tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer sprawy</div><div class="tw-font-semibold tw-break-all"><?= h($op['number']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Licencja</div><div class="tw-font-semibold"><?= h($op['state']['sku'] ?? '') ?></div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Potwierdź do</div><div class="tw-font-semibold"><?= h(ldDate($op['due_on'])) ?></div></div>
</section>

<section class="m-tile">
  <h2 class="m-head">Wniosek AM20 — zmiana sposobu płatności za posiadaną licencję</h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Wnioskodawca (złożył w SZO)</dt><dd><?= h($op['created_name']) ?></dd></div>
    <div><dt>Użytkownik końcowy</dt><dd><?= h($r['uzytkownik'] ?? $op['account_name']) ?></dd></div>
    <div><dt>Obecny płatnik</dt><dd><?= h($r['obecny_platnik'] ?? '') ?></dd></div>
    <div><dt>Nowy płatnik</dt><dd><?= h($r['nowy_platnik'] ?? '') ?></dd></div>
    <div><dt>Od dnia</dt><dd><?= h(ldDate($r['data_zmiany'] ?? '')) ?></dd></div>
    <div><dt>Sposób płatności</dt><dd><?php if (($r['tryb'] ?? '') === 'zajecia'): ?>opłata za zajęcia wzrośnie o <b><?= h(LicenseOperationService::money((float)$r['kwota'])) ?></b>
      <?php else: ?>wpłata <b><?= h(LicenseOperationService::money((float)$r['kwota'])) ?></b> na rachunek <span class="tw-whitespace-nowrap"><?= h(LicenseOperationService::nrb((string)$r['rachunek'])) ?></span>, tytuł: numer sprawy<?php endif; ?></dd></div>
    <div class="sm:tw-col-span-2"><dt>Uzasadnienie</dt><dd class="tw-font-normal"><?= h($r['uzasadnienie'] ?? '') ?></dd></div>
  </dl>
  <p><a class="m-btn-ghost tw-no-underline" href="<?= h(route('ld.pub.payment.pdf', ['token' => $token])) ?>" target="_blank" rel="noopener">Pobierz wniosek z załącznikiem AM20-1 (PDF)</a></p>
</section>

<section class="m-tile">
  <h2 class="m-head">AM20-1 — dokumenty księgowe wystawione dotąd przez FEER</h2>
  <?php if (!$am): ?><p><?= !empty($r['am20_1_brak']) ? 'FEER nie wystawiała dotąd dokumentów księgowych za tę licencję.' : '—' ?></p>
  <?php else: ?>
  <div class="tw-overflow-x-auto"><table class="tw-w-full tw-text-sm tw-border-collapse">
    <thead><tr class="tw-bg-m-bg"><th class="tw-text-left tw-p-2">Rodzaj</th><th class="tw-text-left tw-p-2">Numer</th><th class="tw-text-left tw-p-2">Data</th><th class="tw-text-right tw-p-2">Kwota</th></tr></thead>
    <tbody><?php foreach ($am as $d): ?><tr class="tw-border-t tw-border-solid tw-border-m-line"><td class="tw-p-2"><?= h($d['typ']) ?></td><td class="tw-p-2 tw-font-semibold"><?= h($d['numer']) ?></td>
      <td class="tw-p-2"><?= h(ldDate($d['data'])) ?></td><td class="tw-p-2 tw-text-right"><?= $d['kwota'] !== '' ? h(LicenseOperationService::money((float)$d['kwota'])) : '—' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="tw-mt-2 tw-text-sm">Razem <b><?= h(LicenseOperationService::money($sum['sum'])) ?></b> · pierwsza faktura <b><?= h(ldDate($sum['first_invoice']) ?: '—') ?></b> · ostatnia faktura <b><?= h(ldDate($sum['last_invoice']) ?: '—') ?></b></p>
  <?php endif; ?>
</section>

<form method="post" action="<?= h(route('ld.pub.payment', ['token' => $token])) ?>" novalidate>
  <?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>
  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Potwierdzenie wnioskodawcy</legend>
    <p class="tw-clear-both tw-text-sm"><?= h(OperationForm::Am20->statement()) ?></p>
    <p class="tw-text-sm tw-text-m-steel"><?= h(OperationForm::Am20->effect()) ?></p>
    <div class="tw-max-w-md"><label class="m-label" for="f-name">Imię i nazwisko (podpis) *</label>
      <input class="m-input" id="f-name" name="name" value="<?= h((string)($old['name'] ?? '')) ?>" autocomplete="name"<?= $aria('name') ?>><?= $err('name') ?></div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"<?= !empty($old['confirm']) ? ' checked' : '' ?><?= $aria('confirm') ?>>
      <span>Potwierdzam wniosek o zmianę płatnika i dane z załącznika AM20-1. Zapis elektroniczny (data, godzina, adres IP) zastępuje podpis.</span></label><?= $err('confirm') ?>
  </fieldset>
  <button type="submit" class="m-btn">Potwierdzam wniosek</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
