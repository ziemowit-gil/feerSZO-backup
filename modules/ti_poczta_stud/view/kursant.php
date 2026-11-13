<?php
/**
 * modules/ti_poczta_stud/view/kursant.php — zakładka „Poczta kursanta” w panelu kursanta TI (?tab=poczta, oba wyglądy panelu).
 * Poczta @stud.feer.org.pl prowadzona przez sdata.net.pl, niezależna od Microsoft 365: adres/login, link do webmaila, hasło po kodzie jednorazowym.
 * Kod wysyła i sprawdza index.php (operacje stud_mail_code / stud_mail_verify, LicencjeKod z celem 'stud_mail');
 * stud_mail_done — uczestnik potwierdza, że ustawił własne hasło (hasło startowe znika z bazy). Limit odsłonięć i ważność: PocztaStud.
 * Zmienne z index.php: $stud_mail (PocztaStud::get), $client.
 */
if (!isset($stud_mail) || !$stud_mail) return;
require_once dirname(__DIR__, 2) . '/ti_licencje_kursant/logic/LicencjeKod.php';
$_pm_msg  = $_SESSION['stud_mail_msg'] ?? null;    unset($_SESSION['stud_mail_msg']);
$_pm_rev  = $_SESSION['stud_mail_reveal'] ?? null; unset($_SESSION['stud_mail_reveal']);   // hasło pokazujemy raz, zaraz po kodzie
$_pm_pass = PocztaStud::sessionTake($_pm_rev);   // w sesji leży zaszyfrowane, najwyżej 5 minut
$_pm_otp  = $_pm_pass === '' ? LicencjeKod::pending('stud_mail') : null;
if ($_pm_otp && time() > (int)$_pm_otp['exp']) $_pm_otp = null;
$_pm_host = preg_replace('#^https?://|/$#', '', PocztaStud::WEBMAIL);
$_pm_tok  = h(student_token());
?>
<h1 class="h5 fw-bold mb-1"><i class="bi bi-envelope-at text-primary me-2" aria-hidden="true"></i>Poczta kursanta</h1>
<p class="text-body-secondary small mb-3">Twoja skrzynka e-mail w Fundacji. Otwierasz ją w przeglądarce, na stronie <?= h($_pm_host) ?>.</p>

<?php if ($_pm_msg): ?>
<div class="alert alert-<?= h($_pm_msg[0]) ?>" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($_pm_msg[1]) ?></div>
<?php endif; ?>

<section class="card mb-3" style="max-width:720px" aria-labelledby="pm-konto-h">
  <div class="card-body">
    <h2 class="h6 fw-bold mb-3" id="pm-konto-h">Dane do logowania</h2>
    <dl class="row mb-0">
      <dt class="col-sm-4 text-body-secondary fw-normal">Adres i login</dt>
      <dd class="col-sm-8 d-flex flex-wrap align-items-center gap-2">
        <span class="font-monospace fw-semibold" id="pm-login"><?= h($stud_mail['login']) ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary pm-kopiuj" data-copy="<?= h($stud_mail['login']) ?>">
          <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj<span class="visually-hidden"> adres poczty</span></button>
      </dd>
      <dt class="col-sm-4 text-body-secondary fw-normal" id="poczta-haslo">Hasło</dt>
      <dd class="col-sm-8">
        <?php if ($_pm_pass !== ''): ?>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="font-monospace fw-semibold"><?= h($_pm_pass) ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary pm-kopiuj" data-copy="<?= h($_pm_pass) ?>">
              <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj<span class="visually-hidden"> hasło do poczty</span></button>
          </div>
          <div class="small text-body-secondary mt-1">Pokazujemy je tylko teraz. Po odświeżeniu strony zniknie.
            Zostało pokazań: <?= (int)$stud_mail['revealsLeft'] ?> z <?= PocztaStud::MAX_REVEALS ?>.</div>
        <?php elseif (!$stud_mail['hasPassword'] && !empty($stud_mail['password_cleared_at'])): ?>
          Hasła startowego nie ma już w panelu (<?= h(date('d.m.Y', strtotime((string)$stud_mail['password_cleared_at']))) ?>). Logujesz się własnym hasłem.
        <?php elseif (!$stud_mail['hasPassword']): ?>
          Przekaże je biuro.
        <?php elseif (!$stud_mail['canReveal']): ?>
          <span role="status"><?= h($stud_mail['blockReason']) ?></span>
        <?php elseif ($_pm_otp): ?>
          <p class="small mb-2" role="status">
            <?php if (($_pm_otp['channel'] ?? '') === 'ekran'): ?>Konto testowe: kod to <b class="font-monospace"><?= h((string)$_pm_otp['shown']) ?></b>.
            <?php else: ?>Wysłaliśmy kod <?= ($_pm_otp['channel'] ?? '') === 'sms' ? 'SMS-em na numer' : 'e-mailem na adres' ?> <?= h((string)$_pm_otp['to']) ?>. Jest ważny 10 minut.<?php endif; ?>
          </p>
          <form method="post" class="d-flex flex-wrap align-items-end gap-2">
            <input type="hidden" name="_token" value="<?= $_pm_tok ?>">
            <input type="hidden" name="_op" value="stud_mail_verify">
            <div><label class="form-label small mb-1" for="pm-kod">Kod (6 cyfr)</label>
              <input class="form-control form-control-sm font-monospace" id="pm-kod" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" size="8" required<?= $_pm_msg ? ' aria-invalid="true"' : '' ?>></div>
            <button type="submit" class="btn btn-sm btn-primary">Pokaż hasło</button>
          </form>
          <form method="post" class="mt-2">
            <input type="hidden" name="_token" value="<?= $_pm_tok ?>">
            <input type="hidden" name="_op" value="stud_mail_code">
            <button type="submit" class="btn btn-sm btn-link px-0">Wyślij kod jeszcze raz</button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="_token" value="<?= $_pm_tok ?>">
            <input type="hidden" name="_op" value="stud_mail_code">
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż hasło<span class="visually-hidden"> do poczty (wyślemy kod SMS)</span></button>
          </form>
          <div class="small text-body-secondary mt-1">Hasło startowe jest w panelu do <?= h(date('d.m.Y', strtotime((string)$stud_mail['password_expires_at']))) ?>.
            Możesz je pokazać jeszcze <?= (int)$stud_mail['revealsLeft'] ?> <?= (int)$stud_mail['revealsLeft'] === 1 ? 'raz' : 'razy' ?>.</div>
        <?php endif; ?>
        <?php if ($stud_mail['hasPassword']): ?>
          <form method="post" class="mt-2">
            <input type="hidden" name="_token" value="<?= $_pm_tok ?>">
            <input type="hidden" name="_op" value="stud_mail_done">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Mam już własne hasło — usuń hasło startowe z panelu</button>
          </form>
        <?php endif; ?>
      </dd>
    </dl>
    <a class="btn btn-primary mt-3" href="<?= h(PocztaStud::WEBMAIL) ?>" target="_blank" rel="noopener">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz pocztę<span class="visually-hidden"> (<?= h($_pm_host) ?>, otwiera się w nowej karcie)</span></a>
    <span class="visually-hidden" id="pm-live" aria-live="polite"></span>
  </div>
</section>

<div class="alert alert-light border border-start border-4 border-primary" role="note" style="max-width:720px" aria-label="Ważne informacje o poczcie">
  <p class="fw-semibold mb-1">Ważne</p>
  <ul class="mb-0 small">
    <li>Pocztę prowadzi <?= h(PocztaStud::PROVIDER) ?>.</li>
    <li>Hasło do poczty jest inne niż hasło do panelu. Pokażemy je po kodzie, który wyślemy SMS-em.</li>
    <li>To hasło startowe. Po pierwszym logowaniu ustaw na stronie poczty własne hasło i kliknij „Mam już własne hasło”.</li>
    <li>Hasło startowe zniknie z panelu po <?= PocztaStud::TTL_DAYS ?> dniach od wpisania albo po <?= PocztaStud::MAX_REVEALS ?> pokazaniach.</li>
    <li>Nikomu nie podawaj hasła ani kodu z SMS-a. Pracownicy Fundacji nigdy o nie nie proszą.</li>
  </ul>
</div>
<p class="small text-body-secondary mb-0">Kłopot z logowaniem do poczty? Napisz przez <a href="?tab=problem">Pomoc</a>.</p>

<script>
(function () {
  var live = document.getElementById('pm-live');
  document.querySelectorAll('.pm-kopiuj').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!navigator.clipboard) return;
      navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { live.textContent = 'Skopiowano do schowka.'; });
    });
  });
  var k = document.getElementById('pm-kod'); if (k) k.focus();
})();
</script>
