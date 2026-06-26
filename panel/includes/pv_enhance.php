<?php
/**
 * panel/includes/pv_enhance.php — Wspólny, lekki enhancer panelu (czysty JS + Bootstrap).
 *
 * Zastępuje wcześniejszy wzorzec „React islands z CDN" (pv_react_boot.php).
 * Wszystko renderuje serwer (markup Bootstrap), a ten plik dokłada tylko
 * progresywne wzbogacenia w waniliowym JS — bez kroku budowania i bez CDN:
 *
 *   • filtr po statusie (chipy)         → [data-pv-filter]
 *   • żywe odliczanie do końca umowy     → [data-pv-countdown]
 *   • wyszukiwarka „Centrum akcji"       → [data-pv-hub]
 *   • optymistyczne „Weź"/„Ukończ"       → [data-pv-tasks]
 *
 * Inicjalizacja skanuje atrybuty data-* po załadowaniu DOM, więc kolejność
 * include'ów na stronie nie ma znaczenia. Gdy JS zawiedzie — zostaje pełna,
 * dostępna wersja serwerowa (chipy działają jako linki/markup statyczny,
 * licznik pokazuje wartość początkową).
 *
 * Emitowany raz na żądanie (require_once + guard).
 */
if (!empty($GLOBALS['__pv_enhance_booted'])) return;
$GLOBALS['__pv_enhance_booted'] = true;
?>
<style>
/* ── Chipy filtra / wyszukiwarki (wspólne dla podstron panelu) ───────────── */
.pv-hub-chip{border:1.5px solid #E5E7EB;background:#fff;border-radius:2rem;padding:.32rem .8rem;font-size:.78rem;font-weight:600;color:#374151;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:.3rem}
.pv-hub-chip:hover{border-color:var(--vol-color,#1d4ed8);color:var(--vol-color,#1d4ed8)}
.pv-hub-chip:focus-visible{outline:2px solid var(--vol-color,#1d4ed8);outline-offset:2px}
.pv-hub-chip[aria-pressed="true"]{background:var(--vol-color,#1d4ed8);border-color:var(--vol-color,#1d4ed8);color:#fff}
.pv-hub-chip:disabled{opacity:.5;cursor:default}
.pv-hub-chip .pv-hub-chip-num{background:rgba(0,0,0,.12);border-radius:1rem;padding:0 .4rem;font-size:.7rem;font-weight:700}
.pv-hub-chip[aria-pressed="true"] .pv-hub-chip-num{background:rgba(255,255,255,.28)}
.pv-filter-chips{display:flex;gap:.35rem;flex-wrap:wrap;padding:.6rem 1rem 0}
/* ── Wyszukiwarka „Centrum akcji" ────────────────────────────────────────── */
.pv-hub-bar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.85rem}
.pv-hub-title{font-size:.95rem;font-weight:800;color:#111827;margin:0;line-height:1.2;display:flex;align-items:center;gap:.4rem}
.pv-hub-title i{color:var(--vol-color)}
.pv-hub-search{position:relative;flex:1;min-width:180px;max-width:340px}
.pv-hub-search i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:.9rem;pointer-events:none}
.pv-hub-search input{width:100%;border:1.5px solid #E5E7EB;border-radius:9px;padding:.45rem .7rem .45rem 2rem;font-size:.85rem;background:#fff;color:#111827;transition:border-color .12s,box-shadow .12s}
.pv-hub-search input:focus{outline:none;border-color:var(--vol-color);box-shadow:0 0 0 3px rgba(var(--vol-rgb,29,78,216),.15)}
.pv-hub-chips{display:inline-flex;gap:.35rem}
.pv-hub-empty{background:#fff;border:2px dashed #E5E7EB;border-radius:12px;text-align:center;padding:1.75rem 1rem;color:#6B7280}
.pv-hub-empty i{font-size:1.6rem;color:#D1D5DB;display:block;margin-bottom:.4rem}
.pv-hub-clear{background:none;border:none;color:var(--vol-color);font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:underline;padding:.2rem .4rem}
.pv-hub-clear:focus-visible{outline:2px solid var(--vol-color);outline-offset:2px;border-radius:4px}
@media(max-width:560px){.pv-hub-search{max-width:none;width:100%;order:3}}
/* ── Odliczanie do końca umowy (na tle hero) ─────────────────────────────── */
.pv-cd{display:flex;gap:.4rem;margin-top:.85rem;flex-wrap:wrap}
.pv-cd-unit{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.22);border-radius:9px;padding:.35rem .55rem;min-width:48px;text-align:center;line-height:1.05}
.pv-cd-num{display:block;font-size:1.05rem;font-weight:900;font-variant-numeric:tabular-nums;letter-spacing:.02em}
.pv-cd-lbl{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;opacity:.82;margin-top:.1rem}
</style>
<script>
(function () {
  if (window.__pvEnhance) return;
  window.__pvEnhance = true;

  function $all(sel, ctx){ return [].slice.call((ctx || document).querySelectorAll(sel)); }

  // Polska odmiana liczebnika: forms = [jeden, kilka(2-4), wiele] — tylko dla SR.
  function plural(n, forms){
    n = Math.abs(n);
    if (n === 1) return forms[0];
    var t = n % 10, h = Math.floor(n / 10) % 10;
    if (t >= 2 && t <= 4 && h !== 1) return forms[1];
    return forms[2];
  }

  // ── Filtr po statusie (chipy) ─────────────────────────────────────────────
  function initFilter(root){
    var chips = $all('.pv-hub-chip[data-filter-val]', root);
    if (!chips.length) return;
    var items = $all('[data-filter-item]', root);
    var live  = root.querySelector('[data-pv-filter-live]');
    var forms = ((live && live.getAttribute('data-plural')) || 'pozycję|pozycje|pozycji').split('|');

    function apply(val){
      var shown = 0;
      items.forEach(function (it){
        var ok = val === 'all' || it.getAttribute('data-status') === val;
        it.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      chips.forEach(function (c){ c.setAttribute('aria-pressed', String(c.getAttribute('data-filter-val') === val)); });
      if (live) live.textContent = 'Pokazano ' + shown + ' ' + plural(shown, forms);
    }
    chips.forEach(function (c){
      c.addEventListener('click', function (){ apply(c.getAttribute('data-filter-val')); });
    });
  }

  // ── Wyszukiwarka „Centrum akcji" + filtr „Wymaga uwagi" ───────────────────
  function initHub(root){
    var input = root.querySelector('[data-pv-hub-search]');
    var items = $all('[data-pv-hub-item]', root);
    var chips = $all('.pv-hub-chip[data-hub-filter]', root);
    var live  = root.querySelector('[data-pv-hub-live]');
    var empty = root.querySelector('[data-pv-hub-empty]');
    var emptyQ = empty ? empty.querySelector('[data-pv-hub-empty-q]') : null;
    var clear = root.querySelector('[data-pv-hub-clear]');
    var grid  = root.querySelector('[data-pv-hub-grid]');
    if (!items.length) return;

    var state = { q: '', filter: 'all' };
    function norm(s){ return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

    function apply(){
      var nq = norm(state.q.trim()), shown = 0;
      items.forEach(function (it){
        var ok = true;
        if (state.filter === 'attention' && it.getAttribute('data-attention') !== '1') ok = false;
        if (ok && nq){
          var hay = norm(it.getAttribute('data-search') || '');
          if (hay.indexOf(nq) === -1) ok = false;
        }
        it.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      chips.forEach(function (c){ c.setAttribute('aria-pressed', String(c.getAttribute('data-hub-filter') === state.filter)); });
      if (grid)  grid.style.display  = shown ? '' : 'none';
      if (empty){
        empty.style.display = shown ? 'none' : '';
        if (!shown && emptyQ) emptyQ.textContent = state.q;
      }
      if (live) live.textContent = 'Pokazano ' + shown + ' ' + plural(shown, ['akcję', 'akcje', 'akcji']);
    }

    if (input) input.addEventListener('input', function (){ state.q = input.value; apply(); });
    chips.forEach(function (c){
      c.addEventListener('click', function (){ state.filter = c.getAttribute('data-hub-filter'); apply(); });
    });
    if (clear) clear.addEventListener('click', function (){
      state.q = ''; state.filter = 'all'; if (input) input.value = ''; apply(); if (input) input.focus();
    });
  }

  // ── Żywe odliczanie do końca umowy ────────────────────────────────────────
  function initCountdown(root){
    var start = parseInt(root.getAttribute('data-start'), 10);
    var end   = parseInt(root.getAttribute('data-end'), 10);
    if (!start || !end) return;

    var units = root.querySelector('[data-cd-units]');
    var elD = root.querySelector('[data-cd="days"]'),  elH = root.querySelector('[data-cd="hours"]');
    var elM = root.querySelector('[data-cd="mins"]'),  elS = root.querySelector('[data-cd="secs"]');
    var bar = root.querySelector('[data-cd-bar]'),     fill = root.querySelector('[data-cd-fill]');
    var lbl = root.querySelector('[data-cd-label]'),   pctEl = root.querySelector('[data-cd-pct]');
    function pad(n){ return (n < 10 ? '0' : '') + n; }
    function plDni(n){ return n === 1 ? 'dzień' : 'dni'; }

    function tick(){
      var now = Date.now();
      var remain = Math.max(0, end - now);
      var ended  = remain <= 0;
      var pct = end > start ? Math.min(100, Math.max(0, Math.round((now - start) / (end - start) * 100))) : 100;
      var totalSec = Math.floor(remain / 1000);
      var days = Math.floor(totalSec / 86400);
      var hours = Math.floor((totalSec % 86400) / 3600);
      var mins = Math.floor((totalSec % 3600) / 60);
      var secs = totalSec % 60;

      if (units) units.style.display = ended ? 'none' : '';
      if (!ended){
        if (elD) elD.textContent = days;
        if (elH) elH.textContent = pad(hours);
        if (elM) elM.textContent = pad(mins);
        if (elS) elS.textContent = pad(secs);
      }
      if (fill) fill.style.width = pct + '%';
      if (pctEl) pctEl.textContent = pct + '%';
      if (lbl) lbl.innerHTML = ended ? '<strong>Umowa zakończona</strong>' : ('Pozostało <strong>' + days + '</strong> dni');
      if (bar){
        bar.setAttribute('aria-valuenow', ended ? 100 : pct);
        bar.setAttribute('aria-valuetext', ended ? 'Umowa zakończona — 100%' : ('Postęp ' + pct + '%, pozostało ' + days + ' ' + plDni(days)));
      }
      if (ended && timer){ clearInterval(timer); timer = null; }
    }
    var timer = setInterval(tick, 1000);
    tick();
  }

  // ── Optymistyczne „Weź"/„Ukończ" zadanie + podgląd w offcanvas ────────────
  function initTasks(root){
    var BASE = root.getAttribute('data-base') || '';
    var CSRF = root.getAttribute('data-csrf') || '';
    var sr = document.getElementById('pv-tasks-sr');
    function announce(msg){ if (!sr) return; sr.textContent = ''; setTimeout(function (){ sr.textContent = msg; }, 50); }

    function post(url, payload){
      return fetch(BASE + url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (r){ return r.json(); })
        .then(function (r){ if (!r.ok) throw new Error(r.error || 'err'); return r; });
    }

    // Podgląd szczegółów w Bootstrapowym offcanvas.
    root.addEventListener('click', function (e){
      var open = e.target.closest('[data-task-detail]');
      if (open){
        e.preventDefault();
        var id = open.getAttribute('data-task-detail');
        var oc = document.getElementById('panelTaskOffcanvas');
        var body = document.getElementById('panelTaskOffcanvasBody');
        if (!oc || !window.bootstrap){ window.location = BASE + '/tasks/index.php'; return; }
        body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
        bootstrap.Offcanvas.getOrCreateInstance(oc).show();
        fetch(BASE + '/tasks/detail.php?id=' + id)
          .then(function (r){ return r.text(); })
          .then(function (htmlStr){ body.innerHTML = ''; body.appendChild(document.createRange().createContextualFragment(htmlStr)); })
          .catch(function (){ body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>'; });
        return;
      }

      var done = e.target.closest('[data-task-complete]');
      if (done){
        var li = done.closest('[data-task-row]');
        var tid = done.getAttribute('data-task-complete');
        var title = (li && li.getAttribute('data-task-title')) || 'zadanie';
        done.disabled = true; if (li) li.style.opacity = '.5';
        announce('Zadanie „' + title + '" oznaczone jako ukończone.');
        post('/tasks/api/task.php', { _csrf: CSRF, action: 'complete', id: parseInt(tid, 10) })
          .then(function (){ if (li) li.remove(); refreshCounts(); })
          .catch(function (){ done.disabled = false; if (li) li.style.opacity = ''; announce('Nie udało się ukończyć zadania.'); });
        return;
      }

      var take = e.target.closest('[data-task-claim]');
      if (take){
        var liO = take.closest('[data-task-row]');
        var tidO = take.getAttribute('data-task-claim');
        var titleO = (liO && liO.getAttribute('data-task-title')) || 'zadanie';
        take.disabled = true; if (liO) liO.style.opacity = '.5';
        announce('Wzięto zadanie „' + titleO + '".');
        post('/tasks/api/claim.php', { _csrf: CSRF, task_id: parseInt(tidO, 10), action: 'add' })
          .then(function (){ window.location.reload(); })
          .catch(function (){ take.disabled = false; if (liO) liO.style.opacity = ''; announce('Nie udało się wziąć zadania.'); });
        return;
      }
    });

    function refreshCounts(){
      var n = $all('[data-task-row][data-task-mine]', root).filter(function (el){ return el.style.display !== 'none' && el.parentNode; }).length;
      var head = root.querySelector('[data-task-mine-count]');
      if (head) head.textContent = n ? ' (' + n + ')' : '';
      var ul = root.querySelector('[data-task-mine-list]');
      if (ul && !ul.querySelector('[data-task-row]')){
        var sect = root.querySelector('[data-task-mine-section]');
        if (sect) sect.style.display = 'none';
      }
    }
  }

  function initAll(){
    $all('[data-pv-filter]').forEach(initFilter);
    $all('[data-pv-hub]').forEach(initHub);
    $all('[data-pv-countdown]').forEach(initCountdown);
    $all('[data-pv-tasks]').forEach(initTasks);
  }
  if (document.readyState !== 'loading') initAll();
  else document.addEventListener('DOMContentLoaded', initAll);
})();
</script>
