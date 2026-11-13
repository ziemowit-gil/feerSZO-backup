<?php
/**
 * modules/sso_logto/view/userSection.php — sekcja #sso „Logowanie jednokrotne” w Tożsamości (tozsamosc/index.php).
 * Zmienne z index.php: $db_user, $has_m365, $sec_title. Formularze idą na POST /sso/konto i /sso/haslo (modules/sso_logto).
 * Przy samodzielnym zakładaniu konta użytkownik sam wybiera hasło — SZO go nie zapisuje (przekazuje do Logto i chmury).
 * Opcja „to samo hasło także do poczty” (polecenie 2026-11-13) — tylko gdy osoba ma skrzynkę w rejestrze migratora; nieobowiązkowa.
 */
require_once dirname(__DIR__) . '/logic/SsoAccounts.php';
$ssoMailBox = null;
try { require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingMigrator.php'; $ssoMailBox = DhostingMigrator::ownMailbox((array)$db_user); } catch (\Throwable $e) { $ssoMailBox = null; }
$ssoMailChk = function (string $id) use ($ssoMailBox): void {
    if ($ssoMailBox === null) return;
    echo '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="mail_too" value="1" id="' . h($id) . '" aria-describedby="' . h($id) . 'h">'
       . '<label class="form-check-label" for="' . h($id) . '">Ustaw to samo hasło także do poczty ' . h($ssoMailBox) . '</label></div>'
       . '<div id="' . h($id) . 'h" class="form-text mb-3">Nie musisz tego zaznaczać. Jeśli zaznaczysz, telefon i Outlook poproszą o nowe hasło do poczty.</div>';
};

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
      <li><i class="bi bi-1-circle-fill" aria-hidden="true"></i><span>Logowanie jednokrotne i chmura plików mają jedno hasło. Zmieniasz je tutaj i od razu działa w obu miejscach.</span></li>
      <li><i class="bi bi-2-circle-fill" aria-hidden="true"></i><span>Poczta ma własne hasło.<?= $ssoMailBox !== null ? ' Jeśli chcesz, ustawisz tu takie samo: zaznacz pole pod formularzem. Bez tego hasło do poczty się nie zmienia.' : ' Zmiana hasła tutaj go nie rusza.' ?></span></li>
      <li><i class="bi bi-3-circle-fill" aria-hidden="true"></i><span>Hasło do SZO<?= $has_m365 ? ' i Microsoft 365' : '' ?> jest osobne. Zmieniasz je w <a href="#bezpieczenstwo" @click.prevent="go('bezpieczenstwo', 'haslo')">Zabezpieczeniach</a>.</span></li>
      <li><i class="bi bi-4-circle-fill" aria-hidden="true"></i><span>Nie używaj tego hasła poza organizacją i nie podawaj go nikomu. Administrator go nie zna i nigdy o nie nie poprosi.</span></li>
    </ul>
  </div>

  <h2 class="ma-h2">Twoje konto</h2>
  <div class="ma-card">
    <?php if (!$ssoReady): ?>
      <p class="mb-0">Logowanie jednokrotne nie jest jeszcze uruchomione w organizacji. Gdy będzie gotowe, konto założysz tutaj.</p>

    <?php elseif (!$ssoHas): ?>
      <?php if (SsoConfig::selfEnabled()): ?>
      <p>Nie masz jeszcze konta. Założysz je od razu: login to Twój adres <strong><?= h($ssoEmail) ?></strong>, a hasło wymyślasz sam(a).<?= $ssoCloudSelf ? ' Razem z nim powstanie konto w chmurze plików (' . h($ssoCloudName) . ') z tym samym hasłem.' : '' ?></p>
      <form method="post" action="<?= h($ssoBase . '/sso/konto') ?>" style="max-width:28rem" x-data="{p1:'',p2:''}">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-2">
          <label for="ssoP1" class="form-label fw-semibold">Hasło</label>
          <input type="password" id="ssoP1" name="password" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="ssoP1h" x-model="p1">
          <div id="ssoP1h" class="form-text">Co najmniej 10 znaków. Najłatwiej zapamiętać kilka słów połączonych w jedno. Nie używaj hasła do SZO.</div>
        </div>
        <div class="mb-3">
          <label for="ssoP2" class="form-label fw-semibold">Powtórz hasło</label>
          <input type="password" id="ssoP2" name="password2" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" x-model="p2" aria-describedby="ssoP2h">
          <div id="ssoP2h" class="form-text" aria-live="polite"><span x-show="p2.length > 0 && p1 !== p2">Hasła się różnią.</span></div>
        </div>
        <?php $ssoMailChk('ssoMailNew'); ?>
        <button type="submit" class="tz-btn"><i class="bi bi-person-plus" aria-hidden="true"></i><?= $ssoCloudSelf ? 'Utwórz konta' : 'Utwórz konto' ?></button>
      </form>
      <?php else: ?>
      <p class="mb-0">Nie masz jeszcze konta. Zakłada je administrator, więc <a href="<?= h($ssoBase) ?>/helpdesk/index.php">napisz do helpdesku</a>.</p>
      <?php endif; ?>

    <?php else: ?>
      <dl class="ma-rows mb-3">
        <div class="ma-row"><dt>Login</dt><dd><strong><?= h((string)$ssoAcc['logto_username']) ?></strong> albo <?= h($ssoEmail) ?></dd></div>
        <div class="ma-row"><dt>Hasło ustawione</dt><dd><?= h($ssoDate($ssoAcc['logto_pwd_at'] ?? null)) ?></dd></div>
        <div class="ma-row"><dt>Ostatnie logowanie</dt><dd><?= h($ssoDate($ssoAcc['last_sign_in_at'] ?? null)) ?></dd></div>
        <div class="ma-row"><dt>Chmura plików</dt><dd>
          <?php if ($ssoHasCld): ?>
            <?= h($ssoCloudName) ?>, login <strong><?= h((string)$ssoAcc['cloud_uid']) ?></strong>, hasło to samo
            <?php if (SsoConfig::cloudUrl() !== ''): ?> · <a href="<?= h(SsoConfig::cloudUrl()) ?>" target="_blank" rel="noopener">Otwórz chmurę<span class="visually-hidden"> (nowa karta)</span> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
          <?php else: ?>nie masz konta w chmurze<?php endif; ?>
        </dd></div>
      </dl>
      <?php if (!empty($ssoAcc['logto_pwd_reset_at'])): ?>
        <p class="small mb-3" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Hasło zmieniłeś(-aś) na stronie logowania (<?= h($ssoDate($ssoAcc['logto_pwd_reset_at'])) ?>).<?= $ssoHasCld ? ' W chmurze plików działa jeszcze stare. Ustaw hasło poniżej, a znów będzie jedno w obu miejscach.' : '' ?></p>
      <?php endif; ?>

      <?php $ssoAddCloud = !$ssoHasCld && $ssoCloudSelf && SsoConfig::selfEnabled();   // ma konto bez chmury: /sso/konto dołoży chmurę z nowym hasłem dla obu ?>
      <h3 class="h6 mt-2"><?= $ssoAddCloud ? 'Załóż konto w chmurze plików' : 'Zmień hasło' ?></h3>
      <?php if ($ssoAddCloud): ?><p class="small">Konta w chmurze (<?= h($ssoCloudName) ?>) jeszcze nie masz. Ustaw nowe hasło, a założymy je od razu i hasło będzie działać w obu miejscach.</p><?php endif; ?>
      <form method="post" action="<?= h($ssoBase . ($ssoAddCloud ? '/sso/konto' : '/sso/haslo')) ?>" style="max-width:28rem" x-data="{p1:'',p2:''}">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-2">
          <label for="ssoN1" class="form-label fw-semibold">Nowe hasło</label>
          <input type="password" id="ssoN1" name="password" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="ssoN1h" x-model="p1">
          <div id="ssoN1h" class="form-text">Co najmniej 10 znaków.<?= $ssoHasCld ? ' Zmieni się też w chmurze plików.' : '' ?><?= $ssoMailBox !== null ? '' : ' Hasło do poczty zostaje bez zmian.' ?></div>
        </div>
        <div class="mb-3">
          <label for="ssoN2" class="form-label fw-semibold">Powtórz nowe hasło</label>
          <input type="password" id="ssoN2" name="password2" class="form-control" required minlength="10" maxlength="128" autocomplete="new-password" x-model="p2" aria-describedby="ssoN2h">
          <div id="ssoN2h" class="form-text" aria-live="polite"><span x-show="p2.length > 0 && p1 !== p2">Hasła się różnią.</span></div>
        </div>
        <?php $ssoMailChk('ssoMailPw'); ?>
        <button type="submit" class="tz-btn tz-btn--ghost"><i class="bi <?= $ssoAddCloud ? 'bi-cloud-plus' : 'bi-key' ?>" aria-hidden="true"></i><?= $ssoAddCloud ? 'Załóż konto w chmurze' : 'Zmień hasło' ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>
