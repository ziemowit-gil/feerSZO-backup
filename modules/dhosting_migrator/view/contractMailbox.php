<?php
/**
 * modules/dhosting_migrator/view/contractMailbox.php — karta „Skrzynka pocztowa” w widoku umowy (zakładka M365),
 * dołączana przez contracts/{wolontariat,zlecenie,dzielo}/view.php. Zmienne: $TYPE, $id, $row.
 * Administrator: nowe hasło (raz na ekranie, opcjonalnie SMS), zawieś (bez powiadomienia), przywróć, załóż — POST
 * /migracjaadmin/skrzynka (DhostingMigratorController::boxPost). Nowe hasło pokazujemy tylko raz (DhostingContractMail::takeNew).
 */
require_once dirname(__DIR__) . '/logic/DhostingContractMail.php';
try {
    $__dhBox = DhostingContractMail::boxForContract((string)$TYPE, (int)$id);
    $__dhNew = DhostingContractMail::takeNew((string)$TYPE, (int)$id);
    $__dhOn  = DhostingContractMail::enabled();
} catch (\Throwable $__e) { $__dhBox = null; $__dhNew = null; $__dhOn = false; }
$__dhAdmin = function_exists('is_admin') && is_admin();
$__dhPhone = (string)($row['telefon'] ?? '');
$__dhMob   = $__dhPhone !== '' && function_exists('sms_is_mobile') && sms_is_mobile($__dhPhone);
$__dhPost  = APP_URL . '/migracjaadmin/skrzynka';
$__dhHid   = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="type" value="' . h((string)$TYPE) . '"><input type="hidden" name="id" value="' . (int)$id . '">';
$__dhSusp  = ($__dhBox['status'] ?? '') === 'suspended';
if ($__dhBox || $__dhOn): ?>
<section class="card shadow-sm mb-3" id="skrzynka" aria-labelledby="dhBoxH">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <h2 id="dhBoxH" class="h6 mb-0"><i class="bi bi-envelope-at" aria-hidden="true"></i> Skrzynka pocztowa</h2>
    <?php if ($__dhBox): ?>
      <span class="badge <?= $__dhSusp ? 'bg-secondary' : 'bg-success' ?>"><?= $__dhSusp ? 'Zawieszona' : 'Aktywna' ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body small">
    <?php if ($__dhNew): ?>
      <div class="alert alert-warning py-2" role="status">
        <strong><?= ['created' => 'Założono skrzynkę', 'restored' => 'Przywrócono skrzynkę', 'password' => 'Ustawiono nowe hasło', 'linked' => 'Skrzynka'][$__dhNew['action']] ?? 'Skrzynka' ?>:</strong>
        <code><?= h($__dhNew['mailbox']) ?></code> · hasło: <code class="user-select-all fs-6"><?= h($__dhNew['password']) ?></code>
        <?= $__dhNew['sms'] !== '' ? ' · SMS: ' . h($__dhNew['sms']) : '' ?>
        <div class="mt-1">Hasło widać tylko teraz — system go nie zapisuje. Przekaż je bezpiecznie (SMS, osobiście), nie zwykłym e-mailem.</div>
      </div>
    <?php endif; ?>

    <?php if ($__dhBox): ?>
      <dl class="row mb-2">
        <dt class="col-sm-4">Adres</dt><dd class="col-sm-8"><code><?= h($__dhBox['mailbox']) ?></code></dd>
        <dt class="col-sm-4">Stan</dt>
        <dd class="col-sm-8"><?= $__dhSusp ? 'zawieszona' . (!empty($__dhBox['suspended_at']) ? ' od ' . h(substr((string)$__dhBox['suspended_at'], 0, 16)) : '') . ' (wyłączona, losowe hasło)' : 'aktywna' ?></dd>
        <dt class="col-sm-4">Pochodzenie</dt><dd class="col-sm-8"><?= ($__dhBox['source'] ?? '') === 'migracja' ? 'przeniesiona z Microsoft 365 (login z umowy)' : 'założona przy umowie' ?></dd>
      </dl>
      <p class="text-body-secondary mb-2">Skrzynka jest zawieszana automatycznie <?= DhostingContractMail::GRACE_DAYS ?> dni po wygaśnięciu umowy (gdy osoba nie ma innej trwającej umowy, chyba że zaznaczono „nie wyłączaj”) — bez powiadamiania użytkownika.</p>
      <?php if ($__dhAdmin): ?>
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <form method="post" action="<?= h($__dhPost) ?>" class="d-flex flex-wrap gap-2 align-items-center" onsubmit="return confirm('Ustawić nowe hasło do skrzynki? Dotychczasowe przestanie działać.')">
          <?= $__dhHid ?><input type="hidden" name="op" value="<?= $__dhSusp ? 'restore' : 'password' ?>">
          <?php if ($__dhMob): ?>
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="sms" value="1" id="dhSms" checked>
              <label class="form-check-label" for="dhSms">wyślij hasło SMS-em na <?= h(DhostingMigrator::maskPhone($__dhPhone)) ?></label></div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-key" aria-hidden="true"></i> <?= $__dhSusp ? 'Przywróć i ustaw nowe hasło' : 'Ustaw nowe hasło' ?></button>
        </form>
        <?php if (!$__dhSusp): ?>
        <form method="post" action="<?= h($__dhPost) ?>" onsubmit="return confirm('Zawiesić skrzynkę? Zostanie wyłączona i dostanie losowe hasło. Użytkownik nie dostanie powiadomienia.')">
          <?= $__dhHid ?><input type="hidden" name="op" value="suspend">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle" aria-hidden="true"></i> Zawieś</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-2">Ta umowa nie ma skrzynki pocztowej.</p>
      <?php if ($__dhAdmin && $__dhOn): ?>
      <form method="post" action="<?= h($__dhPost) ?>" class="d-flex flex-wrap gap-2 align-items-center">
        <?= $__dhHid ?><input type="hidden" name="op" value="create">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-envelope-plus" aria-hidden="true"></i> Załóż skrzynkę pocztową</button>
        <span class="text-body-secondary">imie.nazwisko@<?= h(DhostingContractMail::domain()) ?> albo dawna skrzynka tej osoby (po PESEL-u)<?= $__dhMob ? '; hasło SMS-em na ' . h(DhostingMigrator::maskPhone($__dhPhone)) : '' ?>.</span>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
