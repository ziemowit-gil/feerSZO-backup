<?php
/**
 * Partial: lista licencji na oprogramowanie kursanta. Wymaga: $rv_client_id (int).
 * Opcjonalnie (blokada odsłaniania kluczy do akceptacji oświadczenia o poufności):
 * $rv_lic_term_ok (bool), $rv_lic_term (array z ti_term_get('licencje')), $rv_lic_token (string),
 * $rv_lic_redirect_tab (string). Gdy nie podane — nie blokuje (kompatybilność wstecz).
 * Używany przez panel kursanta (zakładka „Licencje") i panel rodzica.
 */
$rv_licenses    = k30_ti_client_licenses((int)$rv_client_id);
$rv_lic_term_ok = $rv_lic_term_ok ?? true;
?>
<?php if (!$rv_licenses): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak przypisanych licencji.
</div>
<?php elseif (!$rv_lic_term_ok): ?>
<?= _ti_terms_acceptance_block($rv_lic_term ?? null, $rv_lic_token ?? '', $rv_lic_redirect_tab ?? 'licencje') ?>
<p class="text-body-secondary small mt-2">
  <i class="bi bi-key me-1" aria-hidden="true"></i>Masz przypisan(ą/e) <?= count($rv_licenses) ?> licencj(ę/e) —
  klucze i dane logowania zobaczysz po zaakceptowaniu oświadczenia powyżej.
</p>
<?php else: $rv_lic_today = date('Y-m-d'); ?>
<div class="row g-3">
  <?php foreach ($rv_licenses as $lic):
    $expired    = !empty($lic['expires_at']) && $lic['expires_at'] < $rv_lic_today;
    $has_secret = $lic['login'] !== '' || $lic['access_key'] !== '';
  ?>
  <div class="col-md-6">
    <div class="card h-100 <?= $expired ? 'border-danger' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:38px;height:38px;background:#eff6ff;color:#2563eb;font-size:1.1rem">
            <i class="bi bi-key-fill" aria-hidden="true"></i>
          </div>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-bold"><?= h($lic['license_name']) ?></div>
            <div class="text-body-secondary small">
              <?= $lic['vendor'] ? h($lic['vendor']) : '' ?><?= $lic['category'] ? ' · '.h($lic['category']) : '' ?>
            </div>
          </div>
          <?php if ($expired): ?>
          <span class="badge text-bg-danger">wygasła</span>
          <?php endif; ?>
        </div>

        <?php if ($has_secret): ?>
        <dl class="row mb-2 small">
          <?php if ($lic['login'] !== ''): ?>
          <dt class="col-4 text-body-secondary fw-normal">Login</dt>
          <dd class="col-8 font-monospace mb-1"><?= h($lic['login']) ?></dd>
          <?php endif; ?>
          <?php if ($lic['access_key'] !== ''): ?>
          <dt class="col-4 text-body-secondary fw-normal">Klucz/hasło</dt>
          <dd class="col-8 mb-1">
            <span class="font-monospace lic-secret" data-secret="<?= h($lic['access_key']) ?>">••••••••</span>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1 lic-reveal" aria-label="Pokaż/ukryj">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </dd>
          <?php endif; ?>
        </dl>
        <?php endif; ?>

        <?php if (!empty($lic['notes'])): ?>
        <div class="small text-body-secondary mb-2"><?= h($lic['notes']) ?></div>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if (!empty($lic['vendor_url'])): ?>
          <a href="<?= h($lic['vendor_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz / zaloguj
          </a>
          <?php endif; ?>
          <?php if (!empty($lic['expires_at'])): ?>
          <span class="small <?= $expired ? 'text-danger' : 'text-body-secondary' ?>">
            <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>ważna do <?= h($lic['expires_at']) ?>
          </span>
          <?php else: ?>
          <span class="small text-body-secondary"><i class="bi bi-infinity me-1" aria-hidden="true"></i>bezterminowo</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('.lic-reveal').forEach(function(btn){
  btn.addEventListener('click', function(){
    var span = btn.parentElement.querySelector('.lic-secret');
    var icon = btn.querySelector('i');
    if (span.dataset.shown === '1') {
      span.textContent = '••••••••'; span.dataset.shown = '0'; icon.className = 'bi bi-eye';
    } else {
      span.textContent = span.getAttribute('data-secret'); span.dataset.shown = '1'; icon.className = 'bi bi-eye-slash';
    }
  });
});
</script>
<?php endif; ?>
