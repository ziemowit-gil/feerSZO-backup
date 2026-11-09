<?php
if (!isset($me, $paid)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/index.php — panel licencji (jeden panel jak Portal Kontrahenta): menu po lewej, sekcje na jednej stronie.
 * Sekcje: Subskrypcje (Claude, NordVPN) · Licencje na Office · Programy i klucze · Materiały dydaktyczne · Moje sprawy · Produkty i usługi
 * (refakturowane przez FEER — liczone osobno) · Moje konto. Prostym językiem: co masz, do kiedy, ile płacisz, co zrobić.
 * Wygląd Metro: znaczniki stanu m-tag (ok / due / bad / off), płaskie kafle. Office: konto @edu.feer.org.pl = Office aktywny.
 * Każdą operację potwierdza kod jednorazowy (SMS) — formularze idą jak zwykle, kontroler odsyła na /potwierdz.
 * Zmienne: $me, $paid, $software, $termOk, $office, $am5, $ops, $products, $prodMonthly, $feerUpn, $p24on, $login, $hasPassword, $minor, $contact, $eduAccount, $flash, $err, $old, $org.
 */
$C = LicencjeKursantController::class;
$e = fn(string $p, string $k) => isset($err[$p][$k]) ? '<p id="e-' . h($p . $k) . '" class="m-err">' . h($err[$p][$k]) . '</p>' : '';
$a = fn(string $p, string $k) => isset($err[$p][$k]) ? ' aria-invalid="true" aria-describedby="e-' . h($p . $k) . '"' : '';
$today = date('Y-m-d');
$nPay = 0; $sumPay = 0.0;
foreach ($paid as $v) foreach ($v['charges'] as $c) if ($c['status'] === 'pending') { $nPay++; $sumPay += (float)$c['amount_pln']; }
$PAGE_TITLE = 'Moje licencje';
$nav = [['subskrypcje', 'Subskrypcje', $nPay ? 'do zapłaty ' . ClaudeBilling::zl($sumPay) : (string)count($paid), $nPay > 0],
        ['office', 'Licencje na Office', ($office || $eduAccount) ? (string)(count($office) + ($eduAccount ? 1 : 0)) : '', false],
        ['programy', 'Programy i klucze', $software ? (string)count($software) : '', false],
        ['dydaktyczne', 'Materiały dydaktyczne', $am5 ? (string)count($am5) : '', false],
        ['sprawy', 'Moje sprawy', $ops ? (string)count($ops) : '', false],
        ['produkty', 'Produkty i usługi', $prodMonthly > 0 ? ClaudeBilling::zl($prodMonthly) . '/mies.' : ($products ? (string)count($products) : ''), false],
        ['konto', 'Moje konto', '', false]];
include __DIR__ . '/_top.php';
$tag = fn(string $cls, string $txt) => '<span class="m-tag ' . h($cls) . '">' . h($txt) . '</span>';
?>
<h1 class="m-title">Moje licencje</h1>
<p class="m-lead"><?= h($me['name']) ?> — licencje, subskrypcje i usługi od Fundacji w jednym miejscu. Każdą zmianę potwierdzasz kodem z SMS-a<?= $contact['phone'] !== '' ? ' (numer ' . h(LicencjeKod::mask($contact['phone'])) . ')' : '' ?>.</p>

<?php /* ── Subskrypcje: Claude, NordVPN ── */ ?>
<section id="subskrypcje" class="tw-mb-2" aria-labelledby="h-sub">
  <h2 id="h-sub" class="m-sect">Subskrypcje <small>— opłacane co miesiąc: proforma, wpłata, faktura</small></h2>
  <?php if (!$paid): ?><div class="m-tile"><p>Nie masz subskrypcji. Jeśli chcesz korzystać z Claude albo NordVPN, napisz do prowadzącego — licencję trzeba Ci najpierw przypisać.</p></div><?php endif; ?>
<?php foreach ($paid as $pk => $v): $P = $v['P']; $s = $v['settings']; $sub = $v['sub'];
  $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
  $buyer = array_merge($open ? array_intersect_key($sub, ClaudeBilling::BUYER_FIELDS) : ClaudeBilling::buyerDefaults((int)$me['cid']), array_intersect_key($old[$pk] ?? [], ClaudeBilling::BUYER_FIELDS));
  $state = !$sub || $sub['status'] === 'ended' ? ['możesz włączyć', 'off'] : ($sub['status'] === 'active' ? ['włączony', 'ok'] : ['wstrzymany — brak wpłaty', 'bad']);
  if (!$open && !$v['eligible']['ok']) $state = [!empty($v['eligible']['needEdu']) ? 'potrzebne konto @edu' : 'niedostępny', 'off'];
  $priceTxt = $s['priced'] ? 'Co miesiąc ' . ClaudeBilling::usdWords($s['usd']) . ' po kursie PKO BP' . ClaudeBilling::extrasWords($s) . ($v['rate'] ? ' — dziś razem ok. ' . ClaudeBilling::zl((float)$v['rate']['amount_pln']) : '')
              . ($s['list_usd'] > $s['usd'] ? '. ' . ClaudeBilling::usdWords($s['usd']) . ' to cena dla organizacji non-profit (normalnie ' . ClaudeBilling::usdWords($s['list_usd']) . ').' : '.') : '';
  $pr = $v['projection'] ?? ['now' => null, 'next' => null];
  $proj = function () use ($pr, $open, $s) { if ((!$pr['now'] && !$pr['next']) || !$s['priced'] || (!$open && !$s['enabled'])) return; ?>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3 tw-mb-4" role="group" aria-label="Najbliższe płatności">
      <?php foreach (['now' => $open ? 'Ta płatność' : 'Pierwsza płatność (po włączeniu)', 'next' => 'Następna płatność'] as $k2 => $lbl): if (!$pr[$k2]) continue; $x = $pr[$k2]; ?>
      <div class="m-box<?= $k2 === 'now' ? ' now' : '' ?>">
        <div class="m-kv"><dt><?= h($lbl) ?></dt></div>
        <div class="m-num"><?= $x['estimate'] ? '≈ ' : '' ?><?= h(ClaudeBilling::zl((float)$x['amount'])) ?></div>
        <div class="tw-text-sm tw-mt-1">dokument <?= h(ClaudeBilling::dayMonth($x['issue'])) ?>, zapłać do <?= h(ClaudeBilling::dayMonth($x['due'])) ?></div>
        <?php if ($x['estimate']): ?><div class="m-note tw-mt-1">szacunek po dzisiejszym kursie — dokładna kwota zależy od kursu z dnia dokumentu</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php };
?>
  <section id="<?= h($pk) ?>" class="m-tile" aria-labelledby="h-<?= h($pk) ?>">
    <h3 id="h-<?= h($pk) ?>" class="m-head"><?= h($P['label']) ?> <?= $tag($state[1], $state[0]) ?></h3>
    <p class="tw-text-sm tw-mb-3"><?= h(ucfirst($P['what'])) ?>.<?= $s['enabled'] || $open ? ' ' . h($priceTxt) : '' ?></p>

    <?php if ($open): ?>
      <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6">
        <div><dt>Włączony od</dt><dd><?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?></dd></div>
        <div><dt>Opłacony do</dt><dd><?= $v['paidThrough'] ? h(ClaudeBilling::dayMonth($v['paidThrough'])) : 'jeszcze nic' ?></dd></div>
        <div><dt>Dokument co miesiąc</dt><dd><?= ClaudeBilling::dueDay($sub, $s) === 1 ? '1. dnia miesiąca' : 'ok. ' . ClaudeBilling::dueDay($sub, $s) . '. dnia' ?>, płatny w <?= ClaudeBilling::TERM_DAYS ?> dni</dd></div>
      </dl>
      <?php $proj(); ?>
      <?php if (!empty($v['priceChange'])): $pc = $v['priceChange']; $chg = $pc['change']; $cmp = $v['priceCmp']; $fromD = ClaudeBilling::dayMonth((string)$chg['effective_from']); ?>
      <div class="pk-alert pk-alert-info" role="region" aria-label="Zmiana ceny <?= h($P['label']) ?>">
        <p class="tw-font-semibold tw-mb-1">Od <?= h($fromD) ?> zmienia się cena</p>
        <p class="tw-text-sm tw-mb-1">Teraz: <?= h($cmp['old']) ?><?= $cmp['oldPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['oldPln'])) . ')' : '' ?>. Od <?= h($fromD) ?>: <b><?= h($cmp['new']) ?></b><?= $cmp['newPln'] !== null ? ' (≈ ' . h(ClaudeBilling::zl($cmp['newPln'])) . ' miesięcznie)' : '' ?>.</p>
        <?php if ((string)$chg['reason'] !== ''): ?><p class="tw-text-sm tw-mb-1">Dlaczego: <?= h((string)$chg['reason']) ?></p><?php endif; ?>
        <?php if ($pc['recipient'] === 'opiekun'): ?>
          <p class="tw-text-sm tw-mb-0">Decyzję podejmuje Twój opiekun — dostał e-mail z linkiem.<?= $pc['decision'] !== '' ? ' Decyzja: ' . ($pc['decision'] === 'accept' ? 'akceptacja.' : 'rezygnacja.') : '' ?></p>
        <?php else: ?>
          <?php if ($pc['decision'] !== ''): ?><p class="tw-text-sm tw-mb-2">Twoja decyzja: <b><?= $pc['decision'] === 'accept' ? 'akceptuję nową cenę' : 'rezygnuję — dostęp skończy się przed ' . h($fromD) ?></b>. Możesz ją zmienić do <?= h(ClaudeBilling::dayMonth(date('Y-m-d', strtotime($chg['effective_from'] . ' -1 day')))) ?>.</p>
          <?php else: ?><p class="tw-text-sm tw-mb-2">Prosimy o decyzję. Bez odpowiedzi: <?= $chg['no_answer'] === 'reject' ? 'dostęp skończy się przed ' . h($fromD) : 'zostajesz z nową ceną (zrezygnować możesz w każdej chwili)' ?>.</p><?php endif; ?>
          <form method="post" action="<?= h(route('lk.price.decide', ['id' => (int)$pc['id']])) ?>" class="tw-flex tw-flex-wrap tw-gap-2"><?= Router::csrfField() ?>
            <?php if ($pc['decision'] !== 'accept'): ?><button class="m-btn" type="submit" name="decision" value="accept">Akceptuję nową cenę</button><?php endif; ?>
            <?php if ($pc['decision'] !== 'reject'): ?><button class="m-btn-ghost m-btn-danger" type="submit" name="decision" value="reject">Nie akceptuję — rezygnuję</button><?php endif; ?>
          </form>
          <p class="m-note tw-mt-2">Potwierdzisz kodem z SMS-a.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($sub['status'] === 'suspended'): ?><div class="pk-alert pk-alert-err" role="alert">Dostęp jest wstrzymany, bo nie dotarła wpłata. Zapłać zaległość poniżej — włączymy go z powrotem sami.</div><?php endif; ?>
      <?php if ($v['gaps']): ?><div class="pk-alert pk-alert-info" role="alert"><b>Brakuje danych do faktury:</b> <?= h(implode(', ', $v['gaps'])) ?>. Uzupełnij je niżej.</div><?php endif; ?>

      <?php foreach ($v['charges'] as $c): if ($c['status'] === 'cancelled') continue; $late = $c['status'] === 'pending' && $c['due_on'] < $today;
        $docUrl = $c['status'] === 'paid' ? (string)$c['invoice_view_url'] : ((string)$c['proforma_view_url'] ?: (string)$c['invoice_view_url']);
        $docNo  = $c['status'] === 'paid' ? (string)$c['invoice_number'] : ((string)$c['proforma_number'] ?: (string)$c['invoice_number']);
        $docLbl = $c['status'] === 'paid' || ($c['proforma_number'] === '' && $c['invoice_number'] !== '') ? 'faktura' : 'proforma';
        if (str_starts_with($docNo, 'SYM-')) { $docNo = ''; $docUrl = ''; } ?>
      <div class="m-row">
        <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
          <span class="tw-font-semibold"><?= h(ucfirst(ClaudeBilling::periodLabel((string)$c['period']))) ?></span>
          <span class="tw-text-lg tw-font-semibold" style="font-variant-numeric:tabular-nums"><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></span>
          <?php if ($c['status'] === 'paid'): ?><?= $tag('ok', 'zapłacone ' . ClaudeBilling::dayMonth(substr((string)$c['paid_at'], 0, 10))) ?>
          <?php else: ?><?= $tag($late ? 'bad' : 'due', ($late ? 'po terminie — było do ' : 'zapłać do ') . ClaudeBilling::dayMonth($c['due_on'])) ?><?php endif; ?>
          <?php if ($docUrl !== ''): ?><a href="<?= h($docUrl) ?>" target="_blank" rel="noopener" class="tw-text-sm"><?= $docLbl ?> <?= h($docNo) ?><span class="tw-sr-only"> (nowa karta)</span></a>
          <?php else: ?><span class="tw-text-sm m-muted"><?= $c['status'] === 'paid' ? 'faktura' : 'proforma' ?> w przygotowaniu</span><?php endif; ?>
        </div>
        <p class="m-note tw-mt-1"><?= h(ClaudeBilling::breakdown($c)) ?> (kurs PKO BP z <?= h(ClaudeBilling::dayMonth(substr((string)$c['rate_at'], 0, 10))) ?>)</p>
        <?php if ($c['status'] === 'pending'): ?>
        <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2">
          <?php if ($s['pay_redirect'] === 'portal'): ?>
            <a class="m-btn" href="../platnosci.php">Zapłać<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></a>
          <?php elseif ($s['pay_redirect'] === 'fakturownia' && $docUrl !== ''): ?>
            <a class="m-btn" href="<?= h($docUrl) ?>" target="_blank" rel="noopener">Zapłać (BLIK / szybki przelew)<span class="tw-sr-only"> — <?= $docLbl ?> w nowej karcie</span></a>
          <?php elseif (!empty($p24on)): ?>
            <form method="post" action="<?= h(route('lk.pay', ['id' => (int)$c['id']])) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Zapłać przez Przelewy24<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></button></form>
          <?php endif; ?>
          <?php if ($docUrl !== '' && $s['pay_redirect'] !== 'fakturownia'): ?><a class="m-btn-ghost" href="<?= h($docUrl) ?>" target="_blank" rel="noopener">BLIK / szybki przelew z <?= $docLbl === 'proforma' ? 'proformy' : 'faktury' ?><span class="tw-sr-only"> (nowa karta)</span></a><?php endif; ?>
        </div>
        <?php if ($v['account']): ?><p class="tw-text-sm tw-mt-2">Albo zwykły przelew: konto <span class="m-mono tw-font-semibold"><?= h($v['account']['nrb_fmt']) ?></span>, tytuł <span class="tw-font-semibold"><?= h(ClaudeBilling::transferTitle($c)) ?></span>, kwota <?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?>.</p><?php endif; ?>
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
        <summary>Zrezygnuj z <?= h($P['label']) ?></summary>
        <form method="post" action="<?= h(route('lk.cancel', ['product' => $pk])) ?>" class="tw-mt-3"><?= Router::csrfField() ?>
          <p class="tw-text-sm tw-mb-2">Nie dostaniesz kolejnych dokumentów. Dostęp zostanie wyłączony<?= $v['paidThrough'] ? ' po ' . h(ClaudeBilling::dayMonth($v['paidThrough'])) : '' ?>. Nieopłaconą zaległość trzeba jeszcze zapłacić.</p>
          <label class="m-check"><input type="checkbox" name="confirm" value="1" required><span>Tak, rezygnuję z <?= h($P['label']) ?>.</span></label>
          <button class="m-btn-ghost m-btn-danger tw-mt-3" type="submit">Zrezygnuj</button>
        </form>
      </details>

    <?php elseif (!empty($v['eligible']['needEdu'])): $eduReq = $v['edu']['request'] ?? null; ?>
      <div class="pk-alert pk-alert-info"><?= h($v['eligible']['reason']) ?></div>
      <?php if ($eduReq): ?>
        <p class="tw-text-sm">Poprosiliśmy o konto <b class="m-mono"><?= h($eduReq['proposed']) ?></b> <?= h(ClaudeBilling::dayMonth(substr((string)$eduReq['created_at'], 0, 10))) ?><?= $eduReq['helpdesk_number'] ? ' (zgłoszenie ' . h($eduReq['helpdesk_number']) . ')' : '' ?>. Gdy Dział IT je założy, włączysz Claude tutaj.</p>
      <?php else: ?>
        <p class="tw-text-sm tw-mb-3">Proponujemy konto: <b class="m-mono"><?= h($login ? $login . '@' . ClaudeBilling::EDU_DOMAIN : 'l + 8 cyfr @' . ClaudeBilling::EDU_DOMAIN) ?></b> — bez Twojego imienia i nazwiska w adresie.</p>
        <form method="post" action="<?= h(route('lk.edu')) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Poproś o konto @<?= h(ClaudeBilling::EDU_DOMAIN) ?></button></form>
      <?php endif; ?>
    <?php elseif (!$v['eligible']['ok']): ?>
      <p class="tw-text-sm"><?= h($v['eligible']['reason']) ?></p>
      <?php $proj(); ?>
    <?php else: ?>
      <?php $proj(); ?>
      <form method="post" action="<?= h(route('lk.activate', ['product' => $pk])) ?>" novalidate><?= Router::csrfField() ?>
        <h4 class="m-sect tw-mt-2">Włącz <?= h($P['label']) ?></h4>
        <p class="tw-text-sm tw-mb-3">Podaj dane do faktury — przy każdej opłacie dostaniesz proformę, a po wpłacie fakturę. Pola z gwiazdką są wymagane.</p>
        <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
          <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
          <div><label class="m-label" for="<?= h($pk . $k) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label><input class="m-input" id="<?= h($pk . $k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] ?? '')) ?>"<?= $a($pk, $k) ?>><?= $e($pk, $k) ?></div>
          <?php endforeach; ?>
        </div>
        <label class="m-check tw-mt-4"><input type="checkbox" name="accept" value="1"<?= !empty($old[$pk]['accept']) ? ' checked' : '' ?><?= $a($pk, 'accept') ?>><span class="tw-text-sm"><?= h(ClaudeBilling::termsText($s)) ?></span></label><?= $e($pk, 'accept') ?>
        <button class="m-btn tw-mt-4" type="submit">Włącz <?= h($P['label']) ?> — akceptuję zasady</button>
        <p class="m-note tw-mt-2">Potwierdzisz kodem z SMS-a. Po włączeniu Dział IT dostaje zgłoszenie i uruchamia Twoje konto. Pierwsza proforma pojawi się tu od razu.</p>
      </form>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</section>

<?php /* ── Licencje na Office (AM7) ── */ ?>
<section id="office" class="m-tile" aria-labelledby="h-office">
  <h2 id="h-office" class="m-head">Licencje na Office</h2>
  <?php if ($eduAccount): $hintE = rawurlencode($eduAccount); ?>
  <div class="m-row" style="border-top:0;padding-top:0">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3"><span class="tw-font-semibold">Office (Microsoft 365 dla edukacji)</span> <?= $tag('ok', 'aktywny') ?></div>
    <p class="tw-text-sm tw-mt-1">Masz konto <span class="m-mono tw-font-semibold"><?= h($eduAccount) ?></span> — z nim działa Office: Word, Excel, PowerPoint, Outlook i Teams w przeglądarce.
      <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($eduAccount) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
    <p class="tw-text-sm tw-mt-1"><a href="https://www.office.com/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a> · <a href="https://outlook.office.com/mail/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Poczta<span class="tw-sr-only"> (nowa karta)</span></a> · <a href="https://teams.microsoft.com/?login_hint=<?= h($hintE) ?>" target="_blank" rel="noopener">Teams<span class="tw-sr-only"> (nowa karta)</span></a></p>
  </div>
  <?php endif; ?>
  <?php if (!$office && !$eduAccount): ?><p class="tw-text-sm m-muted">Nie masz licencji na Office. Office działa z kontem @<?= h(ClaudeBilling::EDU_DOMAIN) ?> — potrzebujesz? Napisz do prowadzącego, złożymy wniosek.</p><?php endif; ?>
  <?php foreach ($office as $o): $g = $o['request_status'] === 'granted'; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($o['title'] ?: 'Microsoft 365 / Office') ?></span>
      <?= $tag($g ? 'ok' : ($o['request_status'] === 'refused' ? 'bad' : 'due'), $C::AM7_STATUS[$o['request_status']] ?? $o['request_status']) ?>
    </div>
    <?php if ($g && $o['tenant_upn']): ?>
      <p class="tw-text-sm tw-mt-1">Login do Office: <span class="m-mono tw-font-semibold"><?= h($o['tenant_upn']) ?></span> · <a href="https://www.office.com/?login_hint=<?= h(rawurlencode((string)$o['tenant_upn'])) ?>" target="_blank" rel="noopener">otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a></p>
      <p class="m-note">Hasło startowe przyszło SMS-em. Przy pierwszym logowaniu Microsoft poprosi o nowe.</p>
    <?php elseif ($g): ?><p class="tw-text-sm tw-mt-1">Licencja przyznana — Dział IT zakłada konto<?= $login ? ' (login ' . h($login) . ')' : '' ?>. Dane logowania przyjdą e-mailem i SMS-em.</p><?php endif; ?>
    <?php if ($o['period_to']): ?><p class="m-note">Ważna<?= $o['period_from'] ? ' od ' . h(ClaudeBilling::dayMonth($o['period_from'])) : '' ?> do <?= h(ClaudeBilling::dayMonth($o['period_to'])) ?> · sprawa <?= h($o['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Programy i klucze (moduł Licencje) ── */ ?>
<section id="programy" class="m-tile" aria-labelledby="h-soft">
  <h2 id="h-soft" class="m-head">Programy i klucze</h2>
  <?php if (!$software): ?><p class="tw-text-sm m-muted">Nie masz przypisanych programów.</p><?php endif; ?>
  <?php if ($software && !$termOk): ?><div class="pk-alert pk-alert-info">Loginy i klucze zobaczysz po zaakceptowaniu oświadczenia o poufności w panelu uczestnika (Regulaminy).</div><?php endif; ?>
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

<?php /* ── Materiały dydaktyczne (AM5) ── */ ?>
<section id="dydaktyczne" class="m-tile" aria-labelledby="h-am5">
  <h2 id="h-am5" class="m-head">Materiały dydaktyczne</h2>
  <?php if (!$am5): ?><p class="tw-text-sm m-muted">Brak licencji na materiały do zajęć.</p><?php endif; ?>
  <?php foreach ($am5 as $x): ?>
  <div class="m-row">
    <span class="tw-font-semibold"><?= h($x['title'] ?: 'Licencja dydaktyczna') ?></span>
    <?= $tag($x['status'] === 'accepted' ? 'ok' : (in_array($x['status'], ['rejected', 'revoked'], true) ? 'bad' : 'due'), $C::AM5_STATUS[$x['status']] ?? $x['status']) ?>
    <?php if ($x['status'] === 'invited' && $x['due_on']): ?><p class="tw-text-sm tw-mt-1">Odeślij oświadczenie do <?= h(ClaudeBilling::dayMonth($x['due_on'])) ?>. Nie masz linku? Napisz do prowadzącego.</p><?php endif; ?>
    <?php if ($x['period_to']): ?><p class="m-note">Do <?= h(ClaudeBilling::dayMonth($x['period_to'])) ?> · sprawa <?= h($x['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Moje sprawy (AM11–24, ANT1) ── */ ?>
<section id="sprawy" class="m-tile" aria-labelledby="h-ops">
  <h2 id="h-ops" class="m-head">Moje sprawy</h2>
  <?php if (!$ops): ?><p class="tw-text-sm m-muted">Brak spraw licencyjnych.</p><?php endif; ?>
  <?php foreach ($ops as $o): ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
    <span class="tw-font-semibold"><?= h($C::OP_NAMES[$o['form']] ?? 'Sprawa licencyjna') ?></span>
    <?= $tag($o['status'] === 'executed' ? 'ok' : (in_array($o['status'], ['rejected', 'cancelled'], true) ? 'off' : 'due'), $C::OP_STATUS[$o['status']] ?? $o['status']) ?>
    <span class="m-note">złożona <?= h(ClaudeBilling::dayMonth(substr((string)$o['created_at'], 0, 10))) ?> · nr <?= h($o['number']) ?></span>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Produkty i usługi (refakturowane przez FEER) — liczone osobno ── */
$pchip = ['active' => ['działa', 'ok'], 'expiring' => ['kończy się wkrótce', 'due'], 'suspended' => ['zawieszona', 'bad']]; ?>
<section id="produkty" class="m-tile" aria-labelledby="h-prod">
  <h2 id="h-prod" class="m-head">Produkty i usługi</h2>
  <p class="tw-text-sm m-muted tw-mb-3">Refakturowane przez Fundację, rozliczane osobno od subskrypcji.</p>
  <?php if ($products): ?><div class="m-box tw-mb-3" style="max-width:320px"><div class="m-kv"><dt>Razem miesięcznie za usługi</dt></div><div class="m-num"><?= h(ClaudeBilling::zl($prodMonthly)) ?></div></div><?php endif; ?>
  <?php if (!$products && $feerUpn === ''): ?><p class="tw-text-sm m-muted">Nie masz usług od Fundacji. Potrzebujesz domeny albo hostingu do projektu? Napisz do prowadzącego.</p><?php endif; ?>
  <?php foreach ($products as $p): $t = TiProducts::TYPES[$p['product_type']] ?? TiProducts::TYPES['other']; $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p); $amt = (float)($p['monthly_amount'] ?? 0); ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold tw-break-all<?= $p['product_type'] === 'domain' ? ' m-mono' : '' ?>"><?= h($p['name']) ?></span>
      <span class="tw-text-sm m-muted"><?= h($t['label']) ?> · <?= h(TiProducts::providerName($p)) ?></span>
      <?= $tag($pchip[$p['status']][1] ?? 'off', $pchip[$p['status']][0] ?? $p['status']) ?>
    </div>
    <p class="tw-text-sm tw-mt-1">
      <?php if ($p['expires_at']): ?>Opłacona do <b><?= h(ClaudeBilling::dayMonth((string)$p['expires_at'])) ?></b><?= $hint === 'overdue' ? ' — termin minął, napisz do biura, jeśli chcesz ją przedłużyć' : ($hint === 'soon' ? ' — zostało ' . (int)$dl . ($dl === 1 ? ' dzień' : ' dni') : '') ?>.<?php else: ?>Bez daty końca.<?php endif; ?>
      <?php if ($amt > 0): ?> Płacisz co miesiąc <b><?= h(ClaudeBilling::zl((float)$p['_after'])) ?></b><?= $p['_after'] < $amt ? ' (zamiast ' . h(ClaudeBilling::zl($amt)) . ($p['discount_note'] !== '' ? ' — ' . h($p['discount_note']) : '') . ')' : '' ?><?= !empty($p['billing_day']) ? ', faktura ok. ' . (int)$p['billing_day'] . '. dnia miesiąca' : '' ?>.<?php endif; ?>
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

<?php /* ── Moje konto ── */ ?>
<section id="konto" class="m-tile" aria-labelledby="h-konto">
  <h2 id="h-konto" class="m-head">Moje konto w panelu licencji</h2>
  <?php if ($login): ?>
    <p class="tw-mb-1">Login: <span class="tw-text-xl m-mono tw-font-bold"><?= h($login) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($login) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
    <p class="tw-text-sm m-muted tw-mb-3">Tym loginem logujesz się do panelu licencji. Ten sam login (z dopiskiem @<?= h(ClaudeBilling::EDU_DOMAIN) ?>) dostają Twoje konta w Office i Claude — bez imienia i nazwiska w adresie.</p>
    <details<?= !$hasPassword ? ' open' : '' ?>><summary><?= $hasPassword ? 'Zmień hasło' : 'Ustaw hasło do panelu licencji' ?></summary>
      <form method="post" action="<?= h(route('lk.password')) ?>" class="tw-mt-3 tw-grid sm:tw-grid-cols-2 tw-gap-3" style="max-width:640px"><?= Router::csrfField() ?>
        <div><label class="m-label" for="pw1">Nowe hasło (min. 10 znaków)</label><input class="m-input" type="password" id="pw1" name="new" autocomplete="new-password" minlength="10"></div>
        <div><label class="m-label" for="pw2">Powtórz hasło</label><input class="m-input" type="password" id="pw2" name="new2" autocomplete="new-password" minlength="10"></div>
        <div><button class="m-btn" type="submit">Zapisz hasło</button> <span class="m-note">potwierdzisz kodem z SMS-a</span></div>
      </form>
    </details>
  <?php else: ?>
    <p class="tw-text-sm">Nie masz jeszcze własnego loginu do panelu licencji (litera l i 8 cyfr). Zakłada go biuro — poproś prowadzącego. Do tego czasu wchodzisz tu z panelu uczestnika TI.</p>
  <?php endif; ?>
  <div class="m-row tw-mt-4">
    <h3 class="m-sect">Dane kontaktowe</h3>
    <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
      <div><dt>E-mail</dt><dd><?= $contact['email'] !== '' ? h($contact['email']) : '—' ?></dd></div>
      <div><dt>Telefon (kody SMS)</dt><dd><?= $contact['phone'] !== '' ? h($contact['phone']) : '—' ?></dd></div>
    </dl>
    <a class="m-btn-ghost" href="<?= h(route('lk.contact')) ?>">Zmień e-mail lub telefon</a>
  </div>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
