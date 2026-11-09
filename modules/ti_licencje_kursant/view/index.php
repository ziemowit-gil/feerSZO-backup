<?php
if (!isset($me, $paid)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/index.php — panel licencji (jeden panel jak Portal Kontrahenta): menu po lewej, sekcje na jednej stronie.
 * Sekcje: Subskrypcje (Claude, NordVPN) · Licencje na Office · Programy i klucze · Materiały dydaktyczne · Moje sprawy · Produkty i usługi
 * (refakturowane przez FEER — liczone osobno) · Moje konto. Prostym językiem: co masz, do kiedy, ile płacisz, co zrobić.
 * Zmienne: $me, $paid, $software, $termOk, $office, $am5, $ops, $products, $prodMonthly, $feerUpn, $p24on, $login, $hasPassword, $minor, $flash, $err, $old, $org.
 */
$C = LicencjeKursantController::class;
$e = fn(string $p, string $k) => isset($err[$p][$k]) ? '<p id="e-' . h($p . $k) . '" class="m-err">' . h($err[$p][$k]) . '</p>' : '';
$a = fn(string $p, string $k) => isset($err[$p][$k]) ? ' aria-invalid="true" aria-describedby="e-' . h($p . $k) . '"' : '';
$today = date('Y-m-d');
$nPay = 0; $sumPay = 0.0;
foreach ($paid as $v) foreach ($v['charges'] as $c) if ($c['status'] === 'pending') { $nPay++; $sumPay += (float)$c['amount_pln']; }
$PAGE_TITLE = 'Moje licencje';
$nav = [['subskrypcje', 'Subskrypcje', $nPay ? 'do zapłaty ' . ClaudeBilling::zl($sumPay) : (string)count($paid), $nPay > 0],
        ['office', 'Licencje na Office', $office ? (string)count($office) : '', false],
        ['programy', 'Programy i klucze', $software ? (string)count($software) : '', false],
        ['dydaktyczne', 'Materiały dydaktyczne', $am5 ? (string)count($am5) : '', false],
        ['sprawy', 'Moje sprawy', $ops ? (string)count($ops) : '', false],
        ['produkty', 'Produkty i usługi', $prodMonthly > 0 ? ClaudeBilling::zl($prodMonthly) . '/mies.' : ($products ? (string)count($products) : ''), false],
        ['konto', 'Moje konto', '', false]];
include __DIR__ . '/_top.php';
$chipCls = ['ok' => 'tw-bg-m-green', 'due' => 'tw-bg-m-amber tw-text-m-dark', 'bad' => 'tw-bg-m-red', 'off' => 'tw-bg-m-steel'];
?>
<h1 class="tw-text-2xl tw-font-bold tw-mb-1">Moje licencje</h1>
<p class="tw-text-m-steel tw-mb-5"><?= h($me['name']) ?> — wszystkie licencje, subskrypcje i usługi od Fundacji w jednym miejscu.</p>

<?php /* ── Subskrypcje: Claude, NordVPN ── */ ?>
<section id="subskrypcje" class="tw-mb-2" aria-labelledby="h-sub">
  <h2 id="h-sub" class="tw-text-xl tw-font-bold tw-mb-3">Subskrypcje <span class="tw-text-sm tw-font-normal tw-text-m-steel">— opłacane co miesiąc: proforma, wpłata, faktura</span></h2>
  <?php if (!$paid): ?><div class="m-tile"><p>Nie masz subskrypcji. Jeśli chcesz korzystać z Claude albo NordVPN, napisz do prowadzącego.</p></div><?php endif; ?>
<?php foreach ($paid as $pk => $v): $P = $v['P']; $s = $v['settings']; $sub = $v['sub'];
  $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
  $buyer = array_merge($open ? array_intersect_key($sub, ClaudeBilling::BUYER_FIELDS) : ClaudeBilling::buyerDefaults((int)$me['cid']), array_intersect_key($old[$pk] ?? [], ClaudeBilling::BUYER_FIELDS));
  $state = !$sub || $sub['status'] === 'ended' ? ['możesz włączyć', 'off'] : ($sub['status'] === 'active' ? ['włączony', 'ok'] : ['wstrzymany — brak wpłaty', 'bad']);
  if (!$open && !$v['eligible']['ok']) $state = [!empty($v['eligible']['needEdu']) ? 'potrzebne konto @edu' : 'niedostępny', 'off'];
  $priceTxt = $s['priced'] ? 'Co miesiąc ' . ClaudeBilling::usdWords($s['usd']) . ' po kursie PKO BP' . ClaudeBilling::extrasWords($s) . ($v['rate'] ? ' — dziś razem ok. ' . ClaudeBilling::zl((float)$v['rate']['amount_pln']) : '')
              . ($s['list_usd'] > $s['usd'] ? '. ' . ClaudeBilling::usdWords($s['usd']) . ' to cena dla organizacji non-profit (normalnie ' . ClaudeBilling::usdWords($s['list_usd']) . ').' : '.') : '';
  $pr = $v['projection'] ?? ['now' => null, 'next' => null];
  $proj = function () use ($pr, $open) { if (!$pr['now'] && !$pr['next']) return; ?>
    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3 tw-mb-4" aria-label="Projekcja płatności">
      <?php foreach (['now' => $open ? 'Ta płatność' : 'Pierwsza płatność (po włączeniu)', 'next' => 'Następna płatność'] as $k2 => $lbl): if (!$pr[$k2]) continue; $x = $pr[$k2]; ?>
      <div class="tw-rounded-lg tw-p-4 <?= $k2 === 'now' ? 'tw-bg-[#fff4d6]' : 'tw-bg-m-bg' ?>" style="border:1px solid var(--pk-border)">
        <div class="tw-text-xs tw-uppercase tw-tracking-wide tw-text-m-steel"><?= h($lbl) ?></div>
        <div class="tw-text-2xl tw-font-bold"><?= $x['estimate'] ? 'ok. ' : '' ?><?= h(ClaudeBilling::zl((float)$x['amount'])) ?></div>
        <div class="tw-text-sm">dokument <?= h(ClaudeBilling::dayMonth($x['issue'])) ?>, zapłać do <?= h(ClaudeBilling::dayMonth($x['due'])) ?></div>
        <?php if ($x['estimate']): ?><div class="tw-text-xs tw-text-m-steel">szacunek po dzisiejszym kursie — dokładna kwota zależy od kursu z dnia dokumentu</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php };
?>
  <section id="<?= h($pk) ?>" class="m-tile" style="border-left:6px solid <?= h($P['color']) ?>" aria-labelledby="h-<?= h($pk) ?>">
    <h3 id="h-<?= h($pk) ?>" class="m-head"><?= h($P['label']) ?> <span class="m-chip <?= $chipCls[$state[1]] ?>"><?= h($state[0]) ?></span></h3>
    <p class="tw-text-sm tw-mb-3"><?= h(ucfirst($P['what'])) ?>. <?= h($priceTxt) ?><?= $v['fromPool'] ? ' Licencję przydzielimy z puli Fundacji przy włączeniu.' : '' ?></p>

    <?php if ($open): ?>
      <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6">
        <div><dt>Włączony od</dt><dd><?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?></dd></div>
        <div><dt>Opłacony do</dt><dd><?= $v['paidThrough'] ? h(ClaudeBilling::dayMonth($v['paidThrough'])) : 'jeszcze nic' ?></dd></div>
        <div><dt>Dokument co miesiąc</dt><dd><?= ClaudeBilling::dueDay($sub, $s) === 1 ? '1. dnia miesiąca' : 'ok. ' . ClaudeBilling::dueDay($sub, $s) . '. dnia' ?>, płatny w <?= ClaudeBilling::TERM_DAYS ?> dni</dd></div>
      </dl>
      <?php $proj(); ?>
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
          <span class="tw-text-lg tw-font-bold"><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></span>
          <?php if ($c['status'] === 'paid'): ?><span class="m-chip tw-bg-m-green">zapłacone <?= h(ClaudeBilling::dayMonth(substr((string)$c['paid_at'], 0, 10))) ?></span>
          <?php else: ?><span class="m-chip <?= $late ? 'tw-bg-m-red' : 'tw-bg-m-amber tw-text-m-dark' ?>"><?= $late ? 'po terminie — było do ' : 'zapłać do ' ?><?= h(ClaudeBilling::dayMonth($c['due_on'])) ?></span><?php endif; ?>
          <?php if ($docUrl !== ''): ?><a href="<?= h($docUrl) ?>" target="_blank" rel="noopener" class="tw-text-sm"><?= $docLbl ?> <?= h($docNo) ?><span class="tw-sr-only"> (nowa karta)</span></a>
          <?php else: ?><span class="tw-text-sm tw-text-m-steel"><?= $c['status'] === 'paid' ? 'faktura' : 'proforma' ?> w przygotowaniu</span><?php endif; ?>
        </div>
        <p class="tw-text-xs tw-text-m-steel tw-mt-1"><?= h(ClaudeBilling::breakdown($c)) ?> (kurs PKO BP z <?= h(ClaudeBilling::dayMonth(substr((string)$c['rate_at'], 0, 10))) ?>)</p>
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
        <?php if ($v['account']): ?><p class="tw-text-sm tw-mt-2">Albo zwykły przelew: konto <span class="tw-font-mono tw-font-semibold"><?= h($v['account']['nrb_fmt']) ?></span>, tytuł <span class="tw-font-semibold"><?= h(ClaudeBilling::transferTitle($c)) ?></span>, kwota <?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?>.</p><?php endif; ?>
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
          <button class="m-btn-ghost tw-mt-3" type="submit">Zrezygnuj</button>
        </form>
      </details>

    <?php elseif (!empty($v['eligible']['needEdu'])): $eduReq = $v['edu']['request'] ?? null; ?>
      <div class="pk-alert pk-alert-info"><?= h($v['eligible']['reason']) ?></div>
      <?php if ($eduReq): ?>
        <p class="tw-text-sm">Poprosiliśmy o konto <b class="tw-font-mono"><?= h($eduReq['proposed']) ?></b> <?= h(ClaudeBilling::dayMonth(substr((string)$eduReq['created_at'], 0, 10))) ?><?= $eduReq['helpdesk_number'] ? ' (zgłoszenie ' . h($eduReq['helpdesk_number']) . ')' : '' ?>. Gdy Dział IT je założy, włączysz Claude tutaj.</p>
      <?php else: ?>
        <p class="tw-text-sm tw-mb-3">Proponujemy konto: <b class="tw-font-mono"><?= h($login ? $login . '@' . ClaudeBilling::EDU_DOMAIN : 'l + 8 cyfr @' . ClaudeBilling::EDU_DOMAIN) ?></b> — bez Twojego imienia i nazwiska w adresie.</p>
        <form method="post" action="<?= h(route('lk.edu')) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Poproś o konto @<?= h(ClaudeBilling::EDU_DOMAIN) ?></button></form>
      <?php endif; ?>
    <?php elseif (!$v['eligible']['ok']): ?>
      <p class="tw-text-sm"><?= h($v['eligible']['reason']) ?></p>
      <?php $proj(); ?>
    <?php else: ?>
      <?php $proj(); ?>
      <form method="post" action="<?= h(route('lk.activate', ['product' => $pk])) ?>" novalidate><?= Router::csrfField() ?>
        <h4 class="tw-font-semibold tw-mb-1">Włącz <?= h($P['label']) ?></h4>
        <p class="tw-text-sm tw-mb-3">Podaj dane do faktury — przy każdej opłacie dostaniesz proformę, a po wpłacie fakturę. Pola z gwiazdką są wymagane.</p>
        <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
          <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
          <div><label class="m-label" for="<?= h($pk . $k) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label><input class="m-input" id="<?= h($pk . $k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] ?? '')) ?>"<?= $a($pk, $k) ?>><?= $e($pk, $k) ?></div>
          <?php endforeach; ?>
        </div>
        <label class="m-check tw-mt-4"><input type="checkbox" name="accept" value="1"<?= !empty($old[$pk]['accept']) ? ' checked' : '' ?><?= $a($pk, 'accept') ?>><span class="tw-text-sm"><?= h(ClaudeBilling::termsText($s)) ?></span></label><?= $e($pk, 'accept') ?>
        <button class="m-btn tw-mt-4" type="submit">Włącz <?= h($P['label']) ?> — akceptuję zasady</button>
        <p class="tw-text-xs tw-text-m-steel tw-mt-2">Po włączeniu Dział IT dostaje zgłoszenie i uruchamia Twoje konto. Pierwsza proforma pojawi się tu od razu.</p>
      </form>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</section>

<?php /* ── Licencje na Office (AM7) ── */ ?>
<section id="office" class="m-tile" aria-labelledby="h-office">
  <h2 id="h-office" class="m-head">Licencje na Office</h2>
  <?php if (!$office): ?><p class="tw-text-sm tw-text-m-steel">Nie masz licencji na Office. Potrzebujesz? Napisz do prowadzącego — złożymy wniosek.</p><?php endif; ?>
  <?php foreach ($office as $o): $g = $o['request_status'] === 'granted'; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($o['title'] ?: 'Microsoft 365 / Office') ?></span>
      <span class="m-chip <?= $g ? 'tw-bg-m-green' : ($o['request_status'] === 'refused' ? 'tw-bg-m-red' : 'tw-bg-m-amber tw-text-m-dark') ?>"><?= h($C::AM7_STATUS[$o['request_status']] ?? $o['request_status']) ?></span>
    </div>
    <?php if ($g && $o['tenant_upn']): ?>
      <p class="tw-text-sm tw-mt-1">Login do Office: <span class="tw-font-mono tw-font-semibold"><?= h($o['tenant_upn']) ?></span> · <a href="https://www.office.com/?login_hint=<?= h(rawurlencode((string)$o['tenant_upn'])) ?>" target="_blank" rel="noopener">otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a></p>
      <p class="tw-text-xs tw-text-m-steel">Hasło startowe przyszło SMS-em. Przy pierwszym logowaniu Microsoft poprosi o nowe.</p>
    <?php elseif ($g): ?><p class="tw-text-sm tw-mt-1">Licencja przyznana — Dział IT zakłada konto<?= $login ? ' (login ' . h($login) . ')' : '' ?>. Dane logowania przyjdą e-mailem i SMS-em.</p><?php endif; ?>
    <?php if ($o['period_to']): ?><p class="tw-text-xs tw-text-m-steel">Ważna<?= $o['period_from'] ? ' od ' . h(ClaudeBilling::dayMonth($o['period_from'])) : '' ?> do <?= h(ClaudeBilling::dayMonth($o['period_to'])) ?> · sprawa <?= h($o['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Programy i klucze (moduł Licencje) ── */ ?>
<section id="programy" class="m-tile" aria-labelledby="h-soft">
  <h2 id="h-soft" class="m-head">Programy i klucze</h2>
  <?php if (!$software): ?><p class="tw-text-sm tw-text-m-steel">Nie masz przypisanych programów.</p><?php endif; ?>
  <?php if ($software && !$termOk): ?><div class="pk-alert pk-alert-info">Loginy i klucze zobaczysz po zaakceptowaniu oświadczenia o poufności w panelu uczestnika (Regulaminy).</div><?php endif; ?>
  <?php foreach ($software as $l): $exp = !empty($l['expires_at']) && $l['expires_at'] < $today; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($l['license_name']) ?></span><span class="tw-text-sm tw-text-m-steel"><?= h($l['vendor']) ?></span>
      <span class="m-chip <?= $exp ? 'tw-bg-m-red' : 'tw-bg-m-green' ?>"><?= $exp ? 'wygasła' : (!empty($l['expires_at']) ? 'ważna do ' . h(ClaudeBilling::dayMonth($l['expires_at'])) : 'bezterminowo') ?></span>
    </div>
    <?php if ($termOk && ($l['login'] !== '' || $l['access_key'] !== '')): ?>
    <dl class="tw-text-sm tw-mt-2 tw-grid tw-grid-cols-[8rem_1fr] tw-gap-y-1">
      <?php if ($l['login'] !== ''): ?><dt class="tw-text-m-steel">Login</dt><dd><span class="tw-font-mono"><?= h($l['login']) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($l['login']) ?>">Kopiuj<span class="tw-sr-only"> login do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
      <?php if ($l['access_key'] !== ''): ?><dt class="tw-text-m-steel">Klucz / hasło</dt><dd><span class="tw-font-mono lk-secret" data-secret="<?= h($l['access_key']) ?>">••••••••</span> <button type="button" class="m-btn-ghost lk-reveal" aria-pressed="false">Pokaż<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($l['access_key']) ?>">Kopiuj<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
    </dl>
    <?php endif; ?>
    <?php if (!empty($l['notes'])): ?><p class="tw-text-sm tw-text-m-steel tw-mt-1"><?= h($l['notes']) ?></p><?php endif; ?>
    <?php if (!empty($l['vendor_url'])): ?><p class="tw-mt-1"><a href="<?= h($l['vendor_url']) ?>" target="_blank" rel="noopener">Otwórz / zaloguj się<span class="tw-sr-only"> do <?= h($l['license_name']) ?> (nowa karta)</span></a></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Materiały dydaktyczne (AM5) ── */ ?>
<section id="dydaktyczne" class="m-tile" aria-labelledby="h-am5">
  <h2 id="h-am5" class="m-head">Materiały dydaktyczne</h2>
  <?php if (!$am5): ?><p class="tw-text-sm tw-text-m-steel">Brak licencji na materiały do zajęć.</p><?php endif; ?>
  <?php foreach ($am5 as $x): ?>
  <div class="m-row">
    <span class="tw-font-semibold"><?= h($x['title'] ?: 'Licencja dydaktyczna') ?></span>
    <span class="m-chip <?= $x['status'] === 'accepted' ? 'tw-bg-m-green' : (in_array($x['status'], ['rejected', 'revoked'], true) ? 'tw-bg-m-red' : 'tw-bg-m-amber tw-text-m-dark') ?>"><?= h($C::AM5_STATUS[$x['status']] ?? $x['status']) ?></span>
    <?php if ($x['status'] === 'invited' && $x['due_on']): ?><p class="tw-text-sm tw-mt-1">Odeślij oświadczenie do <?= h(ClaudeBilling::dayMonth($x['due_on'])) ?>. Nie masz linku? Napisz do prowadzącego.</p><?php endif; ?>
    <?php if ($x['period_to']): ?><p class="tw-text-xs tw-text-m-steel">Do <?= h(ClaudeBilling::dayMonth($x['period_to'])) ?> · sprawa <?= h($x['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Moje sprawy (AM11–24, ANT1) ── */ ?>
<section id="sprawy" class="m-tile" aria-labelledby="h-ops">
  <h2 id="h-ops" class="m-head">Moje sprawy</h2>
  <?php if (!$ops): ?><p class="tw-text-sm tw-text-m-steel">Brak spraw licencyjnych.</p><?php endif; ?>
  <?php foreach ($ops as $o): ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
    <span class="tw-font-semibold"><?= h($C::OP_NAMES[$o['form']] ?? 'Sprawa licencyjna') ?></span>
    <span class="m-chip <?= $o['status'] === 'executed' ? 'tw-bg-m-green' : (in_array($o['status'], ['rejected', 'cancelled'], true) ? 'tw-bg-m-steel' : 'tw-bg-m-amber tw-text-m-dark') ?>"><?= h($C::OP_STATUS[$o['status']] ?? $o['status']) ?></span>
    <span class="tw-text-xs tw-text-m-steel">złożona <?= h(ClaudeBilling::dayMonth(substr((string)$o['created_at'], 0, 10))) ?> · nr <?= h($o['number']) ?></span>
  </div>
  <?php endforeach; ?>
</section>

<?php /* ── Produkty i usługi (refakturowane przez FEER) — liczone osobno ── */
$pchip = ['active' => ['działa', 'tw-bg-m-green'], 'expiring' => ['kończy się wkrótce', 'tw-bg-m-amber tw-text-m-dark'], 'suspended' => ['zawieszona', 'tw-bg-m-red']]; ?>
<section id="produkty" class="m-tile" aria-labelledby="h-prod">
  <h2 id="h-prod" class="m-head">Produkty i usługi <span class="tw-text-sm tw-font-normal tw-text-m-steel">— refakturowane przez Fundację, rozliczane osobno od subskrypcji</span></h2>
  <?php if ($products): ?><p class="tw-mb-3"><span class="tw-text-sm tw-text-m-steel">Razem miesięcznie za usługi:</span> <b class="tw-text-lg"><?= h(ClaudeBilling::zl($prodMonthly)) ?></b></p><?php endif; ?>
  <?php if (!$products && $feerUpn === ''): ?><p class="tw-text-sm tw-text-m-steel">Nie masz usług od Fundacji. Potrzebujesz domeny albo hostingu do projektu? Napisz do prowadzącego.</p><?php endif; ?>
  <?php foreach ($products as $p): $t = TiProducts::TYPES[$p['product_type']] ?? TiProducts::TYPES['other']; $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p); $amt = (float)($p['monthly_amount'] ?? 0); ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold tw-break-all<?= $p['product_type'] === 'domain' ? ' tw-font-mono' : '' ?>"><?= h($p['name']) ?></span>
      <span class="tw-text-sm tw-text-m-steel"><?= h($t['label']) ?> · <?= h(TiProducts::providerName($p)) ?></span>
      <span class="m-chip <?= $pchip[$p['status']][1] ?? 'tw-bg-m-steel' ?>"><?= h($pchip[$p['status']][0] ?? $p['status']) ?></span>
    </div>
    <p class="tw-text-sm tw-mt-1">
      <?php if ($p['expires_at']): ?>Opłacona do <b><?= h(ClaudeBilling::dayMonth((string)$p['expires_at'])) ?></b><?= $hint === 'overdue' ? ' — termin minął, napisz do biura, jeśli chcesz ją przedłużyć' : ($hint === 'soon' ? ' — zostało ' . (int)$dl . ($dl === 1 ? ' dzień' : ' dni') : '') ?>.<?php else: ?>Bez daty końca.<?php endif; ?>
      <?php if ($amt > 0): ?> Płacisz co miesiąc <b><?= h(ClaudeBilling::zl((float)$p['_after'])) ?></b><?= $p['_after'] < $amt ? ' (zamiast ' . h(ClaudeBilling::zl($amt)) . ($p['discount_note'] !== '' ? ' — ' . h($p['discount_note']) : '') . ')' : '' ?><?= !empty($p['billing_day']) ? ', faktura ok. ' . (int)$p['billing_day'] . '. dnia miesiąca' : '' ?>.<?php endif; ?>
    </p>
    <?php if ($p['product_type'] !== 'domain' && trim((string)$p['server_details']) !== ''): ?><p class="tw-text-xs tw-text-m-steel">Pakiet / serwer: <?= h($p['server_details']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($feerUpn !== ''): $hintU = rawurlencode($feerUpn); ?>
  <div class="m-row">
    <span class="tw-font-semibold">Konto Microsoft 365 w Fundacji</span>
    <p class="tw-text-sm tw-mt-1">Login: <span class="tw-font-mono tw-font-semibold"><?= h($feerUpn) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($feerUpn) ?>">Kopiuj<span class="tw-sr-only"> login</span></button>
      · <a href="https://www.office.com/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Office</a> · <a href="https://outlook.office.com/mail/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Poczta</a> · <a href="https://teams.microsoft.com/?login_hint=<?= h($hintU) ?>" target="_blank" rel="noopener">Teams</a></p>
  </div>
  <?php endif; ?>
</section>

<?php /* ── Moje konto ── */ ?>
<section id="konto" class="m-tile" aria-labelledby="h-konto">
  <h2 id="h-konto" class="m-head">Moje konto w panelu licencji</h2>
  <?php if ($login): ?>
    <p class="tw-mb-1">Login: <span class="tw-text-xl tw-font-mono tw-font-bold"><?= h($login) ?></span> <button type="button" class="m-btn-ghost lk-copy" data-copy="<?= h($login) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
    <p class="tw-text-sm tw-text-m-steel tw-mb-3">Tym loginem logujesz się do panelu licencji. Ten sam login (z dopiskiem @<?= h(ClaudeBilling::EDU_DOMAIN) ?>) dostają Twoje konta w Office i Claude — bez imienia i nazwiska w adresie.</p>
    <details<?= !$hasPassword ? ' open' : '' ?>><summary><?= $hasPassword ? 'Zmień hasło' : 'Ustaw hasło do panelu licencji' ?></summary>
      <form method="post" action="<?= h(route('lk.password')) ?>" class="tw-mt-3 tw-grid sm:tw-grid-cols-2 tw-gap-3" style="max-width:640px"><?= Router::csrfField() ?>
        <div><label class="m-label" for="pw1">Nowe hasło (min. 10 znaków)</label><input class="m-input" type="password" id="pw1" name="new" autocomplete="new-password" minlength="10"></div>
        <div><label class="m-label" for="pw2">Powtórz hasło</label><input class="m-input" type="password" id="pw2" name="new2" autocomplete="new-password" minlength="10"></div>
        <div><button class="m-btn" type="submit">Zapisz hasło</button></div>
      </form>
    </details>
  <?php else: ?>
    <p class="tw-text-sm">Nie masz jeszcze własnego loginu do panelu licencji (litera l i 8 cyfr). Zakłada go biuro — poproś prowadzącego. Do tego czasu wchodzisz tu z panelu uczestnika TI.</p>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
