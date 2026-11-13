<?php
/**
 * modules/sso_logto/view/userSection.php — sekcja #sso „Logowanie jednokrotne” w Tożsamości (tozsamosc/index.php).
 * Zmienne z index.php: $db_user, $has_m365, $sec_title. Formularze idą na POST /sso/konto i /sso/haslo (modules/sso_logto).
 * Przy samodzielnym zakładaniu konta użytkownik sam wybiera hasło — SZO go nie zapisuje (przekazuje do Logto i chmury).
 */
require_once dirname(__DIR__) . '/logic/SsoAccounts.php';

$ssoEmail  = strtolower((string)($db_user['email'] ?? ''));
$ssoAcc    = $ssoEmail !== '' ? SsoAccounts::get($ssoEmail) : null;
$ssoReady  = SsoConfig::logtoConfigured();
$ssoHas    = $ssoAcc && !empty($ssoAcc['logto_id']);
$ssoHasCld = $ssoAcc && !empty($ssoAcc['cloud_uid']);
$ssoHost   = (string)parse_url(SsoConfig::logtoUrl(), PHP_URL_HOST);
$ssoCloudName = SsoConfig::cloudLabel();
$ssoCloudSelf = SsoConfig::cloudSelf() && SsoConfig::cloudConfigured();
$ssoDate   = fn(?string $d) => $d ? date('d.m.Y, H:i', strtotime($d)) : 'jeszcze nie';
$ssoBase   = rtrim(APP_URL, '/');
?>
<section id="sso" class="ma-sec" aria-labelledby="sso-h" x-show="tab === 'sso'" x-cloak>
  <?php $sec_title('sso', 'Logowanie jednokrotne', 'Jedno konto i jedno hasło do aplikacji organizacji.'); ?>

  <div class="ma-card">
    <div class="ma-card-hd"><span class="ma-ic" aria-hidden="true"><i class="bi bi-key"></i></span><h2 class="ma-card-t">Co to jest?</h2></div>
    <p class="mb-2">Logowanie jednokrotne działa jak jeden klucz do wielu drzwi. Masz jedno konto i jedno hasło, a logujesz się nimi na stronie <strong><?= h($ssoHost) ?></strong>.</p>
    <p class="mb-0">Aplikacje organizacji, które z niego korzystają (na przykład chmura plików), same przeniosą Cię na tę stronę. Po zalogowaniu wracasz do aplikacji. Dopóki nie wylogujesz się w przeglądarce, kolejne aplikacje nie zapytają o hasło.</p>
  </div>

  <h2 class="ma-h2">Twoje hasła w organizacji</h2>
  <div class="ma-card">
    <ul class="ma-list ma-list--info">
      <li><i class="bi bi-1-circle-fill" aria-hidden="true"></i><span><strong>Logowanie jednokrotne i chmura plików mają jedno hasło.</strong> Zmieniasz je tutaj i od razu działa w obu miejscach.</span></li>
      <li><i class="bi bi-2-circle-fill" aria-hidden="true"></i><span><strong>Hasło do poczty jest odrębne.</strong> Skrzynka pocztowa ma własne hasło. Zmiana hasła logowania jednokrotnego go nie zmienia, a zmiana hasła do poczty nie zmienia tego.</span></li>
      <li><i class="bi bi-3-circle-fill" aria-hidden="true"></i><span><strong>Hasło do SZO<?= $has_m365 ? ' i Microsoft 365' : '' ?> też jest osobne.</strong> Zmieniasz je w <a href="#bezpieczenstwo" @click.prevent="go('bezpieczenstwo', 'haslo')">Zabezpieczeniach</a>.</span></li>
      <li><i class="bi bi-4-circle-fill" aria-hidden="true"></i><span>Nie używaj tego samego hasła w kilku miejscach i nie podawaj go nikomu. Administrator nie zna Twojego hasła i nigdy o nie nie poprosi.</span></li>
    </ul>
  </div>

  <h2 class="ma-h2">Twoje konto</h2>
  <div class="ma-card">
    <?php if (!$ssoReady): ?>
      <p class="mb-0">Logowanie jednokrotne nie jest jeszcze uruchomione w organizacji. Gdy będzie gotowe, konto założysz tutaj.</p>

    <?php elseif (!$ssoHas): ?>
      <?php if (SsoConfig::selfEnabled()): ?>
      <p>Nie masz jeszcze konta. Założysz je od razu: wybierz hasło, a kontem będzie Twój adres <strong><?= h($ssoEmail) ?></strong>.<?= $ssoCloudSelf ? ' Od razu dostaniesz też konto w chmurze plików (' . h($ssoCloudName) . ') z tym samym hasłem.' : '' ?></p>
      <form method="post" action="<?= h($ssoBase . '/sso/konto') ?>" style="max-width:28rem" x-data="{p1:'',p2:''}">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-2">
          <label for="ssoP1" class="form-label fw-semibold">Hasło</label>
          <input type="password" id="ssoP1" name="password" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="ssoP1h" x-model="p1">
          <div id="ssoP1h" class="form-text">Co najmniej 10 znaków. Najlepiej kilka słów, które łatwo zapamiętasz. Nie używaj hasła do poczty ani do SZO.</div>
        </div>
        <div class="mb-3">
          <label for="ssoP2" class="form-label fw-semibold">Powtórz hasło</label>
          <input type="password" id="ssoP2" name="password2" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" x-model="p2" aria-describedby="ssoP2h">
          <div id="ssoP2h" class="form-text" aria-live="polite"><span x-show="p2.length > 0 && p1 !== p2">Hasła się różnią.</span></div>
        </div>
        <button type="submit" class="tz-btn"><i class="bi bi-person-plus" aria-hidden="true"></i>Utwórz konto</button>
      </form>
      <?php else: ?>
      <p class="mb-0">Nie masz jeszcze konta. Konta logowania jednokrotnego zakłada administrator — <a href="<?= h($ssoBase) ?>/helpdesk/index.php">napisz do helpdesku</a>.</p>
      <?php endif; ?>

    <?php else: ?>
      <dl class="ma-rows mb-3">
        <div class="ma-row"><dt>Login</dt><dd><strong><?= h((string)$ssoAcc['logto_username']) ?></strong> albo <?= h($ssoEmail) ?></dd></div>
        <div class="ma-row"><dt>Hasło ustawione</dt><dd><?= h($ssoDate($ssoAcc['logto_pwd_at'] ?? null)) ?></dd></div>
        <div class="ma-row"><dt>Ostatnie logowanie</dt><dd><?= h($ssoDate($ssoAcc['last_sign_in_at'] ?? null)) ?></dd></div>
        <div class="ma-row"><dt>Chmura plików</dt><dd>
          <?php if ($ssoHasCld): ?>
            <?= h($ssoCloudName) ?>, login <strong><?= h((string)$ssoAcc['cloud_uid']) ?></strong>, to samo hasło
            <?php if (SsoConfig::cloudUrl() !== ''): ?> · <a href="<?= h(SsoConfig::cloudUrl()) ?>" target="_blank" rel="noopener">Otwórz chmurę<span class="visually-hidden"> (nowa karta)</span> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
          <?php else: ?>nie masz konta w chmurze<?php endif; ?>
        </dd></div>
      </dl>
      <?php if (!empty($ssoAcc['logto_pwd_reset_at'])): ?>
        <p class="small mb-3" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Hasło zostało zmienione bezpośrednio na stronie logowania (<?= h($ssoDate($ssoAcc['logto_pwd_reset_at'])) ?>).<?= $ssoHasCld ? ' W chmurze plików działa jeszcze stare hasło. Ustaw hasło poniżej, a znów będzie jedno w obu miejscach.' : '' ?></p>
      <?php endif; ?>

      <h3 class="h6 mt-2">Zmień hasło</h3>
      <form method="post" action="<?= h($ssoBase . '/sso/haslo') ?>" style="max-width:28rem" x-data="{p1:'',p2:''}">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-2">
          <label for="ssoN1" class="form-label fw-semibold">Nowe hasło</label>
          <input type="password" id="ssoN1" name="password" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="ssoN1h" x-model="p1">
          <div id="ssoN1h" class="form-text">Co najmniej 10 znaków.<?= $ssoHasCld ? ' Zmieni się też w chmurze plików.' : '' ?> Hasło do poczty zostaje bez zmian.</div>
        </div>
        <div class="mb-3">
          <label for="ssoN2" class="form-label fw-semibold">Powtórz nowe hasło</label>
          <input type="password" id="ssoN2" name="password2" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" x-model="p2" aria-describedby="ssoN2h">
          <div id="ssoN2h" class="form-text" aria-live="polite"><span x-show="p2.length > 0 && p1 !== p2">Hasła się różnią.</span></div>
        </div>
        <button type="submit" class="tz-btn tz-btn--ghost"><i class="bi bi-key" aria-hidden="true"></i>Zmień hasło</button>
      </form>
    <?php endif; ?>
  </div>
</section>
