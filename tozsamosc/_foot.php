<?php
/** tozsamosc/_foot.php — zamknięcie layoutu podsystemu Tożsamość (main → kolumna treści → powłoka). */
$__org = defined('ORG_NAME') ? ORG_NAME : '';
?>
</main>
<footer class="tzf">
  <span><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>System Tożsamości · połączenie szyfrowane</span>
  <a href="<?= APP_URL ?>/helpdesk/index.php">Pomoc</a>
  <a href="<?= APP_URL ?>/tozsamosc/index.php#opanelu">O panelu</a>
  <span style="margin-left:auto">© <?= date('Y') ?> <?= h($__org) ?></span>
</footer>
  </div><!-- /.tzs-col -->
</div><!-- /.tzs-body -->
</div><!-- /.tzs (x-data tzShell) -->
<?php /* Bootstrap JS (offcanvas/modale/dropdowny) — Zarządzanie użytkownikami w tym
         chrome używa paneli data-bs-toggle; bez bundle'a przyciski „Z katalogu AD"
         i „Dodaj użytkownika" były martwe (head ładuje tylko CSS 5.3.3). */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
