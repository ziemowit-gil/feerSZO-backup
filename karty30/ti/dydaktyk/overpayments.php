<?php
/**
 * karty30/ti/dydaktyk/overpayments.php — Nadpłaty z kont wirtualnych uczestników (kierownik).
 *
 * Widok: podsumowanie sald, tabela nadpłat z filtrami (status, uczestnik, grupa),
 * modal rozliczenia (zwrot na rachunek / zaliczenie na FVAT lub grupę /
 * przeksięgowanie na innego uczestnika), modal historii (ścieżka audytu),
 * ręczna rejestracja i „Wykryj nadpłaty”. Tailwind (CDN) + Alpine.js.
 * Klasy używane dynamicznie (:class) są zdefiniowane w <style type="text/tailwindcss">
 * — Tailwind CDN nie kompiluje klas pojawiających się dopiero po starcie strony.
 * Logika i transakcje: modules/ti_overpayments/logic/overpayments.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_overpayments/logic/overpayments.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }
ti_op_migrate();
$by  = (string)($me['name'] ?? '');
$uid = (int)($me['user_id'] ?? 0) ?: null;

// ── JSON: historia jednej nadpłaty ──────────────────────────────────────────
if (($_GET['json'] ?? '') === 'history') {
    header('Content-Type: application/json; charset=utf-8');
    $h = ti_op_history((int)($_GET['id'] ?? 0));
    foreach ($h['audit'] as &$a) { $a['details'] = json_decode((string)$a['details'], true) ?: []; }
    unset($a);
    echo json_encode($h, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Polecenie zwrotu (wydruk) ───────────────────────────────────────────────
if (!empty($_GET['refund_order'])) {
    $o = db_one("SELECT o.*, cl.name AS participant_name FROM overpayment_transactions o JOIN k30_clients cl ON cl.id=o.participant_id
                  WHERE o.id=? AND o.status='refunded'", [(int)$_GET['refund_order']]);
    if (!$o) { http_response_code(404); die('Nie znaleziono zwrotu.'); }
    $org  = (string)org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $acct = trim(chunk_split(preg_replace('/^PL/i', '', (string)$o['refund_account']), 4, ' '));
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Polecenie zwrotu nadpłaty #<?= (int)$o['id'] ?></title>
    <style>body{font:14px/1.5 system-ui,sans-serif;color:#1f2937;max-width:720px;margin:32px auto;padding:0 16px}h1{font-size:19px}
    table{width:100%;border-collapse:collapse;margin:14px 0}td{padding:7px 9px;border:1px solid #d1d5db}td:first-child{width:38%;color:#6b7280}
    .sig{display:flex;justify-content:space-between;margin-top:56px;font-size:12px;color:#6b7280}.sig div{border-top:1px solid #9ca3af;padding-top:4px;width:42%;text-align:center}
    @media print{button{display:none}}</style></head><body>
    <button onclick="window.print()" style="float:right">Drukuj</button>
    <div style="color:#6b7280;font-size:12px"><?= h($org) ?></div>
    <h1>Polecenie zwrotu nadpłaty nr ZN/<?= (int)$o['id'] ?>/<?= h(date('Y', strtotime((string)$o['disposed_at']))) ?></h1>
    <table>
      <tr><td>Uczestnik</td><td><strong><?= h($o['participant_name']) ?></strong></td></tr>
      <tr><td>Kwota zwrotu</td><td><strong><?= h(ti_op_fmt((float)$o['amount'])) ?></strong></td></tr>
      <tr><td>Rachunek odbiorcy</td><td style="font-family:ui-monospace,monospace"><?= h(($o['refund_account'] && stripos($o['refund_account'], 'PL') === 0 ? 'PL ' : '') . $acct) ?></td></tr>
      <tr><td>Tytuł przelewu</td><td><?= h($o['refund_title']) ?></td></tr>
      <tr><td>Źródło nadpłaty</td><td><?= h(ti_op_bucket_label((int)$o['course_id'])) ?> · <?= h(TI_OP_SOURCES[$o['source_type']] ?? $o['source_type']) ?></td></tr>
      <tr><td>Zlecił(a) / data</td><td><?= h($o['disposed_by']) ?>, <?= h(date('d.m.Y H:i', strtotime((string)$o['disposed_at']))) ?></td></tr>
      <?php if (trim((string)$o['notes']) !== ''): ?><tr><td>Uwagi</td><td><?= h($o['notes']) ?></td></tr><?php endif; ?>
    </table>
    <div class="sig"><div>sporządził(a)</div><div>zatwierdził(a) — księgowość</div></div>
    </body></html><?php
    exit;
}

// ── Operacje (POST, PRG) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $gr  = ti_op_parse_amount((string)($_POST['amount'] ?? ''));
    $nt  = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
    $res = null;
    if ($op === 'detect') {
        $r = ti_op_detect_all($by, $uid);
        flash_set('success', "Uzgodniono rejestr z księgą ({$r['participants']} kont): zmian {$r['changes']}.");
    } elseif (in_array($op, ['refund', 'settle', 'transfer', 'register'], true) && $gr === null) {
        flash_set('danger', 'Podaj poprawną kwotę (np. 120,50).');
    } elseif ($op === 'refund') {
        $res = ti_op_refund($id, $gr, (string)($_POST['refund_account'] ?? ''), (string)($_POST['refund_title'] ?? ''), $nt, $by, $uid);
        if (is_int($res)) { flash_set('success', 'Zwrot zlecony (ZN/' . $res . ') — wydrukuj polecenie zwrotu.'); $_SESSION['ti_op_refund_order'] = $res; }
    } elseif ($op === 'settle') {
        [$kind, $tid] = array_pad(explode(':', (string)($_POST['target'] ?? ''), 2), 2, '0');
        $res = ti_op_settle($id, $gr, $kind === 'b' ? (int)$tid : 0, $kind === 'c' ? (int)$tid : 0, $nt, $by, $uid);
        if (is_int($res)) flash_set('success', 'Nadpłata zaliczona.');
    } elseif ($op === 'transfer') {
        $res = ti_op_transfer($id, $gr, (int)($_POST['target_participant_id'] ?? 0), $nt, $by, $uid);
        if (is_int($res)) flash_set('success', 'Nadpłata przeksięgowana na konto innego uczestnika.');
    } elseif ($op === 'register') {
        $res = ti_op_register_manual((int)($_POST['participant_id'] ?? 0), (int)($_POST['course_id'] ?? 0), $gr, $nt, $by, $uid);
        if (is_int($res)) flash_set('success', 'Nadpłata zarejestrowana.');
    }
    if (is_string($res)) flash_set('danger', $res);
    header('Location: overpayments.php'); exit;
}

// ── Dane widoku ─────────────────────────────────────────────────────────────
$list    = ti_op_list();
$summary = ti_op_summary();
$flash   = flash_get();
$pl_m    = [1=>'sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
$per     = fn($m, $y) => $m ? (($pl_m[(int)$m] ?? $m) . ' ' . $y) : '';

// Opcje rozliczenia per uczestnik z nadpłatą do dyspozycji: nieopłacone rozliczenia i grupy
$opts = [];
foreach (array_unique(array_map(fn($r) => (int)$r['participant_id'], array_filter($list, fn($r) => $r['status'] === 'available'))) as $pid) {
    $al = ti_client_allocation($pid); $paid = [];
    foreach ($al['rows'] as $r) $paid[$r['id']] = $r['paid'];
    $bills = [];
    foreach (db_all("SELECT id, month, year, course_id, amount, adjustment, invoice_no FROM k30_ti_billing WHERE client_id=? AND status='issued' ORDER BY year, month", [$pid]) as $b) {
        $debt = round((float)$b['amount'] + (float)$b['adjustment'] - ($paid[(int)$b['id']] ?? 0), 2);
        if ($debt > 0.005) $bills[] = ['v' => 'b:' . $b['id'], 'course' => (int)$b['course_id'], 'debt' => $debt,
            'label' => $per($b['month'], $b['year']) . ' · ' . ti_op_bucket_label((int)$b['course_id']) . ' — do zapłaty ' . ti_op_fmt($debt) . ($b['invoice_no'] ? ' · FV ' . $b['invoice_no'] : '')];
    }
    $groups = [];
    foreach (db_all("SELECT DISTINCT e.course_id, c.name FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id WHERE e.client_id=? ORDER BY c.name", [$pid]) as $g)
        $groups[] = ['v' => 'c:' . $g['course_id'], 'course' => (int)$g['course_id'], 'label' => $g['name'] . ' — przyszłe należności'];
    $opts[$pid] = ['bills' => $bills, 'groups' => $groups];
}
$participants = db_all("SELECT DISTINCT cl.id, cl.name FROM k30_clients cl JOIN k30_ti_enrollments e ON e.client_id=cl.id ORDER BY cl.name COLLATE NOCASE");

// Nadpłata z księgi jeszcze niezarejestrowana (do ręcznej rejestracji) — z migawek sald
$unreg = db_all("SELECT v.participant_id, cl.name, v.overpayment_amount, v.registered_amount FROM virtual_account_balances v
                  JOIN k30_clients cl ON cl.id=v.participant_id WHERE v.overpayment_amount - v.registered_amount > 0.005 ORDER BY cl.name");

$rows = array_map(fn($r) => [
    'id' => (int)$r['id'], 'pid' => (int)$r['participant_id'], 'name' => (string)$r['participant_name'],
    'bucket' => ti_op_bucket_label((int)$r['course_id']), 'course' => (int)$r['course_id'],
    'amount' => (float)$r['amount'], 'status' => (string)$r['status'], 'status_l' => TI_OP_STATUSES[$r['status']] ?? $r['status'],
    'source' => TI_OP_SOURCES[$r['source_type']] ?? $r['source_type'],
    'fvat' => $r['b_invoice_no'] ? 'FV ' . $r['b_invoice_no'] . ' (' . $per($r['b_month'], $r['b_year']) . ')' : ($r['b_month'] ? 'rozl. ' . $per($r['b_month'], $r['b_year']) : ''),
    'against' => $r['status'] === 'settled' ? ($r['sb_month'] ? 'na ' . $per($r['sb_month'], $r['sb_year']) . ($r['sb_invoice_no'] ? ' · FV ' . $r['sb_invoice_no'] : '') : ($r['target_course_id'] ? 'na grupę: ' . ti_op_bucket_label((int)$r['target_course_id']) : ''))
               : ($r['status'] === 'transferred' ? 'na konto: ' . $r['target_name'] : ($r['status'] === 'refunded' ? 'na rach. …' . substr((string)$r['refund_account'], -4) : '')),
    'notes' => (string)$r['notes'], 'created' => date('d.m.Y', strtotime((string)$r['created_at'])), 'by' => (string)$r['created_by'],
    'disposed' => $r['disposed_at'] ? date('d.m.Y H:i', strtotime((string)$r['disposed_at'])) . ' · ' . $r['disposed_by'] : '',
    'parent' => $r['parent_id'] ? (int)$r['parent_id'] : null,
], $list);
$courses = [];
foreach ($rows as $r) $courses[$r['bucket']] = true;
ksort($courses);
?><!doctype html>
<html lang="pl" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nadpłaty uczestników — TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  /* Klasy przełączane przez Alpine (:class) — muszą być tu, patrz nagłówek pliku */
  .st-available   { @apply bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200; }
  .st-refunded    { @apply bg-sky-50 text-sky-800 ring-1 ring-sky-200; }
  .st-settled     { @apply bg-violet-50 text-violet-800 ring-1 ring-violet-200; }
  .st-transferred { @apply bg-amber-50 text-amber-800 ring-1 ring-amber-200; }
  .tab-on  { @apply bg-navy-700 text-white shadow-sm; }
  .tab-off { @apply bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50; }
  .au-row  { @apply border-l-2 border-slate-200 pl-3 py-1; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased" x-data="opApp()" @keydown.escape.window="close()">

<header class="bg-navy-700 text-white">
  <div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap items-center gap-3">
    <a href="billing.php" class="text-white/70 hover:text-white text-sm"><i class="bi bi-arrow-left"></i> Rozliczenia</a>
    <h1 class="text-lg font-semibold">Nadpłaty z kont wirtualnych uczestników</h1>
    <div class="ml-auto flex gap-2">
      <button type="button" @click="openRegister()" class="rounded-md bg-white/10 px-3 py-1.5 text-sm hover:bg-white/20"><i class="bi bi-plus-lg"></i> Zarejestruj ręcznie</button>
      <form method="post" onsubmit="return confirm('Uzgodnić rejestr nadpłat z księgą wszystkich kont?')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_op" value="detect">
        <button class="rounded-md bg-amber-400 px-3 py-1.5 text-sm font-medium text-navy-700 hover:bg-amber-300"><i class="bi bi-search"></i> Wykryj nadpłaty</button>
      </form>
    </div>
  </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-5 space-y-5">
  <?php if ($flash): ?>
  <div role="status" class="rounded-lg px-4 py-3 text-sm <?= $flash['type'] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>">
    <?= h((string)$flash['msg']) ?>
    <?php if (!empty($_SESSION['ti_op_refund_order'])): $ro = (int)$_SESSION['ti_op_refund_order']; unset($_SESSION['ti_op_refund_order']); ?>
    <a class="ml-2 font-medium underline" target="_blank" rel="noopener" href="overpayments.php?refund_order=<?= $ro ?>">Polecenie zwrotu ZN/<?= $ro ?></a>
    <?php endif; ?></div>
  <?php endif; ?>

  <!-- Podsumowanie sald -->
  <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" aria-label="Podsumowanie">
    <?php foreach ([
        ['Do dyspozycji', $summary['by_status']['available'], 'text-emerald-700', 'bi-piggy-bank'],
        ['Zwrócone', $summary['by_status']['refunded'], 'text-sky-700', 'bi-bank'],
        ['Zaliczone', $summary['by_status']['settled'], 'text-violet-700', 'bi-receipt'],
        ['Przeksięgowane', $summary['by_status']['transferred'], 'text-amber-700', 'bi-arrow-left-right'],
        ['Nadpłata wg księgi', $summary['ledger_overpayment'], 'text-slate-800', 'bi-journal-text'],
        ['Niedopłaty kont', $summary['debt'], 'text-red-700', 'bi-exclamation-triangle'],
    ] as [$l, $v, $c, $i]): ?>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center gap-2 text-xs text-slate-500"><i class="bi <?= $i ?>"></i><?= h($l) ?></div>
      <div class="mt-1 text-lg font-semibold <?= $c ?>"><?= h(ti_op_fmt((float)$v)) ?></div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php if ($summary['unreconciled'] > 0): ?>
  <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
    <?= (int)$summary['unreconciled'] ?> kont ma nadpłatę w księdze różną od zarejestrowanej — kliknij „Wykryj nadpłaty”, żeby uzgodnić.</p>
  <?php endif; ?>

  <!-- Filtry -->
  <section class="flex flex-wrap items-center gap-2">
    <template x-for="t in tabs" :key="t.k">
      <button type="button" @click="status = t.k" :class="status === t.k ? 'tab-on' : 'tab-off'"
              class="rounded-full px-3 py-1 text-sm" x-text="t.l + ' (' + count(t.k) + ')'"></button>
    </template>
    <input type="search" x-model="q" placeholder="Szukaj uczestnika…" aria-label="Szukaj uczestnika"
           class="ml-auto w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-navy-600 focus:outline-none">
    <select x-model="bucket" aria-label="Grupa" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
      <option value="">Wszystkie grupy</option>
      <?php foreach (array_keys($courses) as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
    </select>
  </section>

  <!-- Tabela nadpłat -->
  <section class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Uczestnik</th><th class="px-4 py-2">Koszyk</th><th class="px-4 py-2">Źródło / FVAT</th>
            <th class="px-4 py-2 text-right">Kwota</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Rozliczenie</th><th class="px-4 py-2"></th></tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <template x-for="r in filtered()" :key="r.id">
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-2 text-slate-400" x-text="r.id"></td>
            <td class="px-4 py-2 font-medium" x-text="r.name"></td>
            <td class="px-4 py-2" x-text="r.bucket"></td>
            <td class="px-4 py-2 text-slate-600"><span x-text="r.source"></span><span x-show="r.fvat" class="block text-xs text-slate-500" x-text="r.fvat"></span></td>
            <td class="px-4 py-2 text-right font-semibold tabular-nums" x-text="zl(r.amount)"></td>
            <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs" :class="'st-' + r.status" x-text="r.status_l"></span></td>
            <td class="px-4 py-2 text-xs text-slate-600"><span x-text="r.against"></span><span x-show="r.disposed" class="block text-slate-400" x-text="r.disposed"></span></td>
            <td class="px-4 py-2 whitespace-nowrap text-right">
              <button type="button" x-show="r.status === 'available'" @click="openSettle(r)"
                      class="rounded-md bg-navy-700 px-2.5 py-1 text-xs text-white hover:bg-navy-600">Rozlicz</button>
              <a x-show="r.status === 'refunded'" :href="'overpayments.php?refund_order=' + r.id" target="_blank" rel="noopener"
                 class="rounded-md px-2 py-1 text-xs text-sky-700 ring-1 ring-sky-200 hover:bg-sky-50">Polecenie zwrotu</a>
              <button type="button" @click="openHistory(r)" class="rounded-md px-2 py-1 text-xs text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50">Historia</button>
            </td>
          </tr>
        </template>
        <tr x-show="filtered().length === 0"><td colspan="8" class="px-4 py-6 text-center text-slate-500">Brak nadpłat dla wybranych filtrów.</td></tr>
      </tbody>
    </table>
  </section>
  <p class="text-xs text-slate-500">Nadpłata jest liczona z księgi konta (wpłaty − należności). Każde rozliczenie zapisuje się w księdze i w dzienniku audytu w jednej transakcji;
    nadpłatę zużytą przez księgę na nowe należności „Wykryj nadpłaty” oznacza jako zaliczoną automatycznie.</p>
</main>

<!-- Modal: rozliczenie nadpłaty -->
<div x-show="modal === 'settle'" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center" @click.self="close()">
  <div class="w-full max-w-lg rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="st-title">
    <div class="flex items-center border-b border-slate-200 px-5 py-3">
      <h2 id="st-title" class="font-semibold">Rozlicz nadpłatę <span class="text-slate-400" x-text="'#' + (cur && cur.id)"></span></h2>
      <button type="button" @click="close()" class="ml-auto text-slate-400 hover:text-slate-600" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="px-5 py-4 space-y-4" x-show="cur">
      <p class="text-sm text-slate-600"><strong x-text="cur && cur.name"></strong> · <span x-text="cur && cur.bucket"></span> ·
        do dyspozycji <strong class="text-emerald-700" x-text="cur && zl(cur.amount)"></strong></p>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
        <template x-for="m in modes" :key="m.k">
          <button type="button" @click="mode = m.k" :class="mode === m.k ? 'tab-on' : 'tab-off'" class="flex-1 rounded-md px-2 py-1.5" x-text="m.l"></button>
        </template>
      </div>
      <form method="post" class="space-y-3" @submit="confirmSubmit($event)">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" :value="mode">
        <input type="hidden" name="id" :value="cur && cur.id">
        <label class="block text-sm">Kwota (zł)
          <input name="amount" x-model="amount" inputmode="decimal" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
          <span class="text-xs text-slate-500">Mniejsza kwota rozliczy część — reszta zostaje do dyspozycji.</span></label>

        <div x-show="mode === 'refund'" class="space-y-3">
          <label class="block text-sm">Rachunek uczestnika (26 cyfr)
            <input name="refund_account" :required="mode === 'refund'" placeholder="PL00 0000 0000 0000 0000 0000 0000" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm"></label>
          <label class="block text-sm">Tytuł przelewu
            <input name="refund_title" :required="mode === 'refund'" :value="cur ? 'Zwrot nadpłaty — ' + cur.name : ''" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></label>
        </div>
        <div x-show="mode === 'settle'">
          <label class="block text-sm">Zalicz na
            <select name="target" :required="mode === 'settle'" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <template x-for="o in settleOptions()" :key="o.v"><option :value="o.v" x-text="o.label"></option></template>
            </select></label>
          <p x-show="settleOptions().length === 0" class="mt-1 text-xs text-amber-700">Brak nieopłaconych rozliczeń ani innych grup — nadpłata tego koszyka i tak pokryje przyszłe należności automatycznie.</p>
        </div>
        <div x-show="mode === 'transfer'">
          <label class="block text-sm">Na konto uczestnika
            <input type="search" x-model="pq" placeholder="Szukaj…" class="mt-1 w-full rounded-t-md border border-slate-300 px-3 py-1.5 text-sm">
            <select name="target_participant_id" :required="mode === 'transfer'" size="5" class="w-full rounded-b-md border border-t-0 border-slate-300 px-2 py-1 text-sm">
              <template x-for="p in partFiltered()" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
            </select></label>
        </div>
        <label class="block text-sm"><span x-text="mode === 'transfer' ? 'Uzasadnienie (wymagane)' : 'Uwagi'"></span>
          <input name="notes" :required="mode === 'transfer'" maxlength="500" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></label>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="close()" class="rounded-md px-3 py-2 text-sm ring-1 ring-slate-300">Anuluj</button>
          <button class="rounded-md bg-navy-700 px-4 py-2 text-sm font-medium text-white hover:bg-navy-600" x-text="modes.find(m => m.k === mode).btn"></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: ręczna rejestracja -->
<div x-show="modal === 'register'" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center" @click.self="close()">
  <div class="w-full max-w-lg rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="rg-title">
    <div class="flex items-center border-b border-slate-200 px-5 py-3">
      <h2 id="rg-title" class="font-semibold">Zarejestruj nadpłatę ręcznie</h2>
      <button type="button" @click="close()" class="ml-auto text-slate-400 hover:text-slate-600" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="post" class="px-5 py-4 space-y-3">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_op" value="register">
      <?php if ($unreg): ?>
      <p class="text-xs text-slate-500">Konta z nadpłatą w księdze, która nie jest jeszcze w rejestrze:</p>
      <ul class="max-h-32 overflow-y-auto text-sm">
        <?php foreach ($unreg as $u): ?><li><?= h($u['name']) ?> — <?= h(ti_op_fmt((float)$u['overpayment_amount'] - (float)$u['registered_amount'])) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <label class="block text-sm">Uczestnik
        <select name="participant_id" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
          <?php foreach ($participants as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="block text-sm">Koszyk (id grupy; 0 = konto ogólne)
        <input name="course_id" type="number" min="0" value="0" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></label>
      <label class="block text-sm">Kwota (zł)<input name="amount" inputmode="decimal" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></label>
      <label class="block text-sm">Uzasadnienie (np. nr korekty FVAT)<input name="notes" required minlength="5" maxlength="500" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></label>
      <p class="text-xs text-slate-500">Rejestracja nie tworzy pieniędzy — kwota nie może przekroczyć nadpłaty z księgi. Nową wpłatę dodaj w Rozliczeniach.</p>
      <div class="flex justify-end gap-2"><button type="button" @click="close()" class="rounded-md px-3 py-2 text-sm ring-1 ring-slate-300">Anuluj</button>
        <button class="rounded-md bg-navy-700 px-4 py-2 text-sm font-medium text-white">Zarejestruj</button></div>
    </form>
  </div>
</div>

<!-- Modal: historia (ścieżka audytu) -->
<div x-show="modal === 'history'" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center" @click.self="close()">
  <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="hs-title">
    <div class="flex items-center border-b border-slate-200 px-5 py-3">
      <h2 id="hs-title" class="font-semibold">Historia nadpłaty <span class="text-slate-400" x-text="'#' + (cur && cur.id)"></span></h2>
      <button type="button" @click="close()" class="ml-auto text-slate-400 hover:text-slate-600" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto px-5 py-4 space-y-4 text-sm">
      <p x-show="!hist" class="text-slate-500">Wczytywanie…</p>
      <template x-if="hist">
        <div class="space-y-4">
          <div>
            <h3 class="mb-1 text-xs font-semibold uppercase text-slate-500">Wpisy rejestru (od powstania)</h3>
            <template x-for="h in hist.rows" :key="h.id">
              <div class="au-row">
                <span class="font-medium" x-text="'#' + h.id"></span>
                <span x-show="h.parent_id" class="text-slate-400" x-text="'(z #' + h.parent_id + ')'"></span>
                · <span x-text="h.participant_name"></span> · <span x-text="h.bucket"></span> ·
                <strong x-text="zl(+h.amount)"></strong>
                <span class="ml-1 rounded-full px-2 py-0.5 text-xs" :class="'st-' + h.status" x-text="statusL[h.status] || h.status"></span>
                <div class="text-xs text-slate-500" x-text="h.created_at + ' · ' + (h.created_by || '') + (h.notes ? ' · ' + h.notes : '')"></div>
              </div>
            </template>
          </div>
          <div>
            <h3 class="mb-1 text-xs font-semibold uppercase text-slate-500">Dziennik audytu</h3>
            <template x-for="a in hist.audit" :key="a.id">
              <div class="au-row">
                <span class="font-medium" x-text="actionL[a.action] || a.action"></span>
                <span class="text-slate-500" x-text="' · ' + a.created_at + (a.ip_address ? ' · IP ' + a.ip_address : '')"></span>
                <div class="text-xs text-slate-500" x-text="auditText(a.details)"></div>
              </div>
            </template>
            <p x-show="hist.audit.length === 0" class="text-slate-500">Brak wpisów.</p>
          </div>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function opApp() {
  return {
    rows: <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
    opts: <?= json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
    parts: <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name']], $participants), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
    statusL: <?= json_encode(TI_OP_STATUSES, JSON_UNESCAPED_UNICODE) ?>,
    actionL: { 'overpayments.detected': 'Wykryto nadpłatę', 'overpayments.registered': 'Zarejestrowano ręcznie', 'overpayments.refunded': 'Zwrot na rachunek',
               'overpayments.settled': 'Zaliczenie', 'overpayments.transferred': 'Przeksięgowanie', 'overpayments.auto_settled': 'Zaliczono automatycznie' },
    tabs: [{ k: '', l: 'Wszystkie' }, { k: 'available', l: 'Do dyspozycji' }, { k: 'refunded', l: 'Zwrócone' }, { k: 'settled', l: 'Zaliczone' }, { k: 'transferred', l: 'Przeksięgowane' }],
    modes: [{ k: 'refund', l: 'Zwrot', btn: 'Zleć zwrot' }, { k: 'settle', l: 'Zaliczenie', btn: 'Zalicz' }, { k: 'transfer', l: 'Przeksięgowanie', btn: 'Przeksięguj' }],
    status: 'available', q: '', bucket: '', modal: null, cur: null, mode: 'refund', amount: '', pq: '', hist: null,
    zl(v) { return Number(v).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'; },
    match(r) { const q = this.q.trim().toLowerCase(); return (!q || r.name.toLowerCase().includes(q)) && (!this.bucket || r.bucket === this.bucket); },
    filtered() { return this.rows.filter(r => (!this.status || r.status === this.status) && this.match(r)); },
    count(k) { return this.rows.filter(r => (!k || r.status === k) && this.match(r)).length; },
    settleOptions() {
      if (!this.cur) return [];
      const o = this.opts[this.cur.pid] || { bills: [], groups: [] };
      return o.bills.concat(o.groups).filter(x => x.course !== this.cur.course);
    },
    partFiltered() { const q = this.pq.trim().toLowerCase(); return this.parts.filter(p => this.cur && p.id !== this.cur.pid && (!q || p.name.toLowerCase().includes(q))).slice(0, 200); },
    openSettle(r) { this.cur = r; this.mode = 'refund'; this.amount = r.amount.toFixed(2).replace('.', ','); this.pq = ''; this.modal = 'settle'; },
    openRegister() { this.modal = 'register'; },
    async openHistory(r) {
      this.cur = r; this.hist = null; this.modal = 'history';
      try { this.hist = await (await fetch('overpayments.php?json=history&id=' + r.id, { credentials: 'same-origin' })).json(); }
      catch (e) { this.hist = { rows: [], audit: [] }; }
    },
    auditText(d) {
      const p = [];
      if (d.amount !== undefined) p.push(this.zl(d.amount));
      if (d.title) p.push('tytuł: ' + d.title);
      if (d.account_last4) p.push('rach. …' + d.account_last4);
      if (d.target_billing_id) p.push('rozliczenie #' + d.target_billing_id);
      if (d.target_participant_id) p.push('uczestnik #' + d.target_participant_id);
      if (d.notes) p.push(d.notes);
      return p.join(' · ');
    },
    confirmSubmit(e) {
      const m = this.modes.find(x => x.k === this.mode);
      if (!confirm(m.btn + ': ' + this.amount + ' zł — ' + (this.cur ? this.cur.name : '') + '?')) { e.preventDefault(); return false; }
      return true;
    },
    close() { this.modal = null; },
  };
}
</script>
</body>
</html>
