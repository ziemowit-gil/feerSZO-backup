<?php
/**
 * platnosci/admin.php — obsługa portalu płatności przez biuro (logowanie SZO, rola admin).
 *
 * Ustawienia (prefiks rachunków wirtualnych, symulacja P24), dostęp uczestników
 * (indywidualny NRB, link z tokenem, blokada), pozycje do zapłaty (ręczne, import
 * nieopłaconych rozliczeń TI, anulowanie), potwierdzanie / odrzucanie zgłoszonych
 * przelewów na NRB, lista transakcji. Wszystkie zapisy — transakcje PDO + audit_logs.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/payment_portal/logic/paymentPortal.php';

require_role('admin');
pp_migrate();
$me  = current_user();
$by  = (string)($me['name'] ?? $me['email'] ?? 'admin');
$uid = (int)($me['id'] ?? 0) ?: null;

$link_once = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $pid = (int)($_POST['participant_id'] ?? 0);
    $err = null; $ok = null;
    switch ($op) {
        case 'settings':
            $bank = preg_replace('/\D/', '', (string)($_POST['pp_nrb_bank'] ?? ''));
            $pref = preg_replace('/\D/', '', (string)($_POST['pp_nrb_prefix'] ?? ''));
            if ($bank !== '' && strlen($bank) !== 8) { $err = 'Numer rozliczeniowy banku ma 8 cyfr.'; break; }
            if (strlen($pref) > 12) { $err = 'Prefiks może mieć najwyżej 12 cyfr (zostają min. 4 na numer uczestnika).'; break; }
            org_setting_set('pp_nrb_bank', $bank); org_setting_set('pp_nrb_prefix', $pref);
            org_setting_set('pp_p24_simulation', !empty($_POST['pp_p24_simulation']) ? '1' : '0');
            audit_log('payments.settings', ['bank' => $bank, 'prefix' => $pref, 'simulation' => !empty($_POST['pp_p24_simulation']), 'by' => $by], $uid);
            $ok = 'Ustawienia zapisane.'; break;
        case 'user':
            $u = pp_user_ensure($pid, $by, $uid);
            $err = is_string($u) ? $u : null; $ok = 'Dostęp uczestnika utworzony.'; break;
        case 'nrb':
            $raw = ($_POST['generate'] ?? '') === '1' ? (string)pp_nrb_generate($pid) : (string)($_POST['nrb'] ?? '');
            if (($_POST['generate'] ?? '') === '1' && $raw === '') { $err = 'Najpierw ustaw numer rozliczeniowy banku i prefiks rachunków wirtualnych.'; break; }
            $err = pp_set_nrb($pid, $raw, $by, $uid); $ok = 'Numer rachunku zapisany.'; break;
        case 'link':
            if (!pp_user($pid)) { $err = 'Najpierw utwórz dostęp.'; break; }
            $_SESSION['pp_link_once'] = ['pid' => $pid, 'url' => pp_issue_link($pid, $by, $uid)];
            $ok = 'Nowy link dostępu wygenerowany (poprzedni przestał działać).'; break;
        case 'active':
            db()->prepare("UPDATE payment_portal_users SET is_active=? WHERE participant_id=?")->execute([!empty($_POST['on']) ? 1 : 0, $pid]);
            audit_log('payments.portal_user_active', ['participant_id' => $pid, 'active' => !empty($_POST['on']), 'by' => $by], $uid);
            $ok = !empty($_POST['on']) ? 'Dostęp włączony.' : 'Dostęp zablokowany.'; break;
        case 'item':
            $amt = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? ''));
            $r = pp_add_item($pid, (string)($_POST['title'] ?? ''), $amt, (string)($_POST['reference_type'] ?? 'manual'),
                             (int)($_POST['reference_id'] ?? 0) ?: null, trim((string)($_POST['due_date'] ?? '')) ?: null, $by, $uid);
            $err = is_string($r) ? $r : null; $ok = 'Pozycja dodana.'; break;
        case 'cancel_item':
            $err = pp_cancel_item((int)($_POST['item_id'] ?? 0), $by, $uid); $ok = 'Pozycja anulowana.'; break;
        case 'import_ti':
            $n = pp_import_ti($pid ?: null, $by, $uid); $ok = "Zaimportowano nieopłacone rozliczenia TI: {$n}."; break;
        case 'confirm':
            $date = trim((string)($_POST['bank_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $err = 'Podaj datę zaksięgowania z wyciągu.'; break; }
            $err = pp_settle((int)($_POST['tx_id'] ?? 0), ['gateway' => 'nrb', 'bank_date' => $date, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300), 'confirmed_by' => $by], $by, $uid);
            $ok = 'Wpłata potwierdzona — pozycje opłacone.'; break;
        case 'reject':
            $reason = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
            if (mb_strlen($reason) < 5) { $err = 'Podaj powód odrzucenia.'; break; }
            $err = pp_fail((int)($_POST['tx_id'] ?? 0), $reason, $by, $uid); $ok = 'Płatność odrzucona — pozycje wróciły do zapłaty.'; break;
    }
    flash_set($err ? 'danger' : 'success', $err ?? $ok);
    header('Location: admin.php' . ($pid ? '?p=' . $pid : '')); exit;
}
$link_once = $_SESSION['pp_link_once'] ?? null; unset($_SESSION['pp_link_once']);
$flash = flash_get();

$q    = trim((string)($_GET['q'] ?? ''));
$pid  = (int)($_GET['p'] ?? 0);
$found = $q !== '' ? db_all("SELECT c.id, c.name, c.email, u.id AS pu FROM k30_clients c LEFT JOIN payment_portal_users u ON u.participant_id=c.id
                              WHERE c.name LIKE ? OR c.email LIKE ? ORDER BY c.name LIMIT 30", ['%' . $q . '%', '%' . $q . '%']) : [];
$sel  = $pid ? db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$pid]) : null;
$pu   = $sel ? pp_user($pid) : null;
if ($sel) pp_sync_ti_items($pid);
$sel_items = $sel ? db_all("SELECT * FROM payable_items WHERE participant_id=? ORDER BY status='pending' DESC, status='processing' DESC, id DESC LIMIT 100", [$pid]) : [];
$nrb_pending = db_all("SELECT t.*, c.name FROM portal_transactions t JOIN k30_clients c ON c.id=t.participant_id
                        WHERE t.status='pending' AND t.payment_method='individual_nrb' ORDER BY t.id");
$txs = db_all("SELECT t.*, c.name FROM portal_transactions t JOIN k30_clients c ON c.id=t.participant_id ORDER BY t.id DESC LIMIT 50");
$stats = db_one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) p, COALESCE(SUM(CASE WHEN status='processing' THEN amount END),0) pr FROM payable_items");
$csrf = h(csrf_token());
$sim  = org_setting('pp_p24_simulation') === '1';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal płatności — obsługa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 50: '#eef3f9', 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  .inp { @apply w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-navy-600 focus:outline-none focus:ring-1 focus:ring-navy-600; }
  .lbl { @apply block text-xs font-medium text-slate-600 mb-1; }
  .bp { @apply inline-flex items-center gap-1 rounded-md bg-navy-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-navy-600; }
  .bs { @apply inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50; }
  .card { @apply rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
<header class="bg-navy-700 text-white"><div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap items-center gap-3">
  <a href="../index.php" class="text-sm text-white/70 hover:text-white"><i class="bi bi-arrow-left"></i> SZO</a>
  <h1 class="text-lg font-semibold">Portal płatności — obsługa</h1>
  <span class="text-sm text-white/70">do zapłaty <?= h(pp_fmt((float)$stats['p'])) ?> · w trakcie <?= h(pp_fmt((float)$stats['pr'])) ?> · przelewy do potwierdzenia: <?= count($nrb_pending) ?></span>
</div></header>
<main class="mx-auto max-w-7xl px-4 py-5 space-y-5">
  <?php if ($flash): ?><div role="status" class="rounded-lg px-4 py-3 text-sm <?= $flash['type'] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>"><?= h((string)$flash['msg']) ?></div><?php endif; ?>
  <?php if ($link_once): ?>
  <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm ring-1 ring-amber-300" x-data="{ c: false }">
    <strong>Link dostępu (widoczny tylko teraz):</strong>
    <code class="ml-1 break-all"><?= h($link_once['url']) ?></code>
    <button type="button" class="bs ml-2" @click="navigator.clipboard.writeText(<?= h(json_encode($link_once['url'])) ?>); c = true" x-text="c ? 'skopiowano' : 'kopiuj'"></button>
    <div class="mt-1 text-xs text-slate-600">Przekaż go uczestnikowi (e-mail / SMS). W bazie zapisany jest tylko skrót — ponownie go nie wyświetlimy.</div>
  </div>
  <?php endif; ?>

  <!-- Przelewy do potwierdzenia -->
  <section class="card" aria-labelledby="nrb-h">
    <h2 id="nrb-h" class="font-semibold mb-2">Przelewy na NRB do potwierdzenia</h2>
    <?php if (!$nrb_pending): ?><p class="text-sm text-slate-500">Brak zgłoszonych przelewów.</p><?php endif; ?>
    <?php foreach ($nrb_pending as $t): ?>
    <div class="flex flex-wrap items-end gap-3 border-t border-slate-100 py-3 first:border-0">
      <div class="min-w-[16rem] flex-1"><div class="font-medium"><?= h($t['name']) ?> — <?= h(pp_fmt((float)$t['total_amount'])) ?></div>
        <div class="text-xs text-slate-500">tytuł: <span class="font-mono"><?= h($t['transfer_title']) ?></span> · zgłoszono <?= h(date('d.m.Y H:i', strtotime((string)$t['created_at']))) ?></div></div>
      <form method="post" class="flex flex-wrap items-end gap-2" x-data="{ note: '' }">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="tx_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="participant_id" value="0">
        <div><label class="lbl" for="bd<?= (int)$t['id'] ?>">Data z wyciągu</label><input id="bd<?= (int)$t['id'] ?>" type="date" name="bank_date" class="inp" value="<?= date('Y-m-d') ?>"></div>
        <div><label class="lbl" for="nt<?= (int)$t['id'] ?>">Uwagi / powód odrzucenia</label><input id="nt<?= (int)$t['id'] ?>" name="note" class="inp" maxlength="300" x-model="note"></div>
        <button name="_op" value="confirm" class="bp" onclick="return confirm('Potwierdzić wpłatę <?= h(pp_fmt((float)$t['total_amount'])) ?>?')">Potwierdź zaksięgowanie</button>
        <button name="_op" value="reject" class="bs" :disabled="note.trim().length < 5" title="Wymaga powodu">Odrzuć</button>
      </form>
    </div>
    <?php endforeach; ?>
  </section>

  <div class="grid gap-5 lg:grid-cols-3">
    <!-- Uczestnik -->
    <section class="card lg:col-span-2 space-y-4" aria-labelledby="u-h">
      <h2 id="u-h" class="font-semibold">Uczestnik</h2>
      <form method="get" class="flex gap-2"><label class="sr-only" for="q">Szukaj uczestnika</label>
        <input id="q" name="q" class="inp" placeholder="Imię, nazwisko lub e-mail" value="<?= h($q) ?>"><button class="bs">Szukaj</button></form>
      <?php if ($found): ?><ul class="divide-y divide-slate-100 text-sm">
        <?php foreach ($found as $f): ?><li class="flex justify-between py-1.5"><a class="text-navy-700 hover:underline" href="admin.php?p=<?= (int)$f['id'] ?>"><?= h($f['name']) ?></a><span class="text-slate-500"><?= h((string)$f['email']) ?><?= $f['pu'] ? ' · ma dostęp' : '' ?></span></li><?php endforeach; ?>
      </ul><?php endif; ?>

      <?php if ($sel): ?>
      <div class="rounded-lg bg-slate-50 p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2"><span class="font-semibold"><?= h($sel['name']) ?></span><span class="text-sm text-slate-500"><?= h((string)$sel['email']) ?></span>
          <?php if ($pu): ?><span class="rounded-full px-2 py-0.5 text-xs <?= $pu['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>"><?= $pu['is_active'] ? 'dostęp aktywny' : 'dostęp zablokowany' ?></span><?php endif; ?></div>
        <?php if (!$pu): ?>
        <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="user"><input type="hidden" name="participant_id" value="<?= $pid ?>">
          <button class="bp">Utwórz dostęp do portalu</button></form>
        <?php else: ?>
        <div class="grid gap-3 md:grid-cols-2">
          <form method="post" class="space-y-2"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="nrb"><input type="hidden" name="participant_id" value="<?= $pid ?>">
            <label class="lbl" for="nrb">Indywidualny NRB</label>
            <input id="nrb" name="nrb" class="inp font-mono" value="<?= h(pp_nrb_format((string)$pu['individual_nrb'])) ?>" placeholder="26 cyfr">
            <div class="flex gap-2"><button class="bs">Zapisz</button><button class="bs" name="generate" value="1" title="Z prefiksu rachunków wirtualnych">Wygeneruj</button></div></form>
          <div class="space-y-2">
            <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="link"><input type="hidden" name="participant_id" value="<?= $pid ?>">
              <button class="bp" onclick="return confirm('Wygenerować nowy link? Poprzedni przestanie działać.')"><i class="bi bi-link-45deg"></i> Nowy link dostępu</button></form>
            <p class="text-xs text-slate-500">Ostatnie wejście: <?= $pu['last_login_at'] ? h(date('d.m.Y H:i', strtotime((string)$pu['last_login_at']))) : '—' ?> · link z <?= $pu['token_created_at'] ? h(date('d.m.Y', strtotime((string)$pu['token_created_at']))) : '—' ?></p>
            <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="active"><input type="hidden" name="participant_id" value="<?= $pid ?>">
              <input type="hidden" name="on" value="<?= $pu['is_active'] ? '0' : '1' ?>"><button class="bs"><?= $pu['is_active'] ? 'Zablokuj dostęp' : 'Włącz dostęp' ?></button></form>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <h3 class="font-semibold">Pozycje uczestnika</h3>
      <div class="flex flex-wrap gap-2">
        <form method="post"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="import_ti"><input type="hidden" name="participant_id" value="<?= $pid ?>">
          <button class="bs"><i class="bi bi-download"></i> Pobierz nieopłacone rozliczenia TI</button></form>
      </div>
      <form method="post" class="grid gap-2 md:grid-cols-6 items-end" x-data="{ t: 'manual' }">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="item"><input type="hidden" name="participant_id" value="<?= $pid ?>">
        <div class="md:col-span-2"><label class="lbl" for="it-t">Nazwa</label><input id="it-t" name="title" class="inp" required maxlength="200" placeholder="np. Faktura FVAT 12/2026"></div>
        <div><label class="lbl" for="it-a">Kwota (zł)</label><input id="it-a" name="amount" class="inp" inputmode="decimal" required></div>
        <div><label class="lbl" for="it-r">Rodzaj</label><select id="it-r" name="reference_type" class="inp" x-model="t">
          <?php foreach (PP_REF_TYPES as $k => $l): if ($k === 'ti_billing') continue; ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div x-show="t !== 'manual'"><label class="lbl" for="it-i">ID w systemie</label><input id="it-i" name="reference_id" type="number" min="1" class="inp" placeholder="np. id faktury"></div>
        <div><label class="lbl" for="it-d">Termin</label><input id="it-d" type="date" name="due_date" class="inp"></div>
        <div class="md:col-span-6"><button class="bp">Dodaj pozycję</button></div>
      </form>
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Pozycja</th><th class="pr-2">Rodzaj</th><th class="pr-2 text-right">Kwota</th><th class="pr-2">Termin</th><th class="pr-2">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($sel_items as $it): ?>
          <tr><td class="py-1.5 pr-2"><?= h($it['title']) ?></td><td class="pr-2 text-xs"><?= h(PP_REF_TYPES[$it['reference_type']] ?? $it['reference_type']) ?><?= $it['reference_id'] ? ' #' . (int)$it['reference_id'] : '' ?></td>
            <td class="pr-2 text-right tabular-nums"><?= h(pp_fmt((float)$it['amount'])) ?></td><td class="pr-2 text-xs"><?= $it['due_date'] ? h(date('d.m.Y', strtotime((string)$it['due_date']))) : '—' ?></td>
            <td class="pr-2 text-xs"><?= h(PP_ITEM_STATUS[$it['status']] ?? $it['status']) ?></td>
            <td class="text-right"><?php if ($it['status'] === 'pending'): ?><form method="post" onsubmit="return confirm('Anulować pozycję?')"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="cancel_item"><input type="hidden" name="participant_id" value="<?= $pid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="text-xs text-red-700 hover:underline">anuluj</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sel_items): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">Brak pozycji.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <!-- Ustawienia -->
    <section class="card space-y-3" aria-labelledby="s-h">
      <h2 id="s-h" class="font-semibold">Ustawienia</h2>
      <form method="post" class="space-y-3"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="settings">
        <div><label class="lbl" for="sb">Numer rozliczeniowy banku (8 cyfr)</label><input id="sb" name="pp_nrb_bank" class="inp font-mono" value="<?= h(org_setting('pp_nrb_bank')) ?>" inputmode="numeric"></div>
        <div><label class="lbl" for="sp">Prefiks rachunków wirtualnych (z umowy z bankiem)</label><input id="sp" name="pp_nrb_prefix" class="inp font-mono" value="<?= h(org_setting('pp_nrb_prefix')) ?>" inputmode="numeric"></div>
        <p class="text-xs text-slate-500">NRB = cyfry kontrolne + bank + prefiks + ID uczestnika (dopełnione zerami) — suma kontrolna IBAN liczona automatycznie.</p>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pp_p24_simulation" value="1"<?= $sim ? ' checked' : '' ?>> Symulacja Przelewy24 (gdy bramka nie jest skonfigurowana)</label>
        <p class="text-xs text-slate-500">Przelewy24: <?= p24_enabled() ? '<span class="text-emerald-700">skonfigurowane (' . h(p24_environment()) . ')</span>' : '<span class="text-amber-700">nieskonfigurowane</span>' ?> — dane w <a class="underline" href="../admin/p24_settings.php">ustawieniach P24</a>.</p>
        <button class="bp">Zapisz</button></form>
    </section>
  </div>

  <!-- Transakcje -->
  <section class="card overflow-x-auto" aria-labelledby="t-h">
    <h2 id="t-h" class="font-semibold mb-2">Ostatnie transakcje</h2>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Kiedy</th><th class="pr-2">Uczestnik</th><th class="pr-2">Metoda</th><th class="pr-2">Identyfikator</th><th class="pr-2 text-right">Kwota</th><th class="pr-2">Status</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($txs as $t): ?>
        <tr><td class="py-1.5 pr-2 text-xs whitespace-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$t['created_at']))) ?></td><td class="pr-2"><a class="text-navy-700 hover:underline" href="admin.php?p=<?= (int)$t['participant_id'] ?>"><?= h($t['name']) ?></a></td>
          <td class="pr-2 text-xs"><?= $t['payment_method'] === 'p24' ? 'Przelewy24' : 'NRB' ?></td><td class="pr-2 font-mono text-xs"><?= h($t['transfer_title']) ?></td>
          <td class="pr-2 text-right tabular-nums"><?= h(pp_fmt((float)$t['total_amount'])) ?></td><td class="pr-2 text-xs"><?= h(PP_TX_STATUS[$t['status']] ?? $t['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">Brak transakcji.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
