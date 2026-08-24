<?php
/**
 * includes/quick_actions_widget.php — pływające szybkie akcje (makra), wspólne dla modułów.
 *
 * Wywoływane tak samo jak asystent AI: pływający przycisk w rogu ekranu, skrót
 * klawiszowy (Alt+N), Escape zamyka. Dzięki temu „dopisz kontakt / notatkę /
 * sprawę / zadanie" jest pod ręką na każdej stronie, a nie tylko w pasku CRM.
 *
 * Widżet jest SAMOWYSTARCZALNY — własny CSS i JS, bez Bootstrapa i bez CDN —
 * bo trafia do nagłówków modułów, które nie zawsze ładują Bootstrapa.
 *
 * Sterowanie z zewnątrz (przed include):
 *   $QUICK_WIDGET_BOTTOM — odstęp od dołu (domyślnie 5.5rem: nad asystentem),
 *   QUICK_WIDGET_SUPPRESS (define) — całkowicie wyłącza widżet na stronie.
 * W dowolnym miejscu strony:
 *   <button data-quick-open>…</button>              — otwiera panel,
 *   <button data-quick-open="task">…</button>       — otwiera od razu na zadaniu.
 *
 * Zapis idzie do crm/api/quick_create.php, a wybór przypiętych akcji do
 * crm/api/macros.php (ustawienie per użytkownik — includes/crm_macros.php).
 */
if (defined('_QUICK_WIDGET_LOADED')) return;
if (defined('QUICK_WIDGET_SUPPRESS')) return;
define('_QUICK_WIDGET_LOADED', true);

if (!function_exists('current_user') || !current_user()) return;

require_once __DIR__ . '/crm_macros.php';

$_qa_catalog = crm_macros_catalog();
if (!$_qa_catalog) return;                     // brak uprawnień do czegokolwiek
$_qa_pinned  = crm_macros_user();
$_qa_bottom  = $QUICK_WIDGET_BOTTOM ?? '5.5rem';
?>
<style>
#qaw-fab {
  position: fixed; right: 1.5rem; bottom: <?= htmlspecialchars($_qa_bottom, ENT_QUOTES) ?>;
  z-index: 9080; width: 52px; height: 52px; border-radius: 50%; border: 0; cursor: pointer;
  background: #0176D3; color: #fff; font-size: 1.35rem; line-height: 1;
  box-shadow: 0 6px 20px rgba(1,118,211,.35); display: flex; align-items: center; justify-content: center;
  transition: transform .12s, box-shadow .12s;
}
#qaw-fab:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(1,118,211,.45); }
#qaw-fab:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
#qaw-panel {
  position: fixed; right: 1.5rem; bottom: calc(<?= htmlspecialchars($_qa_bottom, ENT_QUOTES) ?> + 64px);
  z-index: 9081; width: min(380px, calc(100vw - 2rem)); max-height: min(560px, calc(100dvh - 8rem));
  background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; overflow: hidden;
  box-shadow: 0 18px 48px rgba(16,24,40,.18); display: none; flex-direction: column;
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
}
#qaw-panel.qaw-open { display: flex; }
@media (max-width: 575px) { #qaw-panel { right: .5rem; left: .5rem; width: auto; } }

.qaw-head { display: flex; align-items: center; gap: .5rem; padding: .7rem .9rem;
  background: #F8FAFC; border-bottom: 1px solid #EEF1F4; font-size: .9rem; font-weight: 700; color: #111827; }
.qaw-head .qaw-x { margin-left: auto; border: 0; background: transparent; color: #9CA3AF;
  font-size: 1.05rem; line-height: 1; cursor: pointer; padding: .15rem .3rem; border-radius: 6px; }
.qaw-head .qaw-x:hover { background: #EEF1F4; color: #111827; }
.qaw-body { padding: .8rem .9rem; overflow-y: auto; }

.qaw-tabs { display: flex; gap: 2px; padding: 3px; background: #F3F4F6; border-radius: 9px; margin-bottom: .7rem; }
.qaw-tab { flex: 1; border: 0; background: transparent; border-radius: 7px; padding: .3rem .2rem;
  font-size: .76rem; color: #6B7280; cursor: pointer; }
.qaw-tab:hover { color: #111827; }
.qaw-tab.is-on { background: #fff; color: #111827; font-weight: 600; box-shadow: 0 1px 2px rgba(16,24,40,.1); }

.qaw-lbl { display: block; font-size: .74rem; font-weight: 600; color: #374151; margin: 0 0 .25rem; }
.qaw-in {
  width: 100%; height: 34px; padding: 0 .6rem; font-size: .85rem; color: #111827;
  border: 1px solid #E5E7EB; border-radius: 8px; background: #fff; box-sizing: border-box;
}
.qaw-in:focus { border-color: #0176D3; box-shadow: 0 0 0 3px rgba(1,118,211,.12); outline: none; }
select.qaw-in { height: 34px; }
.qaw-row { margin-bottom: .6rem; }
.qaw-hint { font-size: .72rem; color: #9CA3AF; margin-top: .25rem; }
.qaw-err { display: none; font-size: .76rem; color: #B91C1C; background: #FEF2F2;
  border: 1px solid #FECACA; border-radius: 8px; padding: .35rem .5rem; margin-bottom: .6rem; }
.qaw-err.is-on { display: block; }

.qaw-hits { border: 1px solid #E5E7EB; border-radius: 8px; margin-top: .3rem; max-height: 150px; overflow-y: auto; }
.qaw-hits:empty { display: none; }
.qaw-hit { display: block; width: 100%; text-align: left; border: 0; background: #fff;
  padding: .35rem .55rem; font-size: .8rem; cursor: pointer; }
.qaw-hit:hover { background: #F3F4F6; }
.qaw-picked { font-size: .74rem; color: #2E844A; margin-top: .25rem; }

.qaw-btn { width: 100%; height: 36px; border: 0; border-radius: 9px; background: #0176D3; color: #fff;
  font-size: .85rem; font-weight: 600; cursor: pointer; }
.qaw-btn:hover { background: #0165B8; }
.qaw-btn[disabled] { opacity: .6; cursor: not-allowed; }

.qaw-links { border-top: 1px solid #EEF1F4; margin-top: .8rem; padding-top: .6rem; display: flex;
  flex-wrap: wrap; gap: .3rem; }
.qaw-link { display: inline-flex; align-items: center; gap: .3rem; padding: .25rem .55rem;
  border: 1px solid #E5E7EB; border-radius: 2rem; font-size: .76rem; color: #374151;
  text-decoration: none; background: #fff; cursor: pointer; }
.qaw-link:hover { background: #F3F4F6; color: #111827; }
.qaw-foot { border-top: 1px solid #EEF1F4; padding: .5rem .9rem; display: flex; align-items: center;
  gap: .4rem; font-size: .73rem; color: #9CA3AF; }
.qaw-foot button { border: 0; background: transparent; color: #6B7280; font-size: .73rem;
  cursor: pointer; padding: .1rem .3rem; border-radius: 6px; }
.qaw-foot button:hover { background: #F3F4F6; color: #111827; }
.qaw-kbd { font-family: ui-monospace, Menlo, monospace; font-size: .68rem; background: #F3F4F6;
  border: 1px solid #E5E7EB; border-radius: 4px; padding: 0 .25rem; }

.qaw-pick { display: flex; align-items: flex-start; gap: .5rem; padding: .35rem 0; font-size: .82rem; color: #111827; }
.qaw-pick input { margin-top: .2rem; }
.qaw-pick small { display: block; color: #9CA3AF; font-size: .72rem; }
</style>

<button id="qaw-fab" type="button" aria-label="Szybkie akcje (Alt+N)"
        title="Szybkie akcje — nowy kontakt, notatka, sprawa, zadanie (Alt+N)">+</button>

<div id="qaw-panel" role="dialog" aria-modal="false" aria-labelledby="qaw-title">
  <div class="qaw-head">
    <span id="qaw-title">Szybkie akcje</span>
    <button type="button" class="qaw-x" id="qaw-close" aria-label="Zamknij">✕</button>
  </div>

  <div class="qaw-body" id="qaw-body">
    <div class="qaw-tabs" id="qaw-tabs" role="tablist"></div>

    <div class="qaw-err" id="qaw-err" role="alert"></div>

    <div class="qaw-row">
      <label class="qaw-lbl" for="qaw-title-in" id="qaw-field-lbl">Nazwa</label>
      <input type="text" class="qaw-in" id="qaw-title-in" autocomplete="off">
    </div>

    <div class="qaw-row" id="qaw-email-row" hidden>
      <label class="qaw-lbl" for="qaw-email">E-mail (opcjonalnie)</label>
      <input type="email" class="qaw-in" id="qaw-email" autocomplete="off">
    </div>

    <div class="qaw-row" id="qaw-contact-row" hidden>
      <label class="qaw-lbl" for="qaw-contact">Kontakt</label>
      <input type="text" class="qaw-in" id="qaw-contact" placeholder="Zacznij pisać nazwę…" autocomplete="off">
      <div class="qaw-hits" id="qaw-hits"></div>
      <div class="qaw-picked" id="qaw-picked"></div>
    </div>

    <div class="qaw-row" id="qaw-list-row" hidden>
      <label class="qaw-lbl" for="qaw-list">Lista zadań</label>
      <select class="qaw-in" id="qaw-list"></select>
    </div>

    <button type="button" class="qaw-btn" id="qaw-save">Zapisz i otwórz</button>

    <?php
      $qa_links = array_filter($_qa_catalog, static fn($m) => $m['kind'] === 'link');
      if ($qa_links):
    ?>
    <div class="qaw-links">
      <?php foreach ($qa_links as $m): ?>
      <a class="qaw-link" href="<?= APP_URL . htmlspecialchars($m['href'], ENT_QUOTES) ?>"
         title="<?= htmlspecialchars($m['title'], ENT_QUOTES) ?>"><?= htmlspecialchars($m['label'], ENT_QUOTES) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Widok ustawień: które akcje mają być przypięte w paskach modułów -->
  <div class="qaw-body" id="qaw-config" hidden>
    <p class="qaw-hint" style="margin-top:0">
      Zaznaczone akcje są przyciskami w pasku CRM. Reszta zostaje tutaj i w menu „Nowe" —
      nic nie znika. Ustawienie jest Twoje i nie zmienia niczego innym.
    </p>
    <?php foreach ($_qa_catalog as $m): ?>
    <label class="qaw-pick">
      <input type="checkbox" class="qaw-pick-in" value="<?= htmlspecialchars($m['key'], ENT_QUOTES) ?>"
             <?= in_array($m['key'], $_qa_pinned, true) ? 'checked' : '' ?>>
      <span><?= htmlspecialchars($m['label'], ENT_QUOTES) ?>
        <small><?= htmlspecialchars($m['title'], ENT_QUOTES) ?></small>
      </span>
    </label>
    <?php endforeach; ?>
    <div class="qaw-err" id="qaw-cfg-err" role="alert"></div>
    <button type="button" class="qaw-btn" id="qaw-cfg-save" style="margin-top:.5rem">Zapisz układ przycisków</button>
  </div>

  <div class="qaw-foot">
    <span class="qaw-kbd">Alt</span>+<span class="qaw-kbd">N</span>
    <button type="button" id="qaw-cfg-toggle">⚙ Dostosuj przyciski</button>
  </div>
</div>

<script>
(function () {
  'use strict';
  var BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>;

  var TYPES = {
    contact: {label: 'Kontakt', field: 'Imię i nazwisko / nazwa', contact: false, email: true,  list: false},
    note:    {label: 'Notatka', field: 'Treść notatki',           contact: true,  email: false, list: false},
    'case':  {label: 'Sprawa',  field: 'Tytuł sprawy',            contact: true,  email: false, list: false},
    task:    {label: 'Zadanie', field: 'Co jest do zrobienia',    contact: false, email: false, list: true}
  };

  var fab   = document.getElementById('qaw-fab');
  var panel = document.getElementById('qaw-panel');
  var body  = document.getElementById('qaw-body');
  var cfg   = document.getElementById('qaw-config');
  var cur = 'contact', contactId = 0, listsLoaded = false, tmr = null;

  function el(id) { return document.getElementById(id); }
  function err(msg, node) {
    var e = node || el('qaw-err');
    e.textContent = msg || '';
    e.classList.toggle('is-on', !!msg);
  }

  function paintTabs() {
    el('qaw-tabs').innerHTML = Object.keys(TYPES).map(function (k) {
      return '<button type="button" role="tab" class="qaw-tab' + (k === cur ? ' is-on' : '') +
             '" data-t="' + k + '" aria-selected="' + (k === cur) + '">' + TYPES[k].label + '</button>';
    }).join('');
    el('qaw-tabs').querySelectorAll('.qaw-tab').forEach(function (b) {
      b.addEventListener('click', function () { setType(this.dataset.t); });
    });
  }

  function setType(t) {
    cur = TYPES[t] ? t : 'contact';
    var c = TYPES[cur];
    paintTabs();
    err('');
    el('qaw-field-lbl').textContent = c.field;
    el('qaw-title-in').placeholder  = c.field + '…';
    el('qaw-email-row').hidden   = !c.email;
    el('qaw-contact-row').hidden = !c.contact;
    el('qaw-list-row').hidden    = !c.list;
    if (c.list && !listsLoaded) loadLists();
    el('qaw-title-in').focus();
  }

  function loadLists() {
    fetch(BASE + '/crm/api/quick_create.php?a=task_lists')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        listsLoaded = true;
        var opts = (d.items || []).map(function (i) {
          return '<option value="' + i.list_id + '">' + String(i.label).replace(/</g, '&lt;') + '</option>';
        }).join('');
        el('qaw-list').innerHTML = opts || '<option value="">— brak list zadań —</option>';
      })
      .catch(function () { listsLoaded = true; });
  }

  el('qaw-contact').addEventListener('input', function () {
    contactId = 0; el('qaw-picked').textContent = '';
    var q = this.value.trim(), hits = el('qaw-hits');
    clearTimeout(tmr);
    if (q.length < 2) { hits.innerHTML = ''; return; }
    tmr = setTimeout(function () {
      fetch(BASE + '/crm/api/contacts_search.php?q=' + encodeURIComponent(q) + '&limit=8')
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          hits.innerHTML = (rows || []).map(function (c) {
            return '<button type="button" class="qaw-hit" data-id="' + c.id + '" data-name="' +
                   String(c.name).replace(/"/g, '&quot;') + '">' + String(c.name).replace(/</g, '&lt;') +
                   (c.email ? ' — ' + String(c.email).replace(/</g, '&lt;') : '') + '</button>';
          }).join('');
          hits.querySelectorAll('.qaw-hit').forEach(function (b) {
            b.addEventListener('click', function () {
              contactId = parseInt(this.dataset.id, 10);
              el('qaw-contact').value = this.dataset.name;
              el('qaw-picked').textContent = 'Wybrano: ' + this.dataset.name;
              hits.innerHTML = '';
            });
          });
        })
        .catch(function () {});
    }, 250);
  });

  function save() {
    var btn = el('qaw-save');
    var payload = {
      _csrf: CSRF, type: cur,
      title: el('qaw-title-in').value.trim(),
      email: el('qaw-email').value.trim(),
      contact_id: contactId,
      list_id: parseInt(el('qaw-list').value || '0', 10)
    };
    if (!payload.title) { err('Wpisz treść — bez tego nie ma czego zapisać.'); return; }

    btn.disabled = true; btn.textContent = 'Zapisuję…';
    fetch(BASE + '/crm/api/quick_create.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false; btn.textContent = 'Zapisz i otwórz';
        if (d.ok) { window.location.href = d.url; return; }
        err(d.error || 'Nie udało się zapisać.');
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Zapisz i otwórz';
        err('Błąd połączenia.');
      });
  }

  el('qaw-save').addEventListener('click', save);
  el('qaw-title-in').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); save(); }
  });

  // ── Ustawienia przypiętych akcji ─────────────────────────────────────────
  el('qaw-cfg-toggle').addEventListener('click', function () {
    var showCfg = cfg.hidden;
    cfg.hidden = !showCfg; body.hidden = showCfg;
    el('qaw-title').textContent = showCfg ? 'Twoje przyciski' : 'Szybkie akcje';
    this.textContent = showCfg ? '← Wróć' : '⚙ Dostosuj przyciski';
  });

  el('qaw-cfg-save').addEventListener('click', function () {
    var btn = this;
    var keys = Array.prototype.slice.call(document.querySelectorAll('.qaw-pick-in:checked'))
                    .map(function (c) { return c.value; });
    btn.disabled = true; btn.textContent = 'Zapisuję…';
    fetch(BASE + '/crm/api/macros.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_csrf: CSRF, keys: keys})
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false; btn.textContent = 'Zapisz układ przycisków';
        if (d.ok) { window.location.reload(); return; }
        err(d.error || 'Nie udało się zapisać.', el('qaw-cfg-err'));
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Zapisz układ przycisków';
        err('Błąd połączenia.', el('qaw-cfg-err'));
      });
  });

  // ── Otwieranie / zamykanie ───────────────────────────────────────────────
  function open(type) {
    cfg.hidden = true; body.hidden = false;
    el('qaw-cfg-toggle').textContent = '⚙ Dostosuj przyciski';
    el('qaw-title').textContent = 'Szybkie akcje';
    panel.classList.add('qaw-open');
    fab.setAttribute('aria-expanded', 'true');
    setType(type || cur);
    setTimeout(function () { el('qaw-title-in').focus(); }, 60);
  }
  function close() {
    panel.classList.remove('qaw-open');
    fab.setAttribute('aria-expanded', 'false');
  }
  function toggle() { panel.classList.contains('qaw-open') ? close() : open(); }

  fab.addEventListener('click', function () { toggle(); });
  el('qaw-close').addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    // Alt+N — wolny skrót (Ctrl+K zajmuje paleta poleceń, Ctrl+Shift+N przeglądarka)
    if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'n' || e.key === 'N')) {
      e.preventDefault(); toggle();
    }
    if (e.key === 'Escape' && panel.classList.contains('qaw-open')) { close(); fab.focus(); }
  });

  // Klik poza panelem zamyka — jak w asystencie
  document.addEventListener('click', function (e) {
    if (!panel.classList.contains('qaw-open')) return;
    if (panel.contains(e.target) || fab.contains(e.target)) return;
    if (e.target.closest('[data-quick-open]')) return;
    close();
  });

  // Zewnętrzne wyzwalacze: [data-quick-open] i [data-quick-open="task"]
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-quick-open]');
    if (!t) return;
    e.preventDefault();
    open(t.getAttribute('data-quick-open') || undefined);
  });

  window.QuickActions = {open: open, close: close, toggle: toggle};
  paintTabs();
})();
</script>
