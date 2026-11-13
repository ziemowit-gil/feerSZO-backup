<?php
/**
 * modules/sso_logto/view/contractCard.php — karta „Logowanie jednokrotne i chmura” w Zarządzaniu dostępem (tozsamosc/access.php).
 * Zmienne z access.php: $TYPE, $id, $row, $email, $has_m365, $sms_ok, $vol_phone. Akcje (tylko administrator) idą na POST /ssoadmin/osoba.
 */
require_once dirname(__DIR__) . '/logic/SsoController.php';

$ssoAcc     = ($email ?? '') !== '' ? SsoAccounts::get($email) : null;
$ssoRows    = SsoController::takeResult($TYPE . ':' . $id);
$ssoReady   = SsoConfig::logtoConfigured();
$ssoCloudOk = SsoConfig::cloudConfigured();
$ssoHas     = $ssoAcc && !empty($ssoAcc['logto_id']);
$ssoHasCld  = $ssoAcc && !empty($ssoAcc['cloud_uid']);
$ssoJob     = $ssoHasCld ? CloudFilesCopy::latestFor((string)$ssoAcc['cloud_uid']) : null;
$ssoAdmin   = function_exists('is_admin') && is_admin();
$ssoFilesOk = !empty($has_m365) && CloudFilesCopy::available();
$ssoAction  = rtrim(APP_URL, '/') . '/ssoadmin/osoba';
$ssoDate    = fn(?string $d) => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$ssoHidden  = function (string $do) use ($TYPE, $id): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="type" value="' . h($TYPE) . '">'
         . '<input type="hidden" name="id" value="' . (int)$id . '"><input type="hidden" name="do" value="' . h($do) . '">';
};
?>
<section class="tz-card" id="sso" aria-labelledby="ssoCardH">
  <div class="tz-card__hd"><i class="bi bi-person-badge" aria-hidden="true"></i>
    <span id="ssoCardH">Logowanie jednokrotne (Logto) i chmura plików</span>
    <span class="tz-badge <?= $ssoHas ? 'tz-badge--ok' : 'tz-badge--off' ?> ms-auto"><?= $ssoHas ? 'Konto istnieje' : 'Brak konta' ?></span>
  </div>
  <div class="tz-card__bd">
    <?php if ($ssoRows) { $ssoResId = 'ssoResult'; include __DIR__ . '/_result.php'; } ?>

    <?php if (!$ssoReady): ?>
      <p class="text-muted mb-0">Logowanie jednokrotne nie jest jeszcze skonfigurowane.<?php if ($ssoAdmin): ?> <a href="<?= h(rtrim(APP_URL, '/') . '/ssoadmin') ?>">Ustawienia logowania jednokrotnego</a><?php endif; ?></p>
    <?php else: ?>
      <dl class="row small mb-2">
        <dt class="col-sm-4 fw-normal text-muted">Login w Logto</dt>
        <dd class="col-sm-8 mb-1"><?= $ssoHas ? '<strong>' . h((string)$ssoAcc['logto_username']) . '</strong> albo ' . h($email) : '—' ?></dd>
        <?php if ($ssoHas): ?>
        <dt class="col-sm-4 fw-normal text-muted">Hasło ustawione w SZO</dt><dd class="col-sm-8 mb-1"><?= h($ssoDate($ssoAcc['logto_pwd_at'] ?? null)) ?></dd>
        <dt class="col-sm-4 fw-normal text-muted">Ostatnie logowanie</dt><dd class="col-sm-8 mb-1"><?= h($ssoDate($ssoAcc['last_sign_in_at'] ?? null)) ?></dd>
        <?php endif; ?>
        <dt class="col-sm-4 fw-normal text-muted">Chmura (<?= h(SsoConfig::cloudLabel()) ?>)</dt>
        <dd class="col-sm-8 mb-1"><?= $ssoHasCld ? 'konto <strong>' . h((string)$ssoAcc['cloud_uid']) . '</strong> — to samo hasło co w Logto' : ($ssoCloudOk ? 'brak konta' : 'chmura nieskonfigurowana') ?></dd>
        <?php if ($ssoJob): ?>
        <dt class="col-sm-4 fw-normal text-muted">Pliki z OneDrive</dt>
        <dd class="col-sm-8 mb-1"><?= h(CloudFilesCopy::LABELS[$ssoJob['status']] ?? $ssoJob['status']) ?>: <?= (int)$ssoJob['copied_files'] ?> z <?= (int)$ssoJob['total_files'] ?> plików<?= !empty($ssoJob['last_error']) ? ' · ' . h(mb_substr((string)$ssoJob['last_error'], 0, 160)) : '' ?></dd>
        <?php endif; ?>
      </dl>
      <?php if (!empty($ssoAcc['logto_pwd_reset_at'])): ?>
        <p class="small text-warning-emphasis mb-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Osoba zmieniła hasło bezpośrednio w Logto (<?= h($ssoDate($ssoAcc['logto_pwd_reset_at'])) ?>) — w chmurze może działać jeszcze stare. Nowe hasło nadane tutaj ujednolici oba.</p>
      <?php endif; ?>

      <?php if ($ssoAdmin): ?>
      <div class="d-flex gap-2 flex-wrap align-items-start">
        <?php if (!$ssoHas): ?>
        <form method="post" action="<?= h($ssoAction) ?>" class="m-0 p-2 border rounded" onsubmit="return confirm(<?= h(json_encode('Założyć konto logowania jednokrotnego dla ' . $email . '? Hasło zostanie wygenerowane i pokazane raz.', JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $ssoHidden('create') ?>
          <?php if ($ssoCloudOk): ?>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="cloud" value="1" id="ssoC1" checked><label class="form-check-label" for="ssoC1">Załóż też konto w chmurze (to samo hasło)</label></div>
          <?php endif; ?>
          <?php if ($ssoFilesOk): ?>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="files" value="1" id="ssoF1"><label class="form-check-label" for="ssoF1">Skopiuj pliki z OneDrive do chmury (w tle)</label></div>
          <?php endif; ?>
          <?php if (!empty($sms_ok) && ($vol_phone ?? '') !== ''): ?>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="sms" value="1" id="ssoS1"><label class="form-check-label" for="ssoS1">Wyślij login i hasło SMS-em na <?= h($vol_phone) ?></label></div>
          <?php endif; ?>
          <button class="tz-btn btn-sm mt-1" type="submit"><i class="bi bi-person-plus" aria-hidden="true"></i> Utwórz konto</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= h($ssoAction) ?>" class="m-0" onsubmit="return confirm('Nadać nowe hasło? Zmieni się w Logto<?= $ssoHasCld ? ' i w chmurze' : '' ?>, stare przestanie działać.')">
          <?= $ssoHidden('password') ?>
          <?php if (!empty($sms_ok) && ($vol_phone ?? '') !== ''): ?>
          <div class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="sms" value="1" id="ssoS2"><label class="form-check-label" for="ssoS2">SMS z nowym hasłem na <?= h($vol_phone) ?></label></div>
          <?php endif; ?>
          <button class="tz-btn tz-btn--ghost btn-sm" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Nowe hasło (Logto<?= $ssoHasCld ? ' + chmura' : '' ?>)</button>
        </form>
        <?php if (!$ssoHasCld && $ssoCloudOk): ?>
        <form method="post" action="<?= h($ssoAction) ?>" class="m-0 p-2 border rounded" onsubmit="return confirm('Założyć konto w chmurze? Żeby hasło było takie samo, SZO nada NOWE hasło — w Logto i w chmurze.')">
          <?= $ssoHidden('cloud') ?>
          <?php if ($ssoFilesOk): ?>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="files" value="1" id="ssoF2"><label class="form-check-label" for="ssoF2">Skopiuj pliki z OneDrive (w tle)</label></div>
          <?php endif; ?>
          <?php if (!empty($sms_ok) && ($vol_phone ?? '') !== ''): ?>
          <div class="form-check small"><input class="form-check-input" type="checkbox" name="sms" value="1" id="ssoS3"><label class="form-check-label" for="ssoS3">SMS z nowym hasłem</label></div>
          <?php endif; ?>
          <button class="tz-btn btn-sm mt-1" type="submit"><i class="bi bi-cloud-plus" aria-hidden="true"></i> Utwórz konto w chmurze</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <p class="text-muted mt-2 mb-0" style="font-size:.8rem"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Chmura ma zawsze to samo hasło co Logto. Hasło do poczty jest odrębne — nie zmienia się tutaj.</p>
      <?php else: ?>
      <p class="text-muted small mb-0">Konta logowania jednokrotnego zakłada administrator.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
