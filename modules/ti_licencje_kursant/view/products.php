<?php
if (!isset($me, $products)) { header('Location: /karty30/ti/kursant/licencje/?_route=%2Fprodukty', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/products.php — zakładka „Produkty i usługi”: usługi kupowane przez Fundację FEER i refakturowane na uczestnika
 * (modules/ti_products: domeny, hosting, licencje i inne — z ceną miesięczną i rabatem) oraz konto Microsoft 365 na tenancie FEER. Prostym językiem.
 * Zmienne: $me, $products, $feerUpn, $flash, $org.
 */
$tab = 'produkty';
include __DIR__ . '/_top.php';
$chip = ['active' => 'tw-bg-m-green', 'expiring' => 'tw-bg-m-amber', 'suspended' => 'tw-bg-m-red'];
$stateTxt = ['active' => 'działa', 'expiring' => 'kończy się wkrótce', 'suspended' => 'zawieszona'];
?>
<p class="tw-text-sm tw-mb-4">Fundacja kupuje te usługi u dostawców w Twoim imieniu, a potem wystawia Ci fakturę (refaktura). Dane dostępowe przekazujemy osobno.
  Opłaty za Claude i NordVPN znajdziesz w zakładce <a href="<?= h(route('lk.index')) ?>">Licencje</a>.</p>

<?php if (!$products && $feerUpn === ''): ?>
<section class="m-tile"><p>Nie masz jeszcze żadnych usług od Fundacji. Jeśli potrzebujesz np. domeny albo hostingu do projektu, napisz do prowadzącego.</p></section>
<?php endif; ?>

<?php if ($products): ?>
<section class="m-tile" aria-labelledby="h-prod">
  <h2 id="h-prod" class="m-head">Usługi od Fundacji</h2>
  <?php foreach ($products as $p):
    $t = TiProducts::TYPES[$p['product_type']] ?? TiProducts::TYPES['other'];
    $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p);
    $amt = (float)($p['monthly_amount'] ?? 0);
    $disc = (float)($p['discount_value'] ?? 0);
    $after = $amt > 0 && $disc > 0 ? ($p['discount_type'] === 'pct' ? round($amt * (1 - $disc / 100), 2) : max(0, round($amt - $disc, 2))) : $amt;
  ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold tw-break-all<?= $p['product_type'] === 'domain' ? ' tw-font-mono' : '' ?>"><?= h($p['name']) ?></span>
      <span class="tw-text-sm tw-text-m-steel"><?= h($t['label']) ?> · <?= h(TiProducts::providerName($p)) ?></span>
      <span class="m-chip <?= $chip[$p['status']] ?? 'tw-bg-m-steel' ?>"><?= h($stateTxt[$p['status']] ?? (TiProducts::STATUSES[$p['status']]['label'] ?? $p['status'])) ?></span>
    </div>
    <p class="tw-text-sm tw-mt-1">
      <?php if ($p['expires_at']): ?>Opłacona do <b><?= h(ClaudeBilling::dayMonth((string)$p['expires_at'])) ?></b><?php
        if ($hint === 'overdue'): ?> — <span class="tw-text-m-red tw-font-semibold">termin minął, napisz do biura, jeśli chcesz ją przedłużyć</span><?php
        elseif ($hint === 'soon'): ?> — <span class="tw-font-semibold">zostało <?= (int)$dl ?> <?= $dl === 1 ? 'dzień' : 'dni' ?></span><?php endif; ?>.
      <?php else: ?>Bez daty końca.<?php endif; ?>
      <?php if ($amt > 0): ?>Płacisz co miesiąc <b><?= h(ClaudeBilling::zl($after)) ?></b><?= $after < $amt ? ' (zamiast ' . h(ClaudeBilling::zl($amt)) . ($p['discount_note'] !== '' ? ' — ' . h($p['discount_note']) : '') . ')' : '' ?><?= !empty($p['billing_day']) ? ', fakturę dostajesz ok. ' . (int)$p['billing_day'] . '. dnia miesiąca' : '' ?>.<?php endif; ?>
    </p>
    <?php if ($p['product_type'] !== 'domain' && trim((string)$p['server_details']) !== ''): ?><p class="tw-text-xs tw-text-m-steel">Pakiet / serwer: <?= h($p['server_details']) ?></p><?php endif; ?>
    <?php if (trim((string)$p['invoice_own']) !== ''): ?><p class="tw-text-xs tw-text-m-steel">Ostatnia faktura od Fundacji: <span class="tw-font-mono"><?= h($p['invoice_own']) ?></span></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($feerUpn !== ''): $hint = rawurlencode($feerUpn); ?>
<section class="m-tile" style="border-left:6px solid #0050ef" aria-labelledby="h-m365">
  <h2 id="h-m365" class="m-head">Konto Microsoft 365 w Fundacji</h2>
  <p class="tw-mb-2">Twój login: <span class="tw-font-mono tw-font-semibold"><?= h($feerUpn) ?></span> <button type="button" class="m-btn-ghost tw-py-0 lk-copy" data-copy="<?= h($feerUpn) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
  <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mb-2">
    <a class="m-btn" href="https://www.office.com/?login_hint=<?= h($hint) ?>" target="_blank" rel="noopener">Otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a>
    <a class="m-btn-ghost" href="https://outlook.office.com/mail/?login_hint=<?= h($hint) ?>" target="_blank" rel="noopener">Poczta<span class="tw-sr-only"> (nowa karta)</span></a>
    <a class="m-btn-ghost" href="https://teams.microsoft.com/?login_hint=<?= h($hint) ?>" target="_blank" rel="noopener">Teams<span class="tw-sr-only"> (nowa karta)</span></a>
  </div>
  <p class="tw-text-xs tw-text-m-steel">Hasło startowe przyszło e-mailem albo SMS-em — przy pierwszym logowaniu Microsoft poprosi o nowe. Nie pamiętasz hasła? Napisz do prowadzącego.</p>
  <div class="tw-sr-only" id="lk-live" aria-live="polite"></div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
