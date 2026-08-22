<?php
/**
 * auth/_login_modals.php — modale alternatywnych metod logowania
 * (kod jednorazowy, SMS, certyfikat X.509). Dołączane z auth/login.php.
 */
?>
<!-- ══ Modale ════════════════════════════════════════════════════════════════ -->

<?php if ($code_available): ?>
<div class="modal fade" id="modal-code" tabindex="-1" aria-labelledby="mcode-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="mcode-title"><i class="bi bi-key-fill" aria-hidden="true"></i> Kod jednorazowy</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'code'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Kod jednorazowy wysłany przez administratora lub wygenerowany na Twoją prośbę.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="mcode-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="code">
          <div class="ks-field">
            <label for="f-code">Kod dostępu</label>
            <input type="text" name="login_code" id="f-code" class="form-control"
                   autocomplete="off" spellcheck="false" required aria-required="true"
                   placeholder="XXXX-XXXX-XXXX">
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-key-fill" aria-hidden="true"></i> Zaloguj kodem
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($sms_available): ?>
<div class="modal fade" id="modal-sms" tabindex="-1" aria-labelledby="msms-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="msms-title"><i class="bi bi-phone-fill" aria-hidden="true"></i> Kod SMS</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'sms'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($info && $active_tab === 'sms'): ?>
        <div class="l-alert l-alert-success" role="status">
          <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
          <span><?= h($info) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sms_step === 1): ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Wpisz numer telefonu powiązany z Twoim kontem.</p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="msms-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="sms_send">
          <div class="ks-field">
            <label for="f-sms-phone">Numer telefonu</label>
            <div style="display:flex;gap:0">
              <span style="display:inline-flex;align-items:center;padding:.65rem .8rem;background:#f8fafc;border:1px solid var(--ks-line);border-right:none;border-radius:10px 0 0 10px;font-weight:700;color:#374151;font-size:.97rem" aria-hidden="true">+48</span>
              <input type="tel" name="sms_phone" id="f-sms-phone" class="form-control"
                     style="border-radius:0 10px 10px 0" placeholder="123 456 789"
                     value="<?= h($sms_phone) ?>" inputmode="numeric" pattern="[0-9 ]{9,11}"
                     autocomplete="tel-national" required aria-required="true"
                     aria-label="Numer telefonu bez prefiksu +48">
            </div>
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-send" aria-hidden="true"></i> Wyślij kod SMS
          </button>
        </form>
        <?php else: ?>
        <p style="font-size:.87rem;color:#374151;margin:0 0 1rem;line-height:1.5">
          Kod wysłany na <strong><?= h($sms_phone) ?></strong>. Ważny 5 minut.
        </p>
        <form method="post" novalidate autocomplete="off" aria-labelledby="msms-title">
          <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
          <input type="hidden" name="_method"   value="sms_verify">
          <input type="hidden" name="sms_phone" value="<?= h($sms_phone) ?>">
          <div class="ks-field">
            <label for="f-sms-code">6-cyfrowy kod SMS</label>
            <input type="text" name="sms_code" id="f-sms-code" class="form-control sms-otp"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" autocomplete="one-time-code" required aria-required="true">
          </div>
          <button type="submit" class="ks-btn ks-btn--primary" style="margin-bottom:.65rem">
            Zaloguj się <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
          <button type="button"
                  style="background:none;border:none;color:var(--ks-muted);font-size:.83rem;cursor:pointer;padding:.4rem;width:100%;text-align:center;border-radius:6px"
                  onclick="document.querySelector('[name=_method]').value='sms_send';this.closest('form').submit()">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Zmień numer
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($x509_available): ?>
<div class="modal fade" id="modal-x509" tabindex="-1" aria-labelledby="mx509-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content lm-content">
      <div class="lm-hd">
        <h2 class="lm-title" id="mx509-title"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Certyfikat X.509</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="lm-body">
        <?php if ($error && $active_tab === 'x509'): ?>
        <div class="l-alert l-alert-danger" role="alert" id="login-error-box">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
          <span id="login-error-text"><?= h($error) ?></span>
        </div>
        <?php endif; ?>
        <p style="font-size:.85rem;color:var(--ks-muted);margin:0 0 1rem">Plik PKCS#12 (.p12) wygenerowany przez administratora systemu.</p>
        <form method="post" enctype="multipart/form-data" novalidate aria-labelledby="mx509-title">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_method" value="x509">
          <div class="ks-field">
            <label for="f-p12">Plik certyfikatu (.p12 lub .pfx)</label>
            <input type="file" name="p12_file" id="f-p12" class="form-control"
                   accept=".p12,.pfx" required aria-required="true">
          </div>
          <div class="ks-field">
            <label for="f-cert-pass">Hasło certyfikatu</label>
            <div class="pass-wrap">
              <input type="password" name="cert_password" id="f-cert-pass" class="form-control"
                     autocomplete="current-password" required aria-required="true">
              <button type="button" class="pass-toggle" aria-label="Pokaż hasło certyfikatu" aria-pressed="false"
                      onclick="togglePass('f-cert-pass', this)">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="ks-btn ks-btn--primary">
            <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Zaloguj certyfikatem
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
