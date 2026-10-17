<?php
/**
 * modules/ti/view/nabor/public/rejestracja.php — samodzielne założenie konta kandydata (imię i nazwisko, e-mail,
 * telefon z kodem SMS, hasło, zgoda) → link aktywacyjny e-mail. Po zapisie ($done) — informacja o linku.
 * Zmienne: $errors, $old, $done, $verified, $smsUrl, $csrf, $org, $acc, $form. Kontroler: NaborCandidateController::register*.
 */
$title = 'Załóż konto kandydata';
include __DIR__ . '/_top.php';
$o = fn($k) => h((string)($old[$k] ?? ''));
?>
<h1 class="pp-h1">Załóż konto kandydata</h1>
<?php if ($done): ?>
  <div class="pp-ok" role="status"><strong>Prawie gotowe.</strong> Na adres <?= $o('email') ?: 'podany w formularzu' ?> wysłaliśmy link aktywacyjny (ważny 48 godzin). Kliknij go, żeby potwierdzić e-mail i zalogować się. Sprawdź też folder SPAM.</div>
  <p><a href="<?= h(naborUrl('/logowanie')) ?>">← Logowanie</a></p>
<?php else: ?>
<?php if ($errors): ?><div class="pp-error" role="alert"><strong>Popraw formularz:</strong><ul style="margin:.4rem 0 0 1.2rem;padding:0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="pp-cols">
  <form method="post" action="<?= h(naborUrl('/rejestracja')) ?>" id="rg-form" novalidate>
    <?= Router::csrfField() ?>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Strona www <input name="www" tabindex="-1" autocomplete="off"></label></div>
    <div class="pp-field"><label for="rg-n">Imię i nazwisko (rodzic / opiekun)</label><input id="rg-n" name="name" class="pp-input" value="<?= $o('name') ?>" autocomplete="name" required></div>
    <div class="pp-field"><label for="rg-e">E-mail (będzie loginem)</label><input id="rg-e" type="email" name="email" class="pp-input" value="<?= $o('email') ?>" autocomplete="email" required></div>
    <div class="pp-field">
      <label for="rg-p">Telefon komórkowy</label>
      <div style="display:flex;gap:.5rem">
        <input id="rg-p" type="tel" name="phone" class="pp-input" value="<?= $o('phone') ?>" autocomplete="tel" inputmode="tel" required>
        <button type="button" class="pp-submit" id="rg-send" style="min-width:auto;white-space:nowrap">Wyślij kod SMS</button>
      </div>
      <p id="rg-ok" class="pp-note" style="color:#2e8b57;font-weight:600;<?= $verified ? '' : 'display:none' ?>" role="status">✓ Numer potwierdzony</p>
      <div id="rg-codebox" style="display:none;margin-top:.6rem">
        <label for="rg-c">Kod z SMS-a</label>
        <div style="display:flex;gap:.5rem;max-width:320px"><input id="rg-c" class="pp-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><button type="button" class="pp-submit" id="rg-verify" style="min-width:auto">Potwierdź</button></div>
      </div>
      <p id="rg-msg" class="pp-note" role="status" aria-live="polite"></p>
    </div>
    <div class="pp-field"><label for="rg-1">Hasło</label><input id="rg-1" type="password" name="password" class="pp-input" autocomplete="new-password" minlength="<?= NABOR_PASS_MIN ?>" required aria-describedby="rg-1h">
      <p id="rg-1h" class="pp-note" style="font-size:.82rem">Co najmniej <?= NABOR_PASS_MIN ?> znaków — najlepiej kilka słów.</p></div>
    <div class="pp-field"><label for="rg-2">Powtórz hasło</label><input id="rg-2" type="password" name="password2" class="pp-input" autocomplete="new-password" required></div>
    <div class="pp-field"><label style="display:flex;gap:.6rem;font-weight:400"><input type="checkbox" name="gdpr" value="1" required style="width:1.2rem;height:1.2rem;margin-top:.2rem">
      <span>Wyrażam zgodę na przetwarzanie moich danych przez <?= h($org) ?> w celu prowadzenia konta kandydata i obsługi zgłoszeń na zajęcia. <strong>(wymagane)</strong></span></label></div>
    <button class="pp-submit">Załóż konto</button>
    <div class="pp-links"><a href="<?= h(naborUrl('/logowanie')) ?>">Mam już konto — zaloguj</a></div>
  </form>
  <aside class="pp-note">
    <p><strong>Jak to działa?</strong></p>
    <ol style="padding-left:1.2rem;line-height:1.8">
      <li>Podaj dane i potwierdź numer telefonu kodem SMS.</li>
      <li>Kliknij link aktywacyjny z e-maila.</li>
      <li>Zaloguj się i złóż zgłoszenie — dane opiekuna uzupełnimy, a kod SMS ani link nie będą już potrzebne.</li>
      <li>Na koncie śledzisz status, wiadomości, dokumenty i — po przyjęciu — potwierdzasz miejsce.</li>
    </ol>
  </aside>
</div>
<script>
(function () {
  var f = document.getElementById('rg-form'), ph = document.getElementById('rg-p'), msg = document.getElementById('rg-msg');
  var send = document.getElementById('rg-send'), box = document.getElementById('rg-codebox'), ok = document.getElementById('rg-ok');
  var verified = <?= json_encode((string)($verified ?? '')) ?>;
  function norm(v) { var d = String(v || '').replace(/\D/g, ''); return d.length === 9 ? '48' + d : d; }
  function sync() { var good = verified && verified === norm(ph.value); ok.style.display = good ? '' : 'none'; send.style.display = good ? 'none' : ''; if (good) box.style.display = 'none'; }
  function post(data) {
    var fd = new FormData(); fd.append('_csrf', <?= json_encode((string)$csrf) ?>);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(<?= json_encode((string)$smsUrl) ?>, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Błąd połączenia.' }; });
  }
  send.addEventListener('click', function () {
    send.disabled = true; msg.textContent = '';
    post({ action: 'send', phone: ph.value }).then(function (r) {
      if (!r.ok) { msg.textContent = r.error; send.disabled = false; return; }
      sentTo = norm(ph.value); box.style.display = ''; msg.textContent = 'Wysłaliśmy SMS z 6-cyfrowym kodem na numer ' + ph.value + ' (ważny 10 minut).'; document.getElementById('rg-c').focus();
      setTimeout(function () { send.disabled = false; send.textContent = 'Wyślij ponownie'; }, 60000);
    });
  });
  document.getElementById('rg-verify').addEventListener('click', function () {
    post({ action: 'verify', phone: ph.value, code: document.getElementById('rg-c').value }).then(function (r) {
      if (!r.ok) { msg.textContent = r.error; return; }
      verified = norm(ph.value); msg.textContent = ''; sync();
    });
  });
  // Po wpisaniu pełnego numeru (9 cyfr) kod SMS wysyła się sam; przycisk zostaje do ponownej wysyłki
  var sentTo = '', autoT = null;
  ph.addEventListener('input', function () {
    sync(); clearTimeout(autoT);
    var n = norm(ph.value);
    if (/^48\d{9}$/.test(n) && n !== sentTo && !(verified && verified === n) && !send.disabled)
      autoT = setTimeout(function () { if (norm(ph.value) === n) send.click(); }, 700);
  });
  sync();
  f.addEventListener('submit', function (e) { if (!(verified && verified === norm(ph.value))) { e.preventDefault(); msg.textContent = 'Potwierdź numer telefonu kodem SMS.'; ph.focus(); } });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
