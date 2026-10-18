<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/modules/ezd_bulk/logic/ezdBulk.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();

$PAGE_TITLE = 'Koszulki';
$user_id    = (int)current_user()['id'];

$status_f   = $_GET['status']    ?? '';
$priority_f = $_GET['priority']  ?? '';
$owner_f    = (int)($_GET['owner_id'] ?? 0);
$mine_f     = !empty($_GET['mine']);
$dod        = $_GET['deadline_od'] ?? '';
$ddo        = $_GET['deadline_do'] ?? '';
$q          = trim($_GET['q']    ?? '');
$hide_ciagla_f  = !empty($_GET['hide_ciagla']);
$hide_old_f     = !empty($_GET['hide_old']);
$show_hidden_f  = !empty($_GET['show_hidden']);

$filters = [
    'status' => $status_f, 'priority' => $priority_f, 'q' => $q,
    'owner_id' => $owner_f, 'deadline_od' => $dod, 'deadline_do' => $ddo,
    'mine_or_shared' => $mine_f, 'hide_ciagla' => $hide_ciagla_f, 'hide_old' => $hide_old_f,
    'show_hidden' => $show_hidden_f,
];
// ── Masowe przerejestrowanie zaznaczonych koszulek ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'bulk_reregister') {
    csrf_check();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

    $ids       = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $targetRaw = trim((string)($_POST['target'] ?? ''));
    $target    = null;
    if (str_starts_with($targetRaw, 'teczka:'))  $target = ['teczka_id' => (int)substr($targetRaw, 7)];
    elseif (str_starts_with($targetRaw, 'jrwa:')) $target = ['jrwa' => substr($targetRaw, 5)];

    if (!$ids)          flash_set('danger', 'Zaznacz przynajmniej jedną koszulkę.');
    elseif (!$target)   flash_set('danger', 'Wskaż, gdzie przenieść zaznaczone koszulki.');
    else {
        $ok = 0; $fail = [];
        foreach ($ids as $sid) {
            $sp = ezd_sprawa_get($sid);
            if (!$sp || !ezd_sprawa_access($sp, $user_id)) { $fail[] = "#{$sid}: brak dostępu"; continue; }
            $r = ezd_sprawa_reregister($sid, $target, $user_id);
            if (!empty($r['ok'])) $ok++;
            else $fail[] = ($sp['znak_sprawy'] ?: "#{$sid}") . ': ' . $r['error'];
        }
        $msg = "Przerejestrowano koszulek: {$ok}.";
        if ($fail) $msg .= ' Pominięto ' . count($fail) . ' — ' . implode('; ', array_slice($fail, 0, 6)) . (count($fail) > 6 ? '…' : '');
        flash_set($ok ? 'success' : 'warning', $msg);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$sprawy  = ezd_sprawy_all($filters, $user_id);
$owners  = db_all("SELECT DISTINCT u.id, u.name FROM ezd_sprawy s JOIN users u ON u.id=s.owner_id ORDER BY u.name");

// Cele masowego przeniesienia (otwarte segregatory + klasy JRWA) — tylko dla edytujących
$teczki_cele = $jrwa_cele = [];
if (can_edit()) {
    $teczki_cele = db_all(
        "SELECT t.id, t.symbol, t.title, t.rok, j.symbol AS jrwa_symbol
         FROM ezd_teczki t LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.status='open' ORDER BY t.symbol, t.rok DESC"
    );
    $jrwa_cele = ezd_jrwa_all();
}

// Eksport CSV bieżących wyników (respektuje filtry)
if (($_GET['export'] ?? '') === 'csv') {
    ezdBulkCsv($sprawy, 'sprawy_' . date('Y-m-d') . '.csv'); exit;
}

// ── Model widoku listy (układ kaflowy/listowy, Tailwind + Alpine) ──────────────
$TONE = ['secondary' => 'slate', 'info' => 'sky', 'primary' => 'blue', 'warning' => 'amber', 'success' => 'emerald', 'danger' => 'rose', 'dark' => 'slate', 'muted' => 'slate'];
$today = date('Y-m-d');
$active_filters = array_filter([
    'status' => $status_f, 'priority' => $priority_f, 'owner_id' => $owner_f ?: '', 'deadline_od' => $dod, 'deadline_do' => $ddo,
]);
$n_filters   = count($active_filters) + (int)$mine_f + (int)$hide_ciagla_f + (int)$hide_old_f + (int)$show_hidden_f;
$owner_names = array_column($owners, 'name', 'id');
$cnt_over = 0; $cnt_mine_dekr = 0;
foreach ($sprawy as $s) {
    if ($s['deadline'] && $s['deadline'] < $today && empty($s['ciagla']) && $s['status'] !== 'closed') $cnt_over++;
}
$self_q = fn(array $over) => '?' . http_build_query(array_filter(array_merge($_GET, $over), fn($v) => $v !== '' && $v !== null));

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style type="text/tailwindcss">
/* ── Lista koszulek: komponenty (Tailwind bez preflightu — przy tw-border zawsze tw-border-solid) ── */
.ezx-card     { @apply tw-bg-white tw-rounded-2xl tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm; }
.ezx-eyebrow  { @apply tw-text-[.64rem] tw-font-bold tw-uppercase tw-tracking-[.1em] tw-text-slate-500; }
.ezx-muted    { @apply tw-text-xs tw-text-slate-500; }
.ezx-btn      { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-text-white tw-px-3 tw-py-1.5 tw-text-xs tw-font-semibold tw-no-underline tw-border-0 tw-cursor-pointer tw-transition tw-shadow-sm hover:tw-bg-blue-700 hover:tw-text-white focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-offset-2 focus-visible:tw-outline-amber-400 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed; }
.ezx-btn--ghost { @apply tw-bg-white tw-text-slate-700 tw-border tw-border-solid tw-border-slate-300 tw-shadow-none hover:tw-bg-slate-50 hover:tw-text-slate-900 hover:tw-border-slate-400; }
.ezx-btn--emerald { @apply tw-bg-emerald-600 hover:tw-bg-emerald-700; }
.ezx-badge    { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[.66rem] tw-font-semibold tw-border tw-border-solid tw-whitespace-nowrap tw-leading-tight; }
.ezx-badge--slate  { @apply tw-bg-slate-100 tw-text-slate-600 tw-border-slate-200; }
.ezx-badge--sky    { @apply tw-bg-sky-50 tw-text-sky-700 tw-border-sky-200; }
.ezx-badge--blue   { @apply tw-bg-blue-50 tw-text-blue-700 tw-border-blue-200; }
.ezx-badge--amber  { @apply tw-bg-amber-50 tw-text-amber-700 tw-border-amber-200; }
.ezx-badge--emerald{ @apply tw-bg-emerald-50 tw-text-emerald-700 tw-border-emerald-200; }
.ezx-badge--rose   { @apply tw-bg-rose-50 tw-text-rose-700 tw-border-rose-200; }
.ezx-badge--violet { @apply tw-bg-violet-50 tw-text-violet-700 tw-border-violet-200; }
.ezx-badge--urgent { @apply tw-bg-rose-600 tw-text-white tw-border-rose-600; }
.ezx-chip     { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-full tw-px-3 tw-py-1.5 tw-text-[.76rem] tw-font-medium tw-text-slate-600 tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-cursor-pointer tw-transition tw-select-none hover:tw-border-slate-400 hover:tw-text-slate-900; }
.ezx-chip--on { @apply tw-bg-blue-600 tw-text-white tw-border-blue-600 hover:tw-text-white hover:tw-border-blue-600; }
.ezx-chip input { @apply tw-sr-only; }
.ezx-chip:has(input:focus-visible) { @apply tw-outline tw-outline-[3px] tw-outline-amber-400; }
.ezx-seg      { @apply tw-inline-flex tw-p-0.5 tw-rounded-lg tw-bg-slate-100 tw-border tw-border-solid tw-border-slate-200; }
.ezx-seg button { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-md tw-px-2.5 tw-py-1 tw-text-xs tw-font-semibold tw-text-slate-600 tw-bg-transparent tw-border-0 tw-cursor-pointer hover:tw-text-slate-900; }
.ezx-seg button[aria-pressed="true"] { @apply tw-bg-white tw-text-blue-700 tw-shadow-sm; }
.ezx-znak     { @apply tw-font-mono tw-text-[.7rem] tw-font-bold tw-text-blue-700; }
.ezx-field label { @apply tw-block tw-text-[.68rem] tw-font-semibold tw-uppercase tw-tracking-[.06em] tw-text-slate-500 tw-mb-1; }
/* Pozycja listy — ten sam DOM w układzie listy i kafli (klasy .sprawa-line/.sprawa-row/.bulk-cb/.sp-* to zaczepy skryptów) */
.ezx-list .sprawa-line { @apply tw-flex tw-items-center tw-gap-2 tw-px-3 tw-py-2.5 tw-border-b tw-border-solid tw-border-slate-100 last:tw-border-b-0 tw-transition hover:tw-bg-slate-50; }
.ezx-grid     { @apply tw-grid tw-grid-cols-1 md:tw-grid-cols-2 2xl:tw-grid-cols-3 tw-gap-3 tw-p-3; }
.ezx-grid .sprawa-line { @apply tw-relative tw-flex tw-flex-col tw-gap-2 tw-rounded-2xl tw-border tw-border-solid tw-border-slate-200 tw-bg-white tw-p-3.5 tw-transition hover:tw-border-blue-300 hover:tw-shadow-md; }
.sprawa-line.is-hidden { @apply tw-opacity-60; background: repeating-linear-gradient(135deg, transparent, transparent 6px, rgba(148,163,184,.08) 6px, rgba(148,163,184,.08) 7px); }
.sprawa-row   { @apply tw-flex-1 tw-min-w-0 tw-no-underline tw-text-slate-900 tw-cursor-pointer tw-flex tw-flex-col tw-gap-1; }
.sprawa-row:hover { @apply tw-text-slate-900; }
.ezx-rowhead  { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-1.5; }
.ezx-rowtitle { @apply tw-text-[.88rem] tw-font-semibold tw-leading-snug; }
.ezx-list .ezx-rowtitle { @apply tw-truncate; }
.ezx-rowmeta  { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-x-3 tw-gap-y-0.5 ezx-muted; }
.ezx-rowmeta .ezx-sep { @apply tw-hidden; }
.ezx-rowactions { @apply tw-flex tw-items-center tw-gap-1 tw-shrink-0; }
.ezx-grid .ezx-rowactions { @apply tw-absolute tw-top-2.5 tw-right-2.5; }
.ezx-grid .bulk-cb-wrap { @apply tw-absolute tw-top-3 tw-left-3; }
.ezx-grid .sprawa-row { @apply tw-pt-6 tw-pl-6; }
.ezx-list .bulk-cb-wrap { @apply tw-pl-1; }
.sp-full-btn  { @apply tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-rounded-lg tw-text-indigo-700 tw-no-underline tw-opacity-60 tw-transition hover:tw-opacity-100 hover:tw-bg-indigo-50; }
.sp-hide-btn  { @apply tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-rounded-lg tw-bg-transparent tw-border-0 tw-text-slate-400 tw-cursor-pointer tw-opacity-0 tw-transition hover:tw-text-rose-600 hover:tw-bg-rose-50; }
.sprawa-line:hover .sp-hide-btn, .sp-hide-btn:focus-visible, .ezx-grid .sp-hide-btn { @apply tw-opacity-100; }
.sprawa-line.is-hidden .sp-hide-btn { @apply tw-opacity-100 tw-text-slate-500 hover:tw-text-blue-700; }
.dekr-cell    { @apply tw-flex tw-items-center tw-gap-1 tw-text-[.72rem]; }
/* Timeline w popupie koszulki (ezd/sprawy/ajax_panel.php) — bez zmian kontraktu klas */
.tl-wrap{display:flex;flex-direction:column;gap:.5rem}
.tl-item{display:flex;align-items:flex-start;gap:.6rem}
.tl-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:.55rem;border:2px solid}
.tl-card{flex:1;border:1px solid #e2e8f0;border-radius:10px;padding:.5rem .75rem;background:#fff;transition:box-shadow .12s;color:inherit}
.tl-card:hover{box-shadow:0 2px 8px rgba(15,23,42,.1)}
.tl-syg{font-size:.62rem;font-family:monospace;color:#64748b;margin-bottom:.15rem}
.tl-title{font-size:.83rem;font-weight:600;color:#1e293b;line-height:1.3}
.tl-meta{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.3rem;font-size:.7rem;color:#64748b}
.pismo-in .tl-dot{border-color:#0ea5e9;background:#e0f2fe}
.pismo-out .tl-dot{border-color:#8b5cf6;background:#ede9fe}
.pismo-int .tl-dot{border-color:#94a3b8;background:#f1f5f9}
.tl-card.pismo-in{border-left:3px solid #0ea5e9}
.tl-card.pismo-out{border-left:3px solid #8b5cf6}
.tl-card.pismo-int{border-left:3px solid #94a3b8}
</style>

<div x-data="ezdSprawy(<?= h(json_encode(['filtersOpen' => $n_filters > 0], JSON_UNESCAPED_SLASHES)) ?>)" x-cloak>

<!-- ═══════════ NAGŁÓWEK ═══════════ -->
<div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-4">
  <div>
    <div class="ezx-eyebrow tw-mb-1">Wirtualne biurko · EZD</div>
    <h1 class="tw-m-0 tw-text-[1.4rem] tw-font-bold tw-tracking-tight tw-text-slate-900"><i class="bi bi-folder2-open tw-text-blue-600 tw-mr-1" aria-hidden="true"></i>Koszulki</h1>
    <p class="tw-m-0 tw-mt-0.5 tw-text-sm tw-text-slate-500">
      <strong class="tw-text-slate-800"><?= count($sprawy) ?></strong> <?= count($sprawy) === 1 ? 'koszulka' : (count($sprawy) % 10 >= 2 && count($sprawy) % 10 <= 4 && (count($sprawy) % 100 < 12 || count($sprawy) % 100 > 14) ? 'koszulki' : 'koszulek') ?>
      <?= $n_filters ? ' spełnia filtry (' . $n_filters . ')' : ' w wykazie' ?><?= $cnt_over ? ' · <span class="tw-text-rose-600 tw-font-semibold">' . $cnt_over . ' po terminie</span>' : '' ?>
    </p>
  </div>
  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
    <a href="<?= h($self_q(['export' => 'csv'])) ?>" class="ezx-btn ezx-btn--ghost tw-py-2 tw-px-3.5 tw-text-sm"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Eksport CSV</a>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php" class="ezx-btn ezx-btn--ghost tw-py-2 tw-px-3.5 tw-text-sm"><i class="bi bi-stars" aria-hidden="true"></i>Przerejestrowanie (AI)</a>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="ezx-btn tw-py-2 tw-px-3.5 tw-text-sm"><i class="bi bi-folder-plus" aria-hidden="true"></i>Nowa koszulka</a>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════ SZUKAJ + FILTRY ═══════════ -->
<form method="get" class="tw-mb-4" role="search">
  <label for="ezx-q" class="tw-sr-only">Szukaj koszulek</label>
  <div class="tw-flex tw-items-center tw-gap-2 tw-rounded-2xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm tw-pl-4 tw-pr-2 tw-py-1.5 focus-within:tw-border-blue-400 focus-within:tw-ring-4 focus-within:tw-ring-blue-100">
    <i class="bi bi-search tw-text-slate-400" aria-hidden="true"></i>
    <input id="ezx-q" type="search" name="q" value="<?= h($q) ?>" autofocus autocomplete="off" class="tw-flex-1 tw-border-0 tw-bg-transparent tw-py-1.5 tw-text-[.95rem] tw-text-slate-900 tw-outline-none tw-min-w-0"
           placeholder="Szukaj po numerze dokumentu, numerze koszulki lub tytule…">
    <button type="button" class="ezx-btn ezx-btn--ghost tw-py-2" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen ? 'true' : 'false'" aria-controls="ezx-filters">
      <i class="bi bi-sliders" aria-hidden="true"></i>Filtry<?php if ($n_filters): ?> <span class="tw-rounded-full tw-bg-blue-600 tw-text-white tw-px-1.5 tw-text-[.68rem]"><?= $n_filters ?></span><?php endif; ?>
    </button>
    <button type="submit" class="ezx-btn tw-py-2 tw-px-4 tw-text-sm">Szukaj</button>
  </div>

  <div id="ezx-filters" x-show="filtersOpen" x-collapse x-cloak>
    <div class="ezx-card tw-mt-3 tw-p-4">
      <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 2xl:tw-grid-cols-6 tw-gap-3">
        <div class="ezx-field">
          <label for="f-status">Status</label>
          <select id="f-status" name="status" class="form-select form-select-sm">
            <option value="">Wszystkie</option>
            <?php foreach (EZD_STATUSES_SPRAWA as $sv => $sl): ?><option value="<?= $sv ?>" <?= $status_f === $sv ? 'selected' : '' ?>><?= h($sl['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="ezx-field">
          <label for="f-priority">Priorytet</label>
          <select id="f-priority" name="priority" class="form-select form-select-sm">
            <option value="">Wszystkie</option>
            <?php foreach (EZD_PRIORITIES as $pv => $pl): ?><option value="<?= $pv ?>" <?= $priority_f === $pv ? 'selected' : '' ?>><?= h($pl['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="ezx-field">
          <label for="f-owner">Właściciel</label>
          <select id="f-owner" name="owner_id" class="form-select form-select-sm">
            <option value="">Wszyscy</option>
            <?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $owner_f === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="ezx-field"><label for="f-dod">Termin od</label><input type="date" id="f-dod" name="deadline_od" class="form-control form-control-sm" value="<?= h($dod) ?>"></div>
        <div class="ezx-field"><label for="f-ddo">Termin do</label><input type="date" id="f-ddo" name="deadline_do" class="form-control form-control-sm" value="<?= h($ddo) ?>"></div>
        <div class="tw-flex tw-items-end tw-gap-2">
          <button type="submit" class="ezx-btn tw-py-2 tw-flex-1"><i class="bi bi-funnel" aria-hidden="true"></i>Filtruj</button>
          <?php if ($n_filters || $q !== ''): ?><a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="ezx-btn ezx-btn--ghost tw-py-2" title="Wyczyść filtry"><i class="bi bi-x-lg" aria-hidden="true"></i></a><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-mt-3" role="group" aria-label="Szybkie przełączniki">
    <label class="ezx-chip<?= $mine_f ? ' ezx-chip--on' : '' ?>"><input type="checkbox" name="mine" value="1" <?= $mine_f ? 'checked' : '' ?> onchange="this.form.submit()"><i class="bi bi-person-check" aria-hidden="true"></i>Tylko moje i współdzielone</label>
    <label class="ezx-chip<?= $hide_ciagla_f ? ' ezx-chip--on' : '' ?>"><input type="checkbox" name="hide_ciagla" value="1" <?= $hide_ciagla_f ? 'checked' : '' ?> onchange="this.form.submit()"><i class="bi bi-infinity" aria-hidden="true"></i>Ukryj ciągle otwarte</label>
    <label class="ezx-chip<?= $hide_old_f ? ' ezx-chip--on' : '' ?>"><input type="checkbox" name="hide_old" value="1" <?= $hide_old_f ? 'checked' : '' ?> onchange="this.form.submit()"><i class="bi bi-clock-history" aria-hidden="true"></i>Ukryj starsze niż 14 dni</label>
    <?php if (can_edit()): ?>
    <label class="ezx-chip<?= $show_hidden_f ? ' ezx-chip--on' : '' ?>"><input type="checkbox" name="show_hidden" value="1" <?= $show_hidden_f ? 'checked' : '' ?> onchange="this.form.submit()"><i class="bi bi-eye-slash" aria-hidden="true"></i>Pokaż ukryte</label>
    <?php endif; ?>
    <?php if ($active_filters): ?>
    <span class="ezx-muted tw-ml-1">Aktywne:
      <?php foreach ($active_filters as $fk => $fv): $lbl = match ($fk) {
          'status' => 'status: ' . (EZD_STATUSES_SPRAWA[$fv]['label'] ?? $fv), 'priority' => 'priorytet: ' . (EZD_PRIORITIES[$fv]['label'] ?? $fv),
          'owner_id' => 'właściciel: ' . ($owner_names[(int)$fv] ?? $fv), 'deadline_od' => 'od ' . $fv, 'deadline_do' => 'do ' . $fv, default => $fv }; ?>
      <a href="<?= h($self_q([$fk => ''])) ?>" class="ezx-badge ezx-badge--blue tw-no-underline tw-ml-1" title="Usuń ten filtr"><?= h($lbl) ?> <i class="bi bi-x" aria-hidden="true"></i></a>
      <?php endforeach; ?>
    </span>
    <?php endif; ?>
  </div>
</form>

<!-- ═══════════ WYNIKI ═══════════ -->
<?php include dirname(dirname(__DIR__)) . '/modules/ezd_bulk/view/result.php'; ?>
<div class="ezx-card">
  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-100">
    <span class="tw-text-[.9rem] tw-font-semibold tw-text-slate-900"><i class="bi bi-folder2 tw-text-blue-600 tw-mr-1" aria-hidden="true"></i>Wyniki <span class="ezx-muted tw-font-normal">(<?= count($sprawy) ?>)</span></span>
    <?php if (can_edit() && $sprawy): ?>
    <label class="tw-inline-flex tw-items-center tw-gap-2 tw-text-xs tw-text-slate-600 tw-cursor-pointer tw-m-0">
      <input type="checkbox" id="bulkAll" class="form-check-input tw-m-0">Zaznacz wszystkie
    </label>
    <?php endif; ?>
    <div class="ezx-seg tw-ml-auto" role="group" aria-label="Układ listy">
      <button type="button" :aria-pressed="view === 'list' ? 'true' : 'false'" @click="setView('list')" title="Lista"><i class="bi bi-list-ul" aria-hidden="true"></i><span class="tw-hidden sm:tw-inline">Lista</span></button>
      <button type="button" :aria-pressed="view === 'cards' ? 'true' : 'false'" @click="setView('cards')" title="Kafle"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="tw-hidden sm:tw-inline">Kafle</span></button>
    </div>
  </div>

  <div :class="view === 'cards' ? 'ezx-grid' : 'ezx-list'">
    <?php foreach ($sprawy as $s):
      $lz   = ezd_lezy_since($s['dekr_since'] ?? null);
      $em   = ezd_etap_meta($s['etap'] ?: 'wszczeta');
      $st   = EZD_STATUSES_SPRAWA[$s['status']] ?? ['label' => $s['status'], 'class' => 'secondary'];
      $pr   = EZD_PRIORITIES[$s['priority']] ?? ['label' => $s['priority'], 'class' => 'secondary'];
      $over = $s['deadline'] && $s['deadline'] < $today && empty($s['ciagla']) && $s['status'] !== 'closed';
      $hid  = !empty($s['hidden_at']);
    ?>
    <div class="sprawa-line<?= $hid ? ' is-hidden' : '' ?>" data-sprawa-id="<?= (int)$s['id'] ?>">
      <?php if (can_edit()): ?>
      <div class="bulk-cb-wrap"><input type="checkbox" class="form-check-input bulk-cb tw-m-0" value="<?= (int)$s['id'] ?>" data-ciagla="<?= empty($s['ciagla']) ? '0' : '1' ?>" data-status="<?= h($s['status']) ?>" aria-label="Zaznacz koszulkę <?= h($s['znak_sprawy']) ?>"></div>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="sprawa-row ezd-sp-preview"
         data-sprawa-id="<?= (int)$s['id'] ?>" data-sprawa-title="<?= h(mb_substr($s['title'], 0, 60)) ?>"
         aria-label="Podgląd koszulki: <?= h($s['title']) ?>. Ctrl+klik otwiera pełny widok.">
        <div class="ezx-rowhead">
          <span class="ezx-znak"><?= h($s['znak_sprawy']) ?></span>
          <span class="ezx-badge ezx-badge--<?= $s['etap'] === 'akceptacja' ? 'violet' : ($TONE[$em['class']] ?? 'slate') ?>"><i class="bi <?= h($em['icon'] ?? 'bi-record-circle') ?>" aria-hidden="true"></i><?= $s['etap'] === 'akceptacja' ? 'W akceptacji' : h($em['label']) ?></span>
          <?php if ($s['status'] !== 'open'): ?><span class="ezx-badge ezx-badge--<?= $TONE[$st['class']] ?? 'slate' ?>"><?= h($st['label']) ?></span><?php endif; ?>
          <?php if (in_array($s['priority'], ['high', 'urgent'], true)): ?><span class="ezx-badge ezx-badge--<?= $TONE[$pr['class']] ?? 'slate' ?>"><i class="bi bi-flag-fill" aria-hidden="true"></i><?= h($pr['label']) ?></span><?php endif; ?>
          <?php if ($over): ?><span class="ezx-badge ezx-badge--urgent"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Pilne</span><?php endif; ?>
          <?php if (!empty($s['ciagla'])): ?><span class="ezx-badge ezx-badge--sky" title="Koszulka ciągła"><i class="bi bi-infinity" aria-hidden="true"></i>ciągła</span><?php endif; ?>
          <?php if ($hid): ?><span class="ezx-badge ezx-badge--slate"><i class="bi bi-eye-slash" aria-hidden="true"></i>ukryta</span><?php endif; ?>
        </div>
        <div class="ezx-rowtitle"><?= h($s['title']) ?></div>
        <div class="ezx-rowmeta">
          <span class="tw-truncate tw-max-w-[280px]" title="<?= h($s['teczka_symbol'] . ' — ' . $s['teczka_title']) ?>"><i class="bi bi-archive tw-mr-0.5" aria-hidden="true"></i><?= h($s['teczka_symbol']) ?> <span class="tw-text-slate-400">· <?= h(mb_strimwidth($s['teczka_title'], 0, 40, '…')) ?></span></span>
          <span title="Właściciel"><i class="bi bi-person tw-mr-0.5" aria-hidden="true"></i><?= h($s['owner_name'] ?? '—') ?></span>
          <?php if (!empty($s['ciagla'])): ?><span class="tw-text-sky-600"><i class="bi bi-infinity" aria-hidden="true"></i> stale otwarta</span>
          <?php elseif ($s['deadline']): ?><span class="<?= $over ? 'tw-text-rose-600 tw-font-semibold' : '' ?>"><i class="bi bi-calendar-event tw-mr-0.5" aria-hidden="true"></i>termin <?= date_pl($s['deadline']) ?></span><?php endif; ?>
          <?php if (!empty($s['dekr_wykonawca_name'])): ?>
          <span class="dekr-cell"><i class="bi bi-person-fill tw-text-amber-500" aria-hidden="true"></i><span class="tw-font-semibold tw-text-slate-700"><?= h($s['dekr_wykonawca_name']) ?></span><span class="<?= $lz['class'] === 'danger' ? 'tw-text-rose-600' : ($lz['class'] === 'warning' ? 'tw-text-amber-600' : 'tw-text-slate-500') ?>"><i class="bi bi-hourglass-split" aria-hidden="true"></i> leży: <?= h($lz['label']) ?></span></span>
          <?php else: ?><span class="tw-text-slate-400"><i class="bi bi-dash-circle" aria-hidden="true"></i> nieprzekazana</span><?php endif; ?>
        </div>
      </a>
      <div class="ezx-rowactions">
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="sp-full-btn" title="Przejdź do sprawy — pełny widok" aria-label="Przejdź do sprawy: <?= h($s['title']) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></a>
        <?php if (can_edit()): ?>
        <button type="button" class="sp-hide-btn" data-id="<?= (int)$s['id'] ?>" data-hidden="<?= $hid ? '1' : '0' ?>"
                title="<?= $hid ? 'Przywróć na liście' : 'Ukryj koszulkę na liście' ?>" aria-label="<?= $hid ? 'Przywróć koszulkę na liście' : 'Ukryj koszulkę na liście' ?>">
          <i class="bi <?= $hid ? 'bi-eye' : 'bi-eye-slash' ?>" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$sprawy): ?>
    <div class="tw-text-center tw-py-12 tw-px-4 tw-col-span-full">
      <i class="bi bi-folder2 tw-text-4xl tw-text-slate-300 tw-block tw-mb-2" aria-hidden="true"></i>
      <div class="tw-font-semibold tw-text-slate-800">Brak koszulek pasujących do filtrów</div>
      <div class="ezx-muted tw-mt-1"><?php if ($n_filters || $q !== ''): ?><a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="tw-text-blue-700">Wyczyść filtry</a><?php endif; ?><?php if (can_edit()): ?> <?= ($n_filters || $q !== '') ? '·' : '' ?> <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="tw-text-blue-700">Załóż nową koszulkę</a><?php endif; ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (can_edit() && $sprawy) include dirname(dirname(__DIR__)) . '/modules/ezd_bulk/view/bar.php'; ?>
</div>

</div><!-- /x-data ezdSprawy -->

<script>
document.addEventListener('alpine:init', function () {
  // Układ (lista/kafle) zapamiętywany w przeglądarce; panel filtrów otwarty, gdy jakiś filtr jest aktywny.
  Alpine.data('ezdSprawy', function (cfg) {
    return {
      filtersOpen: !!cfg.filtersOpen, view: 'list',
      init: function () { try { var v = localStorage.getItem('ezd_sprawy_view'); if (v === 'cards' || v === 'list') this.view = v; } catch (e) {} },
      setView: function (v) { this.view = v; try { localStorage.setItem('ezd_sprawy_view', v); } catch (e) {} }
    };
  });
});
</script>

<?php if (can_edit() && $sprawy): ?>
<!-- Modal: masowe przerejestrowanie (Bootstrap) -->
<div class="modal fade" id="bulkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="bulkForm" method="post" action="<?= h($_SERVER['REQUEST_URI']) ?>" data-ezb-ids>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2 text-success"></i>Przerejestruj zaznaczone koszulki</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="bulk_reregister">
          <p class="small mb-3">Zaznaczono: <span class="fw-bold" id="bulkModalCount">0</span> koszulek. Wskaż, gdzie je przenieść — każda otrzyma nowy znak w docelowym segregatorze.</p>
          <label class="form-label fw-semibold small">Przenieś do:</label>
          <select name="target" class="form-select" required>
            <option value="">— segregator lub klasa JRWA —</option>
            <?php if ($teczki_cele): ?>
            <optgroup label="Istniejące segregatory (otwarte)">
              <?php foreach ($teczki_cele as $t): ?>
              <option value="teczka:<?= (int)$t['id'] ?>"><?= h($t['symbol']) ?> · <?= h($t['title']) ?> (<?= (int)$t['rok'] ?>)<?= $t['jrwa_symbol'] ? ' — JRWA ' . h($t['jrwa_symbol']) : '' ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <optgroup label="Utwórz/uzupełnij segregator w klasie JRWA">
              <?php foreach ($jrwa_cele as $j): ?>
              <option value="jrwa:<?= h($j['symbol']) ?>"><?= h($j['symbol']) ?> — <?= h($j['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
          <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Pomijane: podkoszulki, koszulki z podkoszulkami oraz te już w wskazanym segregatorze. Operacja nieodwracalna.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-arrow-left-right me-1"></i>Przenieś zaznaczone</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php $ezbBack = $_SERVER['REQUEST_URI'] ?? ''; include dirname(dirname(__DIR__)) . '/modules/ezd_bulk/view/modals.php'; ?>
<?php endif; ?>

<!-- Modal: szybki podgląd koszulki (Bootstrap; treść z ezd/sprawy/ajax_panel.php) -->
<div class="modal fade" id="ksModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="ksModalLabel">
  <div class="modal-dialog modal-xl modal-fullscreen-md-down" style="--bs-modal-width:min(900px,96vw)">
    <div class="modal-content" style="border-radius:16px;overflow:hidden">
      <div class="modal-header py-2" style="border-bottom:1px solid #e2e8f0">
        <h2 class="modal-title fw-bold mb-0" id="ksModalLabel" style="font-size:.88rem">
          <i class="bi bi-folder2-open text-primary me-2" aria-hidden="true"></i>
          <span id="ksModalTitleText">Koszulka</span>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij podgląd koszulki"></button>
      </div>
      <div class="modal-body p-3" id="ksModalBody" style="min-height:340px;max-height:82vh;overflow-y:auto">
        <div id="ksModalSpinner" class="text-center py-5 text-muted">
          <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
          Ładowanie koszulki…
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('ksModal');
  if (!modalEl) return;
  var bsModal  = new bootstrap.Modal(modalEl, {focus: true});
  var body     = document.getElementById('ksModalBody');
  var titleEl  = document.getElementById('ksModalTitleText');
  var SPINNER  = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>Ładowanie…</div>';
  var cache    = {};  // id → html
  var curId    = null;

  function open(id, title) {
    titleEl.textContent = title;
    curId = id;
    if (cache[id]) {
      body.innerHTML = cache[id];
    } else {
      body.innerHTML = SPINNER;
      fetch('<?= APP_URL ?>/ezd/sprawy/ajax_panel.php?id=' + id, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function (html) { if (curId === id) { body.innerHTML = html; cache[id] = html; } })
        .catch(function () { if (curId === id) body.innerHTML = '<p class="text-danger p-3 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Nie udało się załadować koszulki.</p>'; });
    }
    bsModal.show();
    setTimeout(function () { var first = body.querySelector('a,button,[tabindex="0"]'); if (first) first.focus(); }, 350);
  }

  var lastTrigger = null;
  document.querySelectorAll('.ezd-sp-preview').forEach(function (el) {
    el.addEventListener('click', function (e) {
      /* Ctrl/Cmd/Shift+klik → normalna nawigacja do pełnego widoku */
      if (e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      lastTrigger = el;
      open(el.dataset.sprawaId, el.dataset.sprawaTitle || 'Koszulka');
    });
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.click(); } });
  });

  /* Czyść stan i oddaj fokus wierszowi po zamknięciu (WCAG 2.5.3) */
  modalEl.addEventListener('hidden.bs.modal', function () {
    curId = null;
    if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
  });
})();
</script>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var HIDE_URL = '<?= APP_URL ?>/ezd/sprawy/ajax_hide.php';
  var showHiddenActive = <?= $show_hidden_f ? 'true' : 'false' ?>;

  document.querySelectorAll('.sp-hide-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var id     = btn.dataset.id;
      var isHid  = btn.dataset.hidden === '1';
      var action = isHid ? 'unhide' : 'hide';
      var line   = btn.closest('.sprawa-line');

      btn.disabled = true;
      var fd = new FormData();
      fd.append('_csrf', CSRF); fd.append('id', id); fd.append('action', action);

      fetch(HIDE_URL, {method:'POST', credentials:'same-origin', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data.ok) { alert(data.error || 'Błąd'); return; }
          var nowHidden = data.hidden;
          btn.dataset.hidden = nowHidden ? '1' : '0';
          btn.title = nowHidden ? 'Przywróć na liście' : 'Ukryj koszulkę na liście';
          btn.setAttribute('aria-label', btn.title);
          btn.querySelector('i').className = 'bi ' + (nowHidden ? 'bi-eye' : 'bi-eye-slash');
          if (nowHidden) {
            line.classList.add('is-hidden');
            if (!showHiddenActive) {
              /* jeśli nie wyświetlamy ukrytych — wygaś wiersz i usuń po chwili */
              line.style.transition = 'opacity .4s';
              line.style.opacity = '0';
              setTimeout(function () { line.remove(); }, 420);
            }
          } else {
            line.classList.remove('is-hidden');
            line.style.opacity = '';
          }
        })
        .catch(function () { btn.disabled = false; alert('Błąd połączenia.'); });
    });
  });
})();
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
