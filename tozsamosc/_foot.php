<?php
/** tozsamosc/_foot.php — zamknięcie layoutu podsystemu Tożsamość. */
$__org = defined('ORG_NAME') ? ORG_NAME : '';
?>
</main>
<footer class="tz-foot" style="max-width:1160px;margin:0 auto;padding:1.25rem 1rem 2rem;color:#7d8aa6;font-size:.76rem;display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1.25rem;border-top:1px solid var(--tz-line)">
  <span style="display:inline-flex;align-items:center;gap:.4rem;font-weight:600;color:#56637f"><i class="bi bi-shield-lock-fill" aria-hidden="true" style="color:var(--tz)"></i>System Tożsamości</span>
  <span><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>połączenie szyfrowane</span>
  <span style="margin-left:auto">© <?= date('Y') ?> <?= h($__org) ?></span>
  <a href="<?= APP_URL ?>/tozsamosc/index.php#opanelu" style="color:inherit;text-decoration:none;font-weight:500"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>O panelu</a>
  <a href="<?= APP_URL ?>/helpdesk/index.php" style="color:inherit;text-decoration:none;font-weight:500"><i class="bi bi-headset me-1" aria-hidden="true"></i>Pomoc</a>
</footer>
<?php /* Bootstrap JS (offcanvas/modale/dropdowny) — Zarządzanie użytkownikami w tym
         chrome używa paneli data-bs-toggle; bez bundle'a przyciski „Z katalogu AD"
         i „Dodaj użytkownika" były martwe (head ładuje tylko CSS 5.3.3). */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
