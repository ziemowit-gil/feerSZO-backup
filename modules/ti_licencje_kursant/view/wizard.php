<?php
if (!isset($me, $p, $step)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/wizard.php — kreator wniosku o płatną licencję (Claude, NordVPN).
 * Krok 1: co dostajesz i ile kosztuje · krok 2: dane do faktury · krok 3: podsumowanie i zgoda → kod SMS → wniosek, proforma z Fakturowni, zgłoszenie w Helpdesku.
 * Zmienne: $me, $p, $P, $s, $step, $el, $buyer, $accept, $err, $rate, $projection, $eduProposal, $eduReq, $org, $flash.
 */
$PAGE_TITLE = 'Wniosek: ' . $P['label'];
$steps = [1 => 'Co dostajesz', 2 => 'Dane do faktury', 3 => 'Podsumowanie'];
$back = LicencjeKursantController::pageUrl($p);
include __DIR__ . '/_top.php';
$fe = fn(string $k) => isset($err[$k]) ? '<p id="w-e-' . h($k) . '" class="m-err">' . h($err[$k]) . '</p>' : '';
$fa = fn(string $k) => isset($err[$k]) ? ' aria-invalid="true" aria-describedby="w-e-' . h($k) . '"' : '';
?>
<p class="m-note tw-mb-2"><a href="<?= h($back) ?>">← <?= h($P['label']) ?></a></p>
<h1 class="m-title">Wniosek: <?= h($P['label']) ?></h1>
<ol class="m-steps" aria-label="Kroki wniosku">
  <?php foreach ($steps as $k => $l): ?>
  <li<?= $k === $step ? ' aria-current="step" class="cur"' : ($k < $step ? ' class="done"' : '') ?>><span class="m-steps-n"><?= $k ?></span> <?= h($l) ?><?= $k < $step ? '<span class="tw-sr-only"> (zrobione)</span>' : '' ?></li>
  <?php endforeach; ?>
</ol>
<p class="tw-sr-only" aria-live="polite">Krok <?= $step ?> z 3: <?= h($steps[$step]) ?></p>

<div class="m-tile" style="max-width:760px">
<form method="post" action="<?= h(route('lk.wizard.post', ['product' => $p])) ?>" novalidate>
  <?= Router::csrfField() ?><input type="hidden" name="krok" value="<?= $step ?>">

<?php if ($step === 1): ?>
  <h2 class="m-head">Co dostajesz i ile to kosztuje</h2>
  <p class="tw-mb-3"><?= h(ucfirst($P['what'])) ?>.</p>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Cena miesięcznie</dt><dd><?= h(ClaudeBilling::priceShort($s)) ?><?= $rate ? ', dziś razem ' . h(ClaudeBilling::zl((float)$rate['amount_pln'])) : '' ?></dd></div>
    <?php if ($s['list_usd'] > $s['usd']): ?><div><dt>Cena regularna</dt><dd><?= h(number_format((float)$s['list_usd'], 2, ',', ' ')) ?> USD. Fundacja ma cenę dla organizacji non-profit i przekazuje Ci zniżkę.</dd></div><?php endif; ?>
  </dl>
  <?php if (!empty($projection['now'])): $x = $projection['now']; ?>
  <div class="m-box now tw-mb-4" style="max-width:340px"><div class="m-kv"><dt>Pierwsza płatność</dt></div><div class="m-num">≈ <?= h(ClaudeBilling::zl((float)$x['amount'])) ?></div>
    <div class="m-note tw-mt-1">Dokładna kwota według kursu PKO BP z dnia proformy.</div></div>
  <?php endif; ?>
  <h3 class="m-sect">Jak to działa</h3>
  <ol class="tw-list-decimal tw-pl-6 tw-mb-4 tw-text-sm tw-space-y-1">
    <li>Składasz wniosek. Od razu dostajesz proformę z Fakturowni (e-mailem i tutaj).</li>
    <li>Płacisz proformę w <?= ClaudeBilling::TERM_DAYS ?> dni: Przelewy24, BLIK albo zwykły przelew.</li>
    <li>Po wpłacie Helpdesk wystawia fakturę VAT i włącza dostęp. Dostaniesz e-mail.</li>
    <li>Potem co miesiąc: proforma, wpłata, faktura. Zrezygnować możesz w każdej chwili.</li>
  </ol>
  <?php if ($eduProposal !== ''): ?>
  <div class="pk-alert pk-alert-info"><?= h($P['label']) ?> działa tylko z kontem Microsoft 365 @<?= h(ClaudeBilling::EDU_DOMAIN) ?>. Nie masz go jeszcze. Założymy konto <b class="m-mono"><?= h($eduProposal) ?></b> przy włączeniu dostępu. Login dostaniesz e-mailem, hasło tymczasowe SMS-em.</div>
  <?php elseif ($eduReq): ?>
  <div class="pk-alert pk-alert-info">Konto <b class="m-mono"><?= h($eduReq['proposed']) ?></b> jest w przygotowaniu. Helpdesk włączy dostęp, gdy będzie gotowe.</div>
  <?php endif; ?>
  <button class="m-btn" type="submit">Dalej: dane do faktury</button>

<?php elseif ($step === 2): ?>
  <h2 class="m-head">Dane do faktury</h2>
  <p class="tw-text-sm tw-mb-3">Na te dane wystawimy proformę i fakturę. Pola z gwiazdką są wymagane. NIP wpisz tylko wtedy, gdy płaci firma.</p>
  <?php if ($err): ?><div class="pk-alert pk-alert-err" role="alert">Popraw zaznaczone pola (<?= count($err) ?>).</div><?php endif; ?>
  <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
    <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
    <div><label class="m-label" for="w-<?= h($k) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label>
      <input class="m-input" id="w-<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] ?? '')) ?>"<?= $fa($k) ?><?= $k === 'buyer_email' ? ' type="email" autocomplete="email"' : '' ?>><?= $fe($k) ?></div>
    <?php endforeach; ?>
  </div>
  <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-4">
    <button class="m-btn" type="submit">Dalej: podsumowanie</button>
    <button class="m-btn-ghost" type="submit" name="dir" value="back">Wstecz</button>
  </div>

<?php else: ?>
  <h2 class="m-head">Podsumowanie</h2>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Licencja</dt><dd><?= h($P['label']) ?></dd></div>
    <div><dt>Cena miesięcznie</dt><dd><?= h(ClaudeBilling::priceShort($s)) ?><?= $rate ? ' (dziś ' . h(ClaudeBilling::zl((float)$rate['amount_pln'])) . ')' : '' ?></dd></div>
    <div><dt>Nabywca</dt><dd><?= h((string)$buyer['buyer_name']) ?><br><?= h((string)$buyer['buyer_street']) ?>, <?= h(trim($buyer['buyer_post_code'] . ' ' . $buyer['buyer_city'])) ?><?= (string)$buyer['buyer_tax_no'] !== '' ? '<br>NIP ' . h((string)$buyer['buyer_tax_no']) : '' ?></dd></div>
    <div><dt>Proforma i faktura na e-mail</dt><dd><?= h((string)$buyer['buyer_email']) ?></dd></div>
    <?php if ($eduProposal !== ''): ?><div><dt>Konto do założenia</dt><dd class="m-mono"><?= h($eduProposal) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($err): ?><div class="pk-alert pk-alert-err" role="alert"><?= h(implode(' ', $err)) ?></div><?php endif; ?>
  <label class="m-check tw-mt-2"><input type="checkbox" name="accept" value="1"<?= $accept ? ' checked' : '' ?><?= $fa('accept') ?>><span class="tw-text-sm"><?= h(ClaudeBilling::termsText($s)) ?></span></label><?= $fe('accept') ?>
  <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-4">
    <button class="m-btn" type="submit">Składam wniosek</button>
    <button class="m-btn-ghost" type="submit" name="dir" value="back">Wstecz</button>
  </div>
  <p class="m-note tw-mt-2">Wymaga kodu SMS. Po złożeniu dostaniesz proformę z Fakturowni, a Helpdesk dostanie zgłoszenie.</p>
<?php endif; ?>
</form>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
