<?php
if (!isset($me, $paid)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/index.php — panel licencji: strona startowa z kaflami i osobne podstrony (polecenie 2026-11-09).
 * $page: 'start' (kafle) · 'claude' / 'nordvpn' (subskrypcja, /licencja/{produkt}) · 'office' · 'programy' · 'materialy' · 'sprawy' · 'produkty' · 'konto'.
 * Menu po lewej prowadzi do podstron. Wygląd Metro: znaczniki stanu m-tag (ok / due / bad / off). Office: konto @edu.feer.org.pl = Office aktywny.
 * Każdą operację potwierdza kod jednorazowy (SMS). Konto testowe ($virtual): dokumenty w symulacji (SYM, PDF do pobrania) i symulowana wpłata.
 * Numery ($ids): numer w panelu licencji (l + 8 cyfr, nadany raz, nim się logujesz) i nr beneficjenta (student_no z Ewidencji Uczestników).
 * Zmienne: $page, $ids, $virtual, $me, $paid, $software, $termOk, $office, $am5, $ops, $products, $prodMonthly, $feerUpn, $p24on, $login, $hasPassword, $minor, $contact, $eduAccount, $officeAcc, $card, $consents, $sprawy, $tickets, ($page = sprawa: $sprawa, $ticket, $msgs, $caseKind, $caseId), $flash, $err, $old, $org.
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
    if ((string)$oa['upn'] === '') {   // login z imienia i nazwiska — bez nich nie da się go ustalić
        echo '<p class="tw-text-sm">Nie możemy ustalić loginu do Office, bo w Twoich danych brakuje imienia albo nazwiska. Napisz do biura, poprawimy to.</p></div>';
        return;
    }
    echo '<p class="tw-text-sm">Twój login do Office będzie taki: <span class="m-mono tw-font-semibold">' . h((string)$oa['upn']) . '</span>. Login dostaniesz e-mailem, hasło tymczasowe SMS-em.</p>';
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
$_nSpr = count($sprawy ?? []) + count($tickets ?? []);
$tiles['sprawy'] = ['Moje sprawy', $_nSpr ? [count($sprawy ?? []) . ' ' . (count($sprawy ?? []) === 1 ? 'sprawa' : 'spraw') . (($tickets ?? []) ? ', ' . count($tickets) . ' zgł.' : ''), 'due'] : ['brak', 'off'], '', false];
$tiles['produkty'] = ['Produkty i usługi', $products ? [count($products) . ' ' . (count($products) === 1 ? 'usługa' : 'usługi'), 'ok'] : ['brak', 'off'], $prodMonthly > 0 ? ClaudeBilling::zl($prodMonthly) . ' miesięcznie' : '', false];
$drive = array_values(array_filter($products, fn($x) => ($x['provider'] ?? '') === 'sdata' || stripos((string)$x['name'], 'dysk online') !== false))[0] ?? null;   // Dysk Online (pilotaż, sdata.net.pl)
$tiles['dysk'] = ['Dysk Online', $drive ? ($drive['status'] === 'suspended' ? ['zawieszony', 'bad'] : ['aktywny', 'ok']) : ['pilotaż', 'due'], $drive ? (string)$drive['name'] : 'dysk w chmurze', false];
$cardModes = array_filter($paid, fn($v) => ($v['payMode'] ?? '') === 'card' && $v['sub'] && in_array($v['sub']['status'], ['requested', 'active', 'suspended'], true));
$tiles['rozliczenia'] = ['Rozliczenia', $card ? ['karta podpięta', 'ok'] : ($cardModes ? ['podepnij kartę', 'due'] : ['przelew', 'off']), $card ? LicenceCard::label($card) : 'karta i dane do faktur', !$card && $cardModes];
$tiles['konto'] = ['Moje konto', $login ? ['aktywne', 'ok'] : ['bez numeru', 'off'], ($login ? 'numer ' . $login . ', ' : '') . 'hasło, kontakt', false];   // numer w opisie (znacznik zamienia litery na wielkie)
$nav = [[$U(''), 'Start', '', false, $page === 'start']];
foreach ($tiles as $k => [$lbl, $st, $sum, $dueFlag]) $nav[] = [$U($k), $lbl, $dueFlag ? $sum : '', $dueFlag, $page === $k || ($page === 'sprawa' && $k === 'sprawy')];
$PAGE_TITLE = $page === 'start' ? 'Moje licencje' : ($page === 'sprawa' ? 'Sprawa' : ($tiles[$page][0] ?? 'Moje licencje'));
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
$idsLine = '<div class="m-box tw-mb-4" role="group" aria-label="Twoje numery" style="border-left:4px solid var(--mt-ink)"><dl class="tw-grid sm:tw-grid-cols-3 tw-gap-x-6 tw-gap-y-2" style="margin:0">'
    . '<div><dt class="m-note">Numer w Panelu Licencji</dt><dd class="m-mono tw-font-semibold" style="margin:0;font-size:1.15rem">' . h($ids['lic']) . '</dd></div>'
    . '<div><dt class="m-note">Numer beneficjenta</dt><dd class="m-mono tw-font-semibold" style="margin:0;font-size:1.15rem">' . h($ids['ben'] !== '' ? $ids['ben'] : 'nie nadano') . '</dd></div>'
    . '<div><dt class="m-note">Konto Office</dt><dd style="margin:0;font-size:1.05rem">' . ($eduAccount ? '<span class="m-mono tw-font-semibold">' . h((string)$eduAccount) . '</span>' : '<a href="' . h(route('lk.office')) . '">nie masz, załóż</a>') . '</dd></div>'
    . '</dl></div>';   // numery w ramce na każdej podstronie (polecenie 2026-11-10)
?>
<?php if ($page === 'start'): ?>
<h1 class="m-title">Moje licencje</h1>
<p class="m-lead"><?= h($me['name']) ?></p>
<?= $idsLine ?>
<?php if ($virtual): ?><div class="pk-alert pk-alert-info" role="note">Konto testowe. Dokumenty i wpłaty są tylko symulacją, nic nie trafia do księgowości.</div><?php endif; ?>
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
  <p class="m-explain"><?= !empty($P['goodstack']) && ($s['goodstack_url'] ?? '') !== '' && !$open && !$requested ? 'Masz dwa sposoby na ' . h($P['label']) . '. Polecamy pierwszy: weryfikujesz się jako osoba z Fundacji i płacisz sam(a) w Anthropic.' : (($v['payMode'] ?? '') === 'card' ? 'Płacisz co miesiąc kartą. Opłatę pobieramy sami w dniu terminu, a po wpłacie przychodzi faktura.' : 'Płacisz co miesiąc. Dostajesz proformę i masz ' . ClaudeBilling::TERM_DAYS . ' dni na wpłatę. Po wpłacie przychodzi faktura. Bez wpłaty wstrzymujemy dostęp.') ?></p>
  <?= $idsLine ?>
  <?php if (!empty($P['goodstack']) && ($s['goodstack_url'] ?? '') !== ''): $gsOpen = $open || $requested; ?>
  <?php /* Sposób polecany (polecenie 2026-11-10): Claude for Nonprofit przez weryfikację Goodstack, płatność własną kartą w Anthropic */ ?>
  <section class="m-tile tw-mb-4" aria-labelledby="gs-h" style="border-top:4px solid var(--mt-ok)">
    <h2 class="m-sect" id="gs-h">Sposób 1 <?= $tag('ok', 'polecamy') ?> Claude for Nonprofit, płacisz sam(a) kartą</h2>
    <p class="tw-text-sm tw-mb-2">Weryfikujesz się jako osoba z Fundacji, a potem kupujesz Claude bezpośrednio w Anthropic, w cenie dla organizacji non-profit. Płacisz własną kartą, Fundacja nie wystawia faktur.</p>
    <?php if (!$eduAccount): ?>
      <p class="tw-text-sm tw-mb-2"><b>Warunek:</b> konto Office @<?= h(ClaudeBilling::EDU_DOMAIN) ?>. Nie masz go jeszcze.</p>
      <?php $officeBox('claude'); ?>
    <?php else: ?>
      <details<?= $gsOpen ? '' : ' open' ?>><summary class="tw-text-sm">Jak to zrobić (5 kroków)</summary>
        <ol class="tw-list-decimal tw-pl-6 tw-text-sm tw-space-y-1 tw-mt-2">
          <li>Kliknij „Przejdź do weryfikacji”. Otworzy się strona Goodstack.</li>
          <li>W polu z nazwą organizacji wpisz numer KRS <b class="m-mono"><?= h($s['goodstack_krs'] !== '' ? $s['goodstack_krs'] : '0000779281') ?></b> i wybierz <b>Fundacja Edukacji Empatii Rozwoju "FEER"</b>.</li>
          <li>Podaj e-mail z konta Office: <b class="m-mono"><?= h((string)$eduAccount) ?></b>. Potwierdź go linkiem, który przyjdzie na tę skrzynkę.</li>
          <li>Fundacja potwierdzi, że należysz do organizacji. Zwykle w ciągu 1–2 dni roboczych.</li>
          <li>Po potwierdzeniu kupisz Claude w Anthropic i zapłacisz własną kartą.</li>
        </ol>
      </details>
      <a class="m-btn tw-mt-3" href="<?= h(route('lk.goodstack')) ?>" target="_blank" rel="noopener">Przejdź do weryfikacji ↗<span class="tw-sr-only"> (strona Goodstack, nowa karta)</span></a>
      <p class="m-note tw-mt-2">Pocztę z konta Office odbierzesz na <a href="https://outlook.office.com/mail/?login_hint=<?= h(rawurlencode((string)$eduAccount)) ?>" target="_blank" rel="noopener">outlook.office.com<span class="tw-sr-only"> (nowa karta)</span></a>.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="m-tile" aria-label="<?= h($P['label']) ?>">
    <?php if (!empty($P['goodstack']) && ($s['goodstack_url'] ?? '') !== ''): ?><h2 class="m-sect">Sposób 2: przez Fundację (płatna licencja)</h2><?php endif; ?>
    <p class="tw-text-sm tw-mb-3"><?= h(ucfirst($P['what'])) ?>.</p>
    <?php if ($s['priced'] && ($s['enabled'] || $open || $requested)): $rt = $v['rate'] ?? null; ?>
    <div class="m-box tw-mb-4" role="group" aria-label="Cena <?= h($P['label']) ?>">
      <dl class="tw-grid sm:tw-grid-cols-4 tw-gap-x-6 tw-gap-y-2 tw-text-sm" style="margin:0">
        <div><dt class="m-note">Cena licencji</dt><dd class="tw-font-semibold" style="margin:0"><?= h(ClaudeBilling::usd((float)$s['usd'])) ?> / miesiąc<?php if ($s['list_usd'] > $s['usd']): ?><span class="m-note tw-block">zwykle <s><?= h(ClaudeBilling::usd((float)$s['list_usd'])) ?></s>, taniej dla organizacji non-profit</span><?php endif; ?></dd></div>
        <?php if ((float)$s['pct'] > 0): ?><div><dt class="m-note"><?= h($s['pct_label'] !== '' ? $s['pct_label'] : 'Narzut') ?></dt><dd class="tw-font-semibold" style="margin:0">+ <?= h(ClaudeBilling::pctFmt((float)$s['pct'])) ?></dd></div><?php endif; ?>
        <?php if ((float)$s['fee_pln'] > 0): ?><div><dt class="m-note">Prowizja za płatność</dt><dd class="tw-font-semibold" style="margin:0">+ <?= h(ClaudeBilling::zl((float)$s['fee_pln'])) ?></dd></div><?php endif; ?>
        <div><dt class="m-note">Razem dziś</dt><dd style="margin:0"><span class="m-num" style="font-size:1.4rem"><?= $rt ? '≈ ' . h(ClaudeBilling::zl((float)$rt['amount_pln'])) : '—' ?></span><span class="m-note tw-block">miesięcznie</span></dd></div>
      </dl>
      <p class="m-note tw-mt-2" style="margin-bottom:0">Dolary przeliczamy po kursie PKO BP z dnia wystawienia proformy, dlatego kwota może się co miesiąc trochę różnić.</p>
    </div>
    <?php endif; ?>

    <?php if ($open || $requested): ?>
      <?php if ($requested): $rq = array_values(array_filter($v['charges'], fn($x) => ($x['kind'] ?? 'month') === 'month' && $x['status'] !== 'cancelled'))[0] ?? null; $rqPaid = $rq && $rq['status'] === 'paid'; ?>
      <div class="pk-alert pk-alert-info" role="status">
        <p class="tw-font-semibold tw-mb-1">Wniosek złożony <?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?><?= $sub['helpdesk_number'] ? ' <span class="m-note">(nr ' . h($sub['helpdesk_number']) . ')</span>' : '' ?>. Co dalej:</p>
        <ol class="tw-list-decimal tw-pl-6 tw-text-sm tw-space-y-1">
          <?php if (($v['payMode'] ?? '') === 'card'): ?>
          <li><?= $card ? 'Karta podpięta.' : '<a href="' . h(route('lk.card', ['product' => $pk])) . '">Podepnij kartę</a> (opłata weryfikacyjna ' . h(ClaudeBilling::zl((float)$s['card_fee_pln'])) . ').' ?></li>
          <li><?= $rqPaid ? 'Pierwsza opłata pobrana z karty. Faktura jest niżej.' : 'Pobierzemy pierwszą opłatę z karty. Faktura przyjdzie od razu.' ?></li>
          <?php else: ?>
          <li><?= $rqPaid ? 'Opłata zapłacona, dziękujemy.' : 'Zapłać do ' . h(ClaudeBilling::dayMonth((string)($rq['due_on'] ?? ''))) . ' (niżej masz dwa sposoby).' ?></li>
          <li><?= $rq && (string)$rq['invoice_number'] !== '' && !str_starts_with((string)$rq['invoice_number'], 'SYM-') ? 'Faktura gotowa, jest niżej.' : 'Po wpłacie wystawimy fakturę.' ?></li>
          <?php endif; ?>
          <li>Włączymy dostęp i napiszemy do Ciebie e-mail.</li>
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
      <?php if (($v['payMode'] ?? '') === 'card'): ?>
        <p class="tw-text-sm tw-mb-3">Płatność: <?= $card ? 'karta ' . h(LicenceCard::label($card)) . ', pobieramy automatycznie, faktura VAT po każdej wpłacie.' : '<b>karta</b>. Nie masz jeszcze podpiętej karty.' ?>
          <a href="<?= h($card ? route('lk.billing') : route('lk.card', ['product' => $pk])) ?>"><?= $card ? 'Zarządzaj kartą' : 'Podepnij kartę' ?></a></p>
      <?php endif; ?>

      <?php foreach ($v['charges'] as $c): if ($c['status'] === 'cancelled') continue; $late = $c['status'] === 'pending' && $c['due_on'] < $today;
        $docUrl = $c['status'] === 'paid' ? (string)$c['invoice_view_url'] : ((string)$c['proforma_view_url'] ?: (string)$c['invoice_view_url']);
        $docNo  = $c['status'] === 'paid' ? (string)$c['invoice_number'] : ((string)$c['proforma_number'] ?: (string)$c['invoice_number']);
        $docLbl = $c['status'] === 'paid' || ($c['proforma_number'] === '' && $c['invoice_number'] !== '') ? 'faktura' : 'proforma';
        if (str_starts_with($docNo, 'SYM-')) { $docNo = ''; $docUrl = ''; }
        // dokumenty do pobrania: prawdziwe (podgląd w Fakturowni) albo — na koncie testowym — symulacje jako PDF
        $docs = []; $symPreview = false;
        foreach (['proforma' => 'Proforma', 'order' => 'Zamówienie', 'invoice' => 'Faktura'] as $dk => $dl) {
            $no = (string)$c[$dk . '_number'];
            if ($no === '') continue;
            $sym = str_starts_with($no, 'SYM-');
            if ($sym && !$virtual && $dk !== 'proforma') continue;   // faktura / zamówienie w symulacji to nie dokument — uczestnik ich nie widzi
            if ($sym && !$virtual) $symPreview = true;               // proforma w symulacji: podgląd PDF (Fakturownia jeszcze nie wystawiła dokumentu)
            $docs[] = [$sym && !$virtual ? $dl . ' (podgląd)' : $dl . ' ' . $no, route('lk.doc', ['id' => (int)$c['id'], 'doc' => $dk]), $sym && $virtual, $sym ? '' : (string)($c[$dk . '_view_url'] ?? '')];
        }
        // dlaczego nie ma dokumentu (zamiast samego „w przygotowaniu”)
        $docWhy = '';
        if (!$docs && $c['status'] === 'pending' && ($c['pay_mode'] ?? '') !== 'card' && ($c['kind'] ?? 'month') === 'month') {
            $ie = (string)($c['invoice_error'] ?? '');
            $docWhy = str_contains($ie, 'Brak danych do faktury') ? 'Proforma czeka na dane do faktury. Uzupełnij je niżej, wystawi się sama.'
                    : ($s['invoice'] !== 'auto' ? 'Proformę wystawi biuro. Możesz już zapłacić przelewem według danych niżej.'
                    : 'Proforma jest w przygotowaniu, zwykle kilka minut. Możesz już zapłacić przelewem według danych niżej.');
        } ?>
      <div class="m-row">
        <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
          <span class="tw-font-semibold"><?= ($c['kind'] ?? '') === 'card' ? 'Opłata weryfikacyjna karty' : h(ucfirst(ClaudeBilling::periodLabel((string)$c['period']))) ?></span>
          <span class="tw-text-lg tw-font-semibold" style="font-variant-numeric:tabular-nums"><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></span>
          <?php if ($c['status'] === 'paid'): ?><?= $tag('ok', 'zapłacone ' . ClaudeBilling::dayMonth(substr((string)$c['paid_at'], 0, 10))) ?>
          <?php elseif (($c['pay_mode'] ?? '') === 'card'): ?><?= $tag('due', 'z karty ' . ClaudeBilling::dayMonth($c['due_on'])) ?>
          <?php else: ?><?= $tag($late ? 'bad' : 'due', $late ? 'po terminie (było do ' . ClaudeBilling::dayMonth($c['due_on']) . ')' : 'zapłać do ' . ClaudeBilling::dayMonth($c['due_on'])) ?><?php endif; ?>
          <?php if (!$docs && $docWhy === ''): ?><span class="tw-text-sm m-muted"><?= $c['status'] === 'paid' ? 'faktura w przygotowaniu' : (($c['pay_mode'] ?? '') === 'card' ? 'faktura VAT po wpłacie' : 'proforma w przygotowaniu') ?></span><?php endif; ?>
        </div>
        <?php if ($docWhy !== ''): ?><p class="tw-text-sm tw-mt-1"><?= h($docWhy) ?></p><?php endif; ?>
        <?php if ($symPreview): ?><p class="m-note tw-mt-1">To podgląd proformy. Ostateczną dostaniesz e-mailem. Zapłacić możesz już teraz.</p><?php endif; ?>
        <?php if ($docs): ?>
        <ul class="m-docs" aria-label="Dokumenty za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?>">
          <?php foreach ($docs as [$dlbl, $durl, $dsym, $dview]): ?>
          <li><span class="tw-mr-2"><?= h($dlbl) ?><?= $dsym ? ' <span class="m-note">(symulacja)</span>' : '' ?></span>
            <a class="m-btn-ghost" href="<?= h($durl) ?>" download>Pobierz PDF<span class="tw-sr-only"> — <?= h($dlbl) ?></span></a>
            <?php if ($dview !== ''): ?> <a class="tw-text-sm" href="<?= h($dview) ?>" target="_blank" rel="noopener">podgląd online<span class="tw-sr-only"> — <?= h($dlbl) ?> (nowa karta)</span></a><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (($c['kind'] ?? '') !== 'card'): ?><details class="tw-mt-1"><summary class="tw-text-sm" style="font-weight:400">Skąd ta kwota?</summary><p class="m-note tw-mt-1"><?= h(ClaudeBilling::breakdown($c)) ?> (kurs dolara w PKO BP z <?= h(ClaudeBilling::dayMonth(substr((string)$c['rate_at'], 0, 10))) ?>)</p></details><?php endif; ?>
        <?php if ($c['status'] === 'pending' && (($c['pay_mode'] ?? '') === 'card' || ($c['kind'] ?? '') === 'card')): ?>
          <p class="tw-text-sm tw-mt-2"><?= ($c['kind'] ?? '') === 'card' ? 'Płatność kartą nie została dokończona.' : ($card ? 'Pobierzemy z karty ' . h(LicenceCard::label($card)) . ' ' . h(ClaudeBilling::dayMonth($c['due_on'])) . '. Nic nie musisz robić.' : 'Podepnij kartę, żeby zapłacić.') ?>
            <?php if (!$card): ?> <a href="<?= h(route('lk.card', ['product' => $pk])) ?>">Podepnij kartę</a><?php endif; ?></p>
          <?php if (!empty($c['card_error'])): ?><p class="m-note">Ostatnia próba się nie udała. Spróbujemy jeszcze raz, a po <?= LicenceCard::MAX_ATTEMPTS ?> próbach dostaniesz proformę do przelewu.</p><?php endif; ?>
        <?php elseif ($c['status'] === 'pending'): $pid = 'pay' . (int)$c['id']; $amt = ClaudeBilling::zl((float)$c['amount_pln']); $ttl = ClaudeBilling::transferTitle($c); ?>
        <section class="tw-mt-3" aria-labelledby="<?= $pid ?>-h">
          <h3 class="m-sect" id="<?= $pid ?>-h">Jak zapłacić <?= h($amt) ?></h3>
          <div class="tw-grid md:tw-grid-cols-2 tw-gap-3">
            <div class="m-box">
              <p class="tw-font-semibold tw-mb-1">Online</p>
              <p class="tw-text-sm tw-mb-3">BLIK, przelew z banku albo karta. Wpłata dociera od razu.</p>
              <?php if ($virtual): ?>
                <form method="post" action="<?= h(route('lk.sim.pay', ['id' => (int)$c['id']])) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Zapłać online (symulacja)<span class="tw-sr-only"> <?= h($amt) ?></span></button></form>
              <?php else: ?>
                <a class="m-btn" href="<?= h(route('lk.portal')) ?>">Zapłać online<span class="tw-sr-only"> <?= h($amt) ?></span></a>
              <?php endif; ?>
              <p class="m-note tw-mt-3">Przejdziesz do portalu płatności. Tam zapłacisz też za zajęcia i inne opłaty naraz.</p>
            </div>
            <?php if ($v['account']): ?>
            <div class="m-box">
              <p class="tw-font-semibold tw-mb-1">Zwykły przelew</p>
              <p class="tw-text-sm tw-mb-2">Z dowolnego banku. Wpłata dociera w 1–2 dni robocze.</p>
              <dl class="m-kv tw-text-sm">
                <dt>Odbiorca</dt><dd><?= h($org) ?></dd>
                <dt>Numer konta</dt><dd><span class="m-mono"><?= h($v['account']['nrb_fmt']) ?></span> <button type="button" class="m-btn-ghost m-copy lk-copy" data-copy="<?= h(str_replace(' ', '', $v['account']['nrb_fmt'])) ?>">Kopiuj<span class="tw-sr-only"> numer konta</span></button></dd>
                <dt>Tytuł</dt><dd><?= h($ttl) ?> <button type="button" class="m-btn-ghost m-copy lk-copy" data-copy="<?= h($ttl) ?>">Kopiuj<span class="tw-sr-only"> tytuł przelewu</span></button></dd>
                <dt>Kwota</dt><dd><?= h($amt) ?> <button type="button" class="m-btn-ghost m-copy lk-copy" data-copy="<?= h(number_format((float)$c['amount_pln'], 2, ',', '')) ?>">Kopiuj<span class="tw-sr-only"> kwotę</span></button></dd>
                <dt>Termin</dt><dd><?= h(ClaudeBilling::dayMonth($c['due_on'])) ?></dd>
              </dl>
            </div>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php $needData = (bool)array_filter($v['charges'], fn($x) => $x['status'] !== 'cancelled' && str_contains((string)($x['invoice_error'] ?? ''), 'Brak danych do faktury')); ?>
      <details class="m-row"<?= $v['gaps'] || $needData || !empty($err[$pk]) ? ' open' : '' ?>>
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
  <?php // Office ze zniżką edukacyjną: plan domyślny A1 (bez aplikacji stacjonarnych), A3 / A5 płatne — ceny ustala administrator (Płatne licencje)
    $plCur = 'A1'; foreach (['office_a5' => 'A5', 'office_a3' => 'A3'] as $opk => $opl) { $osb = $paid[$opk]['sub'] ?? null; if ($osb && in_array($osb['status'], ['active', 'suspended'], true)) { $plCur = $opl; break; } } ?>
  <div class="m-row">
    <h2 class="m-sect">Office ze zniżką edukacyjną</h2>
    <p class="tw-text-sm tw-mb-2">Twój plan: <b><?= h(LicenceOffice::PLANS[$plCur]['name']) ?></b><?= $plCur === 'A1' ? '. To plan domyślny, w cenie: Office w przeglądarce i na telefonie, bez aplikacji stacjonarnych.' : '.' ?></p>
    <div class="tw-overflow-x-auto"><table class="tw-w-full tw-text-sm" style="border-collapse:collapse">
      <caption class="tw-sr-only">Porównanie planów Microsoft 365 dla edukacji</caption>
      <thead><tr style="border-bottom:1px solid var(--pk-border)"><th scope="col" class="tw-text-left tw-py-2 tw-pr-3">Plan</th><th scope="col" class="tw-text-left tw-py-2 tw-pr-3">Co zawiera</th><th scope="col" class="tw-text-left tw-py-2 tw-pr-3">Aplikacje stacjonarne</th><th scope="col" class="tw-text-left tw-py-2 tw-pr-3">Cena</th><th scope="col" class="tw-text-left tw-py-2"><span class="tw-sr-only">Działanie</span></th></tr></thead>
      <tbody>
      <?php foreach (LicenceOffice::PLANS as $plk => $pl): $pv = $pl['product'] ? ($paid[$pl['product']] ?? null) : null; $ps = $pv['settings'] ?? null; $pSub = $pv['sub'] ?? null;
            $pOpen = $pSub && in_array($pSub['status'], ['requested', 'active', 'suspended'], true); ?>
        <tr style="border-bottom:1px solid var(--pk-border)">
          <th scope="row" class="tw-text-left tw-py-2 tw-pr-3 tw-align-top"><?= h($pl['name']) ?><?= $plk === $plCur ? ' ' . $tag('ok', 'Twój plan') : '' ?></th>
          <td class="tw-py-2 tw-pr-3 tw-align-top"><ul class="tw-list-disc tw-pl-5"><?php foreach ($pl['features'] as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></td>
          <td class="tw-py-2 tw-pr-3 tw-align-top"><?= $pl['desktop'] ? 'tak' : 'nie' ?></td>
          <td class="tw-py-2 tw-pr-3 tw-align-top tw-whitespace-nowrap"><?= !$pl['product'] ? 'w cenie' : ($ps && $ps['priced'] ? h(ClaudeBilling::priceShort($ps)) . ' miesięcznie' . (!empty($pv['rate']) ? '<br><span class="m-note">dziś ≈ ' . h(ClaudeBilling::zl((float)$pv['rate']['amount_pln'])) . '</span>' : '') : '<span class="m-muted">cenę poda biuro</span>') ?></td>
          <td class="tw-py-2 tw-align-top"><?php if ($pOpen): ?><a href="<?= h(route('lk.licence', ['product' => $pl['product']])) ?>">Szczegóły</a>
            <?php elseif ($pl['product'] && $plk !== $plCur && $pv && !empty($pv['orderable'])): ?><a class="m-btn-ghost" href="<?= h(route('lk.wizard', ['product' => $pl['product']])) ?>">Złóż wniosek o aktywację<span class="tw-sr-only"> <?= h($pl['name']) ?></span></a>
            <?php elseif ($pl['product'] && $plk !== $plCur): ?><form method="post" action="<?= h(route('lk.office.plan')) ?>"><?= Router::csrfField() ?><input type="hidden" name="plan" value="<?= h($plk) ?>"><button class="m-btn-ghost" type="submit">Złóż wniosek o aktywację<span class="tw-sr-only"> <?= h($pl['name']) ?></span></button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mt-3">
      <a class="m-btn-ghost" href="<?= h(LicenceOffice::PLANS_SOURCE) ?>" target="_blank" rel="noopener">Pełne porównanie planów ↗<span class="tw-sr-only"> na stronie Microsoft (nowa karta)</span></a>
      <span class="m-note">Powyżej skrót. Szczegóły każdego planu są na stronie Microsoft.</span>
    </div>
  </div>
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
<?php if ($page === 'sprawy'): $hdSt = ['nowe' => ['przyjęte', 'due'], 'otwarte' => ['w toku', 'due'], 'oczekuje' => ['czeka na Ciebie', 'bad'], 'rozwiązane' => ['rozwiązane', 'ok'], 'zamknięte' => ['zamknięte', 'off']]; ?>
<h1 class="m-title">Moje sprawy</h1>
<p class="m-explain">Twoje wnioski licencyjne i zgłoszenia w sprawie licencji. Kliknij sprawę, żeby zobaczyć szczegóły i korespondencję.</p>
<?= $idsLine ?>
<section class="m-tile" aria-labelledby="s-am">
  <h2 class="m-sect" id="s-am">Wnioski AM</h2>
  <?php if (!$sprawy): ?><p class="tw-text-sm m-muted">Nie masz wniosków licencyjnych.</p><?php endif; ?>
  <?php foreach ($sprawy as $o): $isOp = $o['kind'] === 'op';
    $nm = $isOp ? ($C::OP_NAMES[$o['form']] ?? 'Sprawa licencyjna') : (($o['form'] === 'AM7' ? 'Office i oprogramowanie (AM7)' : 'Materiały dydaktyczne (AM5)') . ($o['title'] !== '' ? ': ' . $o['title'] : ''));
    $stl = $isOp ? ($C::OP_STATUS[$o['status']] ?? $o['status']) : ($C::AM7_STATUS[$o['status']] ?? $o['status']);
    $cls = in_array($o['status'], ['executed', 'granted'], true) ? 'ok' : (in_array($o['status'], ['rejected', 'cancelled', 'refused', 'withdrawn'], true) ? 'off' : 'due'); ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3 tw-gap-y-1">
    <a class="tw-font-semibold" href="<?= h(route('lk.case', ['kind' => $o['kind'], 'id' => $o['id']])) ?>"><?= h($nm) ?></a>
    <?= $tag($cls, $stl) ?>
    <span class="m-note">nr <?= h($o['number']) ?> · złożona <?= h(ClaudeBilling::dayMonth(substr($o['created'], 0, 10))) ?><?= $o['ticket_no'] !== '' ? ' · zgłoszenie ' . h($o['ticket_no']) : '' ?></span>
  </div>
  <?php endforeach; ?>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="s-hd">
  <h2 class="m-sect" id="s-hd">Zgłoszenia w sprawie licencji</h2>
  <?php if (!$tickets): ?><p class="tw-text-sm m-muted">Nie masz zgłoszeń.</p><?php endif; ?>
  <?php foreach ($tickets as $t): [$tl, $tc] = $hdSt[$t['status']] ?? [$t['status'], 'due']; ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3 tw-gap-y-1">
    <a class="tw-font-semibold" href="<?= h(route('lk.case', ['kind' => 'hd', 'id' => (int)$t['id']])) ?>"><?= h($t['title']) ?></a>
    <?= $tag($tc, $tl) ?>
    <span class="m-note">nr <?= h($t['number']) ?> · <?= h(ClaudeBilling::dayMonth(substr((string)$t['created_at'], 0, 10))) ?></span>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($page === 'sprawa'): $hdSt = ['nowe' => ['przyjęte', 'due'], 'otwarte' => ['w toku', 'due'], 'oczekuje' => ['czeka na Ciebie', 'bad'], 'rozwiązane' => ['rozwiązane', 'ok'], 'zamknięte' => ['zamknięte', 'off']]; ?>
<p class="m-note tw-mb-2"><a href="<?= h(route('lk.cases')) ?>">← Moje sprawy</a></p>
<?php if ($sprawa): $isOp = $sprawa['kind'] === 'op';
  $nm = $isOp ? ($C::OP_NAMES[$sprawa['form']] ?? 'Sprawa licencyjna') : (($sprawa['form'] === 'AM7' ? 'Office i oprogramowanie (AM7)' : 'Materiały dydaktyczne (AM5)'));
  $stl = $isOp ? ($C::OP_STATUS[$sprawa['status']] ?? $sprawa['status']) : ($C::AM7_STATUS[$sprawa['status']] ?? $sprawa['status']);
  $cls = in_array($sprawa['status'], ['executed', 'granted'], true) ? 'ok' : (in_array($sprawa['status'], ['rejected', 'cancelled', 'refused', 'withdrawn'], true) ? 'off' : 'due'); ?>
<h1 class="m-title"><?= h($nm) ?> <?= $tag($cls, $stl) ?></h1>
<section class="m-tile" aria-label="Szczegóły sprawy">
  <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6">
    <div><dt>Numer sprawy</dt><dd class="m-mono"><?= h($sprawa['number']) ?></dd></div>
    <div><dt>Złożona</dt><dd><?= h(ClaudeBilling::date(substr($sprawa['created'], 0, 10))) ?></dd></div>
    <div><dt>Decyzja</dt><dd><?= $sprawa['decided'] !== '' ? h(ClaudeBilling::date(substr($sprawa['decided'], 0, 10))) : 'jeszcze nie ma' ?></dd></div>
    <?php if (!$isOp && ($sprawa['title'] ?? '') !== ''): ?><div><dt>Czego dotyczy</dt><dd><?= h($sprawa['title']) ?></dd></div><?php endif; ?>
    <?php if (!$isOp && ($sprawa['to'] ?? '') !== ''): ?><div><dt>Ważna</dt><dd><?= ($sprawa['from'] ?? '') !== '' ? h(ClaudeBilling::date($sprawa['from'])) . ' – ' : 'do ' ?><?= h(ClaudeBilling::date($sprawa['to'])) ?></dd></div><?php endif; ?>
    <?php if ($sprawa['ticket_no'] !== ''): ?><div><dt>Zgłoszenie</dt><dd class="m-mono"><?= h($sprawa['ticket_no']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<?php elseif ($ticket): [$tl, $tc] = $hdSt[$ticket['status']] ?? [$ticket['status'], 'due']; ?>
<h1 class="m-title"><?= h($ticket['title']) ?> <?= $tag($tc, $tl) ?></h1>
<?php endif; ?>
<?php if ($ticket): [$tl, $tc] = $hdSt[$ticket['status']] ?? [$ticket['status'], 'due']; ?>
<section class="m-tile tw-mt-4" aria-labelledby="k-h">
  <h2 class="m-sect" id="k-h">Korespondencja · zgłoszenie <?= h($ticket['number']) ?> <?= $tag($tc, $tl) ?></h2>
  <div class="m-row" style="border-top:0;padding-top:0">
    <p class="m-note tw-mb-1"><?= h(date('d.m.Y H:i', strtotime((string)$ticket['created_at']))) ?> · zgłoszenie</p>
    <div class="tw-text-sm"><?= nl2br(h(trim(strip_tags((string)$ticket['description'])))) ?></div>
  </div>
  <?php foreach ($msgs as $m): ?>
  <div class="m-row">
    <p class="m-note tw-mb-1"><?= h(date('d.m.Y H:i', strtotime((string)$m['created_at']))) ?> · <?= h((string)$m['user_name'] ?: 'Fundacja') ?></p>
    <div class="tw-text-sm"><?= function_exists('hd_sanitize_body') ? hd_sanitize_body((string)$m['body']) : nl2br(h(strip_tags((string)$m['body']))) ?></div>
  </div>
  <?php endforeach; ?>
  <?php if ($ticket['status'] !== 'zamknięte'): ?>
  <form method="post" action="<?= h(route('lk.case.reply', ['id' => (int)$ticket['id']])) ?>" class="m-row"><?= Router::csrfField() ?>
    <input type="hidden" name="kind" value="<?= h($caseKind) ?>"><input type="hidden" name="back_id" value="<?= (int)$caseId ?>">
    <label class="m-label" for="k-body">Twoja wiadomość</label>
    <textarea class="m-input" id="k-body" name="body" rows="4" required minlength="2" maxlength="5000"></textarea>
    <button class="m-btn tw-mt-2" type="submit">Wyślij wiadomość</button>
  </form>
  <?php else: ?><p class="m-note">Zgłoszenie jest zamknięte. Jeśli potrzebujesz pomocy, napisz do biura.</p><?php endif; ?>
</section>
<?php elseif ($sprawa): ?>
<p class="m-note tw-mt-3">Ta sprawa nie ma jeszcze korespondencji. Jeśli masz pytanie, napisz do biura.</p>
<?php endif; ?>
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
<?php /* ── Dysk Online (pilotaż, realizuje sdata.net.pl) ── */ ?>
<?php if ($page === 'dysk'): ?>
<div class="tw-flex tw-items-center tw-gap-4 tw-flex-wrap"><img src="https://sd136.sdata.net.pl:8027/media/img/seafile-logo.png" alt="Seafile" style="height:40px;width:auto" onerror="this.remove()"><h1 class="m-title" style="margin:0">Dysk Online <?= $tag($drive ? 'ok' : ($driveReq ? 'due' : 'off'), $drive ? 'aktywny' : ($driveReq ? 'zgłoszenie wysłane' : 'pilotaż')) ?></h1></div>
<p class="m-explain">Dodatkowy dysk w chmurze, widoczny w Windowsie, na Macu i w telefonie jak zwykły dysk. Pliki są na serwerach, nie na Twoim komputerze, więc awaria albo kradzież laptopa ich nie zabiera.</p>
<?= $idsLine ?>
<div class="m-imp tw-mb-4" role="note" aria-label="Ważne informacje o Dysku Online"><p class="tw-font-semibold tw-mb-1">Ważne</p>
  <ul class="tw-list-disc tw-pl-5 tw-space-y-1" style="margin-top:0">
    <li>Usługę realizuje sdata.net.pl. Fundacja prowadzi pilotaż i rozlicza usługę z uczestnikami.</li>
    <li>Ceny to ceny dostawcy. Fundacja rozlicza je jako zwrot kosztów.</li>
    <?php if (!$drive && empty($driveReq)): ?><li>Zgłoszenie potwierdzisz kodem SMS.</li><?php endif; ?>
  </ul></div>
<section class="m-tile" aria-labelledby="d-stan">
  <h2 class="m-sect" id="d-stan">Twój Dysk Online</h2>
  <?php if ($drive): ?>
    <p class="tw-text-sm">Usługa: <b><?= h((string)$drive['name']) ?></b><?= (string)($drive['server_details'] ?? '') !== '' ? ' · ' . h((string)$drive['server_details']) : '' ?><?= $drive['expires_at'] ? ' · do ' . h(ClaudeBilling::dayMonth((string)$drive['expires_at'])) : '' ?></p>
    <p class="m-note">Dane do logowania przekazało Ci biuro. Jeśli ich nie masz, napisz do biura.</p>
  <?php else: ?>
    <?php require_once dirname(__DIR__) . '/logic/LicencjeDysk.php'; $dPlans = LicencjeDysk::plans(true); ?>
    <?php $dLock = !empty($driveReq); $dSel = $dLock ? (string)$driveReq['key'] : ''; ?>
    <?php if ($dLock): ?>
    <div class="pk-alert pk-alert-ok" role="status">Twoje zgłoszenie dotarło <?= h(ClaudeBilling::dayMonth((string)$driveReq['at'])) ?><?= $driveReq['ticket'] !== '' ? ' (nr ' . h($driveReq['ticket']) . ')' : '' ?>. Wybrany pakiet: <b><?= h($driveReq['plan']) ?></b>. Założymy dysk i prześlemy dane do logowania. Jeśli chcesz zmienić pakiet, napisz w <a href="<?= h(route('lk.cases')) ?>">Moich sprawach</a>.</div>
    <?php else: ?>
    <p class="tw-text-sm tw-mb-3">To usługa pilotażowa. Wybierz pakiet i zgłoś się. Założymy Ci dysk i prześlemy dane do logowania.</p>
    <?php endif; ?>
    <form method="post" action="<?= h(route('lk.drive.join')) ?>"><?= Router::csrfField() ?>
      <fieldset style="border:0;padding:0;margin:0"<?= $dLock ? ' disabled' : '' ?>><legend class="tw-text-sm tw-font-semibold tw-mb-2"><?= $dLock ? 'Pakiet (zgłoszony)' : 'Pakiet' ?></legend>
      <div class="tw-grid md:tw-grid-cols-3 tw-gap-3">
        <?php $dFirst = true; foreach ($dPlans as $dk => $dp): $dOn = $dLock ? $dk === $dSel : $dFirst; ?>
        <label class="m-box" style="display:block;<?= $dLock ? ($dOn ? 'border:2px solid var(--mt-ink)' : 'opacity:.55') : 'cursor:pointer' ?>">
          <span class="tw-flex tw-items-center tw-gap-2"><input type="radio" name="plan" value="<?= h($dk) ?>"<?= $dOn ? ' checked' : '' ?> required> <span class="tw-font-semibold"><?= h($dp['name']) ?></span></span>
          <span class="m-num tw-block tw-mt-1" style="font-size:1.5rem"><?= h(LicencjeDysk::zl($dp['price'])) ?></span><span class="m-note">miesięcznie</span>
          <span class="tw-block tw-text-sm tw-mt-2"><b><?= h($dp['size']) ?></b><?= $dp['users'] ? ', do ' . (int)$dp['users'] . ' użytkowników' : '' ?></span>
        </label>
        <?php $dFirst = false; endforeach; ?>
      </div>
      </fieldset>
      <p class="tw-text-sm tw-mt-3">W każdym pakiecie: <?= h(implode(', ', LicencjeDysk::FEATURES)) ?>.</p>
      <?php if (!$dLock): ?><button class="m-btn tw-mt-3" type="submit">Zgłaszam się do pilotażu</button><?php endif; ?>
    </form>
  <?php endif; ?>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="d-sea">
  <h2 class="m-sect" id="d-sea">Czym jest Seafile</h2>
  <p class="tw-text-sm tw-mb-2">Dysk Online działa na Seafile. To program do przechowywania i udostępniania plików z otwartym kodem źródłowym. Korzystają z niego uczelnie, firmy i instytucje.</p>
  <ul class="tw-list-disc tw-pl-6 tw-text-sm tw-space-y-1">
    <li><b>Biblioteki.</b> Pliki trzymasz w bibliotekach, czyli głównych folderach. Bibliotekę możesz udostępnić innym osobom albo zaszyfrować własnym hasłem. Wtedy do środka nie zajrzy nawet administrator serwera.</li>
    <li><b>Trzy sposoby pracy.</b> Przez przeglądarkę, przez program SeaDrive, który pokazuje dysk w Eksploratorze Windows albo w Finderze na Macu, i przez aplikację Seafile na Androida i iPhone'a.</li>
    <li><b>Nie zajmuje miejsca na komputerze.</b> SeaDrive pobiera plik dopiero wtedy, gdy go otwierasz.</li>
    <li><b>Historia i kosz.</b> Seafile pamięta wcześniejsze wersje plików, a usunięte pliki przez jakiś czas trzyma w koszu biblioteki.</li>
  </ul>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="d-jak">
  <h2 class="m-sect" id="d-jak">Jak zacząć</h2>
  <ol class="tw-list-decimal tw-pl-6 tw-text-sm tw-space-y-1">
    <li>Zgłaszasz się do pilotażu. Dostajesz login i hasło.</li>
    <li>Instalujesz program na komputerze z Windowsem, na Macu albo w telefonie.</li>
    <li>Wgrywasz pliki na nowy dysk, który się pojawi.</li>
    <li>Gotowe. Masz do nich dostęp z domu, z pracy i z telefonu. Możesz też udostępniać pliki innym osobom.</li>
  </ol>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="d-zal">
  <h2 class="m-sect" id="d-zal">Co zyskujesz</h2>
  <ul class="tw-list-disc tw-pl-6 tw-text-sm tw-space-y-1">
    <li><b>Wspólna praca.</b> Udostępniasz foldery innym użytkownikom. Osobom z zewnątrz możesz dać dostęp do plików przez przeglądarkę.</li>
    <li><b>Bezpieczeństwo przy awarii.</b> Pliki są na wirtualnym dysku, a nie na Twoim komputerze. Codziennie robimy kopię zapasową, więc po awarii komputera dane na Ciebie czekają.</li>
    <li><b>Ochrona przed kradzieżą.</b> Jeśli ktoś ukradnie Ci laptopa, pliki nadal są na Dysku Online.</li>
    <li><b>Wersje plików.</b> Przywrócisz starszą wersję dokumentu Word, arkusza Excel albo pliku tekstowego.</li>
    <li><b>Dostęp z telefonu.</b> Wygodne do wgrywania zdjęć i innych plików.</li>
  </ul>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="d-bez">
  <h2 class="m-sect" id="d-bez">Bezpieczeństwo</h2>
  <ul class="tw-list-disc tw-pl-6 tw-text-sm tw-space-y-1">
    <li>Dane są w profesjonalnych centrach danych: główne serwery w Niemczech, zapasowe w Finlandii. Fizyczny dostęp ma tylko uprawniony personel.</li>
    <li>Serwery mają podwójne zasilanie, podwójne łącza i macierze dyskowe, więc usługa działa całą dobę.</li>
    <li>Kopia zapasowa jest w innym kraju niż serwer główny, więc dane zawsze da się odtworzyć.</li>
    <li>Każdy dysk działa w osobnym kontenerze. Pliki różnych osób się nie mieszają.</li>
    <li>Serwery chroni zapora sieciowa, otwarte są tylko porty potrzebne do pracy.</li>
    <li>Połączenie z dyskiem jest szyfrowane (HTTPS), więc nikt nie podejrzy przesyłanych plików.</li>
  </ul>
</section>
<?php endif; ?>

<?php /* ── Rozliczenia: karta i dane do faktur (konta rozliczeniowe) ── */ ?>
<?php if ($page === 'rozliczenia'): ?>
<h1 class="m-title">Rozliczenia</h1>
<p class="m-explain">Karta do płatności za licencje i dane, na które wystawiamy faktury. Każdą zmianę potwierdzasz kodem SMS.</p>
<?= $idsLine ?>
<section class="m-tile" aria-labelledby="r-karta">
  <h2 class="m-sect" id="r-karta">Karta płatnicza</h2>
  <?php if ($card): $cz = $card['consent_id'] ? LicenceCard::consent((int)$card['consent_id']) : null; ?>
    <p class="tw-text-sm"><span class="tw-font-semibold"><?= h(LicenceCard::label($card)) ?></span> <?= $tag('ok', 'podpięta') ?></p>
    <p class="m-note">Podpięta <?= h(ClaudeBilling::dayMonth(substr((string)$card['created_at'], 0, 10))) ?>. Opłaty pobieramy automatycznie, przed każdym obciążeniem dostajesz e-mail z kwotą i dniem.</p>
    <?php if ($cz): ?><p class="tw-text-sm tw-mt-2">Zgoda z <?= h(date('d.m.Y H:i', strtotime((string)$cz['accepted_at']))) ?>: <a class="m-btn-ghost" href="<?= h(route('lk.consent', ['id' => (int)$cz['id']])) ?>" download>Pobierz PDF<span class="tw-sr-only"> zgody na płatności kartą</span></a></p><?php endif; ?>
    <details class="m-row"><summary>Wycofaj zgodę i odepnij kartę</summary>
      <form method="post" action="<?= h(route('lk.card.remove')) ?>" class="tw-mt-3"><?= Router::csrfField() ?>
        <p class="tw-text-sm">Kolejne opłaty dostaniesz jako proformę do przelewu. To nie jest rezygnacja z licencji i nie zmienia opłat, które już minęły.</p>
        <button class="m-btn-ghost m-btn-danger tw-mt-2" type="submit">Wycofaj zgodę i odepnij kartę</button>
      </form>
    </details>
    <?php foreach ($cardModes as $ck => $cv): ?><p class="tw-text-sm tw-mt-2"><a href="<?= h(route('lk.card', ['product' => $ck])) ?>">Zmień kartę</a> <span class="m-note">(nowa zgoda i opłata weryfikacyjna <?= h(ClaudeBilling::zl((float)$cv['settings']['card_fee_pln'])) ?>)</span></p><?php break; endforeach; ?>
  <?php elseif ($cardModes): ?>
    <p class="tw-text-sm">Za <?= h(implode(', ', array_map(fn($v) => $v['P']['label'], $cardModes))) ?> płacisz kartą. Nie masz jeszcze podpiętej karty.</p>
    <?php foreach ($cardModes as $ck => $cv): ?><a class="m-btn tw-mt-2" href="<?= h(route('lk.card', ['product' => $ck])) ?>">Podepnij kartę (<?= h($cv['P']['label']) ?>)</a> <?php endforeach; ?>
    <p class="m-note tw-mt-2">Najpierw pobierzemy <?= h(ClaudeBilling::zl((float)reset($cardModes)['settings']['card_fee_pln'])) ?> opłaty weryfikacyjnej karty, z fakturą VAT.</p>
  <?php else: ?>
    <p class="tw-text-sm">Za licencje płacisz przelewem po proformie. Płatność kartą włącza Fundacja.</p>
  <?php endif; ?>
  <?php if ($consents): ?>
  <div class="m-row">
    <h3 class="tw-font-semibold tw-text-sm tw-mb-1">Zgody na płatności kartą</h3>
    <ul class="tw-text-sm tw-space-y-1">
      <?php foreach ($consents as $z): ?>
      <li><?= h(date('d.m.Y H:i', strtotime((string)$z['accepted_at']))) ?> · <?= h(ClaudeBilling::P((string)$z['product'])['label']) ?> · IP <?= h((string)$z['ip']) ?> ·
        <?= $z['withdrawn_at'] ? $tag('off', 'wycofana ' . ClaudeBilling::dayMonth(substr((string)$z['withdrawn_at'], 0, 10))) : ($z['card_id'] ? $tag('ok', 'aktywna') : $tag('due', 'płatność niedokończona')) ?>
        <a href="<?= h(route('lk.consent', ['id' => (int)$z['id']])) ?>" download>PDF<span class="tw-sr-only"> zgody z <?= h(date('d.m.Y', strtotime((string)$z['accepted_at']))) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</section>
<section class="m-tile tw-mt-4" aria-labelledby="r-fv">
  <h2 class="m-sect" id="r-fv">Dane do faktur</h2>
  <?php $fvSubs = array_filter($paid, fn($v) => $v['sub'] && in_array($v['sub']['status'], ['requested', 'active', 'suspended'], true)); ?>
  <?php if (!$fvSubs): ?><p class="tw-text-sm">Nie masz subskrypcji, więc nie wystawiamy Ci faktur za licencje.</p><?php endif; ?>
  <?php foreach ($fvSubs as $fk => $fv): $fb = array_merge(array_intersect_key($fv['sub'], ClaudeBilling::BUYER_FIELDS), array_intersect_key($old[$fk] ?? [], ClaudeBilling::BUYER_FIELDS)); $fe = $err[$fk] ?? []; ?>
  <details class="m-row"<?= $fv['gaps'] || $fe ? ' open' : '' ?>>
    <summary><?= h($fv['P']['label']) ?>: <?= h(trim((string)$fb['buyer_name']) ?: 'brak nabywcy') ?><?= $fv['gaps'] ? ' — brakuje: ' . h(implode(', ', $fv['gaps'])) : '' ?></summary>
    <form method="post" action="<?= h(route('lk.buyer', ['product' => $fk])) ?>" novalidate class="tw-mt-3"><?= Router::csrfField() ?><input type="hidden" name="back" value="rozliczenia">
      <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
        <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): $eid = 'r' . $fk . $k; ?>
        <div><label class="m-label" for="<?= h($eid) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label>
          <input class="m-input" id="<?= h($eid) ?>" name="<?= h($k) ?>" value="<?= h((string)($fb[$k] ?? '')) ?>"<?= isset($fe[$k]) ? ' aria-invalid="true" aria-describedby="' . h($eid) . '-e"' : '' ?>>
          <?php if (isset($fe[$k])): ?><p class="m-err" id="<?= h($eid) ?>-e"><?= h((string)$fe[$k]) ?></p><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <button class="m-btn-ghost tw-mt-3" type="submit">Zapisz dane do faktury</button>
    </form>
  </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

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
