/**
 * utils.js — Małe ficzery systemowe
 * Ładowany globalnie przez header.php
 */

// ═══════════════════════════════════════════════════════════════════════════
// 1. CTRL+S — zapisz aktywny formularz
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    // Szukaj przycisku submit w aktywnym focusie, potem na stronie
    var frm = document.activeElement && document.activeElement.form;
    if (!frm) frm = document.querySelector('form[method="post"]:not([data-no-ctrlsave])');
    if (frm) {
      var btn = frm.querySelector('[type="submit"]');
      if (btn) { btn.click(); return; }
      frm.submit();
    }
  }
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. UNSAVED CHANGES WARNING
//    Formularz z [data-dirty-check] — ostrzeż przed wyjściem bez zapisu
// ═══════════════════════════════════════════════════════════════════════════
(function() {
  var dirty = false;
  document.querySelectorAll('form[data-dirty-check] input, form[data-dirty-check] select, form[data-dirty-check] textarea')
    .forEach(function(el) {
      el.addEventListener('change', function() { dirty = true; });
      el.addEventListener('input',  function() { dirty = true; });
    });
  document.querySelectorAll('form[data-dirty-check]').forEach(function(f) {
    f.addEventListener('submit', function() { dirty = false; });
  });
  window.addEventListener('beforeunload', function(e) {
    if (dirty) {
      e.preventDefault();
      e.returnValue = 'Masz niezapisane zmiany. Opuścić stronę?';
    }
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// 3. COPY TO CLIPBOARD — przyciski [data-copy="..."] lub [data-copy-target="#id"]
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('click', function(e) {
  var btn = e.target.closest('[data-copy]');
  if (!btn) return;
  var text = btn.dataset.copy;
  if (!text && btn.dataset.copyTarget) {
    var el = document.querySelector(btn.dataset.copyTarget);
    text = el ? el.textContent.trim() : '';
  }
  if (!text) return;
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg"></i>';
    btn.style.color = '#16a34a';
    setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; }, 1500);
  }).catch(function() {
    // Fallback dla starszych przeglądarek
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. SCROLL TO TOP — przycisk pojawia się po 400px
// ═══════════════════════════════════════════════════════════════════════════
(function() {
  var btn = document.createElement('button');
  btn.id = '_scrolltop';
  btn.innerHTML = '<i class="bi bi-arrow-up"></i>';
  btn.title = 'Wróć na górę';
  btn.setAttribute('aria-label', 'Wróć na górę');
  btn.style.cssText = [
    'position:fixed','bottom:5rem','right:1.25rem','z-index:9990',
    'width:38px','height:38px','border-radius:50%',
    'background:#fff','border:1.5px solid #e2e8f0',
    'box-shadow:0 2px 8px rgba(0,0,0,.12)',
    'color:#64748b','font-size:.95rem',
    'display:none','align-items:center','justify-content:center',
    'cursor:pointer','transition:opacity .2s',
  ].join(';');
  document.body.appendChild(btn);
  var scrollEl = document.getElementById('main') || document.getElementById('pv-content') || window;
  var getScroll = function() {
    return scrollEl === window ? window.scrollY : scrollEl.scrollTop;
  };
  (scrollEl === window ? window : scrollEl).addEventListener('scroll', function() {
    btn.style.display = getScroll() > 400 ? 'flex' : 'none';
  }, { passive: true });
  btn.addEventListener('click', function() {
    if (scrollEl === window) window.scrollTo({ top: 0, behavior: 'smooth' });
    else scrollEl.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// 5. PESEL → wiek na żywo
//    <span class="pesel-age" data-pesel-src="#input_id"></span>
// ═══════════════════════════════════════════════════════════════════════════
function _peselAge(pesel) {
  if (!pesel || pesel.length !== 11) return null;
  var y = parseInt(pesel.substr(0, 2), 10);
  var m = parseInt(pesel.substr(2, 2), 10);
  var d = parseInt(pesel.substr(4, 2), 10);
  if (m >= 81) { y += 1800; m -= 80; }
  else if (m >= 61) { y += 2200; m -= 60; }
  else if (m >= 41) { y += 2100; m -= 40; }
  else if (m >= 21) { y += 2000; m -= 20; }
  else { y += 1900; }
  var bday = new Date(y, m - 1, d);
  if (isNaN(bday)) return null;
  var today = new Date();
  var age = today.getFullYear() - bday.getFullYear();
  if (today < new Date(today.getFullYear(), m - 1, d)) age--;
  return age;
}

document.querySelectorAll('.pesel-age[data-pesel-src]').forEach(function(span) {
  var src = document.querySelector(span.dataset.peselSrc);
  if (!src) return;
  function update() {
    var age = _peselAge(src.value.replace(/\D/g, ''));
    span.textContent = age !== null ? age + ' lat' : '';
  }
  src.addEventListener('input', update);
  update();
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. AUTOSAVE DRAFT w formularzach [data-autosave="key"]
//    Zapisuje do localStorage co 30s i przy zmianie pól
// ═══════════════════════════════════════════════════════════════════════════
(function() {
  document.querySelectorAll('form[data-autosave]').forEach(function(form) {
    var key = 'draft_' + form.dataset.autosave;
    var fields = form.querySelectorAll('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]), select, textarea');

    // Przywróć draft
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(key)); } catch(e) {}
    if (saved && Object.keys(saved).length) {
      var restored = 0;
      fields.forEach(function(f) {
        if (f.name && saved[f.name] !== undefined && !f.value) {
          f.value = saved[f.name]; restored++;
        }
      });
      if (restored) {
        var banner = document.createElement('div');
        banner.style.cssText = 'background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem 1rem;font-size:.82rem;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem';
        banner.innerHTML = '<i class="bi bi-clock-history" style="color:#d97706"></i><span>Przywrócono niezapisany szkic.</span>'
          + '<button type="button" style="margin-left:auto;background:none;border:none;color:#6b7280;cursor:pointer;font-size:.8rem" onclick="localStorage.removeItem(\''+key+'\');this.closest(\'div\').remove()">Usuń szkic</button>';
        form.insertAdjacentElement('beforebegin', banner);
      }
    }

    // Zapisuj
    function save() {
      var data = {};
      fields.forEach(function(f) { if (f.name) data[f.name] = f.value; });
      try { localStorage.setItem(key, JSON.stringify(data)); } catch(e) {}
    }
    fields.forEach(function(f) { f.addEventListener('change', save); });
    setInterval(save, 30000);

    // Wyczyść po zapisaniu
    form.addEventListener('submit', function() {
      try { localStorage.removeItem(key); } catch(e) {}
    });
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// 7. COUNTER ZNAKÓW — textarea[maxlength] lub textarea[data-maxlength]
// ═══════════════════════════════════════════════════════════════════════════
document.querySelectorAll('textarea[maxlength], textarea[data-maxlength]').forEach(function(ta) {
  var max = parseInt(ta.maxLength || ta.dataset.maxlength, 10);
  if (!max || max < 0) return;
  var hint = document.createElement('div');
  hint.style.cssText = 'font-size:.72rem;color:#94a3b8;text-align:right;margin-top:.15rem';
  ta.insertAdjacentElement('afterend', hint);
  function upd() {
    var left = max - ta.value.length;
    hint.textContent = left + ' / ' + max;
    hint.style.color = left < 20 ? '#dc2626' : '#94a3b8';
  }
  ta.addEventListener('input', upd); upd();
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. KLIKNIĘCIE na wierszu tabeli → otwórz link [data-row-href]
//    Ignoruje kliknięcia w przyciski, linki i checkboxy wewnątrz wiersza
// ═══════════════════════════════════════════════════════════════════════════
document.querySelectorAll('tr[data-row-href]').forEach(function(tr) {
  tr.style.cursor = 'pointer';
  tr.addEventListener('click', function(e) {
    if (e.target.closest('a,button,input,label,[data-copy]')) return;
    window.location.href = tr.dataset.rowHref;
  });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. TOOLTIP na skróconych tekstach — auto .text-truncate title
// ═══════════════════════════════════════════════════════════════════════════
document.querySelectorAll('.text-truncate[title=""],.text-truncate:not([title])').forEach(function(el) {
  if (el.scrollWidth > el.clientWidth) {
    el.title = el.textContent.trim();
  }
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. ESC — zamknij dropdown/modal/alert bez klikania X
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('keydown', function(e) {
  if (e.key !== 'Escape') return;
  // Zamknij alert Flash (toast)
  var toast = document.getElementById('_flash_toast_wrap');
  if (toast) { toast.remove(); return; }
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. CTRL+K — wyszukiwarka menu (paleta poleceń)
// ═══════════════════════════════════════════════════════════════════════════
// Paletę obsługuje własny handler w includes/header.php (gdy istnieje #cmdk-root).
// Tu zostaje tylko fallback dla stron bez palety (np. wyzwolenie triggera).
document.addEventListener('keydown', function(e) {
  if (!((e.ctrlKey || e.metaKey) && e.key === 'k')) return;
  if (document.getElementById('cmdk-root')) return; // paleta ma własny handler
  var t = document.getElementById('cmdk-trigger');
  if (t) { e.preventDefault(); t.click(); }
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. AUTO-FOCUS — pierwsze widoczne pole tekstowe na stronach add/edit
// ═══════════════════════════════════════════════════════════════════════════
(function() {
  var path = window.location.pathname;
  if (!/\/(add|edit|compose|nowy|new)\.php/.test(path)) return;
  // Nie nadpisuj jeśli ktoś już ma focus (np. pole z autofocus="")
  if (document.activeElement && document.activeElement !== document.body) return;
  var first = document.querySelector(
    'input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([readonly]):not([disabled]),' +
    'select:not([disabled]),textarea:not([readonly]):not([disabled])'
  );
  if (first) {
    setTimeout(function() { first.focus(); }, 60);
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// 13. SKRÓTY KLAWIATUROWE — klawisz "?" otwiera panel pomocy
// ═══════════════════════════════════════════════════════════════════════════
(function() {
  document.addEventListener('keydown', function(e) {
    // Ignoruj gdy focus jest w polu tekstowym
    var tag = document.activeElement && document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (e.key !== '?') return;
    e.preventDefault();

    // Usuń poprzedni modal jeśli istnieje
    var existing = document.getElementById('_shortcuts_modal');
    if (existing) { existing.remove(); return; }

    var modal = document.createElement('div');
    modal.id = '_shortcuts_modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Skróty klawiaturowe');
    modal.style.cssText = 'position:fixed;inset:0;z-index:10500;display:flex;align-items:center;justify-content:center;padding:1rem';

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:absolute;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px)';
    overlay.addEventListener('click', function() { modal.remove(); });

    var shortcuts = [
      ['Ctrl + K',  'Otwórz wyszukiwanie'],
      ['Ctrl + S',  'Zapisz formularz'],
      ['?',         'Ten panel skrótów'],
      ['Esc',       'Zamknij powiadomienie / wyszukiwanie'],
      ['↑ ↓',       'Nawigacja w wynikach wyszukiwania'],
      ['Enter',     'Otwórz zaznaczony wynik wyszukiwania'],
    ];

    var rows = shortcuts.map(function(s) {
      return '<tr><td style="padding:.35rem .6rem .35rem 0;white-space:nowrap">'
        + '<kbd style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:5px;padding:.15rem .45rem;font-size:.8rem;color:#1e293b;font-family:monospace">'
        + s[0] + '</kbd></td>'
        + '<td style="padding:.35rem 0;color:#475569;font-size:.84rem">' + s[1] + '</td></tr>';
    }).join('');

    var box = document.createElement('div');
    box.style.cssText = 'position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.2);padding:1.4rem 1.6rem;min-width:320px;max-width:440px;width:100%;animation:_toastIn .2s ease both';
    box.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">'
      + '<h2 style="margin:0;font-size:1rem;font-weight:700;color:#0f172a"><i class="bi bi-keyboard me-2" style="color:#94a3b8"></i>Skróty klawiaturowe</h2>'
      + '<button style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1.1rem;padding:0;line-height:1" onclick="document.getElementById(\'_shortcuts_modal\').remove()" aria-label="Zamknij"><i class="bi bi-x-lg"></i></button>'
      + '</div>'
      + '<table style="width:100%;border-collapse:collapse">' + rows + '</table>'
      + '<div style="margin-top:.9rem;padding-top:.75rem;border-top:1px solid #f1f5f9;font-size:.74rem;color:#94a3b8;text-align:center">Naciśnij <kbd style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;padding:.1rem .35rem;font-size:.75rem;color:#475569">Esc</kbd> lub kliknij tło, aby zamknąć</div>';

    // Zamknij przez Esc
    document.addEventListener('keydown', function closeOnEsc(ev) {
      if (ev.key === 'Escape') { modal.remove(); document.removeEventListener('keydown', closeOnEsc); }
    });

    modal.appendChild(overlay);
    modal.appendChild(box);
    document.body.appendChild(modal);
  });
})();
