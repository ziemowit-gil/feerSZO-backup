<?php
/**
 * karty30/includes/footer_k30.php — Zamknięcie layoutu TyfloKonsultacje.
 * Zamyka: <main>, .k30-shell, uruchamia Bootstrap JS + dostępny skrypt.
 */
$_org_f = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
?>

</main><!-- /k30-main -->

<footer class="k30-footer" role="contentinfo">
  <span>
    <i class="bi bi-card-checklist me-1" aria-hidden="true" style="color:#7C3AED"></i>
    <strong>TyfloKonsultacje</strong> — Karty 30
    <?php if ($_org_f): ?> · <?= h($_org_f) ?><?php endif; ?>
  </span>
  <span style="color:#9CA3AF">&copy; <?= date('Y') ?> Rejestr Umów NGO &nbsp;<?php try { require_once dirname(dirname(dirname(__DIR__))) . '/includes/version.php'; $__v = app_version()['hash']; echo '<a href="' . APP_URL . '/admin/version.php" style="color:inherit;opacity:.5;font-size:.7rem;font-family:monospace;text-decoration:none">v' . h($__v) . '</a>'; } catch(\Throwable $e) {} ?></span>
</footer>

</div><!-- /k30-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Dostępność: globalne skrypty ───────────────────────────────── */
(function() {
  'use strict';

  // 1. Mobile sidebar toggle
  var sidebar = document.getElementById('k30-nav');
  var topbar  = document.querySelector('.k30-topbar');
  if (sidebar && topbar) {
    // Przycisk hamburger (mobile) — dodaj dynamicznie
    var hbtn = document.createElement('button');
    hbtn.type = 'button';
    hbtn.className = 'k30-sys-link d-md-none';
    hbtn.setAttribute('aria-label', 'Otwórz nawigację');
    hbtn.setAttribute('aria-expanded', 'false');
    hbtn.setAttribute('aria-controls', 'k30-nav');
    hbtn.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i>';
    hbtn.style.cssText = 'margin-right:.5rem;margin-left:.75rem';
    topbar.insertBefore(hbtn, topbar.querySelector('.k30-brand').nextSibling);

    hbtn.addEventListener('click', function() {
      var open = sidebar.classList.toggle('open');
      hbtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      hbtn.setAttribute('aria-label', open ? 'Zamknij nawigację' : 'Otwórz nawigację');
      if (open) {
        // Fokus na pierwszym linku w sidebarze
        var firstLink = sidebar.querySelector('.k30-nav-link');
        if (firstLink) firstLink.focus();
      }
    });

    // Zamknij sidebar po kliknięciu poza nim (mobile)
    document.addEventListener('click', function(e) {
      if (window.innerWidth < 768 && sidebar.classList.contains('open')
          && !sidebar.contains(e.target) && !hbtn.contains(e.target)) {
        sidebar.classList.remove('open');
        hbtn.setAttribute('aria-expanded', 'false');
        hbtn.setAttribute('aria-label', 'Otwórz nawigację');
        hbtn.focus();
      }
    });

    // Zamknij Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        hbtn.setAttribute('aria-expanded', 'false');
        hbtn.focus();
      }
    });
  }

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
  var activePage = document.querySelector('.k30-nav-link[aria-current="page"]');
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
