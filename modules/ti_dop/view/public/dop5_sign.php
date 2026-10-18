<?php
/** modules/ti_dop/view/public/dop5_sign.php — podpis elektroniczny DOP5 przez osobę uprawnioną. Zmienne: $ctx, $token, $errors, $old, $org, $sym. */
$r = $ctx['statement']; $sg = $ctx['signer']; $d = $r['d'];
$title = 'DOP5 – oświadczenie o stanie środków — podpis';
$err = fn(string $k) => isset($errors[$k]) ? '<p class="m-err">' . h($errors[$k]) . '</p>' : '';
include dirname(__DIR__, 3) . '/ti_licencje_dydaktyczne/view/public/_top.php';
?>
<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4">
  <div class="tw-bg-m-dark tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer</div><div class="tw-text-lg tw-font-semibold"><?= h($r['number']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Kwota</div><div class="tw-font-semibold"><?= h(number_format((float)$d['kwota'], 2, ',', ' ')) ?> zł</div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Stan na</div><div class="tw-font-semibold"><?= h(ldDate($d['as_of'])) ?></div></div>
</section>
<section class="m-tile"><dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
  <div><dt>Podmiot</dt><dd><?= h($d['org']['name']) ?> · NIP <?= h($d['org']['nip']) ?> · KRS <?= h($d['org']['krs']) ?></dd></div>
  <div><dt>Umowa / projekt</dt><dd><?= h($d['umowa_nr']) ?> — <?= h($d['tytul']) ?></dd></div>
  <div><dt>Kwota (słownie)</dt><dd><?= h(dopKwotaSlownie((float)$d['kwota'])) ?></dd></div>
  <div><dt>Rachunek</dt><dd><?= h($d['rachunek']) ?></dd></div>
  <div class="sm:tw-col-span-2"><dt>Podpisujesz jako</dt><dd><?= h(trim($sg['function_title'] . ' — ' . $sg['basis'], ' —')) ?></dd></div>
</dl>
<?php foreach (Dop5Statement::STATEMENTS as $t): ?><p class="tw-text-sm">☒ <?= h($t) ?></p><?php endforeach; ?></section>
<form method="post" action="<?= h(route('dop5.pub.sign', ['token' => $token])) ?>" novalidate><?= Router::csrfField() ?>
  <fieldset class="m-tile"><legend class="m-head tw-float-left tw-w-full">Podpis</legend>
    <div class="tw-clear-both tw-max-w-md"><label class="m-label" for="f-name">Imię i nazwisko *</label><input class="m-input" id="f-name" name="name" value="<?= h((string)($old['name'] ?? '')) ?>" autocomplete="name"><?= $err('name') ?></div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"><span>Składam oświadczenie DOP5 w imieniu podmiotu. Zapis elektroniczny (data, godzina, adres IP) zastępuje podpis odręczny.</span></label><?= $err('confirm') ?>
  </fieldset><button type="submit" class="m-btn">Podpisuję</button></form>
<?php include dirname(__DIR__, 3) . '/ti_licencje_dydaktyczne/view/public/_bottom.php'; ?>
