<?php
/**
 * strategy/index.php — Moduł „Strategia Organizacji" (planowanie działań operacyjnych).
 *
 * Jeden plik = widok + API AJAX (wzorzec ?_ajax=1 + XHR POST jak w CRM).
 * Frontend: Tailwind CSS (CDN) + Alpine.js — tabela z filtrami w czasie
 * rzeczywistym, siatka miesięczna, drawer formularza, podsumowania zasobów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/strategy_org.php';

require_login();
if (!can_read('umowy') && !is_admin()) {
    flash_set('error', 'Brak dostępu do modułu Strategii.');
    header('Location: ' . APP_URL . '/portal.php'); exit;
}
strat_org_migrate();

$user     = current_user();
$can_edit = can_edit() || is_admin();
$is_admin = is_admin();

// ── API AJAX ──────────────────────────────────────────────────────────────────
if (($_GET['_ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (array $d): never {
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Tylko POST.');
        csrf_check();
        $action  = (string)($_POST['action'] ?? '');
        $payload = json_decode((string)($_POST['payload'] ?? '{}'), true) ?: [];

        // Odczyt — dostępny dla każdego z prawem wglądu
        if ($action === 'bootstrap') {
            $rok = (int)($payload['rok'] ?? (int)date('Y')) ?: (int)date('Y');
            $out([
                'ok'    => true,
                'dicts' => strat_org_dictionaries(),
                'plans' => strat_org_plans($rok),
                'years' => strat_org_years(),
                'users' => db_all("SELECT id, name FROM users ORDER BY name"),
            ]);
        }

        // Zapis — tylko z prawem edycji
        if (!$can_edit) throw new RuntimeException('Brak uprawnień do zapisu.');

        if ($action === 'plan_save') {
            $id = strat_org_save_plan($payload, (int)$user['id']);
            $out(['ok' => true, 'id' => $id]);
        }
        if ($action === 'plan_delete') {
            strat_org_delete_plan((int)($payload['id'] ?? 0));
            $out(['ok' => true]);
        }

        // Słowniki — tylko admin
        if (!$is_admin) throw new RuntimeException('Słowniki może zmieniać tylko administrator.');
        if ($action === 'dict_save') {
            $id = strat_org_dict_save((string)($payload['dict'] ?? ''), (array)($payload['data'] ?? []));
            $out(['ok' => true, 'id' => $id]);
        }
        if ($action === 'dict_delete') {
            strat_org_dict_delete((string)($payload['dict'] ?? ''), (int)($payload['id'] ?? 0));
            $out(['ok' => true]);
        }

        throw new RuntimeException('Nieznana akcja.');
    } catch (\Throwable $e) {
        http_response_code(422);
        $out(['ok' => false, 'error' => $e->getMessage()]);
    }
}

$org_name = function_exists('org_setting') ? (org_setting('org_name') ?: '') : '';
?><!DOCTYPE html>
<html lang="pl" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Strategia Organizacji<?= $org_name ? ' · ' . h($org_name) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: { extend: {
    colors: { brand: {50:'#eff6ff',100:'#dbeafe',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af'} },
    fontFamily: { sans: ['system-ui','-apple-system','Segoe UI','Roboto','sans-serif'] },
  }}
};
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>
  [x-cloak]{display:none!important}
  .chip-dot{width:.6rem;height:.6rem;border-radius:9999px;display:inline-block;flex:none}
  @media (prefers-reduced-motion: reduce){ *{transition:none!important;animation:none!important} }
</style>
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased"
      x-data="stratApp()" x-init="init()" @keydown.escape.window="drawerOpen=false; settingsOpen=false">

<!-- ═══ Pasek górny ═══ -->
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
  <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3 px-4 py-3">
    <a href="<?= h(APP_URL) ?>/portal.php" class="flex items-center gap-2 text-slate-500 hover:text-brand-600"
       aria-label="Wróć do portalu SZO">
      <i class="bi bi-arrow-left-short text-xl" aria-hidden="true"></i>
    </a>
    <div class="flex items-center gap-2.5">
      <span class="grid h-9 w-9 place-items-center rounded-xl bg-brand-600 text-white"><i class="bi bi-bullseye" aria-hidden="true"></i></span>
      <div>
        <h1 class="text-base font-semibold leading-tight">Strategia Organizacji</h1>
        <p class="text-xs text-slate-500">Planowanie działań operacyjnych<?= $org_name ? ' · ' . h($org_name) : '' ?></p>
      </div>
    </div>

    <div class="ms-auto flex flex-wrap items-center gap-2">
      <!-- Wybór roku -->
      <label class="sr-only" for="rokSel">Rok planu</label>
      <select id="rokSel" x-model.number="rok" @change="loadYear()"
              class="rounded-lg border-slate-300 bg-white px-3 py-2 text-sm font-medium shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <template x-for="y in yearOptions" :key="y"><option :value="y" x-text="y"></option></template>
      </select>

      <!-- Przełącznik widoku -->
      <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 shadow-sm" role="tablist" aria-label="Widok danych">
        <template x-for="v in [['table','bi-table','Tabela'],['grid','bi-calendar3','Siatka miesięcy'],['summary','bi-bar-chart','Podsumowanie']]" :key="v[0]">
          <button type="button" role="tab" :aria-selected="view===v[0] ? 'true':'false'"
                  @click="view=v[0]"
                  :class="view===v[0] ? 'bg-brand-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'"
                  class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition">
            <i :class="v[1]" class="bi" aria-hidden="true"></i><span class="hidden sm:inline" x-text="v[2]"></span>
          </button>
        </template>
      </div>

      <?php if ($is_admin): ?>
      <button type="button" @click="settingsOpen=true"
              class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-600 shadow-sm hover:bg-slate-50"
              aria-label="Ustawienia słowników">
        <i class="bi bi-gear" aria-hidden="true"></i>
      </button>
      <?php endif; ?>

      <?php if ($can_edit): ?>
      <button type="button" @click="openNew()"
              class="flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Nowe działanie
      </button>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="mx-auto max-w-screen-2xl px-4 py-6">

  <!-- ═══ Karty KPI ═══ -->
  <section aria-label="Podsumowanie roku" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <template x-for="k in kpis" :key="k.label">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3">
          <span class="grid h-10 w-10 place-items-center rounded-xl" :class="k.bg"><i :class="k.icon" class="bi text-lg" aria-hidden="true"></i></span>
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500" x-text="k.label"></p>
            <p class="text-lg font-semibold" x-text="k.value"></p>
          </div>
        </div>
      </div>
    </template>
  </section>

  <!-- ═══ Filtry (na żywo) ═══ -->
  <section aria-label="Filtry" class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-7">
      <div class="col-span-2 md:col-span-3 xl:col-span-2">
        <label for="fSearch" class="mb-1 block text-xs font-medium text-slate-500">Szukaj</label>
        <div class="relative">
          <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
          <input id="fSearch" type="search" x-model.debounce.150ms="f.q" placeholder="Nazwa lub opis działania…"
                 class="w-full rounded-lg border-slate-300 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
      </div>
      <div>
        <label for="fMies" class="mb-1 block text-xs font-medium text-slate-500">Miesiąc</label>
        <select id="fMies" x-model.number="f.miesiac" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
          <option :value="0">Wszystkie</option>
          <template x-for="(m,i) in months" :key="i"><option :value="i+1" x-text="m"></option></template>
        </select>
      </div>
      <div>
        <label for="fGrupa" class="mb-1 block text-xs font-medium text-slate-500">Grupa docelowa</label>
        <select id="fGrupa" x-model.number="f.grupa" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
          <option :value="0">Wszystkie</option>
          <template x-for="g in dicts.groups" :key="g.id"><option :value="g.id" x-text="g.nazwa"></option></template>
        </select>
      </div>
      <div>
        <label for="fTyp" class="mb-1 block text-xs font-medium text-slate-500">Typ działania</label>
        <select id="fTyp" x-model.number="f.typ" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
          <option :value="0">Wszystkie</option>
          <template x-for="t in dicts.types" :key="t.id"><option :value="t.id" x-text="t.nazwa"></option></template>
        </select>
      </div>
      <div>
        <label for="fFin" class="mb-1 block text-xs font-medium text-slate-500">Finansowanie</label>
        <select id="fFin" x-model.number="f.fin" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
          <option :value="0">Wszystkie</option>
          <template x-for="s in dicts.fundings" :key="s.id"><option :value="s.id" x-text="s.nazwa"></option></template>
        </select>
      </div>
      <div>
        <label for="fStatus" class="mb-1 block text-xs font-medium text-slate-500">Status</label>
        <div class="flex gap-2">
          <select id="fStatus" x-model="f.status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option value="">Wszystkie</option>
            <template x-for="(lbl,key) in statusLabels" :key="key"><option :value="key" x-text="lbl"></option></template>
          </select>
          <button type="button" @click="resetFilters()" x-show="filtersActive" x-cloak
                  class="rounded-lg border border-slate-300 px-2.5 text-slate-500 hover:bg-slate-50" aria-label="Wyczyść filtry">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    </div>
    <p class="mt-2 text-xs text-slate-500" role="status">
      <span x-text="filtered.length"></span> z <span x-text="plans.length"></span> działań
      · budżet widocznych: <strong x-text="money(sumBudget(filtered))"></strong>
    </p>
  </section>

  <!-- ═══ Widok: ładowanie / pusto ═══ -->
  <div x-show="loading" x-cloak class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500 shadow-sm">
    <i class="bi bi-arrow-repeat animate-spin text-2xl" aria-hidden="true"></i>
    <p class="mt-2 text-sm">Wczytywanie planu…</p>
  </div>
  <div x-show="!loading && !filtered.length" x-cloak class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center shadow-sm">
    <i class="bi bi-clipboard-plus text-3xl text-slate-300" aria-hidden="true"></i>
    <p class="mt-3 font-medium text-slate-600">Brak działań spełniających kryteria</p>
    <p class="text-sm text-slate-500">Zmień filtry<?= $can_edit ? ' lub dodaj nowe działanie' : '' ?>.</p>
  </div>

  <!-- ═══ Widok: TABELA ═══ -->
  <section x-show="!loading && view==='table' && filtered.length" x-cloak aria-label="Lista działań"
           class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" class="px-4 py-3">Działanie</th>
            <th scope="col" class="px-4 py-3">Termin</th>
            <th scope="col" class="px-4 py-3">Grupa docelowa</th>
            <th scope="col" class="px-4 py-3">Typ</th>
            <th scope="col" class="px-4 py-3">Statut</th>
            <th scope="col" class="px-4 py-3">Finansowanie</th>
            <th scope="col" class="px-4 py-3 text-right">Budżet</th>
            <th scope="col" class="px-4 py-3">Zasoby</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3"><span class="sr-only">Akcje</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template x-for="p in filtered" :key="p.id">
            <tr class="hover:bg-brand-50/40">
              <td class="max-w-xs px-4 py-3">
                <button type="button" class="text-left font-medium text-slate-800 hover:text-brand-700"
                        @click="openEdit(p)" :aria-label="'Otwórz działanie ' + p.nazwa">
                  <span x-text="p.nazwa"></span>
                </button>
                <p class="mt-0.5 truncate text-xs text-slate-500" x-show="p.opis" x-text="p.opis"></p>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                <span x-text="months[p.miesiac-1]"></span> <span x-text="p.rok"></span>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1.5" x-show="p.target_group_id">
                  <span class="chip-dot" :style="'background:'+(dictById('groups',p.target_group_id)?.kolor||'#94a3b8')"></span>
                  <span x-text="dictById('groups',p.target_group_id)?.nazwa||'—'"></span>
                </span>
                <span x-show="!p.target_group_id" class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1.5" x-show="p.action_type_id">
                  <i class="bi" :class="dictById('types',p.action_type_id)?.ikona" :style="'color:'+(dictById('types',p.action_type_id)?.kolor||'#64748b')" aria-hidden="true"></i>
                  <span x-text="dictById('types',p.action_type_id)?.nazwa||'—'"></span>
                </span>
                <span x-show="!p.action_type_id" class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-3">
                <span x-show="p.statute_ref_id" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs"
                      :title="dictById('statutes',p.statute_ref_id)?.tytul" x-text="dictById('statutes',p.statute_ref_id)?.kod||''"></span>
                <span x-show="!p.statute_ref_id" class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-3">
                <template x-if="p.funding_source_id">
                  <span class="inline-flex items-center gap-1.5 text-slate-600">
                    <i class="bi" :class="fundingIcon(dictById('fundings',p.funding_source_id)?.typ)" aria-hidden="true"></i>
                    <span x-text="dictById('fundings',p.funding_source_id)?.nazwa"></span>
                  </span>
                </template>
                <span x-show="!p.funding_source_id" class="text-slate-400">—</span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums" x-text="money(p.budzet)"></td>
              <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                <span class="inline-flex items-center gap-2 text-xs">
                  <span class="inline-flex items-center gap-1" :title="'Zasoby ludzkie: '+resCount(p,'ludzki')"><i class="bi bi-people" aria-hidden="true"></i><span x-text="resCount(p,'ludzki')"></span></span>
                  <span class="inline-flex items-center gap-1" :title="'Zasoby sprzętowe: '+resCount(p,'sprzetowy')"><i class="bi bi-tools" aria-hidden="true"></i><span x-text="resCount(p,'sprzetowy')"></span></span>
                  <span class="inline-flex items-center gap-1" :title="'Koszt zasobów: '+money(resCost(p))"><i class="bi bi-cash-stack" aria-hidden="true"></i><span x-text="money(resCost(p))"></span></span>
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(p.status)" x-text="statusLabels[p.status]||p.status"></span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <?php if ($can_edit): ?>
                <button type="button" @click="openEdit(p)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                        :aria-label="'Edytuj: '+p.nazwa"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                <button type="button" @click="removePlan(p)" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                        :aria-label="'Usuń: '+p.nazwa"><i class="bi bi-trash" aria-hidden="true"></i></button>
                <?php endif; ?>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ═══ Widok: SIATKA MIESIĘCY ═══ -->
  <section x-show="!loading && view==='grid' && filtered.length" x-cloak aria-label="Siatka miesięczna"
           class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
    <template x-for="(m,i) in months" :key="i">
      <div class="flex flex-col rounded-2xl border bg-white shadow-sm"
           :class="byMonth(i+1).length ? 'border-slate-200' : 'border-slate-100 opacity-70'">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h3 class="text-sm font-semibold capitalize" x-text="m + ' ' + rok"></h3>
          <span class="text-xs text-slate-500">
            <span x-text="byMonth(i+1).length"></span> dz. · <span x-text="money(sumBudget(byMonth(i+1)))"></span>
          </span>
        </div>
        <div class="flex-1 space-y-2 p-3">
          <template x-for="p in byMonth(i+1)" :key="p.id">
            <button type="button" @click="openEdit(p)"
                    class="flex w-full items-start gap-2 rounded-xl border border-slate-100 bg-slate-50/70 px-3 py-2 text-left hover:border-brand-200 hover:bg-brand-50"
                    :aria-label="'Otwórz działanie ' + p.nazwa">
              <span class="chip-dot mt-1.5" :style="'background:'+(dictById('groups',p.target_group_id)?.kolor||'#94a3b8')"></span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium" x-text="p.nazwa"></span>
                <span class="block text-xs text-slate-500">
                  <span x-text="dictById('types',p.action_type_id)?.nazwa||'—'"></span> · <span x-text="money(p.budzet)"></span>
                </span>
              </span>
              <span class="mt-0.5 h-2 w-2 flex-none rounded-full" :class="statusDot(p.status)" :title="statusLabels[p.status]"></span>
            </button>
          </template>
          <p x-show="!byMonth(i+1).length" class="py-2 text-center text-xs text-slate-400">brak działań</p>
        </div>
      </div>
    </template>
  </section>

  <!-- ═══ Widok: PODSUMOWANIE ═══ -->
  <section x-show="!loading && view==='summary'" x-cloak aria-label="Podsumowanie zasobów i budżetu"
           class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Budżet i zasoby wg miesiąca</h3>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
            <tr><th class="px-4 py-2">Miesiąc</th><th class="px-4 py-2 text-right">Działania</th>
                <th class="px-4 py-2 text-right">Budżet</th><th class="px-4 py-2 text-right">Koszt zasobów</th>
                <th class="px-4 py-2 text-right">Osoby</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template x-for="(m,i) in months" :key="i">
              <tr x-show="byMonth(i+1).length" class="tabular-nums">
                <td class="px-4 py-2 font-medium capitalize" x-text="m"></td>
                <td class="px-4 py-2 text-right" x-text="byMonth(i+1).length"></td>
                <td class="px-4 py-2 text-right" x-text="money(sumBudget(byMonth(i+1)))"></td>
                <td class="px-4 py-2 text-right" x-text="money(byMonth(i+1).reduce((s,p)=>s+resCost(p),0))"></td>
                <td class="px-4 py-2 text-right" x-text="byMonth(i+1).reduce((s,p)=>s+resCount(p,'ludzki'),0)"></td>
              </tr>
            </template>
            <tr class="bg-slate-50 font-semibold tabular-nums">
              <td class="px-4 py-2">Razem</td>
              <td class="px-4 py-2 text-right" x-text="filtered.length"></td>
              <td class="px-4 py-2 text-right" x-text="money(sumBudget(filtered))"></td>
              <td class="px-4 py-2 text-right" x-text="money(filtered.reduce((s,p)=>s+resCost(p),0))"></td>
              <td class="px-4 py-2 text-right" x-text="filtered.reduce((s,p)=>s+resCount(p,'ludzki'),0)"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold">Budżet wg grupy docelowej</h3>
      <div class="space-y-3 p-4">
        <template x-for="g in groupSummary" :key="g.id">
          <div>
            <div class="mb-1 flex items-center justify-between text-sm">
              <span class="inline-flex items-center gap-1.5">
                <span class="chip-dot" :style="'background:'+g.kolor"></span><span x-text="g.nazwa"></span>
                <span class="text-xs text-slate-400">(<span x-text="g.count"></span>)</span>
              </span>
              <span class="font-medium tabular-nums" x-text="money(g.budzet)"></span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="presentation">
              <div class="h-full rounded-full" :style="'width:'+g.pct+'%; background:'+g.kolor"></div>
            </div>
          </div>
        </template>
        <p x-show="!groupSummary.length" class="text-sm text-slate-400">Brak danych do podsumowania.</p>
      </div>
    </div>
  </section>
</main>

<!-- ═══ Drawer: formularz działania ═══ -->
<div x-show="drawerOpen" x-cloak class="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
  <div class="absolute inset-0 bg-slate-900/40" @click="drawerOpen=false" aria-hidden="true"
       x-show="drawerOpen" x-transition.opacity></div>
  <div class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col bg-white shadow-2xl"
       x-show="drawerOpen"
       x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
       @keydown.escape.stop="drawerOpen=false">

    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
      <h2 id="drawerTitle" class="text-base font-semibold" x-text="form.id ? 'Edycja działania' : 'Nowe działanie'"></h2>
      <button type="button" @click="drawerOpen=false" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Zamknij formularz">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form class="flex-1 overflow-y-auto px-6 py-5" @submit.prevent="savePlan()" novalidate>
      <div x-show="errors.length" x-cloak class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
        <template x-for="(e,i) in errors" :key="i"><p x-text="e"></p></template>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label for="pNazwa" class="mb-1 block text-sm font-medium">Nazwa działania <span class="text-rose-500" aria-hidden="true">*</span></label>
          <input id="pNazwa" x-ref="firstField" type="text" x-model="form.nazwa" required
                 class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <div class="sm:col-span-2">
          <label for="pOpis" class="mb-1 block text-sm font-medium">Opis</label>
          <textarea id="pOpis" x-model="form.opis" rows="2"
                    class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
        </div>

        <div>
          <label for="pMies" class="mb-1 block text-sm font-medium">Miesiąc <span class="text-rose-500" aria-hidden="true">*</span></label>
          <select id="pMies" x-model.number="form.miesiac" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <template x-for="(m,i) in months" :key="i"><option :value="i+1" x-text="m"></option></template>
          </select>
        </div>
        <div>
          <label for="pRok" class="mb-1 block text-sm font-medium">Rok <span class="text-rose-500" aria-hidden="true">*</span></label>
          <input id="pRok" type="number" min="2000" max="2100" x-model.number="form.rok"
                 class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
        </div>

        <div>
          <label for="pGrupa" class="mb-1 block text-sm font-medium">Grupa docelowa</label>
          <select id="pGrupa" x-model.number="form.target_group_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option :value="0">— wybierz —</option>
            <template x-for="g in activeDict('groups')" :key="g.id"><option :value="g.id" x-text="g.nazwa"></option></template>
          </select>
        </div>
        <div>
          <label for="pTyp" class="mb-1 block text-sm font-medium">Typ działania</label>
          <select id="pTyp" x-model.number="form.action_type_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option :value="0">— wybierz —</option>
            <template x-for="t in activeDict('types')" :key="t.id"><option :value="t.id" x-text="t.nazwa"></option></template>
          </select>
        </div>

        <div>
          <label for="pStatut" class="mb-1 block text-sm font-medium">Powiązanie statutowe</label>
          <select id="pStatut" x-model.number="form.statute_ref_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option :value="0">— wybierz —</option>
            <template x-for="s in activeDict('statutes')" :key="s.id"><option :value="s.id" x-text="s.kod + ' — ' + s.tytul"></option></template>
          </select>
          <p class="mt-1 text-xs text-slate-500" x-show="form.statute_ref_id"
             x-text="dictById('statutes',form.statute_ref_id)?.opis||''"></p>
        </div>
        <div>
          <label for="pFin" class="mb-1 block text-sm font-medium">Źródło finansowania</label>
          <select id="pFin" x-model.number="form.funding_source_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option :value="0">— wybierz —</option>
            <template x-for="s in activeDict('fundings')" :key="s.id">
              <option :value="s.id" x-text="s.nazwa + ' (' + fundingLabels[s.typ] + ')'"></option>
            </template>
          </select>
        </div>

        <div>
          <label for="pBudzet" class="mb-1 block text-sm font-medium">Budżet działania (PLN)</label>
          <input id="pBudzet" type="number" min="0" step="0.01" x-model.number="form.budzet"
                 class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
        </div>
        <div>
          <label for="pStatus" class="mb-1 block text-sm font-medium">Status</label>
          <select id="pStatus" x-model="form.status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <template x-for="(lbl,key) in statusLabels" :key="key"><option :value="key" x-text="lbl"></option></template>
          </select>
        </div>

        <div class="sm:col-span-2">
          <label for="pOwner" class="mb-1 block text-sm font-medium">Koordynator</label>
          <select id="pOwner" x-model.number="form.owner_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
            <option :value="0">— brak —</option>
            <template x-for="u in users" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
          </select>
        </div>
      </div>

      <!-- Zasoby -->
      <fieldset class="mt-6">
        <legend class="mb-2 flex w-full items-center justify-between text-sm font-semibold">
          <span>Podział zasobów</span>
          <span class="text-xs font-normal text-slate-500">koszt łączny: <strong x-text="money(formResCost)"></strong></span>
        </legend>
        <div class="space-y-2">
          <template x-for="(r,idx) in form.resources" :key="idx">
            <div class="grid grid-cols-12 items-start gap-2 rounded-xl border border-slate-200 bg-slate-50/60 p-2">
              <div class="col-span-6 sm:col-span-2">
                <label class="sr-only" :for="'rRodzaj'+idx">Rodzaj zasobu</label>
                <select :id="'rRodzaj'+idx" x-model="r.rodzaj" class="w-full rounded-lg border-slate-300 text-xs shadow-sm">
                  <option value="ludzki">Ludzki</option>
                  <option value="sprzetowy">Sprzętowy</option>
                  <option value="finansowy">Finansowy</option>
                </select>
              </div>
              <div class="col-span-6 sm:col-span-4">
                <label class="sr-only" :for="'rNazwa'+idx">Nazwa zasobu</label>
                <template x-if="r.rodzaj==='ludzki'">
                  <select :id="'rNazwa'+idx" x-model.number="r.user_id"
                          @change="r.nazwa = (users.find(u=>u.id===r.user_id)||{}).name || r.nazwa"
                          class="w-full rounded-lg border-slate-300 text-xs shadow-sm">
                    <option :value="0">— osoba spoza systemu —</option>
                    <template x-for="u in users" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
                  </select>
                </template>
                <input x-show="r.rodzaj!=='ludzki' || !r.user_id" type="text" x-model="r.nazwa"
                       :placeholder="r.rodzaj==='ludzki' ? 'Imię i nazwisko' : 'Nazwa zasobu'"
                       :aria-label="'Nazwa zasobu ' + (idx+1)"
                       class="mt-1 w-full rounded-lg border-slate-300 text-xs shadow-sm sm:mt-0"
                       :class="r.rodzaj==='ludzki' ? 'sm:mt-1' : ''">
              </div>
              <div class="col-span-4 sm:col-span-2">
                <label class="sr-only" :for="'rIlosc'+idx">Ilość</label>
                <input :id="'rIlosc'+idx" type="number" min="0" step="0.5" x-model.number="r.ilosc" placeholder="Ilość"
                       class="w-full rounded-lg border-slate-300 text-xs shadow-sm">
              </div>
              <div class="col-span-4 sm:col-span-1">
                <label class="sr-only" :for="'rJedn'+idx">Jednostka</label>
                <input :id="'rJedn'+idx" type="text" x-model="r.jednostka" placeholder="j.m."
                       class="w-full rounded-lg border-slate-300 text-xs shadow-sm">
              </div>
              <div class="col-span-3 sm:col-span-2">
                <label class="sr-only" :for="'rKoszt'+idx">Koszt (PLN)</label>
                <input :id="'rKoszt'+idx" type="number" min="0" step="0.01" x-model.number="r.koszt" placeholder="Koszt"
                       class="w-full rounded-lg border-slate-300 text-xs shadow-sm">
              </div>
              <div class="col-span-1 text-right">
                <button type="button" @click="form.resources.splice(idx,1)"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                        :aria-label="'Usuń zasób ' + (idx+1)">
                  <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
              </div>
            </div>
          </template>
        </div>
        <button type="button" @click="addResource()"
                class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:border-brand-400 hover:text-brand-700">
          <i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj zasób
        </button>
      </fieldset>
    </form>

    <div class="flex items-center gap-3 border-t border-slate-200 px-6 py-4">
      <button type="button" @click="savePlan()" :disabled="saving"
              class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">
        <i class="bi" :class="saving ? 'bi-arrow-repeat animate-spin' : 'bi-check-lg'" aria-hidden="true"></i>
        <span x-text="form.id ? 'Zapisz zmiany' : 'Dodaj działanie'"></span>
      </button>
      <button type="button" @click="drawerOpen=false" class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Anuluj</button>
      <button type="button" x-show="form.id" x-cloak @click="removePlan(form)"
              class="ms-auto inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
        <i class="bi bi-trash" aria-hidden="true"></i> Usuń
      </button>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- ═══ Modal: słowniki (admin) ═══ -->
<div x-show="settingsOpen" x-cloak class="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-labelledby="setTitle">
  <div class="absolute inset-0 bg-slate-900/40" @click="settingsOpen=false" aria-hidden="true" x-show="settingsOpen" x-transition.opacity></div>
  <div class="absolute inset-x-0 top-8 mx-auto flex max-h-[85vh] w-[min(60rem,94vw)] flex-col rounded-2xl bg-white shadow-2xl" x-show="settingsOpen" x-transition>
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
      <h2 id="setTitle" class="text-base font-semibold">Słowniki modułu</h2>
      <button type="button" @click="settingsOpen=false" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" aria-label="Zamknij ustawienia">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <div class="border-b border-slate-200 px-6 pt-3" role="tablist" aria-label="Rodzaj słownika">
      <template x-for="t in [['groups','Grupy docelowe'],['types','Typy działań'],['statutes','Statut'],['fundings','Finansowanie']]" :key="t[0]">
        <button type="button" role="tab" :aria-selected="dictTab===t[0] ? 'true':'false'"
                @click="dictTab=t[0]"
                :class="dictTab===t[0] ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                class="me-4 border-b-2 pb-2 text-sm font-medium" x-text="t[1]"></button>
      </template>
    </div>
    <div class="flex-1 overflow-y-auto px-6 py-4">
      <div class="space-y-2">
        <template x-for="row in dicts[dictTab]" :key="row.id">
          <div class="grid grid-cols-12 items-center gap-2 rounded-xl border border-slate-200 p-2"
               :class="row.is_active==1 ? '' : 'opacity-50'">
            <template x-if="dictTab==='statutes'">
              <input type="text" x-model="row.kod" class="col-span-2 rounded-lg border-slate-300 text-xs" aria-label="Kod paragrafu">
            </template>
            <input type="text" x-model="row[dictTab==='statutes' ? 'tytul' : 'nazwa']"
                   :class="dictTab==='statutes' ? 'col-span-4' : 'col-span-4'"
                   class="rounded-lg border-slate-300 text-xs" aria-label="Nazwa pozycji">
            <template x-if="dictTab==='fundings'">
              <select x-model="row.typ" class="col-span-2 rounded-lg border-slate-300 text-xs" aria-label="Typ źródła">
                <option value="projekt">Projekt</option><option value="dotacja">Dotacja</option><option value="srodki_wlasne">Środki własne</option>
              </select>
            </template>
            <template x-if="dictTab==='fundings'">
              <input type="number" min="0" step="0.01" x-model.number="row.budzet_calkowity" placeholder="Budżet"
                     class="col-span-2 rounded-lg border-slate-300 text-xs" aria-label="Budżet całkowity">
            </template>
            <template x-if="dictTab==='groups' || dictTab==='types'">
              <input type="color" x-model="row.kolor" class="col-span-1 h-8 w-full rounded border-slate-300" aria-label="Kolor">
            </template>
            <template x-if="dictTab==='types'">
              <input type="text" x-model="row.ikona" placeholder="bi-…" class="col-span-2 rounded-lg border-slate-300 font-mono text-xs" aria-label="Ikona Bootstrap Icons">
            </template>
            <div class="col-span-3 ms-auto flex items-center justify-end gap-1">
              <button type="button" @click="row.is_active = row.is_active==1 ? 0 : 1; saveDictRow(row)"
                      class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" :aria-label="row.is_active==1 ? 'Dezaktywuj' : 'Aktywuj'">
                <i class="bi" :class="row.is_active==1 ? 'bi-eye' : 'bi-eye-slash'" aria-hidden="true"></i>
              </button>
              <button type="button" @click="saveDictRow(row)" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600" aria-label="Zapisz pozycję">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
              </button>
              <button type="button" @click="deleteDictRow(row)" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Usuń pozycję">
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </template>
      </div>
      <button type="button" @click="addDictRow()"
              class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:border-brand-400 hover:text-brand-700">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj pozycję
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Toast ═══ -->
<div x-show="toast.msg" x-cloak x-transition.opacity
     class="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-xl px-4 py-2.5 text-sm font-medium text-white shadow-lg"
     :class="toast.ok ? 'bg-emerald-600' : 'bg-rose-600'" role="status" aria-live="polite">
  <span x-text="toast.msg"></span>
</div>

<script>
const CSRF     = <?= json_encode(csrf_token()) ?>;
const CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;

function stratApp() {
  return {
    // ── stan ──
    loading: true, saving: false,
    rok: new Date().getFullYear(),
    years: [], view: 'table',
    dicts: { groups: [], types: [], statutes: [], fundings: [] },
    plans: [], users: [],
    f: { q: '', miesiac: 0, grupa: 0, typ: 0, fin: 0, status: '' },
    drawerOpen: false, settingsOpen: false, dictTab: 'groups',
    form: {}, errors: [],
    toast: { msg: '', ok: true },

    months: ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'],
    statusLabels: { planowane: 'Planowane', w_realizacji: 'W realizacji', zrealizowane: 'Zrealizowane', anulowane: 'Anulowane' },
    fundingLabels: { projekt: 'projekt', dotacja: 'dotacja', srodki_wlasne: 'środki własne' },

    // ── init / API ──
    async init() { await this.loadYear(); },
    async api(action, payload = {}) {
      const fd = new FormData();
      fd.append('_csrf', CSRF); fd.append('action', action);
      fd.append('payload', JSON.stringify(payload));
      const r = await fetch('index.php?_ajax=1', { method: 'POST', body: fd });
      const j = await r.json().catch(() => ({ ok: false, error: 'Błąd połączenia.' }));
      if (!j.ok) throw new Error(j.error || 'Błąd serwera.');
      return j;
    },
    async loadYear() {
      this.loading = true;
      try {
        const j = await this.api('bootstrap', { rok: this.rok });
        this.dicts = j.dicts; this.plans = j.plans; this.years = j.years; this.users = j.users;
      } catch (e) { this.notify(e.message, false); }
      this.loading = false;
    },

    // ── słowniki / pomocnicze ──
    dictById(dict, id) { return this.dicts[dict].find(x => x.id == id); },
    activeDict(dict)   { return this.dicts[dict].filter(x => x.is_active == 1); },
    fundingIcon(typ)   { return { projekt: 'bi-kanban', dotacja: 'bi-bank', srodki_wlasne: 'bi-piggy-bank' }[typ] || 'bi-cash'; },
    money(v) { return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 }).format(+v || 0); },
    resCount(p, kind)  { return (p.resources || []).filter(r => r.rodzaj === kind).reduce((s, r) => s + (+r.ilosc || 0), 0); },
    resCost(p)         { return (p.resources || []).reduce((s, r) => s + (+r.koszt || 0), 0); },
    sumBudget(list)    { return list.reduce((s, p) => s + (+p.budzet || 0), 0); },
    statusClass(s) { return { planowane: 'bg-slate-100 text-slate-600', w_realizacji: 'bg-amber-100 text-amber-700',
                              zrealizowane: 'bg-emerald-100 text-emerald-700', anulowane: 'bg-rose-100 text-rose-600' }[s] || 'bg-slate-100'; },
    statusDot(s)   { return { planowane: 'bg-slate-300', w_realizacji: 'bg-amber-400',
                              zrealizowane: 'bg-emerald-500', anulowane: 'bg-rose-400' }[s] || 'bg-slate-300'; },
    notify(msg, ok = true) { this.toast = { msg, ok }; setTimeout(() => this.toast.msg = '', 3500); },

    // ── filtry (na żywo, po stronie klienta) ──
    get filtered() {
      const q = this.f.q.trim().toLowerCase();
      return this.plans.filter(p =>
        (!q || (p.nazwa + ' ' + (p.opis || '')).toLowerCase().includes(q)) &&
        (!this.f.miesiac || p.miesiac == this.f.miesiac) &&
        (!this.f.grupa   || p.target_group_id == this.f.grupa) &&
        (!this.f.typ     || p.action_type_id == this.f.typ) &&
        (!this.f.fin     || p.funding_source_id == this.f.fin) &&
        (!this.f.status  || p.status === this.f.status)
      );
    },
    get filtersActive() { return this.f.q || this.f.miesiac || this.f.grupa || this.f.typ || this.f.fin || this.f.status; },
    resetFilters() { this.f = { q: '', miesiac: 0, grupa: 0, typ: 0, fin: 0, status: '' }; },
    byMonth(m) { return this.filtered.filter(p => p.miesiac == m); },

    get yearOptions() {
      const ys = new Set([...this.years, this.rok, new Date().getFullYear(), new Date().getFullYear() + 1]);
      return [...ys].sort();
    },
    get kpis() {
      const fl = this.filtered;
      return [
        { label: 'Działania',      value: fl.length,                                        icon: 'bi-calendar-check', bg: 'bg-brand-100 text-brand-700' },
        { label: 'Budżet łączny',  value: this.money(this.sumBudget(fl)),                   icon: 'bi-cash-coin',      bg: 'bg-emerald-100 text-emerald-700' },
        { label: 'Koszt zasobów',  value: this.money(fl.reduce((s,p)=>s+this.resCost(p),0)),icon: 'bi-boxes',          bg: 'bg-amber-100 text-amber-700' },
        { label: 'Osoby w zespole',value: fl.reduce((s,p)=>s+this.resCount(p,'ludzki'),0),  icon: 'bi-people',         bg: 'bg-violet-100 text-violet-700' },
      ];
    },
    get groupSummary() {
      const rows = this.dicts.groups.map(g => {
        const list = this.filtered.filter(p => p.target_group_id == g.id);
        return { id: g.id, nazwa: g.nazwa, kolor: g.kolor, count: list.length, budzet: this.sumBudget(list) };
      }).filter(r => r.count);
      const max = Math.max(1, ...rows.map(r => r.budzet));
      rows.forEach(r => r.pct = Math.round(100 * r.budzet / max));
      return rows.sort((a, b) => b.budzet - a.budzet);
    },
    get formResCost() { return (this.form.resources || []).reduce((s, r) => s + (+r.koszt || 0), 0); },

    // ── formularz działania ──
    blankForm() {
      return { id: 0, nazwa: '', opis: '', rok: this.rok, miesiac: new Date().getMonth() + 1,
               target_group_id: 0, action_type_id: 0, statute_ref_id: 0, funding_source_id: 0,
               budzet: 0, status: 'planowane', owner_id: 0, resources: [] };
    },
    openNew()  { this.errors = []; this.form = this.blankForm(); this.drawerOpen = true; this.focusForm(); },
    openEdit(p) {
      if (!CAN_EDIT) return;
      this.errors = [];
      this.form = JSON.parse(JSON.stringify({ ...this.blankForm(), ...p,
        target_group_id: p.target_group_id || 0, action_type_id: p.action_type_id || 0,
        statute_ref_id: p.statute_ref_id || 0, funding_source_id: p.funding_source_id || 0,
        owner_id: p.owner_id || 0, resources: p.resources || [] }));
      this.drawerOpen = true; this.focusForm();
    },
    focusForm() { this.$nextTick(() => this.$refs.firstField?.focus()); },
    addResource() { this.form.resources.push({ rodzaj: 'ludzki', nazwa: '', user_id: 0, ilosc: 1, jednostka: '', koszt: 0, notatka: '' }); },

    async savePlan() {
      this.errors = [];
      if (!this.form.nazwa.trim()) { this.errors.push('Nazwa działania jest wymagana.'); return; }
      this.saving = true;
      try {
        await this.api('plan_save', this.form);
        this.drawerOpen = false;
        this.notify(this.form.id ? 'Zapisano zmiany.' : 'Dodano działanie.');
        if (this.form.rok !== this.rok) this.rok = this.form.rok;
        await this.loadYear();
      } catch (e) { this.errors = [e.message]; }
      this.saving = false;
    },
    async removePlan(p) {
      if (!confirm('Usunąć działanie „' + p.nazwa + '” wraz z alokacją zasobów?')) return;
      try {
        await this.api('plan_delete', { id: p.id });
        this.drawerOpen = false; this.notify('Usunięto działanie.');
        await this.loadYear();
      } catch (e) { this.notify(e.message, false); }
    },

    // ── słowniki (admin) ──
    addDictRow() {
      const base = { id: 0, is_active: 1, sort_order: this.dicts[this.dictTab].length };
      const extra = {
        groups:   { nazwa: '', kolor: '#2563eb' },
        types:    { nazwa: '', ikona: 'bi-calendar-event', kolor: '#0891b2' },
        statutes: { kod: '', tytul: '', opis: '' },
        fundings: { nazwa: '', typ: 'srodki_wlasne', budzet_calkowity: 0, rok: this.rok },
      }[this.dictTab];
      this.dicts[this.dictTab].push({ ...base, ...extra });
    },
    async saveDictRow(row) {
      try {
        const j = await this.api('dict_save', { dict: this.dictTab, data: row });
        row.id = j.id; this.notify('Zapisano słownik.');
      } catch (e) { this.notify(e.message, false); }
    },
    async deleteDictRow(row) {
      if (!row.id) { this.dicts[this.dictTab] = this.dicts[this.dictTab].filter(r => r !== row); return; }
      if (!confirm('Usunąć pozycję słownika?')) return;
      try {
        await this.api('dict_delete', { dict: this.dictTab, id: row.id });
        this.dicts[this.dictTab] = this.dicts[this.dictTab].filter(r => r.id !== row.id);
        this.notify('Usunięto pozycję.');
      } catch (e) { this.notify(e.message, false); await this.loadYear(); }
    },
  };
}
</script>
</body>
</html>
