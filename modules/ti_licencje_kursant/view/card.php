<?php
if (!isset($me, $p, $t, $pv)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/card.php — zgoda na płatności kartą (treść ustalona przez Fundację, LicenceCard::CHECKBOX / INFO / BUTTON).
 * Przed zgodą: kwota opłaty weryfikacyjnej, opłaty miesięcznej i dzień obciążenia. Po zgodzie: kod jednorazowy → Przelewy24 (tylko karty).
 * Zmienne: $me, $p, $P, $sub, $t (teksty), $pv (kwoty), $card (podpięta karta albo null), $virtual, $p24on, $err, $org, $flash.
 */
$PAGE_TITLE = 'Zgoda na płatności kartą';
include __DIR__ . '/_top.php';
?>
<div class="m-tile" style="max-width:760px">
  <p class="m-note tw-mb-2"><a href="<?= h(route('lk.billing')) ?>">← Rozliczenia</a></p>
  <h1 class="m-head">Zgoda na płatności kartą</h1>
  <p class="tw-text-sm tw-mb-3">Za <?= h($P['label']) ?> płacisz kartą. Opłatę pobieramy co miesiąc automatycznie, a po każdej wpłacie dostajesz fakturę VAT. Kartę wskazujesz w Przelewy24, my nie widzimy jej numeru.</p>
  <?php if ($card): ?><div class="pk-alert pk-alert-info" role="status">Masz już podpiętą kartę <?= h(LicenceCard::label($card)) ?>. Nowa karta ją zastąpi.</div><?php endif; ?>

  <section aria-labelledby="c-kw" class="tw-mb-4">
    <h2 class="m-sect" id="c-kw">Kwoty i terminy</h2>
    <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
      <div><dt>Teraz: opłata weryfikacyjna karty</dt><dd><b><?= h(ClaudeBilling::zl($pv['verify'])) ?></b> (faktura VAT: <?= h($pv['verify_name']) ?>)</dd></div>
      <div><dt>Opłata miesięczna za <?= h($P['label']) ?></dt><dd><b><?= h(ClaudeBilling::zl($pv['monthly'])) ?></b><?= $pv['first_now'] ? ' za ' . h(ClaudeBilling::periodLabel($pv['first_period'])) . ', pobierzemy ją zaraz po weryfikacji karty' : '' ?></dd></div>
      <div><dt>Kolejne obciążenia</dt><dd>co miesiąc, w dniu terminu płatności (<?= (int)$pv['due_day'] ?>. dnia miesiąca + <?= (int)$pv['term'] ?> dni), najbliższe <?= h(ClaudeBilling::date($pv['next_on'])) ?></dd></div>
      <div><dt>Przed każdym obciążeniem</dt><dd>e-mail z kwotą i dniem obciążenia</dd></div>
    </dl>
    <p class="m-note">Kwota zależy od kursu dolara w PKO BP z dnia naliczenia, dlatego co miesiąc może się trochę różnić.</p>
  </section>

  <?php if (!$virtual && !$p24on): ?>
    <div class="pk-alert pk-alert-err" role="alert">Płatności kartą są chwilowo niedostępne. Spróbuj później albo napisz do biura.</div>
  <?php else: ?>
  <form method="post" action="<?= h(route('lk.card.post', ['product' => $p])) ?>" novalidate><?= Router::csrfField() ?>
    <?php if ($err !== ''): ?><div class="pk-alert pk-alert-err" role="alert" id="e-zgoda"><?= h($err) ?></div><?php endif; ?>
    <label class="m-check tw-items-start"><input type="checkbox" name="zgoda" value="1" required aria-describedby="zgoda-info"<?= $err !== '' ? ' aria-invalid="true"' : '' ?>>
      <span><?= h($t['checkbox']) ?></span></label>
    <div id="zgoda-info" class="tw-text-sm tw-mt-3" style="border-left:3px solid var(--pk-border);padding-left:12px">
      <?php foreach ($t['info'] as $par): ?><p class="tw-mb-2"><?= h($par) ?></p><?php endforeach; ?>
    </div>
    <button class="m-btn tw-mt-4" type="submit"><?= h($t['button']) ?></button>
    <p class="m-imp">Najpierw potwierdzisz zgodę kodem SMS. Zapiszemy datę, godzinę i adres IP, a zgodę w PDF dodamy do Twoich dokumentów.<?= $virtual ? ' Konto testowe: płatność i karta to tylko symulacja.' : ' Potem przejdziesz na bezpieczną stronę płatności.' ?></p>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
