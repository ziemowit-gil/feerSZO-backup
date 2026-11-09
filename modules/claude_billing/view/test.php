<?php
if (!isset($P, $actor)) { header('Location: /karty30/ti/dydaktyk/claudeAbonament.php?_route=%2Ftest', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/claude_billing/view/test.php — test całego przebiegu abonamentu Claude na uczestniku testowym:
 * kurs PKO BP → aktywacja (zgłoszenie Helpdesk — podgląd) → naliczenie z proformą w Fakturowni (rabat non-profit, termin 5 dni)
 * → pozycja w portalu płatności → symulowana wpłata Przelewy24 (ten sam kod co webhook) → proforma „opłacona” → przebieg dzienny z datą
 * (kolejny miesiąc, przypomnienie, wstrzymanie, wznowienie) → sprzątanie. Bez e-maili do ludzi i bez pieniędzy.
 * Zmienne: $sub, $charges, $events, $s, $quote, $quoteErr, $log, $tickets, $clientId, $buyer, $fakturowniaReady, $p24, $actor.
 */
$title = $P['label'] . ' — test całego przebiegu'; $isTest = true;
$kierCur = 'claudeAbonament.php';
include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_head.php';
$open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
$nextMonth = date('Y-m-', strtotime('first day of next month')) . str_pad((string)min((int)date('j'), (int)$s['due_day']), 2, '0', STR_PAD_LEFT);
?>
<div class="pivot"><?php foreach (ClaudeBilling::PRODUCTS as $pk => $pp): ?><a href="<?= h(route('cb.index', ['product' => $pk])) ?>"<?= $pk === $product && empty($isTest) ? ' aria-current="page"' : '' ?>><?= h($pp['label']) ?></a><?php endforeach; ?><a href="<?= h(route('cb.test', ['product' => $product])) ?>"<?= !empty($isTest) ? ' aria-current="page"' : '' ?>>Test całego przebiegu (<?= h($P['label']) ?>)</a></div>
<h1>Test całego przebiegu</h1>
<p class="text-secondary">Uczestnik testowy: <b><?= h(ClaudeBillingController::TEST_CLIENT) ?></b> (id <?= (int)$clientId ?>). Test nie wysyła e-maili ani SMS-ów do ludzi i nie pobiera pieniędzy.
  W Fakturowni powstaje <b>proforma</b> (nie dokument księgowy) — „Wyczyść test” usuwa ją z Fakturowni. Zgłoszenia Helpdesk są tylko podglądem, chyba że zaznaczysz inaczej.</p>

<section class="panel accent">
  <h2 class="mt-0">1. Kurs PKO BP i kwota</h2>
  <?php if ($quote): ?>
    <p class="mb-1">Kurs <?= h($quote['kind_label']) ?> USD z tabeli PKO BP <?= h(PkoRates::tableLabel($quote)) ?>: <b><?= h(ClaudeBilling::rateFmt($quote['rate'])) ?></b><?= $quote['stale'] ? ' <span class="chip" style="background:#a20025">' . h($quote['source']) . '</span>' : '' ?></p>
    <p class="mb-0">Cena regularna <?= h(ClaudeBilling::usd($quote['list_usd'])) ?> = <?= h(ClaudeBilling::zl($quote['list_pln'])) ?> · rabat non-profit <?= h(ClaudeBilling::zl($quote['discount_pln'])) ?> · <b>do zapłaty <?= h(ClaudeBilling::priceShort($quote)) ?> = <?= h(ClaudeBilling::zl($quote['amount_pln'])) ?></b></p>
  <?php else: ?><p class="text-danger mb-0">Nie udało się pobrać kursu: <?= h($quoteErr) ?></p><?php endif; ?>
  <p class="small mt-2 mb-0">Fakturownia: <?= ClaudeInvoice::live($s) ? '<b>podłączona</b> — proforma i zamówienie powstaną naprawdę' : '<b class="text-danger">SYMULACJA</b> — ' . ($s['simulate'] ? 'włączony tryb symulacji' : 'Fakturownia niepodłączona') . '; dokumenty dostaną numery SYM-… i nie trafią do Fakturowni' ?> ·
    Przelewy24: <?= $p24['enabled'] ? 'włączone (' . h($p24['env'] === 'sandbox' ? 'sandbox' : 'produkcja — w teście tylko symulacja') . ')' : 'wyłączone — w teście symulacja' ?> ·
    Rachunek do wpłat: <span class="font-monospace"><?= h(ClaudeBilling::payAccount(0, $product)['nrb_fmt'] ?? '—') ?></span></p>
</section>

<?php if (!$open): ?>
<section class="panel">
  <h2 class="mt-0">2. Aktywacja (jak uczestnik w panelu)</h2>
  <form method="post" action="<?= h(route('cb.test.start')) ?>">
    <?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label small" for="t-date">Data aktywacji</label><input class="form-control form-control-sm" type="date" id="t-date" name="date" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-md-3"><label class="form-label small" for="t-day">Dzień graniczny (puste = <?= (int)$s['due_day'] ?>)</label><input class="form-control form-control-sm" type="number" min="1" max="28" id="t-day" name="due_day"></div>
      <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
      <div class="col-md-3"><label class="form-label small" for="t-<?= h($k) ?>"><?= h(ucfirst($l)) ?></label><input class="form-control form-control-sm" id="t-<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] === 'claude-test@example.invalid' ? '' : $buyer[$k])) ?>"></div>
      <?php endforeach; ?>
    </div>
    <p class="small text-secondary mt-1 mb-2">Wyczyść któreś pole danych do faktury, żeby sprawdzić komunikat „brak danych do FV”. E-mail nabywcy w teście nie dostaje wiadomości.</p>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="real_tickets" value="1" id="t-real"><label class="form-check-label" for="t-real">Utwórz prawdziwe zgłoszenia w Helpdesku (z dopiskiem [TEST])</label></div>
    <button class="btn-metro green" type="submit">Uruchom test — aktywuj abonament testowy</button>
  </form>
</section>
<?php else: ?>
<section class="panel">
  <h2 class="mt-0">2. Abonament testowy <span class="chip" style="background:<?= $sub['status'] === 'active' ? '#008a00' : '#a20025' ?>"><?= h(ClaudeBilling::STATUS[$sub['status']]) ?></span></h2>
  <p class="mb-0">Od <?= h(ClaudeBilling::date($sub['activated_on'])) ?>, cykl od <?= h(ClaudeBilling::date($sub['cycle_start'])) ?>, dzień graniczny <?= ClaudeBilling::dueDay($sub, $s) ?>.
    <?= ($g = ClaudeBilling::invoiceDataGaps($sub)) ? '<span class="text-danger fw-semibold">Brak danych do FV: ' . h(implode(', ', $g)) . '</span>' : 'Dane do FV kompletne.' ?></p>
</section>
<?php endif; ?>

<?php if ($charges): ?>
<section class="panel">
  <h2 class="mt-0">3. Naliczenia, proforma i płatność</h2>
  <?php $test = true; include __DIR__ . '/_charges.php'; ?>
</section>
<?php endif; ?>

<?php if ($open): ?>
<section class="panel">
  <h2 class="mt-0">4. Upływ czasu (przebieg dzienny dla podanej daty)</h2>
  <p class="small">Przykłady: kolejny miesiąc — <?= h(ClaudeBilling::date($nextMonth)) ?>; przypomnienie — dzień terminu; wstrzymanie — termin + <?= (int)$s['grace_days'] + 1 ?> dni; po „Symuluj wpłatę” przy wstrzymanym — wznowienie.</p>
  <form method="post" action="<?= h(route('cb.test.time')) ?>" class="d-flex gap-2 align-items-end"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <div><label class="form-label small" for="t-today">Data</label><input class="form-control form-control-sm" type="date" id="t-today" name="today" value="<?= h($nextMonth) ?>"></div>
    <button class="btn-metro dark btn-sm" type="submit">Uruchom przebieg dla tej daty</button></form>
</section>
<?php endif; ?>

<?php if ($tickets): ?>
<section class="panel">
  <h2 class="mt-0">Zgłoszenia Helpdesk (podgląd)</h2>
  <?php foreach ($tickets as $t): ?><details class="mb-1"><summary><?= h($t['title']) ?></summary><pre class="small bg-light p-2" style="white-space:pre-wrap"><?= h($t['desc']) ?></pre></details><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($log || $events): ?>
<section class="panel">
  <h2 class="mt-0">Przebieg testu</h2>
  <?php if ($log): ?><ol class="small"><?php foreach ($log as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol><?php endif; ?>
  <?php if ($events): ?><details><summary class="small">Dziennik abonamentu (<?= count($events) ?>)</summary><ul class="small list-unstyled"><?php foreach ($events as $e): ?><li class="border-bottom py-1"><?= h(date('H:i:s', strtotime((string)$e['created_at']))) ?> — <?= h($e['text']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
<?php endif; ?>

<form method="post" action="<?= h(route('cb.test.clean')) ?>" class="mb-4"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="btn-metro red" type="submit">Wyczyść test (abonament, naliczenia, proformy w Fakturowni)</button></form>
<?php include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_foot.php'; ?>
