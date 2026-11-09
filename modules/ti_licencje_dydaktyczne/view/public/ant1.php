<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/ant1.php — portal: potwierdzenie wniosku ANT1 (dostęp do Claude for Nonprofit) przez osobę:
 * dane wniosku, oświadczenie o opłacie (8 USD miesięcznie), klauzula RODO + oświadczenie o danych; podpis = imię i nazwisko, trzy zgody.
 * Zmienne: $op, $token, $errors, $old, $org.
 */
$title = 'Potwierdzenie wniosku o dostęp do Claude';
$r = $op['request'];
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
include __DIR__ . '/_top.php';
?>
<?php if ($errors): ?><div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy"><p class="tw-font-semibold">Popraw zaznaczone pola (<?= count($errors) ?>).</p></div>
<script>document.getElementById('bledy').focus();</script><?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4" aria-label="Sprawa">
  <div class="tw-bg-m-orange tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer sprawy</div><div class="tw-font-semibold tw-break-all"><?= h($op['number']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Dostęp</div><div class="tw-font-semibold">Claude for Nonprofit</div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Potwierdź do</div><div class="tw-font-semibold"><?= h(ldDate($op['due_on'])) ?></div></div>
</section>

<section class="m-tile">
  <h2 class="m-head">Wniosek ANT1 — nadanie uprawnień w ramach dostępu do subskrypcji Claude for Nonprofit</h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Wniosek dotyczy</dt><dd><?= h(Ant1Form::TYPES[$r['rodzaj'] ?? ''] ?? '') ?></dd></div>
    <div><dt>Imię i nazwisko</dt><dd><?= h(trim(($r['imie'] ?? '') . ' ' . ($r['nazwisko'] ?? ''))) ?></dd></div>
    <div><dt>Adres e-mail (konto Claude)</dt><dd><?= h($r['email_edu'] ?? '') ?></dd></div>
    <div><dt>Nr kursu / nazwa grupy</dt><dd><?= h(trim(($r['nr_kursu'] ?? '') . ' ' . ($r['nazwa_grupy'] ?? '')) ?: '—') ?></dd></div>
    <div><dt>Przygotował(a) w SZO</dt><dd><?= h($op['created_name']) ?></dd></div>
    <?php if (($r['uwagi'] ?? '') !== ''): ?><div class="sm:tw-col-span-2"><dt>Uwagi</dt><dd class="tw-font-normal"><?= h($r['uwagi']) ?></dd></div><?php endif; ?>
  </dl>
  <p class="tw-text-sm"><?= h(Ant1Form::TEXT_REQUEST) ?></p>
  <p><a class="m-btn-ghost tw-no-underline" href="<?= h(route('ld.pub.ant1.pdf', ['token' => $token])) ?>" target="_blank" rel="noopener">Pobierz wniosek ANT1 (PDF)</a></p>
</section>

<form method="post" action="<?= h(route('ld.pub.ant1', ['token' => $token])) ?>" novalidate>
  <?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>

  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Oświadczenie o opłacie za dostęp do Claude (<?= h(Ant1Form::FEE) ?>)</legend>
    <p class="tw-clear-both tw-text-sm"><?= h(Ant1Form::FEE_INTRO) ?></p>
    <p class="tw-text-sm"><?= h(Ant1Form::FEE_LEAD) ?></p>
    <ol class="tw-text-sm tw-list-decimal tw-pl-6 tw-space-y-1"><?php foreach (Ant1Form::FEE_POINTS as $pt): ?><li><?= h($pt) ?></li><?php endforeach; ?></ol>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm_fee" value="1"<?= !empty($old['confirm_fee']) ? ' checked' : '' ?><?= $aria('confirm_fee') ?>>
      <span>Wyrażam zgodę na opłatę <?= h(Ant1Form::FEE) ?> za dostęp do narzędzia Claude i potwierdzam, że zapoznałem/am się z pkt 1–4.</span></label><?= $err('confirm_fee') ?>
  </fieldset>

  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Klauzula informacyjna (RODO)</legend>
    <div class="tw-clear-both tw-text-sm tw-space-y-2"><?php foreach (Ant1Form::RODO as $pt): ?><p><?= h($pt) ?></p><?php endforeach; ?></div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm_rodo" value="1"<?= !empty($old['confirm_rodo']) ? ' checked' : '' ?><?= $aria('confirm_rodo') ?>>
      <span>Zapoznałem/am się z klauzulą informacyjną RODO. <?= h(Ant1Form::RODO_DECL) ?></span></label><?= $err('confirm_rodo') ?>
  </fieldset>

  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Potwierdzenie wniosku</legend>
    <div class="tw-clear-both tw-max-w-md"><label class="m-label" for="f-name">Imię i nazwisko (podpis) *</label>
      <input class="m-input" id="f-name" name="name" value="<?= h((string)($old['name'] ?? '')) ?>" autocomplete="name"<?= $aria('name') ?>><?= $err('name') ?></div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"<?= !empty($old['confirm']) ? ' checked' : '' ?><?= $aria('confirm') ?>>
      <span>Potwierdzam wniosek o nadanie dostępu do Claude. Zapis elektroniczny (data, godzina, adres IP) zastępuje podpis — także pod oświadczeniem o opłacie i klauzulą RODO.</span></label><?= $err('confirm') ?>
  </fieldset>
  <button type="submit" class="m-btn">Potwierdzam wniosek i oświadczenia</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
