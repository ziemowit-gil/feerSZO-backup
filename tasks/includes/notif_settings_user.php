<?php
/**
 * tasks/includes/notif_settings_user.php — ustawienia powiadomień użytkownika (okno w module Zadania
 * i strona notification_settings.php). Układ: przyklejony pasek (szybkie ustawienia, stan zapisu, „Zapisz”),
 * po lewej o czym powiadamiać, po prawej adres, tryb wysyłki, SMS, test.
 *
 * Zapis: fetch → notification_settings.php?_fragment=1 (JSON), komunikat w pasku (widoczny niezależnie od
 * przewinięcia), przełączniki zapisują się same po zmianie. Pola telefonu należą do osobnego formularza
 * #ns-phone-form (atrybut form=) — formularzy NIE zagnieżdżamy.
 * Wymaga: $pref, $user, $csrf, $notify_to, $has_mail, $has_sms, $is_fragment, $_ns_leader.
 */
$ns_on = fn(string $k) => !empty($pref[$k]);
$ns_groups = [
    'Praca w zadaniach' => [
        ['notify_assigned',  'Przypisanie do zadania', 'Ktoś przypisał Cię do zadania.'],
        ['notify_mentioned', 'Wzmianka @',             'Ktoś wspomniał Cię w komentarzu.'],
        ['notify_comment',   'Nowy komentarz',         'W zadaniach, do których jesteś przypisany/a.'],
        ['notify_file',      'Nowy plik',              'W Twoich zadaniach (najwyżej 1 e-mail dziennie na zadanie).'],
        ['notify_moved',     'Zmiana statusu',         'Twoje zadanie trafiło do innej kolumny.'],
        ['notify_watched',   'Obserwowane zadania',    'Komentarze, pliki i statusy zadań, które obserwujesz.'],
        ['notify_confirmed', 'Potwierdzenie wykonania','Lider potwierdził wykonanie Twojego zadania.'],
        ['notify_rejected',  'Odrzucenie wykonania',   'Lider odrzucił wykonanie i podał powód.'],
    ],
    'Terminy' => [
        ['notify_due_1day',  'Termin jutro',     'Dzień przed terminem, wieczorem.'],
        ['notify_due_today', 'Termin dzisiaj',   'Rano w dniu terminu.'],
        ['notify_due_soon',  'Termin za godzinę','Gdy zadanie ma godzinę terminu.'],
    ],
];
$ns_email_val = ($pref['notify_email_pending'] ?? '') !== '' ? $pref['notify_email_pending'] : ($pref['notify_email'] ?? '');
$ns_action = APP_URL . '/tasks/notification_settings.php?_fragment=1';
?>
<style>
.nsu{max-width:1100px;margin:0 auto;font-size:.9rem;color:#111827}
.nsu-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;background:#1d1d1d;color:#fff;padding:.6rem .75rem;margin-bottom:1rem}
.nsu-bar-l{font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.7;margin-right:.25rem}
.nsu-preset{border:0;background:rgba(255,255,255,.12);color:#fff;font-size:.8rem;font-weight:600;padding:.35rem .7rem;cursor:pointer}
.nsu-preset:hover,.nsu-preset:focus-visible{background:#007a00;outline:0}
.nsu-status{flex:1 1 12rem;text-align:right;font-size:.82rem;min-height:1.2em}
.nsu-status.is-ok{color:#9be29b}.nsu-status.is-err{color:#ffb4b4}.nsu-status.is-busy{color:#d4d4d4}
.nsu-save{border:0;background:#007a00;color:#fff;font-weight:600;font-size:.88rem;padding:.45rem 1rem;cursor:pointer}
.nsu-save:hover,.nsu-save:focus-visible{background:#fff;color:#1d1d1d;outline:0}
.nsu-save:disabled{opacity:.6;cursor:wait}
.nsu-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:1.25rem;align-items:start}
@media(max-width:860px){.nsu-grid{grid-template-columns:minmax(0,1fr)}}
.nsu-h{font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .4rem}
.nsu-list{list-style:none;margin:0 0 1.25rem;padding:0;border-top:2px solid #111827}
.nsu-item{display:flex;align-items:center;gap:.75rem;padding:.55rem 0;border-bottom:1px solid #ececec}
.nsu-item label{flex:1;min-width:0;cursor:pointer;margin:0}
.nsu-item b{display:block;font-weight:500}
.nsu-item small{display:block;color:#5f5f5f;font-size:.76rem}
.nsu .form-switch .form-check-input{width:2.4em;height:1.3em;cursor:pointer;margin:0}
.nsu .form-check-input:checked{background-color:#007a00;border-color:#007a00}
.nsu .form-check-input:focus{box-shadow:0 0 0 3px rgba(0,122,0,.2)}
.nsu-box{background:#f2f2f2;padding:.85rem 1rem;margin-bottom:.75rem}
.nsu-box .nsu-h{margin-bottom:.55rem}
.nsu-in{width:100%;border:2px solid #8a8a8a;background:#fff;padding:.4rem .55rem;font:inherit;font-size:.88rem;border-radius:0}
.nsu-in:focus{outline:0;border-color:#007a00}
.nsu-note{font-size:.76rem;color:#5f5f5f;margin:.35rem 0 0}
.nsu-note.is-warn{color:#8a4b00}.nsu-note.is-ok{color:#007a00}
.nsu-radio{display:flex;gap:.5rem;align-items:flex-start;padding:.3rem 0;cursor:pointer}
.nsu-radio input{margin-top:.25rem}
.nsu-radio small{display:block;color:#5f5f5f;font-size:.76rem}
.nsu-row{display:flex;gap:.5rem;align-items:center}
.nsu-btn{border:2px solid #111827;background:#fff;color:#111827;font-size:.8rem;font-weight:600;padding:.3rem .65rem;cursor:pointer}
.nsu-btn:hover,.nsu-btn:focus-visible{background:#111827;color:#fff;outline:0}
.nsu-link{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;background:#1d1d1d;color:#fff;text-decoration:none;margin-bottom:.75rem}
.nsu-link:hover,.nsu-link:focus-visible{background:#007a00;color:#fff}
.nsu-link small{display:block;opacity:.75}
</style>

<div class="nsu" id="nsu">
  <form method="post" id="notifForm" action="<?= h($ns_action) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="nsu-bar" role="toolbar" aria-label="Szybkie ustawienia i zapis">
      <span class="nsu-bar-l">Szybko</span>
      <button type="button" class="nsu-preset" data-preset="all">Wszystko</button>
      <button type="button" class="nsu-preset" data-preset="important">Tylko ważne</button>
      <button type="button" class="nsu-preset" data-preset="deadlines">Tylko terminy</button>
      <button type="button" class="nsu-preset" data-preset="none">Nic</button>
      <span class="nsu-status" id="nsu-status" role="status" aria-live="polite"></span>
      <button type="submit" class="nsu-save" id="nsu-save"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
    </div>

    <div class="nsu-grid">
      <div>
        <?php foreach ($ns_groups as $g => $items): ?>
        <h3 class="nsu-h"><?= h($g) ?> — e-mail</h3>
        <ul class="nsu-list">
          <?php foreach ($items as [$k, $t, $d]): ?>
          <li class="nsu-item">
            <label for="<?= $k ?>"><b><?= h($t) ?></b><small><?= h($d) ?></small></label>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input nsu-auto" type="checkbox" role="switch" id="<?= $k ?>" name="<?= $k ?>" <?= $ns_on($k) ? 'checked' : '' ?>>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
      </div>

      <div>
        <div class="nsu-box">
          <h3 class="nsu-h">Dokąd</h3>
          <label for="notify_email" class="small d-block mb-1">Adres powiadomień (puste = <?= h($user['email'] ?: 'adres z konta') ?>)</label>
          <input type="email" class="nsu-in" id="notify_email" name="notify_email" maxlength="254" autocomplete="email"
                 value="<?= h($ns_email_val) ?>" placeholder="<?= h($user['email'] ?: 'np. imie@example.org') ?>" aria-describedby="notify_email_state">
          <?php if (($pref['notify_email_pending'] ?? '') !== ''): ?>
          <p class="nsu-note is-warn" id="notify_email_state">Czeka na potwierdzenie linkiem wysłanym na <strong><?= h($pref['notify_email_pending']) ?></strong>.
            Do tego czasu powiadomienia idą na <?= h($notify_to ?: '—') ?>.</p>
          <?php elseif (($pref['notify_email'] ?? '') !== '' && !empty($pref['notify_email_verified_at'])): ?>
          <p class="nsu-note is-ok" id="notify_email_state">Adres potwierdzony.</p>
          <?php else: ?>
          <p class="nsu-note" id="notify_email_state"><?= $has_mail ? 'Powiadomienia idą na ' . h($notify_to) . '.' : 'Brak adresu e-mail — powiadomienia nie będą wysyłane.' ?>
            Nowy adres trzeba potwierdzić linkiem.</p>
          <?php endif; ?>
        </div>

        <fieldset class="nsu-box" style="border:0;margin:0 0 .75rem;min-width:0">
          <legend class="nsu-h" style="float:none;width:auto">Jak często</legend>
          <?php $digest_on = !empty($pref['notify_digest']); ?>
          <label class="nsu-radio"><input type="radio" class="nsu-auto" name="notify_digest" value="0" <?= $digest_on ? '' : 'checked' ?>>
            <span>Od razu<small>Osobny e-mail przy każdym zdarzeniu.</small></span></label>
          <label class="nsu-radio"><input type="radio" class="nsu-auto" name="notify_digest" value="1" <?= $digest_on ? 'checked' : '' ?>>
            <span>Raz dziennie<small>Podsumowanie po południu; terminy nadal od razu.</small></span></label>
        </fieldset>

        <div class="nsu-box">
          <h3 class="nsu-h">SMS</h3>
          <?php if (!sms_is_enabled()): ?>
          <p class="nsu-note mt-0">SMS-y nie są skonfigurowane przez administratora.</p>
          <?php else: ?>
          <div class="nsu-item" style="border:0;padding-top:0">
            <label for="notify_sms"><b>Powiadomienia SMS</b><small>Przypisanie, komentarze i terminy.<?= $has_sms ? '' : ' Najpierw podaj numer.' ?></small></label>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input nsu-auto" type="checkbox" role="switch" id="notify_sms" name="notify_sms"
                     <?= $ns_on('notify_sms') ? 'checked' : '' ?> <?= $has_sms ? '' : 'disabled' ?>>
            </div>
          </div>
          <label for="ns-phone-input" class="small d-block mb-1">Numer telefonu</label>
          <div class="nsu-row">
            <input type="tel" class="nsu-in" id="ns-phone-input" name="phone_number" form="ns-phone-form"
                   value="<?= h($user['phone_number'] ?? '') ?>" placeholder="np. +48600123456">
            <button type="submit" class="nsu-btn" form="ns-phone-form">Zapisz numer</button>
          </div>
          <?php endif; ?>
        </div>

        <div class="nsu-box">
          <h3 class="nsu-h">Sprawdź, czy dochodzi</h3>
          <div class="nsu-row" style="flex-wrap:wrap">
            <button type="button" class="nsu-btn nsu-test" data-ch="email" <?= $has_mail ? '' : 'disabled' ?>>E-mail</button>
            <?php if (sms_is_enabled()): ?><button type="button" class="nsu-btn nsu-test" data-ch="sms" <?= $has_sms ? '' : 'disabled' ?>>SMS</button><?php endif; ?>
            <button type="button" class="nsu-btn nsu-test" data-ch="inapp">Dzwonek</button>
          </div>
        </div>

        <?php if ($_ns_leader): ?>
        <a class="nsu-link" href="<?= APP_URL ?>/tasks/notification_settings.php?view=admin">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <span>Funkcje lidera i administratora<small>Status powiadomień członków, test dla innej osoby<?= is_admin() ? ', odpowiedzi e-mailem' : '' ?></small></span>
          <i class="bi bi-chevron-right ms-auto" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
        <p class="nsu-note">Każde zdarzenie najwyżej raz dziennie. Powiadomienia w dzwonku działają zawsze.</p>
      </div>
    </div>
  </form>

  <?php if (sms_is_enabled()): ?>
  <form id="ns-phone-form" method="post" action="<?= h($ns_action) ?>" novalidate hidden>
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="_action" value="save_phone">
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var root = document.getElementById('nsu');
  if (!root || root.dataset.wired) return;
  root.dataset.wired = '1';
  var form = document.getElementById('notifForm');
  var status = document.getElementById('nsu-status');
  var saveBtn = document.getElementById('nsu-save');
  var URL_ = form.getAttribute('action');
  var PRESETS = {
    all:       ['notify_assigned','notify_mentioned','notify_comment','notify_file','notify_moved','notify_watched','notify_confirmed','notify_rejected','notify_due_1day','notify_due_today','notify_due_soon'],
    important: ['notify_assigned','notify_mentioned','notify_confirmed','notify_rejected','notify_due_1day','notify_due_today','notify_due_soon'],
    deadlines: ['notify_due_1day','notify_due_today','notify_due_soon'],
    none:      []
  };
  var timer = null;
  function say(msg, cls) { status.textContent = msg; status.className = 'nsu-status' + (cls ? ' is-' + cls : ''); }
  function post(fd, label) {
    say(label || 'Zapisuję…', 'busy');
    saveBtn.disabled = true;
    return fetch(URL_, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.disabled = false;
        say(d.msg || (d.ok ? 'Zapisano.' : 'Nie udało się zapisać.'), d.ok ? (d.warn ? 'err' : 'ok') : 'err');
        if (d.ok) {
          var b = document.getElementById('tsk-setup-banner'); if (b) b.remove();
        }
        return d;
      })
      .catch(function () { saveBtn.disabled = false; say('Błąd połączenia — nie zapisano. Spróbuj ponownie.', 'err'); return {ok: false}; });
  }
  function saveAll(label) { clearTimeout(timer); return post(new FormData(form), label); }
  form.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); saveAll(); });
  root.querySelectorAll('.nsu-auto').forEach(function (el) {
    el.addEventListener('change', function () { clearTimeout(timer); say('Zmiany zapiszą się za chwilę…', 'busy'); timer = setTimeout(saveAll, 700); });
  });
  root.querySelectorAll('.nsu-preset').forEach(function (b) {
    b.addEventListener('click', function () {
      var on = PRESETS[b.dataset.preset] || [];
      PRESETS.all.forEach(function (k) { var el = document.getElementById(k); if (el) el.checked = on.indexOf(k) >= 0; });
      saveAll();
    });
  });
  var pf = document.getElementById('ns-phone-form');
  if (pf) pf.addEventListener('submit', function (e) {
    e.preventDefault(); e.stopPropagation();
    post(new FormData(pf), 'Zapisuję numer…').then(function (d) {
      var sms = document.getElementById('notify_sms');
      if (d.ok && sms) sms.disabled = !(document.getElementById('ns-phone-input').value.trim());
    });
  });
  root.querySelectorAll('.nsu-test').forEach(function (b) {
    b.addEventListener('click', function () {
      var fd = new FormData();
      fd.set('_csrf', form.querySelector('[name=_csrf]').value);
      fd.set('_action', 'test_notif');
      fd.set('test_channel', b.dataset.ch);
      post(fd, 'Wysyłam test…');
    });
  });
})();
</script>
