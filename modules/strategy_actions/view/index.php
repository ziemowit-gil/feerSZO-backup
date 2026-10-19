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
    'csrf'     => Router::csrfToken(),
    'users'    => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], $users),
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

  <div class="dz-tiles dz-noprint" role="group" aria-label="Szybkie filtry — liczniki">
    <template x-for="t in tiles" :key="t.key">
      <button type="button" class="dz-tile" :style="'--c:' + t.color" @click="f.flag = f.flag === t.key ? '' : t.key"
              :aria-pressed="f.flag === t.key ? 'true' : 'false'">
        <span class="n" x-text="tileCount(t.key)"></span>
        <span><b x-text="t.label"></b><br><small x-text="t.hint"></small></span>
      </button>
    </template>
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

  <div class="dz-toast" role="status" aria-live="polite"><span x-show="msg" x-text="msg"></span></div>
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
                <a class="nm" :href="url(a.id)" x-text="a.nazwa" @click="openAction(a.id, $event)"></a>
                <span x-show="a.wlasne" class="dz-chip" title="Działanie własne — finansowane ze środków organizacji"><i class="bi bi-star-fill" aria-hidden="true"></i> własne</span>
                <div><template x-for="fl in flagsOf(a)" :key="fl.key"><span class="dz-flag" :class="fl.key" x-text="fl.label"></span></template></div>
                <div class="dz-muted small" x-show="a.ms.total">
                  <i class="bi bi-flag" aria-hidden="true"></i> etapy <span x-text="a.ms.done + '/' + a.ms.total"></span><template x-if="a.ms.next"><span x-text="' · następny: ' + a.ms.next.title + (a.ms.next.due ? ' (' + date(a.ms.next.due) + ')' : '')"></span></template>
                </div>
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

  <!-- TABLICA -->
  <section x-show="view === 'tablica'" x-cloak aria-label="Tablica działań według statusu">
    <p class="dz-muted small">Przeciągnij kartę do innej kolumny albo użyj przycisków ◀ ▶, aby zmienić status.</p>
    <div class="dz-board">
      <template x-for="(st, si) in statusKeys" :key="st">
        <div class="dz-col" :class="dragOver === st ? 'over' : ''" :style="'--c:' + statusColor(st)"
             @dragover.prevent="dragOver = st" @dragleave="dragOver = dragOver === st ? '' : dragOver" @drop.prevent="drop(st)">
          <h3><span x-text="statusLabel(st)"></span><span x-text="byStatus(st).length"></span></h3>
          <div class="cards">
            <template x-for="a in byStatus(st)" :key="a.id">
              <article class="dz-card" draggable="true" :style="'--c:' + statusColor(a.status)"
                       @dragstart="dragId = a.id; $event.dataTransfer.effectAllowed = 'move'" @dragend="dragId = null; dragOver = ''"
                       :aria-grabbed="dragId === a.id ? 'true' : 'false'">
                <a class="nm" :href="url(a.id)" x-text="a.nazwa" @click="openAction(a.id, $event)"></a>
                <div class="dz-muted"><i class="bi" :class="typeIcon(a.typ)" aria-hidden="true"></i> <span x-text="typeLabel(a.typ)"></span> · <span x-text="period(a)"></span></div>
                <div class="dz-muted" x-show="a.koordynator" x-text="a.koordynator"></div>
                <template x-if="a.pct !== null">
                  <div class="d-flex align-items-center gap-2 mt-1"><div class="dz-bar"><span :style="'width:' + a.pct + '%;--c:' + pctColor(a.pct)"></span></div><small x-text="a.pct + '%'"></small></div>
                </template>
                <div><template x-for="fl in flagsOf(a)" :key="fl.key"><span class="dz-flag" :class="fl.key" x-text="fl.label"></span></template></div>
                <div class="mv">
                  <button type="button" x-show="si > 0" @click="move(a, statusKeys[si - 1])" :aria-label="'Przenieś „' + a.nazwa + '” do: ' + statusLabel(statusKeys[si - 1])">◀</button>
                  <button type="button" x-show="si < statusKeys.length - 1" @click="move(a, statusKeys[si + 1])" :aria-label="'Przenieś „' + a.nazwa + '” do: ' + statusLabel(statusKeys[si + 1])">▶</button>
                </div>
              </article>
            </template>
          </div>
        </div>
      </template>
    </div>
  </section>

  <!-- OŚ CZASU -->
  <section x-show="view === 'os'" x-cloak aria-label="Oś czasu działań">
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" @click="year--" aria-label="Poprzedni rok"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
      <span style="font:300 1.4rem 'Segoe UI',system-ui,sans-serif" x-text="year" aria-live="polite"></span>
      <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" @click="year++" aria-label="Następny rok"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
      <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" @click="year = thisYear" x-show="year !== thisYear">Bieżący rok</button>
      <span class="dz-muted small ms-auto" x-show="undated.length" x-text="undated.length + ' bez dat — nie ma ich na osi'"></span>
    </div>
    <div class="dz-scroll">
      <div class="dz-tl">
        <div class="hd"><span class="lb">Działanie</span><div class="months"><template x-for="m in monthNames" :key="m"><span x-text="m"></span></template></div></div>
        <template x-for="a in timeline" :key="a.id">
          <div class="rw">
            <div class="lb"><a :href="url(a.id)" x-text="a.nazwa" :title="a.nazwa" @click="openAction(a.id, $event)"></a></div>
            <div class="track">
              <div class="mgrid" aria-hidden="true"><template x-for="m in 12" :key="m"><span></span></template></div>
              <span class="today" x-show="year === thisYear" :style="'left:' + todayPct + '%'" aria-hidden="true"></span>
              <a class="bar" :href="url(a.id)" @click="openAction(a.id, $event)" :class="bar(a).cls"
                 :style="'left:' + bar(a).left + '%;width:' + bar(a).width + '%;--c:' + statusColor(a.status)"
                 :aria-label="a.nazwa + ': ' + period(a) + ', ' + statusLabel(a.status)" :title="a.nazwa + ' · ' + period(a)"
                 x-text="bar(a).width > 8 ? statusLabel(a.status) : ''"></a>
            </div>
          </div>
        </template>
        <div class="dz-empty" x-show="!timeline.length">Brak działań w tym roku dla wybranych filtrów.</div>
      </div>
    </div>
  </section>

  <?php include __DIR__ . '/_panel.php'; ?>

  <!-- KALENDARZ -->
  <section x-show="view === 'kalendarz'" x-cloak aria-label="Kalendarz działań">
    <div id="dz-cal"></div>
    <p class="dz-muted small mt-2">Kalendarz pokazuje działania z datą rozpoczęcia (bez anulowanych), zgodnie z filtrami.</p>
  </section>
</div>

<?php include __DIR__ . '/_journalScript.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
window.DZ = <?= json_encode($dzData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function dzApp() {
  const D = window.DZ;
  const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
  const emptyF = () => ({q: '', status: '', typ: '', koord: '', grant: '', cel: '', od: '', do: '', flag: ''});
  const statusOrder = Object.keys(D.statuses);
  return {
    ...dzJournalMixin({base: D.base, csrf: D.csrf, users: D.users, today: D.today}),
    jid() { return this.panel.id; },
    all: D.actions,
    view: 'lista',
    views: [{key: 'lista', label: 'Lista'}, {key: 'tablica', label: 'Tablica'}, {key: 'os', label: 'Oś czasu'}, {key: 'kalendarz', label: 'Kalendarz'}],
    tiles: [
      {key: 'aktywne', label: 'W toku', hint: 'planowane, przygotowanie, trwające', color: '#00808a'},
      {key: 'noGrant', label: 'Bez finansowania', hint: 'brak grantu, nie są własne', color: '#a20025'},
      {key: 'overdue', label: 'Po terminie', hint: 'minęła data, wciąż w toku', color: '#c25100'},
      {key: 'ending', label: 'Kończą się', hint: 'w ciągu 14 dni', color: '#a86b00'},
      {key: 'behind', label: 'Wskaźniki < 50%', hint: 'wykonanie poniżej połowy planu', color: '#0050ef'},
      {key: 'msOverdue', label: 'Zaległe etapy', hint: 'minął termin etapu', color: '#6a00ff'},
    ],
    statusKeys: statusOrder,
    dragId: null, dragOver: '',
    year: new Date().getFullYear(), thisYear: new Date().getFullYear(),
    monthNames: ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'],
    msg: '',
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
    tileCount(key) { return this.all.filter(a => this.match(a, true) && this.hasFlag(a, key)).length; },
    byStatus(st) { return this.rows.filter(a => a.status === st); },
    drop(st) {
      const a = this.all.find(x => x.id === this.dragId);
      this.dragOver = ''; this.dragId = null;
      if (a) this.move(a, st);
    },
    async move(a, st) {
      if (!a || a.status === st) return;
      const prev = a.status;
      a.status = st;
      const res = await this.post(this.url(a.id, 'status'), {status: st});
      if (res && res.ok) {
        this.replace(res.action); this.say(res.message);
        if (res.journal && this.panel.open && this.panel.id === a.id) this.j = res.journal;
      }
      else { a.status = prev; this.say((res && res.error) || 'Nie udało się zmienić statusu.'); }
    },
    async post(url, data) {
      const fd = new FormData();
      fd.append('_csrf', D.csrf);
      Object.entries(data || {}).forEach(([k, v]) => Array.isArray(v) ? v.forEach(x => fd.append(k + '[]', x)) : fd.append(k, v));
      try {
        const r = await fetch(url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const ct = r.headers.get('content-type') || '';
        return ct.includes('json') ? await r.json() : {ok: false, error: (await r.text()).slice(0, 200) || ('Błąd ' + r.status)};
      } catch (e) { return {ok: false, error: 'Brak połączenia z serwerem.'}; }
    },
    replace(row) {
      if (!row) return;
      const i = this.all.findIndex(x => x.id === row.id);
      if (i >= 0) this.all.splice(i, 1, row); else this.all.unshift(row);
      if (this.cal) this.cal.refetchEvents();
    },
    say(text) { this.msg = ''; this.$nextTick(() => { this.msg = text; }); clearTimeout(this._t); this._t = setTimeout(() => this.msg = '', 5000); },

    get undated() { return this.rows.filter(a => !a.od && !a.do); },
    get timeline() {
      const ys = this.year + '-01-01', ye = this.year + '-12-31';
      return this.rows.filter(a => (a.od || a.do) && (a.od || a.do) <= ye && (a.do || a.od) >= ys)
        .sort((x, y) => (x.od || x.do).localeCompare(y.od || y.do) || x.id - y.id);
    },
    dayOfYear(d) { const t = new Date(d + 'T00:00:00'); return Math.round((t - new Date(this.year, 0, 1)) / 864e5); },
    get yearDays() { return Math.round((new Date(this.year + 1, 0, 1) - new Date(this.year, 0, 1)) / 864e5); },
    get todayPct() { return this.dayOfYear(D.today) / this.yearDays * 100; },
    bar(a) {
      const n = this.yearDays, s0 = this.dayOfYear(a.od || a.do), e0 = this.dayOfYear(a.do || a.od) + 1;
      const s = Math.max(0, s0), e = Math.min(n, e0);
      return {left: s / n * 100, width: Math.max(0.4, (e - s) / n * 100), cls: (s0 < 0 ? 'cut-l ' : '') + (e0 > n ? 'cut-r' : '')};
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
      const L = {noGrant: 'Bez finansowania', overdue: 'Po terminie', ending: 'Kończy się ≤ 14 dni', behind: 'Wskaźniki < 50%', msOverdue: 'Zaległy etap'};
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
        const end = a.do ? new Date(a.do + 'T12:00:00') : null;
        if (end) end.setDate(end.getDate() + 1);
        const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        return {id: String(a.id), title: a.nazwa, start: a.od, end: end ? ymd(end) : null,
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
    // ── Panel szczegółów ──
    panel: {open: false, loading: false, error: '', id: null, saving: false},
    p: {},
    csrf: D.csrf,
    async openAction(id, ev) {
      if (ev && (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1)) return; // nowa karta — zwykły link
      if (ev) ev.preventDefault();
      this._back = document.activeElement;
      this.panel = {open: true, loading: true, error: '', id, saving: false};
      this.p = {};
      this.$nextTick(() => this.$refs.pclose && this.$refs.pclose.focus());
      try {
        const r = await fetch(this.url(id, 'panel'), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (this.panel.id !== id) return;
        if (!j.ok) throw new Error(j.error || 'Błąd');
        this.p = j;
        this.j = j.journal || {milestones: [], log: []};
        this.msNew = {title: '', due: '', owner: ''}; this.comment = '';
        this.replace(j.action);
      } catch (e) { this.panel.error = 'Nie udało się wczytać działania. ' + (e.message || ''); }
      this.panel.loading = false;
    },
    closePanel() {
      this.panel.open = false;
      if (this._back && this._back.focus) this.$nextTick(() => this._back.focus());
    },
    async setStatus(st) {
      const a = this.all.find(x => x.id === this.panel.id);
      await this.move(a, st);
      const fresh = this.all.find(x => x.id === this.panel.id);
      if (fresh) this.p.action = fresh;
    },
    async saveIndicators() {
      const data = {};
      (this.p.indicators || []).forEach(i => { data['real[' + i.id + ']'] = String(i.real ?? ''); });
      this.panel.saving = true;
      const res = await this.post(this.url(this.panel.id, 'wskazniki'), data);
      this.panel.saving = false;
      if (res && res.ok) { this.replace(res.action); this.p.action = res.action; if (res.journal) this.j = res.journal; }
      this.say((res && (res.message || res.error)) || 'Nie udało się zapisać wskaźników.');
    },
  };
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>
