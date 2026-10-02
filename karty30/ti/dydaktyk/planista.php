<?php
/**
 * karty30/ti/dydaktyk/planista.php — Planista zajęć (kierownik): kalendarz i Gantt.
 *
 * Widok dnia = oś czasu (Gantt) z przeciąganiem (zmiana godziny i wiersza),
 * widok tygodnia = macierz zasób × dzień z przeciąganiem między dniami/wierszami.
 * Wiersze: trenerzy / sale / grupy. Walidacja po stronie serwera (sc_validate),
 * dane i zapis przez planistaAjax.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerFeed.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
ti_sched_migrate();

$dyd_name = (string)($me['name'] ?? '');
$KP_TITLE  = 'Planista zajęć — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .sc-bar { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.75rem; }
  .sc-bar .btn-group .btn { border-radius:0; }
  .sc-legend { display:flex; flex-wrap:wrap; gap:.35rem .9rem; font-size:.8rem; margin:.25rem 0 .75rem; }
  .sc-legend i { display:inline-block; width:.8rem; height:.8rem; border:2px solid; margin-right:.3rem; vertical-align:-1px; }
  .sc-stats { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:.75rem; }
  .sc-stat { border:1px solid #d1d5db; background:#fff; padding:.3rem .7rem; font-size:.85rem; }
  .sc-stat b { font-size:1.05rem; margin-right:.25rem; }
  .sc-stat.bad { border-color:#b91c1c; color:#b91c1c; }
  .sc-scroll { overflow:auto; border:1px solid #d1d5db; background:#fff; max-height:72vh; }
  .sc-grid { position:relative; min-width:100%; }
  .sc-head, .sc-row { display:flex; }
  .sc-head { position:sticky; top:0; z-index:5; background:#f3f4f6; border-bottom:1px solid #d1d5db; }
  .sc-label { flex:0 0 170px; position:sticky; left:0; z-index:4; background:#f9fafb; border-right:1px solid #d1d5db;
              padding:.35rem .5rem; font-size:.82rem; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .sc-head .sc-label { z-index:6; background:#f3f4f6; }
  .sc-row { border-bottom:1px solid #e5e7eb; }
  .sc-row.sc-over .sc-track, .sc-row.sc-over .sc-cell { outline:2px solid #2563eb; outline-offset:-2px; }
  .sc-row.sc-over-bad .sc-track { outline:2px solid #b91c1c; outline-offset:-2px; }
  .sc-track { position:relative; flex:0 0 auto; background:repeating-linear-gradient(90deg, transparent 0, transparent calc(var(--hw) - 1px), #eef0f3 calc(var(--hw) - 1px), #eef0f3 var(--hw)); }
  .sc-track.sc-hatch { background-color:#e5e7eb; background-image:repeating-linear-gradient(45deg, rgba(0,0,0,.06) 0 6px, transparent 6px 12px); }
  .sc-win { position:absolute; top:0; bottom:0; background:#fff; border-left:1px dashed #86efac; border-right:1px dashed #86efac; }
  .sc-hour { flex:0 0 auto; text-align:left; font-size:.72rem; color:#6b7280; border-left:1px solid #e5e7eb; padding:.25rem .3rem; box-sizing:border-box; }
  .sc-blk { position:absolute; border:2px solid; padding:1px 4px; font-size:.72rem; line-height:1.15; overflow:hidden; cursor:grab; user-select:none; touch-action:none; box-sizing:border-box; }
  .sc-blk.sc-fixed { cursor:default; opacity:.75; }
  .sc-blk.sc-drag { z-index:20; cursor:grabbing; box-shadow:0 4px 14px rgba(0,0,0,.35); opacity:.92; }
  .sc-blk.sc-bad { border-color:#b91c1c !important; background:#fecaca !important; }
  .sc-blk.sc-conf { box-shadow:inset 0 0 0 2px #b91c1c; }
  .sc-blk b { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .sc-cell { flex:1 1 0; min-width:130px; padding:.2rem; border-left:1px solid #eef0f3; min-height:48px; }
  .sc-cell.sc-off { background:#fef2f2; }
  .sc-cell.sc-today { background:#fffbeb; }
  .sc-chip { border:2px solid; padding:2px 5px; margin-bottom:3px; font-size:.72rem; line-height:1.2; cursor:grab; }
  .sc-chip.sc-fixed { cursor:default; opacity:.75; }
  .sc-chip.sc-conf { box-shadow:inset 0 0 0 2px #b91c1c; }
  .sc-chip.sc-dragging { opacity:.4; }
  .sc-cell.sc-drop { outline:2px solid #2563eb; outline-offset:-2px; background:#eff6ff; }
  .sc-chg { border-style:dashed !important; outline:2px solid #2563eb; outline-offset:1px; }
  .sc-toast { position:fixed; right:1rem; bottom:1rem; z-index:2000; max-width:420px; }
  .sc-issue { font-size:.85rem; margin:.15rem 0; }
  .sc-issue.h { color:#b91c1c; } .sc-issue.s { color:#b45309; }
</style>

<?php $KIER_CUR = 'planista.php'; $KIER_LABEL = 'Planista zajęć'; include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-calendar3-range me-2 text-primary"></i>Planista zajęć</h1>
  <a class="btn btn-outline-secondary btn-sm ms-auto" href="zgloszenia.php"><i class="bi bi-inbox me-1"></i>Zgłoszenia trenerów</a>
  <a class="btn btn-outline-secondary btn-sm" href="raportyPlanisty.php"><i class="bi bi-bar-chart-line me-1"></i>Raporty</a>
  <a class="btn btn-outline-secondary btn-sm" href="generatorCiagow.php"><i class="bi bi-magic me-1"></i>Generator ciągów</a>
  <a class="btn btn-outline-secondary btn-sm" href="kadraLimity.php"><i class="bi bi-person-gear me-1"></i>Limity i kwalifikacje kadry</a>
</div>
<p class="text-body-secondary small mb-2">
  Przeciągnij lekcję, aby zmienić termin, trenera lub salę. Serwer sprawdza nakładania (trener, sala, grupa, Zoom), urlopy,
  okna dostępności, kwalifikacje i limity godzin. Przesunięcie jednej lekcji nie zmienia reszty cyklu.
</p>

<div class="sc-bar" id="scBar">
  <div class="btn-group btn-group-sm" role="group" aria-label="Nawigacja">
    <button type="button" class="btn btn-outline-secondary" id="scPrev" aria-label="Wstecz"><i class="bi bi-chevron-left"></i></button>
    <button type="button" class="btn btn-outline-secondary" id="scToday">Dziś</button>
    <button type="button" class="btn btn-outline-secondary" id="scNext" aria-label="Dalej"><i class="bi bi-chevron-right"></i></button>
  </div>
  <input type="date" class="form-control form-control-sm" style="width:auto" id="scDate" aria-label="Data">
  <div class="btn-group btn-group-sm" role="group" aria-label="Widok">
    <button type="button" class="btn btn-outline-primary" data-view="day">Dzień (oś czasu)</button>
    <button type="button" class="btn btn-outline-primary" data-view="week">Tydzień</button>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Wiersze">
    <button type="button" class="btn btn-outline-primary" data-rows="instructor">Trenerzy</button>
    <button type="button" class="btn btn-outline-primary" data-rows="room">Sale</button>
    <button type="button" class="btn btn-outline-primary" data-rows="course">Grupy</button>
  </div>
  <input type="search" class="form-control form-control-sm" style="width:12rem" id="scFilter" placeholder="Filtr: grupa / trener / sala" aria-label="Filtr">
  <div class="form-check form-check-inline mb-0 small"><input class="form-check-input" type="checkbox" id="scHideEmpty"><label class="form-check-label" for="scHideEmpty">Ukryj puste</label></div>
  <div class="form-check form-check-inline mb-0 small"><input class="form-check-input" type="checkbox" id="scNotify"><label class="form-check-label" for="scNotify">Powiadom kursantów o zmianie</label></div>
</div>
<div class="sc-bar" id="scScnBar">
  <label class="small mb-0" for="scScn">Wersja planu:</label>
  <select class="form-select form-select-sm" style="width:auto;max-width:16rem" id="scScn"></select>
  <button type="button" class="btn btn-outline-primary btn-sm" id="scScnNew"><i class="bi bi-plus-lg me-1"></i>Nowy scenariusz</button>
  <button type="button" class="btn btn-outline-secondary btn-sm" id="scScnDiff" hidden>Zmiany</button>
  <button type="button" class="btn btn-success btn-sm" id="scScnPub" hidden>Opublikuj</button>
  <button type="button" class="btn btn-outline-danger btn-sm" id="scScnDel" hidden>Odrzuć scenariusz</button>
  <button type="button" class="btn btn-outline-primary btn-sm ms-auto" id="scFind"><i class="bi bi-search me-1"></i>Znajdź termin</button>
</div>
<div class="alert alert-info py-2 small" id="scScnBanner" hidden></div>
<div class="sc-stats" id="scStats"></div>
<div class="sc-legend" id="scLegend"></div>
<div class="sc-scroll" id="scScroll"><div class="sc-grid" id="scGrid"><div class="p-3 text-body-secondary">Ładowanie…</div></div></div>
</main>

<div class="modal fade" id="scModal" tabindex="-1" aria-labelledby="scModalTitle" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="scModalTitle">Lekcja</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <div class="small text-body-secondary mb-2" id="scMInfo"></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-0" for="scMDate">Data</label><input type="date" class="form-control form-control-sm" id="scMDate"></div>
        <div class="col-3"><label class="form-label small mb-0" for="scMFrom">Od</label><input type="time" class="form-control form-control-sm" id="scMFrom"></div>
        <div class="col-3"><label class="form-label small mb-0" for="scMTo">Do</label><input type="time" class="form-control form-control-sm" id="scMTo"></div>
        <div class="col-6"><label class="form-label small mb-0" for="scMInstr">Trener</label><select class="form-select form-select-sm" id="scMInstr"></select></div>
        <div class="col-6"><label class="form-label small mb-0" for="scMRoom">Sala</label><select class="form-select form-select-sm" id="scMRoom"></select></div>
      </div>
      <div class="mt-3" id="scMIssues" aria-live="polite"></div>
      <div class="mt-2"><button type="button" class="btn btn-outline-secondary btn-sm" id="scMMatch"><i class="bi bi-magic me-1"></i>Podpowiedz trenera i salę</button></div>
      <div class="mt-2" id="scMMatches"></div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-outline-primary btn-sm" id="scMClient"></button>
        <button type="button" class="btn btn-outline-primary btn-sm" id="scMInstrOk"></button>
        <a class="btn btn-outline-secondary btn-sm" id="scMLink" href="#">Otwórz w zakładce Zajęcia</a>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button><button type="button" class="btn btn-primary btn-sm" id="scMSave">Zapisz</button></div>
  </div></div>
</div>
<div class="modal fade" id="scFindModal" tabindex="-1" aria-labelledby="scFindTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="scFindTitle">Znajdź termin dla grupy</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label small mb-0" for="scFCourse">Grupa</label><select class="form-select form-select-sm" id="scFCourse"></select></div>
        <div class="col-md-2"><label class="form-label small mb-0" for="scFDur">Czas (min)</label><input type="number" class="form-control form-control-sm" id="scFDur" value="90" min="15" max="480" step="15"></div>
        <div class="col-md-2"><label class="form-label small mb-0" for="scFFrom">Od</label><input type="date" class="form-control form-control-sm" id="scFFrom"></div>
        <div class="col-md-3"><label class="form-label small mb-0" for="scFTo">Do</label><input type="date" class="form-control form-control-sm" id="scFTo"></div>
        <div class="col-md-5"><label class="form-label small mb-0" for="scFMethod">Metoda zajęć</label><select class="form-select form-select-sm" id="scFMethod"><option value="">dowolna</option><option value="stacjonarna">🏢 stacjonarnie (tylko z wolną salą)</option><option value="zdalna_zoom">💻 online — Zoom</option><option value="zdalna_inne">💻 online — inne</option></select></div>
        <div class="col-12 d-flex flex-wrap gap-3 small">
          <label><input type="checkbox" class="form-check-input me-1" id="scFAny">Rozważ innych trenerów</label>
          <label><input type="checkbox" class="form-check-input me-1" id="scFWk">Uwzględnij weekendy</label>
          <button type="button" class="btn btn-primary btn-sm ms-auto" id="scFGo">Szukaj</button>
        </div>
      </div>
      <p class="small text-body-secondary mt-2 mb-1" id="scFNote"></p>
      <div id="scFResults" aria-live="polite"></div>
    </div>
  </div></div>
</div>
<div class="modal fade" id="scDiffModal" tabindex="-1" aria-labelledby="scDiffTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="scDiffTitle">Zmiany względem planu oficjalnego</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body" id="scDiffBody"></div>
  </div></div>
</div>
<div class="sc-toast" id="scToast" role="status" aria-live="polite"></div>

<script>
(() => {
'use strict';
const TOKEN = <?= json_encode(dyd_token()) ?>;
const STY = <?= json_encode(SC_STATUS_STYLES, JSON_UNESCAPED_UNICODE) ?>;
const DAYS = ['Nd','Pn','Wt','Śr','Czw','Pt','Sob'];
const st = { view: 'day', rows: 'instructor', date: new Date().toISOString().slice(0,10), data: null, loaded: '', cur: null, scn: 0 };
try { Object.assign(st, JSON.parse(localStorage.getItem('scPrefs') || '{}')); } catch (e) {}
if (!st.date || !/^\d{4}-\d{2}-\d{2}$/.test(st.date)) st.date = new Date().toISOString().slice(0,10);
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const m2h = (m) => String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0');
const h2m = (h) => { const [a,b] = h.split(':'); return (+a)*60 + (+b); };
const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate()+n); return x.toISOString().slice(0,10); };
const monday = (d) => { const x = new Date(d + 'T12:00:00'); const w = (x.getDay()+6)%7; return addDays(d, -w); };
const dow = (d) => new Date(d + 'T12:00:00').getDay();
const save = () => { try { localStorage.setItem('scPrefs', JSON.stringify({view: st.view, rows: st.rows})); } catch (e) {} };
const filt = { q: '', hide: false };

function toast(msg, kind) {
  const el = $('scToast'); el.innerHTML = '<div class="alert alert-' + (kind || 'info') + ' shadow-sm py-2 mb-0">' + esc(msg) + '</div>';
  clearTimeout(toast.t); toast.t = setTimeout(() => el.innerHTML = '', 6000);
}

async function load(force) {
  const from = monday(st.date), to = addDays(from, 6);
  const key = from + '|' + st.scn;
  if (!force && st.loaded === key && st.data) return render();
  try {
    const r = await fetch('planistaAjax.php?action=feed&from=' + from + '&to=' + to + '&scenario=' + st.scn, { credentials: 'same-origin' });
    const j = await r.json(); if (!j.ok) throw new Error(j.msg || 'Błąd');
    st.data = j; st.loaded = key; st.scn = j.scenario || 0; renderScn(); render();
  } catch (e) { $('scGrid').innerHTML = '<div class="p-3 text-danger">Nie udało się pobrać danych: ' + esc(e.message) + '</div>'; }
}

function visibleSessions() {
  const q = filt.q.toLowerCase();
  let list = st.data.sessions;
  if (st.view === 'day') list = list.filter(s => s.date === st.date);
  if (q) list = list.filter(s => (s.course + ' ' + s.code + ' ' + s.instructor + ' ' + s.room).toLowerCase().includes(q));
  return list;
}
const keyOf = (s) => st.rows === 'instructor' ? s.instructor_id : st.rows === 'room' ? s.room_id : s.course_id;

function buildRows(list) {
  const d = st.data; let rows = [];
  if (st.rows === 'instructor') rows = d.instructors.map(x => ({ key: x.id, label: x.name }));
  else if (st.rows === 'room') rows = d.rooms.map(x => ({ key: x.id, label: x.name, sub: x.capacity ? x.capacity + ' os.' : '' }));
  else rows = d.courses.map(x => ({ key: x.id, label: (x.code ? x.code + ' · ' : '') + x.name }));
  const used = new Set(list.map(keyOf));
  if (st.rows !== 'course') rows.push({ key: 0, label: st.rows === 'instructor' ? '— bez trenera —' : '— bez sali —' });
  const q = filt.q.toLowerCase();
  const hide = filt.hide || st.rows === 'course';
  return rows.filter(r => used.has(r.key) || (!hide && !q));
}

function style(s) { return STY[s.ds] || STY.tentative; }
const MI = m => m === 'stacjonarna' ? '🏢 ' : (m === 'zdalna_zoom' || m === 'zdalna_inne') ? '💻 ' : '';
function blockTitle(s) { return MI(s.method) + s.course + ' · ' + s.from + '–' + s.to + (s.instructor ? ' · ' + s.instructor : '') + (s.room ? ' · ' + s.room : '') + ' — ' + style(s).label; }

function render() {
  if (!st.data) return;
  document.querySelectorAll('[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === st.view));
  document.querySelectorAll('[data-rows]').forEach(b => b.classList.toggle('active', b.dataset.rows === st.rows));
  $('scDate').value = st.date;
  $('scLegend').innerHTML = Object.values(STY).map(x => '<span><i style="background:' + x.bg + ';border-color:' + x.color + '"></i>' + esc(x.label) + '</span>').join('');
  const list = visibleSessions();
  const conf = list.filter(s => s.conflicts.length).length;
  const tent = list.filter(s => s.ds === 'tentative').length;
  const onsite = list.filter(s => s.method === 'stacjonarna' && !s.room_id && s.status !== 'cancelled').length;
  $('scStats').innerHTML = '<div class="sc-stat"><b>' + list.length + '</b>lekcji w widoku</div>'
    + '<div class="sc-stat ' + (conf ? 'bad' : '') + '"><b>' + conf + '</b>w konflikcie</div>'
    + '<div class="sc-stat"><b>' + tent + '</b>wstępnych (niepotwierdzonych)</div>'
    + (onsite ? '<div class="sc-stat bad"><b>' + onsite + '</b>stacjonarnych bez sali</div>' : '')
    + '<div class="sc-stat"><b>' + list.filter(s => s.method === 'stacjonarna').length + '</b>🏢 stacjonarnie · <b>' + list.filter(s => s.method === 'zdalna_zoom' || s.method === 'zdalna_inne').length + '</b>💻 online</div>';
  (st.view === 'day' ? renderDay : renderWeek)(list);
}

/* ───────── Widok dnia (Gantt) ───────── */
const PPM = 1.15, LANE_H = 34;
let axis = { a: 420, b: 1320 };
function renderDay(list) {
  const rows = buildRows(list);
  let a = 420, b = 1320;
  list.forEach(s => { a = Math.min(a, Math.floor(h2m(s.from)/60)*60); b = Math.max(b, Math.ceil(h2m(s.to)/60)*60); });
  axis = { a, b };
  const W = (b - a) * PPM, hw = 60 * PPM;
  const hol = (st.data.holidays || []).some(h => h.from <= st.date && h.to >= st.date);
  let html = '<div class="sc-head"><div class="sc-label">' + esc(DAYS[dow(st.date)] + ' ' + st.date) + (hol ? ' · dzień wolny' : '') + '</div><div style="display:flex;width:' + W + 'px">';
  for (let m = a; m < b; m += 60) html += '<div class="sc-hour" style="width:' + hw + 'px">' + m2h(m) + '</div>';
  html += '</div></div>';
  rows.forEach(r => {
    const ss = list.filter(s => keyOf(s) === r.key).sort((x, y) => h2m(x.from) - h2m(y.from));
    const lanes = [];
    ss.forEach(s => { let i = 0; while (lanes[i] && lanes[i] > h2m(s.from)) i++; lanes[i] = h2m(s.to); s._lane = i; });
    const H = Math.max(1, lanes.length) * LANE_H + 4;
    let bg = '', wins = '';
    if (st.rows === 'instructor' && r.key) {
      const lv = (st.data.leaves[r.key] || []).find(l => l.from <= st.date && l.to >= st.date);
      const aw = (st.data.avail[r.key] || []).filter(w => w.dow === dow(st.date) && (!w.vf || w.vf <= st.date) && (!w.vt || w.vt >= st.date));
      if (lv) bg = ' sc-hatch" title="Nieobecność: ' + esc(lv.type) + '';
      else if (st.data.avail[r.key]) {
        bg = ' sc-hatch';
        wins = aw.map(w => '<div class="sc-win" style="left:' + (h2m(w.from)-a)*PPM + 'px;width:' + (h2m(w.to)-h2m(w.from))*PPM + 'px"></div>').join('');
      }
    }
    html += '<div class="sc-row" data-key="' + r.key + '"><div class="sc-label" title="' + esc(r.label) + '">' + esc(r.label) + (r.sub ? ' <small class="text-body-secondary fw-normal">' + esc(r.sub) + '</small>' : '') + '</div>'
      + '<div class="sc-track' + bg + '" data-key="' + r.key + '" style="width:' + W + 'px;height:' + H + 'px;--hw:' + hw + 'px">' + wins
      + ss.map(s => blockHtml(s, a)).join('') + '</div></div>';
  });
  if (!rows.length) html += '<div class="p-3 text-body-secondary">Brak lekcji w tym dniu.</div>';
  $('scGrid').innerHTML = html;
  $('scGrid').querySelectorAll('.sc-blk').forEach(el => bindDrag(el));
}
function blockHtml(s, a) {
  const x = style(s), left = (h2m(s.from) - a) * PPM, w = Math.max(24, (h2m(s.to) - h2m(s.from)) * PPM);
  return '<div class="sc-blk' + (s.movable ? '' : ' sc-fixed') + (s.conflicts.length ? ' sc-conf' : '') + (s.changed ? ' sc-chg' : '') + '" data-id="' + s.id + '" tabindex="0" role="button" title="' + esc(blockTitle(s)) + '"'
    + ' style="left:' + left + 'px;width:' + w + 'px;top:' + (2 + s._lane * LANE_H) + 'px;height:' + (LANE_H - 3) + 'px;background:' + x.bg + ';border-color:' + x.color + ';color:' + x.color + '">'
    + '<b>' + MI(s.method) + esc((s.code ? s.code + ' ' : '') + s.course) + '</b>' + esc(s.from + '–' + s.to) + (s.moved ? ' ↺' : '') + (s.rate ? ' 💰' : '') + (s.method === 'stacjonarna' && !s.room_id && s.status !== 'cancelled' ? ' ⚠ bez sali' : '') + '</div>';
}

function overlapsLocal(s, key, from, to) {
  if (st.rows === 'course') return false;
  return st.data.sessions.some(o => o.id !== s.id && o.date === s.date && o.status !== 'cancelled' && (st.rows === 'instructor' ? o.instructor_id : o.room_id) === key && key && h2m(o.from) < to && from < h2m(o.to));
}

function bindDrag(el) {
  const s = st.data.sessions.find(x => x.id === +el.dataset.id);
  el.addEventListener('click', () => { if (!el._dragged) openModal(s); el._dragged = false; });
  el.addEventListener('keydown', e => { if (e.key === 'Enter') openModal(s); });
  if (!s.movable) return;
  el.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    const sx = e.clientX, sy = e.clientY, f0 = h2m(s.from), t0 = h2m(s.to), key0 = keyOf(s);
    let moved = false, tgt = { key: key0, from: f0, to: t0 };
    el.setPointerCapture(e.pointerId);
    const mv = ev => {
      const dx = ev.clientX - sx, dy = ev.clientY - sy;
      if (!moved && Math.abs(dx) + Math.abs(dy) < 5) return;
      moved = true; el.classList.add('sc-drag');
      let dm = Math.round(dx / PPM / 15) * 15, nf = Math.max(axis.a, Math.min(axis.b - (t0 - f0), f0 + dm));
      dm = nf - f0;
      el.style.transform = 'translate(' + (dm * PPM) + 'px,' + dy + 'px)';
      el.style.pointerEvents = 'none';
      const under = document.elementFromPoint(ev.clientX, ev.clientY); el.style.pointerEvents = '';
      const tr = under && under.closest('.sc-track');
      document.querySelectorAll('.sc-row').forEach(r => r.classList.remove('sc-over', 'sc-over-bad'));
      let key = key0;
      if (tr && st.rows !== 'course') key = +tr.dataset.key;
      tgt = { key, from: nf, to: nf + (t0 - f0) };
      const bad = overlapsLocal(s, key, tgt.from, tgt.to);
      el.classList.toggle('sc-bad', bad);
      const row = tr && tr.closest('.sc-row'); if (row && st.rows !== 'course') row.classList.add(bad ? 'sc-over-bad' : 'sc-over');
    };
    const up = () => {
      el.removeEventListener('pointermove', mv); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up);
      document.querySelectorAll('.sc-row').forEach(r => r.classList.remove('sc-over', 'sc-over-bad'));
      if (!moved) return;
      el._dragged = true;
      el.classList.remove('sc-drag', 'sc-bad'); el.style.transform = '';
      if (tgt.from === f0 && tgt.key === key0) return;
      const p = { date: s.date, time_from: m2h(tgt.from), time_to: m2h(tgt.to) };
      if (st.rows === 'instructor') p.instructor_id = tgt.key; else if (st.rows === 'room') p.room_id = tgt.key;
      doMove(s.id, p);
    };
    el.addEventListener('pointermove', mv); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
  });
}

/* ───────── Widok tygodnia ───────── */
function renderWeek(list) {
  const rows = buildRows(list), mon = monday(st.date), today = new Date().toISOString().slice(0,10);
  const days = [0,1,2,3,4,5,6].map(i => addDays(mon, i));
  const off = (d) => (st.data.holidays || []).some(h => h.from <= d && h.to >= d);
  let html = '<div class="sc-head"><div class="sc-label">Tydzień ' + mon + '</div>' + days.map(d => '<div class="sc-hour" style="flex:1 1 0;min-width:130px">' + DAYS[dow(d)] + ' ' + d.slice(5) + (off(d) ? ' ·wolny' : '') + '</div>').join('') + '</div>';
  rows.forEach(r => {
    html += '<div class="sc-row"><div class="sc-label" title="' + esc(r.label) + '">' + esc(r.label) + '</div>' + days.map(d => {
      const ss = list.filter(s => keyOf(s) === r.key && s.date === d).sort((a, b) => a.from.localeCompare(b.from));
      return '<div class="sc-cell' + (off(d) ? ' sc-off' : '') + (d === today ? ' sc-today' : '') + '" data-key="' + r.key + '" data-date="' + d + '">' + ss.map(chipHtml).join('') + '</div>';
    }).join('') + '</div>';
  });
  if (!rows.length) html += '<div class="p-3 text-body-secondary">Brak lekcji w tym tygodniu.</div>';
  $('scGrid').innerHTML = html;
  const g = $('scGrid');
  g.querySelectorAll('.sc-chip').forEach(el => {
    const s = st.data.sessions.find(x => x.id === +el.dataset.id);
    el.addEventListener('click', () => openModal(s));
    el.addEventListener('keydown', e => { if (e.key === 'Enter') openModal(s); });
    if (!s.movable) return;
    el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', String(s.id)); e.dataTransfer.effectAllowed = 'move'; el.classList.add('sc-dragging'); });
    el.addEventListener('dragend', () => { el.classList.remove('sc-dragging'); g.querySelectorAll('.sc-drop').forEach(c => c.classList.remove('sc-drop')); });
  });
  g.querySelectorAll('.sc-cell').forEach(c => {
    c.addEventListener('dragover', e => { e.preventDefault(); c.classList.add('sc-drop'); });
    c.addEventListener('dragleave', () => c.classList.remove('sc-drop'));
    c.addEventListener('drop', e => {
      e.preventDefault(); c.classList.remove('sc-drop');
      const s = st.data.sessions.find(x => x.id === +e.dataTransfer.getData('text/plain')); if (!s) return;
      const key = +c.dataset.key, date = c.dataset.date;
      if (date === s.date && (st.rows === 'course' || key === keyOf(s))) return;
      const p = { date, time_from: s.from, time_to: s.to };
      if (st.rows === 'instructor') p.instructor_id = key; else if (st.rows === 'room') p.room_id = key;
      doMove(s.id, p);
    });
  });
}
function chipHtml(s) {
  const x = style(s);
  return '<div class="sc-chip' + (s.movable ? '' : ' sc-fixed') + (s.conflicts.length ? ' sc-conf' : '') + (s.changed ? ' sc-chg' : '') + '" draggable="' + (s.movable ? 'true' : 'false') + '" tabindex="0" role="button" data-id="' + s.id + '" title="' + esc(blockTitle(s)) + '"'
    + ' style="background:' + x.bg + ';border-color:' + x.color + ';color:' + x.color + '"><b>' + esc(s.from + '–' + s.to) + '</b> ' + MI(s.method) + esc((s.code ? s.code + ' ' : '') + s.course) + (s.rate ? ' 💰' : '') + (s.method === 'stacjonarna' && !s.room_id && s.status !== 'cancelled' ? ' ⚠' : '')
    + (st.rows !== 'instructor' && s.instructor ? '<br><small>' + esc(s.instructor) + '</small>' : '') + (st.rows !== 'room' && s.room ? '<br><small>' + esc(s.room) + '</small>' : '') + '</div>';
}

/* ───────── Zapis i walidacja ───────── */
async function post(action, id, p) {
  const fd = new FormData(); fd.append('_token', TOKEN); fd.append('action', action); fd.append('session_id', id);
  Object.entries(p || {}).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('planistaAjax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  let j = {}; try { j = await r.json(); } catch (e) { j = { ok: false, msg: 'Błąd odpowiedzi serwera (' + r.status + ').' }; }
  return j;
}
function issuesText(j) {
  return (j.hard || []).map(x => '✖ ' + x.msg).concat((j.soft || []).map(x => '⚠ ' + x.msg)).join('\n');
}
async function doMove(id, p, force) {
  if ($('scNotify').checked) p.notify = 1;
  if (force) p.force = 1;
  if (st.scn) p.scenario = st.scn;
  const j = await post('move', id, p);
  if (j.ok) { toast(st.scn ? 'Zapisano w scenariuszu (plan oficjalny bez zmian).' : 'Zapisano zmianę terminu.', 'success'); await load(true); return true; }
  if (j.needs_ack) {
    if (confirm(issuesText(j) + '\n\nZapisać mimo to?')) return doMove(id, p, true);
    render(); return false;
  }
  toast(j.hard && j.hard.length ? issuesText(j) : (j.msg || 'Błąd zapisu.'), 'danger');
  render(); return false;
}

/* ───────── Modal lekcji ───────── */
let modal;
function fillSelect(sel, items, cur, empty) {
  sel.innerHTML = '<option value="0">' + esc(empty) + '</option>' + items.map(i => '<option value="' + i.id + '"' + (i.id === cur ? ' selected' : '') + '>' + esc(i.name) + '</option>').join('');
}
function openModal(s) {
  st.cur = s; modal = modal || new bootstrap.Modal($('scModal'));
  $('scModalTitle').textContent = (s.code ? s.code + ' · ' : '') + s.course;
  $('scMInfo').textContent = style(s).label + (s.topic ? ' · ' + s.topic : '') + (s.moved ? ' · termin zmieniony' : '') + (s.series ? ' · z cyklu' : '');
  $('scMDate').value = s.date; $('scMFrom').value = s.from; $('scMTo').value = s.to;
  fillSelect($('scMInstr'), st.data.instructors, s.instructor_id, '— z kursu —');
  fillSelect($('scMRoom'), st.data.rooms, s.room_id, '— bez sali —');
  $('scMClient').textContent = (s.client_ok ? '✔ ' : '') + 'Potwierdzone z klientem';
  $('scMInstrOk').textContent = (s.instr_ok ? '✔ ' : '') + 'Potwierdzone z trenerem';
  $('scMClient').classList.toggle('active', s.client_ok); $('scMInstrOk').classList.toggle('active', s.instr_ok);
  $('scMLink').href = 'index.php?course=' + s.course_id + '&tab=lekcje';
  ['scMDate','scMFrom','scMTo','scMInstr','scMRoom','scMSave'].forEach(id => $(id).disabled = !s.movable);
  ['scMClient','scMInstrOk'].forEach(id => $(id).disabled = !!st.scn);
  $('scMMatches').innerHTML = ''; $('scMMatch').disabled = !s.movable;
  $('scMIssues').innerHTML = s.movable ? '' : '<div class="text-body-secondary small">Lekcja odbyta lub odwołana — edycja terminu wyłączona.</div>';
  modal.show(); if (s.movable) check();
}
function mPayload() {
  const p = { date: $('scMDate').value, time_from: $('scMFrom').value, time_to: $('scMTo').value, room_id: $('scMRoom').value };
  p.instructor_id = $('scMInstr').value; return p;
}
let chkT;
function check() { clearTimeout(chkT); chkT = setTimeout(async () => {
  const j = await post('check', st.cur.id, mPayload());
  $('scMIssues').innerHTML = (j.hard || []).map(x => '<div class="sc-issue h">✖ ' + esc(x.msg) + (x.overridable ? ' <em>(do zatwierdzenia)</em>' : '') + '</div>').join('')
    + (j.soft || []).map(x => '<div class="sc-issue s">⚠ ' + esc(x.msg) + '</div>').join('')
    || '<div class="sc-issue text-success">✔ Brak konfliktów.</div>';
}, 250); }
['scMDate','scMFrom','scMTo','scMInstr','scMRoom'].forEach(id => $(id).addEventListener('change', check));
$('scMSave').addEventListener('click', async () => { if (await doMove(st.cur.id, mPayload())) modal.hide(); });
async function toggleState(which) {
  const j = await post('state', st.cur.id, { which });
  if (!j.ok) return toast(j.msg || 'Błąd', 'danger');
  await load(true); const s = st.data.sessions.find(x => x.id === st.cur.id); if (s) openModal(s);
}
$('scMClient').addEventListener('click', () => toggleState('client'));
$('scMInstrOk').addEventListener('click', () => toggleState('instructor'));

/* ───────── Scenariusze ───────── */
function renderScn() {
  const sel = $('scScn'), d = st.data;
  sel.innerHTML = '<option value="0">Oficjalny plan</option>' + d.scenarios.map(x => '<option value="' + x.id + '"' + (x.id === st.scn ? ' selected' : '') + '>' + esc(x.title) + ' (' + x.n_changes + ')</option>').join('');
  ['scScnDiff','scScnPub','scScnDel'].forEach(id => $(id).hidden = !st.scn);
  const b = $('scScnBanner'), cur = d.scenarios.find(x => x.id === st.scn);
  b.hidden = !st.scn; if (cur) b.innerHTML = '<strong>Scenariusz „' + esc(cur.title) + '”</strong> — wersja robocza. Zmiany nie wpływają na plan oficjalny, wypłaty ani kursantów, dopóki nie klikniesz „Opublikuj”.';
}
$('scScn').onchange = e => { st.scn = +e.target.value; load(true); };
$('scScnNew').onclick = async () => {
  const t = prompt('Nazwa scenariusza (np. „Wariant z nowym trenerem”):'); if (!t) return;
  const fd = new FormData(); fd.append('_token', TOKEN); fd.append('action', 'scn_create'); fd.append('title', t);
  const j = await (await fetch('planistaAjax.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
  if (j.ok) { st.scn = j.id; load(true); } else toast(j.msg || 'Błąd', 'danger');
};
async function scnPost(action, extra) {
  const fd = new FormData(); fd.append('_token', TOKEN); fd.append('action', action); fd.append('scenario', st.scn);
  Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
  return (await fetch('planistaAjax.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
}
$('scScnDel').onclick = async () => { if (!confirm('Odrzucić scenariusz wraz ze wszystkimi zmianami?')) return; await scnPost('scn_discard'); st.scn = 0; load(true); };
$('scScnDiff').onclick = async () => {
  const j = await scnPost('scn_diff');
  $('scDiffBody').innerHTML = j.diff && j.diff.length ? '<table class="table table-sm"><thead><tr><th>Grupa</th><th>Było</th><th>Będzie</th><th>Trener</th><th>Sala</th></tr></thead><tbody>'
    + j.diff.map(x => '<tr><td>' + esc(x.course) + '</td><td>' + esc(x.from) + '</td><td>' + esc(x.to) + '</td><td>' + esc(x.instructor) + '</td><td>' + esc(x.room) + '</td></tr>').join('') + '</tbody></table>'
    : '<p class="text-body-secondary">Scenariusz nie zawiera jeszcze zmian — przeciągnij lekcje na planie.</p>';
  new bootstrap.Modal($('scDiffModal')).show();
};
$('scScnPub').onclick = async () => {
  if (!confirm('Opublikować scenariusz jako plan oficjalny? Lekcje zostaną przesunięte.')) return;
  const notify = $('scNotify').checked ? 1 : 0;
  let j = await scnPost('scn_publish', { notify });
  if (j.needs_ack) { if (!confirm((j.issues || []).join('\n') + '\n\nOpublikować mimo ostrzeżeń?')) return; j = await scnPost('scn_publish', { notify, force: 1 }); }
  if (j.ok) { toast(j.msg, 'success'); st.scn = 0; load(true); } else toast((j.issues || [j.msg]).join(' · '), 'danger');
};

/* ───────── Auto-dopasowanie ───────── */
$('scMMatch').onclick = async () => {
  const j = await post('match', st.cur.id, {});
  $('scMMatches').innerHTML = (j.matches || []).map((m, i) => '<div class="d-flex align-items-center gap-2 small mb-1"><span>' + esc(m.instructor) + ' · ' + esc(m.room) + (m.soft.length ? ' <span class="text-warning" title="' + esc(m.soft.join(' | ')) + '">⚠</span>' : '') + '</span>'
    + '<button type="button" class="btn btn-outline-primary btn-sm py-0 ms-auto" data-m="' + i + '">Użyj</button></div>').join('') || '<div class="small text-body-secondary">Brak wolnych kombinacji trener + sala w tym terminie.</div>';
  $('scMMatches').querySelectorAll('[data-m]').forEach(b => b.onclick = () => { const m = j.matches[+b.dataset.m]; $('scMInstr').value = m.instructor_id; $('scMRoom').value = m.room_id; check(); });
};
let findModal;
$('scFind').onclick = () => {
  findModal = findModal || new bootstrap.Modal($('scFindModal'));
  $('scFCourse').innerHTML = st.data.courses.map(c => '<option value="' + c.id + '">' + esc((c.code ? c.code + ' · ' : '') + c.name) + '</option>').join('');
  $('scFFrom').value = $('scFFrom').value || st.date; $('scFTo').value = $('scFTo').value || addDays(st.date, 14);
  $('scFNote').textContent = st.scn ? 'Nowe lekcje dodaje się do planu oficjalnego (nie do scenariusza).' : '';
  findModal.show();
};
$('scFGo').onclick = async () => {
  const fd = new FormData(); fd.append('_token', TOKEN); fd.append('action', 'find_slots'); fd.append('course_id', $('scFCourse').value);
  fd.append('dur', $('scFDur').value); fd.append('method', $('scFMethod').value); fd.append('from', $('scFFrom').value); fd.append('to', $('scFTo').value);
  if ($('scFAny').checked) fd.append('any', 1); if ($('scFWk').checked) fd.append('weekends', 1);
  $('scFResults').innerHTML = '<div class="text-body-secondary small">Szukam…</div>';
  const j = await (await fetch('planistaAjax.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
  const sl = j.slots || [];
  $('scFResults').innerHTML = sl.length ? '<table class="table table-sm align-middle"><tbody>' + sl.map((x, i) => '<tr><td>' + esc(DAYS[dow(x.date)] + ' ' + x.date) + '</td><td>' + esc(x.from + '–' + x.to) + '</td><td>' + esc(x.instructor) + '</td><td>' + esc(x.room) + (x.method === 'stacjonarna' ? ' <small class="text-body-secondary">(' + x.rooms_free + ' wolnych)</small>' : '') + '</td><td>' + (x.soft.length ? '<span class="text-warning" title="' + esc(x.soft.join(' | ')) + '">⚠</span>' : '') + '</td>'
    + '<td class="text-end"><button type="button" class="btn btn-primary btn-sm py-0" data-s="' + i + '">Dodaj lekcję</button></td></tr>').join('') + '</tbody></table>'
    : '<div class="text-body-secondary small">Brak wolnych terminów bez twardych konfliktów w tym zakresie.</div>';
  $('scFResults').querySelectorAll('[data-s]').forEach(b => b.onclick = async () => {
    const x = sl[+b.dataset.s];
    const add = async (force) => {
      const f = new FormData(); f.append('_token', TOKEN); f.append('action', 'add_lesson'); f.append('course_id', $('scFCourse').value);
      f.append('date', x.date); f.append('time_from', x.from); f.append('time_to', x.to); f.append('instructor_id', x.instructor_id); f.append('room_id', x.room_id); f.append('method', $('scFMethod').value); if (force) f.append('force', 1);
      return (await fetch('planistaAjax.php', { method: 'POST', body: f, credentials: 'same-origin' })).json();
    };
    let r = await add(false);
    if (r.needs_ack && confirm(issuesText(r) + '\n\nDodać mimo to?')) r = await add(true);
    if (r.ok) { toast('Dodano lekcję do planu oficjalnego.', 'success'); findModal.hide(); st.scn = 0; load(true); } else if (!r.needs_ack) toast(r.msg || 'Błąd', 'danger');
  });
};

/* ───────── Pasek narzędzi ───────── */
const step = () => st.view === 'day' ? 1 : 7;
$('scPrev').onclick = () => { st.date = addDays(st.date, -step()); load(); };
$('scNext').onclick = () => { st.date = addDays(st.date, step()); load(); };
$('scToday').onclick = () => { st.date = new Date().toISOString().slice(0,10); load(); };
$('scDate').onchange = e => { if (e.target.value) { st.date = e.target.value; load(); } };
document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => { st.view = b.dataset.view; save(); render(); });
document.querySelectorAll('[data-rows]').forEach(b => b.onclick = () => { st.rows = b.dataset.rows; save(); render(); });
$('scFilter').oninput = e => { filt.q = e.target.value.trim(); render(); };
$('scHideEmpty').onchange = e => { filt.hide = e.target.checked; render(); };
load();
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
