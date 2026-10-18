<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/requester.php — portal kursanta: przegląd i podpis elektroniczny wniosku AM7/1 (Office 365).
 * Zmienne: $l (sprawa), $token, $errors, $old, $org.
 */
$title = 'Wniosek o dostęp do Office 365 — podpis';
$r = $l['request'];
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
include __DIR__ . '/_top.php';
?>
<?php if ($errors): ?><div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy"><p class="tw-font-semibold">Popraw zaznaczone pola (<?= count($errors) ?>).</p></div>
<script>document.getElementById('bledy').focus();</script><?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4" aria-label="Sprawa">
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer sprawy</div><div class="tw-text-lg tw-font-semibold"><?= h($l['case_number']) ?></div></div>
  <div class="tw-bg-m-cyan tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Plan Microsoft 365</div><div class="tw-font-semibold"><?= h($r['plan'] ?? '') ?></div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Realizacja do <?= LD_REALIZATION_DAYS ?> dni</div><div class="tw-font-semibold">do <?= h(ldDate($l['due_on'])) ?></div></div>
</section>

<section class="m-tile">
  <h2 class="m-head">Formularz AM7 — wniosek o udostępnienie licencji Office 365 do celów dydaktycznych</h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Do</dt><dd><?= h($l['licensor_name']) ?></dd></div>
    <div><dt>Imię i nazwisko kursanta</dt><dd><?= h($l['interested_name']) ?></dd></div>
    <div><dt>Kurs lub program dydaktyczny</dt><dd><?= h($l['course_name']) ?></dd></div>
    <div><dt>Adres e-mail lub identyfikator konta</dt><dd><?= h(($r['account'] ?? '') ?: '—') ?></dd></div>
    <div><dt>Wnioskowany sposób dostępu</dt><dd><?= ($r['access'] ?? '') === 'other' ? 'inny sposób: ' . h($r['access_other'] ?? '') : 'konto w tenancie ośrodka' ?></dd></div>
    <div><dt>Wnioskowany okres</dt><dd><?= h(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) ?></dd></div>
  </dl>
  <p class="tw-text-sm"><?= h(LicenseDocument::AM7_REQUEST) ?></p>
  <p><a class="m-btn-ghost tw-no-underline" href="<?= h(route('ld.pub.request.pdf', ['token' => $token])) ?>" target="_blank" rel="noopener">Pobierz wniosek (PDF)</a></p>
</section>

<form method="post" action="<?= h(route('ld.pub.request', ['token' => $token])) ?>" novalidate>
  <?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>
  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Uzasadnienie i podpis</legend>
    <div class="tw-clear-both tw-grid sm:tw-grid-cols-2 tw-gap-4">
      <div class="sm:tw-col-span-2"><label class="m-label" for="f-j">Uzasadnienie lub opis potrzeb dydaktycznych</label><textarea class="m-input" id="f-j" name="justification" rows="3"><?= $v('justification') ?></textarea></div>
      <div><label class="m-label" for="f-name">Imię i nazwisko (podpis) *</label><input class="m-input" id="f-name" name="name" value="<?= $v('name') ?>" autocomplete="name"<?= $aria('name') ?>><?= $err('name') ?></div>
      <div><label class="m-label" for="f-place">Miejscowość *</label><input class="m-input" id="f-place" name="place" value="<?= $v('place') ?>" autocomplete="address-level2"<?= $aria('place') ?>><?= $err('place') ?></div>
    </div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"<?= !empty($old['confirm']) ? ' checked' : '' ?><?= $aria('confirm') ?>>
      <span><?= h(LicenseDocument::AM7_REQUEST_DECL) ?> Podpisuję wniosek elektronicznie (zapiszemy datę, godzinę i adres IP).</span></label><?= $err('confirm') ?>
  </fieldset>
  <button type="submit" class="m-btn">Podpisz wniosek</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
