<?php
/**
 * auth/_login_form_body.php
 * Formularz logowania dla wybranej grupy ($view: 'priv' | 'feer').
 * Dołączane z auth/login.php.
 * Wymagane zmienne: $ms_available, $sms_available, $code_available, $x509_available,
 *   $active_tab, $error, $view, $redirect, $_url_feer.
 */

// Błąd dotyczy obu pól (email + hasło) — tylko dla metody lokalnej
$local_error_id = ($error && $active_tab === 'local') ? 'login-error-box' : '';
$is_feer = ($view === 'feer');

// Alternatywne metody (modale) — dobrane do grupy
$alt_tabs = [];
if ($is_feer) {
    if ($x509_available) $alt_tabs['x509'] = ['icon'=>'bi-patch-check-fill', 'label'=>'Certyfikat X.509',  'desc'=>'Plik .p12 dla administratora systemu'];
    if ($code_available) $alt_tabs['code'] = ['icon'=>'bi-key-fill',         'label'=>'Kod jednorazowy',   'desc'=>'Jednorazowy dostęp od administratora'];
} else {
    if ($code_available) $alt_tabs['code'] = ['icon'=>'bi-key-fill',  'label'=>'Kod jednorazowy', 'desc'=>'Pierwsze logowanie lub jednorazowy dostęp'];
    if ($sms_available)  $alt_tabs['sms']  = ['icon'=>'bi-phone-fill','label'=>'Kod SMS',        'desc'=>'Logowanie przez numer telefonu'];
}
?>

<?php if ($is_feer && $ms_available): ?>
<!-- ── Microsoft 365 (tylko konto @feer.org.pl) ─────────────────────────── -->
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
<p class="method-for">Konto <strong>@feer.org.pl</strong> — jedno kliknięcie, bez wpisywania hasła</p>

<div class="or-div"><span>lub e-mailem służbowym i hasłem</span></div>
<?php endif; ?>

<!-- ── Formularz: e-mail i hasło ────────────────────────────────────────── -->
<form method="post"
      id="local-form"
      novalidate
      autocomplete="on"
      aria-labelledby="login-title">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_method" value="local">
  <input type="hidden" name="_view"   value="<?= h($view) ?>">

  <div style="margin-bottom:1rem">
    <label class="form-label" for="f-email">Adres e-mail</label>
    <input type="email"
           name="email"
           id="f-email"
           class="form-control"
           placeholder="<?= $is_feer ? 'imie.nazwisko@feer.org.pl' : 'nazwa@domena.pl' ?>"
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
           <?= (!$is_feer || !$ms_available) ? 'autofocus' : '' ?>>
    <div id="f-email-hint" class="form-hint">
      <?php if ($is_feer): ?>
      Twój e-mail służbowy <strong>@feer.org.pl</strong>.
      <?php else: ?>
      Twój prywatny e-mail podany do WiadomościFEER.
      <?php endif; ?>
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

<?php if (!$is_feer): ?>
<!-- ── Wyjątek: wolontariusz/zleceniobiorca z kontem @feer.org.pl ────────── -->
<a href="<?= h($_url_feer) ?>" class="cross-link">
  <i class="bi bi-building-fill" aria-hidden="true"></i>
  <span class="cross-link-body">
    <span class="cross-link-title">Masz włączone konto @feer.org.pl?</span>
    <span class="cross-link-sub">Zaloguj się kontem służbowym</span>
  </span>
  <i class="bi bi-chevron-right arr" aria-hidden="true"></i>
</a>
<?php endif; ?>

<!-- ── Problem z logowaniem → zgłoszenie do helpdesku (prefiks LOG) ──────── -->
<p style="text-align:center;margin:1rem 0 0">
  <a href="<?= APP_URL ?>/auth/report_login_issue.php"
     class="forgot-link" style="font-size:.82rem"
     aria-label="Zgłoś problem z logowaniem — otwiera formularz weryfikacji tożsamości">
    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
    Masz problem z logowaniem? Zgłoś to
  </a>
</p>

<?php if (!empty($alt_tabs)): ?>
<!-- ── Więcej opcji — rozwijane (kod jednorazowy / SMS / X.509) ─────────── -->
<details class="more-options"<?= in_array($active_tab, ['code','sms','x509'], true) ? ' open' : '' ?>>
  <summary>
    <i class="bi bi-three-dots" aria-hidden="true"></i>
    Więcej opcji logowania
    <i class="bi bi-chevron-down chev" aria-hidden="true"></i>
  </summary>
  <div class="more-options-body method-triggers" role="group" aria-label="Dodatkowe metody logowania">
  <?php foreach ($alt_tabs as $key => $m): ?>
  <button type="button"
          class="method-trigger-btn"
          data-bs-toggle="modal"
          data-bs-target="#modal-<?= $key ?>">
    <span class="method-trigger-icon" aria-hidden="true">
      <i class="bi <?= $m['icon'] ?>"></i>
    </span>
    <span class="method-trigger-body">
      <span class="method-trigger-label"><?= h($m['label']) ?></span>
      <span class="method-trigger-sub"><?= h($m['desc']) ?></span>
    </span>
    <i class="bi bi-chevron-right method-trigger-arrow" aria-hidden="true"></i>
  </button>
  <?php endforeach; ?>
  </div>
</details>
<?php endif; /* /alt_tabs */ ?>
