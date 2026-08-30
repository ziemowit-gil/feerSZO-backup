<?php /* ═══════════════════ TAB: DYSK ═══════════════════ */ ?>
<?php
  $oc_reveal  = $_SESSION['owncloud_reveal'] ?? null; unset($_SESSION['owncloud_reveal']);
  $oc_account = owncloud_instructor_account($uid);
  $oc_ready   = owncloud_enabled() && owncloud_admin_configured();
?>
<div class="mt-3">
  <h2 class="h5 fw-bold mb-1"><i class="bi bi-hdd-network text-primary me-2" aria-hidden="true"></i>Mój dysk</h2>
  <p class="text-body-secondary small mb-3">Własne miejsce na pliki w chmurze ownCloud — materiały, kopie notatek, projekty.</p>

  <?php if ($oc_reveal): ?>
  <div class="alert alert-warning shadow-sm" role="alert" style="max-width:520px">
    <h3 class="h6 fw-bold mb-2"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Zapisz dane logowania — pokażemy je tylko raz</h3>
    <dl class="row mb-2 small">
      <dt class="col-4">Adres</dt>
      <dd class="col-8"><a href="<?= h($oc_reveal['url']) ?>" target="_blank" rel="noopener"><?= h($oc_reveal['url']) ?></a></dd>
      <dt class="col-4">Login</dt><dd class="col-8 font-monospace"><?= h($oc_reveal['username']) ?></dd>
      <dt class="col-4">Hasło</dt><dd class="col-8 font-monospace"><?= h($oc_reveal['password']) ?></dd>
      <dt class="col-4">Limit</dt><dd class="col-8"><?= (int)$oc_reveal['quota_mb'] ?> MB</dd>
    </dl>
    <p class="small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Po opuszczeniu tej strony hasła nie pokażemy ponownie — w razie potrzeby zresetuj je przyciskiem poniżej.</p>
  </div>
  <?php endif; ?>

  <?php if (!$oc_ready): ?>
  <div class="alert alert-secondary" role="note">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Konta „Mój dysk” wymagają konta administratora ownCloud, którego administrator systemu jeszcze nie skonfigurował. Spróbuj później.
  </div>
  <?php elseif (!$oc_account): ?>
  <div class="card border-0 shadow-sm" style="max-width:460px">
    <div class="card-body">
      <h3 class="h6 fw-bold mb-2">Nie masz jeszcze konta</h3>
      <p class="small text-body-secondary">
        Utworzymy konto ownCloud z limitem <?= (int)owncloud_setting('instructor_quota_mb', '5120') ?> MB.
        Login i hasło zobaczysz od razu po utworzeniu — zapisz je w bezpiecznym miejscu.
      </p>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"    value="owncloud_create">
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz konto</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="card border-0 shadow-sm" style="max-width:460px">
    <div class="card-body">
      <h3 class="h6 fw-bold mb-2"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>Twoje konto</h3>
      <dl class="row mb-3 small">
        <dt class="col-4">Login</dt><dd class="col-8 font-monospace"><?= h($oc_account['owncloud_username']) ?></dd>
        <dt class="col-4">Limit</dt><dd class="col-8"><?= (int)$oc_account['owncloud_quota_mb'] ?> MB</dd>
        <dt class="col-4">Założone</dt>
        <dd class="col-8"><?= $oc_account['owncloud_created_at'] ? h(date('d.m.Y', strtotime($oc_account['owncloud_created_at']))) : '—' ?></dd>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?= h(rtrim(owncloud_setting('url'), '/')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary">
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz ownCloud
        </a>
        <form method="post" onsubmit="return confirm('Zresetować hasło? Stare hasło stanie się nieprawidłowe.');">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"    value="owncloud_reset">
          <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło</button>
        </form>
        <form method="post" onsubmit="return confirm('UWAGA: to usunie Twoje obecne konto ownCloud WRAZ ZE WSZYSTKIMI plikami, które masz na nim zapisane — nieodwracalnie, bez możliwości odzyskania. Zostanie od razu założone nowe, puste konto z nowym loginem i hasłem. Czy na pewno chcesz kontynuować?');">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"    value="owncloud_recreate">
          <button type="submit" class="btn btn-outline-danger"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Utwórz konto od nowa</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php $owncloud_pomoc_id = 'oc-dyd'; require __DIR__ . '/../_owncloud_pomoc.php'; ?>
</div>
