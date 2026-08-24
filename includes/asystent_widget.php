<?php
/**
 * includes/asystent_widget.php — pływający asystent AI, wspólny dla wszystkich modułów.
 *
 * Dołączany do nagłówka każdego modułu (jak includes/bug_report_widget.php), więc
 * musi być samowystarczalny: własny CSS i JS, bez Bootstrapa, bez CDN. Rozmowa
 * idzie do api/asystent_ai.php w kontekście zalogowanego użytkownika, dzięki czemu
 * agent widzi funkcje dostępne dla jego roli i jego własne sprawy.
 *
 * Wymaga: current_user(), csrf_token(), APP_URL, includes/asystent_ai.php.
 *
 * Sterowanie z zewnątrz (przed include):
 *   $ASAI_WIDGET_BOTTOM — odstęp od dołu (domyślnie 1.5rem); podnieś, gdy strona
 *                         ma już inny pływający przycisk w prawym dolnym rogu,
 *   $ASAI_WIDGET_SCOPE  — nazwa modułu (trafia do audytu zapytań),
 *   $ASAI_WIDGET_INLINE — true: zamiast pływającego przycisku renderuje czat
 *                         w miejscu include'a (używa go panel/asystent.php).
 * W dowolnym miejscu strony:
 *   <button data-asai-open>…</button>              — otwiera asystenta,
 *   <button data-asai-ask="Jak wpisać godziny?">…  — otwiera i od razu pyta.
 */
if (defined('_ASAI_WIDGET_LOADED')) return;
// Ekran, który sam JEST asystentem (panel/asystent.php), wyłącza wersję pływającą —
// dwie kopie tej samej rozmowy na jednej stronie biłyby się o te same identyfikatory.
if (defined('ASAI_WIDGET_SUPPRESS') && empty($ASAI_WIDGET_INLINE)) return;
define('_ASAI_WIDGET_LOADED', true);

if (!function_exists('asai_widget_enabled')) {
    require_once __DIR__ . '/asystent_ai.php';
}
if (!function_exists('current_user') || !current_user()) return;
if (!asai_widget_enabled()) return;

$_asaiw_inline = !empty($ASAI_WIDGET_INLINE);
$_asaiw_bottom = $ASAI_WIDGET_BOTTOM ?? '1.5rem';
$_asaiw_scope  = (string)($ASAI_WIDGET_SCOPE ?? '');
$_asaiw_role   = (function_exists('is_admin') && is_admin()) ? 'admin'
               : ((function_exists('can_edit') && can_edit()) ? 'editor' : 'viewer');
$_asaiw_chips  = asai_suggestions($_asaiw_role, true);
?>
<style>
:root { --asaiw-accent: #6d28d9; }
#asaiw-fab {
  position: fixed; right: 1.5rem; bottom: <?= htmlspecialchars($_asaiw_bottom, ENT_QUOTES) ?>;
  z-index: 9080; width: 52px; height: 52px; border-radius: 50%; border: 0; cursor: pointer;
  background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff;
  display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
  box-shadow: 0 6px 22px rgba(79,70,229,.42); transition: transform .15s, box-shadow .15s;
}
#asaiw-fab:hover  { transform: scale(1.08); box-shadow: 0 8px 28px rgba(79,70,229,.55); }
#asaiw-fab:active { transform: scale(.96); }
#asaiw-fab:focus-visible { outline: 3px solid #a78bfa; outline-offset: 3px; }

#asaiw-panel {
  position: fixed; right: 1.5rem; bottom: calc(<?= htmlspecialchars($_asaiw_bottom, ENT_QUOTES) ?> + 64px);
  z-index: 9081; width: min(420px, calc(100vw - 2rem)); height: min(620px, calc(100dvh - 8rem));
  display: none; flex-direction: column; overflow: hidden;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
  box-shadow: 0 24px 70px -18px rgba(15,23,42,.38);
  font-size: .875rem; color: #1e293b;
}
#asaiw-panel.asaiw-open { display: flex; }
@media (max-width: 575.98px) {
  #asaiw-panel { right: .5rem; left: .5rem; width: auto; height: min(80dvh, 620px); }
}

.asaiw-hd {
  display: flex; align-items: center; gap: .55rem; padding: .7rem .85rem;
  background: linear-gradient(135deg, #f5f3ff, #eef2ff); border-bottom: 1px solid #e9e5ff;
}
.asaiw-hd .asaiw-ic {
  width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: #ede9fe; color: var(--asaiw-accent);
}
.asaiw-hd b { font-size: .88rem; font-weight: 700; display: block; line-height: 1.2; }
.asaiw-hd span { font-size: .72rem; color: #64748b; }
.asaiw-hd-btns { margin-left: auto; display: flex; gap: .25rem; }
.asaiw-hd-btns button, .asaiw-hd-btns a {
  border: 0; background: transparent; color: #64748b; cursor: pointer;
  width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center;
  text-decoration: none; font-size: .95rem;
}
.asaiw-hd-btns button:hover, .asaiw-hd-btns a:hover { background: #fff; color: var(--asaiw-accent); }

.asaiw-log { flex: 1; overflow-y: auto; padding: .85rem; }
.asaiw-msg { display: flex; gap: .5rem; margin-bottom: .9rem; }
.asaiw-msg .asaiw-av {
  width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: .85rem;
}
.asaiw-msg.ai   .asaiw-av { background: #ede9fe; color: var(--asaiw-accent); }
.asaiw-msg.user { flex-direction: row-reverse; }
.asaiw-msg.user .asaiw-av { background: #1e293b; color: #fff; }
.asaiw-bub { max-width: 84%; border-radius: 12px; padding: .55rem .75rem; line-height: 1.55; overflow-wrap: anywhere; }
.asaiw-msg.ai   .asaiw-bub { background: #f8fafc; border: 1px solid #eef2f7; }
.asaiw-msg.user .asaiw-bub { background: #1e293b; color: #f8fafc; }
.asaiw-bub > *:first-child { margin-top: 0; }
.asaiw-bub > *:last-child  { margin-bottom: 0; }
.asaiw-bub p  { margin: .35rem 0; }
.asaiw-bub ul, .asaiw-bub ol { margin: .35rem 0; padding-left: 1.15rem; }
.asaiw-bub li { margin-bottom: .15rem; }
.asaiw-bub h3 { font-size: .88rem; font-weight: 700; margin: .55rem 0 .25rem; }
.asaiw-bub code { background: #eef2f7; border-radius: 4px; padding: .1em .3em; font-size: .84em; color: #be185d; }
.asaiw-bub a { color: var(--asaiw-accent); }

.asaiw-src { margin-top: .55rem; display: flex; flex-wrap: wrap; gap: .3rem; }
.asaiw-src a {
  display: inline-flex; align-items: center; gap: .3rem; text-decoration: none;
  font-size: .72rem; padding: .22rem .55rem; border: 1px solid #e2e8f0; border-radius: 2rem;
  background: #fff; color: #334155;
}
.asaiw-src a:hover { border-color: var(--asaiw-accent); background: #f5f3ff; color: #5b21b6; }
.asaiw-steps {
  margin-top: .5rem; font-size: .71rem; color: #64748b; background: #f8fafc;
  border: 1px dashed #e2e8f0; border-radius: 8px; padding: .4rem .55rem;
}
.asaiw-steps div { padding: .08rem 0; }

.asaiw-chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .6rem; }
.asaiw-chip {
  font-size: .75rem; padding: .28rem .6rem; border: 1px solid #e2e8f0; border-radius: 2rem;
  background: #fff; color: #475569; cursor: pointer; text-align: left;
}
.asaiw-chip:hover { border-color: var(--asaiw-accent); background: #f5f3ff; color: #5b21b6; }

.asaiw-cp { border-top: 1px solid #eef2f7; padding: .6rem .7rem; background: #fcfcfd; }
.asaiw-field {
  display: flex; gap: .4rem; align-items: flex-end;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: .3rem .35rem .3rem .65rem;
}
.asaiw-field:focus-within { border-color: var(--asaiw-accent); }
.asaiw-field textarea {
  flex: 1; border: 0; outline: 0; background: transparent; resize: none; font: inherit;
  max-height: 110px; padding: .35rem 0; line-height: 1.45; color: #1e293b;
}
.asaiw-send {
  width: 34px; height: 34px; flex-shrink: 0; border: 0; border-radius: 9px; cursor: pointer;
  background: var(--asaiw-accent); color: #fff; display: flex; align-items: center; justify-content: center;
}
.asaiw-send:disabled { opacity: .45; cursor: default; }
.asaiw-foot { font-size: .66rem; color: #94a3b8; text-align: center; margin-top: .4rem; }
.asaiw-dots span {
  display: inline-block; width: 5px; height: 5px; margin: 0 1px; border-radius: 50%;
  background: var(--asaiw-accent); animation: asaiwB 1.2s infinite;
}
.asaiw-dots span:nth-child(2) { animation-delay: .15s; }
.asaiw-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes asaiwB { 0%,60%,100% { transform: translateY(0); opacity: .4 } 30% { transform: translateY(-3px); opacity: 1 } }

@media print { #asaiw-fab, #asaiw-panel { display: none !important; } }
<?php if ($_asaiw_inline): ?>
/* Tryb inline: ten sam czat, ale wpisany w stronę zamiast wisieć nad nią. */
#asaiw-panel {
  position: static; display: flex; right: auto; bottom: auto;
  width: 100%; max-width: 100%; height: min(70dvh, 680px);
  box-shadow: 0 1px 2px rgba(15,23,42,.05);
}
<?php endif; ?>
</style>

<?php if (!$_asaiw_inline): ?>
<button type="button" id="asaiw-fab" aria-haspopup="dialog" aria-expanded="false"
        aria-controls="asaiw-panel" title="Asystent AI — zapytaj o procedury i o system"
        aria-label="Asystent AI — zapytaj o procedury i o system">
  <i class="bi bi-stars" aria-hidden="true"></i>
</button>
<?php endif; ?>

<div id="asaiw-panel" <?= $_asaiw_inline ? 'class="asaiw-open"' : 'role="dialog" aria-modal="false"' ?>
     aria-label="Asystent AI">
  <div class="asaiw-hd">
    <span class="asaiw-ic"><i class="bi bi-stars" aria-hidden="true"></i></span>
    <span>
      <b>Asystent AI</b>
      <span>Procedury, dokumenty i obsługa SZO</span>
    </span>
    <span class="asaiw-hd-btns">
      <button type="button" id="asaiw-clear" title="Nowa rozmowa" aria-label="Nowa rozmowa">
        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
      </button>
      <?php if (!$_asaiw_inline): ?>
      <a href="<?= APP_URL ?>/panel/asystent.php" title="Otwórz w pełnym oknie" aria-label="Otwórz w pełnym oknie">
        <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i>
      </a>
      <button type="button" id="asaiw-close" title="Zamknij" aria-label="Zamknij asystenta">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
      <?php endif; ?>
    </span>
  </div>

  <div class="asaiw-log" id="asaiw-log" aria-live="polite" aria-atomic="false"></div>

  <div class="asaiw-cp">
    <form class="asaiw-field" id="asaiw-form">
      <textarea id="asaiw-input" rows="1" placeholder="O co chcesz zapytać?" autocomplete="off"
                aria-label="Pytanie do asystenta AI"></textarea>
      <button type="submit" class="asaiw-send" id="asaiw-send" aria-label="Wyślij pytanie">
        <i class="bi bi-send-fill" aria-hidden="true"></i>
      </button>
    </form>
    <div class="asaiw-foot">Odpowiedzi generuje AI — przy decyzjach formalnych sprawdź źródło.</div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var ENDPOINT = <?= json_encode(APP_URL . '/api/asystent_ai.php') ?>;
  var CSRF     = <?= json_encode(csrf_token()) ?>;
  var SCOPE    = <?= json_encode($_asaiw_scope) ?>;
  var CHIPS    = <?= json_encode(array_values($_asaiw_chips), JSON_UNESCAPED_UNICODE) ?>;
  var HKEY     = 'asaiwHistory';
  var INLINE   = <?= $_asaiw_inline ? 'true' : 'false' ?>;

  var fab   = document.getElementById('asaiw-fab');
  var panel = document.getElementById('asaiw-panel');
  var log   = document.getElementById('asaiw-log');
  var form  = document.getElementById('asaiw-form');
  var input = document.getElementById('asaiw-input');
  var send  = document.getElementById('asaiw-send');
  if (!panel || (!fab && !INLINE)) return;

  var history = [];
  var busy = false;

  // Rozmowa przeżywa przejście na inną stronę — pytanie zadane w CRM ma sens
  // także po wejściu do panelu, a przepisywanie go od nowa jest irytujące.
  try {
    var raw = sessionStorage.getItem(HKEY);
    if (raw) history = JSON.parse(raw) || [];
  } catch (e) { history = []; }
  function persist() {
    try { sessionStorage.setItem(HKEY, JSON.stringify(history.slice(-10))); } catch (e) {}
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Minimalny renderer Markdown — bez zależności zewnętrznych, bo widżet
  // wchodzi do nagłówków modułów, które nie ładują żadnej biblioteki md.
  function md(text) {
    var lines = String(text).split(/\r?\n/), out = [], list = null;
    function closeList() { if (list) { out.push('</' + list + '>'); list = null; } }
    function inline(t) {
      t = esc(t);
      t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
      t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
                    '<a href="$2" target="_blank" rel="noopener">$1</a>');
      t = t.replace(/(^|[\s(])((?:https?:\/\/)[^\s<)]+)/g,
                    '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
      return t;
    }
    lines.forEach(function (ln) {
      var m;
      if (/^\s*$/.test(ln)) { closeList(); return; }
      if ((m = ln.match(/^#{1,6}\s+(.*)$/)))      { closeList(); out.push('<h3>' + inline(m[1]) + '</h3>'); return; }
      if ((m = ln.match(/^\s*[-*•]\s+(.*)$/)))    { if (list !== 'ul') { closeList(); out.push('<ul>'); list = 'ul'; }
                                                    out.push('<li>' + inline(m[1]) + '</li>'); return; }
      if ((m = ln.match(/^\s*\d+[.)]\s+(.*)$/)))  { if (list !== 'ol') { closeList(); out.push('<ol>'); list = 'ol'; }
                                                    out.push('<li>' + inline(m[1]) + '</li>'); return; }
      closeList();
      out.push('<p>' + inline(ln) + '</p>');
    });
    closeList();
    return out.join('');
  }

  function down() { log.scrollTop = log.scrollHeight; }

  function bubble(kind, htmlOrText, isHtml) {
    var el = document.createElement('div');
    el.className = 'asaiw-msg ' + kind;
    var icon = kind === 'user' ? 'bi-person-fill' : 'bi-stars';
    el.innerHTML = '<span class="asaiw-av"><i class="bi ' + icon + '" aria-hidden="true"></i></span>' +
                   '<div class="asaiw-bub"></div>';
    var b = el.querySelector('.asaiw-bub');
    if (isHtml) b.innerHTML = htmlOrText; else b.textContent = htmlOrText;
    log.appendChild(el);
    down();
    return b;
  }

  function renderSources(box, sources) {
    if (!sources || !sources.length) return;
    var w = document.createElement('div');
    w.className = 'asaiw-src';
    sources.forEach(function (s) {
      var a = document.createElement('a');
      a.href = s.url || '#';
      a.target = '_blank';
      a.rel = 'noopener';
      a.innerHTML = '<i class="bi ' + esc(s.icon || 'bi-file-earmark') + '" aria-hidden="true"></i>' +
                    '<span>' + esc(s.title || '') + '</span>';
      a.title = (s.label || s.type || '') + ': ' + (s.title || '');
      w.appendChild(a);
    });
    box.appendChild(w);
  }

  function renderSteps(box, trace) {
    if (!trace || !trace.length) return;
    var d = document.createElement('div');
    d.className = 'asaiw-steps';
    var names = { szukaj: 'Szukał w bazie wiedzy', otworz: 'Otworzył', funkcje: 'Sprawdził funkcje systemu', moje: 'Sprawdził Twoje dane' };
    var html = '';
    trace.forEach(function (t) {
      html += '<div>' + esc(names[t.tool] || t.tool) + ': „' + esc(t.input) + '" — ' + esc(t.summary) + '</div>';
    });
    d.innerHTML = html;
    box.appendChild(d);
  }

  function greet() {
    var b = bubble('ai',
      'Zapytaj mnie o <strong>procedurę albo dokument organizacji</strong>, o to ' +
      '<strong>gdzie coś zrobić w SZO</strong>, albo o <strong>Twoje własne sprawy</strong> ' +
      '(godziny, zadania, wnioski).', true);
    if (CHIPS.length) {
      var w = document.createElement('div');
      w.className = 'asaiw-chips';
      CHIPS.forEach(function (c) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'asaiw-chip';
        btn.textContent = c;
        btn.addEventListener('click', function () { ask(c); });
        w.appendChild(btn);
      });
      b.appendChild(w);
    }
  }

  function replay() {
    log.innerHTML = '';
    if (!history.length) { greet(); return; }
    history.forEach(function (m) {
      if (m.role === 'user') bubble('user', m.text, false);
      else bubble('ai', md(m.text), true);
    });
  }

  function grow() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 110) + 'px'; }

  function ask(text) {
    if (busy) return;
    text = String(text || '').trim();
    if (!text) return;
    var chips = log.querySelector('.asaiw-chips');
    if (chips) chips.remove();

    busy = true; send.disabled = true;
    bubble('user', text, false);
    history.push({ role: 'user', text: text });
    persist();
    input.value = ''; grow();

    var box = bubble('ai',
      '<span style="color:#64748b">Sprawdzam bazę wiedzy i funkcje systemu… ' +
      '<span class="asaiw-dots"><span></span><span></span><span></span></span></span>', true);

    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ _csrf: CSRF, scope: SCOPE, history: history.slice(-10) })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          box.innerHTML = '<span style="color:#dc2626"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' +
                          esc(data.error || 'Wystąpił błąd.') + '</span>';
          renderSteps(box, data.trace);
          return;
        }
        box.innerHTML = md(data.answer || '(brak odpowiedzi)');
        renderSources(box, data.sources);
        renderSteps(box, data.trace);
        history.push({ role: 'assistant', text: data.answer || '' });
        persist();
      })
      .catch(function () {
        box.innerHTML = '<span style="color:#dc2626"><i class="bi bi-wifi-off" aria-hidden="true"></i> ' +
                        'Błąd połączenia. Spróbuj ponownie.</span>';
      })
      .finally(function () {
        busy = false; send.disabled = false; down(); input.focus();
      });
  }

  function open(prefill) {
    panel.classList.add('asaiw-open');
    if (fab) {
      fab.setAttribute('aria-expanded', 'true');
      fab.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    }
    if (!log.childElementCount) replay();
    down();
    if (prefill) { input.value = prefill; grow(); }
    input.focus();
  }
  function close() {
    if (INLINE) return;                       // wpisany w stronę — nie ma czego zamykać
    panel.classList.remove('asaiw-open');
    fab.setAttribute('aria-expanded', 'false');
    fab.innerHTML = '<i class="bi bi-stars" aria-hidden="true"></i>';
  }
  function toggle() { panel.classList.contains('asaiw-open') ? close() : open(); }

  if (fab) fab.addEventListener('click', toggle);
  var closeBtn = document.getElementById('asaiw-close');
  if (closeBtn) closeBtn.addEventListener('click', function () { close(); if (fab) fab.focus(); });
  document.getElementById('asaiw-clear').addEventListener('click', function () {
    history = []; persist(); replay(); input.focus();
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', grow);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
  });
  document.addEventListener('keydown', function (e) {
    if (!INLINE && e.key === 'Escape' && panel.classList.contains('asaiw-open')) { close(); fab.focus(); }
  });

  if (INLINE) replay();

  // Zewnętrzne wyzwalacze: [data-asai-open] i [data-asai-ask="pytanie"].
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-asai-open],[data-asai-ask]');
    if (!t) return;
    e.preventDefault();
    var q = t.getAttribute('data-asai-ask');
    open();
    if (q) ask(q);
  });
})();
</script>
