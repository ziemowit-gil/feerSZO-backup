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
<div class="row g-3 kp-lic-siatka">
  <?php foreach ($rv_licenses as $lic):
    $expired    = !empty($lic['expires_at']) && $lic['expires_at'] < $rv_lic_today;
    $has_secret = $lic['login'] !== '' || $lic['access_key'] !== '';
  ?>
  <div class="col-md-6">
    <div class="card h-100 kp-lic-kafel<?= $expired ? ' border-danger kp-lic-wygasla' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0 kp-lic-ikona"
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

        <?php
          // Claude: Fundacja kończy rozliczanie licencji 7 dni od 10.10.2026 (komunikat dla kursanta)
          $kp_claude_koniec = '2026-10-17';
          $kp_is_claude = stripos((string)$lic['license_name'] . ' ' . (string)$lic['vendor'], 'claude') !== false
                       || stripos((string)$lic['vendor'], 'anthropic') !== false || stripos((string)$lic['vendor'], 'antropic') !== false;
        ?>
        <?php if ($kp_is_claude): ?>
        <div class="alert alert-warning py-2 small mb-2 kp-lic-uwaga" role="note">
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
          <?php if ($rv_lic_today < $kp_claude_koniec): ?>
            <strong>Uwaga:</strong> od <?= h(date('d.m.Y', strtotime($kp_claude_koniec))) ?> licencja Claude nie będzie już rozliczana przez Fundację.
          <?php else: ?>
            <strong>Uwaga:</strong> od <?= h(date('d.m.Y', strtotime($kp_claude_koniec))) ?> licencja Claude nie jest już rozliczana przez Fundację.
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($has_secret): ?>
        <dl class="row mb-2 small">
          <?php if ($lic['login'] !== ''): ?>
          <dt class="col-4 text-body-secondary fw-normal">Login</dt>
          <dd class="col-8 mb-1">
            <span class="font-monospace kp-lic-wartosc"><?= h($lic['login']) ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1 lic-copy" data-copy="<?= h($lic['login']) ?>"
                    aria-label="Kopiuj login do <?= h($lic['license_name']) ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
          </dd>
          <?php endif; ?>
          <?php if ($lic['access_key'] !== ''): ?>
          <dt class="col-4 text-body-secondary fw-normal">Klucz/hasło</dt>
          <dd class="col-8 mb-1">
            <span class="font-monospace lic-secret kp-lic-wartosc" data-secret="<?= h($lic['access_key']) ?>" aria-label="Klucz ukryty">••••••••</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1 lic-reveal" aria-pressed="false"
                    aria-label="Pokaż klucz do <?= h($lic['license_name']) ?>"><i class="bi bi-eye me-1" aria-hidden="true"></i><span>Pokaż</span></button>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1 lic-copy" data-copy="<?= h($lic['access_key']) ?>"
                    aria-label="Kopiuj klucz do <?= h($lic['license_name']) ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
          </dd>
          <?php endif; ?>
        </dl>
        <?php endif; ?>

        <?php if (!empty($lic['notes'])): ?>
        <div class="small text-body-secondary mb-2"><?= h($lic['notes']) ?></div>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2 flex-wrap kp-lic-stopka">
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

<div class="visually-hidden" id="lic-live" aria-live="polite"></div>
<script>
(function () {
  var live = document.getElementById('lic-live');
  document.querySelectorAll('.lic-reveal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var span = btn.parentElement.querySelector('.lic-secret');
      var icon = btn.querySelector('i'), txt = btn.querySelector('span');
      var show = span.dataset.shown !== '1';
      span.textContent = show ? span.getAttribute('data-secret') : '••••••••';
      span.dataset.shown = show ? '1' : '0';
      span.setAttribute('aria-label', show ? 'Klucz' : 'Klucz ukryty');
      icon.className = show ? 'bi bi-eye-slash me-1' : 'bi bi-eye me-1';
      if (txt) txt.textContent = show ? 'Ukryj' : 'Pokaż';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
  document.querySelectorAll('.lic-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var val = btn.getAttribute('data-copy') || '';
      var done = function () { if (live) live.textContent = 'Skopiowano do schowka.'; btn.classList.add('kp-lic-skopiowano'); setTimeout(function () { btn.classList.remove('kp-lic-skopiowano'); }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(val).then(done, function () {});
    });
  });
})();
</script>
<?php endif; ?>
