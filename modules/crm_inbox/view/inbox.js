/* modules/crm_inbox/view/inbox.js — zachowanie Skrzynki CRM (crm/inbox.php).
 *
 * Konfiguracja przychodzi z window.IB (wypisuje ją strona). Wszystko, co dotyczy
 * otwartej wiadomości, siedzi w ibInitPane() — panel bywa podmieniany bez
 * przeładowania strony, a skrypty z dociągniętego HTML-a się nie wykonują,
 * więc dane bierzemy z atrybutów data-*, nie ze zmiennych wstawionych przez PHP. */
(function () {
  'use strict';
  var IB = window.IB || {};
  var wrap = document.getElementById('ibWrap');
  if (!wrap) return;

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  IB.esc = esc; IB.store = store;

  /* ── Wysokość kolumn: lista i wiadomość mieszczą się w oknie ─────────────
     Każda kolumna przewija się sama; strona przewija się dopiero wtedy, gdy
     okno jest niższe niż rozsądne minimum. */
  var shell = document.getElementById('ibShell');
  function fitHeight() {
    if (window.matchMedia('(max-width:1099.98px)').matches) return;
    var top = wrap.getBoundingClientRect().top + window.scrollY;
    var h = Math.max(window.innerHeight - top - 12, 420);
    shell.style.setProperty('--ib-avail', h + 'px');
  }
  fitHeight();
  window.addEventListener('resize', fitHeight);

  /* ── Regulowana szerokość listy ──────────────────────────────────────── */
  var rz = document.getElementById('ibResizer');
  var MIN_W = 300, MAX_W = 900, DEF_W = 440;
  function listW() {
    var v = parseInt(getComputedStyle(wrap).getPropertyValue('--ib-list-w'), 10);
    return isNaN(v) ? DEF_W : v;
  }
  function setW(w, save) {
    var max = Math.min(MAX_W, wrap.clientWidth - 420);
    w = Math.round(Math.max(MIN_W, Math.min(max, w)));
    wrap.style.setProperty('--ib-list-w', w + 'px');
    if (rz) rz.setAttribute('aria-valuenow', w);
    if (save) store('crmInboxListW', String(w));
  }
  if (rz) {
    rz.setAttribute('aria-valuenow', listW());
    rz.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      rz.setPointerCapture(e.pointerId);
      rz.classList.add('is-drag'); document.body.classList.add('ib-resizing');
      var x0 = e.clientX, w0 = listW();
      function move(ev) { setW(w0 + ev.clientX - x0, false); }
      function up() {
        rz.removeEventListener('pointermove', move); rz.removeEventListener('pointerup', up); rz.removeEventListener('pointercancel', up);
        rz.classList.remove('is-drag'); document.body.classList.remove('ib-resizing');
        setW(listW(), true);
      }
      rz.addEventListener('pointermove', move); rz.addEventListener('pointerup', up); rz.addEventListener('pointercancel', up);
    });
    rz.addEventListener('keydown', function (e) {
      var w = listW(), step = e.shiftKey ? 80 : 24;
      if (e.key === 'ArrowLeft') setW(w - step, true);
      else if (e.key === 'ArrowRight') setW(w + step, true);
      else if (e.key === 'Home') setW(MIN_W, true);
      else if (e.key === 'End') setW(MAX_W, true);
      else return;
      e.preventDefault();
    });
    rz.addEventListener('dblclick', function () { setW(DEF_W, true); });
  }

  /* ── Lista zwarta (bez podglądu treści) ──────────────────────────────── */
  var cBtn = document.getElementById('ibCompactBtn');
  function paintCompact() {
    if (!cBtn) return;
    var on = wrap.classList.contains('is-compact');
    cBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    cBtn.querySelector('.bi').className = 'bi bi-' + (on ? 'check-square-fill' : 'square');
  }
  if (cBtn) {
    cBtn.addEventListener('click', function () {
      wrap.classList.toggle('is-compact');
      store('crmInboxCompact', wrap.classList.contains('is-compact') ? '1' : '0');
      paintCompact();
    });
    paintCompact();
  }

  /* ── Treść wiadomości w ramce ─────────────────────────────────────────────
     Ramka ma wysokość treści — przewija się panel, a nie ramka w panelu.
     allow-same-origin (bez allow-scripts) pozwala nam zmierzyć dokument, a
     treść i tak nie może niczego uruchomić. */
  function fitFrame(fr) {
    var d;
    try { d = fr.contentDocument; } catch (e) { return; }
    if (!d || !d.documentElement) return;
    var measure = function () {
      var h = Math.max(d.documentElement.scrollHeight, d.body ? d.body.scrollHeight : 0);
      fr.style.height = Math.max(h, 120) + 'px';
    };
    measure();
    if (window.ResizeObserver && !fr._ibRO) {
      fr._ibRO = new ResizeObserver(measure);
      fr._ibRO.observe(d.documentElement);
    }
    // Klawisze naciśnięte w ramce nie wychodzą z niej same — przekazujemy je
    // stronie, żeby skróty działały także po kliknięciu w treść.
    if (!d._ibKeys) {
      d._ibKeys = true;
      d.addEventListener('keydown', function (e) {
        document.dispatchEvent(new CustomEvent('ib:frame-key', { detail: e }));
      });
    }
  }
  document.addEventListener('load', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('ib-frame')) {
      e.target._ibRO = null;
      fitFrame(e.target);
    }
  }, true);

  function showImages(fr) {
    if (!fr || fr.dataset.imgs === '1') return;
    fr.dataset.imgs = '1';
    fr.srcdoc = fr.getAttribute('srcdoc').replace(/CRM_IMG_SRC/g, 'https: http:');
    var bar = document.querySelector('[data-ib-imgbar]');
    if (bar) bar.hidden = true;
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ib-imgs]')) showImages(document.getElementById('ibFrame'));
  });
  document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-ib-imgs-always]')) return;
    store('crmInboxImgs', e.target.checked ? '1' : '0');
    if (e.target.checked) showImages(document.getElementById('ibFrame'));
  });

  /* ── Panel otwartej wiadomości: wszystko, co trzeba podpiąć po podmianie ── */
  function wireContactSearch(nameId, hiddenId, boxId) {
    var input = document.getElementById(nameId), box = document.getElementById(boxId), hid = document.getElementById(hiddenId);
    if (!input || !box || !hid) return;
    var timer = null;
    input.addEventListener('input', function () {
      hid.value = '';
      clearTimeout(timer);
      var q = this.value.trim();
      if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
      timer = setTimeout(function () {
        fetch(IB.contacts + '?q=' + encodeURIComponent(q) + '&limit=8', { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.length) { box.style.display = 'none'; return; }
            box.innerHTML = data.map(function (c) {
              return '<button type="button" class="list-group-item list-group-item-action py-1 px-2 text-start" style="font-size:.8rem" data-id="' + c.id + '" data-name="' + esc(c.name) + '">' +
                '<strong>' + esc(c.name) + '</strong>' + (c.organizacja ? '<span class="text-muted ms-1">' + esc(c.organizacja) + '</span>' : '') +
                '</button>';
            }).join('');
            box.style.display = '';
            box.querySelectorAll('button').forEach(function (b) {
              b.addEventListener('click', function () { input.value = this.dataset.name; hid.value = this.dataset.id; box.style.display = 'none'; });
            });
          }).catch(function () {});
      }, 280);
    });
    document.addEventListener('click', function (e) { if (!input.contains(e.target)) box.style.display = 'none'; });
  }

  function initAssistant() {
    var btn = document.getElementById('ibAssistBtn');
    if (!btn) return;
    var box = document.getElementById('ibAssist'), body = document.getElementById('ibAssistBody'), close = document.getElementById('ibAssistClose');
    var CONTACT = parseInt(btn.dataset.contact, 10) || 0, SUBJ = btn.dataset.subject || '';
    var cache = null;
    if (close) close.addEventListener('click', function () { box.hidden = true; });

    function render(d) {
      if (!d.ok) {
        body.innerHTML = '<div class="ib-assist-body"><div class="alert alert-warning py-2 mb-0 small">' + esc(d.error || 'Asystent niedostępny.') + '</div></div>';
        return;
      }
      var ic = d.intents || {}, html = '<div class="ib-assist-body"><div class="ib-assist-row">'
        + '<span class="ib-assist-chip" style="background:' + esc(ic.color || '#5f5f5f') + '18;color:' + esc(ic.color || '#5f5f5f') + '"><i class="bi ' + esc(ic.icon || 'bi-chat-dots') + '"></i>' + esc(ic.label || d.intent) + '</span>';
      if (d.escalate) html += '<span class="ib-assist-chip" style="background:#fbe5e9;color:#a20025"><i class="bi bi-person-raised-hand"></i>do człowieka</span>';
      if (d.sensitive) html += '<span class="ib-assist-chip" style="background:#fdf0dc;color:#7a3e00"><i class="bi bi-shield-exclamation"></i>dane wrażliwe w treści</span>';
      html += '</div>';
      if (d.summary) html += '<div style="font-size:.86rem">' + esc(d.summary) + '</div>';
      if (d.escalate && d.escalate_reason) html += '<div class="text-danger mt-1" style="font-size:.8rem">' + esc(d.escalate_reason) + '</div>';
      if (d.missing && d.missing.length) {
        html += '<div class="ib-assist-lbl">Brakuje, żeby załatwić sprawę</div><ul class="mb-0 ps-3" style="font-size:.84rem">';
        d.missing.forEach(function (m) { html += '<li>' + esc(m) + '</li>'; });
        html += '</ul>';
      }
      if (d.reply) {
        html += '<div class="ib-assist-lbl">Propozycja odpowiedzi</div><div class="ib-assist-draft">' + esc(d.reply) + '</div><div class="d-flex flex-wrap gap-1 mt-2">';
        if (CONTACT) html += '<button type="button" class="ib-act" data-ib-assist-use><i class="bi bi-reply-fill" aria-hidden="true"></i>Użyj tej odpowiedzi</button>';
        html += '<button type="button" class="ib-act" data-ib-assist-copy><i class="bi bi-clipboard" aria-hidden="true"></i>Kopiuj</button></div>';
      }
      body.innerHTML = html + '</div>';

      var use = body.querySelector('[data-ib-assist-use]');
      if (use) use.addEventListener('click', function () {
        // Kompozytor ładuje się asynchronicznie — wklejamy, gdy edytor już jest.
        window.openCommModal(CONTACT, 'email');
        var tries = 0, timer = setInterval(function () {
          var subj = document.getElementById('cm-subject');
          if (subj && window.CM) {
            clearInterval(timer);
            if (!subj.value) subj.value = 'Re: ' + SUBJ;
            var plain = document.getElementById('cm-plain');
            if (plain) { window.CM.setMode('plain'); plain.value = d.reply; window.CM.updateChar(); }
          } else if (++tries > 40) { clearInterval(timer); }
        }, 150);
      });
      var copy = body.querySelector('[data-ib-assist-copy]');
      if (copy) copy.addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(d.reply);
        copy.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i>Skopiowane';
      });
    }

    btn.addEventListener('click', function () {
      box.hidden = false;
      if (cache) { render(cache); return; }   // druga analiza tej samej wiadomości nic nie wnosi
      body.innerHTML = '<div class="ib-assist-body text-muted small"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Czytam wiadomość…</div>';
      fetch(IB.assistant, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ _csrf: IB.csrf, id: parseInt(btn.dataset.msg, 10) })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { cache = d; render(d); })
        .catch(function () {
          body.innerHTML = '<div class="ib-assist-body"><div class="alert alert-danger py-2 mb-0 small">Błąd połączenia z asystentem.</div></div>';
        });
    });
  }

  function initPane() {
    // Pasek akcji bywa przycinany przez przewijany panel — Popper ze strategią
    // 'fixed' wyprowadza menu poza niego.
    if (window.bootstrap) document.querySelectorAll('#ibMsgCol [data-ib-dd]').forEach(function (el) {
      window.bootstrap.Dropdown.getOrCreateInstance(el, {
        popperConfig: function (cfg) { return Object.assign({}, cfg, { strategy: 'fixed' }); }
      });
    });
    // Kursor w pierwszym polu po otwarciu okna akcji
    ['mdCase', 'mdEzd', 'mdFwd'].forEach(function (id) {
      var m = document.getElementById(id);
      if (!m) return;
      m.addEventListener('shown.bs.modal', function () {
        var f = m.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
        if (f) f.focus();
      });
    });
    wireContactSearch('caseContactName', 'caseContactId', 'caseContactSuggestions');
    wireContactSearch('ezdStronaName', 'ezdStronaCrmId', 'ezdStronaSuggestions');
    initAssistant();

    var fr = document.getElementById('ibFrame');
    if (fr) {
      if (store('crmInboxImgs') === '1' && document.querySelector('[data-ib-imgbar]')) {
        var al = document.querySelector('[data-ib-imgs-always]');
        if (al) al.checked = true;
        showImages(fr);
      } else if (fr.contentDocument && fr.contentDocument.readyState === 'complete') {
        fitFrame(fr);
      }
    }
  }
  IB.initPane = initPane;
  initPane();

  /* ── Odpowiedz / Odpowiedz szablonem — delegacja zamiast onclick z JSON-em ── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-reply]');
    if (!b || typeof window.openCommModal !== 'function') return;
    e.preventDefault();
    var opts = { subject: b.dataset.subject || '' };
    if (b.dataset.tpl) opts.tpl = parseInt(b.dataset.tpl, 10) || 0;
    window.openCommModal(parseInt(b.dataset.contact, 10) || 0, 'email', opts);
  });

  /* ── Masowe działania — checkboxy są poza <form> (form="ibBulkForm") ───── */
  function bulkInit() {
    var bar = document.getElementById('ibBulkBar');
    if (!bar) return;
    var all = document.getElementById('ibCheckAll'), btn = document.getElementById('ibBulkBtn'),
        rdBtn = document.getElementById('ibBulkReadBtn'), opFld = document.getElementById('ibBulkOp'),
        out = document.getElementById('ibBulkN'), info = document.getElementById('ibBulkInfo');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.ib-check'));
    function refresh() {
      var n = boxes.filter(function (b) { return b.checked; }).length;
      out.textContent = n; info.hidden = n === 0;
      btn.disabled = n === 0; if (rdBtn) rdBtn.disabled = n === 0;
      bar.classList.toggle('is-armed', n > 0);
      boxes.forEach(function (b) { b.closest('.ib-row').classList.toggle('is-checked', b.checked); });
      all.checked = n > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
    }
    if (!all.dataset.bound) {
      all.dataset.bound = '1';
      all.addEventListener('change', function () {
        document.querySelectorAll('.ib-check').forEach(function (b) { b.checked = all.checked; });
        bulkInit();
      });
      // Jeden formularz, dwie operacje — przycisk ustawia _op tuż przed wysłaniem
      if (rdBtn) rdBtn.addEventListener('click', function () { opFld.value = 'bulk_read'; });
      btn.addEventListener('click', function () { opFld.value = IB.view === 'hidden' ? 'bulk_unhide' : 'bulk_hide'; });
    }
    boxes.forEach(function (b) { if (!b.dataset.bound) { b.dataset.bound = '1'; b.addEventListener('change', refresh); } });
    refresh();
  }
  IB.bulkInit = bulkInit;
  bulkInit();
  document.addEventListener('ib:list-refreshed', bulkInit);

  /* ── Odświeżanie listy + sygnał przy nowej wiadomości ─────────────────────
     Lekki endpoint mówi, czy coś przyszło; pełny HTML listy dociągamy dopiero
     wtedy. Otwarta wiadomość zostaje nietknięta. */
  var listWrap = document.getElementById('ibListWrap');
  var bar = document.getElementById('ibNewBar'), barTxt = document.getElementById('ibNewTxt');
  var lastId = IB.lastId || 0, busy = false;
  var soundOn = store('crmInboxSound') !== '0', actx = null;

  function ding() {
    if (!soundOn) return;
    try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      [880, 1175].forEach(function (freq, i) {
        var o = actx.createOscillator(), g = actx.createGain(), t = actx.currentTime + i * 0.13;
        o.type = 'sine'; o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.16, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
        o.connect(g); g.connect(actx.destination);
        o.start(t); o.stop(t + 0.24);
      });
    } catch (e) {}
  }
  var sBtn = document.getElementById('ibSoundBtn');
  function paintSound() {
    if (!sBtn) return;
    sBtn.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
    document.getElementById('ibSoundIco').className = soundOn ? 'bi bi-bell-fill' : 'bi bi-bell-slash';
    document.getElementById('ibSoundTxt').textContent = soundOn ? 'Sygnał przy nowej wiadomości: włączony' : 'Sygnał przy nowej wiadomości: wyciszony';
  }
  if (sBtn) {
    sBtn.addEventListener('click', function () {
      soundOn = !soundOn;
      store('crmInboxSound', soundOn ? '1' : '0');
      paintSound();
      if (soundOn) ding();
    });
    paintSound();
  }

  function paintCounts(counts) {
    if (!counts) return;
    document.querySelectorAll('[data-ib-cnt]').forEach(function (el) {
      var n = parseInt(counts[el.dataset.ibCnt], 10);
      if (isNaN(n)) return;
      el.textContent = n;
      el.classList.toggle('is-zero', n === 0);
    });
  }

  /** Podmienia listę świeżym HTML-em tej samej strony (zachowuje przewinięcie). */
  function refreshList(then) {
    if (busy || !listWrap) return;
    busy = true;
    fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('ibListWrap');
        var list = document.getElementById('ibList'), st = list ? list.scrollTop : 0;
        if (fresh) listWrap.innerHTML = fresh.innerHTML;
        var nl = document.getElementById('ibList');
        if (nl) nl.scrollTop = st;
        var cnt = {};
        doc.querySelectorAll('[data-ib-cnt]').forEach(function (el) { cnt[el.dataset.ibCnt] = el.textContent; });
        paintCounts(cnt);
        busy = false;
        document.dispatchEvent(new CustomEvent('ib:list-refreshed', { detail: { doc: doc } }));
        if (typeof then === 'function') then(doc);
      })
      .catch(function () { busy = false; });
  }
  IB.refreshList = refreshList;

  function poll() {
    var p = IB.params || {};
    var qs = Object.keys(p).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(p[k] || ''); }).join('&');
    fetch(IB.poll + '?' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        paintCounts(d.counts);
        if (!d.max_id) return;
        if (lastId && d.max_id > lastId) {
          lastId = d.max_id;
          ding();
          if (document.querySelector('.ib-check:checked')) {
            // Ktoś zaznacza wiadomości — nie wyrywamy mu listy spod kursora
            barTxt.textContent = 'Przyszły nowe wiadomości';
            bar.hidden = false; bar.classList.add('is-on');
          } else {
            refreshList();
          }
        } else if (!lastId) {
          lastId = d.max_id;
        }
      })
      .catch(function () {});
  }
  if (bar) bar.addEventListener('click', function () { bar.hidden = true; bar.classList.remove('is-on'); refreshList(); });
  var pollTimer = setInterval(function () { if (!document.hidden) poll(); }, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  window.addEventListener('beforeunload', function () { clearInterval(pollTimer); });

  /* ── Podgląd załączników ──────────────────────────────────────────────────
     Obraz i PDF na miejscu, tekst jako tekst (nie HTML — to treść z zewnątrz),
     reszta: uczciwe „tego nie pokażemy” i pobieranie. */
  (function () {
    var modalEl = document.getElementById('ibAttModal');
    if (!modalEl) return;
    var body = document.getElementById('ibAttBody'), title = document.getElementById('ibAttTitle'),
        dl = document.getElementById('ibAttDownload'), open = document.getElementById('ibAttOpen');
    function show(url, kind, name) {
      title.textContent = name;
      dl.href = url + '&dl=1';
      open.href = url;
      body.innerHTML = '<div class="text-center text-muted py-5 att-wait"><div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>'
                     + '<div class="mt-2 small">Wczytuję załącznik…</div></div>';
      if (kind === 'image' || kind === 'pdf') {
        var node = document.createElement(kind === 'image' ? 'img' : 'iframe');
        node.style.cssText = kind === 'image' ? 'max-width:100%;max-height:72vh;display:none;margin:0 auto' : 'width:100%;height:72vh;border:0;display:none';
        if (kind === 'image') node.alt = name; else node.title = name;
        node.addEventListener('load', function () { var sp = body.querySelector('.att-wait'); if (sp) sp.remove(); node.style.display = 'block'; });
        node.addEventListener('error', function () { body.innerHTML = '<div class="alert alert-warning mb-0">Nie udało się wczytać załącznika. Spróbuj go pobrać.</div>'; });
        node.src = url;
        body.appendChild(node);
      } else if (kind === 'text') {
        fetch(url, { credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (t) { body.innerHTML = '<pre style="max-height:72vh;overflow:auto;white-space:pre-wrap;font-size:.82rem;margin:0">' + esc(t.slice(0, 200000)) + '</pre>'; })
          .catch(function () { body.innerHTML = '<div class="alert alert-warning mb-0">Nie udało się wczytać pliku.</div>'; });
      } else {
        body.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-file-earmark" style="font-size:2rem" aria-hidden="true"></i>'
                       + '<div class="mt-2">Tego typu pliku nie pokażemy w przeglądarce.</div><div class="small">Pobierz go albo otwórz w nowej karcie.</div></div>';
      }
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.ib-att[data-att-url]');
      if (btn) show(btn.dataset.attUrl, btn.dataset.attKind, btn.dataset.attName || 'załącznik');
    });
    // Zwolnij ramkę po zamknięciu — inaczej PDF zostaje wczytany w tle.
    modalEl.addEventListener('hidden.bs.modal', function () { body.innerHTML = ''; });
  })();
})();
