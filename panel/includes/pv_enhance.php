<?php
/**
 * panel/includes/pv_enhance.php — Wspólny, lekki enhancer panelu (czysty JS + Bootstrap).
 *
 * Zastępuje wcześniejszy wzorzec „React islands z CDN" (pv_react_boot.php).
 * Wszystko renderuje serwer (markup Bootstrap), a ten plik dokłada tylko
 * progresywne wzbogacenia w waniliowym JS — bez kroku budowania i bez CDN:
 *
 *   • filtr po statusie (chipy)         → [data-pv-filter]
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
.pv-hub-chips{display:inline-flex;gap:.35rem;flex-wrap:wrap}
/* ── Odliczanie do końca umowy (na tle hero) ─────────────────────────────── */
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

  // ── Centrum akcji — filtr „Wymaga uwagi" ──────────────────────────────────
  function initHub(root){
    var items = $all('[data-pv-hub-item]', root);
    var chips = $all('.pv-hub-chip[data-hub-filter]', root);
    var live  = root.querySelector('[data-pv-hub-live]');
    if (!items.length || !chips.length) return;

    function apply(filter){
      var shown = 0;
      items.forEach(function (it){
        var ok = filter !== 'attention' || it.getAttribute('data-attention') === '1';
        it.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      chips.forEach(function (c){ c.setAttribute('aria-pressed', String(c.getAttribute('data-hub-filter') === filter)); });
      if (live) live.textContent = 'Pokazano ' + shown + ' ' + plural(shown, ['akcję', 'akcje', 'akcji']);
    }
    chips.forEach(function (c){
      c.addEventListener('click', function (){ apply(c.getAttribute('data-hub-filter')); });
    });
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
    function openTaskDetail(id){
      var oc = document.getElementById('panelTaskOffcanvas');
      var body = document.getElementById('panelTaskOffcanvasBody');
      if (!oc || !window.bootstrap){ window.location = BASE + '/tasks/index.php'; return; }
      body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
      bootstrap.Offcanvas.getOrCreateInstance(oc).show();
      fetch(BASE + '/tasks/detail.php?id=' + id)
        .then(function (r){ return r.text(); })
        .then(function (htmlStr){ body.innerHTML = ''; body.appendChild(document.createRange().createContextualFragment(htmlStr)); })
        .catch(function (){ body.innerHTML = '<div class="pv-alert pv-alert-danger m-3">Błąd ładowania.</div>'; });
    }

    root.addEventListener('click', function (e){
      var open = e.target.closest('[data-task-detail]');
      if (open){
        e.preventDefault();
        openTaskDetail(open.getAttribute('data-task-detail'));
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

      // Klik gdziekolwiek na wierszu (poza przyciskami obsłużonymi wyżej) też otwiera szczegóły.
      var row = e.target.closest('[data-task-row]');
      if (row && row.getAttribute('data-task-id')){
        openTaskDetail(row.getAttribute('data-task-id'));
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
    $all('[data-pv-tasks]').forEach(initTasks);
  }
  if (document.readyState !== 'loading') initAll();
  else document.addEventListener('DOMContentLoaded', initAll);
})();
</script>
