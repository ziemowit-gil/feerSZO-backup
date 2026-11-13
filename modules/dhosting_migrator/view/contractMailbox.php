<?php
/**
 * modules/dhosting_migrator/view/contractMailbox.php — karta „Skrzynka pocztowa” dla umowy. Dołączana:
 *  • w widoku umowy (zakładka M365): contracts/{wolontariat,zlecenie,dzielo}/view.php — wygląd Bootstrap SZO,
 *  • w Systemie Tożsamości: tozsamosc/access.php („Zarządzanie dostępem”) — przed include ustaw $DH_BOX_SKIN = 'tz'
 *    (klasy .tz-* z tozsamosc/_head.php; po akcji powrót na tę stronę, nie do umowy).
 * Zmienne: $TYPE, $id, $row (wiersz umowy).
 * Administrator: nowe hasło (raz na ekranie, opcjonalnie SMS), zawieś (bez powiadomienia), przywróć, załóż — POST
 * /migracjaadmin/skrzynka (DhostingMigratorController::boxPost). Nowe hasło pokazujemy tylko raz (DhostingContractMail::takeNew).
 */
require_once dirname(__DIR__) . '/logic/DhostingContractMail.php';
try {
    $__dhBox = DhostingContractMail::boxForContract((string)$TYPE, (int)$id);
    $__dhNew = DhostingContractMail::takeNew((string)$TYPE, (int)$id);
    $__dhOn  = DhostingContractMail::enabled();
} catch (\Throwable $__e) { $__dhBox = null; $__dhNew = null; $__dhOn = false; }
$__dhTz    = ($DH_BOX_SKIN ?? '') === 'tz';
$__dhC     = $__dhTz
    ? ['card' => 'tz-card', 'hd' => 'tz-card__hd', 'h' => 'h6 mb-0 fw-semibold', 'bd' => 'tz-card__bd small',
       'ok' => 'tz-badge tz-badge--ok ms-auto', 'off' => 'tz-badge tz-badge--off ms-auto',
       'btn' => 'tz-btn tz-btn--sm', 'danger' => 'tz-btn tz-btn--danger tz-btn--sm', 'muted' => 'text-muted']
    : ['card' => 'card shadow-sm mb-3', 'hd' => 'card-header fw-semibold d-flex justify-content-between align-items-center', 'h' => 'h6 mb-0', 'bd' => 'card-body small',
       'ok' => 'badge bg-success', 'off' => 'badge bg-secondary',
       'btn' => 'btn btn-sm btn-primary', 'danger' => 'btn btn-sm btn-outline-danger', 'muted' => 'text-body-secondary'];
$__dhAdmin = function_exists('is_admin') && is_admin();
$__dhPhone = (string)($row['telefon'] ?? '');
$__dhMob   = $__dhPhone !== '' && function_exists('sms_is_mobile') && sms_is_mobile($__dhPhone);
$__dhPost  = APP_URL . '/migracjaadmin/skrzynka';
$__dhHid   = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="type" value="' . h((string)$TYPE) . '"><input type="hidden" name="id" value="' . (int)$id . '">'
           . ($__dhTz ? '<input type="hidden" name="ret" value="tz">' : '');
$__dhSusp  = ($__dhBox['status'] ?? '') === 'suspended';
if ($__dhBox || $__dhOn || $__dhTz): ?>
<section class="<?= $__dhC['card'] ?>" id="skrzynka" aria-labelledby="dhBoxH">
  <div class="<?= $__dhC['hd'] ?>">
    <?php if ($__dhTz): ?><i class="bi bi-envelope-at" aria-hidden="true"></i><?php endif; ?>
    <h2 id="dhBoxH" class="<?= $__dhC['h'] ?>"><?php if (!$__dhTz): ?><i class="bi bi-envelope-at" aria-hidden="true"></i> <?php endif; ?>Skrzynka pocztowa</h2>
    <?php if ($__dhBox): ?>
      <span class="<?= $__dhSusp ? $__dhC['off'] : $__dhC['ok'] ?>"><?= $__dhSusp ? 'Zawieszona' : 'Aktywna' ?></span>
    <?php elseif ($__dhTz): ?>
      <span class="<?= $__dhC['off'] ?>">Brak skrzynki</span>
    <?php endif; ?>
  </div>
  <div class="<?= $__dhC['bd'] ?>">
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
      <p class="<?= $__dhC['muted'] ?> mb-2">Skrzynka jest zawieszana automatycznie <?= DhostingContractMail::GRACE_DAYS ?> dni po wygaśnięciu umowy (gdy osoba nie ma innej trwającej umowy, chyba że zaznaczono „nie wyłączaj”) — bez powiadamiania użytkownika.</p>
      <?php if ($__dhAdmin): ?>
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <form method="post" action="<?= h($__dhPost) ?>" class="d-flex flex-wrap gap-2 align-items-center m-0" onsubmit="return confirm('Ustawić nowe hasło do skrzynki? Dotychczasowe przestanie działać.')">
          <?= $__dhHid ?><input type="hidden" name="op" value="<?= $__dhSusp ? 'restore' : 'password' ?>">
          <?php if ($__dhMob): ?>
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="sms" value="1" id="dhSms" checked>
              <label class="form-check-label" for="dhSms">wyślij hasło SMS-em na <?= h(DhostingMigrator::maskPhone($__dhPhone)) ?></label></div>
          <?php endif; ?>
          <button type="submit" class="<?= $__dhC['btn'] ?>"><i class="bi bi-key" aria-hidden="true"></i> <?= $__dhSusp ? 'Przywróć i ustaw nowe hasło' : 'Ustaw nowe hasło' ?></button>
        </form>
        <?php if (!$__dhSusp): ?>
        <form method="post" action="<?= h($__dhPost) ?>" class="m-0" onsubmit="return confirm('Zawiesić skrzynkę? Zostanie wyłączona i dostanie losowe hasło. Użytkownik nie dostanie powiadomienia.')">
          <?= $__dhHid ?><input type="hidden" name="op" value="suspend">
          <button type="submit" class="<?= $__dhC['danger'] ?>"><i class="bi bi-pause-circle" aria-hidden="true"></i> Zawieś</button>
        </form>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <p class="<?= $__dhC['muted'] ?> mb-0">Nowe hasło, zawieszenie i przywrócenie skrzynki — tylko administrator.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-2">Ta umowa nie ma skrzynki pocztowej.</p>
      <?php if (!$__dhOn): ?>
      <p class="<?= $__dhC['muted'] ?> mb-0">Zakładanie skrzynek przy umowach jest wyłączone albo serwer poczty nie jest skonfigurowany<?= $__dhAdmin ? ' — <a href="' . h(APP_URL . '/migracjaadmin#ustawienia') . '">ustawienia migratora poczty</a>' : '' ?>.</p>
      <?php elseif ($__dhAdmin): ?>
      <form method="post" action="<?= h($__dhPost) ?>" class="d-flex flex-wrap gap-2 align-items-center m-0">
        <?= $__dhHid ?><input type="hidden" name="op" value="create">
        <button type="submit" class="<?= $__dhC['btn'] ?>"><i class="bi bi-envelope-plus" aria-hidden="true"></i> Załóż skrzynkę pocztową</button>
        <span class="<?= $__dhC['muted'] ?>">imie.nazwisko@<?= h(DhostingContractMail::domain()) ?> albo dawna skrzynka tej osoby (po PESEL-u)<?= $__dhMob ? '; hasło SMS-em na ' . h(DhostingMigrator::maskPhone($__dhPhone)) : '' ?>.</span>
      </form>
      <?php else: ?>
      <p class="<?= $__dhC['muted'] ?> mb-0">Skrzynkę zakłada administrator.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
