/* modules/launcher/launcher.js — wspólny launcher modułów.
 * Trzy układy (lista / szuflada / pełny ekran) zapamiętane w localStorage,
 * wyszukiwarka, nawigacja klawiaturą (↑ ↓ Enter Esc), sekcja „Ostatnio używane”.
 * Konfiguracja: window.__feerLauncher = {appUrl, portal, items:[{key,sec,label,desc,icon,mc,mb,url,on,badge}]}.
 */
(function () {
  'use strict';
  var cfg = window.__feerLauncher; if (!cfg) return;
  var trigger = document.getElementById('fl-trigger');
  var root    = document.getElementById('fl-root');
  if (!trigger || !root) return;
  if (root.parentNode !== document.body) document.body.appendChild(root);

  var KEY_LAYOUT = 'feerLauncherLayout', KEY_RECENT = 'feerLauncherRecent';
  var VALID = ['list', 'drawer', 'overlay'];
  var layout = lsGet(KEY_LAYOUT); if (VALID.indexOf(layout) < 0) layout = 'list';
  var isOpen = false, query = '', focusIdx = -1, visible = [];

  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // ── Ostatnio używane: klucze modułów, najnowszy pierwszy, max 5 ─────────
  function recentKeys() { try { var a = JSON.parse(lsGet(KEY_RECENT) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } }
  function pushRecent(key) {
    if (!key) return;
    var a = recentKeys().filter(function (k) { return k !== key; }); a.unshift(key);
    lsSet(KEY_RECENT, JSON.stringify(a.slice(0, 5)));
  }
  function recentItems() {
    var byKey = {}; cfg.items.forEach(function (it) { byKey[it.key] = it; });
    return recentKeys().map(function (k) { return byKey[k]; }).filter(function (it) { return it && !it.on; });
  }

  // ── Dane ─────────────────────────────────────────────────────────────────
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l'); }
  function match(it) { if (!query) return true; var q = norm(query); return norm(it.label).indexOf(q) >= 0 || norm(it.desc).indexOf(q) >= 0 || norm(it.sec).indexOf(q) >= 0; }
  function sections() {
    var order = [], map = {};
    cfg.items.forEach(function (it) { if (!match(it)) return; if (!map[it.sec]) { map[it.sec] = []; order.push(it.sec); } map[it.sec].push(it); });
    var out = order.map(function (s) { return { name: s, icon: 'bi-folder2', items: map[s] }; });
    if (!query) { var r = recentItems(); if (r.length) out.unshift({ name: 'Ostatnio używane', icon: 'bi-clock-history', items: r }); }
    return out;
  }

  // ── HTML ─────────────────────────────────────────────────────────────────
  function itemHTML(it, idx, big) {
    return '<a class="fl-item' + (big ? ' fl-item-big' : '') + (it.on ? ' fl-on' : '') + (idx === focusIdx ? ' fl-focus' : '') + '" href="' + esc(it.url) + '" data-idx="' + idx + '" data-key="' + esc(it.key) + '" role="option" aria-selected="' + (idx === focusIdx) + '">'
      + '<span class="fl-ic" style="--mc:' + esc(it.mc) + ';--mb:' + esc(it.mb) + '"><i class="bi ' + esc(it.icon) + '"></i></span>'
      + '<span class="fl-tx"><span class="fl-label">' + esc(it.label) + '</span>' + (it.desc ? '<span class="fl-desc">' + esc(it.desc) + '</span>' : '') + '</span>'
      + (it.on ? '<span class="fl-cur-badge">Tutaj</span>' : (it.badge ? '<span class="fl-badge" title="Do zrobienia">' + esc(it.badge) + '</span>' : ''))
      + '</a>';
  }
  function bodyHTML(big) {
    var html = ''; visible = []; var secs = sections();
    secs.forEach(function (s) {
      html += '<div class="fl-sec-head"><i class="bi ' + s.icon + '"></i>' + esc(s.name) + '</div><div class="' + (big ? 'fl-grid' : 'fl-list') + '">';
      s.items.forEach(function (it) { var i = visible.length; visible.push(it); html += itemHTML(it, i, big); });
      html += '</div>';
    });
    return html || '<div class="fl-empty">Brak modułów pasujących do „' + esc(query) + '”.</div>';
  }
  function switchHTML() {
    function b(k, icon, t) { return '<button type="button" class="fl-sw-btn' + (layout === k ? ' fl-sw-on' : '') + '" data-layout="' + k + '" title="' + t + '" aria-label="Układ: ' + t + '" aria-pressed="' + (layout === k) + '"><i class="bi ' + icon + '"></i></button>'; }
    return '<div class="fl-switch" role="group" aria-label="Układ launchera">' + b('list', 'bi-list-ul', 'Lista') + b('drawer', 'bi-layout-sidebar-inset-reverse', 'Szuflada') + b('overlay', 'bi-fullscreen', 'Pełny ekran') + '</div>';
  }
  function headHTML() { return '<div class="fl-head"><span class="fl-title"><i class="bi bi-grid-3x3-gap-fill"></i>Moduły</span>' + switchHTML() + '<button type="button" class="fl-close" id="fl-close" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button></div>'; }
  function searchHTML() { return '<div class="fl-search"><i class="bi bi-search"></i><input type="search" id="fl-q" placeholder="Szukaj modułu…" autocomplete="off" spellcheck="false" aria-label="Szukaj modułu" aria-controls="fl-scroll" value="' + esc(query) + '"></div>'; }
  function footHTML() {
    return '<div class="fl-foot"><a href="' + esc(cfg.portal) + '"><i class="bi bi-grid-3x3-gap"></i> Portal — wszystkie moduły</a>'
      + '<span class="fl-hint"><span><kbd>↑</kbd><kbd>↓</kbd> wybór</span><span><kbd>↵</kbd> otwórz</span><span><kbd>esc</kbd> zamknij</span></span></div>';
  }

  function render() {
    root.className = 'fl-mode-' + layout;
    var big = (layout === 'overlay');
    var inner = headHTML() + searchHTML() + '<div class="fl-scroll" id="fl-scroll" role="listbox" aria-label="Moduły">' + bodyHTML(big) + '</div>' + footHTML();
    if (layout === 'list')        root.innerHTML = '<div class="fl-pop" role="dialog" aria-label="Wybór modułu">' + inner + '</div>';
    else if (layout === 'drawer') root.innerHTML = '<div class="fl-backdrop" data-close="1"></div><div class="fl-drawer" role="dialog" aria-modal="true" aria-label="Wybór modułu">' + inner + '</div>';
    else                          root.innerHTML = '<div class="fl-backdrop" data-close="1"></div><div class="fl-overlay-inner" role="dialog" aria-modal="true" aria-label="Wybór modułu">' + inner + '</div>';
    if (layout === 'list') position();
    bind(big);
  }
  function position() {
    var pop = root.querySelector('.fl-pop'); if (!pop) return;
    var r = trigger.getBoundingClientRect();
    pop.style.top = (r.bottom + 6) + 'px';
    var w = pop.offsetWidth || 320, left = r.left;
    if (left + w > window.innerWidth - 8) left = window.innerWidth - 8 - w;
    pop.style.left = Math.max(8, left) + 'px';
  }
  function refreshList(big) {
    var sc = document.getElementById('fl-scroll'); if (sc) sc.innerHTML = bodyHTML(big);
  }
  function markFocus() {
    root.querySelectorAll('.fl-item').forEach(function (n) {
      var on = (+n.getAttribute('data-idx') === focusIdx);
      n.classList.toggle('fl-focus', on); n.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) n.scrollIntoView({ block: 'nearest' });
    });
  }
  function bind(big) {
    root.querySelectorAll('.fl-sw-btn').forEach(function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); layout = b.getAttribute('data-layout'); lsSet(KEY_LAYOUT, layout); focusIdx = -1; render(); });
    });
    var c = document.getElementById('fl-close'); if (c) c.addEventListener('click', close);
    root.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
    var q = document.getElementById('fl-q');
    if (q) {
      q.addEventListener('input', function () { query = q.value; focusIdx = query ? 0 : -1; refreshList(big); markFocus(); });
      setTimeout(function () { q.focus(); }, 30);
    }
    root.addEventListener('click', function (e) {
      var a = e.target.closest('.fl-item'); if (a) pushRecent(a.getAttribute('data-key'));
    });
    root.addEventListener('mousemove', function (e) {
      var a = e.target.closest('.fl-item'); if (!a) return;
      var i = +a.getAttribute('data-idx'); if (i !== focusIdx) { focusIdx = i; markFocus(); }
    });
  }

  function open() {
    if (isOpen) return;
    isOpen = true; query = ''; focusIdx = -1;
    root.hidden = false; trigger.setAttribute('aria-expanded', 'true');
    render();
    document.addEventListener('keydown', onKey);
    document.addEventListener('click', onDoc, true);
    window.addEventListener('resize', position);
  }
  function close(returnFocus) {
    if (!isOpen) return;
    isOpen = false; root.hidden = true; root.innerHTML = '';
    trigger.setAttribute('aria-expanded', 'false');
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('click', onDoc, true);
    window.removeEventListener('resize', position);
    if (returnFocus !== false) trigger.focus();
  }
  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!visible.length) return;
      e.preventDefault();
      var d = e.key === 'ArrowDown' ? 1 : -1;
      focusIdx = focusIdx < 0 ? (d > 0 ? 0 : visible.length - 1) : (focusIdx + d + visible.length) % visible.length;
      markFocus();
    } else if (e.key === 'Enter') {
      if (focusIdx >= 0 && visible[focusIdx]) { e.preventDefault(); pushRecent(visible[focusIdx].key); window.location.href = visible[focusIdx].url; }
    }
  }
  function onDoc(e) {
    if (layout !== 'list') return;
    if (root.contains(e.target) || trigger.contains(e.target)) return;
    close(false);
  }
  trigger.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); isOpen ? close() : open(); });
  // Zapamiętaj bieżący moduł jako „ostatnio używany” — także gdy wejście było z menu, nie z launchera.
  cfg.items.forEach(function (it) { if (it.on) pushRecent(it.key); });
})();
