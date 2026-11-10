<?php
if (!isset($adm, $plans)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/admin_dysk.php — Dysk Online (pilotaż, sdata.net.pl): pakiety, ceny i dostępność — kierownik albo administrator.
 * Zmienne: $adm, $plans (LicencjeDysk::plans), $org, $flash.
 */
$PAGE_TITLE = 'Dysk Online: pakiety';
require_once dirname(__DIR__) . '/logic/LicencjeAdminNav.php';
$nav = LicencjeAdminNav::sideNav('dysk');
include __DIR__ . '/_top.php';
$num = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
?>
<h1 class="m-title">Dysk Online: pakiety i ceny</h1>
<p class="m-explain">Usługa pilotażowa, realizuje sdata.net.pl. Uczestnik wybiera pakiet przy zgłoszeniu do pilotażu, a zgłoszenie z linkiem do zamówienia u dostawcy trafia do Helpdesku. Aktywną usługę wpisujesz uczestnikowi w Usługach i produktach (dostawca sdata.net.pl).</p>
<form method="post" action="<?= h(route('lk.admin.drive.save')) ?>" novalidate><?= Router::csrfField() ?>
  <div class="tw-grid md:tw-grid-cols-3 tw-gap-4">
  <?php foreach ($plans as $k => $p): ?>
    <section class="m-tile" aria-labelledby="dp-<?= h($k) ?>">
      <h2 class="m-sect" id="dp-<?= h($k) ?>"><?= h($p['name']) ?> · <?= h($p['size']) ?></h2>
      <div><label class="m-label" for="pr-<?= h($k) ?>">Cena miesięczna (zł)</label><input class="m-input" id="pr-<?= h($k) ?>" name="p[<?= h($k) ?>][price]" inputmode="decimal" value="<?= h($num((float)$p['price'])) ?>"></div>
      <div class="tw-mt-2"><label class="m-label" for="us-<?= h($k) ?>">Liczba użytkowników</label><input class="m-input" id="us-<?= h($k) ?>" name="p[<?= h($k) ?>][users]" inputmode="numeric" value="<?= h((string)($p['users'] ?? '')) ?>" placeholder="nie podano"></div>
      <label class="m-check tw-mt-2"><input type="checkbox" name="p[<?= h($k) ?>][active]" value="1"<?= $p['active'] ? ' checked' : '' ?>><span>Pakiet widoczny dla uczestników</span></label>
      <p class="m-note tw-mt-2"><a href="<?= h($p['order']) ?>" target="_blank" rel="noopener">Zamówienie u dostawcy ↗<span class="tw-sr-only"> (sdata.net.pl, nowa karta)</span></a></p>
    </section>
  <?php endforeach; ?>
  </div>
  <button class="m-btn tw-mt-4" type="submit">Zapisz pakiety</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
