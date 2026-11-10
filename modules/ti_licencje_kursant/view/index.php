<?php
if (!isset($me, $paid)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/index.php — panel licencji: strona startowa z kaflami i osobne podstrony (polecenie 2026-11-09).
 * $page: 'start' (kafle) · 'claude' / 'nordvpn' (subskrypcja, /licencja/{produkt}) · 'office' · 'programy' · 'materialy' · 'sprawy' · 'produkty' · 'konto'.
 * Menu po lewej prowadzi do podstron. Wygląd Metro: znaczniki stanu m-tag (ok / due / bad / off). Office: konto @edu.feer.org.pl = Office aktywny.
 * Każdą operację potwierdza kod jednorazowy (SMS). Konto testowe ($virtual): dokumenty w symulacji (SYM, PDF do pobrania) i symulowana wpłata.
 * Numery ($ids): numer w panelu licencji (l + 8 cyfr, nadany raz, nim się logujesz) i nr beneficjenta (student_no z Ewidencji Uczestników).
 * Zmienne: $page, $ids, $virtual, $me, $paid, $software, $termOk, $office, $am5, $ops, $products, $prodMonthly, $feerUpn, $p24on, $login, $hasPassword, $minor, $contact, $eduAccount, $officeAcc, $flash, $err, $old, $org.
 */
$C = LicencjeKursantController::class;
$U = fn(string $k) => LicencjeKursantController::pageUrl($k);
$e = fn(string $p, string $k) => isset($err[$p][$k]) ? '<p id="e-' . h($p . $k) . '" class="m-err">' . h($err[$p][$k]) . '</p>' : '';
$a = fn(string $p, string $k) => isset($err[$p][$k]) ? ' aria-invalid="true" aria-describedby="e-' . h($p . $k) . '"' : '';
$today = date('Y-m-d');
// kafle i menu: krótki stan każdej licencji
$tiles = [];
foreach ($paid as $pk => $v) {
    $sub = $v['sub']; $due = 0.0; $n = 0;
    foreach ($v['charges'] as $c) if ($c['status'] === 'pending') { $n++; $due += (float)$c['amount_pln']; }
    $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
    $st = $sub && $sub['status'] === 'requested' ? ['wniosek złożony', 'due'] : (!$open ? ($v['eligible']['ok'] ? ['możesz złożyć wniosek', 'off'] : ['niedostępny', 'off'])
        : ($sub['status'] === 'suspended' ? ['wstrzymany', 'bad'] : ['włączony', 'ok']));
    $tiles[$pk] = [$v['P']['label'], $st, $n ? 'do zapłaty ' . ClaudeBilling::zl($due) : ($open ? 'opłacone' : ''), $n > 0];
}
$tiles['office'] = ['Office', $eduAccount ? ['aktywny', 'ok'] : ($office ? ['wniosek', 'due'] : ['brak', 'off']), $eduAccount ? (string)$eduAccount : '', false];
// Konto Office (inicjał.nazwisko@edu) — stan i przycisk „Załóż konto Office” (sprawdza tenant EDU; gdy konta nie ma, zakłada je; kod jednorazowy)
$officeBox = function (string $back) use ($officeAcc, $virtual) {
    $oa = $officeAcc ?: ['upn' => '', 'state' => '', 'note' => ''];
    echo '<div class="m-row" style="border-top:0;padding-top:0">';
    echo '<p class="tw-text-sm">Konto Office będzie miało login <span class="m-mono tw-font-semibold">' . h((string)$oa['upn'] ?: '(brak imienia lub nazwiska w kartotece)') . '</span>. Login dostaniesz e-mailem, hasło tymczasowe SMS-em.</p>';
    if (($oa['state'] ?? '') === 'error' && ($oa['note'] ?? '') !== '') echo '<p class="m-note">Ostatnia próba się nie udała. Spróbuj jeszcze raz albo napisz do biura.</p>';
    if ((string)$oa['upn'] !== '') {
        echo '<form method="post" action="' . h(route('lk.edu')) . '" class="tw-mt-2">' . Router::csrfField() . '<input type="hidden" name="back" value="' . h($back) . '">'
           . '<button class="m-btn" type="submit">Załóż konto Office</button></form>';
        echo '<p class="m-note tw-mt-1">' . ($virtual ? 'Konto testowe: tylko symulacja, bez Microsoft 365.' : 'Najpierw sprawdzimy, czy takie konto już masz. Jeśli tak, po prostu je połączymy.') . '</p>';
    }
    echo '</div>';
};
$tiles['programy'] = ['Programy i klucze', $software ? [count($software) . ' ' . (count($software) === 1 ? 'program' : 'programy'), 'ok'] : ['brak', 'off'], '', false];
$tiles['materialy'] = ['Materiały dydaktyczne', $am5 ? [count($am5) . ' ' . (count($am5) === 1 ? 'licencja' : 'licencje'), 'ok'] : ['brak', 'off'], '', false];
$tiles['sprawy'] = ['Moje sprawy', $ops ? [count($ops) . ' ' . (count($ops) === 1 ? 'sprawa' : 'sprawy'), 'due'] : ['brak', 'off'], '', false];
$tiles['produkty'] = ['Produkty i usługi', $products ? [count($products) . ' ' . (count($products) === 1 ? 'usługa' : 'usługi'), 'ok'] : ['brak', 'off'], $prodMonthly > 0 ? ClaudeBilling::zl($prodMonthly) . ' miesięcznie' : '', false];
$tiles['konto'] = ['Moje konto', $login ? ['aktywne', 'ok'] : ['bez numeru', 'off'], ($login ? 'numer ' . $login . ', ' : '') . 'hasło, kontakt', false];   // numer w opisie (znacznik zamienia litery na wielkie)
$nav = [[$U(''), 'Start', '', false, $page === 'start']];
foreach ($tiles as $k => [$lbl, $st, $sum, $dueFlag]) $nav[] = [$U($k), $lbl, $dueFlag ? $sum : '', $dueFlag, $page === $k];
$PAGE_TITLE = $page === 'start' ? 'Moje licencje' : ($tiles[$page][0] ?? 'Moje licencje');
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
$idsLine = '<p class="m-note tw-mb-4">Numer w panelu licencji: <b class="m-mono">' . h($ids['lic']) . '</b> · Nr beneficjenta: <b class="m-mono">' . h($ids['ben'] !== '' ? $ids['ben'] : 'nie nadano') . '</b>'
    . ' · Konto Office: ' . ($eduAccount ? '<b class="m-mono">' . h((string)$eduAccount) . '</b>' : '<a href="' . h(route('lk.office')) . '">brak, załóż</a>') . '</p>';   // Office na każdej podstronie (polecenie 2026-11-10)
?>
<?php if ($page === 'start'): ?>
<h1 class="m-title">Moje licencje</h1>
<p class="m-lead"><?= h($me['name']) ?>. Każdą zmianę potwierdzasz kodem SMS<?= $contact['phone'] !== '' ? ' wysłanym na numer ' . h(LicencjeKod::mask($contact['phone'])) : '' ?>.</p>
<?= $idsLine ?>
<?php if ($virtual): ?><div class="pk-alert pk-alert-info" role="note">Konto testowe. Dokumenty z Fakturowni są symulowane (numery SYM, PDF do pobrania), wpłatę można zasymulować, nic nie trafia do Fakturowni ani Helpdesku.</div><?php endif; ?>
<?php if (!$paid): ?><p class="m-explain">Nie masz subskrypcji. Licencję na Claude albo NordVPN przypisuje prowadzący.</p><?php endif; ?>
<ul class="m-tiles" role="list">
  <?php foreach ($tiles as $k => [$lbl, $st, $sum, $dueFlag]): ?>
  <li><a class="m-tilelink<?= $dueFlag ? ' due' : '' ?>" href="<?= h($U($k)) ?>">
    <span class="m-tilelink-title"><?= h($lbl) ?></span>
    <span class="m-tag <?= h($st[1]) ?>"><?= h($st[0]) ?></span>
    <?php if ($sum !== ''): ?><span class="m-tilelink-sum"><?= h($sum) ?></span><?php endif; ?>
  </a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php /* ── Subskrypcje: Claude, NordVPN ── */ ?>
<?php foreach ($paid as $pk => $v): if ($page !== $pk) continue; $P = $v['P']; $s = $v['settings']; $sub = $v['sub'];
  $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
  $requested = $sub && $sub['status'] === 'requested';   // wniosek z kreatora: proforma wystawiona, czeka na wpłatę i Helpdesk
  $buyer = array_merge($open || $requested ? array_intersect_key($sub, ClaudeBilling::BUYER_FIELDS) : ClaudeBilling::buyerDefaults((int)$me['cid']), array_intersect_key($old[$pk] ?? [], ClaudeBilling::BUYER_FIELDS));
  $state = $requested ? ['wniosek złożony', 'due'] : (!$sub || $sub['status'] === 'ended' ? ['możesz złożyć wniosek', 'off'] : ($sub['status'] === 'active' ? ['włączony', 'ok'] : ['wstrzymany: brak wpłaty', 'bad']));
  if (!$open && !$requested && !$v['eligible']['ok']) $state = ['niedostępny', 'off'];
  $priceTxt = $s['priced'] ? 'Cena: ' . ClaudeBilling::priceShort($s) . ' miesięcznie (kurs PKO BP)' . ($v['rate'] ? ', dziś razem ' . ClaudeBilling::zl((float)$v['rate']['amount_pln']) : '') . '.'
              . ($s['list_usd'] > $s['usd'] ? ' To cena dla organizacji non-profit, cena regularna to ' . number_format((float)$s['list_usd'], 2, ',', ' ') . ' USD.' : '') : '';
  $pr = $v['projection'] ?? ['now' => null, 'next' => null];
  $proj = function () use ($pr, $open, $s) { if ((!$pr['now'] && !$pr['next']) || !$s['priced'] || (!$open && !$s['enabled'])) return; ?>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3 tw-mb-4" role="group" aria-label="Najbliższe płatności">
      <?php foreach (['now' => $open ? 'Ta płatność' : 'Pierwsza płatność (po włączeniu)', 'next' => 'Następna płatność'] as $k2 => $lbl): if (!$pr[$k2]) continue; $x = $pr[$k2]; ?>
      <div class="m-box<?= $k2 === 'now' ? ' now' : '' ?>">
        <div class="m-kv"><dt><?= h($lbl) ?></dt></div>
        <div class="m-num"><?= $x['estimate'] ? '≈ ' : '' ?><?= h(ClaudeBilling::zl((float)$x['amount'])) ?></div>
        <div class="tw-text-sm tw-mt-1">dokument <?= h(ClaudeBilling::dayMonth($x['issue'])) ?>, zapłać do <?= h(ClaudeBilling::dayMonth($x['due'])) ?></div>
        <?php if ($x['estimate']): ?><div class="m-note tw-mt-1">Szacunek według dzisiejszego kursu. Kwota na dokumencie zależy od kursu z dnia wystawienia.</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php };
?>
  <h1 class="m-title"><?= h($P['label']) ?> <?= $tag($state[1], $state[0]) ?></h1>
  <p class="m-explain">Subskrypcja płatna co miesiąc. Dostajesz proformę, masz <?= ClaudeBilling::TERM_DAYS ?> dni na wpłatę, a po wpłacie dostajesz fakturę. Bez wpłaty dostęp zostaje wstrzymany.</p>
  <?= $idsLine ?>
  <section class="m-tile" aria-label="<?= h($P['label']) ?>">
    <p class="tw-text-sm tw-mb-3"><?= h(ucfirst($P['what'])) ?>.<?= $s['enabled'] || $open ? ' ' . h($priceTxt) : '' ?></p>

    <?php if ($open || $requested): ?>
      <?php if ($requested): $rq = $v['charges'][0] ?? null; $rqPaid = $rq && $rq['status'] === 'paid'; ?>
      <div class="pk-alert pk-alert-info" role="status">
        <p class="tw-font-semibold tw-mb-1">Wniosek złożony <?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?><?= $sub['helpdesk_number'] ? ' (zgłoszenie ' . h($sub['helpdesk_number']) . ')' : '' ?></p>
        <ol class="tw-list-decimal tw-pl-6 tw-text-sm tw-space-y-1">
          <li><?= $rqPaid ? 'Proforma zapłacona.' : 'Zapłać proformę (niżej) do ' . h(ClaudeBilling::dayMonth((string)($rq['due_on'] ?? ''))) . '.' ?></li>
          <li>Helpdesk wystawi fakturę VAT<?= $rq && (string)$rq['invoice_number'] !== '' ? ': gotowe' : '' ?>.</li>
          <li>Helpdesk włączy dostęp. Dostaniesz e-mail.</li>
        </ol>
      </div>
      <?php endif; ?>
      <?php if ($open): ?>
      <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6">
        <div><dt>Włączony od</dt><dd><?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?></dd></div>
        <div><dt>Opłacony do</dt><dd><?= $v['paidThrough'] ? h(ClaudeBilling::dayMonth($v['paidThrough'])) : 'jeszcze nic' ?></dd></div>
        <div><dt>Dokument co miesiąc</dt><dd><?= ClaudeBilling::dueDay($sub, $s) === 1 ? '1. dnia miesiąca' : 'ok. ' . ClaudeBilling::dueDay($sub, $s) . '. dnia' ?>, płatny w <?= ClaudeBilling::TERM_DAYS ?> dni</dd></div>
      </dl>
      <?php $proj(); ?>
      <?php endif; ?>
      <?php if (!empty($v['priceChange'])): $pc = $v['priceChange']; $chg = $pc['change']; $cmp = $v['priceCmp']; $fromD = ClaudeBilling::dayMonth((string)$chg['effective_from']); ?>
      <div class="pk-alert pk-alert-info" role="region" aria-label="Zmiana ceny <?= h($P['label']) ?>">
        <p class="tw-font-semibold tw-mb-1">Nowa cena od <?= h($fromD) ?></p>
        <p class="tw-text-sm tw-mb-1">Teraz: <?= h($cmp['old']) ?><?= $cmp['oldPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['oldPln'])) . ')' : '' ?>. Od <?= h($fromD) ?>: <b><?= h($cmp['new']) ?></b><?= $cmp['newPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['newPln'])) . ' miesięcznie)' : '' ?>.</p>
        <?php if ((string)$chg['reason'] !== ''): ?><p class="tw-text-sm tw-mb-1">Powód: <?= h((string)$chg['reason']) ?></p><?php endif; ?>
        <?php if ($pc['recipient'] === 'opiekun'): ?>
          <p class="tw-text-sm tw-mb-0">Decyduje opiekun. Wysłaliśmy mu e-mail z linkiem.<?= $pc['decision'] !== '' ? ' Jego decyzja: ' . ($pc['decision'] === 'accept' ? 'akceptacja.' : 'rezygnacja.') : '' ?></p>
        <?php else: ?>
          <?php if ($pc['decision'] !== ''): ?><p class="tw-text-sm tw-mb-2">Twoja decyzja: <b><?= $pc['decision'] === 'accept' ? 'akceptujesz nową cenę' : 'rezygnujesz, dostęp kończy się przed ' . h($fromD) ?></b>. Możesz ją zmienić do <?= h(ClaudeBilling::dayMonth(date('Y-m-d', strtotime($chg['effective_from'] . ' -1 day')))) ?>.</p>
          <?php else: ?><p class="tw-text-sm tw-mb-2">Wybierz przed <?= h($fromD) ?>. Jeśli nie wybierzesz, <?= $chg['no_answer'] === 'reject' ? 'dostęp skończy się przed ' . h($fromD) : 'zostaniesz przy nowej cenie (zrezygnować możesz w każdej chwili)' ?>.</p><?php endif; ?>
          <form method="post" action="<?= h(route('lk.price.decide', ['id' => (int)$pc['id']])) ?>" class="tw-flex tw-flex-wrap tw-gap-2"><?= Router::csrfField() ?>
            <?php if ($pc['decision'] !== 'accept'): ?><button class="m-btn" type="submit" name="decision" value="accept">Akceptuję nową cenę</button><?php endif; ?>
            <?php if ($pc['decision'] !== 'reject'): ?><button class="m-btn-ghost m-btn-danger" type="submit" name="decision" value="reject">Rezygnuję</button><?php endif; ?>
          </form>
          <p class="m-note tw-mt-2">Wymaga kodu SMS.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($sub['status'] === 'suspended'): ?><div class="pk-alert pk-alert-err" role="alert">Dostęp wstrzymany, bo nie ma wpłaty. Po wpłacie włączy się sam.</div><?php endif; ?>
      <?php if ($v['gaps']): ?><div class="pk-alert pk-alert-info" role="alert">Brak danych do faktury: <?= h(implode(', ', $v['gaps'])) ?>. Uzupełnij je niżej.</div><?php endif; ?>

      <?php foreach ($v['charges'] as $c): if ($c['status'] === 'cancelled') continue; $late = $c['status'] === 'pending' && $c['due_on'] < $today;
        $docUrl = $c['status'] === 'paid' ? (string)$c['invoice_view_url'] : ((string)$c['proforma_view_url'] ?: (string)$c['invoice_view_url']);
        $docNo  = $c['status'] === 'paid' ? (string)$c['invoice_number'] : ((string)$c['proforma_number'] ?: (string)$c['invoice_number']);
        $docLbl = $c['status'] === 'paid' || ($c['proforma_number'] === '' && $c['invoice_number'] !== '') ? 'faktura' : 'proforma';
        if (str_starts_with($docNo, 'SYM-')) { $docNo = ''; $docUrl = ''; }
        // dokumenty do pobrania: prawdziwe (podgląd w Fakturowni) albo — na koncie testowym — symulacje jako PDF
        $docs = [];
        foreach (['proforma' => 'Proforma', 'order' => 'Zamówienie', 'invoice' => 'Faktura'] as $dk => $dl) {
            $no = (string)$c[$dk . '_number'];
            if ($no === '') continue;
            $sym = str_starts_with($no, 'SYM-');
            if ($sym && !$virtual) continue;
            $docs[] = [$dl . ' ' . $no, route('lk.doc', ['id' => (int)$c['id'], 'doc' => $dk]), $sym, $sym ? '' : (string)($c[$dk . '_view_url'] ?? '')];
        } ?>
      <div class="m-row">
        <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
          <span class="tw-font-semibold"><?= h(ucfirst(ClaudeBilling::periodLabel((string)$c['period']))) ?></span>
          <span class="tw-text-lg tw-font-semibold" style="font-variant-numeric:tabular-nums"><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></span>
          <?php if ($c['status'] === 'paid'): ?><?= $tag('ok', 'zapłacone ' . ClaudeBilling::dayMonth(substr((string)$c['paid_at'], 0, 10))) ?>
          <?php else: ?><?= $tag($late ? 'bad' : 'due', $late ? 'po terminie (było do ' . ClaudeBilling::dayMonth($c['due_on']) . ')' : 'zapłać do ' . ClaudeBilling::dayMonth($c['due_on'])) ?><?php endif; ?>
          <?php if (!$docs): ?><span class="tw-text-sm m-muted"><?= $c['status'] === 'paid' ? 'faktura' : 'proforma' ?> w przygotowaniu</span><?php endif; ?>
        </div>
        <?php if ($docs): ?>
        <ul class="m-docs" aria-label="Dokumenty za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?>">
          <?php foreach ($docs as [$dlbl, $durl, $dsym, $dview]): ?>
          <li><span class="tw-mr-2"><?= h($dlbl) ?><?= $dsym ? ' <span class="m-note">(symulacja)</span>' : '' ?></span>
            <a class="m-btn-ghost" href="<?= h($durl) ?>" download>Pobierz PDF<span class="tw-sr-only"> — <?= h($dlbl) ?></span></a>
            <?php if ($dview !== ''): ?> <a class="tw-text-sm" href="<?= h($dview) ?>" target="_blank" rel="noopener">podgląd online<span class="tw-sr-only"> — <?= h($dlbl) ?> (nowa karta)</span></a><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <p class="m-note tw-mt-1"><?= h(ClaudeBilling::breakdown($c)) ?> (kurs PKO BP z <?= h(ClaudeBilling::dayMonth(substr((string)$c['rate_at'], 0, 10))) ?>)</p>
        <?php if ($c['status'] === 'pending'): ?>
        <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2">
          <?php if ($virtual): ?>
            <form method="post" action="<?= h(route('lk.sim.pay', ['id' => (int)$c['id']])) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Zapłać (symulacja)<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></button></form>
          <?php elseif ($s['pay_redirect'] === 'portal'): ?>
            <a class="m-btn" href="<?= h(route('lk.portal')) ?>">Zapłać w portalu płatności<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></a>
          <?php elseif ($s['pay_redirect'] === 'fakturownia' && $docUrl !== ''): ?>
            <a class="m-btn" href="<?= h($docUrl) ?>" target="_blank" rel="noopener">Zapłać (BLIK / szybki przelew)<span class="tw-sr-only"> — <?= $docLbl ?> w nowej karcie</span></a>
          <?php elseif (!empty($p24on)): ?>
            <form method="post" action="<?= h(route('lk.pay', ['id' => (int)$c['id']])) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Zapłać przez Przelewy24<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></button></form>
          <?php endif; ?>
          <?php if ($docUrl !== '' && $s['pay_redirect'] !== 'fakturownia'): ?><a class="m-btn-ghost" href="<?= h($docUrl) ?>" target="_blank" rel="noopener">BLIK / szybki przelew z <?= $docLbl === 'proforma' ? 'proformy' : 'faktury' ?><span class="tw-sr-only"> (nowa karta)</span></a><?php endif; ?>
        </div>
        <?php if ($s['pay_redirect'] !== 'portal'): ?><p class="tw-text-sm tw-mt-2"><a href="<?= h(route('lk.portal')) ?>">Wszystkie opłaty w portalu płatności</a> <span class="m-note">(zajęcia, licencje i inne w jednym miejscu)</span></p><?php endif; ?>
        <?php if ($v['account']): ?><p class="tw-text-sm tw-mt-2">Przelew: konto <span class="m-mono tw-font-semibold"><?= h($v['account']['nrb_fmt']) ?></span>, tytuł <span class="tw-font-semibold"><?= h(ClaudeBilling::transferTitle($c)) ?></span>, kwota <?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?>.</p><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <details class="m-row"<?= $v['gaps'] || !empty($err[$pk]) ? ' open' : '' ?>>
        <summary>Dane do faktury</summary>
        <form method="post" action="<?= h(route('lk.buyer', ['product' => $pk])) ?>" novalidate class="tw-mt-3"><?= Router::csrfField() ?>
          <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
            <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
            <div><label class="m-label" for="<?= h($pk . $k) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label><input class="m-input" id="<?= h($pk . $k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] ?? '')) ?>"<?= $a($pk, $k) ?>><?= $e($pk, $k) ?></div>
            <?php endforeach; ?>
          </div>
          <button class="m-btn-ghost tw-mt-3" type="submit">Zapisz dane do faktury</button>
        </form>
      </details>
      <details class="m-row">
        <summary><?= $requested ? 'Wycofaj wniosek' : 'Zrezygnuj z ' . h($P['label']) ?></summary>
        <form method="post" action="<?= h(route('lk.cancel', ['product' => $pk])) ?>" class="tw-mt-3"><?= Router::csrfField() ?>
          <?php if ($requested): ?>
          <p class="tw-text-sm tw-mb-2">Proforma przestanie być aktualna, nie płać jej. Wycofać można tylko przed wpłatą.</p>
          <label class="m-check"><input type="checkbox" name="confirm" value="1" required><span>Wycofuję wniosek o <?= h($P['label']) ?>.</span></label>
          <button class="m-btn-ghost m-btn-danger tw-mt-3" type="submit">Wycofaj wniosek</button>
          <?php else: ?>
          <p class="tw-text-sm tw-mb-2">Po rezygnacji nie będzie nowych opłat. Dostęp wyłączymy<?= $v['paidThrough'] ? ' po ' . h(ClaudeBilling::dayMonth($v['paidThrough'])) : ' od razu' ?>. Zaległe opłaty trzeba zapłacić.</p>
          <label class="m-check"><input type="checkbox" name="confirm" value="1" required><span>Rezygnuję z <?= h($P['label']) ?>.</span></label>
          <button class="m-btn-ghost m-btn-danger tw-mt-3" type="submit">Zrezygnuj</button>
          <?php endif; ?>
        </form>
      </details>

    <?php elseif (!$v['eligible']['ok']): ?>
      <p class="tw-text-sm"><?= h($v['eligible']['reason']) ?></p>
      <?php if (!empty($v['eligible']['needEdu'])) $officeBox('claude'); ?>
      <?php $proj(); ?>
    <?php else: ?>
      <?php $proj(); ?>
      <?php if (!empty($v['eligible']['needEdu'])): ?><p class="tw-text-sm tw-mb-2"><?= h($P['label']) ?> działa tylko z kontem Microsoft 365 @<?= h(ClaudeBilling::EDU_DOMAIN) ?>. Nie masz go jeszcze. Możesz je założyć teraz albo zostawić to nam: założymy je przy włączeniu dostępu.</p><?php $officeBox('claude'); ?><?php endif; ?>
      <a class="m-btn" href="<?= h(route('lk.wizard', ['product' => $pk])) ?>">Złóż wniosek o <?= h($P['label']) ?></a>
      <p class="m-note tw-mt-2">Trzy kroki: co dostajesz, dane do faktury, podsumowanie. Potem dostajesz proformę z Fakturowni, a po wpłacie Helpdesk wystawia fakturę VAT i włącza dostęp.</p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<?php if (isset(ClaudeBilling::PRODUCTS[$page]) && !isset($paid[$page])): ?>
<h1 class="m-title"><?= h(ClaudeBilling::P($page)['label']) ?></h1>
<p class="m-explain">Nie masz przypisanej licencji <?= h(ClaudeBilling::P($page)['label']) ?>. Przypisuje ją prowadzący albo biuro.</p>
<?php endif; ?>

<?php /* ── Licencje na Office (AM7) ── */ ?>
<?php if ($page === 'office'): ?>
<h1 class="m-title">Licencje na Office</h1>
<p class="m-explain">Word, Excel, PowerPoint, Outlook i Teams. Office działa z kontem @<?= h(ClaudeBilling::EDU_DOMAIN) ?>.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Licencje na Office">
  <?php if ($eduAccount): $hintE = rawurlencode($eduAccount); ?>
  <div class="m-row" style="border-top:0;padding-top:0">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3"><span class="tw-font-semibold">Office (Microsoft 365 dla edukacji)</span> <?= $tag('ok', 'aktywny') ?></div>
    <p class="tw-text-sm tw-mt-1">Twoje konto: <span class="m-mono tw-font-semibold"><?= h($eduAccount) ?></span>
      <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($eduAccount) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
    <p class="tw-text-sm tw-mt-1"><a href="https://www.office.com/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a> · <a href="https://outlook.office.com/mail/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Poczta<span class="tw-sr-only"> (nowa karta)</span></a> · <a href="https://teams.microsoft.com/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Teams<span class="tw-sr-only"> (nowa karta)</span></a></p>
  </div>
  <?php endif; ?>
  <?php if (!$eduAccount): ?><?php $officeBox('office'); ?><?php endif; ?>
  <?php foreach ($office as $o): $g = $o['request_status'] === 'granted'; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($o['title'] ?: 'Microsoft 365 / Office') ?></span>
      <?= $tag($g ? 'ok' : ($o['request_status'] === 'refused' ? 'bad' : 'due'), $C::AM7_STATUS[$o['request_status']] ?? $o['request_status']) ?>
    </div>
    <?php if ($g && $o['tenant_upn']): ?>
      <p class="tw-text-sm tw-mt-1">Login do Office: <span class="m-mono tw-font-semibold"><?= h($o['tenant_upn']) ?></span> · <a href="https://www.office.com/?login_hint=<?= h(rawurlencode((string)$o['tenant_upn'])) ?>" target="_blank" rel="noopener">otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a></p>
      <p class="m-note">Hasło startowe przyszło SMS-em. Przy pierwszym logowaniu Microsoft każe je zmienić.</p>
    <?php elseif ($g): ?><p class="tw-text-sm tw-mt-1">Licencja przyznana. Dział IT zakłada konto<?= $login ? ' (login ' . h($login) . ')' : '' ?>. Dane logowania przyjdą e-mailem i SMS-em.</p><?php endif; ?>
    <?php if ($o['period_to']): ?><p class="m-note">Ważna<?= $o['period_from'] ? ' od ' . h(ClaudeBilling::dayMonth($o['period_from'])) : '' ?> do <?= h(ClaudeBilling::dayMonth($o['period_to'])) ?> · sprawa <?= h($o['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Programy i klucze (moduł Licencje) ── */ ?>
<?php if ($page === 'programy'): ?>
<h1 class="m-title">Programy i klucze</h1>
<p class="m-explain">Programy, które przypisała Ci Fundacja, z loginami i kluczami. Nie przekazuj ich nikomu.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Programy i klucze">
  <?php if (!$software): ?><p class="tw-text-sm m-muted">Nie masz przypisanych programów.</p><?php endif; ?>
  <?php if ($software && !$termOk): ?><div class="pk-alert pk-alert-info">Loginy i klucze są ukryte, dopóki nie zaakceptujesz oświadczenia o poufności (panel uczestnika, zakładka Regulaminy).</div><?php endif; ?>
  <?php foreach ($software as $l): $exp = !empty($l['expires_at']) && $l['expires_at'] < $today; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($l['license_name']) ?></span><span class="tw-text-sm m-muted"><?= h($l['vendor']) ?></span>
      <?= $tag($exp ? 'bad' : 'ok', $exp ? 'wygasła' : (!empty($l['expires_at']) ? 'ważna do ' . ClaudeBilling::dayMonth($l['expires_at']) : 'bezterminowo')) ?>
    </div>
    <?php if ($termOk && ($l['login'] !== '' || $l['access_key'] !== '')): ?>
    <dl class="tw-text-sm tw-mt-2 tw-grid tw-grid-cols-[8rem_1fr] tw-gap-y-1">
      <?php if ($l['login'] !== ''): ?><dt class="m-muted">Login</dt><dd><span class="m-mono"><?= h($l['login']) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($l['login']) ?>">Kopiuj<span class="tw-sr-only"> login do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
      <?php if ($l['access_key'] !== ''): ?><dt class="m-muted">Klucz / hasło</dt><dd><span class="m-mono lk-secret" data-secret="<?= h($l['access_key']) ?>">••••••••</span> <button type="button" class="m-btn-ghost lk-reveal" aria-pressed="false">Pokaż<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($l['access_key']) ?>">Kopiuj<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
    </dl>
    <?php endif; ?>
    <?php if (!empty($l['notes'])): ?><p class="tw-text-sm m-muted tw-mt-1"><?= h($l['notes']) ?></p><?php endif; ?>
    <?php if (!empty($l['vendor_url'])): ?><p class="tw-mt-1"><a href="<?= h($l['vendor_url']) ?>" target="_blank" rel="noopener">Otwórz / zaloguj się<span class="tw-sr-only"> do <?= h($l['license_name']) ?> (nowa karta)</span></a></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Materiały dydaktyczne (AM5) ── */ ?>
<?php if ($page === 'materialy'): ?>
<h1 class="m-title">Materiały dydaktyczne</h1>
<p class="m-explain">Licencje na materiały do zajęć. Dostęp dostajesz po odesłaniu oświadczenia, link przychodzi e-mailem.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Materiały dydaktyczne">
  <?php if (!$am5): ?><p class="tw-text-sm m-muted">Brak licencji na materiały do zajęć.</p><?php endif; ?>
  <?php foreach ($am5 as $x): ?>
  <div class="m-row">
    <span class="tw-font-semibold"><?= h($x['title'] ?: 'Licencja dydaktyczna') ?></span>
    <?= $tag($x['status'] === 'accepted' ? 'ok' : (in_array($x['status'], ['rejected', 'revoked'], true) ? 'bad' : 'due'), $C::AM5_STATUS[$x['status']] ?? $x['status']) ?>
    <?php if ($x['status'] === 'invited' && $x['due_on']): ?><p class="tw-text-sm tw-mt-1">Odeślij oświadczenie do <?= h(ClaudeBilling::dayMonth($x['due_on'])) ?>. Link jest w e-mailu. Jeśli go nie masz, napisz do prowadzącego.</p><?php endif; ?>
    <?php if ($x['period_to']): ?><p class="m-note">Do <?= h(ClaudeBilling::dayMonth($x['period_to'])) ?> · sprawa <?= h($x['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Moje sprawy (AM11–24, ANT1) ── */ ?>
<?php if ($page === 'sprawy'): ?>
<h1 class="m-title">Moje sprawy</h1>
<p class="m-explain">Twoje wnioski licencyjne: przyznanie, zwrot kosztów, zmiana płatnika lub planu, zawieszenie, przedłużenie. Tu widać, na jakim są etapie.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Moje sprawy">
  <?php if (!$ops): ?><p class="tw-text-sm m-muted">Brak spraw licencyjnych.</p><?php endif; ?>
  <?php foreach ($ops as $o): ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
    <span class="tw-font-semibold"><?= h($C::OP_NAMES[$o['form']] ?? 'Sprawa licencyjna') ?></span>
    <?= $tag($o['status'] === 'executed' ? 'ok' : (in_array($o['status'], ['rejected', 'cancelled'], true) ? 'off' : 'due'), $C::OP_STATUS[$o['status']] ?? $o['status']) ?>
    <span class="m-note">złożona <?= h(ClaudeBilling::dayMonth(substr((string)$o['created_at'], 0, 10))) ?> · nr <?= h($o['number']) ?></span>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Produkty i usługi (refakturowane przez FEER) — liczone osobno ── */
$pchip = ['active' => ['działa', 'ok'], 'expiring' => ['kończy się wkrótce', 'due'], 'suspended' => ['zawieszona', 'bad']]; ?>
<?php if ($page === 'produkty'): ?>
<h1 class="m-title">Produkty i usługi</h1>
<p class="m-explain">Domeny, hosting i inne usługi, które Fundacja kupuje dla Ciebie i refakturuje. Płacisz za nie osobno, poza subskrypcjami.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Produkty i usługi">
  <?php if ($products): ?><div class="m-box tw-mb-3" style="max-width:320px"><div class="m-kv"><dt>Razem miesięcznie za usługi</dt></div><div class="m-num"><?= h(ClaudeBilling::zl($prodMonthly)) ?></div></div><?php endif; ?>
  <?php if (!$products && $feerUpn === ''): ?><p class="tw-text-sm m-muted">Nie masz usług. Domenę albo hosting zamawia prowadzący.</p><?php endif; ?>
  <?php foreach ($products as $p): $t = TiProducts::TYPES[$p['product_type']] ?? TiProducts::TYPES['other']; $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p); $amt = (float)($p['monthly_amount'] ?? 0); ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold tw-break-all<?= $p['product_type'] === 'domain' ? ' m-mono' : '' ?>"><?= h($p['name']) ?></span>
      <span class="tw-text-sm m-muted"><?= h($t['label']) ?> · <?= h(TiProducts::providerName($p)) ?></span>
      <?= $tag($pchip[$p['status']][1] ?? 'off', $pchip[$p['status']][0] ?? $p['status']) ?>
    </div>
    <p class="tw-text-sm tw-mt-1">
      <?php if ($p['expires_at']): ?>Opłacona do <b><?= h(ClaudeBilling::dayMonth((string)$p['expires_at'])) ?></b><?= $hint === 'overdue' ? '. Termin minął; żeby przedłużyć, napisz do biura' : ($hint === 'soon' ? ', zostało ' . (int)$dl . ($dl === 1 ? ' dzień' : ' dni') : '') ?>.<?php else: ?>Bez daty końca.<?php endif; ?>
      <?php if ($amt > 0): ?> Płacisz co miesiąc <b><?= h(ClaudeBilling::zl((float)$p['_after'])) ?></b><?= $p['_after'] < $amt ? ' (zamiast ' . h(ClaudeBilling::zl($amt)) . ($p['discount_note'] !== '' ? ': ' . h($p['discount_note']) : '') . ')' : '' ?><?= !empty($p['billing_day']) ? ', faktura ok. ' . (int)$p['billing_day'] . '. dnia miesiąca' : '' ?>.<?php endif; ?>
    </p>
    <?php if ($p['product_type'] !== 'domain' && trim((string)$p['server_details']) !== ''): ?><p class="m-note">Pakiet / serwer: <?= h($p['server_details']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($feerUpn !== ''): $hintU = rawurlencode($feerUpn); ?>
  <div class="m-row">
    <span class="tw-font-semibold">Konto Microsoft 365 w Fundacji</span>
    <p class="tw-text-sm tw-mt-1">Login: <span class="m-mono tw-font-semibold"><?= h($feerUpn) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($feerUpn) ?>">Kopiuj<span class="tw-sr-only"> login</span></button>
      · <a href="https://www.office.com/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Office</a> · <a href="https://outlook.office.com/mail/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Poczta</a> · <a href="https://teams.microsoft.com/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Teams</a></p>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ── Moje konto ── */ ?>
<?php if ($page === 'konto'): ?>
<h1 class="m-title">Moje konto w panelu licencji</h1>
<p class="m-explain">Login, hasło i dane kontaktowe. Na ten telefon wysyłamy kody, na e-mail proformy i faktury.</p>
<?= $idsLine ?>
<section class="m-tile" aria-label="Moje konto w panelu licencji">
  <?php if ($login): ?>
    <details<?= !$hasPassword ? ' open' : '' ?>><summary><?= $hasPassword ? 'Zmień hasło' : 'Ustaw hasło do panelu licencji' ?></summary>
      <form method="post" action="<?= h(route('lk.password')) ?>" class="tw-mt-3 tw-grid sm:tw-grid-cols-2 tw-gap-3" style="max-width:640px"><?= Router::csrfField() ?>
        <div><label class="m-label" for="pw1">Nowe hasło (min. 10 znaków)</label><input class="m-input" type="password" id="pw1" name="new" autocomplete="new-password" minlength="10"></div>
        <div><label class="m-label" for="pw2">Powtórz hasło</label><input class="m-input" type="password" id="pw2" name="new2" autocomplete="new-password" minlength="10"></div>
        <div><button class="m-btn" type="submit">Zapisz hasło</button> <span class="m-note">wymaga kodu SMS</span></div>
      </form>
    </details>
  <?php else: ?>
    <p class="tw-text-sm">Nie masz loginu do panelu licencji. Zakłada go biuro. Do tego czasu wchodzisz tu z panelu uczestnika TI.</p>
  <?php endif; ?>
  <div class="m-row tw-mt-4">
    <h2 class="m-sect">Twoje numery</h2>
    <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
      <div><dt>Numer w panelu licencji</dt><dd><span class="m-mono tw-text-lg"><?= h($ids['lic']) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($ids['lic']) ?>">Kopiuj<span class="tw-sr-only"> numer w panelu licencji</span></button></dd></div>
      <div><dt>Nr beneficjenta</dt><dd class="m-mono"><?= h($ids['ben'] !== '' ? $ids['ben'] : 'nie nadano') ?></dd></div>
    </dl>
    <p class="m-note">Numer w panelu licencji jest nadany raz i się nie zmienia. Nim i hasłem logujesz się do panelu licencji. Nr beneficjenta pochodzi z Ewidencji Uczestników. Oba podawaj w kontakcie z biurem.</p>
  </div>
  <div class="m-row">
    <h2 class="m-sect">Dane kontaktowe</h2>
    <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
      <div><dt>E-mail</dt><dd><?= $contact['email'] !== '' ? h($contact['email']) : '—' ?></dd></div>
      <div><dt>Telefon (kody SMS)</dt><dd><?= $contact['phone'] !== '' ? h($contact['phone']) : '—' ?></dd></div>
    </dl>
    <a class="m-btn-ghost" href="<?= h(route('lk.contact')) ?>">Zmień e-mail lub telefon</a>
  </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
