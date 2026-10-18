<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/holder.php — portal licencjobiorcy: dane osobowe/adresowe i oświadczenia AM5/2, AM5/3.
 * Zmienne: $a (przydział), $l (licencja), $token, $errors [pole => komunikat], $old (wartości pól), $org.
 */
$am7 = $l['form'] === LicenseForm::Am7;
$sym = $l['form']->value;
$title = $am7 ? 'Oświadczenia — licencja ' . $l['title'] : 'Oświadczenia do licencji dydaktycznej';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
$input = function (string $k, string $label, string $type = 'text', string $auto = '', string $extra = '') use ($v, $err, $aria): string {
    return '<div><label class="m-label" for="f-' . $k . '">' . h($label) . '</label><input class="m-input" id="f-' . $k . '" name="' . $k . '" type="' . $type . '" value="' . $v($k) . '"'
        . ($auto !== '' ? ' autocomplete="' . $auto . '"' : '') . $aria($k) . $extra . '>' . $err($k) . '</div>';
};
$checked = fn(string $k) => !empty($old[$k]) ? ' checked' : '';
include __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy">
  <p class="tw-font-semibold">Popraw zaznaczone pola (<?= count($errors) ?>).</p>
</div>
<script>document.getElementById('bledy').focus();</script>
<?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4" aria-label="Licencja">
  <div class="tw-bg-m-cyan tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Utwór / materiał</div><div class="tw-text-lg tw-font-semibold"><?= h($l['title']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Okres licencji</div><div class="tw-font-semibold"><?= h(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) ?></div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Realizacja do <?= LD_REALIZATION_DAYS ?> dni</div><div class="tw-font-semibold">do <?= h(ldDate($a['due_on'])) ?></div></div>
</section>

<section class="m-tile">
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Kurs</dt><dd><?= h($l['course_name'] ?: '—') ?></dd></div>
    <div><dt>Licencjodawca</dt><dd><?= h($l['licensor_name']) ?></dd></div>
    <?php if ($am7): ?>
    <div><dt>Konto, identyfikator lub klucz</dt><dd><?= h($l['decision']['account'] ?? '') ?></dd></div>
    <div><dt>Produkt, licencja lub plan</dt><dd><?= h($l['decision']['plan'] ?? '') ?></dd></div>
    <div class="sm:tw-col-span-2"><dt>Po zakończeniu dostępu</dt><dd class="tw-font-normal tw-whitespace-pre-line"><?= h($l['decision']['after_end'] ?? '') ?></dd></div>
    <?php else: ?>
    <div><dt>Rodzaj licencji</dt><dd><?= ($l['license_kind'] ?? '') === 'wylaczna' ? 'wyłączna' : 'niewyłączna' ?></dd></div>
    <div><dt>Terytorium</dt><dd><?= h($l['territory'] ?: '—') ?></dd></div>
    <?php endif; ?>
    <?php if (trim((string)($l['decision']['accepted_scope'] ?? '')) !== ''): ?>
    <div class="sm:tw-col-span-2"><dt>Zakres licencji</dt><dd class="tw-font-normal tw-whitespace-pre-line"><?= h($l['decision']['accepted_scope']) ?></dd></div>
    <?php endif; ?>
  </dl>
  <?php if ($a['status'] === 'rejected' && trim((string)$a['decision_note']) !== ''): ?>
    <p class="tw-border-l-4 tw-border-solid tw-border-m-red tw-pl-3 tw-mt-2"><b>Poprzednie oświadczenia odrzucono:</b> <?= h($a['decision_note']) ?></p>
  <?php endif; ?>
</section>

<form method="post" action="<?= h(route('ld.pub.holder', ['token' => $token])) ?>" novalidate>
  <?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>

  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">1. Twoje dane</legend>
    <p class="m-help tw-clear-both tw-mb-3">Zmienione dane zapiszemy z historią zmian (stara i nowa wartość). Zmiana e-maila i telefonu wymaga akceptacji biura.</p>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-4">
      <div class="sm:tw-col-span-2"><?= $input('name', 'Imię i nazwisko *', 'text', 'name', ' required') ?></div>
      <?= $input('email', 'E-mail', 'email', 'email') ?>
      <?= $input('phone', 'Telefon', 'tel', 'tel') ?>
      <div class="sm:tw-col-span-2"><?= $input('addr_street', 'Ulica', 'text', 'address-line1') ?></div>
      <?= $input('addr_house', 'Nr domu', 'text', '') ?>
      <?= $input('addr_flat', 'Nr lokalu', 'text', '') ?>
      <?= $input('addr_postal', 'Kod pocztowy', 'text', 'postal-code', ' inputmode="numeric" placeholder="00-000"') ?>
      <?= $input('addr_city', 'Miejscowość *', 'text', 'address-level2', ' required') ?>
    </div>
  </fieldset>

  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">2. Oświadczenia</legend>
    <div class="tw-clear-both tw-space-y-4">
      <?php foreach ($docs as $i => $doc): $fld = $i === 0 ? 'accept_participant' : 'accept_nontransfer'; ?>
      <div class="tw-border-l-8 tw-border-solid <?= $i === 0 ? 'tw-border-m-cyan' : 'tw-border-m-cobalt' ?> tw-pl-4">
        <h2 class="tw-font-semibold"><?= h($doc->symbol()) ?> · <?= h($doc->title()) ?></h2>
        <?php foreach (LicenseDocument::declarationTexts($doc) as $t): ?><p class="tw-my-2 tw-text-sm"><?= h($t) ?></p><?php endforeach; ?>
        <label class="m-check"><input type="checkbox" name="<?= $fld ?>" value="1"<?= $checked($fld) ?><?= $aria($fld) ?>>
          <span>Składam oświadczenie: <?= h(mb_strtolower($doc->title())) ?> (<?= h($doc->symbol()) ?>).</span></label><?= $err($fld) ?>
      </div>
      <?php endforeach; ?>
      <div class="tw-grid sm:tw-grid-cols-2 tw-gap-4">
        <?= $input('place', 'Miejscowość złożenia oświadczeń *', 'text', 'address-level2', ' required') ?>
        <div><span class="m-label">Data</span><p class="tw-py-2 tw-font-semibold"><?= h(ldDate(ldToday())) ?></p></div>
      </div>
      <label class="m-check"><input type="checkbox" name="accept_rodo" value="1"<?= $checked('accept_rodo') ?><?= $aria('accept_rodo') ?>>
        <span>Przyjmuję do wiadomości, że <?= h($org) ?> przetwarza moje dane w celu obsługi licencji i dokumentacji usługi szkoleniowej (audyt MSUES 2.0).</span></label><?= $err('accept_rodo') ?>
    </div>
  </fieldset>

  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-4">
    <button type="submit" class="m-btn">Złóż oświadczenia</button>
    <p class="m-help">Po złożeniu wygenerujemy dokumenty <?= h($docs[0]->symbol()) ?> i <?= h($docs[1]->symbol()) ?> (PDF) i wyślemy potwierdzenie.</p>
  </div>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
