<?php
/** tozsamosc/_foot.php — zamknięcie layoutu podsystemu Tożsamość. */
$__org = defined('ORG_NAME') ? ORG_NAME : '';
?>
</main>
<footer style="max-width:1100px;margin:0 auto;padding:1rem 1rem 2rem;color:#6b7280;font-size:.78rem;border-top:1px solid #e5e7eb;display:flex;flex-wrap:wrap;gap:.5rem 1.5rem">
  <span><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>System Tożsamości · połączenie szyfrowane</span>
  <a href="<?= APP_URL ?>/helpdesk/index.php" style="color:inherit">Pomoc</a>
  <a href="<?= APP_URL ?>/tozsamosc/index.php#opanelu" style="color:inherit">O panelu</a>
  <span style="margin-left:auto">© <?= date('Y') ?> <?= h($__org) ?></span>
</footer>
<?php /* Bootstrap JS (offcanvas/modale/dropdowny) — Zarządzanie użytkownikami w tym
         chrome używa paneli data-bs-toggle; bez bundle'a przyciski „Z katalogu AD"
         i „Dodaj użytkownika" były martwe (head ładuje tylko CSS 5.3.3). */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
