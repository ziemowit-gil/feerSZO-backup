<?php
/**
 * karty30/ti/dydaktyk/pricing.php — Cenniki i rabaty zależne od typu zajęć (kierownik TI).
 *
 * Zakładki (Alpine): Symulator (kalkulacja na żywo, statusy uczestnika, „Zastosuj
 * do zapisu”), Typy zajęć, Reguły rabatowe (kreator z polami zależnymi od warunku),
 * Grupy i zapisy (typ zajęć grupy, cena z cennika vs stawka zapisu, zastosowanie /
 * nadpisanie z uzasadnieniem), Historia (calculated_prices_log + audyt pricing.*).
 * Tailwind (CDN) + Alpine.js; klasy przełączane przez :class są w <style type="text/tailwindcss">
 * (Tailwind CDN nie kompiluje klas dodanych po starcie strony).
 * Logika: modules/ti_pricing/logic/pricingEngine.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_pricing/logic/pricingEngine.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }
ti_pricing_migrate();
$by  = (string)($me['name'] ?? '');
$uid = (int)($me['user_id'] ?? 0) ?: null;

// ── JSON: kalkulacja (tylko odczyt — symulator) ─────────────────────────────
if (($_GET['json'] ?? '') === 'calc') {
    header('Content-Type: application/json; charset=utf-8');
    $ov = [];
    if (isset($_GET['ov']) && $_GET['ov'] === '1') {
        $ov['statuses']   = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['statuses'] ?? '')))));
        $ov['groups']     = max(1, min(99, (int)($_GET['groups'] ?? 1)));
        $ov['credit']     = max(0, (float)str_replace(',', '.', (string)($_GET['credit'] ?? '0')));
        $ov['start_date'] = ti_pricing_date($_GET['start_date'] ?? '');
    }
    echo json_encode(TiPricingEngine::calculate([
        'lesson_type_id' => (int)($_GET['type'] ?? 0), 'client_id' => (int)($_GET['client'] ?? 0) ?: null,
        'course_id' => (int)($_GET['course'] ?? 0) ?: null, 'date' => (string)($_GET['date'] ?? ''), 'profile' => $ov,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Operacje (POST, PRG) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $back = (string)($_POST['_tab'] ?? 'sym');
    $res = null; $ok = null;
    switch ($op) {
        case 'save_type':  $res = ti_pricing_save_type($_POST, $by, $uid); $ok = 'Typ zajęć zapisany.'; break;
        case 'save_rule':  $res = ti_pricing_save_rule($_POST, $by, $uid); $ok = 'Reguła zapisana.'; break;
        case 'delete_rule': $res = ti_pricing_delete_rule((int)($_POST['id'] ?? 0), $by, $uid) ?? 0; $ok = 'Reguła usunięta.'; break;
        case 'course_types':
            $res = ti_pricing_set_course_types((int)($_POST['course_id'] ?? 0), (int)($_POST['lesson_type_id'] ?? 0) ?: null,
                                               (int)($_POST['online_lesson_type_id'] ?? 0) ?: null, $by, $uid) ?? 0;
            $ok = 'Typy zajęć grupy zapisane.'; break;
        case 'statuses':
            $res = ti_pricing_set_participant_status((int)($_POST['client_id'] ?? 0), (array)($_POST['statuses'] ?? []), $by, $uid) ?? 0;
            $ok = 'Statusy uczestnika zapisane.'; break;
        case 'apply':
            $ovr = [];
            foreach (['stacjonarna', 'online'] as $m) {
                $raw = trim((string)($_POST['override_' . $m] ?? ''));
                if ($raw !== '') { $v = ti_pricing_num($raw); if ($v === null) { $res = 'Nadpisana cena musi być liczbą ≥ 0.'; break 2; } $ovr[$m] = $v; }
            }
            $r = ti_pricing_apply_to_enrollment((int)($_POST['course_id'] ?? 0), (int)($_POST['client_id'] ?? 0), $by, $uid, $ovr, (string)($_POST['reason'] ?? ''));
            $res = $r['ok'] ? 0 : $r['msg']; $ok = $r['msg']; break;
    }
    if (is_string($res)) flash_set('danger', $res); elseif ($ok) flash_set('success', $ok);
    header('Location: pricing.php?tab=' . urlencode($back) . (!empty($_POST['_course']) ? '&course=' . (int)$_POST['_course'] : '')); exit;
}

// ── Dane widoku ─────────────────────────────────────────────────────────────
$flash   = flash_get();
$types   = db_all("SELECT * FROM lesson_types ORDER BY is_active DESC, name COLLATE NOCASE");
$rules   = db_all("SELECT r.*, t.name AS type_name FROM discount_rules r LEFT JOIN lesson_types t ON t.id=r.target_lesson_type_id ORDER BY r.is_active DESC, r.priority, r.id");
$courses = db_all("SELECT c.id, c.name, c.is_online, pct.lesson_type_id, pct.online_lesson_type_id,
                          (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id=c.id AND e.status='active') AS n
                     FROM k30_ti_courses c LEFT JOIN ti_pricing_course_types pct ON pct.course_id=c.id
                    WHERE c.status NOT IN ('archived','cancelled') ORDER BY c.name COLLATE NOCASE");
$parts   = db_all("SELECT cl.id, cl.name, COALESCE(ps.statuses,'') AS statuses FROM k30_clients cl
                     JOIN k30_ti_student_accounts a ON a.client_id=cl.id LEFT JOIN ti_pricing_participant_status ps ON ps.participant_id=cl.id
                    WHERE a.is_active=1 ORDER BY cl.name COLLATE NOCASE");
$enr_by_course = [];
foreach (db_all("SELECT course_id, client_id FROM k30_ti_enrollments WHERE status='active'") as $e) $enr_by_course[(int)$e['course_id']][] = (int)$e['client_id'];

// Grupy i zapisy: szczegóły jednej grupy (cena z cennika vs stawka zapisu)
$sel_course = (int)($_GET['course'] ?? 0);
$sel_rows = [];
if ($sel_course) {
    $ct = ti_pricing_course_types($sel_course);
    foreach (k30_ti_enrollments($sel_course) as $e) {
        if ($e['status'] !== 'active') continue;
        $row = ['client_id' => (int)$e['client_id'], 'name' => $e['client_name'], 'rate' => (float)$e['hourly_rate'], 'rate_on' => (float)($e['hourly_rate_online'] ?? 0),
                'calc' => null, 'calc_on' => null];
        if ($ct['lesson_type_id']) $row['calc'] = TiPricingEngine::calculate(['lesson_type_id' => (int)$ct['lesson_type_id'], 'client_id' => (int)$e['client_id'], 'course_id' => $sel_course]);
        if ($ct['online_lesson_type_id']) $row['calc_on'] = TiPricingEngine::calculate(['lesson_type_id' => (int)$ct['online_lesson_type_id'], 'client_id' => (int)$e['client_id'], 'course_id' => $sel_course]);
        $sel_rows[] = $row;
    }
}
$log = db_all("SELECT l.*, cl.name AS participant_name, t.name AS type_name, c.name AS course_name FROM calculated_prices_log l
                LEFT JOIN k30_clients cl ON cl.id=l.participant_id LEFT JOIN lesson_types t ON t.id=l.lesson_type_id
                LEFT JOIN k30_ti_courses c ON c.id=l.course_id ORDER BY l.id DESC LIMIT 200");
$audit = db_all("SELECT * FROM audit_logs WHERE action LIKE 'pricing.%' ORDER BY id DESC LIMIT 100");
$tab   = in_array($_GET['tab'] ?? '', ['sym', 'types', 'rules', 'groups', 'log'], true) ? $_GET['tab'] : 'sym';
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$csrf = h(csrf_token());
$fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
?><!doctype html>
<html lang="pl" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cenniki i rabaty — TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { navy: { 600: '#1d4c80', 700: '#10335c' } } } } };</script>
<style type="text/tailwindcss">
  .tab-on  { @apply border-navy-700 text-navy-700 font-semibold; }
  .tab-off { @apply border-transparent text-slate-500 hover:text-slate-700; }
  .pill-on { @apply bg-navy-700 text-white ring-navy-700; }
  .pill-off{ @apply bg-white text-slate-600 ring-slate-300 hover:bg-slate-50; }
  .inp { @apply w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-navy-600 focus:outline-none focus:ring-1 focus:ring-navy-600; }
  .lbl { @apply block text-xs font-medium text-slate-600 mb-1; }
  .btn { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium; }
  .btn-pri { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium bg-navy-700 text-white hover:bg-navy-600; }
  .btn-sec { @apply inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50; }
  .card { @apply rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200; }
  .step-ok   { @apply border-l-4 border-emerald-500 bg-emerald-50; }
  .step-skip { @apply border-l-4 border-slate-300 bg-slate-50 text-slate-500; }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased" x-data="prApp()" x-init="init()">

<header class="bg-navy-700 text-white">
  <div class="mx-auto max-w-7xl px-4 py-4 flex flex-wrap items-center gap-3">
    <a href="billing.php" class="text-white/70 hover:text-white text-sm"><i class="bi bi-arrow-left"></i> Rozliczenia</a>
    <h1 class="text-lg font-semibold">Cenniki i rabaty zajęć</h1>
    <span class="text-white/60 text-sm">cena = stawka godzinowa zapisu kursanta (zł/h)</span>
  </div>
  <nav class="mx-auto max-w-7xl px-4 flex gap-4 overflow-x-auto" aria-label="Zakładki">
    <?php foreach (['sym' => 'Symulator', 'types' => 'Typy zajęć', 'rules' => 'Reguły rabatowe', 'groups' => 'Grupy i zapisy', 'log' => 'Historia'] as $k => $l): ?>
    <a href="pricing.php?tab=<?= $k ?>" class="whitespace-nowrap border-b-2 pb-2 text-sm <?= $tab === $k ? 'border-amber-400 text-white font-semibold' : 'border-transparent text-white/70 hover:text-white' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="mx-auto max-w-7xl px-4 py-5 space-y-5">
  <?php if ($flash): ?>
  <div role="status" class="rounded-lg px-4 py-3 text-sm <?= $flash['type'] === 'danger' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>"><?= h((string)$flash['msg']) ?></div>
  <?php endif; ?>

<?php if ($tab === 'sym'): ?>
  <!-- ═══ Symulator ═══ -->
  <div class="grid gap-5 lg:grid-cols-5">
    <section class="card lg:col-span-2 space-y-3" aria-labelledby="sym-h">
      <h2 id="sym-h" class="font-semibold">Parametry</h2>
      <div><label class="lbl" for="s-course">Grupa (opcjonalnie — ustawia typ zajęć i datę startu)</label>
        <select id="s-course" class="inp" x-model="s.course" @change="onCourse()">
          <option value="">— bez grupy —</option>
          <template x-for="c in courses" :key="c.id"><option :value="c.id" x-text="c.name + (c.lesson_type_id ? '' : ' (bez typu)')"></option></template>
        </select></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="lbl" for="s-type">Typ zajęć</label>
          <select id="s-type" class="inp" x-model="s.type" @change="calc()">
            <option value="">— wybierz —</option>
            <template x-for="t in types" :key="t.id"><option :value="t.id" x-text="t.name + ' — ' + zl(t.base_price) + '/h'"></option></template>
          </select></div>
        <div><label class="lbl" for="s-date">Data kalkulacji</label><input id="s-date" type="date" class="inp" x-model="s.date" @change="calc()"></div>
      </div>
      <div><label class="lbl" for="s-part">Uczestnik (opcjonalnie — profil z bazy)</label>
        <input type="search" class="inp mb-1" placeholder="Szukaj…" x-model="pq" aria-label="Szukaj uczestnika">
        <select id="s-part" class="inp" size="5" x-model="s.client" @change="onClient()">
          <option value="">— bez uczestnika —</option>
          <template x-for="p in partsFiltered()" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
        </select></div>
      <div class="rounded-lg bg-slate-50 p-3 space-y-2">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="s.ov" @change="calc()"> Ręczny profil (zamiast danych uczestnika)</label>
        <fieldset :disabled="!s.ov" class="space-y-2" :class="s.ov ? '' : 'opacity-50'">
          <legend class="lbl">Statusy</legend>
          <div class="flex flex-wrap gap-1">
            <template x-for="(l, k) in statuses" :key="k">
              <button type="button" class="rounded-full px-2.5 py-1 text-xs ring-1" :class="s.st.includes(k) ? 'pill-on' : 'pill-off'" @click="toggleSt(k)" x-text="l"></button>
            </template>
          </div>
          <div class="grid grid-cols-3 gap-2">
            <div><label class="lbl" for="s-g">Liczba grup</label><input id="s-g" type="number" min="1" max="99" class="inp" x-model="s.groups" @input.debounce.300ms="calc()"></div>
            <div><label class="lbl" for="s-cr">Nadpłata (zł)</label><input id="s-cr" type="number" min="0" step="0.01" class="inp" x-model="s.credit" @input.debounce.300ms="calc()"></div>
            <div><label class="lbl" for="s-sd">Start grupy</label><input id="s-sd" type="date" class="inp" x-model="s.start" @change="calc()"></div>
          </div>
        </fieldset>
      </div>
    </section>

    <section class="card lg:col-span-3 space-y-3" aria-labelledby="res-h" aria-live="polite">
      <h2 id="res-h" class="font-semibold">Wynik</h2>
      <p x-show="!r" class="text-sm text-slate-500">Wybierz typ zajęć (albo grupę z przypisanym typem).</p>
      <template x-if="r && !r.ok"><p class="text-sm text-red-700" x-text="r.error"></p></template>
      <template x-if="r && r.ok">
        <div class="space-y-3">
          <div class="flex flex-wrap items-end gap-6">
            <div><div class="text-xs text-slate-500">Cena bazowa</div><div class="text-lg" x-text="zl(r.base) + '/h'"></div></div>
            <div><div class="text-xs text-slate-500">Cena końcowa</div><div class="text-3xl font-bold text-navy-700" x-text="zl(r.final) + '/h'"></div></div>
            <div x-show="r.base > r.final"><div class="text-xs text-slate-500">Rabat razem</div><div class="text-lg text-emerald-700" x-text="'−' + zl(r.base - r.final) + ' (' + Math.round((1 - r.final / r.base) * 100) + '%)'"></div></div>
          </div>
          <div class="text-xs text-slate-500">Profil: statusy <span x-text="r.profile.statuses.length ? r.profile.statuses.join(', ') : 'brak'"></span>
            · grup <span x-text="r.profile.groups"></span> · nadpłata <span x-text="zl(r.profile.credit)"></span>
            · start <span x-text="r.profile.start_date || '—'"></span></div>
          <ol class="space-y-1.5 text-sm">
            <template x-for="st in r.steps" :key="'a' + st.rule_id">
              <li class="rounded-md px-3 py-2 step-ok">
                <div class="flex flex-wrap justify-between gap-2"><span class="font-medium" x-text="st.name"></span>
                  <span class="tabular-nums" x-text="zl(st.before) + ' → ' + zl(st.after) + '  (−' + zl(st.amount) + ')'"></span></div>
                <div class="text-xs text-slate-600" x-text="(st.type === 'percentage' ? st.value + '%' : zl(st.value)) + ' · priorytet ' + st.priority + ' · ' + st.reason + (st.stackable ? '' : ' · nie łączy się')"></div>
              </li>
            </template>
            <template x-for="sk in r.skipped" :key="'s' + sk.rule_id">
              <li class="rounded-md px-3 py-1.5 text-xs step-skip"><span class="font-medium" x-text="sk.name"></span> — <span x-text="sk.why"></span></li>
            </template>
            <li x-show="!r.steps.length && !r.skipped.length" class="text-sm text-slate-500">Brak aktywnych reguł dla tego typu.</li>
          </ol>
          <!-- Zastosowanie do zapisu (uczestnik zapisany do wybranej grupy) -->
          <div x-show="canApply()" class="border-t border-slate-200 pt-3">
            <form method="post" class="flex flex-wrap items-end gap-2" @submit="if (!confirm('Ustawić stawkę zapisu kursanta z cennika?')) $event.preventDefault()">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="apply"><input type="hidden" name="_tab" value="sym">
              <input type="hidden" name="course_id" :value="s.course"><input type="hidden" name="client_id" :value="s.client">
              <button class="btn-pri"><i class="bi bi-check2-circle"></i> Zastosuj do zapisu kursanta</button>
              <span class="text-xs text-slate-500">ustawi stawkę stacjonarną (i online, jeśli grupa ma typ online) — z zapisem w historii</span>
            </form>
          </div>
        </div>
      </template>
      <!-- Statusy uczestnika (profil cenowy) -->
      <div x-show="s.client" class="border-t border-slate-200 pt-3">
        <form method="post" class="space-y-2">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="statuses"><input type="hidden" name="_tab" value="sym">
          <input type="hidden" name="client_id" :value="s.client">
          <div class="lbl">Statusy uczestnika w cenniku („kontynuacja” wykrywana automatycznie)</div>
          <div class="flex flex-wrap gap-3 text-sm">
            <template x-for="(l, k) in statuses" :key="'c' + k">
              <label x-show="k !== 'kontynuacja'" class="flex items-center gap-1"><input type="checkbox" name="statuses[]" :value="k" :checked="clientStatuses().includes(k)"> <span x-text="l"></span></label>
            </template>
          </div>
          <button class="btn-sec">Zapisz statusy</button>
        </form>
      </div>
    </section>
  </div>

<?php elseif ($tab === 'types'): ?>
  <!-- ═══ Typy zajęć ═══ -->
  <div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Nazwa</th><th class="pr-3">Slug</th><th class="pr-3">Tryb</th><th class="pr-3 text-right">Cena bazowa</th><th class="pr-3">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($types as $t): ?>
          <tr><td class="py-2 pr-3 font-medium"><?= h($t['name']) ?><?php if ($t['description']): ?><div class="text-xs text-slate-500"><?= h($t['description']) ?></div><?php endif; ?></td>
            <td class="pr-3 font-mono text-xs"><?= h($t['slug']) ?></td><td class="pr-3"><?= h(TI_PRICING_MODES[$t['mode']] ?? $t['mode']) ?></td>
            <td class="pr-3 text-right tabular-nums"><?= $fmt($t['base_price']) ?> zł/h</td>
            <td class="pr-3"><?= $t['is_active'] ? '<span class="text-emerald-700">aktywny</span>' : '<span class="text-slate-400">nieaktywny</span>' ?></td>
            <td class="text-right"><button type="button" class="btn-sec" @click='editType(<?= $J($t) ?>)'>Edytuj</button></td></tr>
        <?php endforeach; ?>
        <?php if (!$types): ?><tr><td colspan="6" class="py-6 text-center text-slate-500">Brak typów zajęć — dodaj pierwszy.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <section class="card">
      <h2 class="font-semibold mb-3" x-text="t.id ? 'Edycja typu zajęć' : 'Nowy typ zajęć'"></h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="save_type"><input type="hidden" name="_tab" value="types">
        <input type="hidden" name="id" :value="t.id">
        <div><label class="lbl" for="t-n">Nazwa</label><input id="t-n" name="name" class="inp" required maxlength="120" x-model="t.name" placeholder="np. Warsztat stacjonarny"></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="t-s">Slug (puste = z nazwy)</label><input id="t-s" name="slug" class="inp font-mono" pattern="[a-z0-9][a-z0-9-]*" x-model="t.slug"></div>
          <div><label class="lbl" for="t-m">Tryb</label><select id="t-m" name="mode" class="inp" x-model="t.mode">
            <?php foreach (TI_PRICING_MODES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div><label class="lbl" for="t-p">Cena bazowa (zł/h)</label><input id="t-p" name="base_price" class="inp" inputmode="decimal" required x-model="t.base_price"></div>
        <div><label class="lbl" for="t-d">Opis</label><textarea id="t-d" name="description" rows="2" class="inp" x-model="t.description"></textarea></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" x-model="t.is_active"> Aktywny</label>
        <div class="flex gap-2"><button class="btn-pri">Zapisz</button><button type="button" class="btn-sec" x-show="t.id" @click="editType(null)">Nowy</button></div>
      </form>
    </section>
  </div>

<?php elseif ($tab === 'rules'): ?>
  <!-- ═══ Reguły rabatowe ═══ -->
  <div class="grid gap-5 lg:grid-cols-3">
    <section class="card lg:col-span-2 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-2">Prio</th><th class="pr-3">Reguła</th><th class="pr-3">Rabat</th><th class="pr-3">Warunek</th><th class="pr-3">Typ zajęć</th><th class="pr-3">Okres</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($rules as $r): ?>
          <tr class="<?= $r['is_active'] ? '' : 'text-slate-400' ?>">
            <td class="py-2 pr-2 tabular-nums"><?= (int)$r['priority'] ?></td>
            <td class="pr-3 font-medium"><?= h($r['name']) ?><?= $r['stackable'] ? '' : ' <span class="rounded bg-amber-100 px-1.5 text-xs text-amber-800">nie łączy się</span>' ?><?= $r['is_active'] ? '' : ' <span class="text-xs">(wył.)</span>' ?></td>
            <td class="pr-3 whitespace-nowrap"><?= $r['discount_type'] === 'percentage' ? $fmt($r['discount_value']) . '%' : $fmt($r['discount_value']) . ' zł' ?></td>
            <td class="pr-3 text-xs"><?= h(TI_PRICING_CONDITIONS[$r['condition_type']] ?? $r['condition_type']) ?><?= $r['condition_value'] !== null && $r['condition_value'] !== '' ? ': <b>' . h($r['condition_value']) . '</b>' : '' ?></td>
            <td class="pr-3 text-xs"><?= h($r['type_name'] ?? 'wszystkie') ?></td>
            <td class="pr-3 text-xs whitespace-nowrap"><?= h(($r['date_from'] ?: '…') . ' – ' . ($r['date_to'] ?: '…')) ?></td>
            <td class="text-right whitespace-nowrap">
              <button type="button" class="btn-sec" @click='editRule(<?= $J($r) ?>)'>Edytuj</button>
              <form method="post" class="inline" onsubmit="return confirm('Usunąć regułę?')">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="delete_rule"><input type="hidden" name="_tab" value="rules">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn text-red-700 hover:bg-red-50" aria-label="Usuń regułę"><i class="bi bi-trash"></i></button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$rules): ?><tr><td colspan="7" class="py-6 text-center text-slate-500">Brak reguł rabatowych.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <p class="mt-3 text-xs text-slate-500">Kolejność: rosnąco po priorytecie. Rabat procentowy liczony od ceny po wcześniejszych rabatach. Reguła „nie łączy się” liczona jest od ceny bazowej i wygrywa, tylko gdy daje niższą cenę niż pozostałe razem — potem dalsze reguły są pomijane.</p>
    </section>
    <section class="card">
      <h2 class="font-semibold mb-3" x-text="ru.id ? 'Edycja reguły' : 'Nowa reguła'"></h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="save_rule"><input type="hidden" name="_tab" value="rules">
        <input type="hidden" name="id" :value="ru.id">
        <div><label class="lbl" for="r-n">Nazwa</label><input id="r-n" name="name" class="inp" required maxlength="160" x-model="ru.name" placeholder="np. 10% dla NGO na webinary"></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="r-dt">Rodzaj rabatu</label><select id="r-dt" name="discount_type" class="inp" x-model="ru.discount_type"><option value="percentage">Procentowy (%)</option><option value="fixed">Kwotowy (zł/h)</option></select></div>
          <div><label class="lbl" for="r-dv" x-text="ru.discount_type === 'percentage' ? 'Wartość (0–100 %)' : 'Wartość (zł/h)'"></label>
            <input id="r-dv" name="discount_value" class="inp" inputmode="decimal" required x-model="ru.discount_value"></div>
        </div>
        <div><label class="lbl" for="r-tt">Typ zajęć</label><select id="r-tt" name="target_lesson_type_id" class="inp" x-model="ru.target_lesson_type_id">
          <option value="">Wszystkie typy</option><template x-for="tt in types" :key="tt.id"><option :value="tt.id" x-text="tt.name"></option></template></select></div>
        <div><label class="lbl" for="r-ct">Warunek</label><select id="r-ct" name="condition_type" class="inp" x-model="ru.condition_type">
          <?php foreach (TI_PRICING_CONDITIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div x-show="ru.condition_type === 'participant_status'">
          <div class="lbl">Statusy (dowolny z zaznaczonych)</div>
          <div class="flex flex-wrap gap-1">
            <template x-for="(l, k) in statuses" :key="'r' + k">
              <button type="button" class="rounded-full px-2.5 py-1 text-xs ring-1" :class="ruSt().includes(k) ? 'pill-on' : 'pill-off'" @click="toggleRuSt(k)" x-text="l"></button>
            </template>
          </div>
        </div>
        <div x-show="['early_bird','bundle_quantity','virtual_account_overpayment'].includes(ru.condition_type)">
          <label class="lbl" for="r-cv" x-text="{early_bird: 'Minimum dni przed startem grupy', bundle_quantity: 'Minimalna liczba grup uczestnika', virtual_account_overpayment: 'Minimalna nadpłata na koncie (zł)'}[ru.condition_type] || ''"></label>
          <input id="r-cv" class="inp" inputmode="decimal" x-model="ru.condition_value">
        </div>
        <input type="hidden" name="condition_value" :value="ru.condition_value">
        <div class="grid grid-cols-2 gap-2">
          <div><label class="lbl" for="r-df">Od</label><input id="r-df" type="date" name="date_from" class="inp" x-model="ru.date_from"></div>
          <div><label class="lbl" for="r-dt2">Do</label><input id="r-dt2" type="date" name="date_to" class="inp" x-model="ru.date_to"></div>
        </div>
        <div class="grid grid-cols-2 gap-2 items-end">
          <div><label class="lbl" for="r-p">Priorytet (niższy = wcześniej)</label><input id="r-p" type="number" min="0" max="9999" name="priority" class="inp" x-model="ru.priority"></div>
          <div class="space-y-1 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="stackable" value="1" x-model="ru.stackable"> Łączy się z innymi</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" x-model="ru.is_active"> Aktywna</label>
          </div>
        </div>
        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600" x-text="ruPreview()"></p>
        <div class="flex gap-2"><button class="btn-pri">Zapisz regułę</button><button type="button" class="btn-sec" x-show="ru.id" @click="editRule(null)">Nowa</button></div>
      </form>
    </section>
  </div>

<?php elseif ($tab === 'groups'): ?>
  <!-- ═══ Grupy i zapisy ═══ -->
  <section class="card overflow-x-auto">
    <h2 class="font-semibold mb-2">Typ zajęć grupy</h2>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Grupa</th><th class="pr-3">Uczestn.</th><th class="pr-3">Typ (stacjonarne)</th><th class="pr-3">Typ dla lekcji online</th><th></th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($courses as $c): ?>
        <tr class="<?= $sel_course === (int)$c['id'] ? 'bg-amber-50' : '' ?>">
          <td class="py-2 pr-3 font-medium"><?= h($c['name']) ?><?= $c['is_online'] ? ' <span class="text-xs text-sky-700">online</span>' : '' ?></td>
          <td class="pr-3"><?= (int)$c['n'] ?></td>
          <td colspan="2" class="pr-3">
            <form method="post" class="flex flex-wrap gap-2 items-center">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="course_types"><input type="hidden" name="_tab" value="groups">
              <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="_course" value="<?= $sel_course ?>">
              <select name="lesson_type_id" class="inp !w-56" aria-label="Typ zajęć — <?= h($c['name']) ?>"><option value="">— brak —</option>
                <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$c['lesson_type_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select>
              <select name="online_lesson_type_id" class="inp !w-56" aria-label="Typ online — <?= h($c['name']) ?>"><option value="">— jak stacjonarne —</option>
                <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$c['online_lesson_type_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select>
              <button class="btn-sec">Zapisz</button>
            </form></td>
          <td class="text-right"><a class="btn-sec" href="pricing.php?tab=groups&amp;course=<?= (int)$c['id'] ?>#zapisy">Zapisy</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php if ($sel_course): $sc = array_values(array_filter($courses, fn($c) => (int)$c['id'] === $sel_course))[0] ?? null; ?>
  <section id="zapisy" class="card overflow-x-auto">
    <h2 class="font-semibold mb-1">Zapisy — <?= h($sc['name'] ?? ('#' . $sel_course)) ?></h2>
    <p class="text-xs text-slate-500 mb-3">Cena z cennika wyliczona dla profilu każdego uczestnika. „Zastosuj” ustawia stawkę zapisu; wpisana cena nadpisuje cennik (wymaga uzasadnienia, trafia do audytu).</p>
    <?php if (!$sel_rows): ?><p class="text-sm text-slate-500">Brak aktywnych uczestników.</p><?php endif; ?>
    <?php if ($sel_rows && empty($sc['lesson_type_id'])): ?><p class="text-sm text-amber-800">Najpierw przypisz grupie typ zajęć.</p><?php endif; ?>
    <?php if ($sel_rows && !empty($sc['lesson_type_id'])): ?>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Uczestnik</th><th class="pr-3 text-right">Stawka teraz</th><th class="pr-3 text-right">Z cennika</th><th class="pr-3">Rabaty</th><th class="pr-3">Zastosuj / nadpisz</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($sel_rows as $row): $c1 = $row['calc']; $c2 = $row['calc_on'];
        $diff = abs($c1['final'] - $row['rate']) > 0.005 || ($c2 && abs($c2['final'] - $row['rate_on']) > 0.005); ?>
        <tr class="<?= $diff ? 'bg-amber-50/60' : '' ?>">
          <td class="py-2 pr-3 font-medium"><?= h($row['name']) ?><div class="text-xs text-slate-500"><?= h(implode(', ', $c1['profile']['statuses']) ?: 'bez statusu') ?> · grup <?= (int)$c1['profile']['groups'] ?></div></td>
          <td class="pr-3 text-right tabular-nums"><?= $fmt($row['rate']) ?><?php if ($c2): ?><div class="text-xs text-slate-500">online <?= $fmt($row['rate_on']) ?></div><?php endif; ?></td>
          <td class="pr-3 text-right tabular-nums font-semibold"><?= $fmt($c1['final']) ?><?php if ($c2): ?><div class="text-xs font-normal text-slate-500">online <?= $fmt($c2['final']) ?></div><?php endif; ?></td>
          <td class="pr-3 text-xs"><?= h(implode(' · ', array_map(fn($s) => $s['name'] . ' −' . $fmt($s['amount']), $c1['steps'])) ?: '—') ?></td>
          <td class="pr-3">
            <form method="post" class="flex flex-wrap items-center gap-1" onsubmit="var o=this.override_stacjonarna.value||(this.override_online&&this.override_online.value); if(o && this.reason.value.trim().length<5){alert('Nadpisanie ceny wymaga uzasadnienia.');return false;} return confirm('Ustawić stawkę zapisu?')">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="_op" value="apply"><input type="hidden" name="_tab" value="groups">
              <input type="hidden" name="course_id" value="<?= $sel_course ?>"><input type="hidden" name="_course" value="<?= $sel_course ?>"><input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
              <input name="override_stacjonarna" class="inp !w-24" inputmode="decimal" placeholder="nadpisz" aria-label="Nadpisana stawka stacjonarna">
              <?php if ($c2): ?><input name="override_online" class="inp !w-24" inputmode="decimal" placeholder="online" aria-label="Nadpisana stawka online"><?php endif; ?>
              <input name="reason" class="inp !w-44" maxlength="300" placeholder="uzasadnienie (przy nadpisaniu)" aria-label="Uzasadnienie nadpisania">
              <button class="btn-pri">Zastosuj</button>
            </form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <?php endif; ?>

<?php else: ?>
  <!-- ═══ Historia ═══ -->
  <section class="card overflow-x-auto">
    <h2 class="font-semibold mb-2">Historia wyliczeń (zatwierdzone)</h2>
    <table class="min-w-full text-sm">
      <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Kiedy</th><th class="pr-3">Uczestnik</th><th class="pr-3">Grupa / typ</th><th class="pr-3 text-right">Baza</th><th class="pr-3">Kroki</th><th class="pr-3 text-right">Cena</th><th class="pr-3">Źródło</th></tr></thead>
      <tbody class="divide-y divide-slate-100">
      <?php foreach ($log as $l): $d = json_decode((string)$l['applied_discounts_json'], true) ?: []; ?>
        <tr><td class="py-2 pr-3 whitespace-nowrap text-xs"><?= h(date('d.m.Y H:i', strtotime((string)$l['created_at']))) ?><div class="text-slate-500"><?= h($l['created_by']) ?></div></td>
          <td class="pr-3"><?= h($l['participant_name'] ?? '—') ?></td>
          <td class="pr-3 text-xs"><?= h(($l['course_name'] ?? '—') . ' · ' . ($l['type_name'] ?? '?') . ' · ' . $l['mode']) ?></td>
          <td class="pr-3 text-right tabular-nums"><?= $fmt($l['base_price']) ?></td>
          <td class="pr-3 text-xs"><?= h(implode(' → ', array_map(fn($s) => $s['name'] . ' (' . $fmt($s['after']) . ')', $d['steps'] ?? [])) ?: '—') ?></td>
          <td class="pr-3 text-right tabular-nums font-semibold"><?= $fmt($l['final_price']) ?></td>
          <td class="pr-3 text-xs"><?= $l['source'] === 'override' ? '<span class="text-amber-700">nadpisano</span> — ' . h($l['note']) . ' (cennik ' . $fmt($d['engine_final'] ?? 0) . ')' : 'z cennika' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="7" class="py-6 text-center text-slate-500">Brak zatwierdzonych wyliczeń.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
  <section class="card overflow-x-auto">
    <h2 class="font-semibold mb-2">Dziennik audytu cennika</h2>
    <ul class="space-y-1 text-xs">
      <?php foreach ($audit as $a): $det = json_decode((string)$a['details'], true) ?: []; ?>
      <li class="border-l-2 border-slate-200 pl-3"><span class="font-medium"><?= h($a['action']) ?></span> · <?= h($a['created_at']) ?> · <?= h($det['by'] ?? '') ?> · IP <?= h((string)$a['ip_address']) ?>
        <span class="text-slate-500"><?= h(mb_strimwidth(json_encode(array_diff_key($det, ['by' => 1]), JSON_UNESCAPED_UNICODE), 0, 220, '…')) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$audit): ?><li class="text-slate-500">Brak wpisów.</li><?php endif; ?>
    </ul>
  </section>
<?php endif; ?>
</main>

<script>
function prApp() {
  const blankType = { id: '', name: '', slug: '', mode: 'dowolna', base_price: '', description: '', is_active: true };
  const blankRule = { id: '', name: '', discount_type: 'percentage', discount_value: '', target_lesson_type_id: '', condition_type: 'none',
                      condition_value: '', date_from: '', date_to: '', priority: 100, stackable: true, is_active: true };
  return {
    types: <?= $J(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'base_price' => (float)$t['base_price']], array_filter($types, fn($t) => $t['is_active']))) ?>,
    courses: <?= $J(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'lesson_type_id' => $c['lesson_type_id'] ? (int)$c['lesson_type_id'] : null], $courses)) ?>,
    parts: <?= $J(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'statuses' => $p['statuses']], $parts)) ?>,
    enr: <?= $J($enr_by_course) ?>,
    statuses: <?= $J(TI_PRICING_STATUSES) ?>,
    s: { course: '', type: '', client: '', date: new Date().toISOString().slice(0, 10), ov: false, st: [], groups: 1, credit: 0, start: '' },
    pq: '', r: null, t: { ...blankType }, ru: { ...blankRule }, seq: 0,
    init() {},
    zl(v) { return Number(v || 0).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'; },
    partsFiltered() { const q = this.pq.trim().toLowerCase(); return this.parts.filter(p => !q || p.name.toLowerCase().includes(q)).slice(0, 300); },
    clientStatuses() { const p = this.parts.find(x => String(x.id) === String(this.s.client)); return p && p.statuses ? p.statuses.split(',') : []; },
    toggleSt(k) { this.s.st = this.s.st.includes(k) ? this.s.st.filter(x => x !== k) : [...this.s.st, k]; this.calc(); },
    onCourse() { const c = this.courses.find(x => String(x.id) === String(this.s.course)); if (c && c.lesson_type_id) this.s.type = String(c.lesson_type_id); this.calc(); },
    onClient() { this.calc(); },
    canApply() { return this.r && this.r.ok && this.s.client && this.s.course && (this.enr[this.s.course] || []).includes(Number(this.s.client))
                  && (this.courses.find(x => String(x.id) === String(this.s.course)) || {}).lesson_type_id; },
    async calc() {
      if (!this.s.type) { this.r = null; return; }
      const q = new URLSearchParams({ json: 'calc', type: this.s.type, client: this.s.client, course: this.s.course, date: this.s.date });
      if (this.s.ov) { q.set('ov', '1'); q.set('statuses', this.s.st.join(',')); q.set('groups', this.s.groups); q.set('credit', this.s.credit); q.set('start_date', this.s.start); }
      const my = ++this.seq;
      try { const res = await (await fetch('pricing.php?' + q.toString(), { credentials: 'same-origin' })).json(); if (my === this.seq) this.r = res; }
      catch (e) { this.r = { ok: false, error: 'Błąd kalkulacji.' }; }
    },
    editType(x) { this.t = x ? { ...x, is_active: !!Number(x.is_active), description: x.description || '' } : { ...blankType }; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    editRule(x) {
      this.ru = x ? { ...x, target_lesson_type_id: x.target_lesson_type_id ? String(x.target_lesson_type_id) : '', condition_value: x.condition_value || '',
                      date_from: (x.date_from || '').slice(0, 10), date_to: (x.date_to || '').slice(0, 10), stackable: !!Number(x.stackable), is_active: !!Number(x.is_active) }
                  : { ...blankRule };
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    ruSt() { return (this.ru.condition_value || '').split(',').map(x => x.trim()).filter(Boolean); },
    toggleRuSt(k) { const a = this.ruSt(); this.ru.condition_value = (a.includes(k) ? a.filter(x => x !== k) : [...a, k]).join(','); },
    ruPreview() {
      const v = this.ru.discount_type === 'percentage' ? (this.ru.discount_value || 0) + '%' : this.zl(this.ru.discount_value) + '/h';
      const tt = this.types.find(x => String(x.id) === String(this.ru.target_lesson_type_id));
      const cond = { none: 'zawsze', participant_status: 'gdy uczestnik ma status: ' + (this.ruSt().map(k => this.statuses[k] || k).join(' lub ') || '—'),
        early_bird: 'gdy do startu grupy zostało co najmniej ' + (this.ru.condition_value || '?') + ' dni',
        bundle_quantity: 'gdy uczestnik jest zapisany do co najmniej ' + (this.ru.condition_value || '?') + ' grup',
        virtual_account_overpayment: 'gdy nadpłata na koncie wynosi co najmniej ' + this.zl(this.ru.condition_value) }[this.ru.condition_type];
      return 'Rabat ' + v + ' na ' + (tt ? '„' + tt.name + '”' : 'wszystkie typy zajęć') + ', ' + cond
        + (this.ru.date_from || this.ru.date_to ? ', w okresie ' + (this.ru.date_from || '…') + ' – ' + (this.ru.date_to || '…') : '')
        + (this.ru.stackable ? '.' : ' — nie łączy się z innymi rabatami.');
    },
  };
}
</script>
</body>
</html>
