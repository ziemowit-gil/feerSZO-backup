/**
 * teryt_cascade.js — Kaskadowe selecty Województwo → Powiat → Gmina
 * z danymi z /crm/api/teryt.php
 *
 * Użycie:
 *   TerytCascade.init({
 *     woj:  '#select_woj',
 *     pow:  '#select_pow',
 *     gmi:  '#input_gmi',        // input text (autocomplete) LUB '#select_gmi' (select)
 *     api:  '/crm/api/teryt.php',
 *   });
 */
const TerytCascade = (function () {
  'use strict';

  function init(opts) {
    const wojEl  = document.querySelector(opts.woj);
    const powEl  = document.querySelector(opts.pow);
    const gmiEl  = document.querySelector(opts.gmi);
    const api    = opts.api || '/crm/api/teryt.php';

    if (!wojEl || !powEl) return;

    // Zapamiętaj oryginalne wartości (do re-select po załadowaniu)
    const initPow = powEl.value || '';
    const initGmi = gmiEl ? (gmiEl.value || '') : '';

    // Woj → załaduj powiaty
    wojEl.addEventListener('change', () => {
      resetSelect(powEl, '— wybierz powiat —');
      if (gmiEl) resetGmi();
      const woj = wojEl.value;
      if (!woj) return;
      loadPowiaty(woj, powEl, api).then(() => {
        if (initPow) {
          powEl.value = initPow;
          if (powEl.value === initPow) powEl.dispatchEvent(new Event('change'));
        }
      });
    });

    // Pow → załaduj gminy
    powEl.addEventListener('change', () => {
      if (gmiEl) resetGmi();
      const pow = powEl.value;
      if (!pow || !gmiEl) return;
      const isSelect = gmiEl.tagName === 'SELECT';
      if (isSelect) {
        loadGminy(pow, gmiEl, api).then(() => {
          if (initGmi) gmiEl.value = initGmi;
        });
      } else {
        gmiEl.setAttribute('data-pow', pow);
      }
    });

    // Gmina text input — autocomplete (jeśli to <input>)
    if (gmiEl && gmiEl.tagName === 'INPUT') {
      let timer = null;
      let ddEl  = null;

      gmiEl.addEventListener('input', () => {
        clearTimeout(timer);
        const pow = powEl.value || gmiEl.getAttribute('data-pow') || '';
        const q   = gmiEl.value.trim();
        if (q.length < 2) { hideDropdown(); return; }
        timer = setTimeout(() => {
          const url = pow
            ? `${api}?action=gminy&pow=${encodeURIComponent(pow)}`
            : `${api}?action=search&q=${encodeURIComponent(q)}&level=3`;
          fetch(url).then(r => r.json()).then(rows => {
            const filtered = pow
              ? rows.filter(r => r.name.toLowerCase().includes(q.toLowerCase()))
              : rows;
            showDropdown(gmiEl, filtered);
          });
        }, 200);
      });

      gmiEl.addEventListener('blur', () => setTimeout(hideDropdown, 200));

      function showDropdown(inp, rows) {
        hideDropdown();
        if (!rows.length) return;
        ddEl = document.createElement('div');
        ddEl.style.cssText = 'position:absolute;z-index:1055;background:#fff;border:1px solid #E5E7EB;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);width:'+inp.offsetWidth+'px;max-height:200px;overflow-y:auto';
        rows.slice(0, 30).forEach(r => {
          const d = document.createElement('div');
          d.style.cssText = 'padding:.4rem .75rem;cursor:pointer;font-size:.83rem';
          d.textContent = r.label;
          d.addEventListener('mousedown', () => { inp.value = r.name; inp.setAttribute('data-kod', r.id || ''); hideDropdown(); });
          d.addEventListener('mouseover', () => d.style.background = '#F3F4F6');
          d.addEventListener('mouseout',  () => d.style.background = '');
          ddEl.appendChild(d);
        });
        inp.parentElement.style.position = 'relative';
        inp.parentElement.appendChild(ddEl);
        ddEl.style.top = inp.offsetTop + inp.offsetHeight + 'px';
        ddEl.style.left= inp.offsetLeft + 'px';
      }

      function hideDropdown() {
        if (ddEl) { ddEl.remove(); ddEl = null; }
      }
    }

    // Auto-init jeśli woj ma wartość
    if (wojEl.value) {
      loadPowiaty(wojEl.value, powEl, api).then(() => {
        if (initPow) {
          powEl.value = initPow;
          if (powEl.value === initPow && gmiEl && gmiEl.tagName === 'SELECT') {
            loadGminy(initPow, gmiEl, api).then(() => {
              if (initGmi) gmiEl.value = initGmi;
            });
          } else if (initPow && gmiEl && gmiEl.tagName === 'INPUT') {
            gmiEl.setAttribute('data-pow', initPow);
          }
        }
      });
    }
  }

  async function loadPowiaty(woj, sel, api) {
    resetSelect(sel, 'Ładowanie…');
    const rows = await fetch(`${api}?action=powiaty&woj=${encodeURIComponent(woj)}`).then(r => r.json()).catch(() => []);
    resetSelect(sel, rows.length ? '— wybierz powiat —' : '(brak danych — zaimportuj TERYT)');
    rows.forEach(r => {
      const o = document.createElement('option');
      o.value = r.id; o.textContent = r.label;
      sel.appendChild(o);
    });
  }

  async function loadGminy(pow, sel, api) {
    resetSelect(sel, 'Ładowanie…');
    const rows = await fetch(`${api}?action=gminy&pow=${encodeURIComponent(pow)}`).then(r => r.json()).catch(() => []);
    resetSelect(sel, rows.length ? '— wybierz gminę —' : '(brak danych)');
    rows.forEach(r => {
      const o = document.createElement('option');
      o.value = r.id; o.textContent = r.label;
      sel.appendChild(o);
    });
  }

  function resetSelect(sel, placeholder) {
    if (sel.tagName !== 'SELECT') return;
    sel.innerHTML = `<option value="">${placeholder}</option>`;
  }

  function resetGmi() {
    const gmiEl = arguments[0] || null;
    if (!gmiEl) return;
    if (gmiEl.tagName === 'SELECT') resetSelect(gmiEl, '— wybierz gminę —');
    else { gmiEl.value = ''; gmiEl.removeAttribute('data-pow'); gmiEl.removeAttribute('data-kod'); }
  }

  return { init, loadPowiaty, loadGminy };
})();
