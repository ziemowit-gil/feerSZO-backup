<?php
if (!isset($P, $actor)) { header('Location: /karty30/ti/dydaktyk/claudeAbonament.php', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/claude_billing/view/index.php — ekran zarządzania „Płatne licencje” (panel dydaktyka), przebudowany 2026-11-09:
 * nagłówek z wyborem produktu i stanem (Fakturownia, zamawianie, cena), pasek liczb (stonowany, bez kolorowych kafli),
 * zakładki: Wnioski (kreator uczestnika → wpłata → faktura VAT → dostęp, te same kroki co w karcie Helpdesku) · Abonamenty ·
 * Uczestnicy (konto w panelu licencji, aktywacja za uczestnika) · Cena i zamawianie (kurs, przebieg, komunikat, zmiana ceny) · Ustawienia.
 * Zmienne: $tab, $requests, $rows, $filter, $stats, $s, $rate, $q, $found, $changes, $changeAnswers, $actor, $product, $P.
 */
$title = 'Płatne licencje: ' . $P['label'];
$kierCur = 'claudeAbonament.php';
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
require_once dirname(__DIR__) . '/logic/ClaudeInvoice.php';
require_once dirname(__DIR__) . '/logic/PriceChange.php';
include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_head.php';
$U = fn(string $t, array $x = []) => route('cb.index', ['product' => $product, 'tab' => $t] + $x);
$tag = fn(string $cls, string $txt) => '<span class="cb-tag ' . h($cls) . '">' . h($txt) . '</span>';
$stCls = ['requested' => 'due', 'active' => 'ok', 'suspended' => 'bad', 'ended' => 'off'];
$live = ClaudeInvoice::live($s);
$fkAcc = trim((string)org_setting('fakturownia_account'));
$win = !$s['enabled'] ? ['wyłączone', 'bad'] : (($s['available_until'] !== '' && $s['available_until'] < date('Y-m-d')) ? ['zamknięte od ' . ClaudeBilling::date($s['available_until']), 'bad']
     : (($s['available_from'] !== '' && $s['available_from'] > date('Y-m-d')) ? ['od ' . ClaudeBilling::date($s['available_from']), 'due'] : ['otwarte' . ($s['available_until'] !== '' ? ' do ' . ClaudeBilling::date($s['available_until']) : ''), 'ok']));
$tabs = ['wnioski' => 'Wnioski', 'abonamenty' => 'Abonamenty', 'uczestnicy' => 'Uczestnicy', 'cena' => 'Cena i zamawianie', 'ustawienia' => 'Ustawienia'];
require_once dirname(__DIR__) . '/logic/LicenceOffice.php';
// Konto Office uczestnika (inicjał.nazwisko@edu) w każdej zakładce: stan z SZO (bez pytania tenanta przy wyświetlaniu); przycisk sprawdza tenant EDU i zakłada konto, gdy go nie ma
$officeCell = function (int $cid, string $back = '') use ($tag, $product): string {
    $o = LicenceOffice::summary($cid);
    $out = $o['upn'] !== '' ? '<span class="font-monospace small">' . h($o['upn']) . '</span> ' : '';
    $out .= $o['ok'] ? $tag('ok', 'aktywne') : $tag($o['state'] === 'error' ? 'bad' : 'off', $o['label']);
    if ($o['checked'] !== '') $out .= '<span class="d-block small text-secondary">sprawdzono ' . h($o['checked']) . '</span>';
    if ($back !== '' && !$o['ok']) {
        $out .= '<form method="post" action="' . h(route('cb.office', ['cid' => $cid])) . '" class="mt-1">' . Router::csrfField()
              . '<input type="hidden" name="product" value="' . h($product) . '"><input type="hidden" name="back" value="' . h($back) . '">'
              . '<button class="cb-btn ghost" type="submit">Sprawdź / załóż<span class="visually-hidden"> konto Office</span></button></form>';
    }
    return $out;
};
$reqForm = function (int $sid, string $act, string $label, string $cls, string $extra = '') {
    return '<form method="post" action="' . h(route('cb.req', ['id' => $sid, 'act' => $act])) . '" class="d-inline-block">' . Router::csrfField() . $extra
         . '<button class="cb-btn ' . h($cls) . '" type="submit">' . h($label) . '</button></form>';
};
?>
<?php include __DIR__ . '/_style.php'; ?>
<div class="cbx">
<div class="cb-head">
  <h1 class="mb-0">Płatne licencje</h1>
  <nav class="cb-prod" aria-label="Produkt"><?php foreach (ClaudeBilling::PRODUCTS as $pk => $pp): ?><a href="<?= h(route('cb.index', ['product' => $pk, 'tab' => $tab])) ?>"<?= $pk === $product ? ' aria-current="page"' : '' ?>><?= h($pp['label']) ?></a><?php endforeach; ?></nav>
</div>
<div class="cb-state" role="status">
  <span>Cena: <b><?= $s['priced'] ? h(ClaudeBilling::priceShort($s)) . ($rate ? ' ≈ ' . h(ClaudeBilling::zl((float)$rate['amount_pln'])) : '') . ' miesięcznie' : 'nieustawiona' ?></b></span>
  <span>Zamawianie: <?= $tag($win[1], $win[0]) ?></span>
  <span>Fakturownia: <?= $live ? $tag('ok', 'podłączona') : $tag('bad', !empty($s['simulate']) ? 'symulacja włączona' : 'niepodłączona, symulacja') ?> <a href="<?= h($U('ustawienia')) ?>#fk-h">szczegóły</a></span>
  <span><a href="<?= h(route('cb.test', ['product' => $product])) ?>">Test całego przebiegu</a></span>
</div>

<div class="cb-metrics" role="list">
  <a role="listitem" class="cb-metric<?= $requests ? ' warn' : '' ?>" href="<?= h($U('wnioski')) ?>"><span class="n"><?= count($requests) ?></span><span class="l">wnioski do obsługi</span></a>
  <a role="listitem" class="cb-metric" href="<?= h($U('abonamenty', ['status' => 'active'])) ?>"><span class="n"><?= $stats['active'] ?></span><span class="l">aktywne</span></a>
  <a role="listitem" class="cb-metric<?= $stats['suspended'] ? ' alert' : '' ?>" href="<?= h($U('abonamenty', ['status' => 'suspended'])) ?>"><span class="n"><?= $stats['suspended'] ?></span><span class="l">wstrzymane</span></a>
  <div role="listitem" class="cb-metric"><span class="n"><?= $stats['pending'] ?></span><span class="l">do zapłaty · <?= h(ClaudeBilling::zl($stats['pending_sum'])) ?></span></div>
  <div role="listitem" class="cb-metric<?= $stats['overdue'] ? ' alert' : '' ?>"><span class="n"><?= $stats['overdue'] ?></span><span class="l">po terminie</span></div>
  <div role="listitem" class="cb-metric"><span class="n"><?= h(ClaudeBilling::zl($stats['paid_month'])) ?></span><span class="l">wpłaty w tym miesiącu</span></div>
  <div role="listitem" class="cb-metric<?= $stats['buyer_gaps'] ? ' alert' : '' ?>"><span class="n"><?= $stats['buyer_gaps'] ?></span><span class="l">brak danych do FV</span></div>
  <div role="listitem" class="cb-metric<?= $stats['no_invoice'] ? ' alert' : '' ?>"><span class="n"><?= $stats['no_invoice'] ?></span><span class="l">opłacone bez faktury</span></div>
</div>

<nav class="pivot" aria-label="Sekcje">
  <?php foreach ($tabs as $k => $l): ?><a href="<?= h($U($k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= h($l) ?><?= $k === 'wnioski' && $requests ? ' (' . count($requests) . ')' : '' ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'wnioski'): ?>
<p class="cb-explain">Wnioski złożone przez uczestników w kreatorze. Kolejność: proforma (powstaje sama) → wpłata → faktura VAT → aktywacja. Dopóki wniosek czeka, faktura po wpłacie nie wystawia się sama. Te same przyciski są w karcie zgłoszenia w Helpdesku.</p>
<?php if (!$requests): ?><p>Brak wniosków o <?= h($P['label']) ?> do obsługi.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm">
  <caption class="visually-hidden">Wnioski o <?= h($P['label']) ?></caption>
  <thead><tr><th scope="col">Uczestnik</th><th scope="col">Złożony</th><th scope="col">Kroki</th><th scope="col">Konto Office</th><th scope="col">Helpdesk</th><th scope="col">Następny krok</th></tr></thead>
  <tbody>
  <?php foreach ($requests as $r): $ch = $r['charge']; $paid = $ch && $ch['status'] === 'paid'; $inv = $ch ? (string)$ch['invoice_number'] : ''; $sid = (int)$r['id']; ?>
    <tr>
      <td><a href="<?= h(route('cb.show', ['id' => $sid])) ?>"><?= h((string)$r['client_name']) ?></a></td>
      <td class="text-nowrap"><?= h(ClaudeBilling::date($r['activated_on'])) ?></td>
      <td><ul class="cb-steps">
        <li class="<?= $ch && $ch['proforma_number'] !== '' ? 'done' : 'todo' ?>">Proforma <?= h($ch['proforma_number'] ?? '—') ?><?= $ch ? ', ' . h(ClaudeBilling::zl((float)$ch['amount_pln'])) . ', do ' . h(ClaudeBilling::date($ch['due_on'])) : '' ?><?= $ch && $ch['proforma_number'] !== '' ? ' · <a href="' . h(route('cb.doc', ['id' => (int)$ch['id'], 'doc' => 'proforma'])) . '" download>pobierz PDF<span class="visually-hidden"> proformy</span></a>' : '' ?></li>
        <li class="<?= $paid ? 'done' : 'todo' ?>"><?= $paid ? 'Wpłata ' . h(ClaudeBilling::date((string)$ch['paid_at'])) . ' (' . h((string)$ch['paid_via']) . ')' : 'Wpłata: jeszcze nie ma' ?></li>
        <li class="<?= $inv !== '' ? 'done' : 'todo' ?>"><?= $inv !== '' ? 'Faktura ' . h($inv) . ' · <a href="' . h(route('cb.doc', ['id' => (int)$ch['id'], 'doc' => 'invoice'])) . '" download>pobierz PDF<span class="visually-hidden"> faktury</span></a>' : 'Faktura VAT: po wpłacie' ?></li>
      </ul></td>
      <td><?= $officeCell((int)$r['client_id'], 'wnioski') ?></td>
      <td><?= (int)($r['helpdesk_ticket_id'] ?? 0) ? '<a href="' . h(APP_URL) . '/helpdesk/view.php?id=' . (int)$r['helpdesk_ticket_id'] . '" target="_blank" rel="noopener">' . h($r['helpdesk_number'] ?: 'zgłoszenie') . '<span class="visually-hidden"> (nowa karta)</span></a>' : h($r['helpdesk_number'] ?: '—') ?></td>
      <td>
        <?php if ($ch && !$paid): ?>
          <?= $reqForm($sid, 'paid', 'Wpłata dotarła', 'ghost', '<label class="visually-hidden" for="ref' . $sid . '">Tytuł przelewu</label><input class="form-control form-control-sm d-inline-block me-1" style="width:9rem" id="ref' . $sid . '" name="ref" placeholder="tytuł / nr (opcja)">') ?>
        <?php elseif ($paid && $inv === ''): ?>
          <?= $reqForm($sid, 'invoice', 'Wystaw fakturę VAT', '') ?>
        <?php else: ?>
          <?= $reqForm($sid, 'activate', 'Aktywuj dostęp', 'ok') ?>
        <?php endif; ?>
        <details class="mt-1"><summary class="small">Odrzuć</summary>
          <?= $reqForm($sid, 'reject', 'Odrzuć wniosek', 'danger', '<label class="form-label small mb-0" for="why' . $sid . '">Powód (uczestnik dostanie e-mail)</label><textarea class="form-control form-control-sm mb-1" id="why' . $sid . '" name="reason" rows="2" required minlength="5"></textarea>') ?>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>

<?php elseif ($tab === 'abonamenty'): ?>
<p class="cb-explain">Wszystkie abonamenty <?= h($P['label']) ?>. Kliknij osobę, żeby zobaczyć opłaty, dokumenty, dane do faktury i historię.</p>
<p class="small">Pokaż: <?php foreach (['' => 'wszystkie', 'requested' => 'wnioski', 'active' => 'aktywne', 'suspended' => 'wstrzymane', 'ended' => 'zakończone'] as $k => $l): ?>
  <a class="me-2<?= $filter === $k ? ' fw-bold' : '' ?>" href="<?= h($U('abonamenty', $k !== '' ? ['status' => $k] : [])) ?>"<?= $filter === $k ? ' aria-current="true"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></p>
<?php if (!$rows): ?><p>Brak abonamentów w tym widoku.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Abonamenty <?= h($P['label']) ?></caption>
  <thead><tr><th scope="col">Uczestnik</th><th scope="col">Stan</th><th scope="col">Od</th><th scope="col" class="text-end">Do zapłaty</th><th scope="col">Najbliższy termin</th><th scope="col">Dane do FV</th><th scope="col">Płatność</th><th scope="col">Konto Office</th><th scope="col">Helpdesk</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $g = ClaudeBilling::invoiceDataGaps($r); ?>
    <tr>
      <td><a href="<?= h(route('cb.show', ['id' => (int)$r['id']])) ?>"><?= h($r['client_name']) ?></a><?= (int)$r['free'] ? ' ' . $tag('off', 'bez opłat') : '' ?></td>
      <td><?= $tag($stCls[$r['status']] ?? 'off', ClaudeBilling::STATUS[$r['status']] ?? $r['status']) ?></td>
      <td class="text-nowrap"><?= h(ClaudeBilling::date($r['activated_on'])) ?></td>
      <td class="text-end text-nowrap"><?= (int)$r['n_pending'] ? h(ClaudeBilling::zl((float)$r['sum_pending'])) . ' (' . (int)$r['n_pending'] . ')' : '—' ?></td>
      <td class="text-nowrap<?= $r['next_due'] && $r['next_due'] < date('Y-m-d') ? ' text-danger fw-bold' : '' ?>"><?= h(ClaudeBilling::date($r['next_due'])) ?></td>
      <td><?= $g ? $tag('bad', 'brak: ' . implode(', ', $g)) : 'komplet' ?></td>
      <td class="small"><?php require_once dirname(__DIR__) . '/logic/LicenceCard.php'; $rc = LicenceCard::active((int)$r['client_id']); $rm = LicenceCard::mode($r); ?>
        <?= $rm === 'card' ? ($rc ? 'karta ' . h(LicenceCard::label($rc)) : $tag('due', 'karta: niepodpięta')) : 'proforma i przelew' ?><?= ($r['pay_mode'] ?? '') !== '' ? ' <span class="text-secondary">(wyjątek)</span>' : '' ?></td>
      <td><?= $officeCell((int)$r['client_id']) ?></td>
      <td><?= h($r['helpdesk_number'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>

<?php elseif ($tab === 'uczestnicy'): ?>
<?php require_once dirname(__DIR__, 2) . '/ti_licencje_kursant/logic/LicencjePanelAccess.php'; $lpUrl = LicencjePanelAuth::publicUrl(); ?>
<p class="cb-explain">Panel Licencji działa osobno od panelu uczestnika TI. Adres dla uczestników: <a href="<?= h($lpUrl) ?>" target="_blank" rel="noopener"><span class="font-monospace"><?= h($lpUrl) ?></span><span class="visually-hidden"> (nowa karta)</span></a>
  <button type="button" class="cb-btn ghost" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= h($lpUrl) ?>');this.textContent='Skopiowano'">Kopiuj adres</button></p>
<p class="cb-explain">„Wyślij dane do panelu” zakłada login (l + 8 cyfr, nadawany raz) i nowe hasło. Uczestnik dostaje e-mail z adresem i loginem oraz SMS z hasłem. Dane dostają tylko osoby z licencjami albo produktami; płatny produkt liczy się dopiero z fakturą VAT. Tu możesz też włączyć <?= h($P['label']) ?> za uczestnika, bez kreatora.</p>
<form method="get" action="claudeAbonament.php" class="d-flex gap-2 mb-3" role="search" style="max-width:520px"><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="tab" value="uczestnicy">
  <label class="visually-hidden" for="cb-q">Szukaj uczestnika</label><input class="form-control" id="cb-q" name="q" value="<?= h($q) ?>" placeholder="Imię lub nazwisko">
  <button class="cb-btn" type="submit">Szukaj</button>
</form>
<?php if ($found): ?>
<div class="table-responsive"><table class="table table-sm">
  <caption class="visually-hidden">Wyniki wyszukiwania</caption>
  <thead><tr><th scope="col">Uczestnik</th><th scope="col">Licencja <?= h($P['label']) ?></th><th scope="col">Abonament</th><th scope="col">Konto Office</th><th scope="col">Włącz za uczestnika</th><th scope="col">Panel licencji</th></tr></thead><tbody>
  <?php foreach ($found as $c): $ll = LicenseLogin::get((int)$c['id']); ?>
    <tr><td><?= h($c['name']) ?></td><td><?= $c['claude'] ? h($c['claude']['license_name']) : '<span class="text-secondary">nieprzypisana</span>' ?></td>
      <td><?= $c['sub'] ? '<a href="' . h(route('cb.show', ['id' => (int)$c['sub']['id']])) . '">' . $tag($stCls[$c['sub']['status']] ?? 'off', ClaudeBilling::STATUS[$c['sub']['status']] ?? '') . '</a>' : '—' ?></td>
      <td><?= $officeCell((int)$c['id'], 'uczestnicy:' . $q) ?></td>
      <td><?php if (!$c['sub'] || $c['sub']['status'] === 'ended'): ?>
        <form method="post" action="<?= h(route('cb.activate')) ?>" class="d-flex gap-1 align-items-center"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
          <label class="visually-hidden" for="cb-d<?= (int)$c['id'] ?>">Data włączenia</label><input class="form-control form-control-sm" style="width:150px" type="date" id="cb-d<?= (int)$c['id'] ?>" name="date" value="<?= date('Y-m-d') ?>">
          <button class="cb-btn ok" type="submit">Włącz</button></form>
      <?php else: ?>—<?php endif; ?></td>
      <td><?= $ll ? '<span class="font-monospace small me-1">' . h($ll) . '</span>' : '' ?>
        <?php $lpWhy = LicencjePanelAccess::reasons((int)$c['id']); ?>
        <?php if ($lpWhy): ?>
        <form method="post" action="<?= h(route('cb.lic.account')) ?>" class="d-inline"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <button class="cb-btn<?= $ll && LicenseLogin::hasPassword((int)$c['id']) ? ' ghost' : '' ?>" type="submit"><?= $ll && LicenseLogin::hasPassword((int)$c['id']) ? 'Wyślij nowe hasło' : 'Wyślij dane do panelu' ?><span class="visually-hidden"> (<?= h($c['name']) ?>)</span></button></form>
        <span class="d-block small text-secondary"><?= h(implode(', ', array_slice($lpWhy, 0, 3))) ?></span>
        <?php else: $lpPw = LicencjePanelAccess::paidWithoutInvoice((int)$c['id']); ?>
        <span class="small text-secondary">Brak licencji i produktów<?= $lpPw ? '. Płatne bez faktury VAT: ' . h(implode(', ', $lpPw)) : '' ?>.</span>
        <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php elseif ($q !== ''): ?><p>Nikogo nie znaleziono.</p><?php endif; ?>
<?php $lpCand = LicencjePanelAccess::candidates(); ?>
<section class="panel mt-3" aria-labelledby="lp-c-h">
  <h2 class="mt-0" id="lp-c-h">Bez danych do Panelu Licencji (<?= count($lpCand) ?>)</h2>
  <p class="cb-explain mb-2">Osoby z licencjami albo produktami, które nie mają jeszcze hasła do panelu.</p>
  <?php if (!$lpCand): ?><p class="mb-0">Wszyscy z licencjami albo produktami mają już dane.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <caption class="visually-hidden">Uczestnicy bez danych do Panelu Licencji</caption>
    <thead><tr><th scope="col">Uczestnik</th><th scope="col">Ma</th><th scope="col">Dane do panelu</th></tr></thead><tbody>
    <?php foreach ($lpCand as $lc): ?>
      <tr><td><?= h($lc['name']) ?></td><td class="small"><?= h(implode(', ', array_slice($lc['reasons'], 0, 3))) ?><?= count($lc['reasons']) > 3 ? ' i ' . (count($lc['reasons']) - 3) . ' inne' : '' ?></td>
        <td><form method="post" action="<?= h(route('cb.lic.account')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><input type="hidden" name="client_id" value="<?= (int)$lc['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <button class="cb-btn" type="submit">Wyślij dane do panelu<span class="visually-hidden"> (<?= h($lc['name']) ?>)</span></button></form></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'cena'): $nt = ClaudeBilling::noticeText($product); ?>
<section class="panel" aria-labelledby="rate-h">
  <h2 class="mt-0" id="rate-h">Kurs i kwota</h2>
  <p class="mb-2"><?php if ($rate): ?>Kurs <?= h($rate['kind_label']) ?> PKO BP (tabela <?= h(PkoRates::tableLabel($rate)) ?>): <b><?= h(ClaudeBilling::rateFmt((float)$rate['rate'])) ?>/USD</b>
    → <?= h(ClaudeBilling::priceShort($s)) ?> = <b><?= h(ClaudeBilling::zl((float)$rate['amount_pln'])) ?></b> miesięcznie<?= $s['list_usd'] > $s['usd'] ? ' (cena regularna ' . h(ClaudeBilling::usd($s['list_usd'])) . ' = ' . h(ClaudeBilling::zl(ClaudeBilling::listService($rate))) . ' bez prowizji, rabat non-profit ' . h(ClaudeBilling::zl((float)$rate['discount_pln'])) . ')' : '' ?>.
    <?php else: ?>Nie pobrano jeszcze kursu z PKO BP.<?php endif; ?></p>
  <div class="d-flex flex-wrap gap-2">
    <form method="post" action="<?= h(route('cb.rate')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="cb-btn" type="submit">Pobierz kurs teraz</button></form>
    <form method="post" action="<?= h(route('cb.run')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="cb-btn ghost" type="submit">Uruchom przebieg dzienny</button></form>
  </div>
  <p class="small text-secondary mt-2 mb-0">Przebieg dzienny robi to samo co CRON co godzinę: naliczenia, przypomnienia, wstrzymania, zmiany cen i dokumenty, które wcześniej nie wyszły.</p>
</section>

<section class="panel" aria-labelledby="win-h">
  <h2 class="mt-0" id="win-h">Zamawianie przez uczestników <?= $tag($win[1], $win[0]) ?></h2>
  <p class="small mb-2">Włączanie i daty „od / do” ustawiasz w zakładce Ustawienia. Komunikat trafia do panelu uczestników (Komunikaty TI, przypięty, 30 dni).</p>
  <details><summary class="small">Podgląd komunikatu</summary><p class="fw-semibold mt-2 mb-1"><?= h($nt['title']) ?></p><p class="small" style="white-space:pre-line"><?= h($nt['body']) ?></p></details>
  <form method="post" action="<?= h(route('cb.notice')) ?>" class="d-flex flex-wrap gap-3 align-items-center mt-2"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="email" value="1" id="n-email"><label class="form-check-label" for="n-email">wyślij też e-mailem do wszystkich uczestników</label></div>
    <button class="cb-btn" type="submit">Opublikuj komunikat</button>
  </form>
</section>

<?php $openChg = null; foreach ($changes as $c) if (in_array($c['status'], ['draft', 'sent'], true)) { $openChg = $c; break; } ?>
<section class="panel" id="zmiana-ceny" aria-labelledby="zc-h">
  <h2 class="mt-0" id="zc-h">Zmiana ceny <?= h($P['label']) ?></h2>
  <p class="small mb-2">Każdy abonent dostaje e-mail (było, będzie, od kiedy) z linkiem: akceptuję albo rezygnuję. Za osobę niepełnoletnią decyduje opiekun. W dniu zmiany cena przestawia się sama, a rezygnujący kończą abonament przed pierwszym dokumentem po nowej cenie.</p>
  <?php if ($openChg): $cmp = PriceChange::compare($openChg); ?>
    <div class="border p-3 mb-3 bg-white">
      <p class="mb-1"><b>#<?= (int)$openChg['id'] ?> od <?= h(ClaudeBilling::date($openChg['effective_from'])) ?></b>: <?= h(PriceChange::STATUS[$openChg['status']] ?? $openChg['status']) ?></p>
      <p class="mb-1">Teraz: <?= h($cmp['old']) ?><?= $cmp['oldPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['oldPln'])) . ')' : '' ?>. Od zmiany: <b><?= h($cmp['new']) ?></b><?= $cmp['newPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['newPln'])) . ')' : '' ?>.</p>
      <?php if ($openChg['reason'] !== ''): ?><p class="small mb-1">Powód: <?= h($openChg['reason']) ?></p><?php endif; ?>
      <p class="small mb-2">Brak odpowiedzi: <?= h(PriceChange::NO_ANSWER[$openChg['no_answer']] ?? '') ?>.<?php if ($openChg['status'] === 'sent'): ?> Odpowiedzi: <?= (int)$openChg['n_acc'] ?> akceptuje, <?= (int)$openChg['n_rej'] ?> rezygnuje, <?= max(0, (int)$openChg['n'] - (int)$openChg['n_acc'] - (int)$openChg['n_rej']) ?> bez odpowiedzi (z <?= (int)$openChg['n'] ?>).<?php endif; ?></p>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($openChg['status'] === 'draft'): ?>
        <form method="post" action="<?= h(route('cb.price.send', ['id' => (int)$openChg['id']])) ?>"><?= Router::csrfField() ?><button class="cb-btn" type="submit">Wyślij powiadomienia do abonentów</button></form>
        <?php endif; ?>
        <form method="post" action="<?= h(route('cb.price.cancel', ['id' => (int)$openChg['id']])) ?>"><?= Router::csrfField() ?><button class="cb-btn danger" type="submit">Wycofaj zmianę</button></form>
      </div>
      <?php if ($changeAnswers): ?>
      <div class="table-responsive mt-3"><table class="table table-sm mb-0"><caption class="small">Abonenci i ich decyzje</caption>
        <thead><tr><th scope="col">Uczestnik</th><th scope="col">Decyduje</th><th scope="col">Decyzja</th><th scope="col">Kiedy, kto</th></tr></thead><tbody>
        <?php foreach ($changeAnswers as $a): ?>
          <tr><td><?= h((string)$a['name']) ?></td><td><?= h($a['recipient']) ?><?= $a['recipient_email'] === '' ? ' <span class="text-danger">(brak e-maila)</span>' : '' ?></td>
            <td><?= $a['decision'] === 'accept' ? 'akceptuje' : ($a['decision'] === 'reject' ? '<b>rezygnuje</b>' : 'brak odpowiedzi') ?></td>
            <td class="small"><?= $a['decided_at'] ? h(ClaudeBilling::date($a['decided_at']) . ', ' . $a['decided_name']) : '—' ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <details><summary class="fw-semibold">Zaplanuj zmianę ceny</summary>
    <form method="post" action="<?= h(route('cb.price.create')) ?>" class="mt-3"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="zc-from">Od kiedy</label><input class="form-control" type="date" id="zc-from" name="effective_from" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= date('Y-m-01', strtotime('first day of +2 month')) ?>" aria-describedby="zc-from-h"><div id="zc-from-h" class="form-text">Najlepiej 1. dzień miesiąca, co najmniej 30 dni naprzód.</div></div>
        <div class="col-md-3"><label class="form-label" for="zc-usd">Nowa cena (USD)</label><input class="form-control" id="zc-usd" name="usd" required inputmode="decimal" value="<?= h((string)$s['usd']) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="zc-list">Cena regularna (USD)</label><input class="form-control" id="zc-list" name="list_usd" inputmode="decimal" value="<?= h((string)$s['list_usd']) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="zc-pct"><?= h($s['pct_label']) ?> (%)</label><input class="form-control" id="zc-pct" name="pct" inputmode="decimal" value="<?= h((string)$s['pct']) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="zc-fee">Prowizja (zł)</label><input class="form-control" id="zc-fee" name="fee_pln" inputmode="decimal" value="<?= h(number_format($s['fee_pln'], 2, '.', '')) ?>"></div>
        <div class="col-md-8"><label class="form-label" for="zc-why">Powód (trafi do e-maila)</label><input class="form-control" id="zc-why" name="reason" maxlength="500" placeholder="np. Anthropic podniósł cenę dla organizacji non-profit"></div>
        <div class="col-md-4"><fieldset><legend class="form-label fs-6">Gdy ktoś nie odpowie</legend>
          <?php foreach (PriceChange::NO_ANSWER as $k => $l): ?><div class="form-check"><input class="form-check-input" type="radio" name="no_answer" value="<?= h($k) ?>" id="zc-na-<?= h($k) ?>"<?= $k === 'accept' ? ' checked' : '' ?>><label class="form-check-label small" for="zc-na-<?= h($k) ?>"><?= h($l) ?></label></div><?php endforeach; ?>
        </fieldset></div>
      </div>
      <button class="cb-btn mt-3" type="submit">Zaplanuj (powiadomienia wyślesz w następnym kroku)</button>
    </form>
  </details>
  <?php endif; ?>
  <?php $past = array_filter($changes, fn($c) => !in_array($c['status'], ['draft', 'sent'], true)); if ($past): ?>
  <details class="mt-2"><summary class="small">Wcześniejsze zmiany (<?= count($past) ?>)</summary><ul class="small mt-2 mb-0">
    <?php foreach ($past as $c): ?><li>#<?= (int)$c['id'] ?> od <?= h(ClaudeBilling::date($c['effective_from'])) ?>: <?= h(number_format((float)$c['usd'], 2, ',', ' ')) ?> USD, <?= h(PriceChange::STATUS[$c['status']] ?? $c['status']) ?> (akceptacje <?= (int)$c['n_acc'] ?>, rezygnacje <?= (int)$c['n_rej'] ?>)</li><?php endforeach; ?>
  </ul></details>
  <?php endif; ?>
</section>

<?php else: /* ustawienia */ ?>
<section class="panel" aria-labelledby="fk-h">
  <h2 class="mt-0" id="fk-h">Fakturownia: tylko dokumenty płatnych licencji</h2>
  <p class="mb-1"><?= $live ? 'Podłączona: konto <b>' . h($fkAcc) . '.fakturownia.pl</b>. Proformy, zamówienia i faktury VAT wystawiają się w Fakturowni.'
      : (!empty($s['simulate']) ? 'Włączona symulacja (przełącznik niżej): dokumenty SYM-… tylko w SZO.' : 'Niepodłączona: dokumenty powstają w symulacji (SYM-…) i zostaną wystawione naprawdę, gdy połączenie zadziała.') ?></p>
  <p class="small mb-0">Konfiguracja: Administracja › <a href="<?= h(APP_URL) ?>/admin/invoices_settings.php">Faktury</a> (administrator): „Konto Fakturowni” i „Token API”, stawka zw i podstawa zwolnienia, „Testuj połączenie”. „Backend faktur” na tamtej stronie dotyczy innych faktur SZO, nie licencji. Konto testowe uczestnika zawsze zostaje w symulacji.</p>
</section>
<?php require_once dirname(__DIR__) . '/logic/LicenceCrm.php';
      $crmG = null; try { $crmG = db_one("SELECT g.id, g.name, (SELECT COUNT(*) FROM crm_group_members m WHERE m.group_id=g.id) n FROM crm_groups g WHERE g.auto_source=?", [LicenceCrm::SOURCE]); } catch (\Throwable $e) {} ?>
<section class="panel" aria-labelledby="crm-h">
  <h2 class="mt-0 h6" id="crm-h">Grupa w CRM</h2>
  <p class="mb-2"><?= $crmG ? '„' . h($crmG['name']) . '”: ' . (int)$crmG['n'] . ' kontaktów. <a href="' . h(APP_URL) . '/crm/group/view.php?id=' . (int)$crmG['id'] . '">Otwórz w CRM</a>' : 'Grupa „' . h(LicenceCrm::NAME) . '” powstanie przy pierwszym dodaniu.' ?></p>
  <p class="small text-secondary mb-2">Uczestnicy z płatną licencją (wniosek, aktywna, wstrzymana) i z przypisanym oprogramowaniem trafiają tam sami: przy wniosku, przy włączeniu przez kadrę i raz dziennie (CRON). Nowe kontakty CRM powstają bez automatyzacji i bez e-maili. Z grupy nikt nie jest usuwany.</p>
  <form method="post" action="<?= h(route('cb.crm')) ?>"><?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>"><button class="cb-btn ghost" type="submit">Dopisz teraz wszystkich obecnych</button></form>
</section>
<section class="panel" aria-labelledby="set-h">
  <h2 class="mt-0" id="set-h">Ustawienia <?= h($P['label']) ?></h2>
  <form method="post" action="<?= h(route('cb.settings')) ?>">
    <?= Router::csrfField() ?><input type="hidden" name="product" value="<?= h($product) ?>">
    <fieldset class="mb-3"><legend class="fs-6 fw-semibold">Włączniki</legend>
      <div class="d-flex flex-wrap gap-4">
      <?php foreach (['enabled' => 'Wnioski od uczestników przyjmowane', 'simulate' => 'Symuluj dokumenty (nie wysyłaj do Fakturowni)', 'invoice_email' => 'Wysyłaj faktury e-mailem', 'notify' => 'E-maile do uczestników (proforma, przypomnienie, wstrzymanie)', 'helpdesk' => 'Zgłoszenia w Helpdesku przy aktywacji, wstrzymaniu, rezygnacji'] as $k => $l): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= h($k) ?>" value="1" id="s-<?= h($k) ?>"<?= $s[$k] ? ' checked' : '' ?>><label class="form-check-label" for="s-<?= h($k) ?>"><?= h($l) ?></label></div>
      <?php endforeach; ?>
      </div>
      <div class="form-text">Wnioski z kreatora zawsze trafiają do Helpdesku, niezależnie od ostatniego przełącznika.</div>
    </fieldset>
    <fieldset class="mb-3"><legend class="fs-6 fw-semibold">Cena</legend>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label" for="s-usd">Opłata miesięczna (USD<?= $product === 'claude' ? ', cena non-profit' : '' ?>)</label><input class="form-control" id="s-usd" name="usd" value="<?= h((string)$s['usd']) ?>" inputmode="decimal"></div>
      <div class="col-md-3"><label class="form-label" for="s-list">Cena regularna (USD)</label><input class="form-control" id="s-list" name="list_usd" value="<?= h((string)$s['list_usd']) ?>" inputmode="decimal" aria-describedby="s-list-h"><div id="s-list-h" class="form-text">Rabat na fakturze. 0 = bez rabatu.</div></div>
      <div class="col-md-2"><label class="form-label" for="s-pct">Narzut (%)</label><input class="form-control" id="s-pct" name="pct" value="<?= h((string)$s['pct']) ?>" inputmode="decimal" aria-describedby="s-pct-h"><div id="s-pct-h" class="form-text">23 = VAT od importu usługi.</div></div>
      <div class="col-md-2"><label class="form-label" for="s-pctl">Nazwa narzutu</label><input class="form-control" id="s-pctl" name="pct_label" value="<?= h($s['pct_label']) ?>" maxlength="30"></div>
      <div class="col-md-2"><label class="form-label" for="s-fee">Prowizja (zł)</label><input class="form-control" id="s-fee" name="fee_pln" value="<?= h(number_format($s['fee_pln'], 2, '.', '')) ?>" inputmode="decimal"></div>
      <div class="col-md-4"><label class="form-label" for="s-kind">Kurs PKO BP</label><select class="form-select" id="s-kind" name="rate_kind"><?php foreach (PkoRates::KINDS as $k => $l): ?><option value="<?= h($k) ?>"<?= $s['rate_kind'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label" for="s-disc">Rabat non-profit na fakturze</label><select class="form-select" id="s-disc" name="discount_mode"><option value="rabat"<?= $s['discount_mode'] === 'rabat' ? ' selected' : '' ?>>rabat kwotowy na pozycji</option><option value="opis"<?= $s['discount_mode'] === 'opis' ? ' selected' : '' ?>>cena non-profit + opis rabatu</option></select></div>
    </div></fieldset>
    <fieldset class="mb-3"><legend class="fs-6 fw-semibold">Terminy i okno wniosków</legend>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label" for="s-day">Dzień dokumentu</label><input class="form-control" id="s-day" name="due_day" type="number" min="1" max="28" value="<?= (int)$s['due_day'] ?>" aria-describedby="s-day-h"><div id="s-day-h" class="form-text">1 = proforma 1. dnia miesiąca. Termin zawsze <?= ClaudeBilling::TERM_DAYS ?> dni.</div></div>
      <div class="col-md-3"><label class="form-label" for="s-grace">Wstrzymanie po (dni po terminie)</label><input class="form-control" id="s-grace" name="grace_days" type="number" min="0" max="60" value="<?= (int)$s['grace_days'] ?>"></div>
      <div class="col-md-3"><label class="form-label" for="s-from">Wnioski od</label><input class="form-control" type="date" id="s-from" name="available_from" value="<?= h($s['available_from']) ?>" aria-describedby="s-win-h"></div>
      <div class="col-md-3"><label class="form-label" for="s-until">Wnioski do</label><input class="form-control" type="date" id="s-until" name="available_until" value="<?= h($s['available_until']) ?>" aria-describedby="s-win-h"><div id="s-win-h" class="form-text">Puste = bez ograniczenia. Działające abonamenty trwają dalej.</div></div>
    </div></fieldset>
    <fieldset class="mb-3" aria-describedby="pos-h"><legend class="fs-6 fw-semibold">Pozycje na fakturze</legend>
      <p class="small text-secondary mb-2" id="pos-h">Tak będą się nazywać pozycje na proformie, zamówieniu i fakturze VAT (Fakturownia i rejestr faktur). Pola do wstawienia:
        <?php $pf = []; foreach (ClaudeBilling::POSITION_FIELDS as $tk => $tl) $pf[] = '<code>' . h($tk) . '</code> (' . h($tl) . ')'; echo implode(', ', $pf); ?>. W nazwie usługi musi być <code>{okres}</code>.</p>
      <div class="row g-3">
        <div class="col-md-7"><label class="form-label" for="s-pos">Nazwa pozycji usługi</label><input class="form-control" id="s-pos" name="position_name" maxlength="200" value="<?= h($s['position_name']) ?>" aria-describedby="s-pos-p">
          <div class="form-text" id="s-pos-p">Podgląd: <b><?= h(ClaudeBilling::positionText($s['position_name'], $product, date('Y-m'), 'Jan Kowalski')) ?></b></div></div>
        <div class="col-md-5"><label class="form-label" for="s-feen">Nazwa pozycji prowizji</label><input class="form-control" id="s-feen" name="fee_name" maxlength="200" value="<?= h($s['fee_name']) ?>" aria-describedby="s-feen-p">
          <div class="form-text" id="s-feen-p">Podgląd: <b><?= h(ClaudeBilling::positionText($s['fee_name'], $product, date('Y-m'), 'Jan Kowalski')) ?></b><?= $s['fee_pln'] > 0 ? '' : ' (prowizja = 0 zł, pozycja się nie pojawi)' ?></div></div>
      </div>
      <div class="form-text">Puste pole przywraca nazwę domyślną. Zmiana dotyczy nowych dokumentów; wystawionych się nie zmienia.</div>
    </fieldset>
    <?php require_once dirname(__DIR__, 3) . '/includes/p24.php'; require_once dirname(__DIR__) . '/logic/LicenceCard.php'; $p24ok = p24_enabled(); ?>
    <fieldset class="mb-3" aria-describedby="pm-h"><legend class="fs-6 fw-semibold">Sposób płatności</legend>
      <p class="small text-secondary mb-2" id="pm-h">Karta: uczestnik podpina kartę w Panelu Licencji (zgoda, kod SMS, PDF zgody w jego dokumentach). Najpierw pobieramy opłatę weryfikacyjną z fakturą VAT,
        potem co miesiąc opłatę z karty w dniu terminu (wcześniej e-mail z kwotą i dniem), a po każdej wpłacie od razu fakturę VAT. Po <?= LicenceCard::MAX_ATTEMPTS ?> nieudanych próbach opłata przechodzi na proformę i przelew.
        Wymaga Przelewy24 z usługą kart i płatności cyklicznych (włącza ją opiekun konta w P24): <?= $p24ok ? $tag('ok', 'Przelewy24 włączone') : $tag('bad', 'Przelewy24 wyłączone') ?> <a href="<?= h(APP_URL) ?>/admin/p24_settings.php">ustawienia Przelewy24</a>.</p>
      <div class="row g-3">
        <div class="col-md-5" role="radiogroup" aria-labelledby="pm-l"><span class="form-label d-block" id="pm-l">Jak płacą uczestnicy za <?= h($P['label']) ?></span>
          <?php foreach (LicenceCard::PAY_MODES as $pmk => $pml): ?>
          <div class="form-check"><input class="form-check-input" type="radio" name="pay_mode" id="pm-<?= h($pmk) ?>" value="<?= h($pmk) ?>"<?= $s['pay_mode'] === $pmk ? ' checked' : '' ?><?= $pmk === 'card' && !$p24ok && $s['pay_mode'] !== 'card' ? ' disabled' : '' ?>>
            <label class="form-check-label" for="pm-<?= h($pmk) ?>"><?= h(ucfirst($pml)) ?></label></div>
          <?php endforeach; ?>
          <div class="form-text">Dla jednej osoby zmienisz to na karcie abonamentu.</div></div>
        <div class="col-md-3"><label class="form-label" for="s-cfee">Opłata weryfikacyjna karty (zł)</label><input class="form-control" id="s-cfee" name="card_fee_pln" inputmode="decimal" value="<?= h(number_format((float)$s['card_fee_pln'], 2, ',', '')) ?>"></div>
        <div class="col-md-4"><label class="form-label" for="s-cfeen">Nazwa pozycji na fakturze</label><input class="form-control" id="s-cfeen" name="card_fee_name" maxlength="200" value="<?= h($s['card_fee_name']) ?>"></div>
      </div>
    </fieldset>
    <?php if (!empty($P['office'])): ?>
    <fieldset class="mb-3" aria-describedby="sku-h"><legend class="fs-6 fw-semibold">Licencja w tenancie edukacyjnym</legend>
      <p class="small text-secondary mb-2" id="sku-h">Konto Office ma domyślnie A1 (bez aplikacji stacjonarnych). Po aktywacji <?= h($P['label']) ?> SZO nada kontu tę licencję w tenancie edukacyjnym. Puste pole: Helpdesk nadaje ją ręcznie.</p>
      <div class="row g-3"><div class="col-md-6"><label class="form-label" for="s-sku">Identyfikator licencji (SKU) <?= h($P['office']) ?></label>
        <input class="form-control font-monospace" id="s-sku" name="tenant_sku" value="<?= h($s['tenant_sku']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" aria-describedby="sku-h"></div></div>
    </fieldset>
    <?php endif; ?>
    <fieldset class="mb-3"><legend class="fs-6 fw-semibold">Dokumenty i płatności</legend>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="s-inv">Dokumenty w Fakturowni</label><select class="form-select" id="s-inv" name="invoice"><option value="auto"<?= $s['invoice'] === 'auto' ? ' selected' : '' ?>>automatycznie</option><option value="manual"<?= $s['invoice'] === 'manual' ? ' selected' : '' ?>>ręcznie z karty abonamentu</option><option value="off"<?= $s['invoice'] === 'off' ? ' selected' : '' ?>>wyłączone</option></select></div>
      <div class="col-md-4"><label class="form-label" for="s-pay">Przycisk „Zapłać” u uczestnika</label><select class="form-select" id="s-pay" name="pay_redirect"><option value="p24"<?= $s['pay_redirect'] === 'p24' ? ' selected' : '' ?>>Przelewy24 od razu</option><option value="portal"<?= $s['pay_redirect'] === 'portal' ? ' selected' : '' ?>>portal płatności SZO</option><option value="fakturownia"<?= $s['pay_redirect'] === 'fakturownia' ? ' selected' : '' ?>>Szybki przelew z dokumentu w Fakturowni</option></select></div>
      <div class="col-md-4"><label class="form-label" for="s-acc">Rachunek do wpłat</label><input class="form-control font-monospace" id="s-acc" name="bank_account" value="<?= h(ClaudeBilling::payAccount(0, $product)['nrb_fmt'] ?? '') ?>"></div>
      <div class="col-md-8"><label class="form-label" for="s-fast">Szybki przelew (Fakturownia)</label><input class="form-control" id="s-fast" name="fast_transfer_url" value="<?= h($s['fast_transfer_url']) ?>"><div class="form-text"><a href="<?= h($s['fast_transfer_url']) ?>" target="_blank" rel="noopener">Konfiguracja w Fakturowni<span class="visually-hidden"> (nowa karta)</span></a></div></div>
    </div></fieldset>
    <button class="cb-btn" type="submit">Zapisz ustawienia</button>
  </form>
</section>
<?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_foot.php'; ?>
