/*
 * modules/onedrive_picker/view/picker.js — okno wyboru plików z OneDrive (funkcjonalność współdzielona).
 *
 *   OneDrivePicker.open({
 *     endpoint: APP_URL + '/modules/onedrive_picker/router.php?_route=/list',
 *     csrf:     CSRF,
 *     accept:   '.pdf,.docx',        // opcjonalnie — pokazywane typy plików
 *     maxBytes: 10485760,            // opcjonalnie — większe pliki są widoczne, ale wyłączone
 *     multi:    true,
 *     title:    'Dodaj pliki z OneDrive',
 *     onPick:   function (items) {}  // [{id, name, size}] — pobranie robi serwer modułu docelowego
 *   });
 *
 * Natywny <dialog> (showModal): fokus zamknięty w oknie, Esc zamyka, tło nieaktywne.
 * Bez zależności od Bootstrapa/Tailwinda (okno bywa otwierane z treści wstrzykiwanej AJAX-em).
 */
(function () {
  'use strict';
  if (window.OneDrivePicker) return;

  var CSS =
    '.odp{border:0;border-radius:12px;padding:0;width:min(640px,calc(100vw - 32px));max-height:min(80vh,720px);'
    + 'color:#1f2937;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,.3);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}'
    + '.odp::backdrop{background:rgba(15,23,42,.55)}'
    + '.odp-in{display:flex;flex-direction:column;max-height:inherit}'
    + '.odp-head{display:flex;align-items:center;gap:.5rem;padding:.85rem 1rem;border-bottom:1px solid #e5e7eb}'
    + '.odp-head h2{flex:1;margin:0;font-size:1.05rem;font-weight:700}'
    + '.odp-x{border:0;background:none;font-size:1.5rem;line-height:1;cursor:pointer;color:#374151;padding:.1rem .4rem;border-radius:6px}'
    + '.odp-search{display:flex;gap:.4rem;padding:.7rem 1rem 0}'
    + '.odp-search input{flex:1;min-width:0;border:1px solid #9ca3af;border-radius:6px;padding:.4rem .6rem;font:inherit}'
    + '.odp-crumbs{padding:.55rem 1rem 0;font-size:.85rem}'
    + '.odp-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.15rem}'
    + '.odp-crumbs li+li::before{content:"›";margin:0 .3rem;color:#6b7280}'
    + '.odp-crumbs button{border:0;background:none;padding:0;color:#0057a3;text-decoration:underline;cursor:pointer;font:inherit}'
    + '.odp-status{padding:.4rem 1rem;font-size:.82rem;color:#4b5563;min-height:1.6em}'
    + '.odp-status.err{color:#b91c1c;font-weight:600}'
    + '.odp-list{list-style:none;margin:0;padding:0 .5rem .5rem;overflow:auto;flex:1;min-height:160px}'
    + '.odp-list li{margin:0}'
    + '.odp-row{display:flex;align-items:center;gap:.6rem;width:100%;padding:.5rem;border:0;border-radius:8px;background:none;'
    + 'text-align:left;font:inherit;color:inherit;cursor:pointer}'
    + '.odp-row:hover{background:#f1f5f9}'
    + '.odp-row.off{opacity:.55;cursor:not-allowed}'
    + '.odp-row input{width:1.1rem;height:1.1rem;flex:none;margin:0}'
    + '.odp-ic{flex:none;width:1.6rem;text-align:center;font-size:1.15rem}'
    + '.odp-nm{flex:1;min-width:0;overflow-wrap:anywhere}'
    + '.odp-meta{flex:none;font-size:.78rem;color:#4b5563;white-space:nowrap}'
    + '.odp-foot{display:flex;align-items:center;gap:.5rem;padding:.75rem 1rem;border-top:1px solid #e5e7eb}'
    + '.odp-foot span{flex:1;font-size:.85rem;color:#374151}'
    + '.odp-btn{border:1px solid #6b7280;background:#fff;color:#1f2937;border-radius:6px;padding:.4rem .85rem;font:inherit;cursor:pointer}'
    + '.odp-btn.pri{background:#0078d4;border-color:#0078d4;color:#fff;font-weight:600}'
    + '.odp-btn:disabled{opacity:.5;cursor:not-allowed}'
    + '.odp :focus-visible{outline:3px solid #0078d4;outline-offset:2px}'
    + '@media (prefers-color-scheme:dark){.odp{background:#1f2937;color:#f3f4f6}.odp-head,.odp-foot{border-color:#374151}'
    + '.odp-row:hover{background:#374151}.odp-meta,.odp-status,.odp-foot span{color:#d1d5db}.odp-x{color:#e5e7eb}'
    + '.odp-search input{background:#111827;color:#f3f4f6;border-color:#6b7280}.odp-crumbs button{color:#93c5fd}'
    + '.odp-btn{background:#111827;color:#f3f4f6}.odp-btn.pri{background:#0078d4;color:#fff}}';

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'class') e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    }
    if (text != null) e.textContent = text;
    return e;
  }

  function fmtSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' kB';
    return b + ' B';
  }

  function plural(n, one, few, many) {
    if (n === 1) return one;
    var d = n % 10, t = n % 100;
    return (d >= 2 && d <= 4 && (t < 12 || t > 14)) ? few : many;
  }

  var ICONS = { pdf: '📕', doc: '📘', docx: '📘', odt: '📘', xls: '📗', xlsx: '📗', ods: '📗', csv: '📗',
                ppt: '📙', pptx: '📙', jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️', zip: '🗜️' };

  function open(opts) {
    opts = opts || {};
    if (!document.getElementById('odp-css')) {
      var st = el('style', { id: 'odp-css' }); st.textContent = CSS; document.head.appendChild(st);
    }
    var multi    = opts.multi !== false;
    var maxBytes = opts.maxBytes || 0;
    var accept   = String(opts.accept || '').split(',').map(function (s) { return s.trim().replace(/^\./, '').toLowerCase(); })
                     .filter(Boolean).join(',');
    var opener   = document.activeElement;
    var picked   = {};                     // id → {id, name, size} — zostaje przy zmianie folderu
    var path     = [{ id: '', name: 'Mój OneDrive' }];
    var reqNo    = 0;

    var dlg   = el('dialog', { class: 'odp', 'aria-labelledby': 'odp-title' });
    var inner = el('div', { class: 'odp-in' });
    var head  = el('div', { class: 'odp-head' });
    head.appendChild(el('h2', { id: 'odp-title' }, opts.title || 'Wybierz pliki z OneDrive'));
    var xBtn  = el('button', { type: 'button', class: 'odp-x', 'aria-label': 'Zamknij okno OneDrive' }, '×');
    head.appendChild(xBtn);

    var form  = el('form', { class: 'odp-search', role: 'search' });
    var q     = el('input', { type: 'search', 'aria-label': 'Szukaj w OneDrive', placeholder: 'Szukaj w OneDrive…' });
    form.appendChild(q);
    form.appendChild(el('button', { type: 'submit', class: 'odp-btn' }, 'Szukaj'));

    var crumbs = el('nav', { class: 'odp-crumbs', 'aria-label': 'Folder w OneDrive' });
    var status = el('div', { class: 'odp-status', role: 'status', 'aria-live': 'polite' });
    var list   = el('ul', { class: 'odp-list', 'aria-label': 'Pliki i foldery' });

    var foot   = el('div', { class: 'odp-foot' });
    var count  = el('span', { 'aria-live': 'polite' });
    var cancel = el('button', { type: 'button', class: 'odp-btn' }, 'Anuluj');
    var ok     = el('button', { type: 'button', class: 'odp-btn pri', disabled: '' }, multi ? 'Dodaj wybrane' : 'Dodaj plik');
    foot.appendChild(count); foot.appendChild(cancel); foot.appendChild(ok);

    inner.appendChild(head); inner.appendChild(form); inner.appendChild(crumbs);
    inner.appendChild(status); inner.appendChild(list); inner.appendChild(foot);
    dlg.appendChild(inner);
    document.body.appendChild(dlg);

    function close() {
      if (dlg.open) dlg.close();
    }
    dlg.addEventListener('close', function () {
      dlg.remove();
      if (opener && opener.focus) { try { opener.focus(); } catch (e) {} }
    });
    xBtn.addEventListener('click', close);
    cancel.addEventListener('click', close);
    ok.addEventListener('click', function () {
      var items = Object.keys(picked).map(function (k) { return picked[k]; });
      if (!items.length) return;
      close();
      if (typeof opts.onPick === 'function') opts.onPick(items);
    });

    function updateCount() {
      var n = Object.keys(picked).length;
      count.textContent = n ? 'Wybrano: ' + n + ' ' + plural(n, 'plik', 'pliki', 'plików') : '';
      ok.disabled = !n;
    }

    function setStatus(text, isErr) {
      status.textContent = text;
      status.className = 'odp-status' + (isErr ? ' err' : '');
    }

    function renderCrumbs(searchQ) {
      crumbs.textContent = '';
      var ol = el('ol');
      path.forEach(function (p, i) {
        var li = el('li');
        if (i === path.length - 1 && !searchQ) {
          li.appendChild(el('span', { 'aria-current': 'location' }, p.name));
        } else {
          var b = el('button', { type: 'button' }, p.name);
          b.addEventListener('click', function () { path = path.slice(0, i + 1); q.value = ''; load(); });
          li.appendChild(b);
        }
        ol.appendChild(li);
      });
      if (searchQ) ol.appendChild(el('li', null, 'Wyniki: „' + searchQ + '”'));
      crumbs.appendChild(ol);
    }

    function render(items) {
      list.textContent = '';
      items.forEach(function (it) {
        var li = el('li');
        if (it.folder) {
          var b = el('button', { type: 'button', class: 'odp-row' });
          b.appendChild(el('span', { class: 'odp-ic', 'aria-hidden': 'true' }, '📁'));
          b.appendChild(el('span', { class: 'odp-nm' }, it.name));
          b.appendChild(el('span', { class: 'odp-meta' },
            'folder' + (it.count ? ', ' + it.count + ' ' + plural(it.count, 'element', 'elementy', 'elementów') : '')));
          b.addEventListener('click', function () {
            if (q.value.trim()) { path = [path[0]]; q.value = ''; }
            path.push({ id: it.id, name: it.name });
            load();
          });
          li.appendChild(b);
        } else {
          var tooBig = maxBytes && it.size > maxBytes;
          var lab = el('label', { class: 'odp-row' + (tooBig ? ' off' : '') });
          var cb  = el('input', { type: 'checkbox', value: it.id });
          if (tooBig) cb.disabled = true;
          if (picked[it.id]) cb.checked = true;
          cb.addEventListener('change', function () {
            if (cb.checked) {
              if (!multi) {
                picked = {};
                list.querySelectorAll('input[type=checkbox]').forEach(function (o) { if (o !== cb) o.checked = false; });
              }
              picked[it.id] = { id: it.id, name: it.name, size: it.size };
            } else {
              delete picked[it.id];
            }
            updateCount();
          });
          lab.appendChild(cb);
          lab.appendChild(el('span', { class: 'odp-ic', 'aria-hidden': 'true' }, ICONS[it.ext] || '📄'));
          lab.appendChild(el('span', { class: 'odp-nm' }, it.name));
          lab.appendChild(el('span', { class: 'odp-meta' },
            fmtSize(it.size) + (it.modified ? ', ' + it.modified : '') + (tooBig ? ' — za duży' : '')));
          li.appendChild(lab);
        }
        list.appendChild(li);
      });
    }

    function load() {
      var searchQ = q.value.trim();
      var my = ++reqNo;
      renderCrumbs(searchQ);
      list.textContent = '';
      setStatus('Wczytywanie…');
      var fd = new FormData();
      fd.append('_csrf', opts.csrf || '');
      fd.append('folder', searchQ ? '' : path[path.length - 1].id);
      fd.append('q', searchQ);
      fd.append('ext', accept);
      fetch(opts.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.text().then(function (t) {
            try { return JSON.parse(t); } catch (e) { return { ok: false, error: t ? t.slice(0, 200) : 'HTTP ' + r.status }; }
          });
        })
        .then(function (res) {
          if (my !== reqNo || !dlg.open) return;
          if (!res.ok) { setStatus(res.error || 'Błąd OneDrive.', true); return; }
          render(res.items || []);
          var n = (res.items || []).length;
          setStatus(n
            ? (searchQ ? 'Znaleziono: ' : 'W folderze: ') + n + ' ' + plural(n, 'element', 'elementy', 'elementów')
            : (searchQ ? 'Nic nie znaleziono.' : 'Folder jest pusty' + (accept ? ' albo nie ma w nim plików dozwolonego typu.' : '.')));
        })
        .catch(function () {
          if (my === reqNo) setStatus('Błąd połączenia z serwerem.', true);
        });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); load(); });

    dlg.showModal();
    q.focus();
    updateCount();
    load();
  }

  window.OneDrivePicker = { open: open };
})();
