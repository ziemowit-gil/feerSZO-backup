<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/spis.php — SPIS ELEKTRONICZNY dokumentów sprawy (QR z druku AM56): lista dokumentów z kodami,
 * rewizją i stanem — BEZ danych osobowych (numer sprawy, symbole dokumentów, kody, stany, daty).
 * Zmienne: $title, $number, $rows, $rev.
 */
$tone = 'info';
include __DIR__ . '/_top.php';
?>
<section class="tw-bg-m-cobalt tw-text-white tw-p-6" role="status">
  <p class="tw-text-lg">Spis elektroniczny dokumentów sprawy <b><?= h($number) ?></b> — obowiązuje rewizja druków <b>R<?= (int)$rev ?></b>.</p>
</section>
<section class="m-tile tw-mt-4" aria-label="Spis dokumentów">
  <div class="tw-overflow-x-auto">
  <table class="tw-w-full tw-text-left tw-text-sm">
    <thead><tr class="tw-border-b tw-border-solid tw-border-m-line"><th class="tw-py-2 tw-pr-3" scope="col">Dokument</th><th class="tw-py-2 tw-pr-3" scope="col">Numer</th><th class="tw-py-2 tw-pr-3" scope="col">Kod</th><th class="tw-py-2 tw-pr-3" scope="col">Stan</th><th class="tw-py-2" scope="col">Data</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $void = $r['state'] === 'UNIEWAŻNIONY'; ?>
      <tr class="tw-border-b tw-border-solid tw-border-m-line<?= $void ? ' tw-text-m-steel' : '' ?>">
        <td class="tw-py-2 tw-pr-3<?= $void ? ' tw-line-through' : '' ?>"><?= h($r['label']) ?></td><td class="tw-py-2 tw-pr-3"><?= h($r['number']) ?></td>
        <td class="tw-py-2 tw-pr-3"><code><?= h($r['code']) ?></code></td>
        <td class="tw-py-2 tw-pr-3"><span class="tw-inline-block tw-px-2 tw-py-0.5 tw-text-white tw-text-xs tw-font-semibold" style="background:<?= h($r['color']) ?>"><?= h($r['state']) ?></span></td>
        <td class="tw-py-2"><?= h($r['date'] !== '' ? ldDate(substr((string)$r['date'], 0, 10)) : '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!empty($espisUrl)): ?><p class="tw-mt-3"><a class="tw-inline-block tw-bg-m-cobalt tw-text-white tw-px-3 tw-py-1 tw-font-semibold" href="<?= h($espisUrl) ?>">Otwórz eSpis w EZD — podgląd wersji elektronicznej (po zalogowaniu)</a></p><?php endif; ?>
  <p class="tw-mt-3 tw-text-sm tw-text-m-steel">Druk z niższą rewizją niż R<?= (int)$rev ?> albo oznaczony „UNIEWAŻNIONY” jest nieaktualny. Spis nie zawiera danych osobowych.</p>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
