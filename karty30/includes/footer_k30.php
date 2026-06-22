<?php
/**
 * karty30/includes/footer_k30.php — Zamknięcie layoutu modułu Dydaktyka (d. TyfloKonsultacje).
 * Zamyka: <main>, .k30-shell, uruchamia Bootstrap JS + dostępny skrypt.
 */
$_org_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>

</main><!-- /k30-main -->

<footer class="k30-footer" role="contentinfo">
  <span>
    <i class="bi bi-card-checklist me-1" aria-hidden="true" style="color:#7C3AED"></i>
    <strong>Dydaktyka</strong> — Karty 30
    <?php if ($_org_f): ?> · <?= h($_org_f) ?><?php endif; ?>
  </span>
  <span style="color:#9CA3AF;font-size:.78rem;display:flex;align-items:center;gap:.5rem">
    <?php $_w3=preg_split('/\s+/',trim($_org_f??'')); if(count($_w3)>=2) echo '<span style="opacity:.55">'.h(implode('',array_map(fn($w)=>mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8'),$_w3))).'</span>'; ?>
    Platforma NGO
    <?php try { require_once dirname(dirname(dirname(__DIR__))) . '/includes/version.php'; $__v = app_version()['main']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.5;font-family:monospace;font-size:.7rem;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?>
  </span>
</footer>

</div><!-- /k30-shell -->

<script>
/* ── Dostępność: globalne skrypty ───────────────────────────────── */
(function() {
  'use strict';

  // 1. Menu główne jest teraz poziomym paskiem na górze (k30-menubar) —
  //    zawsze widoczne i responsywne (zawijanie), więc nie potrzebuje hamburgera.

  // 2. Potwierdzenia usunięcia — ogłoś intencję
  document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
      var msg = el.dataset.confirm || 'Czy na pewno chcesz wykonać tę akcję?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // 3. Powróć focus po załadowaniu strony na #k30-main jeśli URL ma hash
  if (window.location.hash === '#k30-main') {
    var main = document.getElementById('k30-main');
    if (main) main.focus();
  }

  // 4. Auto-fokus na pierwszym błędzie formularza
  var firstErr = document.querySelector('[aria-invalid="true"]');
  if (firstErr) {
    firstErr.focus();
    var live = document.getElementById('k30-live-urgent');
    if (live) live.textContent = 'Formularz zawiera błędy. Sprawdź zaznaczone pola.';
  }

  // 5. Ogłoś aktywną stronę w nawigacji dla czytników
  var activePage = document.querySelector('.k30-menu-link[aria-current="page"], .k30-menubar .dropdown-item[aria-current="page"]');
  if (activePage) {
    var live = document.getElementById('k30-live');
    if (live) live.textContent = 'Jesteś na stronie: ' + activePage.textContent.trim();
  }

  // 6. Tooltip dostępności na skrótach klawiaturowych
  document.querySelectorAll('[data-keyboard]').forEach(function(el) {
    var key = el.dataset.keyboard;
    el.setAttribute('title', (el.getAttribute('title') || '') + ' (klawisz: ' + key + ')');
  });

})();
</script>
</body>
</html>
