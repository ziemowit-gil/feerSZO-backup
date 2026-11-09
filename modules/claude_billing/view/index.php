<?php
if (!isset($P, $actor)) { header('Location: /karty30/ti/dydaktyk/claudeAbonament.php', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/claude_billing/view/index.php — pulpit „Abonament Claude” (panel dydaktyka): kafle, kurs PKO BP, ustawienia, aktywacja przez kadrę, lista.
 * Zmienne: $rows, $filter, $stats, $s, $rate, $q, $found, $actor.
 */
$title = 'Płatne licencje — ' . $P['label'];
$kierCur = 'claudeAbonament.php';
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_head.php';
$st = ['active' => '#008a00', 'suspended' => '#a20025', 'ended' => '#647687'];
?>
<div class="pivot"><?php foreach (ClaudeBilling::PRODUCTS as $pk => $pp): ?><a href="<?= h(route('cb.index', ['product' => $pk])) ?>"<?= $pk === $product && empty($isTest) ? ' aria-current="page"' : '' ?>><?= h($pp['label']) ?></a><?php endforeach; ?><a href="<?= h(route('cb.test', ['product' => $product])) ?>"<?= !empty($isTest) ? ' aria-current="page"' : '' ?>>Test całego przebiegu (<?= h($P['label']) ?>)</a></div>
<h1><span class="sym" style="background:<?= h($P['color']) ?>"><?= h(strtoupper(substr($P['label'], 0, 3))) ?></span> <?= h($P['label']) ?> <small class="text-secondary fs-6"><?= $s['priced'] ? h(ClaudeBilling::usd($s['usd'])) . '/mies. po kursie PKO BP' : 'cena nieustawiona — uzupełnij w Ustawieniach' ?><?= $P['uniqueEmail'] ? ' · jedna licencja na adres e-mail' : '' ?></small></h1>

<?php $live = ClaudeInvoice::live($s); if (!$live): ?>
<div class="panel warn" role="status"><b>Dokumenty są SYMULOWANE</b> — <?= $s['simulate'] ? 'włączony tryb symulacji w Ustawieniach' : 'Fakturownia nie jest podłączona (Administracja › Faktury: konto i token API)' ?>.
  Proformy, zamówienia i faktury dostają numery SYM-… i <b>nie powstają w Fakturowni</b>; uczestnicy ich nie widzą. Gdy Fakturownia zadziała, CRON wystawi je naprawdę.</div>
<?php endif; ?>
<div class="tiles" role="list">
  <a role="listitem" class="tile" style="background:#008a00" href="<?= h(route('cb.index', ['status' => 'active', 'product' => $product])) ?>"><span class="n"><?= $stats['active'] ?></span><span class="l">aktywne</span></a>
  <a role="listitem" class="tile" style="background:#a20025" href="<?= h(route('cb.index', ['status' => 'suspended', 'product' => $product])) ?>"><span class="n"><?= $stats['suspended'] ?></span><span class="l">wstrzymane (zaległość)</span></a>
  <div role="listitem" class="tile" style="background:#f0a30a"><span class="n"><?= $stats['pending'] ?></span><span class="l">do zapłaty · <?= h(ClaudeBilling::zl($stats['pending_sum'])) ?></span></div>
  <div role="listitem" class="tile" style="background:#fa6800"><span class="n"><?= $stats['overdue'] ?></span><span class="l">po terminie</span></div>
  <div role="listitem" class="tile wide" style="background:#1ba1e2"><span class="n"><?= h(ClaudeBilling::zl($stats['paid_month'])) ?></span><span class="l">wpłaty w tym miesiącu</span></div>
  <div role="listitem" class="tile" style="background:<?= $stats['buyer_gaps'] ? '#a20025' : '#647687' ?>"><span class="n"><?= $stats['buyer_gaps'] ?></span><span class="l">brak danych do FV</span></div>
  <div role="listitem" class="tile" style="background:<?= $stats['no_invoice'] ? '#a20025' : '#647687' ?>"><span class="n"><?= $stats['no_invoice'] ?></span><span class="l">opłacone bez faktury</span></div>
</div>

<section class="panel accent">
  <h2 class="mt-0">Kurs i kwota</h2>
  <p class="mb-2"><?php if ($rate): ?>Ostatni kurs <?= h($rate['kind_label']) ?> PKO BP (tabela <?= h(PkoRates::tableLabel($rate)) ?>): <b><?= h(ClaudeBilling::rateFmt((float)$rate['rate'])) ?>/USD</b>
    → <?= h(ClaudeBilling::priceShort($s)) ?> = <b><?= h(ClaudeBilling::zl((float)$rate['amount_pln'])) ?></b> miesięcznie<?= $s['list_usd'] > $s['usd'] ? ' (cena regularna ' . h(ClaudeBilling::usd($s['list_usd'])) . ' = ' . h(ClaudeBilling::zl((float)$rate['list_pln'])) . ', rabat non-profit ' . h(ClaudeBilling::zl((float)$rate['discount_pln'])) . ')' : '' ?>.
    <?php else: ?>Nie pobrano jeszcze kursu z PKO BP.<?php endif; ?></p>
  <div class="d-flex flex-wrap gap-2">
    <form method="post" action="<?= h(route('cb.rate')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="btn-metro" type="submit">Pobierz kurs teraz</button></form>
    <form method="post" action="<?= h(route('cb.run')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="btn-metro dark" type="submit">Uruchom przebieg dzienny (naliczenia, przypomnienia, wstrzymania)</button></form>
    <a class="btn-metro orange text-decoration-none" href="<?= h(route('cb.test', ['product' => $product])) ?>">Test całego przebiegu</a>
  </div>
</section>

<details class="panel">
  <summary class="fw-semibold">Ustawienia</summary>
  <form method="post" action="<?= h(route('cb.settings')) ?>" class="mt-3">
    <?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label" for="s-usd">Opłata miesięczna (USD<?= $product === 'claude' ? ', cena non-profit' : '' ?>)</label><input class="form-control" id="s-usd" name="usd" value="<?= h((string)$s['usd']) ?>" inputmode="decimal"></div>
      <div class="col-md-3"><label class="form-label" for="s-list">Cena regularna (USD) — rabat na fakturze</label><input class="form-control" id="s-list" name="list_usd" value="<?= h((string)$s['list_usd']) ?>" inputmode="decimal" aria-describedby="s-list-h"><div id="s-list-h" class="form-text">0 = bez rabatu na fakturze.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-day">Dzień graniczny naliczenia</label><input class="form-control" id="s-day" name="due_day" type="number" min="1" max="28" value="<?= (int)$s['due_day'] ?>" aria-describedby="s-day-h"><div id="s-day-h" class="form-text">1 = dokument zawsze 1. dnia miesiąca (domyślnie). Inny dzień: w dniu aktywacji, nie później niż ten dzień. Termin płatności: zawsze <?= ClaudeBilling::TERM_DAYS ?> dni.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-from">Zamawianie od</label><input class="form-control" type="date" id="s-from" name="available_from" value="<?= h($s['available_from']) ?>" aria-describedby="s-win-h"></div>
      <div class="col-md-3"><label class="form-label" for="s-until">Zamawianie do</label><input class="form-control" type="date" id="s-until" name="available_until" value="<?= h($s['available_until']) ?>" aria-describedby="s-win-h"><div id="s-win-h" class="form-text">Puste = bez ograniczenia. Poza tym oknem uczestnik nie włączy licencji; działające abonamenty trwają dalej.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-pct">Doliczany podatek / narzut (%)</label><input class="form-control" id="s-pct" name="pct" value="<?= h((string)$s['pct']) ?>" inputmode="decimal" aria-describedby="s-pct-h"><div id="s-pct-h" class="form-text">Domyślnie 23 (VAT od importu usługi, płaci uczestnik). 0 = bez narzutu.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-pctl">Nazwa narzutu</label><input class="form-control" id="s-pctl" name="pct_label" value="<?= h($s['pct_label']) ?>" maxlength="30"></div>
      <div class="col-md-3"><label class="form-label" for="s-fee">Prowizja za płatność (zł)</label><input class="form-control" id="s-fee" name="fee_pln" value="<?= h(number_format($s['fee_pln'], 2, '.', '')) ?>" inputmode="decimal" aria-describedby="s-fee-h"><div id="s-fee-h" class="form-text">Osobna pozycja faktury. 0 = bez prowizji.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-grace">Wstrzymanie po (dni po terminie)</label><input class="form-control" id="s-grace" name="grace_days" type="number" min="0" max="60" value="<?= (int)$s['grace_days'] ?>"></div>
      <div class="col-md-4"><label class="form-label" for="s-kind">Kurs PKO BP</label><select class="form-select" id="s-kind" name="rate_kind"><?php foreach (PkoRates::KINDS as $k => $l): ?><option value="<?= h($k) ?>"<?= $s['rate_kind'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label" for="s-acc">Rachunek do wpłat</label><input class="form-control font-monospace" id="s-acc" name="bank_account" value="<?= h(ClaudeBilling::payAccount(0, $product)['nrb_fmt'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label" for="s-inv">Faktury w Fakturowni</label><select class="form-select" id="s-inv" name="invoice"><option value="auto"<?= $s['invoice'] === 'auto' ? ' selected' : '' ?>>automatycznie przy naliczeniu</option><option value="manual"<?= $s['invoice'] === 'manual' ? ' selected' : '' ?>>ręcznie z karty abonamentu</option><option value="off"<?= $s['invoice'] === 'off' ? ' selected' : '' ?>>wyłączone</option></select></div>
      <div class="col-md-4"><label class="form-label" for="s-disc">Rabat non-profit na fakturze</label><select class="form-select" id="s-disc" name="discount_mode"><option value="rabat"<?= $s['discount_mode'] === 'rabat' ? ' selected' : '' ?>>rabat kwotowy na pozycji</option><option value="opis"<?= $s['discount_mode'] === 'opis' ? ' selected' : '' ?>>cena non-profit + opis rabatu</option></select></div>
      <div class="col-md-4"><label class="form-label" for="s-pay">Przycisk „Zapłać” w panelu uczestnika</label><select class="form-select" id="s-pay" name="pay_redirect"><option value="p24"<?= $s['pay_redirect'] === 'p24' ? ' selected' : '' ?>>Przelewy24 od razu (domyślnie)</option><option value="portal"<?= $s['pay_redirect'] === 'portal' ? ' selected' : '' ?>>portal płatności SZO (Przelewy24 / przelew)</option><option value="fakturownia"<?= $s['pay_redirect'] === 'fakturownia' ? ' selected' : '' ?>>Szybki przelew z faktury w Fakturowni</option></select></div>
      <div class="col-md-4"><label class="form-label" for="s-fast">Szybki przelew (Fakturownia)</label><input class="form-control" id="s-fast" name="fast_transfer_url" value="<?= h($s['fast_transfer_url']) ?>"><div class="form-text"><a href="<?= h($s['fast_transfer_url']) ?>" target="_blank" rel="noopener">Konfiguracja w Fakturowni</a></div></div>
      <div class="col-12 d-flex flex-wrap gap-4">
        <?php foreach (['enabled' => 'Zamawianie (samodzielne włączanie) przez uczestników włączone', 'invoice_email' => 'Wysyłaj faktury e-mailem', 'notify' => 'E-maile do uczestników (naliczenie, przypomnienie, wstrzymanie)', 'helpdesk' => 'Zgłoszenia w Helpdesku IT', 'simulate' => 'Symuluj wystawianie dokumentów (nie wysyłaj do Fakturowni)'] as $k => $l): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= h($k) ?>" value="1" id="s-<?= h($k) ?>"<?= $s[$k] ? ' checked' : '' ?>><label class="form-check-label" for="s-<?= h($k) ?>"><?= h($l) ?></label></div>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn-metro mt-3" type="submit">Zapisz ustawienia</button>
  </form>
</details>

<?php $win = !$s['enabled'] ? ['wyłączone', '#a20025'] : (($s['available_until'] !== '' && $s['available_until'] < date('Y-m-d')) ? ['zamknięte (do ' . ClaudeBilling::date($s['available_until']) . ')', '#a20025']
      : (($s['available_from'] !== '' && $s['available_from'] > date('Y-m-d')) ? ['od ' . ClaudeBilling::date($s['available_from']), '#f0a30a'] : ['otwarte' . ($s['available_until'] !== '' ? ' do ' . ClaudeBilling::date($s['available_until']) : ''), '#008a00']));
      $nt = ClaudeBilling::noticeText($product); ?>
<section class="panel">
  <h2 class="mt-0">Zamawianie przez uczestników <span class="chip" style="background:<?= $win[1] ?>"><?= h($win[0]) ?></span></h2>
  <p class="small mb-2">Przełącznik i daty „od / do” — w Ustawieniach. Komunikat trafia do panelu uczestników (Komunikaty TI, przypięty, 30 dni).</p>
  <details><summary class="small">Podgląd komunikatu</summary><p class="fw-semibold mt-2 mb-1"><?= h($nt['title']) ?></p><p class="small" style="white-space:pre-line"><?= h($nt['body']) ?></p></details>
  <form method="post" action="<?= h(route('cb.notice')) ?>" class="d-flex flex-wrap gap-3 align-items-center mt-2"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="email" value="1" id="n-email"><label class="form-check-label" for="n-email">wyślij też e-mailem do wszystkich uczestników</label></div>
    <button class="btn-metro orange" type="submit">Opublikuj komunikat dla uczestników</button>
  </form>
</section>

<details class="panel"<?= $q !== '' ? ' open' : '' ?>>
  <summary class="fw-semibold">Uczestnik: konto w panelu licencji · aktywacja za uczestnika</summary>
  <form method="get" action="claudeAbonament.php" class="d-flex gap-2 mt-3" role="search"><input type="hidden" name="product" value="<?= h($product) ?>">
    <label class="visually-hidden" for="cb-q">Szukaj uczestnika</label><input class="form-control" id="cb-q" name="q" value="<?= h($q) ?>" placeholder="Imię lub nazwisko">
    <button class="btn-metro" type="submit">Szukaj</button>
  </form>
  <?php if ($found): ?>
  <table class="table table-sm mt-2"><thead><tr><th>Uczestnik</th><th>Licencja <?= h($P['label']) ?></th><th>Abonament</th><th><span class="visually-hidden">Akcja</span></th><th>Panel licencji</th></tr></thead><tbody>
  <?php foreach ($found as $c): ?>
    <tr><td><?= h($c['name']) ?></td><td><?= $c['claude'] ? h($c['claude']['license_name']) : '<span class="text-secondary">brak</span>' ?></td>
      <td><?= $c['sub'] ? h(ClaudeBilling::STATUS[$c['sub']['status']]) : '—' ?></td>
      <td><?php if (!$c['sub'] || $c['sub']['status'] === 'ended'): ?>
        <form method="post" action="<?= h(route('cb.activate')) ?>" class="d-flex gap-1 align-items-center"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
          <label class="visually-hidden" for="cb-d<?= (int)$c['id'] ?>">Data aktywacji</label><input class="form-control form-control-sm" style="width:150px" type="date" id="cb-d<?= (int)$c['id'] ?>" name="date" value="<?= date('Y-m-d') ?>">
          <button class="btn-metro green btn-sm" type="submit">Aktywuj</button></form>
      <?php else: ?><a href="<?= h(route('cb.show', ['id' => (int)$c['sub']['id']])) ?>">karta</a><?php endif; ?></td>
      <td><?php $ll = LicenseLogin::get((int)$c['id']); ?><?= $ll ? '<span class="font-monospace small">' . h($ll) . '</span> ' : '' ?>
        <form method="post" action="<?= h(route('cb.lic.account')) ?>" class="d-inline"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <button class="btn-metro btn-sm<?= $ll ? ' dark' : '' ?>" type="submit"><?= $ll ? 'Nowe hasło' : 'Załóż konto w panelu licencji' ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php elseif ($q !== ''): ?><p class="mt-2">Nikogo nie znaleziono.</p><?php endif; ?>
</details>

<h2>Abonamenty <?= isset(ClaudeBilling::STATUS[$filter]) ? '— ' . h(ClaudeBilling::STATUS[$filter]) . ' <a class="fs-6" href="' . h(route('cb.index')) . '">pokaż wszystkie</a>' : '' ?></h2>
<?php if (!$rows): ?><p class="text-secondary">Brak abonamentów. Uczestnik z licencją <?= h($P['label']) ?> włącza dostęp w panelu uczestnika (Moje licencje).</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Abonamenty Claude</caption>
  <thead><tr><th scope="col">Uczestnik</th><th scope="col">Stan</th><th scope="col">Od</th><th scope="col">Dzień</th><th scope="col" class="text-end">Do zapłaty</th><th scope="col">Najbliższy termin</th><th scope="col">Dane do FV</th><th scope="col">Helpdesk</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $g = ClaudeBilling::invoiceDataGaps($r); ?>
    <tr>
      <td><a href="<?= h(route('cb.show', ['id' => (int)$r['id']])) ?>"><?= h($r['client_name']) ?></a><?= (int)$r['free'] ? ' <span class="chip" style="background:#647687">bez opłat</span>' : '' ?></td>
      <td><span class="chip" style="background:<?= $st[$r['status']] ?? '#647687' ?>"><?= h(ClaudeBilling::STATUS[$r['status']] ?? $r['status']) ?></span></td>
      <td><?= h(ClaudeBilling::date($r['activated_on'])) ?></td>
      <td><?= ClaudeBilling::dueDay($r, $s) ?><?= $r['due_day'] ? ' (indyw.)' : '' ?></td>
      <td class="text-end"><?= (int)$r['n_pending'] ? h(ClaudeBilling::zl((float)$r['sum_pending'])) . ' (' . (int)$r['n_pending'] . ')' : '—' ?></td>
      <td class="<?= $r['next_due'] && $r['next_due'] < date('Y-m-d') ? 'text-danger fw-bold' : '' ?>"><?= h(ClaudeBilling::date($r['next_due'])) ?></td>
      <td><?= $g ? '<span class="chip" style="background:#a20025">brak: ' . h(implode(', ', $g)) . '</span>' : '✓' ?></td>
      <td><?= h($r['helpdesk_number'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_foot.php'; ?>
