/* modules/rich_editor/view/richEditor.js — wspólny edytor treści (CKEditor 5, kompilacja „classic” 39.0.1).
 *
 *   var ed = RichEditor.attach(hostElOrSelector, {
 *     placeholder: 'Treść…', label: 'Treść e-maila',   // label → aria-label pola edycji
 *     describedBy: 'idKomunikatuBledu',                 // aria-describedby (komunikat błędu pod polem)
 *     initial: '<p>…</p>' | 'zwykły tekst',             // treść początkowa (HTML albo tekst)
 *     minHeight: '220px', maxHeight: '60vh'
 *   });
 *   ed.ready                → Promise (CKEditor gotowy albo pole zastępcze, gdy CDN niedostępny)
 *   ed.getHtml()            → HTML z edytora (do zapisu szablonu, szkicu)
 *   ed.getEmailHtml()       → HTML do wysyłki: bez pustych akapitów, akapity z margin:0 0 1em
 *   ed.getText()            → zwykły tekst (walidacja, licznik znaków)
 *   ed.isEmpty()
 *   ed.setHtml(htmlOrText)  → tekst bez znaczników zamienia się w akapity (pusta linia) i <br>
 *   ed.prependHtml(html)    → wstawia HTML przed obecną treścią (cytat w odpowiedzi)
 *   ed.appendHtml(html)
 *   ed.insertText(t)        → w miejscu kursora (zmienne {imie} itp.)
 *   ed.focus(atStart)
 *   ed.onChange(fn)
 *   ed.setInvalid(bool)
 *
 * Do czasu wczytania edytora operacje trafiają do bufora (treść zostanie wstawiona po starcie),
 * więc istniejący, synchroniczny kod strony może wołać setHtml/getText od razu. */
(function () {
  'use strict';
  if (window.RichEditor) return;

  function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function looksHtml(s) { return /<\s*(p|br|div|span|a|ul|ol|li|table|h[1-6]|strong|b|em|i|u|blockquote)\b[^>]*>/i.test(String(s || '')); }

  /** Zwykły tekst → akapity (pusta linia) i łamania wierszy (pojedynczy Enter). */
  function plainToHtml(t) {
    t = String(t == null ? '' : t).replace(/\r\n?/g, '\n').trim();
    if (!t) return '';
    return t.split(/\n[ \t]*\n+/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
  }
  function toHtml(s) { s = String(s == null ? '' : s); return looksHtml(s) ? s : plainToHtml(s); }

  /** HTML → tekst: akapity i <br> jako nowe linie, bez podwójnych pustych linii. */
  function htmlToText(html) {
    var d = document.createElement('div');
    d.innerHTML = String(html || '')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div|li|h[1-6]|blockquote|tr)>/gi, '</$1>\n');
    return (d.textContent || '')
      .replace(/ /g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{2,}/g, '\n')
      .trim();
  }

  /** HTML do maila: puste akapity (podwójny Enter) wylatują — odstęp daje margines akapitu, taki sam jak
   *  w edytorze; bez jawnego marginesu Outlook/Gmail dokładają własny i robi się dziura na trzy linie. */
  function emailHtml(html) {
    return String(html || '')
      .replace(/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '')
      .replace(/<p>/gi, '<p style="margin:0 0 1em">')
      .trim();
  }

  /* Bootstrap w oknie modalnym zabiera fokus elementom spoza okna — a okienka CKEditora (link, tabela)
     wiszą w <body>. Zdarzenie focusin z tych okienek zatrzymujemy, zanim dotrze do pułapki fokusu. */
  window.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('.ck-body-wrapper, .ck-balloon-panel')) e.stopImmediatePropagation();
  }, true);

  function attach(host, opts) {
    host = typeof host === 'string' ? document.querySelector(host) : host;
    opts = opts || {};
    var ed = null, ta = null, cbs = [], pending = toHtml(opts.initial || ''), started = pending;

    // Własna otoczka: wygląd (.re-wrap) i oznaczenie błędu niezależnie od kontenera strony
    var wrap = document.createElement('div');
    wrap.className = 're-wrap';
    if (opts.minHeight) wrap.style.setProperty('--re-min-h', opts.minHeight);
    if (opts.maxHeight) wrap.style.setProperty('--re-max-h', opts.maxHeight);
    if (host && host.parentNode) { host.parentNode.insertBefore(wrap, host); wrap.appendChild(host); }

    function fire() { cbs.forEach(function (f) { try { f(); } catch (e) { console.error(e); } }); }

    var api = {
      editor: null,
      wrap: wrap,
      getHtml: function () {
        if (ed) return ed.getData();
        if (ta) return plainToHtml(ta.value);
        return pending;
      },
      getEmailHtml: function () { return emailHtml(api.getHtml()); },
      getText: function () { return ta ? ta.value.trim() : htmlToText(api.getHtml()); },
      isEmpty: function () { return api.getText() === ''; },
      setHtml: function (h) {
        var html = toHtml(h);
        if (ed) ed.setData(html);
        else if (ta) { ta.value = htmlToText(html); fire(); }
        else pending = html;
      },
      prependHtml: function (h) { api.setHtml(toHtml(h) + api.getHtml()); },
      appendHtml: function (h) { api.setHtml(api.getHtml() + toHtml(h)); },
      insertText: function (t) {
        t = String(t);
        if (ed) {
          ed.model.change(function (w) { ed.model.insertContent(w.createText(t)); });
          ed.editing.view.focus();
        } else if (ta) {
          var s = ta.selectionStart, e = ta.selectionEnd;
          ta.value = ta.value.slice(0, s) + t + ta.value.slice(e);
          ta.selectionStart = ta.selectionEnd = s + t.length;
          ta.focus(); fire();
        } else pending += esc(t);
      },
      focus: function (atStart) {
        if (ed) {
          if (atStart) ed.model.change(function (w) { w.setSelection(w.createPositionAt(ed.model.document.getRoot(), 0)); });
          ed.editing.view.focus();
        } else if (ta) { ta.focus(); if (atStart) ta.setSelectionRange(0, 0); }
      },
      onChange: function (f) { if (typeof f === 'function') cbs.push(f); },
      setInvalid: function (on) {
        wrap.classList.toggle('is-invalid', !!on);
        var el = ed ? ed.ui.view.editable.element : ta;
        if (el) { if (on) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); }
      }
    };

    function fallback(why) {
      if (why) console.error('RichEditor:', why);
      ta = document.createElement('textarea');
      ta.className = 're-fallback';
      ta.setAttribute('aria-label', opts.label || 'Treść');
      if (opts.describedBy) ta.setAttribute('aria-describedby', opts.describedBy);
      ta.placeholder = (opts.placeholder || 'Treść…') + ' (edytor się nie wczytał — wpisz zwykły tekst)';
      ta.value = htmlToText(pending);
      ta.addEventListener('input', fire);
      if (host) host.replaceWith(ta); else wrap.appendChild(ta);
      return api;
    }

    if (!host) { api.ready = Promise.resolve(fallback('brak elementu edytora')); return api; }
    if (!window.ClassicEditor) { api.ready = Promise.resolve(fallback('CKEditor nie wczytał się z CDN')); return api; }

    api.ready = window.ClassicEditor.create(host, {
      language: 'pl',
      initialData: pending,
      placeholder: opts.placeholder || 'Treść… Enter = nowy akapit, Shift+Enter = nowa linia',
      // Osadzanie filmów (YouTube itp.) w poczcie nie działa
      removePlugins: ['MediaEmbed'],
      toolbar: ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', '|', 'undo', 'redo'],
      heading: { options: [
        { model: 'paragraph', title: 'Akapit', class: 'ck-heading_paragraph' },
        { model: 'heading2', view: 'h2', title: 'Nagłówek', class: 'ck-heading_heading2' },
        { model: 'heading3', view: 'h3', title: 'Podtytuł', class: 'ck-heading_heading3' }
      ]},
      link: { defaultProtocol: 'https://' }
    }).then(function (e) {
      ed = e; api.editor = e;
      if (pending !== started) e.setData(pending);           // setHtml() wołane w trakcie wczytywania
      if (opts.label) e.ui.view.editable.element.setAttribute('aria-label', opts.label);
      if (opts.describedBy) e.ui.view.editable.element.setAttribute('aria-describedby', opts.describedBy);
      e.model.document.on('change:data', fire);
      return api;
    }).catch(function (err) { return fallback(err); });
    return api;
  }

  window.RichEditor = { attach: attach, emailHtml: emailHtml, plainToHtml: plainToHtml, htmlToText: htmlToText, looksHtml: looksHtml };
})();
