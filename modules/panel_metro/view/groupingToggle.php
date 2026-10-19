<?php
/**
 * modules/panel_metro/view/groupingToggle.php — przełącznik „Bez grupowania” | „Grupuj wg funkcji”.
 * Wejście: $grouped, $boardId. Dwa przyciski z aria-pressed w role="group"; komunikat o zmianie
 * w regionie role="status". Bez JS formularz idzie zwykłym POST-em (powrót na tę samą stronę).
 */
$_gtUrl = APP_URL . '/modules/panel_metro/router.php/uklad';
?>
<style>
.pvm .mt-seg{margin:0}
.pvm .mt-seg__grp{display:inline-flex;flex-wrap:wrap;border:2px solid var(--pvm-ink)}
.pvm .mt-seg__btn{display:inline-flex;align-items:center;gap:.45rem;min-height:44px;padding:.45rem .95rem;border:0;border-radius:0;background:transparent;color:var(--pvm-ink);font:inherit;font-size:.85rem;font-weight:600;cursor:pointer}
.pvm .mt-seg__btn:not([aria-pressed="true"]):hover{background:var(--pvm-light-h)}
.pvm .mt-seg__btn[aria-pressed="true"]{background:var(--pvm-acc);color:var(--pvm-acc-on);cursor:default}
.pvm .mt-seg__btn:focus-visible{outline:3px solid #fbbf24;outline-offset:-3px}
:root[data-theme="hc"] .pvm .mt-seg__btn[aria-pressed="true"]{background:#000;color:#fff}
@media(max-width:480px){.pvm .mt-seg__btn{padding:.45rem .65rem;font-size:.8rem}}
</style>
<form method="post" action="<?= h($_gtUrl) ?>" class="mt-seg" data-pvm-group-toggle="<?= h($boardId) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= h((string)($_SERVER['REQUEST_URI'] ?? '')) ?>">
  <div class="mt-seg__grp" role="group" aria-label="Układ kafli">
    <button type="submit" name="grouped" value="0" class="mt-seg__btn" aria-controls="<?= h($boardId) ?>" aria-pressed="<?= $grouped ? 'false' : 'true' ?>">
      <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Bez grupowania
    </button>
    <button type="submit" name="grouped" value="1" class="mt-seg__btn" aria-controls="<?= h($boardId) ?>" aria-pressed="<?= $grouped ? 'true' : 'false' ?>">
      <i class="bi bi-collection" aria-hidden="true"></i>Grupuj wg funkcji
    </button>
  </div>
  <span class="visually-hidden" role="status" aria-live="polite" data-pvm-group-status></span>
</form>
<script>
(function () {
  var form = document.currentScript && document.currentScript.previousElementSibling;
  if (!form || !form.matches('form[data-pvm-group-toggle]') || !window.fetch || !window.FormData) return;
  var board  = document.getElementById(form.getAttribute('data-pvm-group-toggle'));
  var status = form.querySelector('[data-pvm-group-status]');
  if (!board) return;
  var groups = {};
  try { groups = JSON.parse(board.getAttribute('data-pvm-groups') || '{}'); } catch (e) {}

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }
  /* Przekłada istniejące kafle (te same węzły — liczniki zostają) w sekcje albo jedną siatkę. */
  function layout(on) {
    var tiles = [].slice.call(board.querySelectorAll('.mt-tile')).sort(function (a, b) {
      return (+a.getAttribute('data-pvm-ord')) - (+b.getAttribute('data-pvm-ord'));
    });
    board.textContent = '';
    if (!on) {
      board.appendChild(el('h2', 'visually-hidden', 'Skróty'));
      var grid = el('div', 'mt-grid');
      tiles.forEach(function (t) { grid.appendChild(t); });
      board.appendChild(grid);
      return;
    }
    Object.keys(groups).forEach(function (key) {
      var list = tiles.filter(function (t) { return t.getAttribute('data-pvm-group') === key; });
      if (!list.length) return;
      var id  = board.id + '-' + key;
      var sec = el('section', 'mt-sec');
      sec.setAttribute('aria-labelledby', id);
      var h = el('h2', 'mt-sec__h', groups[key]);
      h.id = id;
      var grid = el('div', 'mt-grid');
      list.forEach(function (t) { grid.appendChild(t); });
      sec.appendChild(h); sec.appendChild(grid);
      board.appendChild(sec);
    });
  }
  function setPressed(on) {
    form.querySelectorAll('button[name="grouped"]').forEach(function (b) {
      b.setAttribute('aria-pressed', String((b.value === '1') === on));
    });
  }

  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!btn || btn.name !== 'grouped') return; // stara przeglądarka — zwykły POST
    e.preventDefault();
    if (btn.getAttribute('aria-pressed') === 'true') return;
    var on = btn.value === '1';
    layout(on); setPressed(on);
    status.textContent = on ? 'Kafle pogrupowane wg funkcji.' : 'Kafle bez grupowania.';
    var fd = new FormData(form);
    fd.set('grouped', on ? '1' : '0');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); })
      .catch(function () {
        layout(!on); setPressed(!on);
        status.textContent = 'Nie udało się zapisać układu kafli. Odśwież stronę i spróbuj ponownie.';
      });
  });
})();
</script>
