<?php
/**
 * modules/ti/view/portal/_chat.php — okno czatu przy zgłoszeniu (konto kandydata i panel kierownika).
 * Odświeżanie co 5 s (tylko gdy karta jest widoczna), wysyłka bez przeładowania, Enter wysyła, Shift+Enter — nowy wiersz.
 * Dostępność: dziennik role="log" z aria-live, etykiety, komunikaty błędów w aria-live, czytelne „Ty” / nadawca / godzina.
 * Wejście: $chatUrl (GET ?after= / POST body), $chatCsrfField (nazwa pola), $chatCsrf (token), $chatTitle, $chatPeer (np. „organizatorem”),
 *          $chatDisabled (string z powodem albo '').
 */
$chatId = 'ch' . substr(md5($chatUrl), 0, 6);
?>
<section class="nb-chat" id="czat" aria-labelledby="<?= $chatId ?>-h">
  <h2 id="<?= $chatId ?>-h" class="nb-chat-h"><i class="bi bi-chat-dots" aria-hidden="true"></i> <?= h($chatTitle) ?></h2>
  <div class="nb-chat-log" id="<?= $chatId ?>-log" role="log" aria-live="polite" aria-label="Wiadomości czatu" tabindex="0">
    <p class="nb-chat-empty" id="<?= $chatId ?>-empty">Brak wiadomości. Napisz — odpowiedź pojawi się tutaj bez odświeżania strony.</p>
  </div>
  <?php if (!empty($chatDisabled)): ?>
    <p class="nb-chat-info"><?= h($chatDisabled) ?></p>
  <?php else: ?>
  <form class="nb-chat-form" id="<?= $chatId ?>-form">
    <label for="<?= $chatId ?>-in" class="nb-chat-lbl">Wiadomość do rozmowy z <?= h($chatPeer) ?></label>
    <div class="nb-chat-row">
      <textarea id="<?= $chatId ?>-in" rows="2" maxlength="5000" required aria-describedby="<?= $chatId ?>-hint"></textarea>
      <button type="submit" class="nb-chat-send"><i class="bi bi-send" aria-hidden="true"></i> Wyślij</button>
    </div>
    <p id="<?= $chatId ?>-hint" class="nb-chat-hint">Enter — wyślij, Shift + Enter — nowy wiersz.</p>
    <p class="nb-chat-err" id="<?= $chatId ?>-err" role="alert"></p>
  </form>
  <?php endif; ?>
</section>
<style>
  .nb-chat { border: 1px solid #c8ccd2; background: #fff; padding: 1rem 1.1rem; margin: 1.25rem 0; }
  .nb-chat-h { font-size: 1.15rem; font-weight: 600; margin: 0 0 .7rem; display: flex; gap: .5rem; align-items: center; }
  .nb-chat-log { max-height: 22rem; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; padding: .3rem .1rem; }
  .nb-chat-msg { max-width: 82%; padding: .55rem .75rem; border: 1px solid #dde1e6; background: #f3f4f6; }
  .nb-chat-msg.mine { margin-left: auto; background: #e6f0f7; border-color: #b9d3e6; }
  .nb-chat-meta { font-size: .78rem; color: #4b5563; margin-bottom: .15rem; }
  .nb-chat-body { white-space: pre-line; word-wrap: break-word; }
  .nb-chat-empty, .nb-chat-info, .nb-chat-hint { color: #4b5563; font-size: .9rem; margin: .2rem 0; }
  .nb-chat-lbl { display: block; font-weight: 600; margin: .8rem 0 .3rem; }
  .nb-chat-row { display: flex; gap: .5rem; align-items: stretch; }
  .nb-chat-row textarea { flex: 1; border: 2px solid #8a9099; border-radius: 0; padding: .55rem .7rem; font: inherit; resize: vertical; min-height: 3rem; }
  .nb-chat-send { border: 0; border-radius: 0; background: #0f6fa8; color: #fff; font: inherit; font-weight: 600; padding: 0 1.1rem; min-height: 48px; cursor: pointer; }
  .nb-chat-send:hover { background: #0c5b8a; } .nb-chat-send:disabled { opacity: .6; }
  .nb-chat-err { color: #b91c1c; font-size: .9rem; margin: .3rem 0 0; min-height: 1em; }
  html.pp-contrast .nb-chat-msg.mine { border-width: 2px !important; }
</style>
<script>
(function () {
  var id = <?= json_encode($chatId) ?>, url = <?= json_encode($chatUrl) ?>, csrfName = <?= json_encode($chatCsrfField) ?>, csrf = <?= json_encode($chatCsrf) ?>;
  var log = document.getElementById(id + '-log'), empty = document.getElementById(id + '-empty');
  var form = document.getElementById(id + '-form'), input = document.getElementById(id + '-in'), err = document.getElementById(id + '-err');
  var last = 0, busy = false;
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function add(list) {
    if (!list || !list.length) return;
    var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
    list.forEach(function (m) {
      if (m.id <= last) return;
      last = m.id;
      if (empty) { empty.remove(); empty = null; }
      var el = document.createElement('div');
      el.className = 'nb-chat-msg' + (m.mine ? ' mine' : '');
      el.innerHTML = '<div class="nb-chat-meta">' + esc(m.who) + ' · ' + esc(m.at) + '</div><div class="nb-chat-body">' + esc(m.body) + '</div>';
      log.appendChild(el);
    });
    if (atBottom || list.some(function (m) { return m.mine; })) log.scrollTop = log.scrollHeight;
  }
  function poll() {
    if (busy || document.hidden) return;
    fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'after=' + last, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) add(j.msgs); }).catch(function () {});
  }
  poll(); setInterval(poll, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  if (!form) return;
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); } });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = input.value.trim(); if (!body) return;
    var fd = new FormData(); fd.append(csrfName, csrf); fd.append('body', body); fd.append('after', last);
    busy = true; form.querySelector('button').disabled = true; err.textContent = '';
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) { input.value = ''; add(j.msgs); } else { err.textContent = (j && j.error) || 'Nie udało się wysłać.'; } })
      .catch(function () { err.textContent = 'Błąd połączenia — spróbuj ponownie.'; })
      .finally(function () { busy = false; form.querySelector('button').disabled = false; input.focus(); });
  });
})();
</script>
