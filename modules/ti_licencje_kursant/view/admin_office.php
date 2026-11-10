<?php
if (!isset($adm, $plans)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/admin_office.php — Office ze zniżką edukacyjną: ceny planów A3 i A5 (A1 w cenie), edytuje kierownik albo administrator.
 * Zapis tylko ceny i zamawiania (ClaudeBilling::savePrices) — VAT, prowizja i reszta ustawień produktu bez zmian. Zmienne: $adm, $plans, $org, $flash.
 */
$PAGE_TITLE = 'Office: ceny planów';
require_once dirname(__DIR__) . '/logic/LicencjeAdminNav.php';
$nav = LicencjeAdminNav::sideNav('ceny-office');
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
$num = fn(float $v) => $v > 0 ? rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') : '';
?>
<h1 class="m-title">Office: ceny planów</h1>
<p class="m-explain">Konto Office ma domyślnie plan A1, w cenie i bez aplikacji stacjonarnych. Tu ustalasz miesięczną cenę planów A3 i A5 dla uczestników. Do ceny doliczamy VAT i prowizję jak przy innych płatnych licencjach.</p>
<form method="post" action="<?= h(route('lk.admin.prices.save')) ?>" novalidate><?= Router::csrfField() ?>
  <div class="tw-grid md:tw-grid-cols-2 tw-gap-4">
  <?php foreach ($plans as $k => $pl): $s = $pl['s']; $r = $pl['rate']; ?>
    <section class="m-tile" aria-labelledby="pl-<?= h($k) ?>">
      <h2 class="m-sect" id="pl-<?= h($k) ?>"><?= h($pl['name']) ?> <?= $s['enabled'] && $s['priced'] ? $tag('ok', 'można zamawiać') : $tag('off', 'zamawianie wyłączone') ?></h2>
      <ul class="tw-list-disc tw-pl-5 tw-text-sm tw-mb-3"><?php foreach ($pl['features'] as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
      <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
        <div><label class="m-label" for="usd-<?= h($k) ?>">Cena miesięczna (USD)</label>
          <input class="m-input" id="usd-<?= h($k) ?>" name="usd[<?= h($k) ?>]" inputmode="decimal" value="<?= h($num((float)$s['usd'])) ?>" placeholder="np. 3,50" aria-describedby="usd-h-<?= h($k) ?>"></div>
        <div><label class="m-label" for="lst-<?= h($k) ?>">Cena regularna (USD, opcjonalnie)</label>
          <input class="m-input" id="lst-<?= h($k) ?>" name="list_usd[<?= h($k) ?>]" inputmode="decimal" value="<?= h($num((float)$s['list_usd'])) ?>" placeholder="bez zniżki"></div>
      </div>
      <p class="m-note tw-mt-1" id="usd-h-<?= h($k) ?>"><?= $s['priced'] && $r ? 'Dziś uczestnik zapłaci około ' . h(ClaudeBilling::zl((float)$r['amount_pln'])) . ' miesięcznie (' . h(ClaudeBilling::priceShort($s)) . ', kurs PKO BP).'
      : ($s['priced'] ? 'Uczestnik zapłaci ' . h(ClaudeBilling::priceShort($s)) . ' miesięcznie. Kwotę w złotych policzymy po kursie PKO BP z dnia proformy.' : 'Bez ceny uczestnik może tylko wysłać wniosek do biura.') ?></p>
      <label class="m-check tw-mt-2"><input type="checkbox" name="enabled[<?= h($k) ?>]" value="1"<?= $s['enabled'] ? ' checked' : '' ?>><span>Uczestnicy mogą zamawiać <?= h($k) ?> w Panelu Licencji</span></label>
      <p class="m-note tw-mt-2"><a href="/licencje/claudeAbonament.php?product=<?= h($pl['product']) ?>&amp;tab=ustawienia">Pozostałe ustawienia <?= h($k) ?></a> (VAT, prowizja, sposób płatności, licencja w tenancie)</p>
    </section>
  <?php endforeach; ?>
  </div>
  <button class="m-btn tw-mt-4" type="submit">Zapisz ceny</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
