<?php
/**
 * modules/ti_licencje_kursant/view/index.php — „Moje licencje” (kursant.feer.org.pl/licencje/), szata portalu wniosków (Metro: płaskie kafle, akcent cyan,
 * Tailwind CDN z prefiksem tw-). Wszystko prostym językiem: co masz, do kiedy, ile płacisz, co zrobić.
 * Zmienne: $me, $paid, $software, $termOk, $office, $am5, $ops, $login, $minor, $flash, $err, $old, $org.
 */
$C = LicencjeKursantController::class;
$e = fn(string $p, string $k) => isset($err[$p][$k]) ? '<p id="e-' . h($p . $k) . '" class="m-err">' . h($err[$p][$k]) . '</p>' : '';
$a = fn(string $p, string $k) => isset($err[$p][$k]) ? ' aria-invalid="true" aria-describedby="e-' . h($p . $k) . '"' : '';
$today = date('Y-m-d');
$nPay = 0; $sumPay = 0.0;
foreach ($paid as $v) foreach ($v['charges'] as $c) if ($c['status'] === 'pending') { $nPay++; $sumPay += (float)$c['amount_pln']; }
$nLic = count($software) + count(array_filter($office, fn($o) => $o['request_status'] === 'granted')) + count(array_filter($am5, fn($x) => $x['status'] === 'accepted'));
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1d1d1d">
<title>Moje licencje — <?= h($org) ?></title>
<script src="https://cdn.tailwindcss.com/3.4.5"></script>
<script>tailwind.config = { prefix: 'tw-', corePlugins: { preflight: true }, theme: { borderRadius: { none: '0', DEFAULT: '0' }, extend: {
  colors: { m: { cyan: '#1ba1e2', cobalt: '#0050ef', green: '#008a00', red: '#a20025', amber: '#f0a30a', orange: '#fa6800', steel: '#647687', dark: '#1d1d1d', line: '#d6d6d6', bg: '#f4f4f4', nord: '#4687ff', claude: '#da532c' } },
  fontFamily: { metro: ['"Segoe UI"', '"Segoe UI Web"', '"Open Sans"', 'Ubuntu', 'system-ui', 'sans-serif'] } } } }</script>
<style type="text/tailwindcss">
  body { @apply tw-font-metro tw-bg-m-bg tw-text-m-dark; }
  .m-tile   { @apply tw-bg-white tw-border tw-border-solid tw-border-m-line tw-p-5 sm:tw-p-6 tw-mb-4; }
  .m-head   { @apply tw-text-xl tw-font-light tw-mb-3 tw-text-m-dark; }
  .m-label  { @apply tw-block tw-text-sm tw-font-semibold tw-mb-1; }
  .m-input  { @apply tw-w-full tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-px-3 tw-py-2 focus:tw-outline-none focus:tw-border-m-cyan; }
  .m-input[aria-invalid="true"] { @apply tw-border-m-red; }
  .m-err    { @apply tw-text-sm tw-text-m-red tw-mt-1 tw-font-semibold; }
  .m-btn    { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-bg-m-cyan tw-text-white tw-font-semibold tw-px-5 tw-py-3 tw-border-0 tw-no-underline hover:tw-bg-m-cobalt focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; min-height: 44px; }
  .m-btn-ghost { @apply tw-inline-flex tw-items-center tw-gap-2 tw-bg-white tw-text-m-dark tw-font-semibold tw-px-4 tw-py-2 tw-border-2 tw-border-solid tw-border-m-dark tw-no-underline hover:tw-bg-m-bg focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; min-height: 44px; }
  .m-check  { @apply tw-flex tw-gap-3 tw-items-start tw-p-3 tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-cursor-pointer; }
  .m-check input { @apply tw-mt-1 tw-h-5 tw-w-5 tw-shrink-0 tw-accent-m-cyan; }
  .m-chip   { @apply tw-inline-block tw-text-white tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold tw-align-middle; }
  .m-kv dt  { @apply tw-text-xs tw-uppercase tw-tracking-wide tw-text-m-steel; }
  .m-kv dd  { @apply tw-mb-3 tw-font-semibold; }
  .m-row    { @apply tw-border-t tw-border-solid tw-border-m-line tw-py-3; }
  summary   { @apply tw-cursor-pointer tw-font-semibold; min-height: 32px; }
  a { @apply tw-text-m-cobalt tw-underline; }
  .skip { @apply tw-sr-only focus:tw-not-sr-only focus:tw-absolute focus:tw-p-3 focus:tw-bg-m-amber focus:tw-text-m-dark; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="tw-bg-m-dark tw-text-white">
  <div class="tw-max-w-4xl tw-mx-auto tw-flex tw-items-stretch">
    <div class="tw-bg-m-cyan tw-px-4 tw-py-3 tw-text-2xl tw-font-semibold" aria-hidden="true">🔑</div>
    <div class="tw-px-4 tw-py-2 tw-flex-1">
      <div class="tw-text-xs tw-uppercase tw-tracking-widest tw-opacity-80"><?= h($org) ?></div>
      <div class="tw-text-lg tw-font-light">Moje licencje</div>
    </div>
    <a href="../index.php" class="tw-self-center tw-px-4 tw-text-white tw-text-sm">← Panel uczestnika</a>
  </div>
</header>
<main id="tresc" class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-py-6">
<h1 class="tw-text-3xl tw-font-light tw-mb-1">Moje licencje</h1>
<p class="tw-text-m-steel tw-mb-5"><?= h($me['name']) ?> — wszystkie programy i dostępy w jednym miejscu.</p>

<?php if ($flash): ?>
<div class="<?= $flash[0] === 'ok' ? 'tw-bg-m-green' : ($flash[0] === 'info' ? 'tw-bg-m-cobalt' : 'tw-bg-m-red') ?> tw-text-white tw-p-4 tw-mb-4" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>" tabindex="-1" id="komunikat"><?= h($flash[1]) ?></div>
<script>document.getElementById('komunikat').focus();</script>
<?php endif; ?>

<section class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2 tw-mb-5" aria-label="Podsumowanie">
  <div class="tw-bg-m-cyan tw-text-white tw-p-4"><div class="tw-text-3xl tw-font-light"><?= $nLic + count(array_filter($paid, fn($v) => $v['sub'] && $v['sub']['status'] === 'active')) ?></div><div class="tw-text-xs tw-uppercase">aktywne licencje</div></div>
  <div class="<?= $nPay ? 'tw-bg-m-amber tw-text-m-dark' : 'tw-bg-m-steel tw-text-white' ?> tw-p-4"><div class="tw-text-3xl tw-font-light"><?= $nPay ? h(ClaudeBilling::zl($sumPay)) : '0 zł' ?></div><div class="tw-text-xs tw-uppercase">do zapłaty<?= $nPay ? ' (' . $nPay . ')' : '' ?></div></div>
  <a href="#login" class="tw-bg-m-dark tw-text-white tw-p-4 tw-no-underline tw-col-span-2"><div class="tw-text-xl tw-font-light tw-font-mono"><?= $login ? h($login) : 'brak' ?></div><div class="tw-text-xs tw-uppercase">mój login do licencji</div></a>
</section>

<?php /* ── Płatne licencje: Claude, NordVPN ── */ foreach ($paid as $pk => $v): $P = $v['P']; $s = $v['settings']; $sub = $v['sub'];
  $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
  $buyer = array_merge($open ? array_intersect_key($sub, ClaudeBilling::BUYER_FIELDS) : ClaudeBilling::buyerDefaults((int)$me['cid']), array_intersect_key($old[$pk] ?? [], ClaudeBilling::BUYER_FIELDS));
  $state = !$sub || $sub['status'] === 'ended' ? ['możesz włączyć', 'tw-bg-m-steel'] : ($sub['status'] === 'active' ? ['włączony', 'tw-bg-m-green'] : ['wstrzymany — brak wpłaty', 'tw-bg-m-red']);
  if (!$open && !$v['eligible']['ok']) $state = ['niedostępny', 'tw-bg-m-steel'];
  $priceTxt = $s['priced'] ? 'Co miesiąc ' . ClaudeBilling::usdWords($s['usd']) . ($v['rate'] ? ' (dziś ok. ' . ClaudeBilling::zl((float)$v['rate']['amount_pln']) . ')' : '')
              . ($s['list_usd'] > $s['usd'] ? ' — cena dla organizacji non-profit, normalnie ' . ClaudeBilling::usdWords($s['list_usd']) . '.' : '.') : '';
?>
<section id="<?= h($pk) ?>" class="m-tile" style="border-left:6px solid <?= h($P['color']) ?>" aria-labelledby="h-<?= h($pk) ?>">
  <h2 id="h-<?= h($pk) ?>" class="m-head"><?= h($P['label']) ?> <span class="m-chip <?= $state[1] ?>"><?= h($state[0]) ?></span></h2>
  <p class="tw-text-sm tw-mb-3"><?= h(ucfirst($P['what'])) ?>. <?= h($priceTxt) ?></p>

  <?php if ($open): ?>
    <dl class="m-kv tw-grid sm:tw-grid-cols-3 tw-gap-x-6">
      <div><dt>Włączony od</dt><dd><?= h(ClaudeBilling::dayMonth($sub['activated_on'])) ?></dd></div>
      <div><dt>Opłacony do</dt><dd><?= $v['paidThrough'] ? h(ClaudeBilling::dayMonth($v['paidThrough'])) : 'jeszcze nic' ?></dd></div>
      <div><dt>Faktura co miesiąc</dt><dd>ok. <?= ClaudeBilling::dueDay($sub, $s) ?>. dnia, płatna w <?= ClaudeBilling::TERM_DAYS ?> dni</dd></div>
    </dl>
    <?php if ($sub['status'] === 'suspended'): ?><p class="tw-bg-m-red tw-text-white tw-p-3 tw-mb-3" role="alert">Dostęp jest wstrzymany, bo nie dotarła wpłata. Zapłać zaległą fakturę poniżej — włączymy go z powrotem sami, nie musisz nic zgłaszać.</p><?php endif; ?>
    <?php if ($v['gaps']): ?><p class="tw-bg-m-amber tw-text-m-dark tw-p-3 tw-mb-3" role="alert"><b>Brakuje danych do faktury:</b> <?= h(implode(', ', $v['gaps'])) ?>. Uzupełnij je niżej — bez nich nie wystawimy faktury.</p><?php endif; ?>

    <?php foreach ($v['charges'] as $c): if ($c['status'] === 'cancelled') continue; $late = $c['status'] === 'pending' && $c['due_on'] < $today; ?>
    <div class="m-row">
      <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
        <span class="tw-font-semibold"><?= h(ucfirst(ClaudeBilling::periodLabel((string)$c['period']))) ?></span>
        <span class="tw-text-lg"><?= h(ClaudeBilling::zl((float)$c['amount_pln'])) ?></span>
        <?php if ($c['status'] === 'paid'): ?><span class="m-chip tw-bg-m-green">zapłacone <?= h(ClaudeBilling::dayMonth(substr((string)$c['paid_at'], 0, 10))) ?></span>
        <?php else: ?><span class="m-chip <?= $late ? 'tw-bg-m-red' : 'tw-bg-m-amber' ?>"><?= $late ? 'po terminie — było do ' : 'zapłać do ' ?><?= h(ClaudeBilling::dayMonth($c['due_on'])) ?></span><?php endif; ?>
        <?php if ($c['invoice_view_url']): ?><a href="<?= h($c['invoice_view_url']) ?>" target="_blank" rel="noopener" class="tw-text-sm">faktura <?= h($c['invoice_number']) ?><span class="tw-sr-only"> (otwiera się w nowej karcie)</span></a><?php endif; ?>
      </div>
      <p class="tw-text-xs tw-text-m-steel tw-mt-1"><?= h(ClaudeBilling::usdWords((float)$c['usd'])) ?> × <?= h(ClaudeBilling::rateFmt((float)$c['rate'])) ?> (kurs PKO BP z <?= h(ClaudeBilling::dayMonth(substr((string)$c['rate_at'], 0, 10))) ?>)</p>
      <?php if ($c['status'] === 'pending'): ?>
      <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2">
        <?php if ($s['pay_redirect'] === 'portal'): ?>
          <a class="m-btn" href="../platnosci.php">Zapłać<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></a>
        <?php elseif ($s['pay_redirect'] === 'fakturownia' && $c['invoice_view_url']): ?>
          <a class="m-btn" href="<?= h($c['invoice_view_url']) ?>" target="_blank" rel="noopener">Zapłać (BLIK / szybki przelew)<span class="tw-sr-only"> — faktura w nowej karcie</span></a>
        <?php else: ?>
          <form method="post" action="<?= h(route('lk.pay', ['id' => (int)$c['id']])) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Zapłać przez Przelewy24<span class="tw-sr-only"> za <?= h(ClaudeBilling::periodLabel((string)$c['period'])) ?></span></button></form>
        <?php endif; ?>
        <?php if ($c['invoice_view_url'] && $s['pay_redirect'] !== 'fakturownia'): ?><a class="m-btn-ghost" href="<?= h($c['invoice_view_url']) ?>" target="_blank" rel="noopener">BLIK / szybki przelew z faktury<span class="tw-sr-only"> (nowa karta)</span></a><?php endif; ?>
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
        <p class="tw-text-sm tw-mb-2">Nie dostaniesz kolejnych faktur. Dostęp zostanie wyłączony<?= $v['paidThrough'] ? ' po ' . h(ClaudeBilling::dayMonth($v['paidThrough'])) : '' ?>. Jeśli masz nieopłaconą fakturę, trzeba ją jeszcze zapłacić.</p>
        <label class="m-check"><input type="checkbox" name="confirm" value="1" required><span>Tak, rezygnuję z <?= h($P['label']) ?>.</span></label>
        <button class="m-btn-ghost tw-mt-3" type="submit">Zrezygnuj</button>
      </form>
    </details>

  <?php elseif (!$v['eligible']['ok']): ?>
    <p class="tw-text-sm"><?= h($v['eligible']['reason']) ?></p>
  <?php else: ?>
    <form method="post" action="<?= h(route('lk.activate', ['product' => $pk])) ?>" novalidate><?= Router::csrfField() ?>
      <h3 class="tw-font-semibold tw-mb-1">Włącz <?= h($P['label']) ?></h3>
      <p class="tw-text-sm tw-mb-3">Podaj dane do faktury — przy każdej opłacie dostaniesz fakturę e-mailem. Pola z gwiazdką są wymagane.</p>
      <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
        <?php foreach (ClaudeBilling::BUYER_FIELDS as $k => $l): ?>
        <div><label class="m-label" for="<?= h($pk . $k) ?>"><?= h(ucfirst($l)) ?><?= $k !== 'buyer_tax_no' ? ' *' : '' ?></label><input class="m-input" id="<?= h($pk . $k) ?>" name="<?= h($k) ?>" value="<?= h((string)($buyer[$k] ?? '')) ?>"<?= $a($pk, $k) ?>><?= $e($pk, $k) ?></div>
        <?php endforeach; ?>
      </div>
      <label class="m-check tw-mt-4"><input type="checkbox" name="accept" value="1"<?= !empty($old[$pk]['accept']) ? ' checked' : '' ?><?= $a($pk, 'accept') ?>><span class="tw-text-sm"><?= h(ClaudeBilling::termsText($s)) ?></span></label><?= $e($pk, 'accept') ?>
      <button class="m-btn tw-mt-4" type="submit">Włącz <?= h($P['label']) ?> — akceptuję zasady</button>
      <p class="tw-text-xs tw-text-m-steel tw-mt-2">Po włączeniu Dział IT dostaje zgłoszenie i uruchamia Twoje konto<?= $login ? ' (login: ' . h($login) . ')' : '' ?>. Pierwsza faktura pojawi się tu od razu.</p>
    </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php /* ── Licencje na Office (AM7) ── */ if ($office): ?>
<section class="m-tile" style="border-left:6px solid #0050ef" aria-labelledby="h-office">
  <h2 id="h-office" class="m-head">Licencje na Office</h2>
  <?php foreach ($office as $o): $g = $o['request_status'] === 'granted'; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($o['title'] ?: 'Microsoft 365 / Office') ?></span>
      <span class="m-chip <?= $g ? 'tw-bg-m-green' : ($o['request_status'] === 'refused' ? 'tw-bg-m-red' : 'tw-bg-m-amber') ?>"><?= h($C::AM7_STATUS[$o['request_status']] ?? $o['request_status']) ?></span>
    </div>
    <?php if ($g && $o['tenant_upn']): ?>
      <p class="tw-text-sm tw-mt-1">Twój login do Office: <span class="tw-font-mono tw-font-semibold"><?= h($o['tenant_upn']) ?></span>
        · <a href="https://www.office.com/?login_hint=<?= h(rawurlencode((string)$o['tenant_upn'])) ?>" target="_blank" rel="noopener">otwórz Office<span class="tw-sr-only"> (nowa karta)</span></a></p>
      <p class="tw-text-xs tw-text-m-steel">Hasło startowe przyszło SMS-em. Przy pierwszym logowaniu Microsoft poprosi o nowe.</p>
    <?php elseif ($g): ?><p class="tw-text-sm tw-mt-1">Licencja przyznana — Dział IT zakłada Twoje konto<?= $login ? ' (login ' . h($login) . ')' : '' ?>. Dane logowania przyjdą e-mailem i SMS-em.</p><?php endif; ?>
    <?php if ($o['period_to']): ?><p class="tw-text-xs tw-text-m-steel">Ważna<?= $o['period_from'] ? ' od ' . h(ClaudeBilling::dayMonth($o['period_from'])) : '' ?> do <?= h(ClaudeBilling::dayMonth($o['period_to'])) ?> · sprawa <?= h($o['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Licencje dydaktyczne (AM5) ── */ if ($am5): ?>
<section class="m-tile" style="border-left:6px solid #1ba1e2" aria-labelledby="h-am5">
  <h2 id="h-am5" class="m-head">Materiały i licencje dydaktyczne</h2>
  <?php foreach ($am5 as $x): ?>
  <div class="m-row">
    <span class="tw-font-semibold"><?= h($x['title'] ?: 'Licencja dydaktyczna') ?></span>
    <span class="m-chip <?= $x['status'] === 'accepted' ? 'tw-bg-m-green' : (in_array($x['status'], ['rejected', 'revoked'], true) ? 'tw-bg-m-red' : 'tw-bg-m-amber') ?>"><?= h($C::AM5_STATUS[$x['status']] ?? $x['status']) ?></span>
    <?php if ($x['status'] === 'invited' && $x['due_on']): ?><p class="tw-text-sm tw-mt-1">Odeślij oświadczenie do <?= h(ClaudeBilling::dayMonth($x['due_on'])) ?>. Nie masz linku? Napisz do prowadzącego.</p><?php endif; ?>
    <?php if ($x['period_to']): ?><p class="tw-text-xs tw-text-m-steel">Do <?= h(ClaudeBilling::dayMonth($x['period_to'])) ?> · sprawa <?= h($x['case_number']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ── Programy i klucze (moduł Licencje) ── */ if ($software): ?>
<section class="m-tile" aria-labelledby="h-soft">
  <h2 id="h-soft" class="m-head">Programy i klucze</h2>
  <?php if (!$termOk): ?><p class="tw-bg-m-amber tw-text-m-dark tw-p-3 tw-mb-3">Loginy i klucze zobaczysz po zaakceptowaniu oświadczenia o poufności — <a href="../index.php?tab=regulaminy">przejdź do Regulaminów</a>.</p><?php endif; ?>
  <?php foreach ($software as $l): $exp = !empty($l['expires_at']) && $l['expires_at'] < $today; ?>
  <div class="m-row">
    <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
      <span class="tw-font-semibold"><?= h($l['license_name']) ?></span><span class="tw-text-sm tw-text-m-steel"><?= h($l['vendor']) ?></span>
      <span class="m-chip <?= $exp ? 'tw-bg-m-red' : 'tw-bg-m-green' ?>"><?= $exp ? 'wygasła' : (!empty($l['expires_at']) ? 'ważna do ' . h(ClaudeBilling::dayMonth($l['expires_at'])) : 'bezterminowo') ?></span>
    </div>
    <?php if ($termOk && ($l['login'] !== '' || $l['access_key'] !== '')): ?>
    <dl class="tw-text-sm tw-mt-2 tw-grid tw-grid-cols-[8rem_1fr] tw-gap-y-1">
      <?php if ($l['login'] !== ''): ?><dt class="tw-text-m-steel">Login</dt><dd><span class="tw-font-mono"><?= h($l['login']) ?></span> <button type="button" class="m-btn-ghost tw-py-0 lk-copy" data-copy="<?= h($l['login']) ?>">Kopiuj<span class="tw-sr-only"> login do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
      <?php if ($l['access_key'] !== ''): ?><dt class="tw-text-m-steel">Klucz / hasło</dt><dd><span class="tw-font-mono lk-secret" data-secret="<?= h($l['access_key']) ?>">••••••••</span> <button type="button" class="m-btn-ghost tw-py-0 lk-reveal" aria-pressed="false">Pokaż<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button> <button type="button" class="m-btn-ghost tw-py-0 lk-copy" data-copy="<?= h($l['access_key']) ?>">Kopiuj<span class="tw-sr-only"> klucz do <?= h($l['license_name']) ?></span></button></dd><?php endif; ?>
    </dl>
    <?php endif; ?>
    <?php if (!empty($l['notes'])): ?><p class="tw-text-sm tw-text-m-steel tw-mt-1"><?= h($l['notes']) ?></p><?php endif; ?>
    <?php if (!empty($l['vendor_url'])): ?><p class="tw-mt-1"><a href="<?= h($l['vendor_url']) ?>" target="_blank" rel="noopener">Otwórz / zaloguj się<span class="tw-sr-only"> do <?= h($l['license_name']) ?> (nowa karta)</span></a></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="tw-sr-only" id="lk-live" aria-live="polite"></div>
</section>
<?php endif; ?>

<?php /* ── Sprawy licencyjne ── */ if ($ops): ?>
<section class="m-tile" aria-labelledby="h-ops">
  <h2 id="h-ops" class="m-head">Moje sprawy</h2>
  <?php foreach ($ops as $o): ?>
  <div class="m-row tw-flex tw-flex-wrap tw-items-baseline tw-gap-x-3">
    <span class="tw-font-semibold"><?= h($C::OP_NAMES[$o['form']] ?? 'Sprawa licencyjna') ?></span>
    <span class="m-chip <?= $o['status'] === 'executed' ? 'tw-bg-m-green' : (in_array($o['status'], ['rejected', 'cancelled'], true) ? 'tw-bg-m-steel' : 'tw-bg-m-amber') ?>"><?= h($C::OP_STATUS[$o['status']] ?? $o['status']) ?></span>
    <span class="tw-text-xs tw-text-m-steel">złożona <?= h(ClaudeBilling::dayMonth(substr((string)$o['created_at'], 0, 10))) ?> · nr <?= h($o['number']) ?></span>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section id="login" class="m-tile" aria-labelledby="h-login">
  <h2 id="h-login" class="m-head">Mój login do licencji</h2>
  <?php if ($login): ?>
    <p class="tw-text-2xl tw-font-mono tw-mb-1"><?= h($login) ?> <button type="button" class="m-btn-ghost tw-py-0 tw-text-base lk-copy" data-copy="<?= h($login) ?>">Kopiuj<span class="tw-sr-only"> login</span></button></p>
    <p class="tw-text-sm tw-text-m-steel">Ten login nie zmienia się. Używamy go do kont w Office, Claude i NordVPN — zamiast Twojego imienia i nazwiska.</p>
  <?php else: ?>
    <p class="tw-text-sm tw-mb-3">Możesz wygenerować login w formacie <b>l + 8 cyfr</b> (np. l40381927). Użyjemy go do kont w Office, Claude i NordVPN zamiast Twojego imienia i nazwiska — tak, żeby z loginu nie dało się poznać, kim jesteś. Generuje się raz i potem już się nie zmienia.</p>
    <form method="post" action="<?= h(route('lk.login')) ?>"><?= Router::csrfField() ?><button class="m-btn" type="submit">Wygeneruj mój login</button></form>
  <?php endif; ?>
</section>

<?php if (!$paid && !$office && !$am5 && !$software && !$ops): ?>
<section class="m-tile"><p>Nie masz jeszcze żadnych licencji. Jeśli potrzebujesz programu do nauki (np. Office, Claude, NordVPN), napisz do prowadzącego.</p></section>
<?php endif; ?>
</main>
<footer class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-pb-8 tw-text-xs tw-text-m-steel"><?= h($org) ?> · Moje licencje · pytania: napisz do prowadzącego w panelu</footer>
<script>
(function () {
  var live = document.getElementById('lk-live');
  document.querySelectorAll('.lk-copy').forEach(function (b) {
    b.addEventListener('click', function () { navigator.clipboard.writeText(b.getAttribute('data-copy')); if (live) live.textContent = 'Skopiowano.'; b.firstChild.textContent = 'Skopiowano'; });
  });
  document.querySelectorAll('.lk-reveal').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = b.parentElement.querySelector('.lk-secret'), show = s.dataset.shown !== '1';
      s.textContent = show ? s.getAttribute('data-secret') : '••••••••'; s.dataset.shown = show ? '1' : '0';
      b.firstChild.textContent = show ? 'Ukryj' : 'Pokaż'; b.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
})();
</script>
</body>
</html>
