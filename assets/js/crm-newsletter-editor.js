/**
 * assets/js/crm-newsletter-editor.js — logika edytora newsletterów CRM.
 *
 * Stan trzyma dokument bloków (ten sam kształt, który zapisuje się w
 * crm_campaigns.design_json). Wygląd renderuje WYŁĄCZNIE serwer — po każdej
 * zmianie wysyłamy dokument do crm/campaign/api.php?_action=render i wstawiamy
 * zwrócony HTML do iframe. Nie ma tu drugiego renderera bloków, bo dwa
 * renderery zawsze się w końcu rozjeżdżają i podgląd zaczyna kłamać.
 *
 * Przeciąganie: kafelki palety żyją w dokumencie nadrzędnym, a kanwa to iframe
 * (ten sam origin), więc listenery dragover/drop zakładamy wewnątrz jego
 * dokumentu. Payload jedzie w text/plain jako "new:<typ>" albo "move:<id>".
 * Kolejność bloków w DOM odpowiada kolejności w tablicy, więc indeks upuszczenia
 * wyliczamy z pozycji elementu pod kursorem.
 */
(function () {
  'use strict';

  var B = window.CEM_BOOT;
  if (!B) return;

  var design     = B.design && B.design.blocks ? B.design : { version: 2, settings: {}, blocks: [] };
  var selectedId = null;
  var showGlobal = false;
  var personalize = false;
  var past = [], future = [];
  var quill = null;

  var $  = function (s) { return document.querySelector(s); };
  var el = function (tag, cls, txt) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt != null) n.textContent = txt;
    return n;
  };
  var uid = function () { return 'b' + Math.random().toString(36).slice(2, 10); };
  var clone = function (o) { return JSON.parse(JSON.stringify(o)); };

  // ── Komunikacja z API ─────────────────────────────────────────────────────

  function post(action, extra) {
    var fd = new FormData();
    fd.append('_csrf', B.csrf);
    fd.append('_action', action);
    fd.append('id', B.id);
    Object.keys(extra || {}).forEach(function (k) {
      var v = extra[k];
      if (v === undefined || v === null) return;
      if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
      else fd.append(k, v);
    });
    return fetch(B.api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; },
                                               function ()  { return { ok: false, j: { error: 'Nieprawidłowa odpowiedź serwera.' } }; }); });
  }

  // ── Stan ──────────────────────────────────────────────────────────────────

  /** Każda zmiana dokumentu przechodzi tędy: historia, autosave, przerysowanie. */
  function commit(fn) {
    past.push(JSON.stringify(design));
    if (past.length > 60) past.shift();
    future.length = 0;
    fn();
    afterChange();
  }

  function afterChange() {
    renderBlockList();
    renderInspector();
    scheduleRender();
    scheduleSave();
  }

  function undo() {
    if (!past.length) return;
    future.push(JSON.stringify(design));
    design = JSON.parse(past.pop());
    afterChange();
  }
  function redo() {
    if (!future.length) return;
    past.push(JSON.stringify(design));
    design = JSON.parse(future.pop());
    afterChange();
  }

  function blockIndex(id) {
    for (var i = 0; i < design.blocks.length; i++) if (design.blocks[i].id === id) return i;
    return -1;
  }
  function selected() {
    var i = blockIndex(selectedId);
    return i < 0 ? null : design.blocks[i];
  }
  function isLocked(type) { return !!(B.defs[type] && B.defs[type].locked); }

  function insertBlock(type, index) {
    if (!B.defs[type]) return;
    var b = { id: uid(), type: type, props: clone(B.defs[type].defaults) };
    if (index == null || index < 0 || index > design.blocks.length) index = design.blocks.length;
    commit(function () { design.blocks.splice(index, 0, b); });
    selectedId = b.id; showGlobal = false;
    renderInspector(); renderBlockList();
  }

  function moveBlock(id, toIndex) {
    var from = blockIndex(id);
    if (from < 0) return;
    commit(function () {
      var b = design.blocks.splice(from, 1)[0];
      // Po wyjęciu elementu indeksy za nim przesuwają się o jeden — korygujemy cel.
      design.blocks.splice(from < toIndex ? toIndex - 1 : toIndex, 0, b);
    });
  }

  function swapBlock(id, delta) {
    var i = blockIndex(id), j = i + delta;
    if (i < 0 || j < 0 || j >= design.blocks.length) return;
    commit(function () {
      var t = design.blocks[i];
      design.blocks[i] = design.blocks[j];
      design.blocks[j] = t;
    });
  }

  function duplicateBlock(id) {
    var i = blockIndex(id);
    if (i < 0) return;
    var copy = clone(design.blocks[i]);
    copy.id = uid();
    commit(function () { design.blocks.splice(i + 1, 0, copy); });
    selectedId = copy.id;
    renderInspector(); renderBlockList();
  }

  function removeBlock(id) {
    var i = blockIndex(id);
    if (i < 0) return;
    // Stopka jest nieusuwalna — wysyłka masowa bez linku wypisania jest
    // niedopuszczalna, więc lepiej nie dać jej usunąć, niż wykrywać brak później.
    if (isLocked(design.blocks[i].type)) {
      toast('Stopki z linkiem wypisania nie można usunąć.');
      return;
    }
    commit(function () { design.blocks.splice(i, 1); });
    if (selectedId === id) { selectedId = null; renderInspector(); }
  }

  function setProp(id, key, value) {
    var i = blockIndex(id);
    if (i < 0) return;
    commit(function () { design.blocks[i].props[key] = value; });
  }

  function setSetting(key, value) {
    commit(function () { design.settings[key] = value; });
  }

  // ── Kanwa ─────────────────────────────────────────────────────────────────

  var frame = $('#canvas');
  var renderTimer = null, renderSeq = 0, savedScroll = 0;

  function scheduleRender() {
    clearTimeout(renderTimer);
    renderTimer = setTimeout(doRender, 220);
  }

  function doRender() {
    var seq = ++renderSeq;
    post('render', {
      design: JSON.stringify(design),
      subject: $('#cSubject').value,
      preheader: $('#cPre').value,
      editable: 1,
      personalize: personalize ? 1 : ''
    }).then(function (res) {
      if (seq !== renderSeq) return;                  // spóźniona odpowiedź
      if (!res.ok) { toast(res.j.error || 'Nie udało się wyrenderować podglądu.'); return; }
      try { savedScroll = frame.contentWindow ? frame.contentWindow.scrollY : 0; } catch (e) { savedScroll = 0; }
      frame.srcdoc = res.j.html;
      renderWarnings(res.j.warnings || []);
    });
  }

  frame.addEventListener('load', function () {
    var doc = frame.contentDocument;
    if (!doc || !doc.body) return;
    wireCanvas(doc);
    fitFrame(doc);
    try { frame.contentWindow.scrollTo(0, savedScroll); } catch (e) {}
  });

  /** Iframe rośnie do wysokości treści — przewija się kontener, nie ramka. */
  function fitFrame(doc) {
    var h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight, 400);
    frame.style.height = (h + 8) + 'px';
  }

  function domBlockIds(doc) {
    return Array.prototype.map.call(doc.querySelectorAll('[data-cem-block]'), function (n) {
      return n.getAttribute('data-cem-block');
    });
  }

  function clearDropMarks(doc) {
    Array.prototype.forEach.call(doc.querySelectorAll('.cem-drop-before,.cem-drop-after'), function (n) {
      n.classList.remove('cem-drop-before', 'cem-drop-after');
    });
  }

  function wireCanvas(doc) {
    var nodes = doc.querySelectorAll('[data-cem-block]');

    Array.prototype.forEach.call(nodes, function (node) {
      var id = node.getAttribute('data-cem-block');
      if (id === selectedId) node.classList.add('cem-sel');
      node.setAttribute('draggable', 'true');

      node.addEventListener('click', function (e) {
        e.stopPropagation();
        selectedId = id; showGlobal = false;
        Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('cem-sel'); });
        node.classList.add('cem-sel');
        renderInspector(); renderBlockList();
      });

      node.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', 'move:' + id);
        e.dataTransfer.effectAllowed = 'move';
      });
    });

    // Kliknięcie w tło odznacza blok i pokazuje styl globalny.
    doc.body.addEventListener('click', function () {
      selectedId = null; showGlobal = true;
      Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('cem-sel'); });
      renderInspector(); renderBlockList();
    });

    /** Element bloku pod kursorem + strona (przed/po) wg jego środka. */
    function hit(y) {
      var best = null;
      Array.prototype.forEach.call(nodes, function (n) {
        var r = n.getBoundingClientRect();
        if (y >= r.top && y <= r.bottom) best = { node: n, after: y > r.top + r.height / 2 };
      });
      if (best) return best;
      // Kursor poza blokami: nad pierwszym → na początek, pod ostatnim → na koniec.
      if (!nodes.length) return null;
      var first = nodes[0].getBoundingClientRect();
      if (y < first.top) return { node: nodes[0], after: false };
      return { node: nodes[nodes.length - 1], after: true };
    }

    doc.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      clearDropMarks(doc);
      var h = hit(e.clientY);
      if (h) h.node.classList.add(h.after ? 'cem-drop-after' : 'cem-drop-before');
    });

    doc.addEventListener('dragleave', function () { clearDropMarks(doc); });

    doc.addEventListener('drop', function (e) {
      e.preventDefault();
      clearDropMarks(doc);
      var payload = '';
      try { payload = e.dataTransfer.getData('text/plain') || ''; } catch (err) { return; }

      var ids = domBlockIds(doc);
      var h = hit(e.clientY);
      var index = ids.length;
      if (h) {
        index = ids.indexOf(h.node.getAttribute('data-cem-block'));
        if (index < 0) index = ids.length;
        else if (h.after) index += 1;
      }

      if (payload.indexOf('new:') === 0)       insertBlock(payload.slice(4), index);
      else if (payload.indexOf('move:') === 0) moveBlock(payload.slice(5), index);
    });
  }

  // ── Paleta ────────────────────────────────────────────────────────────────

  function renderPalette() {
    var box = $('#palette');
    box.innerHTML = '';
    var groups = {};
    Object.keys(B.defs).forEach(function (type) {
      var d = B.defs[type];
      (groups[d.group] = groups[d.group] || []).push({ type: type, def: d });
    });

    ['Treść', 'Media', 'Struktura'].forEach(function (g) {
      if (!groups[g]) return;
      var head = el('p', 'cem-lbl', g);
      head.style.marginTop = '.6rem';
      box.appendChild(head);
      var grid = el('div', 'cem-tiles');
      groups[g].forEach(function (item) {
        var t = el('button', 'cem-tile');
        t.type = 'button';
        t.draggable = true;
        t.title = 'Przeciągnij na kanwę lub naciśnij Enter';
        var ic = el('i', 'bi ' + (item.def.icon || 'bi-square'));
        ic.setAttribute('aria-hidden', 'true');
        t.appendChild(ic);
        t.appendChild(el('span', null, item.def.label));
        t.addEventListener('dragstart', function (e) {
          e.dataTransfer.setData('text/plain', 'new:' + item.type);
          e.dataTransfer.effectAllowed = 'copy';
        });
        t.addEventListener('click', function () { insertBlock(item.type, design.blocks.length); });
        grid.appendChild(t);
      });
      box.appendChild(grid);
    });
  }

  // ── Lista bloków (dostępna z klawiatury) ──────────────────────────────────

  function renderBlockList() {
    var box = $('#blockList');
    box.innerHTML = '';
    if (!design.blocks.length) {
      box.appendChild(el('p', 'text-muted', 'Brak bloków — dodaj pierwszy z palety powyżej.')).style.fontSize = '.72rem';
      return;
    }
    design.blocks.forEach(function (b, i) {
      var d = B.defs[b.type] || { label: b.type };
      var row = el('div', 'cem-row' + (b.id === selectedId ? ' sel' : ''));

      var nm = el('button', 'nm');
      nm.type = 'button';
      nm.textContent = (i + 1) + '. ' + d.label + summary(b);
      nm.addEventListener('click', function () {
        selectedId = b.id; showGlobal = false;
        renderInspector(); renderBlockList();
        scrollCanvasTo(b.id);
      });
      row.appendChild(nm);

      row.appendChild(iconBtn('bi-arrow-up', 'Przenieś w górę', i === 0, function () { swapBlock(b.id, -1); }));
      row.appendChild(iconBtn('bi-arrow-down', 'Przenieś w dół', i === design.blocks.length - 1, function () { swapBlock(b.id, 1); }));
      row.appendChild(iconBtn('bi-files', 'Duplikuj', false, function () { duplicateBlock(b.id); }));
      row.appendChild(iconBtn('bi-trash', 'Usuń', isLocked(b.type), function () { removeBlock(b.id); }));
      box.appendChild(row);
    });
  }

  function summary(b) {
    var p = b.props || {};
    var txt = p.text || p.label || p.org || (p.html ? String(p.html).replace(/<[^>]*>/g, ' ') : '');
    txt = String(txt || '').trim();
    return txt ? ' — ' + (txt.length > 22 ? txt.slice(0, 22) + '…' : txt) : '';
  }

  function iconBtn(icon, title, disabled, onClick) {
    var b = el('button');
    b.type = 'button';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.disabled = !!disabled;
    if (disabled) b.style.opacity = '.3';
    var i = el('i', 'bi ' + icon);
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    b.addEventListener('click', onClick);
    return b;
  }

  function scrollCanvasTo(id) {
    try {
      var node = frame.contentDocument.querySelector('[data-cem-block="' + id + '"]');
      if (node) node.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } catch (e) {}
  }

  // ── Inspektor ─────────────────────────────────────────────────────────────

  function renderInspector() {
    var box = $('#inspector');
    if (quill) { quill = null; }
    box.innerHTML = '';

    var b = selected();
    if (!b || showGlobal) { renderGlobalPanel(box); return; }

    var d = B.defs[b.type];
    box.appendChild(headline(d.label, b.type));

    (d.fields || []).forEach(function (f) {
      box.appendChild(fieldWidget(b, f));
    });

    if (b.type === 'text' || b.type === 'heading') box.appendChild(tokenHelp());
  }

  function headline(text, sub) {
    var wrap = el('div');
    wrap.style.marginBottom = '.7rem';
    var h = el('p', 'cem-lbl', text);
    h.style.margin = '0';
    wrap.appendChild(h);
    return wrap;
  }

  function renderGlobalPanel(box) {
    box.appendChild(headline('Styl globalny'));
    var s = design.settings || {};

    box.appendChild(fieldRow('Szerokość wiadomości (px)', numberInput(s.containerWidth || 600, 480, 720, function (v) {
      setSetting('containerWidth', v);
    }), 'Standard to 600 px — szerzej bywa obcinane w podglądzie Outlooka.'));

    box.appendChild(fieldRow('Krój pisma', selectInput(B.fonts.map(function (f) {
      return { value: f.value, label: f.label };
    }), s.fontFamily, function (v) { setSetting('fontFamily', v); }),
    'Tylko kroje dostępne w klientach poczty — własne fonty i tak nie doładują się w Outlooku.'));

    box.appendChild(fieldRow('Rozmiar tekstu (px)', numberInput(s.baseFontSize || 16, 12, 20, function (v) { setSetting('baseFontSize', v); })));
    box.appendChild(fieldRow('Zaokrąglenie narożników (px)', numberInput(s.borderRadius == null ? 8 : s.borderRadius, 0, 24, function (v) { setSetting('borderRadius', v); })));
    box.appendChild(fieldRow('Tło wiadomości', colorInput(s.backgroundColor, function (v) { setSetting('backgroundColor', v); })));
    box.appendChild(fieldRow('Tło treści', colorInput(s.contentBackground, function (v) { setSetting('contentBackground', v); })));
    box.appendChild(fieldRow('Kolor tekstu', colorInput(s.textColor, function (v) { setSetting('textColor', v); })));
    box.appendChild(fieldRow('Kolor nagłówków', colorInput(s.headingColor, function (v) { setSetting('headingColor', v); })));
    box.appendChild(fieldRow('Kolor linków', colorInput(s.linkColor, function (v) { setSetting('linkColor', v); })));
  }

  function fieldRow(label, control, hint) {
    var w = el('div', 'cem-f');
    var l = el('label', null, label);
    if (control.id) l.setAttribute('for', control.id);
    w.appendChild(l);
    w.appendChild(control);
    if (hint) w.appendChild(el('div', 'hint', hint));
    return w;
  }

  var seqId = 0;
  function nextId() { return 'cemf' + (++seqId); }

  function numberInput(value, min, max, onChange) {
    var i = el('input');
    i.type = 'number'; i.id = nextId();
    i.value = value; i.min = min; i.max = max;
    i.addEventListener('change', function () {
      var v = parseInt(i.value, 10);
      if (isNaN(v)) v = min;
      v = Math.max(min, Math.min(max, v));
      i.value = v;
      onChange(v);
    });
    return i;
  }

  function textInput(value, onChange, type) {
    var i = el('input');
    i.type = type || 'text'; i.id = nextId();
    i.value = value == null ? '' : value;
    var t = null;
    i.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { onChange(i.value); }, 260);
    });
    return i;
  }

  function textareaInput(value, onChange) {
    var i = el('textarea');
    i.id = nextId(); i.rows = 3;
    i.value = value == null ? '' : value;
    var t = null;
    i.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { onChange(i.value); }, 300);
    });
    return i;
  }

  function colorInput(value, onChange) {
    var i = el('input');
    i.type = 'color'; i.id = nextId();
    i.value = /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#000000';
    i.addEventListener('change', function () { onChange(i.value); });
    return i;
  }

  function selectInput(options, value, onChange) {
    var s = el('select');
    s.id = nextId();
    options.forEach(function (o) {
      var op = el('option', null, o.label);
      op.value = o.value;
      if (String(o.value) === String(value)) op.selected = true;
      s.appendChild(op);
    });
    s.addEventListener('change', function () { onChange(s.value); });
    return s;
  }

  function toggleInput(value, label, onChange) {
    var w = el('div', 'form-check form-switch');
    var i = el('input', 'form-check-input');
    i.type = 'checkbox'; i.id = nextId(); i.checked = !!value;
    var l = el('label', 'form-check-label', label);
    l.setAttribute('for', i.id);
    l.style.fontSize = '.76rem';
    i.addEventListener('change', function () { onChange(i.checked); });
    w.appendChild(i); w.appendChild(l);
    return w;
  }

  function fieldWidget(b, f) {
    var val = b.props[f.key];

    if (f.kind === 'toggle') {
      var w = el('div', 'cem-f');
      w.appendChild(toggleInput(val, f.label, function (v) { setProp(b.id, f.key, v); }));
      if (f.hint) w.appendChild(el('div', 'hint', f.hint));
      return w;
    }
    if (f.kind === 'number')   return fieldRow(f.label, numberInput(val || 0, f.min || 0, f.max || 999, function (v) { setProp(b.id, f.key, v); }), f.hint);
    if (f.kind === 'color')    return fieldRow(f.label, colorInput(val, function (v) { setProp(b.id, f.key, v); }), f.hint);
    if (f.kind === 'select')   return fieldRow(f.label, selectInput(f.options, val, function (v) { setProp(b.id, f.key, v); }), f.hint);
    if (f.kind === 'textarea') return fieldRow(f.label, textareaInput(val, function (v) { setProp(b.id, f.key, v); }), f.hint);
    if (f.kind === 'url')      return fieldRow(f.label, textInput(val, function (v) { setProp(b.id, f.key, v); }, 'url'), f.hint);
    if (f.kind === 'richtext') return richtextWidget(b, f);
    if (f.kind === 'image')    return imageWidget(b, f);
    if (f.kind === 'items')    return itemsWidget(b, f);
    if (f.kind === 'networks') return networksWidget(b, f);
    return fieldRow(f.label, textInput(val, function (v) { setProp(b.id, f.key, v); }), f.hint);
  }

  function richtextWidget(b, f) {
    var w = el('div', 'cem-f');
    w.appendChild(el('label', null, f.label));
    var host = el('div');
    host.style.background = '#fff';
    w.appendChild(host);

    // Quill jest już używany w CRM (header_crm.php) — nie wprowadzamy drugiego
    // edytora tekstu. Bez Quilla schodzimy na textarea, żeby pole zawsze działało.
    if (typeof Quill === 'undefined') {
      w.removeChild(host);
      w.appendChild(textareaInput(b.props[f.key], function (v) { setProp(b.id, f.key, v); }));
      return w;
    }

    quill = new Quill(host, {
      theme: 'snow',
      modules: { toolbar: [['bold', 'italic', 'underline'], [{ list: 'bullet' }, { list: 'ordered' }], ['link'], ['clean']] }
    });
    quill.root.innerHTML = b.props[f.key] || '';

    var t = null, id = b.id, key = f.key;
    quill.on('text-change', function () {
      clearTimeout(t);
      t = setTimeout(function () { setProp(id, key, quill.root.innerHTML); }, 350);
    });
    return w;
  }

  function imageWidget(b, f) {
    var w = el('div', 'cem-f');
    w.appendChild(el('label', null, f.label));

    var url = textInput(b.props[f.key], function (v) { setProp(b.id, f.key, v); }, 'url');
    url.placeholder = 'https://… lub wgraj plik';
    w.appendChild(url);

    var bar = el('div', 'd-flex gap-1 mt-1');
    var file = el('input');
    file.type = 'file'; file.accept = 'image/png,image/jpeg,image/gif'; file.hidden = true;

    var up = el('button', 'btn btn-sm btn-light border flex-fill');
    up.type = 'button';
    up.textContent = 'Wgraj plik';
    up.addEventListener('click', function () { file.click(); });

    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) return;
      up.disabled = true; up.textContent = 'Wgrywam…';
      var fd = new FormData();
      fd.append('files[]', file.files[0]);
      fetch(B.upload, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var f0 = (j.files || [])[0];
          if (!f0) { toast('Nie udało się wgrać pliku (dozwolone PNG/JPG/GIF do 5 MB).'); return; }
          url.value = f0.url;
          setProp(b.id, f.key, f0.url);
        })
        .catch(function () { toast('Nie udało się wgrać pliku.'); })
        .finally(function () { up.disabled = false; up.textContent = 'Wgraj plik'; });
    });

    bar.appendChild(up);
    bar.appendChild(file);
    w.appendChild(bar);
    if (f.hint) w.appendChild(el('div', 'hint', f.hint));
    return w;
  }

  function itemsWidget(b, f) {
    var w = el('div', 'cem-f');
    w.appendChild(el('label', null, f.label));
    var items = Array.isArray(b.props[f.key]) ? b.props[f.key] : [];

    items.forEach(function (it, idx) {
      var card = el('div', 'cem-item');
      var head = el('div', 'd-flex align-items-center mb-1');
      var t = el('strong', null, 'Pozycja ' + (idx + 1));
      t.style.fontSize = '.7rem';
      head.appendChild(t);
      var del = iconBtn('bi-trash', 'Usuń pozycję', false, function () {
        commit(function () { b.props[f.key].splice(idx, 1); });
      });
      del.className = 'ms-auto btn btn-sm btn-link text-danger p-0';
      head.appendChild(del);
      card.appendChild(head);

      [['title', 'Tytuł', 'text'], ['desc', 'Opis', 'text'], ['price', 'Cena / etykieta', 'text'],
       ['href', 'Link', 'url'], ['image', 'Obrazek (URL)', 'url']].forEach(function (spec) {
        var inp = textInput(it[spec[0]], function (v) {
          commit(function () { b.props[f.key][idx][spec[0]] = v; });
        }, spec[2]);
        inp.placeholder = spec[1];
        inp.setAttribute('aria-label', spec[1] + ' — pozycja ' + (idx + 1));
        inp.style.marginBottom = '.2rem';
        card.appendChild(inp);
      });
      w.appendChild(card);
    });

    var add = el('button', 'btn btn-sm btn-light border w-100');
    add.type = 'button';
    add.textContent = '+ Dodaj pozycję';
    add.addEventListener('click', function () {
      commit(function () {
        if (!Array.isArray(b.props[f.key])) b.props[f.key] = [];
        b.props[f.key].push({ image: '', title: 'Nowa pozycja', desc: '', price: '', href: 'https://' });
      });
    });
    w.appendChild(add);
    return w;
  }

  function networksWidget(b, f) {
    var w = el('div', 'cem-f');
    w.appendChild(el('label', null, f.label));
    var current = {};
    (b.props[f.key] || []).forEach(function (n) { current[n.name] = n.url; });

    Object.keys(B.social).forEach(function (code) {
      var inp = textInput(current[code] || '', function (v) {
        commit(function () {
          var list = [];
          Object.keys(B.social).forEach(function (c) {
            var val = (c === code) ? v : (current[c] || '');
            if (String(val).trim() !== '') list.push({ name: c, url: val });
          });
          current[code] = v;
          b.props[f.key] = list;
        });
      }, 'url');
      inp.placeholder = B.social[code];
      inp.setAttribute('aria-label', 'Adres profilu — ' + B.social[code]);
      inp.style.marginBottom = '.2rem';
      w.appendChild(inp);
    });
    w.appendChild(el('div', 'hint', 'Puste pole = ikona nie pojawi się w wiadomości.'));
    return w;
  }

  function tokenHelp() {
    var w = el('div', 'cem-f cem-tokens');
    w.appendChild(el('label', null, 'Zmienne personalizacji'));
    var box = el('div');
    Object.keys(B.tokens).forEach(function (tok) {
      var btn = el('button', 'btn btn-sm btn-outline-secondary');
      btn.type = 'button';
      btn.textContent = tok;
      btn.title = B.tokens[tok] + ' — kliknij, aby skopiować';
      btn.addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(tok);
        toast('Skopiowano ' + tok);
      });
      box.appendChild(btn);
    });
    w.appendChild(box);
    w.appendChild(el('div', 'hint', 'Wklej zmienną w treść — przy wysyłce podstawimy dane odbiorcy.'));
    return w;
  }

  function renderWarnings(list) {
    var box = $('#warnBox');
    box.innerHTML = '';
    list.forEach(function (msg) { box.appendChild(el('div', 'cem-warn', msg)); });
  }

  // ── Autosave ──────────────────────────────────────────────────────────────

  var saveTimer = null;
  function scheduleSave() {
    setStatus('Zapisywanie…', '#B45309');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(doSave, 900);
  }

  function doSave() {
    post('save', {
      design: JSON.stringify(design),
      name: $('#cName').value,
      subject: $('#cSubject').value,
      preheader: $('#cPre').value
    }).then(function (res) {
      if (res.ok && res.j.ok) setStatus('Zapisano ' + res.j.saved_at, '#047857');
      else setStatus(res.j.error || 'Błąd zapisu', '#B91C1C');
    }).catch(function () { setStatus('Brak połączenia', '#B91C1C'); });
  }

  function setStatus(text, color) {
    var s = $('#cStatus');
    s.textContent = text;
    s.style.color = color || '#64748B';
  }

  function toast(msg) {
    setStatus(msg, '#B45309');
    setTimeout(function () { setStatus('Zapisano', '#047857'); }, 2600);
  }

  // ── Pasek narzędzi ────────────────────────────────────────────────────────

  $('#bUndo').addEventListener('click', undo);
  $('#bRedo').addEventListener('click', redo);
  $('#bGlobal').addEventListener('click', function () {
    selectedId = null; showGlobal = true;
    renderInspector(); renderBlockList();
  });

  $('#bDesktop').addEventListener('click', function () { setDevice('desktop'); });
  $('#bMobile').addEventListener('click', function () { setDevice('mobile'); });
  function setDevice(d) {
    frame.classList.toggle('mobile', d === 'mobile');
    $('#bDesktop').className = d === 'desktop' ? 'btn btn-dark' : 'btn btn-light border';
    $('#bMobile').className  = d === 'mobile'  ? 'btn btn-dark' : 'btn btn-light border';
    $('#bDesktop').setAttribute('aria-pressed', String(d === 'desktop'));
    $('#bMobile').setAttribute('aria-pressed', String(d === 'mobile'));
  }

  $('#bPers').addEventListener('change', function () {
    personalize = this.checked;
    doRender();
  });

  ['cName', 'cSubject', 'cPre'].forEach(function (id) {
    var t = null;
    $('#' + id).addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { scheduleSave(); if (id !== 'cName') scheduleRender(); }, 300);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey)) return;
    var k = e.key.toLowerCase();
    if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
    else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
    else if (k === 's') { e.preventDefault(); clearTimeout(saveTimer); doSave(); }
  });

  window.addEventListener('beforeunload', function (e) {
    if ($('#cStatus').textContent.indexOf('Zapisywanie') === 0) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // Szablony
  Array.prototype.forEach.call(document.querySelectorAll('.cem-load-tpl'), function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Wczytać szablon? Obecna treść kampanii zostanie zastąpiona.')) return;
      post('load_template', { template_id: btn.getAttribute('data-tpl') }).then(function (res) {
        if (!res.ok) { toast(res.j.error || 'Nie udało się wczytać szablonu.'); return; }
        commit(function () { design = res.j.design; });
        if (res.j.subject && !$('#cSubject').value) $('#cSubject').value = res.j.subject;
        selectedId = null;
        afterChange();
      });
    });
  });

  $('#bTpl').addEventListener('click', function () {
    var name = prompt('Nazwa nowego szablonu:', $('#cName').value);
    if (!name) return;
    post('save_as_template', { design: JSON.stringify(design), template_name: name }).then(function (res) {
      toast(res.ok ? 'Szablon zapisany.' : (res.j.error || 'Nie udało się zapisać szablonu.'));
    });
  });

  // ── Test ──────────────────────────────────────────────────────────────────

  var testModal = new bootstrap.Modal($('#mTest'));
  $('#bTest').addEventListener('click', function () { $('#testMsg').innerHTML = ''; testModal.show(); });
  $('#bDoTest').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    post('test', {
      email: $('#testMail').value,
      design: JSON.stringify(design),
      subject: $('#cSubject').value,
      preheader: $('#cPre').value
    }).then(function (res) {
      $('#testMsg').innerHTML = res.ok
        ? '<div class="alert alert-success py-2 mb-0" style="font-size:.78rem">Wysłano wiadomość testową.</div>'
        : '<div class="alert alert-danger py-2 mb-0" style="font-size:.78rem">' + escapeHtml(res.j.error || 'Błąd wysyłki.') + '</div>';
    }).finally(function () { btn.disabled = false; });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── Odbiorcy i wysyłka ────────────────────────────────────────────────────

  var sendModal = new bootstrap.Modal($('#mSend'));
  var filter = B.segment.filter && B.segment.filter.rules ? B.segment.filter : { op: 'and', rules: [] };

  $('#bSend').addEventListener('click', function () {
    clearTimeout(saveTimer);
    doSave();
    $('#sendProblems').innerHTML = '';
    sendModal.show();
    refreshAudience();
  });

  $('#segType').value = B.segment.type || 'tags';
  setMulti($('#segTags'), B.segment.tags || []);
  setMulti($('#segGroups'), (B.segment.group_ids || []).map(String));
  if ($('#segPurpose')) $('#segPurpose').value = String(B.segment.purpose || 0);
  $('#filtGlue').value = filter.op || 'and';

  function setMulti(sel, values) {
    if (!sel) return;
    Array.prototype.forEach.call(sel.options, function (o) { o.selected = values.indexOf(o.value) >= 0; });
  }
  function getMulti(sel) {
    return sel ? Array.prototype.filter.call(sel.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) : [];
  }

  function syncPanes() {
    var t = $('#segType').value;
    Array.prototype.forEach.call(document.querySelectorAll('.seg-pane'), function (p) {
      p.hidden = p.getAttribute('data-pane') !== t;
    });
  }
  syncPanes();

  $('#segType').addEventListener('change', function () { syncPanes(); refreshAudience(); });
  $('#segTags').addEventListener('change', refreshAudience);
  $('#segGroups').addEventListener('change', refreshAudience);
  if ($('#segPurpose')) $('#segPurpose').addEventListener('change', refreshAudience);
  $('#filtGlue').addEventListener('change', function () { filter.op = this.value; refreshAudience(); });
  $('#bAddRule').addEventListener('click', function () {
    var first = Object.keys(B.segFields)[0];
    filter.rules.push({ field: first, op: Object.keys(B.segOps[B.segFields[first].kind])[0], value: '' });
    renderRules(); refreshAudience();
  });

  function renderRules() {
    var box = $('#filtRules');
    box.innerHTML = '';
    if (!filter.rules.length) {
      var p = el('p', 'text-muted mb-0', 'Brak warunków — segment obejmie wszystkie aktywne kontakty.');
      p.style.fontSize = '.76rem';
      box.appendChild(p);
      return;
    }

    filter.rules.forEach(function (rule, idx) {
      var row = el('div', 'd-flex gap-1 align-items-start mb-1');

      var fSel = el('select', 'form-select form-select-sm');
      fSel.style.maxWidth = '38%';
      Object.keys(B.segFields).forEach(function (k) {
        var o = el('option', null, B.segFields[k].label);
        o.value = k;
        if (k === rule.field) o.selected = true;
        fSel.appendChild(o);
      });
      fSel.setAttribute('aria-label', 'Pole warunku ' + (idx + 1));
      fSel.addEventListener('change', function () {
        rule.field = fSel.value;
        rule.op = Object.keys(B.segOps[B.segFields[rule.field].kind])[0];
        rule.value = '';
        renderRules(); refreshAudience();
      });
      row.appendChild(fSel);

      var kind = B.segFields[rule.field] ? B.segFields[rule.field].kind : 'text';
      var oSel = el('select', 'form-select form-select-sm');
      oSel.style.maxWidth = '32%';
      Object.keys(B.segOps[kind] || {}).forEach(function (op) {
        var o = el('option', null, B.segOps[kind][op]);
        o.value = op;
        if (op === rule.op) o.selected = true;
        oSel.appendChild(o);
      });
      oSel.setAttribute('aria-label', 'Operator warunku ' + (idx + 1));
      oSel.addEventListener('change', function () { rule.op = oSel.value; renderRules(); refreshAudience(); });
      row.appendChild(oSel);

      row.appendChild(valueControl(rule, kind, idx));

      var del = el('button', 'btn btn-sm btn-light border');
      del.type = 'button';
      del.innerHTML = '<i class="bi bi-x"></i>';
      del.setAttribute('aria-label', 'Usuń warunek ' + (idx + 1));
      del.addEventListener('click', function () { filter.rules.splice(idx, 1); renderRules(); refreshAudience(); });
      row.appendChild(del);

      box.appendChild(row);
    });
  }

  function valueControl(rule, kind, idx) {
    var noValue = ['empty', 'not_empty', 'bounced'];
    if (noValue.indexOf(rule.op) >= 0) {
      var span = el('span', 'text-muted flex-fill', '—');
      span.style.fontSize = '.76rem';
      return span;
    }

    var opts = (B.segFields[rule.field] && B.segFields[rule.field].options) || [];
    var multiKinds = ['enum', 'custom_enum', 'tag', 'group'];

    if (multiKinds.indexOf(kind) >= 0 && opts.length) {
      var sel = el('select', 'form-select form-select-sm flex-fill');
      sel.multiple = (kind !== 'consent');
      sel.size = 1;
      opts.forEach(function (o) {
        var op = el('option', null, o.label);
        op.value = o.value;
        if ([].concat(rule.value || []).map(String).indexOf(String(o.value)) >= 0) op.selected = true;
        sel.appendChild(op);
      });
      sel.setAttribute('aria-label', 'Wartość warunku ' + (idx + 1));
      sel.addEventListener('change', function () {
        rule.value = Array.prototype.filter.call(sel.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
        refreshAudience();
      });
      return sel;
    }

    if (kind === 'consent') {
      var cs = el('select', 'form-select form-select-sm flex-fill');
      opts.forEach(function (o) {
        var op = el('option', null, o.label);
        op.value = o.value;
        if (String(o.value) === String(rule.value)) op.selected = true;
        cs.appendChild(op);
      });
      cs.addEventListener('change', function () { rule.value = cs.value; refreshAudience(); });
      return cs;
    }

    var inp = el('input', 'form-control form-control-sm flex-fill');
    inp.type = (kind === 'days' || kind === 'event') ? 'number' : 'text';
    if (inp.type === 'number') { inp.min = 1; inp.max = 3650; }
    inp.value = rule.value == null ? '' : rule.value;
    inp.setAttribute('aria-label', 'Wartość warunku ' + (idx + 1));
    var t = null;
    inp.addEventListener('input', function () {
      rule.value = inp.value;
      clearTimeout(t);
      t = setTimeout(refreshAudience, 450);
    });
    return inp;
  }
  renderRules();

  var audTimer = null, audSeq = 0;
  function refreshAudience() {
    clearTimeout(audTimer);
    audTimer = setTimeout(function () {
      var seq = ++audSeq;
      $('#audBox').innerHTML = '<span class="text-muted">Sprawdzam liczbę odbiorców…</span>';
      post('audience', segmentPayload()).then(function (res) {
        if (seq !== audSeq) return;
        if (!res.ok) { $('#audBox').innerHTML = '<span class="text-danger">' + escapeHtml(res.j.error || 'Błąd') + '</span>'; return; }
        var h = '<strong>' + res.j.sendable + '</strong> odbiorców otrzyma tę wiadomość.';
        if (res.j.skipped > 0) {
          h += '<div class="mt-1 text-muted" style="font-size:.76rem">Pominiętych: ' + res.j.skipped + '<ul class="mb-0 ps-3">';
          (res.j.breakdown || []).forEach(function (b) { h += '<li>' + escapeHtml(b.label) + ': ' + b.n + '</li>'; });
          h += '</ul></div>';
        }
        if (res.j.description) h += '<div class="mt-1 text-muted" style="font-size:.72rem">Warunki: ' + escapeHtml(res.j.description) + '</div>';
        if (res.j.sendable === 0) h += '<div class="mt-1 text-danger" style="font-size:.76rem">Nie ma komu wysłać — popraw segment.</div>';
        $('#audBox').innerHTML = h;
      });
    }, 260);
  }

  function segmentPayload() {
    var t = $('#segType').value;
    var p = { segment_type: t, purpose_id: $('#segPurpose') ? $('#segPurpose').value : 0 };
    if (t === 'tags')   p.tags = getMulti($('#segTags'));
    if (t === 'groups') p.group_ids = getMulti($('#segGroups'));
    if (t === 'filter') p.filter = JSON.stringify(filter);
    return p;
  }

  var whenRadios = document.querySelectorAll('input[name=when]');
  Array.prototype.forEach.call(whenRadios, function (r) {
    r.addEventListener('change', function () {
      var sched = $('#wSched').checked;
      $('#schedAt').disabled = !sched;
      $('#bConfirmSend').textContent = sched ? 'Zaplanuj' : 'Wyślij';
    });
  });

  $('#bConfirmSend').addEventListener('click', function () {
    var btn = this;
    var sched = $('#wSched').checked;
    if (!confirm(sched ? 'Zaplanować wysyłkę na podany termin?' : 'Wysłać kampanię teraz? Tego nie da się cofnąć.')) return;

    btn.disabled = true;
    $('#sendProblems').innerHTML = '';
    var payload = segmentPayload();
    payload.design = JSON.stringify(design);
    payload.subject = $('#cSubject').value;
    payload.preheader = $('#cPre').value;
    payload.when = sched ? 'schedule' : 'now';
    payload.scheduled_at = $('#schedAt').value;

    post('schedule', payload).then(function (res) {
      if (res.ok && res.j.ok) {
        window.location.href = B.api.replace('/api.php', '/view.php') + '?id=' + B.id;
        return;
      }
      var msgs = res.j.problems || [res.j.error || 'Nie udało się rozpocząć wysyłki.'];
      $('#sendProblems').innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:.8rem"><ul class="mb-0 ps-3">'
        + msgs.map(function (m) { return '<li>' + escapeHtml(m) + '</li>'; }).join('') + '</ul></div>';
    }).finally(function () { btn.disabled = false; });
  });

  // ── Start ─────────────────────────────────────────────────────────────────

  renderPalette();
  renderBlockList();
  renderInspector();
  doRender();
  setStatus('Zapisano', '#047857');
})();
