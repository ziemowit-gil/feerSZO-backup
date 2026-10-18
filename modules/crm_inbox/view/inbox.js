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
  /* ══ Przeglądanie bez przeładowania ══════════════════════════════════════
     Klik w wiadomość dociąga tę samą stronę z ?msg=ID i podmienia tylko panel
     wiadomości (i okna akcji); lista, jej przewinięcie i zaznaczenia zostają.
     Adres w pasku się zmienia (history), więc „Wstecz” i odświeżenie działają.
     Gdy cokolwiek pójdzie nie tak — zwykłe przejście pod adres. */
  var live = document.getElementById('ibLive');
  function announce(t) { if (live) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); } }

  var toastEl = document.getElementById('ibToast'), toastTimer = null;
  function toast(msg, o) {
    if (!toastEl || !msg) return;
    o = o || {};
    clearTimeout(toastTimer);
    toastEl.className = 'ib-toast' + (o.err ? ' ib-toast--err' : '');
    toastEl.innerHTML = '<span>' + esc(msg) + '</span>';
    if (o.undo) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = 'Cofnij';
      b.addEventListener('click', function () { toastEl.hidden = true; o.undo(); });
      toastEl.appendChild(b);
    }
    toastEl.hidden = false;
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, o.undo ? 8000 : 4500);
  }
  IB.toast = toast;

  function rows() { return Array.prototype.slice.call(document.querySelectorAll('#ibList .ib-row')); }
  function currentId() { var p = document.getElementById('ibPane'); return p ? (parseInt(p.dataset.msg, 10) || 0) : 0; }
  function rowOf(id) { return document.querySelector('#ibList .ib-row[data-id="' + id + '"]'); }
  function hrefOf(row) { var a = row && row.querySelector('.ib-item'); return a ? a.getAttribute('href') : null; }
  function isNarrow() { return window.matchMedia('(max-width:1099.98px)').matches; }

  function markActive(id) {
    rows().forEach(function (r) {
      var on = parseInt(r.dataset.id, 10) === id, a = r.querySelector('.ib-item');
      r.classList.toggle('is-active', on);
      if (on) {
        r.classList.remove('unread');
        var sr = r.querySelector('.ib-sr-unread'); if (sr) sr.remove();
        a.setAttribute('aria-current', 'true');
        if (!isNarrow()) r.scrollIntoView({ block: 'nearest' });
      } else a.removeAttribute('aria-current');
    });
    document.querySelectorAll('#ibList .ib-thread-item').forEach(function (t) {
      t.classList.toggle('active', parseInt(t.dataset.ibOpen, 10) === id);
    });
  }

  /** Poprzednia/Następna i „3 z 30” wg listy, którą użytkownik ma przed oczami. */
  function syncNav() {
    var rs = rows(), id = currentId(), i = -1;
    rs.forEach(function (r, k) { if (parseInt(r.dataset.id, 10) === id) i = k; });
    var pos = document.querySelector('#ibMsgCol .ib-nav-pos');
    if (pos) pos.textContent = i >= 0 ? (i + 1) + ' z ' + rs.length : '';
    [['prev', i - 1], ['next', i + 1]].forEach(function (p) {
      var a = document.querySelector('#ibMsgCol [data-ib-go="' + p[0] + '"]');
      if (!a) return;
      var h = (i >= 0 && rs[p[1]]) ? hrefOf(rs[p[1]]) : null;
      if (h) { a.setAttribute('href', h); a.removeAttribute('aria-disabled'); }
      else { a.removeAttribute('href'); a.setAttribute('aria-disabled', 'true'); }
    });
  }

  var loadSeq = 0;
  function loadMsg(href, o) {
    o = o || {};
    var seq = ++loadSeq;
    wrap.classList.add('ib-loading');
    return fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok || r.redirected) throw new Error('http'); return r.text(); })
      .then(function (html) {
        if (seq !== loadSeq) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var col = doc.getElementById('ibMsgCol');
        if (!col) throw new Error('fragment');
        document.getElementById('ibMsgCol').innerHTML = col.innerHTML;
        var mods = doc.getElementById('ibMsgModals'), myMods = document.getElementById('ibMsgModals');
        if (myMods) myMods.innerHTML = mods ? mods.innerHTML : '';
        // Licznik „Skrzynka” w menu CRM i liczniki widoków — otwarcie = przeczytanie
        var nb = doc.querySelector('.crm-navlink.active .crm-nav-badge'), ob = document.querySelector('.crm-navlink.active .crm-nav-badge');
        if (nb && ob) ob.textContent = nb.textContent;
        var cnt = {};
        doc.querySelectorAll('[data-ib-cnt]').forEach(function (el) { cnt[el.dataset.ibCnt] = el.textContent; });
        paintCounts(cnt);
        if (o.push !== false) history.pushState({ ibMsg: 1 }, '', href);
        if (doc.title) document.title = doc.title;
        var id = currentId();
        markActive(id);
        syncNav();
        if (o.reading !== false) wrap.classList.add('is-reading');
        initPane();
        wrap.classList.remove('ib-loading');
        var pane = document.getElementById('ibPane');
        if (pane && !isNarrow()) pane.scrollTop = 0;
        if (isNarrow() && o.reading !== false) window.scrollTo(0, 0);
        var h1 = document.getElementById('ibMsgTitle');
        if (o.focusPane && h1) h1.focus();
        if (h1) announce('Otwarto: ' + h1.textContent);
        document.dispatchEvent(new CustomEvent('ib:pane-loaded'));
      })
      .catch(function () { if (seq === loadSeq) window.location.href = href; });
  }
  IB.loadMsg = loadMsg;
  IB.rows = rows; IB.currentId = currentId; IB.hrefOf = hrefOf;

  /** Przejście o jedną wiadomość w górę/dół listy. */
  IB.go = function (dir, o) {
    var rs = rows(), id = currentId(), i = -1;
    rs.forEach(function (r, k) { if (parseInt(r.dataset.id, 10) === id) i = k; });
    var t = rs[i < 0 ? 0 : i + dir];
    if (!t) { announce(dir > 0 ? 'To ostatnia wiadomość na tej stronie listy.' : 'To pierwsza wiadomość na liście.'); return; }
    loadMsg(hrefOf(t), o);
    var a = t.querySelector('.ib-item');
    if (a && document.activeElement && document.activeElement.closest && document.activeElement.closest('#ibList')) a.focus();
  };

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a[data-ib-open], a[data-ib-go], a[data-ib-back]');
    if (!a || !a.getAttribute('href')) return;
    e.preventDefault();
    if (a.hasAttribute('data-ib-back')) {
      wrap.classList.remove('is-reading');
      history.pushState({ ibMsg: 0 }, '', a.getAttribute('href'));
      var act = document.querySelector('#ibList .ib-row.is-active .ib-item');
      if (act) { act.scrollIntoView({ block: 'center' }); act.focus(); }
      return;
    }
    loadMsg(a.getAttribute('href'));
  });

  window.addEventListener('popstate', function () {
    var m = parseInt(new URLSearchParams(window.location.search).get('msg') || '0', 10);
    if (!m) { wrap.classList.remove('is-reading'); return; }
    if (m !== currentId()) loadMsg(window.location.href, { push: false });
    else wrap.classList.add('is-reading');
  });
  syncNav();

  /* ── Akcje na wiadomości bez przeładowania ────────────────────────────────
     Formularze z data-ib-xhr idą fetch-em z _xhr=1; serwer odpowiada JSON-em
     z tym samym komunikatem, który pokazałby po przekierowaniu. Gdy wiadomość
     wypada z bieżącego widoku (załatwiona, spam, porzucona), znika z listy
     i od razu otwiera się następna — z „Cofnij” w komunikacie. */
  var STAYS = { archive: ['all', 'mine', 'archived'], spam: ['spam'], hide: ['hidden'] };
  var UNDO  = { archive: 'restore', spam: 'restore', hide: 'unhide' };

  function post(fd) {
    fd.set('_xhr', '1');
    return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }

  function removeRow(id) {
    var r = rowOf(id);
    if (!r) return null;
    var rs = rows(), i = rs.indexOf(r), nxt = rs[i + 1] || rs[i - 1] || null;
    var sib = r.nextElementSibling;
    if (sib && sib.classList.contains('ib-thread')) sib.remove();
    var prev = r.previousElementSibling, after = r.nextElementSibling;
    r.remove();
    // Nagłówek dnia bez wiadomości pod spodem też znika
    if (prev && prev.classList.contains('ib-day') && (!after || after.classList.contains('ib-day'))) prev.remove();
    bulkInit();
    return nxt;
  }

  function reloadPane() { return loadMsg(window.location.href, { push: false, reading: wrap.classList.contains('is-reading') }); }

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-ib-xhr]');
    if (!f || !window.fetch || !window.FormData) return;
    e.preventDefault();
    var op = f.dataset.ibXhr, fd = new FormData(f), id = parseInt(fd.get('msg_id'), 10) || currentId();
    document.querySelectorAll('#ibMsgCol [data-ib-dd][aria-expanded="true"]').forEach(function (b) {
      var d = window.bootstrap && window.bootstrap.Dropdown.getInstance(b); if (d) d.hide();
    });
    post(fd).then(function (d) {
      if (!d || !d.ok) { toast((d && d.msg) || 'Nie udało się wykonać działania.', { err: true }); return; }
      var undo = null;
      if (UNDO[op]) undo = function () {
        var u = new FormData(f); u.set('_op', UNDO[op]);
        post(u).then(function (d2) {
          toast(d2 && d2.msg);
          refreshList(function () { var r = rowOf(id); loadMsg(r ? hrefOf(r) : window.location.href); });
        });
      };
      if (STAYS[op] && STAYS[op].indexOf(IB.view) < 0) {
        var nxt = removeRow(id);
        toast(d.msg, { undo: undo });
        if (nxt) loadMsg(hrefOf(nxt), { reading: wrap.classList.contains('is-reading') });
        else {
          var u = new URL(window.location.href); u.searchParams.delete('msg');
          loadMsg(u.pathname + u.search, { reading: false });
        }
        return;
      }
      if (op === 'unread') {
        // Ponowne otwarcie oznaczyłoby ją z powrotem jako przeczytaną — zmieniamy tylko wiersz
        var r = rowOf(id); if (r) r.classList.add('unread');
        toast(d.msg);
        return;
      }
      toast(d.msg, { undo: undo });
      reloadPane();
      refreshList();
    }).catch(function () { f.removeAttribute('data-ib-xhr'); f.submit(); });
  });
  /* ══ Skróty klawiszowe ═══════════════════════════════════════════════════
     Jednoklawiszowe jak w Gmailu (j/k, e, r…). WCAG 2.1.4: da się je wyłączyć
     w oknie „Skróty klawiszowe”, nie działają w polach tekstowych, przy
     otwartym oknie ani menu, ani z Ctrl/Alt/Cmd. */
  var keysOn = store('crmInboxKeys') !== '0';
  var keysBox = document.getElementById('ibKeysOn');
  if (keysBox) {
    keysBox.checked = keysOn;
    keysBox.addEventListener('change', function () { keysOn = keysBox.checked; store('crmInboxKeys', keysOn ? '1' : '0'); });
  }
  function typing(t) { return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || '')); }
  function pressKey(k) {
    var b = document.querySelector('#ibMsgCol [data-ib-key="' + k + '"]');
    if (b) { b.click(); return true; }
    return false;
  }
  function openModal(id) {
    var m = document.getElementById(id);
    if (m && window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(m).show(); return true; }
    return false;
  }
  function focusedRow(t) { return t && t.closest ? t.closest('#ibList .ib-row') : null; }

  function onKey(e, fromFrame) {
    if (!keysOn || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (typing(t)) {
      if (e.key === 'Escape' && t.id === 'ibSearch') t.blur();
      return;
    }
    if (document.querySelector('.modal.show, .dropdown-menu.show')) return;
    var k = e.key, inList = !fromFrame && !!(t.closest && t.closest('#ibList'));
    var done = true;
    if (k === 'j' || (k === 'ArrowDown' && inList)) IB.go(1);
    else if (k === 'k' || (k === 'ArrowUp' && inList)) IB.go(-1);
    else if ((k === 'Enter' && inList && t.classList.contains('ib-item')) || k === 'o') {
      var row = focusedRow(t) || document.querySelector('#ibList .ib-row.is-active');
      if (!row) return;
      if (parseInt(row.dataset.id, 10) === IB.currentId()) {
        wrap.classList.add('is-reading');
        var h1 = document.getElementById('ibMsgTitle'); if (h1) h1.focus();
      } else loadMsg(IB.hrefOf(row), { focusPane: true });
    }
    else if (k === '/') { var s = document.getElementById('ibSearch'); if (s) { s.focus(); s.select(); } }
    else if (k === 'Escape') {
      var back = document.querySelector('#ibMsgCol [data-ib-back]');
      if (wrap.classList.contains('is-reading') && isNarrow() && back) back.click();
      else { var a = document.querySelector('#ibList .ib-row.is-active .ib-item'); if (a) a.focus(); else done = false; }
    }
    else if (k === 'x') {
      var r = focusedRow(t) || document.querySelector('#ibList .ib-row.is-active');
      var c = r && r.querySelector('.ib-check');
      if (c) { c.checked = !c.checked; c.dispatchEvent(new Event('change', { bubbles: true })); } else done = false;
    }
    else if (k === 'I') { var ra = document.getElementById('ibReadAll'); if (ra) ra.click(); else done = false; }
    else if (k === '?') done = openModal('ibKeysModal');
    else if (k === 'c') done = openModal('mdCase');
    else if (k === 'z') done = openModal('mdEzd');
    else if (k === 'f') done = openModal('mdFwd');
    else if ('aeru!'.indexOf(k) >= 0 && k.length === 1) done = pressKey(k);
    else done = false;
    if (done) e.preventDefault();
  }
  document.addEventListener('keydown', function (e) { onKey(e, false); });
  // Klawisze z ramki z treścią (przekazuje je fitFrame)
  document.addEventListener('ib:frame-key', function (ev) { onKey(ev.detail, true); });
})();
