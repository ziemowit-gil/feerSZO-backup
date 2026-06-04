<?php
/**
 * auth/_login_form_body.php
 * Wspólna treść formularzy logowania — używana przez oba layouty (split i simple).
 * Wymaga zmiennych: $ms_available, $sms_available, $code_available,
 *   $active_tab, $active_alt, $alt_tabs, $error, $redirect,
 *   $sms_step, $sms_phone, $ms_auth_url (function).
 */
?>

<?php if ($ms_available): ?>
<!-- ══ DWUKOLUMNOWY UKŁAD: MS365 | E-mail+hasło ══════════════════════════ -->
<div class="login-cols">

  <!-- Lewa — Microsoft 365 (metoda główna) -->
  <div class="login-col-ms">
    <div class="ms-col-heading">
      <i class="bi bi-microsoft me-1" aria-hidden="true"></i>Konto Microsoft 365
    </div>
    <a href="<?= h(ms_auth_url($redirect)) ?>"
       class="btn-login"
       aria-label="Zaloguj się przez konto Microsoft 365 — zostaniesz przekierowany na stronę Microsoft">
      <svg class="ms-logo" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true">
        <path fill="#f35325" d="M1 1h10v10H1z"/>
        <path fill="#81bc06" d="M12 1h10v10H12z"/>
        <path fill="#05a6f0" d="M1 12h10v10H12z"/>
        <path fill="#ffba08" d="M12 12h10v10H12z"/>
      </svg>
      Zaloguj przez Microsoft 365
    </a>
    <p class="ms-col-note">
      Jedno kliknięcie — bez wpisywania hasła. Zalecana metoda dla wszystkich osób z kontem organizacji Microsoft.
    </p>
  </div>

  <!-- Separator pionowy -->
  <div class="login-col-divider" role="separator" aria-hidden="true"></div>

  <!-- Prawa — E-mail + hasło (zapasowa) -->
  <div class="login-col-local">
    <div class="local-col-heading">
      <i class="bi bi-person-fill me-1" aria-hidden="true"></i>E-mail i hasło
    </div>
    <form method="post" novalidate aria-label="Formularz logowania — e-mail i hasło" autocomplete="on">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_method" value="local">
      <div class="mb-3">
        <label class="form-label" for="f-email">Adres e-mail</label>
        <input type="email" name="email" id="f-email"
               class="form-control"
               placeholder="nazwa@domena.pl"
               autocomplete="email"
               required aria-required="true"
               <?= $error ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
        <?php if ($error && str_contains($error, 'mail')): ?>
        <div id="email-err" class="form-hint" style="color:#DC2626"><?= h($error) ?></div>
        <?php endif; ?>
      </div>
      <div class="mb-4">
        <label class="form-label" for="f-pass">Hasło</label>
        <div class="pass-wrap">
          <input type="password" name="password" id="f-pass"
                 class="form-control"
                 autocomplete="current-password"
                 required aria-required="true"
                 aria-describedby="pass-hint">
          <button type="button" class="pass-toggle"
                  aria-label="Pokaż hasło"
                  aria-pressed="false"
                  onclick="togglePass('f-pass', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <div id="pass-hint" class="form-hint">
          Nie masz konta Microsoft? Użyj e-maila i hasła nadanego przez administratora.
        </div>
      </div>
      <button type="submit" class="btn-login">
        Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </button>
      <div class="text-center mt-3">
        <a href="<?= APP_URL ?>/user/verify_reset.php"
           style="font-size:.84rem;color:#4B5563;text-decoration:none"
           aria-label="Zresetuj zapomniane hasło">
          <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Zapomniałem hasła
        </a>
      </div>
    </form>
  </div>

</div><!-- /login-cols -->

<?php else: ?>
<!-- ══ JEDNOKOLUMNOWY UKŁAD (bez MS365) ══════════════════════════════════ -->
<form method="post" novalidate aria-label="Formularz logowania — e-mail i hasło" autocomplete="on">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_method" value="local">
  <div class="mb-3">
    <label class="form-label" for="f-email">Adres e-mail</label>
    <input type="email" name="email" id="f-email"
           class="form-control"
           placeholder="nazwa@domena.pl"
           autocomplete="email"
           required aria-required="true"
           autofocus
           <?= $error ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
    <?php if ($error && str_contains($error, 'mail')): ?>
    <div id="email-err" class="form-hint" style="color:#DC2626"><?= h($error) ?></div>
    <?php endif; ?>
  </div>
  <div class="mb-4">
    <label class="form-label" for="f-pass">Hasło</label>
    <div class="pass-wrap">
      <input type="password" name="password" id="f-pass"
             class="form-control"
             autocomplete="current-password"
             required aria-required="true"
             aria-describedby="pass-hint">
      <button type="button" class="pass-toggle"
              aria-label="Pokaż hasło"
              aria-pressed="false"
              onclick="togglePass('f-pass', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
    <div id="pass-hint" class="form-hint">
      Hasło nadane przez administratora lub zmienione po pierwszym logowaniu.
    </div>
  </div>
  <button type="submit" class="btn-login">
    Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
  </button>
  <div class="text-center mt-3">
    <a href="<?= APP_URL ?>/user/verify_reset.php"
       style="font-size:.84rem;color:#4B5563;text-decoration:none"
       aria-label="Zresetuj zapomniane hasło">
      <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Zapomniałem hasła
    </a>
  </div>
</form>
<?php endif; ?>

<!-- ══ Alternatywne metody (kod jednorazowy, SMS) ════════════════════════ -->
<?php
$alt_tabs = [];
if ($code_available) $alt_tabs['code'] = ['icon' => 'bi-key-fill',   'label' => 'Kod jednorazowy', 'for' => 'Pierwsze logowanie lub gość'];
if ($sms_available)  $alt_tabs['sms']  = ['icon' => 'bi-phone-fill', 'label' => 'Kod SMS',         'for' => 'Bez konta — tylko numer telefonu'];
$active_alt = in_array($active_tab, ['code','sms'], true) ? $active_tab : null;
?>
<?php if (!empty($alt_tabs)): ?>
<div class="or-div" aria-hidden="true"><span>lub zaloguj inaczej</span></div>
<nav aria-label="Alternatywne metody logowania" id="tab-nav">
  <div role="tablist" aria-label="Wybierz alternatywną metodę logowania">
    <?php foreach ($alt_tabs as $key => $m): ?>
    <button role="tab"
            id="tab-btn-<?= $key ?>"
            aria-selected="<?= $active_alt === $key ? 'true' : 'false' ?>"
            aria-controls="tab-<?= $key ?>"
            onclick="switchAltTab('<?= $key ?>')"
            <?= $active_alt === $key ? '' : 'tabindex="-1"' ?>>
      <i class="bi <?= $m['icon'] ?>" aria-hidden="true"></i>
      <span><?= h($m['label']) ?><small style="display:block;font-size:.7em;font-weight:400;opacity:.65;margin-top:.05rem"><?= h($m['for']) ?></small></span>
    </button>
    <?php endforeach; ?>
  </div>
</nav>

<!-- Kod jednorazowy -->
<?php if ($code_available): ?>
<section id="tab-code" style="margin-top:1rem" aria-labelledby="tab-btn-code"
         <?= $active_alt !== 'code' ? 'hidden' : '' ?>>
  <form method="post" novalidate aria-label="Formularz logowania — jednorazowy kod dostępu" autocomplete="off">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="code">
    <div class="mb-4">
      <label class="form-label" for="f-code">Kod jednorazowy</label>
      <input type="text" name="login_code" id="f-code"
             class="form-control"
             style="font-family:monospace;letter-spacing:.1em;text-align:center;font-size:1.1rem"
             placeholder="XXXXXXXX"
             spellcheck="false"
             autocomplete="one-time-code"
             required aria-required="true"
             aria-describedby="code-hint"
             <?= $active_alt === 'code' ? 'autofocus' : '' ?>>
      <div id="code-hint" class="form-hint">
        Kod jednorazowy wysłany e-mailem lub podany przez administratora. Ważny tylko do pierwszego użycia.
      </div>
    </div>
    <button type="submit" class="btn-login">
      Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
  </form>
</section>
<?php endif; ?>

<!-- SMS -->
<?php if ($sms_available): ?>
<section id="tab-sms" style="margin-top:1rem" aria-labelledby="tab-btn-sms"
         <?= $active_alt !== 'sms' ? 'hidden' : '' ?>>
  <?php if ($sms_step === 1): ?>
  <form method="post" novalidate aria-label="Formularz logowania — krok 1: podaj numer telefonu" autocomplete="off">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="sms_send">
    <div class="mb-4">
      <label class="form-label" for="f-sms-phone">Numer telefonu</label>
      <div class="input-group">
        <span class="input-group-text fw-semibold" style="border:2px solid #6B7280;border-right:none;color:#374151">+48</span>
        <input type="tel" name="sms_phone" id="f-sms-phone"
               class="form-control"
               style="border-left:none"
               placeholder="123 456 789"
               value="<?= h($sms_phone) ?>"
               inputmode="numeric" pattern="[0-9 ]{9,11}"
               autocomplete="tel-national"
               required aria-required="true"
               aria-describedby="sms-phone-hint"
               <?= $active_alt === 'sms' ? 'autofocus' : '' ?>>
      </div>
      <div id="sms-phone-hint" class="form-hint">
        Podaj numer telefonu, który wpisałeś/aś w umowie wolontariackiej. Wyślemy jednorazowy kod SMS.
      </div>
    </div>
    <button type="submit" class="btn-login">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij kod SMS
    </button>
  </form>
  <?php else: ?>
  <form method="post" novalidate aria-label="Formularz logowania — krok 2: wpisz kod SMS" autocomplete="off">
    <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
    <input type="hidden" name="_method"   value="sms_verify">
    <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
    <p style="font-size:.88rem;color:#374151;margin-bottom:1rem">
      Kod wysłany na numer <strong><?= h($sms_phone) ?></strong>. Ważny 5 minut.
    </p>
    <div class="mb-4">
      <label class="form-label" for="f-sms-code">6-cyfrowy kod SMS</label>
      <input type="text" name="sms_code" id="f-sms-code"
             class="form-control sms-code"
             inputmode="numeric"
             pattern="[0-9]{6}"
             maxlength="6"
             placeholder="• • • • • •"
             autocomplete="one-time-code"
             required aria-required="true"
             aria-describedby="sms-code-hint"
             autofocus>
      <div id="sms-code-hint" class="form-hint">
        Sprawdź wiadomości SMS — wpisz 6-cyfrowy kod. Ważny przez 5 minut.
      </div>
    </div>
    <button type="submit" class="btn-login mb-3">
      Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
    <button type="button"
            style="background:none;border:none;color:#4B5563;font-size:.84rem;cursor:pointer;padding:.4rem;text-decoration:underline;width:100%;text-align:center"
            aria-label="Wróć — zmień numer telefonu"
            onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer telefonu
    </button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php endif; ?>
