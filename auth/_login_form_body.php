<?php
/**
 * auth/_login_form_body.php
 * Treść formularzy logowania — dołączana z auth/login.php.
 * Wymagane zmienne: $ms_available, $sms_available, $code_available, $x509_available,
 *   $active_tab, $error, $redirect, $sms_step, $sms_phone.
 */

// Określ aktywną zakładkę alternatywnej metody
$active_alt = in_array($active_tab, ['code','sms','x509'], true) ? $active_tab : null;

// Mapowanie: błąd dotyczy obu pól (email + hasło)
$local_error_id = ($error && $active_tab === 'local') ? 'login-error-box' : '';
?>

<?php if ($ms_available): ?>
<!-- ── Microsoft 365 ─────────────────────────────────────────────────────── -->
<a href="<?= h(ms_auth_url($redirect)) ?>"
   class="btn-ms365"
   aria-label="Zaloguj się przez konto Microsoft 365 — zostaniesz przekierowany na stronę Microsoft">
  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
    <path fill="#f35325" d="M1 1h10v10H1z"/>
    <path fill="#81bc06" d="M12 1h10v10H12z"/>
    <path fill="#05a6f0" d="M1 12h10v10H1z"/>
    <path fill="#ffba08" d="M12 12h10v10H12z"/>
  </svg>
  Zaloguj przez Microsoft 365
</a>
<p class="ms-note">Zalecana metoda — jedno kliknięcie, bez wpisywania hasła</p>

<div class="or-div"><span>lub użyj e-maila i hasła</span></div>
<?php endif; ?>

<!-- ── Formularz: e-mail i hasło ────────────────────────────────────────── -->
<form method="post"
      id="local-form"
      novalidate
      autocomplete="on"
      aria-labelledby="login-title">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_method" value="local">

  <div style="margin-bottom:1rem">
    <label class="form-label" for="f-email">Adres e-mail</label>
    <input type="email"
           name="email"
           id="f-email"
           class="form-control"
           placeholder="nazwa@domena.pl"
           autocomplete="email"
           inputmode="email"
           required
           aria-required="true"
           <?php if ($local_error_id): ?>
           aria-invalid="true"
           aria-describedby="<?= $local_error_id ?> f-email-hint"
           <?php else: ?>
           aria-describedby="f-email-hint"
           <?php endif; ?>
           <?= !$ms_available ? 'autofocus' : '' ?>>
    <div id="f-email-hint" class="form-hint">
      Adres e-mail podany administratorowi przy rejestracji konta.
    </div>
  </div>

  <div style="margin-bottom:1.25rem">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:.38rem">
      <label class="form-label" for="f-pass" style="margin-bottom:0">Hasło</label>
      <a href="<?= APP_URL ?>/user/verify_reset.php"
         class="forgot-link"
         style="font-size:.8rem"
         aria-label="Zresetuj zapomniane hasło — otwiera formularz odzyskiwania">
        <i class="bi bi-question-circle" aria-hidden="true"></i>
        Zapomniałem hasła
      </a>
    </div>
    <div class="pass-wrap">
      <input type="password"
             name="password"
             id="f-pass"
             class="form-control"
             autocomplete="current-password"
             required
             aria-required="true"
             <?php if ($local_error_id): ?>
             aria-invalid="true"
             aria-describedby="<?= $local_error_id ?> f-pass-hint"
             <?php else: ?>
             aria-describedby="f-pass-hint"
             <?php endif; ?>>
      <button type="button"
              class="pass-toggle"
              aria-label="Pokaż hasło"
              aria-pressed="false"
              onclick="togglePass('f-pass', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
    <div id="f-pass-hint" class="form-hint">
      Hasło nadane przez administratora lub zmienione po pierwszym logowaniu.
    </div>
  </div>

  <button type="submit" class="btn-login">
    Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
  </button>
</form>

<?php
// Zbuduj listę alternatywnych metod
$alt_tabs = [];
if ($code_available) $alt_tabs['code'] = [
  'icon'  => 'bi-key-fill',
  'label' => 'Kod jednorazowy',
  'desc'  => 'Pierwsze logowanie lub jednorazowy dostęp gościa',
];
if ($sms_available)  $alt_tabs['sms'] = [
  'icon'  => 'bi-phone-fill',
  'label' => 'Kod SMS',
  'desc'  => 'Logowanie przez numer telefonu bez konta e-mail',
];
if ($x509_available) $alt_tabs['x509'] = [
  'icon'  => 'bi-patch-check-fill',
  'label' => 'Certyfikat X.509',
  'desc'  => 'Plik .p12 generowany dla administratorów systemu',
];
?>

<?php if (!empty($alt_tabs)): ?>
<!-- ── Alternatywne metody ───────────────────────────────────────────────── -->
<div class="or-div" aria-hidden="true"><span>lub zaloguj inaczej</span></div>

<div role="tablist"
     class="method-tablist"
     aria-label="Alternatywne metody logowania">
  <?php foreach ($alt_tabs as $key => $m): ?>
  <button role="tab"
          class="method-tab"
          id="tab-btn-<?= $key ?>"
          aria-selected="<?= $active_alt === $key ? 'true' : 'false' ?>"
          aria-controls="tab-panel-<?= $key ?>"
          tabindex="<?= $active_alt === $key ? '0' : '-1' ?>"
          onclick="switchAltTab('<?= $key ?>')">
    <i class="bi <?= $m['icon'] ?>" aria-hidden="true"></i>
    <?= h($m['label']) ?>
  </button>
  <?php endforeach; ?>
</div>

<?php if ($active_alt && isset($alt_tabs[$active_alt])): ?>
<p class="method-desc" aria-live="polite" id="method-active-desc">
  <?= h($alt_tabs[$active_alt]['desc']) ?>
</p>
<?php else: ?>
<p class="method-desc" id="method-active-desc"></p>
<?php endif; ?>

<!-- ── Panel: Kod jednorazowy ────────────────────────────────────────────── -->
<?php if ($code_available): ?>
<section id="tab-panel-code"
         role="tabpanel"
         aria-labelledby="tab-btn-code"
         tabindex="<?= $active_alt === 'code' ? '0' : '-1' ?>"
         class="method-panel"
         <?= $active_alt !== 'code' ? 'hidden' : '' ?>>
  <form method="post"
        novalidate
        autocomplete="off"
        aria-label="Logowanie kodem jednorazowym">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="code">

    <div style="margin-bottom:1rem">
      <label class="form-label" for="f-code">Kod jednorazowy</label>
      <input type="text"
             name="login_code"
             id="f-code"
             class="form-control"
             style="font-family:monospace;letter-spacing:.12em;text-align:center;font-size:1.05rem"
             placeholder="XXXXXXXX"
             spellcheck="false"
             autocomplete="one-time-code"
             required
             aria-required="true"
             aria-describedby="f-code-hint"
             <?php if ($error && $active_alt === 'code'): ?>
             aria-invalid="true"
             aria-errormessage="login-error-box"
             <?php endif; ?>
             <?= $active_alt === 'code' ? 'autofocus' : '' ?>>
      <div id="f-code-hint" class="form-hint">
        Kod wysłany e-mailem lub podany przez administratora. Ważny wyłącznie do pierwszego użycia.
      </div>
    </div>
    <button type="submit" class="btn-login">
      Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
  </form>
</section>
<?php endif; ?>

<!-- ── Panel: Kod SMS ─────────────────────────────────────────────────────── -->
<?php if ($sms_available): ?>
<section id="tab-panel-sms"
         role="tabpanel"
         aria-labelledby="tab-btn-sms"
         tabindex="<?= $active_alt === 'sms' ? '0' : '-1' ?>"
         class="method-panel"
         <?= $active_alt !== 'sms' ? 'hidden' : '' ?>>

  <?php if ($sms_step === 1): ?>
  <form method="post"
        novalidate
        autocomplete="off"
        aria-label="Logowanie SMS — krok 1: podaj numer telefonu">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="sms_send">
    <div style="margin-bottom:1rem">
      <label class="form-label" for="f-sms-phone">Numer telefonu</label>
      <div style="display:flex;gap:0">
        <span style="display:inline-flex;align-items:center;padding:.65rem .8rem;background:#f8fafc;border:2px solid #94a3b8;border-right:none;border-radius:8px 0 0 8px;font-weight:700;color:#374151;font-size:1rem;white-space:nowrap"
              aria-hidden="true">+48</span>
        <input type="tel"
               name="sms_phone"
               id="f-sms-phone"
               class="form-control"
               style="border-radius:0 8px 8px 0"
               placeholder="123 456 789"
               value="<?= h($sms_phone) ?>"
               inputmode="numeric"
               pattern="[0-9 ]{9,11}"
               autocomplete="tel-national"
               required
               aria-required="true"
               aria-label="Numer telefonu bez prefiksu +48"
               aria-describedby="f-sms-hint"
               <?php if ($error && $active_alt === 'sms'): ?>
               aria-invalid="true"
               aria-errormessage="login-error-box"
               <?php endif; ?>
               <?= $active_alt === 'sms' ? 'autofocus' : '' ?>>
      </div>
      <div id="f-sms-hint" class="form-hint">
        Numer wpisany w umowie wolontariackiej lub udostępniony administratorowi.
      </div>
    </div>
    <button type="submit" class="btn-login">
      <i class="bi bi-send" aria-hidden="true"></i>
      Wyślij kod SMS
    </button>
  </form>

  <?php else: ?>
  <form method="post"
        novalidate
        autocomplete="off"
        aria-label="Logowanie SMS — krok 2: wpisz kod">
    <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
    <input type="hidden" name="_method"   value="sms_verify">
    <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
    <p style="font-size:.88rem;color:#374151;margin-bottom:.9rem;line-height:1.5">
      Kod wysłany na numer <strong><?= h($sms_phone) ?></strong>.<br>
      Ważny przez <strong>5 minut</strong>.
    </p>
    <div style="margin-bottom:1rem">
      <label class="form-label" for="f-sms-code">6-cyfrowy kod SMS</label>
      <input type="text"
             name="sms_code"
             id="f-sms-code"
             class="form-control sms-otp"
             inputmode="numeric"
             pattern="[0-9]{6}"
             maxlength="6"
             placeholder="000000"
             autocomplete="one-time-code"
             required
             aria-required="true"
             aria-describedby="f-sms-code-hint"
             <?php if ($error && $active_alt === 'sms'): ?>
             aria-invalid="true"
             aria-errormessage="login-error-box"
             <?php endif; ?>
             autofocus>
      <div id="f-sms-code-hint" class="form-hint">
        Sprawdź wiadomości SMS — wpisz 6 cyfr.
      </div>
    </div>
    <button type="submit" class="btn-login" style="margin-bottom:.65rem">
      Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
    <button type="button"
            style="background:none;border:none;color:#64748b;font-size:.83rem;cursor:pointer;padding:.4rem;width:100%;text-align:center;border-radius:6px"
            aria-label="Wróć — zmień numer telefonu"
            onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer telefonu
    </button>
  </form>
  <?php endif; ?>

</section>
<?php endif; ?>

<!-- ── Panel: Certyfikat X.509 ───────────────────────────────────────────── -->
<?php if ($x509_available): ?>
<section id="tab-panel-x509"
         role="tabpanel"
         aria-labelledby="tab-btn-x509"
         tabindex="<?= $active_alt === 'x509' ? '0' : '-1' ?>"
         class="method-panel"
         <?= $active_alt !== 'x509' ? 'hidden' : '' ?>>
  <form method="post"
        enctype="multipart/form-data"
        novalidate
        aria-label="Logowanie certyfikatem X.509">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_method" value="x509">
    <div style="margin-bottom:1rem">
      <label class="form-label" for="f-p12">Plik certyfikatu (.p12 lub .pfx)</label>
      <input type="file"
             name="p12_file"
             id="f-p12"
             class="form-control"
             accept=".p12,.pfx"
             required
             aria-required="true"
             aria-describedby="f-p12-hint"
             <?php if ($error && $active_alt === 'x509'): ?>
             aria-invalid="true"
             aria-errormessage="login-error-box"
             <?php endif; ?>
             <?= $active_alt === 'x509' ? 'autofocus' : '' ?>>
      <div id="f-p12-hint" class="form-hint">
        Plik PKCS#12 wygenerowany przez administratora systemu.
      </div>
    </div>
    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="f-cert-pass">Hasło certyfikatu</label>
      <div class="pass-wrap">
        <input type="password"
               name="cert_password"
               id="f-cert-pass"
               class="form-control"
               autocomplete="current-password"
               required
               aria-required="true"
               aria-describedby="f-cert-pass-hint"
               <?php if ($error && $active_alt === 'x509'): ?>
               aria-invalid="true"
               aria-errormessage="login-error-box"
               <?php endif; ?>>
        <button type="button"
                class="pass-toggle"
                aria-label="Pokaż hasło certyfikatu"
                aria-pressed="false"
                onclick="togglePass('f-cert-pass', this)">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
      </div>
      <div id="f-cert-pass-hint" class="form-hint">
        Hasło podane przez administratora przy generowaniu pliku .p12.
      </div>
    </div>
    <button type="submit" class="btn-login">
      <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
      Zaloguj certyfikatem
    </button>
  </form>
</section>
<?php endif; ?>

<?php endif; /* /alt_tabs */ ?>
