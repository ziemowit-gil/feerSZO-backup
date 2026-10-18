<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/licensor.php — portal licencjodawcy: podgląd wniosku AM5/1 i decyzja (sekcja H).
 * Zmienne: $l (licencja), $token, $errors, $old, $org.
 */
$title = 'Wniosek o udzielenie licencji dydaktycznej';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<p id="e-' . $k . '" class="m-err">' . h($errors[$k]) . '</p>' : '';
$aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . $k . '"' : '';
$scopes = (array)($l['request']['scopes'] ?? []);
$decision = (string)($old['decision'] ?? 'grant');
include __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="tw-bg-m-red tw-text-white tw-p-4 tw-mb-4" role="alert" tabindex="-1" id="bledy"><p class="tw-font-semibold">Popraw zaznaczone pola (<?= count($errors) ?>).</p></div>
<script>document.getElementById('bledy').focus();</script>
<?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-4" aria-label="Wniosek">
  <div class="tw-bg-m-cyan tw-text-white tw-p-4 tw-col-span-2"><div class="tw-text-xs tw-uppercase tw-opacity-80">Utwór / materiał</div><div class="tw-text-lg tw-font-semibold"><?= h($l['title']) ?></div></div>
  <div class="tw-bg-m-cobalt tw-text-white tw-p-4"><div class="tw-text-xs tw-uppercase tw-opacity-80">Numer wniosku</div><div class="tw-font-semibold"><?= h(LicenseDocument::number(LicenseDoc::Am5Request, $l)) ?></div></div>
  <div class="tw-bg-m-amber tw-text-m-dark tw-p-4"><div class="tw-text-xs tw-uppercase">Decyzja do <?= LD_REALIZATION_DAYS ?> dni</div><div class="tw-font-semibold">do <?= h(ldDate($l['due_on'])) ?></div></div>
</section>

<section class="m-tile">
  <h2 class="m-head">Treść wniosku (<?= LicenseDoc::Am5Request->symbol() ?>)</h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Wnioskodawca / przyszły licencjobiorca</dt><dd><?= h($l['applicant_name']) ?><br><span class="tw-font-normal tw-whitespace-pre-line"><?= h($l['applicant_contact']) ?></span></dd></div>
    <div><dt>Nazwa kursu / zajęć</dt><dd><?= h($l['course_name'] ?: '—') ?></dd></div>
    <div class="sm:tw-col-span-2"><dt>Tytuł, autor i opis utworu</dt><dd class="tw-font-normal tw-whitespace-pre-line"><?= h($l['work_description']) ?></dd></div>
    <div class="sm:tw-col-span-2"><dt>Wnioskowany zakres</dt><dd class="tw-font-normal"><ul class="tw-list-disc tw-pl-5">
      <?php foreach ($scopes as $k => $d): ?><li><b><?= h(LicenseRequestDto::SCOPES[$k][0] ?? $k) ?></b>: <?= h($d) ?></li><?php endforeach; ?>
    </ul></dd></div>
    <div><dt>Okres korzystania</dt><dd><?= h(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to'])) ?></dd></div>
    <div><dt>Terytorium</dt><dd><?= h($l['territory']) ?></dd></div>
    <div class="sm:tw-col-span-2"><dt>Wynagrodzenie lub informacja o nieodpłatności</dt><dd><?= h($l['request']['remuneration'] ?? '') ?></dd></div>
  </dl>
  <p><a class="m-btn-ghost tw-no-underline" href="<?= h(route('ld.pub.decision.pdf', ['token' => $token])) ?>" target="_blank" rel="noopener">Pobierz wniosek (PDF)</a></p>
</section>

<form method="post" action="<?= h(route('ld.pub.decision', ['token' => $token])) ?>" novalidate>
  <?= Router::csrfField() ?>
  <div class="tw-hidden" aria-hidden="true"><label>Nie wypełniaj <input name="www" tabindex="-1" autocomplete="off"></label></div>
  <fieldset class="m-tile">
    <legend class="m-head tw-float-left tw-w-full">Decyzja licencjodawcy</legend>
    <p class="tw-clear-both tw-text-sm tw-mb-4"><?= h(LicenseDocument::TEXT_DECISION) ?></p>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-2 tw-mb-4" role="radiogroup" aria-label="Rozstrzygnięcie">
      <label class="m-check"><input type="radio" name="decision" value="grant"<?= $decision === 'grant' ? ' checked' : '' ?>><span><b>Udzielam licencji</b></span></label>
      <label class="m-check"><input type="radio" name="decision" value="refuse"<?= $decision === 'refuse' ? ' checked' : '' ?>><span><b>Odmawiam udzielenia licencji</b></span></label>
    </div>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-4">
      <div class="sm:tw-col-span-2"><span class="m-label">Rodzaj licencji (przy udzieleniu)</span>
        <div class="tw-flex tw-gap-2">
          <label class="m-check tw-flex-1"><input type="radio" name="kind" value="niewylaczna"<?= ($old['kind'] ?? '') !== 'wylaczna' ? ' checked' : '' ?>><span>niewyłączna</span></label>
          <label class="m-check tw-flex-1"><input type="radio" name="kind" value="wylaczna"<?= ($old['kind'] ?? '') === 'wylaczna' ? ' checked' : '' ?>><span>wyłączna</span></label>
        </div><?= $err('kind') ?></div>
      <div class="sm:tw-col-span-2"><label class="m-label" for="f-scope">Zaakceptowane sposoby korzystania i ewentualne ograniczenia</label>
        <textarea class="m-input" id="f-scope" name="accepted_scope" rows="5"<?= $aria('accepted_scope') ?>><?= $v('accepted_scope') ?></textarea><?= $err('accepted_scope') ?></div>
      <div><label class="m-label" for="f-pf">Okres licencji — od</label><input class="m-input" id="f-pf" type="date" name="period_from" value="<?= $v('period_from') ?>"<?= $aria('period_from') ?>><?= $err('period_from') ?></div>
      <div><label class="m-label" for="f-pt">Okres licencji — do</label><input class="m-input" id="f-pt" type="date" name="period_to" value="<?= $v('period_to') ?>"<?= $aria('period_to') ?>><?= $err('period_to') ?></div>
      <div><label class="m-label" for="f-ter">Terytorium</label><input class="m-input" id="f-ter" name="territory" value="<?= $v('territory') ?>"<?= $aria('territory') ?>><?= $err('territory') ?></div>
      <div><label class="m-label" for="f-terms">Wynagrodzenie / pozostałe warunki</label><input class="m-input" id="f-terms" name="terms" value="<?= $v('terms') ?>"></div>
      <div class="sm:tw-col-span-2"><label class="m-label" for="f-ref">Uzasadnienie odmowy (przy odmowie, opcjonalnie)</label><textarea class="m-input" id="f-ref" name="refusal_reason" rows="2"><?= $v('refusal_reason') ?></textarea></div>
      <div><label class="m-label" for="f-place">Miejscowość</label><input class="m-input" id="f-place" name="place" value="<?= $v('place') ?>" autocomplete="address-level2"<?= $aria('place') ?>><?= $err('place') ?></div>
      <div><label class="m-label" for="f-date">Data</label><input class="m-input" id="f-date" type="date" name="date" value="<?= $v('date') ?: h(ldToday()) ?>"></div>
      <div class="sm:tw-col-span-2"><label class="m-label" for="f-sig">Imię i nazwisko osoby podejmującej decyzję (w imieniu licencjodawcy) *</label>
        <input class="m-input" id="f-sig" name="signer_name" value="<?= $v('signer_name') ?>" autocomplete="name"<?= $aria('signer_name') ?>><?= $err('signer_name') ?></div>
    </div>
    <label class="m-check tw-mt-4"><input type="checkbox" name="confirm" value="1"<?= !empty($old['confirm']) ? ' checked' : '' ?><?= $aria('confirm') ?>>
      <span>Potwierdzam decyzję. Zapis elektroniczny (data, godzina, adres IP) zastępuje podpis w sekcji H wniosku.</span></label><?= $err('confirm') ?>
  </fieldset>
  <button type="submit" class="m-btn">Zapisz decyzję</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
