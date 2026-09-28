<?php
/**
 * contracts/includes/cv_ui.php — warstwa „v2” widoków i formularzy umów:
 * zwijane karty + uporządkowany pasek zakładek, mniej ikon ozdobnych.
 *
 * Włączanie: klasa `cv-v2` na kontenerze (np. `.cv-tabs-layout` w widoku albo
 * wrapper formularza) + cv_ui_assets() raz na stronę. Bez klasy nic się nie
 * zmienia — typy umów przechodzą na v2 pojedynczo.
 *
 * Zwijane karty (progressive enhancement, bez zmiany markupu sekcji):
 *   .cv-section > .cv-section-head .cv-section-title   (widok umowy)
 *   .esec > .esec-head .esec-title | h6                 (formularz edycji)
 *   .card > .card-header                                (karty Bootstrap w zakładkach)
 * Tytuł staje się przyciskiem (aria-expanded/aria-controls) w elemencie
 * nagłówka — wzorzec „accordion” WAI-ARIA. Stan zapamiętany w localStorage
 * per typ umowy + sekcja (data-cc-key na kontenerze). Domyślnie zwinięte:
 * data-cc-collapsed na sekcji. Wyłączenie dla sekcji: data-cc="off".
 *
 * Formularze: przy błędzie walidacji (zdarzenie `invalid`) otwiera się karta
 * i zakładka Bootstrap zawierające pole. Wydruk: wszystkie karty rozwinięte.
 */
function cv_ui_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<style>
.cv-v2 {
  --cv-line: #E2E8F0; --cv-soft: #F8FAFC; --cv-ink: #0F172A;
  --cv-muted: #475569; --cv-label: #64748B; --cv-accent: #1D4ED8;
}

/* ── Pasek zakładek: jeden rząd, bez ikon, reszta w „Więcej” ─────────────── */
.cv-v2 .cv-tabbar {
  display: flex; flex-wrap: nowrap; align-items: center; gap: .15rem;
  margin: 0 0 1rem; padding: .3rem; list-style: none;
  background: #fff; border: 1px solid var(--cv-line); border-radius: 12px;
  overflow-x: auto; scrollbar-width: thin;
}
.cv-v2 .cv-tabbar .nav-item { flex-shrink: 0; }
.cv-v2 .cv-tabbar .nav-link {
  border: 0 !important; border-radius: 8px !important; background: transparent !important;
  padding: .45rem .85rem; font-size: .86rem; font-weight: 500; white-space: nowrap;
  color: var(--cv-muted); display: inline-flex; align-items: center; gap: .4rem;
}
.cv-v2 .cv-tabbar .nav-link:hover { color: var(--cv-ink); background: #F1F5F9 !important; }
.cv-v2 .cv-tabbar .nav-link.active,
.cv-v2 .cv-tabbar .nav-link[aria-selected="true"] {
  color: var(--cv-accent) !important; background: #EFF5FF !important; font-weight: 600;
}
.cv-v2 .cv-tabbar .nav-link:focus-visible { outline: 2px solid var(--cv-accent); outline-offset: 1px; }
.cv-v2 .cv-tabbar .nav-link > .bi:not(.cv-keep) { display: none; }
.cv-v2 .cv-tabbar .cv-tab-more { margin-left: auto; }
.cv-v2 .cv-tabbar .dropdown-menu { font-size: .88rem; border-color: var(--cv-line); border-radius: 10px; padding: .3rem; min-width: 14rem; }
.cv-v2 .cv-tabbar .dropdown-item { border-radius: 6px; display: flex; align-items: center; gap: .5rem; padding: .45rem .7rem; color: var(--cv-ink); }
.cv-v2 .cv-tabbar .dropdown-item > .bi { display: none; }
.cv-v2 .cv-tabbar .dropdown-item.active, .cv-v2 .cv-tabbar .dropdown-item:active { background: #EFF5FF; color: var(--cv-accent); font-weight: 600; }
.cv-v2 .cv-tabbar .badge { font-weight: 600; margin-left: auto; }
.cv-v2 .cv-tabbar .nav-link .badge { margin-left: .15rem; }
.cv-v2 .cv-dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 50%; background: #94A3B8; flex-shrink: 0; }
.cv-v2 .cv-dot.is-ok { background: #15803D; } .cv-v2 .cv-dot.is-warn { background: #B45309; } .cv-v2 .cv-dot.is-bad { background: #B91C1C; }
@media print { .cv-v2 .cv-tabbar { display: none; } }
/* Zakładki formularza (Alpine) — pasek przyklejony do górnej krawędzi karty formularza */
.cv-v2 .cv-formtabs { margin: 0; border-width: 0 0 1px; border-radius: 0; background: var(--cv-soft); padding: .4rem .5rem; }
.cv-v2 .cv-formtabs .nav-link { cursor: pointer; }

/* ── Zawartość zakładki ───────────────────────────────────────────────────── */
.cv-v2 .cv-panel {
  background: #fff; border: 1px solid var(--cv-line); border-radius: 12px;
  padding: 1rem; box-shadow: 0 1px 3px rgba(15,23,42,.04);
}

/* ── Karty (sekcje) ───────────────────────────────────────────────────────── */
.cv-v2 .cv-section, .cv-v2 .esec {
  background: #fff; border: 1px solid var(--cv-line); border-radius: 10px;
  padding: 0; margin: 0 0 .75rem;
}
.cv-v2 .cv-section:last-child, .cv-v2 .esec:last-child { margin-bottom: 0; }
.cv-v2 .cv-section-head, .cv-v2 .esec-head {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin: 0; padding: .3rem .6rem .3rem .3rem;
  background: var(--cv-soft); border-bottom: 1px solid var(--cv-line); border-radius: 10px 10px 0 0;
}
.cv-v2 .cc-collapsed > .cv-section-head, .cv-v2 .cc-collapsed > .esec-head,
.cv-v2 .card.cc-collapsed > .card-header { border-bottom-color: transparent; border-radius: 10px; }
.cv-v2 .cv-section-icon, .cv-v2 .esec-head > .bi { display: none; }
.cv-v2 .cv-section-title, .cv-v2 .esec-title, .cv-v2 .esec-head h6 {
  flex: 1 1 auto; margin: 0; font-size: .95rem; font-weight: 600;
  text-transform: none; letter-spacing: 0; color: var(--cv-ink);
}
.cv-v2 .esec-sub { font-weight: 400; font-size: .82rem; color: var(--cv-label); margin-left: .35rem; }
.cv-v2 .cv-section-action { margin-left: auto; }
.cv-v2 .cc-body { padding: .9rem 1rem 1rem; }
.cv-v2 .cc-body[hidden] { display: none; }

/* Karty Bootstrap w zakładkach — spójne z sekcjami */
.cv-v2 .tab-pane .card, .cv-v2 .cc-scope .card { border-color: var(--cv-line); border-radius: 10px; box-shadow: none !important; }
.cv-v2 .tab-pane .card > .card-header, .cv-v2 .cc-scope .card > .card-header {
  background: var(--cv-soft); border-bottom-color: var(--cv-line); border-radius: 10px 10px 0 0;
  padding: .3rem .6rem .3rem .3rem; display: flex; align-items: center; flex-wrap: wrap; gap: .5rem;
  color: var(--cv-ink); font-weight: 600;
}
.cv-v2 .card > .cc-body { padding: 0; }
.cv-v2 .card > .card-header .cc-heading { flex: 1 1 auto; margin: 0; font-size: .95rem; font-weight: 600; }
.cv-v2 .card > .card-header > .bi:first-child, .cv-v2 .card > .card-header .cc-label > .bi:first-child { display: none; }

/* Przycisk zwijania */
.cv-v2 .cc-toggle {
  display: flex; align-items: center; gap: .5rem; width: 100%;
  background: none; border: 0; border-radius: 6px; padding: .4rem .5rem;
  font: inherit; color: inherit; text-align: left; cursor: pointer;
}
.cv-v2 .cc-toggle:hover { background: #EEF2F7; }
.cv-v2 .cc-toggle:focus-visible { outline: 2px solid var(--cv-accent); outline-offset: 1px; }
.cv-v2 .cc-chev { font-size: .8rem; color: var(--cv-label); transition: transform .15s ease; flex-shrink: 0; }
.cv-v2 .cc-toggle[aria-expanded="false"] .cc-chev { transform: rotate(-90deg); }
.cv-v2 .cc-label { flex: 1 1 auto; min-width: 0; }
@media (prefers-reduced-motion: reduce) { .cv-v2 .cc-chev { transition: none; } }

/* Zwiń / rozwiń wszystko */
.cv-v2 .cc-bulk { display: flex; justify-content: flex-end; gap: .75rem; margin: -.25rem 0 .5rem; }
.cv-v2 .cc-bulk button { background: none; border: 0; padding: .15rem 0; font-size: .8rem; color: var(--cv-accent); text-decoration: underline; cursor: pointer; }

/* Karty boczne formularza wolontariatu (panel zapisu) — spójne z kartami sekcji */
.cv-v2 .sidebar-card-head { text-transform: none; letter-spacing: 0; font-size: .92rem; font-weight: 600; color: var(--cv-ink); }
.cv-v2 .sidebar-card-head > .bi { display: none; }

/* Etykiety pól — kontrast ≥ 4.5:1 (było #94A3B8 ≈ 2.6:1) */
.cv-v2 .cv-label, .cv-v2 .detail-label, .cv-v2 .cv-table th { color: var(--cv-label); }

@media print {
  .cv-v2 .cc-body[hidden] { display: block !important; }
  .cv-v2 .cc-chev, .cv-v2 .cc-bulk { display: none !important; }
}
</style>
<script>
(function () {
  'use strict';
  var LS = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var uid = 0;
  function slug(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/ł/g, 'l').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 48);
  }
  var INTERACTIVE = 'a,button,input,select,textarea,form,label,.btn,.dropdown,[role="button"]';

  /** Zamienia sekcję w zwijaną kartę. titleHost — element nagłówka (heading), do którego trafia przycisk. */
  function enhance(sec, head, titleHost, titleNodes, keyPrefix) {
    if (sec.dataset.ccDone || sec.dataset.cc === 'off') return;
    // Treść = wszystko po nagłówku
    var body = document.createElement('div');
    body.className = 'cc-body';
    body.id = 'cc-body-' + (++uid);
    var n = head.nextSibling, hasContent = false;
    while (n) {
      var nx = n.nextSibling;
      if (n.nodeType === 1 || (n.nodeType === 3 && n.textContent.trim())) hasContent = true;
      body.appendChild(n);
      n = nx;
    }
    if (!hasContent) { while (body.firstChild) sec.appendChild(body.firstChild); return; }
    sec.appendChild(body);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cc-toggle';
    btn.setAttribute('aria-controls', body.id);
    btn.innerHTML = '<i class="bi bi-chevron-down cc-chev" aria-hidden="true"></i>';
    var label = document.createElement('span');
    label.className = 'cc-label';
    titleNodes.forEach(function (t) { label.appendChild(t); });
    btn.appendChild(label);
    titleHost.appendChild(btn);

    var key = keyPrefix + ':' + (sec.id || slug(label.textContent));
    function set(open, persist) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      body.hidden = !open;
      sec.classList.toggle('cc-collapsed', !open);
      if (persist) LS.set(key, open ? '1' : '0');
    }
    body.__ccSet = set;
    var saved = LS.get(key);
    set(saved !== null ? saved === '1' : !sec.hasAttribute('data-cc-collapsed'), false);
    btn.addEventListener('click', function () { set(btn.getAttribute('aria-expanded') !== 'true', true); });
    sec.dataset.ccDone = '1';
  }

  function initScope(scope) {
    var prefix = 'cc:' + (scope.dataset.ccKey || location.pathname);

    scope.querySelectorAll('.cv-section').forEach(function (sec) {
      if (sec.closest('.modal')) return;
      var head = sec.querySelector(':scope > .cv-section-head');
      var title = head && head.querySelector('.cv-section-title');
      if (!title) return;
      title.setAttribute('role', 'heading');
      title.setAttribute('aria-level', '3');
      enhance(sec, head, title, Array.prototype.slice.call(title.childNodes), prefix);
    });

    scope.querySelectorAll('.esec').forEach(function (sec) {
      if (sec.closest('.modal')) return;
      var head = sec.querySelector(':scope > .esec-head');
      var title = head && head.querySelector('.esec-title, h6, h3');
      if (!title) return;
      enhance(sec, head, title, Array.prototype.slice.call(title.childNodes), prefix);
    });

    // Karty Bootstrap w zakładkach: tytuł = początkowe, nieinteraktywne węzły nagłówka
    scope.querySelectorAll('.tab-pane .card, .cc-scope .card').forEach(function (card) {
      if (card.closest('.modal')) return;
      var head = card.querySelector(':scope > .card-header');
      if (!head || head.querySelector('.nav-tabs, .nav-pills')) return;
      var nodes = [];
      var target = head;
      // Nagłówek z pojedynczym wrapperem (div/span z tytułem + przyciski obok)
      for (var i = 0; i < head.childNodes.length; i++) {
        var c = head.childNodes[i];
        if (c.nodeType === 3) { if (c.textContent.trim()) nodes.push(c); else if (nodes.length) nodes.push(c); continue; }
        if (c.nodeType !== 1) continue;
        if (c.matches(INTERACTIVE) || c.querySelector(INTERACTIVE) || c.classList.contains('badge') || c.classList.contains('ms-auto')) break;
        nodes.push(c);
      }
      if (!nodes.length || !nodes.some(function (x) { return x.textContent.trim(); })) return;
      var h = document.createElement('div');
      h.className = 'cc-heading';
      h.setAttribute('role', 'heading');
      h.setAttribute('aria-level', '3');
      head.insertBefore(h, nodes[0]);
      enhance(card, head, h, nodes, prefix);
    });

    // „Zwiń / rozwiń wszystko” w zakładkach z co najmniej 3 kartami
    scope.querySelectorAll('.tab-pane, [data-tab-pane]').forEach(function (pane) {
      var bodies = pane.querySelectorAll('.cc-body');
      if (bodies.length < 3 || pane.querySelector(':scope > .cc-bulk')) return;
      var bar = document.createElement('div');
      bar.className = 'cc-bulk no-print';
      bar.innerHTML = '<button type="button" data-cc-all="1">Rozwiń wszystko</button><button type="button" data-cc-all="0">Zwiń wszystko</button>';
      bar.addEventListener('click', function (e) {
        var v = e.target.getAttribute('data-cc-all');
        if (v === null) return;
        pane.querySelectorAll('.cc-body').forEach(function (b) { b.__ccSet && b.__ccSet(v === '1', true); });
      });
      pane.insertBefore(bar, pane.firstChild);
    });
  }

  /** Odsłania element: rozwija karty-przodków i przełącza zakładkę Bootstrap. */
  function reveal(el) {
    var b = el.closest('.cc-body');
    while (b) { if (b.hidden && b.__ccSet) b.__ccSet(true, false); b = b.parentElement && b.parentElement.closest('.cc-body'); }
    var pane = el.closest('.tab-pane');
    if (pane && !pane.classList.contains('active') && pane.id && window.bootstrap) {
      var trig = document.querySelector('[data-bs-toggle="tab"][data-bs-target="#' + pane.id + '"]');
      if (trig) { try { bootstrap.Tab.getOrCreateInstance(trig).show(); } catch (e) {} }
    }
  }
  window.cvReveal = reveal;

  document.addEventListener('invalid', function (e) { if (e.target && e.target.closest) reveal(e.target); }, true);

  function revealHash() {
    if (!location.hash || location.hash.length < 2) return;
    var t; try { t = document.querySelector(location.hash); } catch (e) { return; }
    if (t && !t.matches('.tab-pane')) {
      reveal(t);
      // przeglądarka przewinęła do kotwicy, zanim zakładka była widoczna
      setTimeout(function () { t.scrollIntoView({ block: 'start' }); }, 60);
    }
  }
  window.addEventListener('hashchange', revealHash);

  // Karty budujemy PRZED inicjalizacją Alpine (alpine:init — DOM jest już sparsowany,
  // bo Alpine ładuje się z defer), żeby Alpine nie wiązał się z przenoszonymi węzłami.
  var booted = false;
  // Zapamiętanie zakładki w obrębie sesji: <ul class="cv-tabbar" data-remember="klucz">
  function rememberTabs() {
    document.querySelectorAll('.cv-v2 .cv-tabbar[data-remember]').forEach(function (bar) {
      var key = 'cvtab:' + bar.dataset.remember, saved = null;
      try { saved = sessionStorage.getItem(key); } catch (e) {}
      if (saved && !location.hash && window.bootstrap) {
        var t = bar.querySelector('[data-bs-target="' + saved + '"]');
        if (t) { try { bootstrap.Tab.getOrCreateInstance(t).show(); } catch (e) {} }
      }
      bar.addEventListener('shown.bs.tab', function (e) {
        try { sessionStorage.setItem(key, e.target.getAttribute('data-bs-target')); } catch (er) {}
      });
    });
  }
  function boot() {
    if (booted) return;
    booted = true;
    document.querySelectorAll('.cv-v2').forEach(initScope);
    rememberTabs();
    revealHash();
  }
  document.addEventListener('alpine:init', boot);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
    <?php
}
