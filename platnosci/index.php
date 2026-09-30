<?php
/**
 * platnosci/index.php — portal płatności SZO (szo.feer.org.pl/platnosci) dla uczestnika.
 *
 * Routing (jeden punkt wejścia):
 *   ?t=TOKEN            — logowanie linkiem od biura (token → sesja „szo_platnosci”, potem czysty adres)
 *   (domyślnie)         — pozycje do zapłaty (koszyk), wybór metody, historia
 *   ?view=status&tx=ID  — status transakcji (powrót z Przelewy24 / po zgłoszeniu przelewu), odświeżany na żywo
 *   ?view=sim&tx=ID     — bramka testowa (tylko gdy P24 nie jest skonfigurowane, a symulacja włączona w adminie)
 *   ?json=status&tx=ID  — stan transakcji (JSON) dla ekranu statusu
 *   POST _op=pay | cancel_nrb | sim_result
 * Logika: modules/payment_portal/logic/paymentPortal.php. Tailwind (CDN) + Alpine.js;
 * klasy przełączane przez :class są w <style type="text/tailwindcss">.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/stripe.php';
require_once dirname(__DIR__) . '/includes/payu.php';
require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';

pp_migrate();
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (isset($_GET['t'])) {
    $u = pp_login_token((string)$_GET['t']);
    header('Location: ' . ($u ? './' : './?err=link')); exit;
}
$pid = pp_current();
$org = (string)org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'SZO');

if (!$pid) {
    http_response_code(isset($_GET['err']) ? 403 : 200); ?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Płatności — <?= h($org) ?></title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-100 text-slate-800 grid place-items-center p-4">
<main class="max-w-md w-full rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
  <h1 class="text-xl font-semibold mb-2">Portal płatności</h1>
  <?php if (isset($_GET['err'])): ?><p role="alert" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Link jest nieważny albo wygasł.</p><?php endif; ?>
  <p class="text-slate-600">Wejście do portalu jest możliwe wyłącznie przez osobisty link otrzymany od biura <?= h($org) ?>. Jeśli go nie masz albo przestał działać, skontaktuj się z biurem.</p>
</main></body></html>
<?php exit; }

$user = pp_user($pid);
$cl   = db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$pid]);

// ── JSON: stan transakcji ───────────────────────────────────────────────────
if (($_GET['json'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $tx = pp_transaction_view((string)($_GET['tx'] ?? ''), $pid);
    echo json_encode($tx ? ['status' => $tx['status'], 'label' => PP_TX_STATUS[$tx['status']] ?? $tx['status'], 'completed_at' => $tx['completed_at']] : ['status' => 'unknown']);
    exit;
}

// ── Operacje ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pp_csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'pay') {
        $tx = pp_create_transaction($pid, (array)($_POST['items'] ?? []), (string)($_POST['method'] ?? ''));
        if (is_string($tx)) { $_SESSION['pp_flash'] = ['danger', $tx]; header('Location: ./'); exit; }
        if ($tx['payment_method'] === 'p24') {
            $go = pp_start_p24($tx, (string)($user['email'] ?? $cl['email'] ?? ''));
            if (is_array($go)) { $_SESSION['pp_flash'] = ['danger', $go['error']]; header('Location: ./'); exit; }
            header('Location: ' . $go); exit;
        }
        header('Location: ./?view=status&tx=' . urlencode($tx['transaction_uuid'])); exit;
    }
    if ($op === 'topup') {   // doładowanie portfela (wpłata ogólna na konto kursanta) przez PayU / Stripe / Przelewy24
        $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $prov = (string)($_POST['provider'] ?? '');
        $back = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST)) . '/platnosci/';   // powrót na ten sam host (sesja portalu)
        if ($amt < 1 || $amt > 20000) { $_SESSION['pp_flash'] = ['danger', 'Podaj kwotę doładowania od 1 do 20 000 zł.']; header('Location: ./'); exit; }
        $desc = 'Doładowanie portfela TI — ' . (string)($cl['name'] ?? '');
        $mail = (string)($user['email'] ?? $cl['email'] ?? '');
        try {
            if ($prov === 'payu' && payu_enabled()) { $o = payu_create_order('k30_ti_wallet', $pid, $amt, $desc, $back . '?wpay=payu', rtrim(APP_URL, '/') . '/api/payu_webhook.php', $mail); audit_log('payments.portal_topup', ['participant_id' => $pid, 'provider' => 'payu', 'amount' => $amt], null); header('Location: ' . $o['url']); exit; }
            if ($prov === 'stripe' && stripe_enabled()) { $o = stripe_create_checkout('k30_ti_wallet', $pid, $amt, $desc, $back . '?wpay=stripe', $back . '?wcancel=1', $mail); audit_log('payments.portal_topup', ['participant_id' => $pid, 'provider' => 'stripe', 'amount' => $amt], null); header('Location: ' . $o['url']); exit; }
            if ($prov === 'p24' && p24_enabled()) { $o = p24_create_order('k30_ti_wallet', $pid, $amt, $desc, $back . '?wpay=p24', rtrim(APP_URL, '/') . '/api/p24_webhook.php', $mail); audit_log('payments.portal_topup', ['participant_id' => $pid, 'provider' => 'p24', 'amount' => $amt], null); header('Location: ' . $o['url']); exit; }
            $_SESSION['pp_flash'] = ['danger', 'Wybrana metoda płatności nie jest teraz dostępna.'];
        } catch (\Throwable $e) { $_SESSION['pp_flash'] = ['danger', 'Nie udało się rozpocząć płatności: ' . $e->getMessage()]; }
        header('Location: ./'); exit;
    }
    if ($op === 'declare') {   // zgłoszenie wpłaty przelewem (do potwierdzenia przez biuro) — jak „Zgłoś wpłatę” w panelu kursanta
        $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        if ($amt < 1 || $amt > 100000) { $_SESSION['pp_flash'] = ['danger', 'Podaj kwotę wpłaty od 1 do 100 000 zł.']; header('Location: ./'); exit; }
        ti_wallet_request_add($pid, $amt, (string)($_POST['note'] ?? ''), 'uczestnik');
        audit_log('payments.portal_declare', ['participant_id' => $pid, 'amount' => $amt], null);
        $_SESSION['pp_flash'] = ['success', 'Zgłoszenie wpłaty ' . number_format($amt, 2, ',', ' ') . ' zł przekazane — biuro zaksięguje ją po potwierdzeniu wpływu.'];
        header('Location: ./'); exit;
    }
    if ($op === 'cancel_nrb') {   // uczestnik rezygnuje ze zgłoszonego, niezaksięgowanego przelewu
        $tx = db_one("SELECT * FROM portal_transactions WHERE transaction_uuid=? AND participant_id=? AND status='pending' AND payment_method='individual_nrb'", [(string)($_POST['tx'] ?? ''), $pid]);
        $err = $tx ? pp_fail((int)$tx['id'], 'Anulowane przez uczestnika przed zaksięgowaniem', 'uczestnik', null) : 'Nie znaleziono oczekującej płatności.';
        $_SESSION['pp_flash'] = $err ? ['danger', $err] : ['success', 'Płatność anulowana — pozycje wróciły do koszyka.'];
        header('Location: ./'); exit;
    }
    if ($op === 'sim_result' && !p24_enabled() && org_setting('pp_p24_simulation') === '1') {
        $tx = db_one("SELECT * FROM portal_transactions WHERE transaction_uuid=? AND participant_id=? AND status='pending' AND payment_method='p24'", [(string)($_POST['tx'] ?? ''), $pid]);
        if ($tx) {
            audit_log('payments.p24_simulated', ['transaction_id' => (int)$tx['id'], 'result' => ($_POST['result'] ?? '') === 'ok' ? 'success' : 'failed'], null);
            ($_POST['result'] ?? '') === 'ok' ? pp_settle((int)$tx['id'], ['gateway' => 'p24-symulacja'], 'symulacja', null)
                                             : pp_fail((int)$tx['id'], 'Odrzucono w bramce testowej', 'symulacja', null);
        }
        header('Location: ./?view=status&tx=' . urlencode((string)($_POST['tx'] ?? ''))); exit;
    }
    header('Location: ./'); exit;
}

// ── Dane widoku ─────────────────────────────────────────────────────────────
pp_expire_stale($pid);
pp_sync_ti_items($pid);
$flash = $_SESSION['pp_flash'] ?? null; unset($_SESSION['pp_flash']);
$view  = (string)($_GET['view'] ?? '');
$items = db_all("SELECT * FROM payable_items WHERE participant_id=? AND status IN ('pending','processing') ORDER BY COALESCE(due_date,'9999-12-31'), id", [$pid]);
$hist  = db_all("SELECT * FROM portal_transactions WHERE participant_id=? ORDER BY id DESC LIMIT 50", [$pid]);
$hist_items = [];
foreach (db_all("SELECT ti.portal_transaction_id, pi.title, ti.amount FROM portal_transaction_items ti JOIN payable_items pi ON pi.id=ti.payable_item_id
                  JOIN portal_transactions t ON t.id=ti.portal_transaction_id WHERE t.participant_id=?", [$pid]) as $r) $hist_items[(int)$r['portal_transaction_id']][] = $r;
$bal_ti  = ti_client_balance($pid);
$bills_all = k30_ti_client_billing($pid);
$wreqs = array_slice(ti_wallet_requests_for_client($pid), 0, 8);
$pay_hist = array_slice(ti_payments_for_client($pid), 0, 15);
$pay_info = k30_ti_client_payment($pid);
$pp_gw    = ['payu' => payu_enabled(), 'stripe' => stripe_enabled(), 'p24' => p24_enabled()];
$p24_on  = p24_enabled() || org_setting('pp_p24_simulation') === '1';
$acct    = pp_payment_account($user);            // indywidualny (wirtualny) albo ogólny rachunek organizacji
$nrb     = (string)($acct['nrb'] ?? '');
$today   = date('Y-m-d');
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$csrf = h(pp_csrf());
$stx  = in_array($view, ['status', 'sim'], true) ? pp_transaction_view((string)($_GET['tx'] ?? ''), $pid) : null;
?><!doctype html>
<html lang="pl" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Płatności — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 50: '#eef3f9', 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  .m-on  { @apply ring-2 ring-navy-700 bg-navy-50; }
  .m-off { @apply ring-1 ring-slate-300 bg-white hover:bg-slate-50; }
  .row-on  { @apply bg-navy-50; }
  .st-success { @apply bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200; }
  .st-pending { @apply bg-amber-50 text-amber-900 ring-1 ring-amber-200; }
  .st-failed  { @apply bg-slate-100 text-slate-600 ring-1 ring-slate-300; }
  .st-unknown { @apply bg-slate-100 text-slate-600 ring-1 ring-slate-300; }
  a:focus-visible, button:focus-visible, input:focus-visible, label:focus-within { @apply outline-none ring-2 ring-offset-2 ring-navy-600; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important} @media (prefers-reduced-motion: reduce){*{transition:none!important;animation:none!important}}</style>
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Przejdź do treści</a>
<header class="bg-navy-700 text-white">
  <div class="mx-auto max-w-5xl px-4 py-4 flex flex-wrap items-center gap-2">
    <i class="bi bi-credit-card-2-front text-xl" aria-hidden="true"></i>
    <span class="font-semibold">Płatności · <?= h($org) ?></span>
    <span class="ml-auto text-sm text-white/80"><?= h($cl['name'] ?? '') ?></span>
  </div>
</header>

<main id="main" class="mx-auto max-w-5xl px-4 py-6 space-y-6">
  <?php if ($flash): ?>
  <div role="<?= $flash[0] === 'danger' ? 'alert' : 'status' ?>" class="rounded-lg px-4 py-3 text-sm <?= $flash[0] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>"><?= h($flash[1]) ?></div>
  <?php endif; ?>

<?php if ($view === 'sim' && $stx && $stx['status'] === 'pending' && $stx['payment_method'] === 'p24' && !p24_enabled()): ?>
  <!-- ═══ Bramka testowa (symulacja P24) ═══ -->
  <section class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-amber-300" aria-labelledby="sim-h">
    <p class="mb-2 rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">TRYB TESTOWY — żadne pieniądze nie są pobierane</p>
    <h1 id="sim-h" class="text-lg font-semibold">Przelewy24 (symulacja)</h1>
    <p class="mt-1 text-slate-600">Do zapłaty: <strong><?= h(pp_fmt((float)$stx['total_amount'])) ?></strong></p>
    <form method="post" class="mt-4 flex gap-2">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="sim_result"><input type="hidden" name="tx" value="<?= h($stx['transaction_uuid']) ?>">
      <button name="result" value="ok" class="flex-1 rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700">Zapłać</button>
      <button name="result" value="fail" class="flex-1 rounded-md bg-white px-4 py-2 font-medium ring-1 ring-slate-300 hover:bg-slate-50">Odrzuć</button>
    </form>
  </section>

<?php elseif ($view === 'status' && $stx): ?>
  <!-- ═══ Status transakcji ═══ -->
  <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200" aria-labelledby="st-h"
           x-data="{ st: <?= h($J($stx['status'])) ?>, label: <?= h($J(PP_TX_STATUS[$stx['status']] ?? $stx['status'])) ?>, n: 0,
                     poll() { if (this.st !== 'pending' || this.n++ > 60) return;
                              fetch('./?json=status&tx=<?= h(urlencode($stx['transaction_uuid'])) ?>', { credentials: 'same-origin' }).then(r => r.json())
                                .then(d => { this.st = d.status; this.label = d.label || d.status; if (this.st === 'pending') setTimeout(() => this.poll(), <?= $stx['payment_method'] === 'p24' ? 3000 : 15000 ?>); })
                                .catch(() => setTimeout(() => this.poll(), 10000)); } }"
           x-init="setTimeout(() => poll(), 2000)">
    <div class="flex flex-wrap items-center gap-3">
      <h1 id="st-h" class="text-lg font-semibold">Płatność <?= h(strtoupper(substr(str_replace('-', '', $stx['transaction_uuid']), 0, 10))) ?></h1>
      <span class="rounded-full px-3 py-1 text-sm font-medium" :class="'st-' + st" role="status" aria-live="polite" x-text="label"></span>
    </div>
    <p class="mt-1 text-slate-600"><?= $stx['payment_method'] === 'p24' ? 'Przelewy24' : 'Przelew tradycyjny' ?> · <?= h(pp_fmt((float)$stx['total_amount'])) ?> · <?= h(date('d.m.Y H:i', strtotime((string)$stx['created_at']))) ?></p>
    <ul class="mt-3 divide-y divide-slate-100 text-sm">
      <?php foreach ($stx['items'] as $it): ?><li class="flex justify-between py-1.5"><span><?= h($it['title']) ?></span><span class="tabular-nums"><?= h(pp_fmt((float)$it['amount'])) ?></span></li><?php endforeach; ?>
    </ul>
    <?php if ($stx['payment_method'] === 'individual_nrb'): ?>
    <div x-show="st === 'pending'" class="mt-4 rounded-xl bg-navy-50 p-4 ring-1 ring-navy-600/20">
      <h2 class="font-semibold mb-2">Dane do przelewu</h2>
      <?php $t_nrb = (string)($stx['target_nrb'] ?: $nrb); $t_gen = $t_nrb !== '' && $t_nrb === pp_nrb_normalize((string)org_setting('pp_general_nrb'));
            $rows = [['Numer rachunku', pp_nrb_format($t_nrb), $t_nrb], ['Kwota', pp_fmt((float)$stx['total_amount']), number_format((float)$stx['total_amount'], 2, ',', '')], ['Tytuł przelewu', $stx['transfer_title'], $stx['transfer_title']], ['Odbiorca', $org, $org]]; ?>
      <dl class="grid gap-2 sm:grid-cols-[10rem_1fr]">
        <?php foreach ($rows as [$l, $v, $copy]): ?>
        <dt class="text-sm text-slate-500"><?= h($l) ?></dt>
        <dd class="flex items-center gap-2" x-data="{ c: false }"><span class="font-mono font-semibold break-all"><?= h($v) ?></span>
          <button type="button" class="rounded px-2 py-0.5 text-xs ring-1 ring-slate-300 hover:bg-white" @click="navigator.clipboard.writeText(<?= h($J($copy)) ?>); c = true; setTimeout(() => c = false, 1500)"
                  :aria-label="c ? 'Skopiowano' : 'Kopiuj: <?= h($l) ?>'"><span x-text="c ? 'skopiowano' : 'kopiuj'"></span></button></dd>
        <?php endforeach; ?>
      </dl>
      <?php if ($t_gen): ?>
      <p role="note" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-300">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> To wspólny rachunek organizacji — wpłatę rozpoznajemy <strong>wyłącznie po tytule</strong>. Przepisz go bez zmian (najważniejszy jest kod <span class="font-mono font-semibold"><?= h(pp_title_code((string)$stx['transaction_uuid'])) ?></span>).</p>
      <?php endif; ?>
      <ol class="mt-3 list-decimal pl-5 text-sm text-slate-600 space-y-1">
        <li>Zrób przelew z dokładnie tą kwotą i <strong>tytułem</strong> — po nim rozpoznajemy płatność.</li>
        <li>Zaksięgowanie trwa zwykle 1–2 dni robocze. Status zmieni się tutaj sam, gdy wpłata pojawi się na wyciągu bankowym.</li>
        <li>Do tego czasu wybrane pozycje są oznaczone jako „w trakcie opłacania”.</li>
      </ol>
      <form method="post" class="mt-3" onsubmit="return confirm('Anulować tę płatność? Zrób to tylko, jeśli NIE wysłałeś przelewu.')">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="cancel_nrb"><input type="hidden" name="tx" value="<?= h($stx['transaction_uuid']) ?>">
        <button class="text-sm text-slate-600 underline hover:text-slate-800">Nie wysłałem przelewu — anuluj</button>
      </form>
    </div>
    <?php endif; ?>
    <p x-show="st === 'success'" class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-emerald-800">Dziękujemy — płatność została zaksięgowana.</p>
    <p x-show="st === 'failed'" class="mt-4 rounded-lg bg-slate-100 px-4 py-3 text-slate-700">Płatność nie doszła do skutku — pozycje wróciły na listę do zapłaty.</p>
    <p x-show="st === 'pending' && <?= $J($stx['payment_method'] === 'p24') ?>" class="mt-4 text-sm text-slate-600">Czekamy na potwierdzenie z Przelewy24 — ta strona odświeży się sama.</p>
    <a href="./" class="mt-5 inline-flex items-center gap-1 text-navy-700 hover:underline"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do płatności</a>
  </section>

<?php else: ?>
  <!-- ═══ Układ: podsumowanie + zakładki (Do zapłaty / Wpłać / Historia / Informacje) ═══ -->
  <?php $bd = (float)($bal_ti['debt'] ?? 0); $bc = (float)($bal_ti['credit'] ?? 0); $due_sum = 0.0; foreach ($items as $it0) { if ($it0['status'] === 'pending') $due_sum += (float)$it0['amount']; }
        $acc_txt = (string)($pay_info['account'] ?? ''); $acc_d = preg_replace('/\D/', '', $acc_txt); ?>
  <div x-data="{ tab: location.hash.slice(1) || '<?= $items ? 'zaplac' : 'wplac' ?>', go(t) { this.tab = t; try { history.replaceState(null, '', '#' + t); } catch (e) {} } }" class="space-y-6">
    <div class="grid gap-4 md:grid-cols-3" aria-label="Podsumowanie">
      <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="text-xs uppercase tracking-wide text-slate-500">Saldo</div>
        <div class="mt-1 text-3xl font-semibold tabular-nums <?= $bd > 0.005 ? 'text-red-700' : ($bc > 0.005 ? 'text-emerald-700' : 'text-slate-700') ?>"><?= $bd > 0.005 ? '−' . h(pp_fmt($bd)) : ($bc > 0.005 ? '+' . h(pp_fmt($bc)) : h(pp_fmt(0))) ?></div>
        <div class="text-xs text-slate-500"><?= $bd > 0.005 ? 'niedopłata' : ($bc > 0.005 ? 'nadpłata — zaliczana na kolejne zajęcia' : 'rozliczone') ?> · należności <?= h(pp_fmt((float)($bal_ti['charges'] ?? 0))) ?>, wpłaty <?= h(pp_fmt((float)($bal_ti['payments'] ?? 0))) ?></div>
      </div>
      <button type="button" @click="go('zaplac')" class="rounded-2xl bg-white p-5 text-left shadow-sm ring-1 ring-slate-200 hover:ring-navy-600">
        <div class="text-xs uppercase tracking-wide text-slate-500">Do zapłaty</div>
        <div class="mt-1 text-3xl font-semibold tabular-nums <?= $due_sum > 0.005 ? 'text-amber-700' : 'text-emerald-700' ?>"><?= h(pp_fmt($due_sum)) ?></div>
        <div class="text-xs text-slate-500"><?= count(array_filter($items, fn($i0) => $i0['status'] === 'pending')) ?> pozycji · kliknij, aby zapłacić</div>
      </button>
      <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" x-data="{ c: false }">
        <div class="text-xs uppercase tracking-wide text-slate-500"><?= !empty($pay_info['virtual']) ? 'Twój numer rachunku' : 'Rachunek do wpłat' ?></div>
        <div class="mt-1 font-mono text-base font-semibold tracking-wide break-words"><?= $acc_txt !== '' ? h($acc_txt) : '—' ?></div>
        <?php if ($acc_txt !== ''): ?><button type="button" class="mt-1 text-xs text-navy-700 hover:underline" @click="navigator.clipboard.writeText(<?= h($J($acc_d)) ?>); c = true" x-text="c ? 'Skopiowano' : 'Kopiuj numer'"></button><?php endif; ?>
      </div>
    </div>
    <nav class="flex flex-wrap gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200" aria-label="Sekcje portalu płatności">
      <?php foreach (['zaplac' => ['Do zapłaty', 'bi-cart-check'], 'wplac' => ['Wpłać', 'bi-bank2'], 'hist' => ['Historia i rozliczenia', 'bi-clock-history'], 'info' => ['Informacje', 'bi-info-circle']] as $tk => [$tl, $ti]): ?>
      <button type="button" @click="go('<?= $tk ?>')" :class="tab === '<?= $tk ?>' ? 'bg-navy-700 text-white shadow' : 'text-slate-600 hover:bg-slate-100'" :aria-current="tab === '<?= $tk ?>' ? 'page' : null" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition"><i class="bi <?= $ti ?>" aria-hidden="true"></i><?= $tl ?></button>
      <?php endforeach; ?>
    </nav>
    <div x-show="tab === 'zaplac'" x-cloak class="space-y-6">
  <!-- ═══ Koszyk ═══ -->
  <form method="post" x-data="cart()" @submit="submit($event)" class="grid gap-6 lg:grid-cols-3">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="pay">
    <section class="lg:col-span-2 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="it-h">
      <div class="flex items-center gap-2 border-b border-slate-200 px-5 py-3">
        <h1 id="it-h" class="font-semibold">Do zapłaty</h1>
        <button type="button" x-show="payable().length > 1" class="ml-auto text-sm text-navy-700 hover:underline" @click="toggleAll()"
                x-text="sel.length === payable().length ? 'Odznacz wszystkie' : 'Zaznacz wszystkie'"></button>
      </div>
      <?php if (!$items): ?>
      <p class="px-5 py-8 text-center text-slate-500"><i class="bi bi-check2-circle text-2xl text-emerald-600" aria-hidden="true"></i><br>Nie masz nic do zapłaty.</p>
      <?php endif; ?>
      <ul class="divide-y divide-slate-100">
        <template x-for="it in items" :key="it.id">
          <li class="flex items-center gap-3 px-5 py-3" :class="sel.includes(it.id) ? 'row-on' : ''">
            <input type="checkbox" name="items[]" :value="it.id" :id="'it' + it.id" x-model.number="sel" :disabled="it.status !== 'pending'"
                   class="h-5 w-5 rounded border-slate-400 text-navy-700">
            <label :for="'it' + it.id" class="flex-1 min-w-0 cursor-pointer">
              <span class="block font-medium" x-text="it.title"></span>
              <span class="block text-xs text-slate-500">
                <span x-text="it.type_label"></span>
                <template x-if="it.due_date"><span> · termin <span x-text="it.due_label"></span>
                  <span x-show="it.overdue" class="ml-1 rounded bg-red-100 px-1.5 text-red-800">po terminie</span></span></template>
                <span x-show="it.status === 'processing'" class="ml-1 rounded bg-amber-100 px-1.5 text-amber-900">w trakcie opłacania</span>
              </span>
            </label>
            <span class="tabular-nums font-semibold whitespace-nowrap" x-text="zl(it.amount)"></span>
          </li>
        </template>
      </ul>
    </section>

    <aside class="space-y-4 lg:sticky lg:top-4 self-start" aria-labelledby="sum-h">
      <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 id="sum-h" class="font-semibold">Podsumowanie</h2>
        <p class="mt-1 text-sm text-slate-500"><span x-text="sel.length"></span> poz.</p>
        <p class="mt-2 text-3xl font-bold tabular-nums text-navy-700" aria-live="polite" x-text="zl(total())"></p>
        <fieldset class="mt-4 space-y-2">
          <legend class="text-sm font-medium text-slate-700 mb-1">Metoda płatności</legend>
          <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3" :class="method === 'p24' ? 'm-on' : 'm-off'"<?= $p24_on ? '' : ' title="Chwilowo niedostępne"' ?>>
            <input type="radio" name="method" value="p24" x-model="method" class="mt-1"<?= $p24_on ? '' : ' disabled' ?>>
            <span><span class="block font-medium">Przelewy24</span><span class="block text-xs text-slate-500">BLIK, szybki przelew, karta — od razu<?= $p24_on ? '' : ' (chwilowo niedostępne)' ?></span></span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3" :class="method === 'individual_nrb' ? 'm-on' : 'm-off'">
            <input type="radio" name="method" value="individual_nrb" x-model="method" class="mt-1"<?= $nrb !== '' ? '' : ' disabled' ?>>
            <span><span class="block font-medium"><?= ($acct['kind'] ?? '') === 'individual' ? 'Przelew na indywidualny rachunek' : 'Przelew tradycyjny' ?></span>
              <span class="block text-xs text-slate-500"><?= $nrb !== '' ? h(pp_nrb_format($nrb)) . (($acct['kind'] ?? '') === 'general' ? ' — rachunek ogólny, liczy się tytuł przelewu' : '') . ' — księgowanie 1–2 dni' : 'chwilowo niedostępny' ?></span></span>
          </label>
        </fieldset>
        <button class="mt-4 w-full rounded-lg bg-navy-700 px-4 py-2.5 font-semibold text-white hover:bg-navy-600 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!sel.length || !method" x-text="method === 'individual_nrb' ? 'Pokaż dane do przelewu' : (method === 'p24' ? 'Zapłać ' + zl(total()) : 'Wybierz metodę')"></button>
        <p class="mt-2 text-xs text-slate-500">Kwota jest sprawdzana w systemie przy płatności — liczy się stan w chwili zapłaty.</p>
      </section>
    </aside>
  </form>
    </div>
    <div x-show="tab === 'wplac'" x-cloak class="space-y-6">
    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="dw-h" x-data="{ c: false }">
      <h2 id="dw-h" class="font-semibold"><i class="bi bi-bank2 text-navy-700" aria-hidden="true"></i> Dane do wpłaty</h2>
      <?php $acc_txt = (string)($pay_info['account'] ?? ''); $acc_d = preg_replace('/\D/', '', $acc_txt); ?>
      <?php if ($acc_txt !== ''): ?>
      <div class="mt-3 rounded-xl border-2 border-navy-700 bg-navy-50 px-4 py-3 text-center">
        <div class="text-xs text-slate-600"><?= !empty($pay_info['virtual']) ? 'Twój indywidualny numer rachunku' : 'Rachunek do wpłat (grupy / organizacji)' ?></div>
        <div class="font-mono text-xl font-semibold tracking-wide"><?= h($acc_txt) ?></div>
      </div>
      <dl class="mt-3 grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
        <?php if (trim((string)($pay_info['title'] ?? '')) !== ''): ?><dt class="text-slate-500">Tytuł przelewu</dt><dd class="font-mono"><?= h($pay_info['title']) ?></dd><?php endif; ?>
        <dt class="text-slate-500">Odbiorca</dt><dd><?= h($org) ?></dd>
        <?php if (!empty($pay_info['virtual'])): ?><dt class="text-slate-500">Uwaga</dt><dd>Na ten numer wpłacasz wszystkie należności z tytułu szkoleń. Wpłaty są księgowane na koniec dnia, o 20:00.</dd><?php endif; ?>
      </dl>
      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300 hover:bg-slate-50" @click="navigator.clipboard.writeText(<?= h($J($acc_d)) ?>); c = true" x-text="c ? 'Skopiowano' : 'Kopiuj numer'"></button>
        <?php if (!empty($pay_info['virtual'])): ?><a href="rachunek_pdf.php" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-md bg-navy-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-navy-600"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Drukuj PDF z informacją o numerze</a><?php endif; ?>
      </div>
      <?php else: ?><p class="mt-2 text-sm text-slate-500">Numer rachunku nie został jeszcze ustawiony — skontaktuj się z biurem.</p><?php endif; ?>
    </section>
  <?php if (array_filter($pp_gw)): ?>
  <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="dl-h">
    <h2 id="dl-h" class="font-semibold"><i class="bi bi-plus-circle text-navy-700" aria-hidden="true"></i> Doładuj portfel</h2>
    <p class="mt-1 text-xs text-slate-500">Wpłata trafia na Twoje konto i pokrywa kolejne należności za zajęcia.</p>
    <form method="post" class="mt-3 flex flex-wrap items-end gap-2">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="topup">
      <div><label class="block text-xs text-slate-600" for="tu-a">Kwota (zł)</label><input id="tu-a" name="amount" inputmode="decimal" required placeholder="np. 200" class="w-32 rounded-md border border-slate-300 px-3 py-1.5 text-sm"></div>
      <?php foreach (['payu' => 'Zapłać przez PayU', 'p24' => 'Zapłać przez Przelewy24', 'stripe' => 'Zapłać kartą (Stripe)'] as $gk => $gl): if (empty($pp_gw[$gk])) continue; ?>
      <button name="provider" value="<?= $gk ?>" class="rounded-md bg-navy-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-navy-600"><?= h($gl) ?></button>
      <?php endforeach; ?>
    </form>
  </section>
  <?php endif; ?>
  <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="zw-h">
    <h2 id="zw-h" class="font-semibold"><i class="bi bi-send-check text-navy-700" aria-hidden="true"></i> Zgłoś wpłatę przelewem</h2>
    <p class="mt-1 text-xs text-slate-500">Zrobiłeś przelew na swój numer rachunku? Zgłoś kwotę — biuro zaksięguje ją po potwierdzeniu wpływu. (Wpłaty są księgowane na koniec dnia, o 20:00.)</p>
    <form method="post" class="mt-3 flex flex-wrap items-end gap-2">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="declare">
      <div><label class="block text-xs text-slate-600" for="dc-a">Kwota (zł)</label><input id="dc-a" name="amount" inputmode="decimal" required class="w-32 rounded-md border border-slate-300 px-3 py-1.5 text-sm"></div>
      <div class="min-w-[12rem] flex-1"><label class="block text-xs text-slate-600" for="dc-n">Uwagi (opcjonalnie)</label><input id="dc-n" name="note" maxlength="300" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" placeholder="np. data przelewu"></div>
      <button class="rounded-md bg-white px-3 py-1.5 text-sm font-medium ring-1 ring-slate-300 hover:bg-slate-50">Zgłoś wpłatę</button>
    </form>
    <?php if ($wreqs): ?><ul class="mt-3 space-y-1 text-xs text-slate-600"><?php $wst = ['pending' => 'czeka na potwierdzenie', 'approved' => 'zaksięgowana', 'rejected' => 'odrzucona']; foreach ($wreqs as $wr): ?>
      <li><?= h(date('d.m.Y', strtotime((string)$wr['created_at']))) ?> · <strong><?= h(pp_fmt((float)$wr['amount'])) ?></strong> · <?= h($wst[$wr['status']] ?? $wr['status']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
    </div>
    <div x-show="tab === 'hist'" x-cloak class="space-y-6">
  <?php if ($pay_hist): ?>
  <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="hw-h">
    <h2 id="hw-h" class="border-b border-slate-200 px-5 py-3 font-semibold"><i class="bi bi-clock-history text-navy-700" aria-hidden="true"></i> Historia wpłat</h2>
    <div class="overflow-x-auto"><table class="min-w-full text-sm"><caption class="sr-only">Ostatnie wpłaty</caption><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="px-5 py-2">Data</th><th class="px-2">Metoda</th><th class="px-2">Opis</th><th class="px-5 text-right">Kwota</th></tr></thead><tbody class="divide-y divide-slate-100">
      <?php $mlab = ['transfer' => 'przelew', 'cash' => 'gotówka', 'stripe' => 'Stripe', 'payu' => 'PayU', 'p24' => 'Przelewy24', 'other' => 'inna', 'internal' => 'korekta']; foreach ($pay_hist as $ph): ?>
      <tr><td class="whitespace-nowrap px-5 py-2"><?= h(date('d.m.Y', strtotime((string)$ph['paid_at']))) ?></td><td class="px-2"><?= h($mlab[$ph['method']] ?? $ph['method']) ?></td><td class="px-2 text-xs text-slate-600"><?= h(mb_strimwidth((string)$ph['note'], 0, 70, '…')) ?></td><td class="px-5 text-right tabular-nums <?= (float)$ph['amount'] < 0 ? 'text-red-700' : '' ?>"><?= h(pp_fmt((float)$ph['amount'])) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </section>
  <?php endif; ?>
  <?php if ($bills_all): $mn = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień']; ?>
  <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="rz-h">
    <h2 id="rz-h" class="border-b border-slate-200 px-5 py-3 font-semibold"><i class="bi bi-receipt text-navy-700" aria-hidden="true"></i> Rozliczenia zajęć</h2>
    <div class="overflow-x-auto"><table class="min-w-full text-sm"><caption class="sr-only">Rozliczenia miesięczne</caption><thead class="text-left text-xs uppercase text-slate-500"><tr><th class="px-5 py-2">Okres</th><th class="px-2">Grupa</th><th class="px-2 text-right">Należność</th><th class="px-2 text-right">Pokryto</th><th class="px-2">Termin</th><th class="px-5 text-right">Dokumenty</th></tr></thead><tbody class="divide-y divide-slate-100">
      <?php foreach (array_slice($bills_all, 0, 24) as $bl): $amt = (float)$bl['amount'] + (float)($bl['adjustment'] ?? 0); ?>
      <tr><td class="whitespace-nowrap px-5 py-2"><?= h(($mn[(int)$bl['month']] ?? $bl['month']) . ' ' . $bl['year']) ?></td><td class="px-2 text-xs"><?= h($bl['course_name'] ?: 'rozliczenie łączne') ?></td>
        <td class="px-2 text-right tabular-nums"><?= h(pp_fmt($amt)) ?></td><td class="px-2 text-right tabular-nums"><?= h(pp_fmt((float)($bl['paid_amount'] ?? 0))) ?></td>
        <td class="px-2 text-xs"><?= !empty($bl['due_date']) ? h(date('d.m.Y', strtotime((string)$bl['due_date']))) : '—' ?></td>
        <td class="px-5 text-right text-xs whitespace-nowrap"><a class="text-navy-700 hover:underline" href="dokument.php?type=hours&amp;month=<?= (int)$bl['month'] ?>&amp;year=<?= (int)$bl['year'] ?><?= (int)$bl['course_id'] > 0 ? '&amp;course_id=' . (int)$bl['course_id'] : '' ?>" target="_blank" rel="noopener">rozpiska</a>
          <?php if (!empty($bl['invoice_path'])): ?> · <a class="text-navy-700 hover:underline" href="dokument.php?type=invoice&amp;id=<?= (int)$bl['id'] ?>" target="_blank" rel="noopener">faktura</a><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </section>
  <?php endif; ?>
  <!-- ═══ Historia ═══ -->
  <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="h-h">
    <h2 id="h-h" class="border-b border-slate-200 px-5 py-3 font-semibold">Historia płatności</h2>
    <?php if (!$hist): ?><p class="px-5 py-6 text-center text-slate-500">Brak płatności.</p><?php endif; ?>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($hist as $t): ?>
      <li class="px-5 py-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium"><?= h(date('d.m.Y H:i', strtotime((string)$t['created_at']))) ?></span>
          <span class="text-sm text-slate-500"><?= $t['payment_method'] === 'p24' ? 'Przelewy24' : 'Przelew' ?> · <?= h(strtoupper(substr(str_replace('-', '', $t['transaction_uuid']), 0, 10))) ?></span>
          <span class="rounded-full px-2.5 py-0.5 text-xs st-<?= h($t['status']) ?>"><?= h(PP_TX_STATUS[$t['status']] ?? $t['status']) ?></span>
          <span class="ml-auto tabular-nums font-semibold"><?= h(pp_fmt((float)$t['total_amount'])) ?></span>
          <?php if ($t['status'] === 'pending'): ?><a class="text-sm text-navy-700 hover:underline" href="./?view=status&amp;tx=<?= h(urlencode($t['transaction_uuid'])) ?>">szczegóły</a><?php endif; ?>
        </div>
        <div class="text-xs text-slate-500"><?= h(implode(' · ', array_map(fn($x) => $x['title'], $hist_items[(int)$t['id']] ?? []))) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
    </div>
    <div x-show="tab === 'info'" x-cloak class="space-y-6">
  <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="in-h">
    <h2 id="in-h" class="font-semibold"><i class="bi bi-info-circle text-navy-700" aria-hidden="true"></i> Informacje o płatnościach</h2>
    <ul class="mt-2 space-y-2 text-sm text-slate-700">
      <li><strong>Gdzie płacić.</strong> Wszystkie należności z tytułu szkoleń i zajęć wpłacasz na <strong>swój indywidualny numer rachunku</strong> (u góry strony). Jeśli jeszcze go nie masz, płacisz na rachunek grupy lub organizacji — zobacz „Dane do wpłaty".</li>
      <li><strong>Tytuł przelewu.</strong> Wpisz zalecany tytuł z sekcji „Dane do wpłaty". Przy rachunku indywidualnym wpłata przypisuje się do Ciebie automatycznie, także przy innym tytule.</li>
      <li><strong>Księgowanie.</strong> Wpłaty są księgowane zawsze na koniec dnia, o godzinie 20:00 — saldo po wpłacie zobaczysz po tej godzinie. Płatność online (PayU, Przelewy24, karta) księguje się od razu po potwierdzeniu przez bramkę.</li>
      <li><strong>Bank.</strong> Rachunki obsługuje PKO Bank Polski S.A.</li>
      <li><strong>Nadpłata</strong> jest zaliczana na kolejne zajęcia; niedopłatę widzisz w saldzie i w „Do zapłaty". Zwrot nadpłaty ustal z biurem.</li>
      <li><strong>Dokumenty.</strong> Rozpiskę godzin i fakturę pobierzesz w tabeli „Rozliczenia zajęć". PDF z informacją o numerze rachunku — przycisk w „Dane do wpłaty".</li>
      <li><strong>Pytania.</strong> Skontaktuj się z prowadzącym lub biurem <?= h($org) ?>.</li>
    </ul>
  </section>
    </div>
  </div>
<?php endif; ?>
</main>

<footer class="mx-auto max-w-5xl px-4 pb-8 pt-2">
  <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 rounded-2xl bg-white px-5 py-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">
    <span class="font-medium text-slate-700">Płatności obsługują:</span>
    <?php if (p24_enabled() || org_setting('pp_p24_simulation') === '1'): ?>
    <a href="https://www.przelewy24.pl" target="_blank" rel="noopener" class="inline-flex items-center" title="Przelewy24 — płatności online"><img src="/assets/logo/przelewy24.svg" alt="Przelewy24" class="h-8 w-auto" width="91" height="32"></a>
    <?php endif; ?>
    <?php if (payu_enabled()): ?><span class="inline-flex items-center gap-1 font-semibold text-slate-700" title="Płatności online PayU"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i>PayU</span><?php endif; ?>
    <?php if (stripe_enabled()): ?><span class="inline-flex items-center gap-1 font-semibold text-slate-700" title="Płatności kartą Stripe"><i class="bi bi-credit-card" aria-hidden="true"></i>Stripe</span><?php endif; ?>
    <span class="inline-flex items-center gap-1" title="Bank prowadzący rachunki do wpłat"><i class="bi bi-bank2" aria-hidden="true"></i>Rachunki: <strong class="ml-1 text-slate-700">PKO Bank Polski S.A.</strong></span>
  </div>
</footer>

<?php if (!in_array($view, ['status', 'sim'], true)): ?>
<script>
function cart() {
  return {
    items: <?= $J(array_map(fn($i) => ['id' => (int)$i['id'], 'title' => $i['title'], 'amount' => (float)$i['amount'], 'status' => $i['status'],
                                     'type_label' => PP_REF_TYPES[$i['reference_type']] ?? $i['reference_type'], 'due_date' => $i['due_date'],
                                     'due_label' => $i['due_date'] ? date('d.m.Y', strtotime((string)$i['due_date'])) : '', 'overdue' => $i['due_date'] && $i['due_date'] < $today], $items)) ?>,
    sel: [], method: <?= $J($p24_on ? 'p24' : ($nrb !== '' ? 'individual_nrb' : '')) ?>,
    payable() { return this.items.filter(i => i.status === 'pending'); },
    toggleAll() { this.sel = this.sel.length === this.payable().length ? [] : this.payable().map(i => i.id); },
    total() { return this.items.filter(i => this.sel.includes(i.id)).reduce((s, i) => s + Math.round(i.amount * 100), 0) / 100; },
    zl(v) { return Number(v).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'; },
    submit(e) { if (!this.sel.length || !this.method) e.preventDefault(); },
  };
}
</script>
<?php endif; ?>
</body>
</html>
