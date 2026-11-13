<?php
/**
 * modules/sso_logto/view/migratorBar.php — w formularzu migratora (#dhmForm): konta Logto + chmura + pliki z OneDrive
 * dla zaznaczonych kont Microsoft 365. Przycisk wysyła ten sam formularz na POST /migracjaadmin/pliki (formaction).
 */
require_once dirname(__DIR__) . '/logic/CloudFilesCopy.php';
// Wystarczy chmura (polecenie 2026-11-13: „nie mam takiego przycisku”) — bez Logto zakładamy samo konto w chmurze + pliki.
$ssoMigReady = SsoConfig::cloudConfigured();
$ssoMigLogto = SsoConfig::logtoConfigured();
$ssoMigFiles = CloudFilesCopy::available();
?>
<div class="dhm-bar d-flex flex-wrap align-items-center gap-3 border-top-0 pt-0" aria-labelledby="ssoMigH">
  <span id="ssoMigH" class="small fw-semibold"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Chmura (<?= h(SsoConfig::cloudLabel()) ?>):</span>
  <?php if (!$ssoMigReady): ?>
    <span class="small text-body-secondary">najpierw skonfiguruj chmurę w <a href="<?= h(rtrim(APP_URL, '/')) ?>/ssoadmin">ustawieniach logowania jednokrotnego i chmury</a>.</span>
  <?php else: ?>
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="files_copy" value="1" id="ssoFilesCopy" <?= $ssoMigFiles ? 'checked' : 'disabled' ?> aria-describedby="ssoFilesCopyH">
      <label class="form-check-label small" for="ssoFilesCopy">Skopiuj pliki z OneDrive do chmury (w tle)</label>
      <span id="ssoFilesCopyH" class="visually-hidden"><?= $ssoMigFiles ? 'Pliki trafią do folderu OneDrive w chmurze; kopiowanie działa co 5 minut.' : 'Niedostępne: brak integracji Microsoft 365.' ?></span>
    </div>
    <div class="form-check mb-0">
      <input class="form-check-input" type="checkbox" name="files_sms" value="1" id="ssoFilesSms">
      <label class="form-check-label small" for="ssoFilesSms">Wyślij login i hasło SMS-em</label>
    </div>
    <button type="submit" class="btn btn-outline-primary btn-sm ms-auto" id="ssoCloudGo" formaction="<?= h(rtrim(APP_URL, '/') . '/migracjaadmin/pliki') ?>" disabled aria-describedby="ssoCloudGoH">
      <i class="bi bi-cloud-plus" aria-hidden="true"></i> <?= $ssoMigLogto ? 'Konto Logto + chmura' : 'Konto w chmurze + pliki' ?><span id="ssoCloudGoN"></span>
    </button>
    <span id="ssoCloudGoH" class="visually-hidden"><?= $ssoMigLogto ? 'Dla zaznaczonych osób zakłada konto logowania jednokrotnego i konto w chmurze z jednym, nowym hasłem.' : 'Dla zaznaczonych osób zakłada konto w chmurze z nowym hasłem.' ?> Hasło do poczty się nie zmienia.</span>
    <?php if (!$ssoMigLogto): ?>
    <span class="small text-body-secondary w-100">Logowanie jednokrotne (Logto) nie jest jeszcze skonfigurowane — brakuje identyfikatora aplikacji M2M w <a href="<?= h(rtrim(APP_URL, '/')) ?>/ssoadmin">ustawieniach</a>. Teraz powstanie samo konto w chmurze; konto Logto dostanie to samo hasło przy następnym nadaniu.</span>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($ssoMigReady): ?>
<script>
(function () {
  var btn = document.getElementById('ssoCloudGo'), n = document.getElementById('ssoCloudGoN');
  function upd() {
    var c = document.querySelectorAll('#dhmTable .dhm-row:checked').length;
    btn.disabled = c === 0 || c > 25;
    n.textContent = c > 25 ? ' (maks. 25)' : (c ? ' (' + c + ')' : '');
  }
  document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('dhm-check')) upd(); });
  upd();
})();
</script>
<?php endif; ?>
