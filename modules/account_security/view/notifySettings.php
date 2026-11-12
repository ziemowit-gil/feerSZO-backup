<?php
/**
 * modules/account_security/view/notifySettings.php — ustawienie „Powiadomienia o bezpieczeństwie” w akordeonie Zabezpieczeń
 * (tozsamosc/index.php, sekcja #bezpieczenstwo). Wymaga: $uid, $db_user, $csrf, $sec_head (closure nagłówka ustawienia),
 * $as_notify_prefs (AccountSecurityNotify::prefs), $phone_verified (bool), $sms_available (bool).
 * Zapis: POST modules/account_security/router.php?_route=/powiadomienia; test: /powiadomienia/test.
 */
$asNUrl   = fn(string $route): string => APP_URL . '/modules/account_security/router.php?_route=' . rawurlencode($route);
$asNSmsOk = $phone_verified && $sms_available;
$asNMail  = (string)($db_user['email'] ?? '');
$asNMailOk = filter_var($asNMail, FILTER_VALIDATE_EMAIL) !== false;
?>
  <article id="sec-powiadomienia" class="ma-exp" :class="sec === 'powiadomienia' && 'is-open'">
    <?php $sec_head('powiadomienia'); ?>
    <div id="sec-powiadomienia-bd" class="ma-exp-bd" role="region" aria-labelledby="sec-powiadomienia-hd" x-show="sec === 'powiadomienia'">
      <div class="ma-split">
        <form method="post" action="<?= h($asNUrl('/powiadomienia')) ?>" class="m-0">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <fieldset class="m-0 p-0 border-0">
            <legend class="ma-legend">Kiedy mamy Cię powiadomić?</legend>
            <ul class="ma-methods">
              <?php foreach (AccountSecurityNotify::EVENTS as $k => [$label, $smsAllowed]): $pr = $as_notify_prefs[$k]; ?>
              <li class="ma-method">
                <div class="ma-method-tx"><b><?= h($label) ?></b></div>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input" type="checkbox" name="n[<?= h($k) ?>][email]" value="1" id="as-n-<?= h($k) ?>-e"<?= $pr['email'] ? ' checked' : '' ?>>
                  <label class="form-check-label" for="as-n-<?= h($k) ?>-e">E-mail<span class="visually-hidden"> — <?= h($label) ?></span></label>
                </div>
                <?php if ($smsAllowed): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input" type="checkbox" name="n[<?= h($k) ?>][sms]" value="1" id="as-n-<?= h($k) ?>-s"<?= $pr['sms'] ? ' checked' : '' ?><?= $asNSmsOk ? '' : ' disabled aria-describedby="as-n-nosms"' ?>>
                  <label class="form-check-label" for="as-n-<?= h($k) ?>-s">SMS<span class="visually-hidden"> — <?= h($label) ?></span></label>
                </div>
                <?php endif; ?>
              </li>
              <?php endforeach; ?>
            </ul>
          </fieldset>
          <?php if (!$asNSmsOk): ?>
          <p id="as-n-nosms" class="ma-help"><?= $sms_available
              ? 'SMS wymaga zweryfikowanego numeru telefonu — dodaj go w ustawieniu „Telefon do SMS” powyżej.'
              : 'Wysyłka SMS jest w tej chwili wyłączona w systemie.' ?></p>
          <?php endif; ?>
          <?php if (!$asNMailOk): ?>
          <div class="ma-bar ma-bar--warn" role="status"><i class="bi bi-exclamation-triangle-fill ma-bar-ic" aria-hidden="true"></i>
            <div class="ma-bar-tx">Login konta nie jest adresem e-mail — powiadomienia e-mailem nie zostaną wysłane. Poproś administratora o uzupełnienie adresu.</div></div>
          <?php endif; ?>
          <button type="submit" class="tz-btn" style="margin-top:.75rem">Zapisz ustawienia</button>
        </form>

        <aside class="ma-aside" aria-labelledby="as-n-aside-h">
          <h4 id="as-n-aside-h"><i class="bi bi-info-circle" aria-hidden="true"></i>Jak to działa</h4>
          <ul>
            <li>E-mail wysyłamy na adres konta<?= $asNMailOk ? ': <strong>' . h($asNMail) . '</strong>' : '' ?>; SMS — na zweryfikowany numer.</li>
            <li>Wiadomość mówi, co się stało, kiedy i z jakiego urządzenia — nigdy nie zawiera hasła ani kodów.</li>
            <li>Zgłoszenie „To nie ja” w Urządzeniach i logowaniach zawsze potwierdzamy e-mailem.</li>
          </ul>
          <form method="post" action="<?= h($asNUrl('/powiadomienia/test')) ?>" class="m-0" style="margin-top:.75rem!important">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm"><i class="bi bi-send" aria-hidden="true"></i> Wyślij powiadomienie testowe</button>
          </form>
        </aside>
      </div>
    </div>
  </article>
