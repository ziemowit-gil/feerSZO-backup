<?php
/**
 * karty30/includes/footer_k30.php — Zamknięcie layoutu modułu Dydaktyka.
 * Zamyka: <main>, ładuje Bootstrap JS i uruchamia skrypty dostępności.
 */
$_org_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>

</main><!-- /k30-main -->

<footer class="border-top bg-body mt-4" role="contentinfo">
  <div class="container-fluid d-flex flex-wrap justify-content-between align-items-center gap-2 py-3 small text-body-secondary k30-main-content">
    <span>
      <i class="bi bi-card-checklist me-1 text-primary" aria-hidden="true"></i>
      <strong>Dydaktyka</strong> — Dydaktyka 3<?php if ($_org_f): ?> · <?= h($_org_f) ?><?php endif; ?>
    </span>
    <span class="d-flex align-items-center gap-2">
      Platforma NGO
      <?php try { require_once dirname(dirname(__DIR__)) . '/includes/version.php'; $__v = app_version()['main']; echo '<a href="' . APP_URL . '/admin/version.php" class="text-body-secondary text-decoration-none font-monospace" style="font-size:.7rem;opacity:.6">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?>
    </span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Dostępność: globalne skrypty ───────────────────────────────── */
(function() {
  'use strict';

  // 1. Potwierdzenia akcji (np. usunięcie)
  document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
      var msg = el.dataset.confirm || 'Czy na pewno chcesz wykonać tę akcję?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // 2. Po załadowaniu z hashem #k30-main — ustaw focus na treści głównej
  if (window.location.hash === '#k30-main') {
    var main = document.getElementById('k30-main');
    if (main) main.focus();
  }

  // 3. Auto-fokus na pierwszym błędzie formularza + ogłoszenie
  var firstErr = document.querySelector('[aria-invalid="true"]');
  if (firstErr) {
    firstErr.focus();
    var live = document.getElementById('k30-live-urgent');
    if (live) live.textContent = 'Formularz zawiera błędy. Sprawdź zaznaczone pola.';
  }

  // 4. Ogłoś aktywną stronę w nawigacji dla czytników
  var activePage = document.querySelector('#k30-nav .nav-link.active, #k30-nav .dropdown-item[aria-current="page"]');
  if (activePage) {
    var live = document.getElementById('k30-live');
    if (live) live.textContent = 'Jesteś na stronie: ' + activePage.textContent.trim();
  }

  // 5. Tooltip dostępności na skrótach klawiaturowych
  document.querySelectorAll('[data-keyboard]').forEach(function(el) {
    var key = el.dataset.keyboard;
    el.setAttribute('title', (el.getAttribute('title') || '') + ' (klawisz: ' + key + ')');
  });

  // 6. Bootstrap tooltips
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
  });

})();
</script>
</body>
</html>
