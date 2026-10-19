<?php
/**
 * modules/strategy_actions/view/index.php — ekran Działań: filtry + przełączane widoki (pivot Metro).
 * Dane całego rejestru idą raz jako JSON; filtrowanie, sortowanie i widoki liczy Alpine po stronie przeglądarki.
 * Zmienne: $actions (ActionsService::all()), $users, $grants, $init (filtry z adresu).
 */
$navActive = 'lista';
$dzData = [
    'actions'  => $actions,
    'statuses' => ActionsService::STATUSES,
    'active'   => ActionsService::ACTIVE,
    'types'    => ActionsService::TYPES,
    'goals'    => ActionsService::goals(),
    'init'     => $init,
    'base'     => ActionsService::url(),
    'today'    => date('Y-m-d'),
];
include __DIR__ . '/_head.php';
?>
<div x-data="dzApp()" x-init="init()">
  <div class="dz-top">
    <div>
      <h1 class="dz-title">Działania</h1>
      <p class="dz-sub">Projekty, wydarzenia i usługi organizacji — finansowanie, wskaźniki, terminy.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap dz-noprint">
      <a href="<?= h(ActionsService::url('nowe')) ?>" class="dz-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe działanie</a>
    </div>
  </div>

  <div class="dz-pivot dz-noprint" role="group" aria-label="Widok działań">
    <template x-for="v in views" :key="v.key">
      <button type="button" @click="view = v.key" :aria-pressed="view === v.key ? 'true' : 'false'" x-text="v.label"></button>
    </template>
  </div>

  <form class="dz-filters dz-noprint" role="search" aria-label="Filtry działań" @submit.prevent>
    <div class="wide">
      <label class="dz-lbl" for="dz-q">Szukaj</label>
      <input id="dz-q" type="search" class="dz-in" x-model.debounce.200ms="f.q" placeholder="Nazwa, opis, miejsce, grant, osoba…">
    </div>
    <div>
      <label class="dz-lbl" for="dz-st">Status</label>
      <select id="dz-st" class="dz-in" x-model="f.status">
        <option value="">Wszystkie</option>
        <option value="aktywne">W toku (planowane, przygotowanie, trwające)</option>
        <?php foreach (ActionsService::STATUSES as $k => $s): ?>
        <option value="<?= h($k) ?>"><?= h($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="dz-typ">Typ</label>
      <select id="dz-typ" class="dz-in" x-model="f.typ">
        <option value="">Wszystkie</option>
        <?php foreach (ActionsService::TYPES as $k => $t): ?>
        <option value="<?= h($k) ?>"><?= h($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="dz-ko">Koordynator</label>
      <select id="dz-ko" class="dz-in" x-model="f.koord">
        <option value="">Wszyscy</option>
        <option value="brak">— bez koordynatora —</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="dz-gr">Finansowanie</label>
      <select id="dz-gr" class="dz-in" x-model="f.grant">
        <option value="">Wszystkie</option>
        <option value="wlasne">Działania własne</option>
        <option value="brak">Bez grantu</option>
        <?php foreach ($grants as $g): ?>
        <option value="<?= (int)$g['id'] ?>"><?= h($g['nazwa']) ?><?= $g['donator'] ? ' — ' . h($g['donator']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="dz-cel">Cel statutowy §6</label>
      <select id="dz-cel" class="dz-in" x-model="f.cel">
        <option value="">Wszystkie</option>
        <option value="brak">— bez celu —</option>
        <?php foreach (ActionsService::goals() as $k => $l): ?>
        <option value="<?= (int)$k ?>"><?= (int)$k ?>. <?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="dz-od">Od</label>
      <input id="dz-od" type="date" class="dz-in" x-model="f.od">
    </div>
    <div>
      <label class="dz-lbl" for="dz-do">Do</label>
      <input id="dz-do" type="date" class="dz-in" x-model="f.do">
    </div>
    <div class="d-flex align-items-end">
      <button type="button" class="dz-btn dz-btn--ghost w-100 justify-content-center" @click="resetFilters()" :disabled="!filtersOn">
        <i class="bi bi-x-circle" aria-hidden="true"></i>Wyczyść filtry
      </button>
    </div>
  </form>

  <p class="dz-muted small mb-2" role="status" aria-live="polite">
    Pokazano <b x-text="rows.length"></b> z <span x-text="all.length"></span> działań<span x-show="filtersOn"> (filtry włączone)</span>.
  </p>

  <!-- LISTA -->
  <section x-show="view === 'lista'" aria-label="Lista działań">
    <div class="dz-scroll" x-show="rows.length">
      <table class="dz-table">
        <thead>
          <tr>
            <template x-for="c in columns" :key="c.key">
              <th scope="col" :class="c.num ? 'dz-num' : ''" :aria-sort="sort.key === c.key ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none'">
                <button type="button" @click="sortBy(c.key)">
                  <span x-text="c.label"></span>
                  <i class="bi" :class="sort.key === c.key ? (sort.dir > 0 ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : ''" aria-hidden="true"></i>
                </button>
              </th>
            </template>
            <th scope="col"><span class="visually-hidden">Akcje</span></th>
          </tr>
        </thead>
        <tbody>
          <template x-for="a in rows" :key="a.id">
            <tr>
              <td style="min-width:240px">
                <a class="nm" :href="url(a.id)" x-text="a.nazwa"></a>
                <span x-show="a.wlasne" class="dz-chip" title="Działanie własne — finansowane ze środków organizacji"><i class="bi bi-star-fill" aria-hidden="true"></i> własne</span>
                <div><template x-for="fl in flagsOf(a)" :key="fl.key"><span class="dz-flag" :class="fl.key" x-text="fl.label"></span></template></div>
              </td>
              <td class="text-nowrap"><i class="bi" :class="typeIcon(a.typ)" aria-hidden="true"></i> <span x-text="typeLabel(a.typ)"></span></td>
              <td x-text="a.koordynator || '—'"></td>
              <td><span class="dz-badge" :style="'--c:' + statusColor(a.status)" x-text="statusLabel(a.status)"></span></td>
              <td class="text-nowrap" x-text="period(a)"></td>
              <td style="min-width:150px">
                <template x-for="g in a.grants" :key="g.id"><span class="dz-chip" x-text="g.nazwa"></span></template>
                <div class="dz-muted small" x-show="a.kwota > 0" x-text="money(a.kwota)"></div>
                <span x-show="!a.grants.length" class="dz-muted" x-text="a.wlasne ? 'środki własne' : '—'"></span>
              </td>
              <td style="min-width:120px">
                <template x-if="a.pct !== null">
                  <div class="d-flex align-items-center gap-2" :title="num(a.indReal) + ' / ' + num(a.indPlan)">
                    <div class="dz-bar"><span :style="'width:' + a.pct + '%;--c:' + pctColor(a.pct)"></span></div>
                    <small x-text="a.pct + '%'"></small>
                  </div>
                </template>
                <span x-show="a.pct === null" class="dz-muted">—</span>
              </td>
              <td class="text-nowrap text-end">
                <a class="dz-btn dz-btn--ghost dz-btn--sm" :href="url(a.id, 'edycja')" :aria-label="'Edytuj: ' + a.nazwa"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="dz-empty" x-show="!rows.length">
      Brak działań spełniających kryteria.
      <a href="<?= h(ActionsService::url('nowe')) ?>">Dodaj działanie</a>
    </div>
  </section>

  <!-- KALENDARZ -->
  <section x-show="view === 'kalendarz'" x-cloak aria-label="Kalendarz działań">
    <div id="dz-cal"></div>
    <p class="dz-muted small mt-2">Kalendarz pokazuje działania z datą rozpoczęcia (bez anulowanych), zgodnie z filtrami.</p>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
window.DZ = <?= json_encode($dzData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function dzApp() {
  const D = window.DZ;
  const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
  const emptyF = () => ({q: '', status: '', typ: '', koord: '', grant: '', cel: '', od: '', do: '', flag: ''});
  const statusOrder = Object.keys(D.statuses);
  return {
    all: D.actions,
    view: 'lista',
    views: [{key: 'lista', label: 'Lista'}, {key: 'kalendarz', label: 'Kalendarz'}],
    f: emptyF(),
    sort: {key: 'od', dir: -1},
    columns: [
      {key: 'nazwa', label: 'Nazwa'}, {key: 'typ', label: 'Typ'}, {key: 'koordynator', label: 'Koordynator'},
      {key: 'status', label: 'Status'}, {key: 'od', label: 'Okres'}, {key: 'kwota', label: 'Finansowanie'},
      {key: 'pct', label: 'Wskaźniki'},
    ],
    cal: null,

    init() {
      let saved = {};
      try { saved = JSON.parse(localStorage.getItem('dz.state') || '{}') || {}; } catch (e) {}
      const fromUrl = Object.keys(emptyF()).filter(k => D.init[k]);
      if (fromUrl.length) fromUrl.forEach(k => this.f[k] = String(D.init[k]));
      else if (saved.f) Object.keys(emptyF()).forEach(k => { if (saved.f[k] !== undefined) this.f[k] = String(saved.f[k]); });
      if (saved.sort && saved.sort.key) this.sort = saved.sort;
      const keys = this.views.map(v => v.key);
      this.view = keys.includes(D.init.widok) ? D.init.widok : (keys.includes(saved.view) ? saved.view : 'lista');
      this.$watch('f', () => this.changed());
      this.$watch('view', () => this.changed());
      this.changed();
    },
    changed() {
      try { localStorage.setItem('dz.state', JSON.stringify({view: this.view, f: this.f, sort: this.sort})); } catch (e) {}
      if (this.view === 'kalendarz') this.$nextTick(() => this.renderCal());
    },
    get filtersOn() { return Object.values(this.f).some(v => v !== ''); },
    resetFilters() { this.f = emptyF(); },

    match(a, skipFlag) {
      const f = this.f;
      if (f.status === 'aktywne' ? !D.active.includes(a.status) : (f.status && a.status !== f.status)) return false;
      if (f.typ && a.typ !== f.typ) return false;
      if (f.koord === 'brak' ? a.koordynatorId !== null : (f.koord && String(a.koordynatorId) !== f.koord)) return false;
      if (f.grant === 'wlasne') { if (!a.wlasne) return false; }
      else if (f.grant === 'brak') { if (a.grants.length) return false; }
      else if (f.grant && !a.grants.some(g => String(g.id) === f.grant)) return false;
      if (f.cel === 'brak' ? a.cel !== null : (f.cel && String(a.cel) !== f.cel)) return false;
      if (f.od && (a.do || a.od || '') < f.od) return false;
      if (f.do && (!a.od || a.od > f.do)) return false;
      if (!skipFlag && f.flag && !this.hasFlag(a, f.flag)) return false;
      if (f.q) {
        const hay = fold([a.nazwa, a.opis, a.koordynator, a.lokalizacja, ...a.grants.map(g => g.nazwa)].join(' '));
        if (!fold(f.q).split(/\s+/).filter(Boolean).every(w => hay.includes(w))) return false;
      }
      return true;
    },
    hasFlag(a, flag) {
      if (flag === 'aktywne') return D.active.includes(a.status);
      return !!(a.flags && a.flags[flag]);
    },
    get rows() {
      const k = this.sort.key, dir = this.sort.dir;
      const val = a => k === 'status' ? statusOrder.indexOf(a.status)
        : (k === 'kwota' || k === 'pct') ? (a[k] ?? -1)
        : fold(k === 'typ' ? this.typeLabel(a.typ) : (a[k] ?? ''));
      return this.all.filter(a => this.match(a)).sort((x, y) => {
        const vx = val(x), vy = val(y);
        if (vx === vy) return y.id - x.id;
        if (vx === '' ) return 1;
        if (vy === '' ) return -1;
        return (vx < vy ? -1 : 1) * dir;
      });
    },
    sortBy(key) {
      this.sort = this.sort.key === key ? {key, dir: -this.sort.dir} : {key, dir: (key === 'od' || key === 'kwota' || key === 'pct') ? -1 : 1};
      this.changed();
    },

    flagsOf(a) {
      const L = {noGrant: 'Bez finansowania', overdue: 'Po terminie', ending: 'Kończy się ≤ 14 dni', behind: 'Wskaźniki < 50%'};
      return Object.keys(L).filter(k => a.flags && a.flags[k]).map(k => ({key: k, label: L[k]}));
    },
    url(id, sub) { return D.base + '/' + id + (sub ? '/' + sub : ''); },
    statusLabel(s) { return (D.statuses[s] || {label: s}).label; },
    statusColor(s) { return (D.statuses[s] || {color: '#5f5f5f'}).color; },
    typeLabel(t) { return (D.types[t] || {label: t}).label; },
    typeIcon(t) { return (D.types[t] || {icon: 'bi-three-dots'}).icon; },
    pctColor(p) { return p >= 100 ? '#008a00' : (p >= 50 ? '#00808a' : '#c25100'); },
    date(d) { return d ? d.slice(8, 10) + '.' + d.slice(5, 7) + '.' + d.slice(0, 4) : ''; },
    period(a) {
      if (!a.od && !a.do) return '—';
      if (a.od && a.do && a.od !== a.do) return this.date(a.od) + ' – ' + this.date(a.do);
      return this.date(a.od || a.do);
    },
    num(v) { return Number(v || 0).toLocaleString('pl-PL', {maximumFractionDigits: 2}); },
    money(v) { return Number(v || 0).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' zł'; },

    calEvents() {
      return this.rows.filter(a => a.od && a.status !== 'anulowane').map(a => {
        const end = a.do ? new Date(a.do + 'T00:00:00') : null;
        if (end) end.setDate(end.getDate() + 1);
        return {id: String(a.id), title: a.nazwa, start: a.od, end: end ? end.toISOString().slice(0, 10) : null,
                color: this.statusColor(a.status), allDay: true};
      });
    },
    renderCal() {
      if (typeof FullCalendar === 'undefined') return;
      if (!this.cal) {
        this.cal = new FullCalendar.Calendar(document.getElementById('dz-cal'), {
          initialView: 'dayGridMonth', locale: 'pl', firstDay: 1, height: 'auto',
          buttonText: {today: 'dziś', month: 'miesiąc', list: 'lista'},
          headerToolbar: {left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth'},
          events: (info, ok) => ok(this.calEvents()),
          eventClick: info => { info.jsEvent.preventDefault(); this.openAction(Number(info.event.id)); },
          eventDidMount: info => info.el.setAttribute('title', info.event.title),
        });
        this.cal.render();
      } else {
        this.cal.refetchEvents();
        this.cal.updateSize();
      }
    },
    openAction(id) { window.location = this.url(id); },
  };
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>
