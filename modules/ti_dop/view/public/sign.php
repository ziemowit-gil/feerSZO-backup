<?php
/**
 * modules/ti_dop/view/public/sign.php — portal: podgląd i podpis elektroniczny wniosku DOP1 / DOP2 przez wnioskodawcę.
 * Zmienne: $r, $token, $errors, $old, $org, $sym.
 */
$f = $r['formEnum'];
$title = 'Wniosek ' . $f->value . ' – ' . $f->title();
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
include dirname(__DIR__, 3) . '/ti_licencje_dydaktyczne/view/public/_top.php';
?>
<?php if ($errors): ?><div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy"><p class="tw-font-semibold">Popraw zaznaczone pola.</p></div><script>document.getElementById('bledy').focus();</script><?php endif; ?>
<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4">
  <div class="tw-bg-m-cyan tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer wniosku</div><div class="tw-text-lg tw-font-semibold"><?= h($r['number']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Kwota</div><div class="tw-font-semibold"><?= h(number_format((float)($r['d']['kwota'] ?? 0), 2, ',', ' ')) ?> zł</div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Realizacja do</div><div class="tw-font-semibold"><?= h(ldDate($r['due_on'])) ?></div></div>
</section>
<section class="m-tile">
  <h2 class="m-head"><?= h($f->statement()) ?></h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <?php foreach ($f->fields() as $k => [$label, $type, , $opts]): $vv = (string)($r['d'][$k] ?? ''); if ($type === 'check') { if ($vv === '') continue; $vv = 'tak'; } elseif ($type === 'money' && $vv !== '') $vv = number_format((float)$vv, 2, ',', ' ') . ' zł (słownie: ' . dopKwotaSlownie((float)$vv) . ')'; elseif ($type === 'date') $vv = ldDate($vv); elseif ($type === 'select') $vv = (string)($opts[$vv] ?? $vv); if ($vv === '') continue; ?>
      <div><dt><?= h($label) ?></dt><dd><?= h($vv) ?></dd></div>
    <?php endforeach; ?>
  </dl>
  <p><a class="m-btn-ghost tw-no-underline" href="<?= h(route('dop.pub.pdf', ['token' => $token])) ?>" target="_blank" rel="noopener">Pobierz wniosek (PDF)</a></p>
</section>
<form method="post" action="<?= h(route('dop.pub.sign', ['token' => $token])) ?>" novalidate><?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>
  <fieldset class="m-tile"><legend class="m-head tw-float-left tw-w-full">Podpis wnioskodawcy</legend>
    <div class="tw-clear-both tw-max-w-md"><label class="m-label" for="f-name">Imię i nazwisko (podpis) *</label><input class="m-input" id="f-name" name="name" value="<?= h((string)($old['name'] ?? '')) ?>" autocomplete="name"<?= $aria('name') ?>><?= $err('name') ?></div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"<?= !empty($old['confirm']) ? ' checked' : '' ?><?= $aria('confirm') ?>>
      <span>Potwierdzam dane wniosku i składam go. Zapis elektroniczny (data, godzina, adres IP) zastępuje podpis odręczny.</span></label><?= $err('confirm') ?>
  </fieldset>
  <button type="submit" class="m-btn">Podpisuję i składam wniosek</button>
</form>
<?php include dirname(__DIR__, 3) . '/ti_licencje_dydaktyczne/view/public/_bottom.php'; ?>
