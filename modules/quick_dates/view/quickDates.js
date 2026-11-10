/* modules/quick_dates/view/quickDates.js — szybki wybór daty: „+7 dni”, „+14 dni”, „Za miesiąc” przy polach
 * <input type="date" data-quick-dates> (wartość „parent” = przyciski pod rodzicem pola).
 *
 * Data liczona od DZIŚ (nie od wpisanej) — „termin za tydzień”. „Za miesiąc” = ten sam dzień w następnym
 * miesiącu, a gdy go nie ma (31 → luty) — ostatni dzień miesiąca. Po kliknięciu pole dostaje zdarzenia
 * input i change, więc zapisy podpięte pod onchange (np. tdPatch w oknie zadania) działają bez zmian.
 * Pola wstawione później (panel zadania z fetch) wychwytuje MutationObserver. */
(function () {
  'use strict';
  if (window.QuickDates) return;

  var SEL  = 'input[type="date"][data-quick-dates]';
  var OPTS = [
    { days: 7,    label: '+7 dni',     say: 'za 7 dni' },
    { days: 14,   label: '+14 dni',    say: 'za 14 dni' },
    { months: 1,  label: 'Za miesiąc', say: 'za miesiąc' }
  ];
  var DOW = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function human(d) { return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' (' + DOW[d.getDay()] + ')'; }
  function target(o) {
    var t = new Date(); t.setHours(12, 0, 0, 0);            // południe — bez przeskoków przy zmianie czasu
    if (o.days) t.setDate(t.getDate() + o.days);
    if (o.months) {
      var day = t.getDate();
      t.setDate(1); t.setMonth(t.getMonth() + o.months);
      t.setDate(Math.min(day, new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate()));
    }
    return t;
  }

  function enhance(inp) {
    if (inp.dataset.qdReady) return;
    inp.dataset.qdReady = '1';
    var name = (inp.labels && inp.labels[0] ? inp.labels[0].textContent : '').replace(/\s+/g, ' ').trim()
            || inp.getAttribute('aria-label') || 'Data';
    var row = document.createElement('div');
    row.className = 'qd-row';
    row.setAttribute('role', 'group');
    row.setAttribute('aria-label', 'Szybki wybór — ' + name);
    OPTS.forEach(function (o) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'qd-btn';
      b.textContent = o.label;
      // Podpowiedź z konkretną datą liczona na bieżąco (strona bywa otwarta przez północ)
      function describe() {
        var t = target(o);
        b.title = 'Ustaw ' + human(t);
        b.setAttribute('aria-label', name + ': ' + o.say + ', ' + human(t));
      }
      describe();
      b.addEventListener('pointerenter', describe);
      b.addEventListener('focus', describe);
      b.addEventListener('click', function () {
        if (inp.disabled || inp.readOnly) return;
        var v = iso(target(o));
        if ((inp.min && v < inp.min) || (inp.max && v > inp.max)) return;
        inp.value = v;
        inp.dispatchEvent(new Event('input',  { bubbles: true }));
        inp.dispatchEvent(new Event('change', { bubbles: true }));
      });
      row.appendChild(b);
    });
    var anchor = inp.dataset.quickDates === 'parent' && inp.parentElement ? inp.parentElement : inp;
    anchor.insertAdjacentElement('afterend', row);
  }

  function scan(root) {
    root = root && root.querySelectorAll ? root : document;
    if (root.matches && root.matches(SEL)) enhance(root);
    root.querySelectorAll(SEL).forEach(enhance);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(document); });
  else scan(document);
  new MutationObserver(function (muts) {
    muts.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); }); });
  }).observe(document.documentElement, { childList: true, subtree: true });

  window.QuickDates = { scan: scan };
})();
