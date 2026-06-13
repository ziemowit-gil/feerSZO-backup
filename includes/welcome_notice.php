<?php
/**
 * includes/welcome_notice.php — Modal powitalny „świeżo wdrożony system".
 * Dołączany w nagłówku, PO bug_report_widget.php (używa #bugReportModal).
 * Pokazuje się raz na przeglądarkę (localStorage), z opcją „nie pokazuj ponownie".
 * Wymaga: current_user(), APP_URL, Bootstrap 5 JS, ikony Bootstrap.
 *
 * a11y: role=dialog (Bootstrap), aria-labelledby + aria-describedby,
 *       focus trap i obsługa Esc po stronie Bootstrapa, btn-close z aria-label,
 *       zliczanie prefers-reduced-motion (wyłączenie animacji fade).
 */
$_wn_user = current_user();
if (!$_wn_user) return;

// Wersja komunikatu — podbij, gdy chcesz pokazać modal ponownie wszystkim.
$_wn_version = '2026-06-go-live';
?>
<div class="modal fade" id="welcomeNoticeModal" tabindex="-1"
     aria-labelledby="welcomeNoticeLabel"
     aria-describedby="welcomeNoticeBody" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden">

      <div class="modal-header border-0 text-white py-3"
           style="background:linear-gradient(135deg,#2563eb 0%,#4f46e5 100%)">
        <h5 class="modal-title fw-semibold d-flex align-items-center"
            id="welcomeNoticeLabel" style="font-size:1.05rem">
          <i class="bi bi-rocket-takeoff-fill me-2" aria-hidden="true"></i>
          Witamy w nowym systemie!
        </h5>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal" aria-label="Zamknij okno powitalne"></button>
      </div>

      <div class="modal-body px-4 pt-3 pb-2" id="welcomeNoticeBody">
        <p class="mb-3" style="font-size:.92rem;line-height:1.55">
          System został <strong>świeżo wdrożony</strong>. Pracujemy nad tym, aby
          działał bez zarzutu — ale na początku mogą zdarzyć się drobne usterki.
        </p>

        <div class="d-flex align-items-start gap-2 p-3 mb-2 rounded"
             style="background:#fef2f2;border:1px solid #fecaca">
          <i class="bi bi-bug-fill text-danger flex-shrink-0"
             style="font-size:1.15rem;line-height:1.4" aria-hidden="true"></i>
          <div style="font-size:.88rem;line-height:1.5">
            <strong class="d-block mb-1">Zauważyłeś błąd? Daj nam znać.</strong>
            Każde zgłoszenie pomaga nam szybciej poprawiać system.
            Skorzystaj z przycisku <span class="text-danger fw-semibold">
            <i class="bi bi-bug-fill" aria-hidden="true"></i> Zgłoś błąd</span>
            w prawym górnym rogu albo przez nasz helpdesk.
          </div>
        </div>

        <div class="form-check mt-3 mb-1">
          <input class="form-check-input" type="checkbox" id="welcomeNoticeDontShow">
          <label class="form-check-label" for="welcomeNoticeDontShow"
                 style="font-size:.82rem;color:#64748b">
            Nie pokazuj tego okna ponownie
          </label>
        </div>
      </div>

      <div class="modal-footer border-0 px-4 pb-3 pt-1 d-flex flex-wrap gap-2">
        <a href="<?= APP_URL ?>/helpdesk/new.php"
           class="btn btn-sm btn-outline-primary">
          <i class="bi bi-life-preserver me-1" aria-hidden="true"></i>Przejdź do helpdesku
        </a>
        <button type="button" class="btn btn-sm btn-danger" id="welcomeNoticeBug">
          <i class="bi bi-bug-fill me-1" aria-hidden="true"></i>Zgłoś błąd
        </button>
        <button type="button" class="btn btn-sm btn-primary ms-auto"
                data-bs-dismiss="modal">
          Rozumiem, zaczynajmy
        </button>
      </div>

    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('welcomeNoticeModal');
  if (!el || typeof bootstrap === 'undefined') return;

  var KEY = 'feer_welcome_notice_dismissed';
  var VERSION = <?= json_encode($_wn_version) ?>;
  var dontShow = document.getElementById('welcomeNoticeDontShow');
  var bugBtn   = document.getElementById('welcomeNoticeBug');

  // Wyłącz animację, gdy użytkownik woli ograniczony ruch (a11y).
  try {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      el.classList.remove('fade');
    }
  } catch (e) {}

  // Pokaż tylko, jeśli nie odrzucono tej wersji komunikatu.
  var seen;
  try { seen = window.localStorage.getItem(KEY); } catch (e) { seen = null; }
  if (seen !== VERSION) {
    try { new bootstrap.Modal(el).show(); } catch (e) {}
  }

  // Zapamiętaj wybór „nie pokazuj ponownie" przy zamknięciu.
  el.addEventListener('hide.bs.modal', function(){
    if (dontShow && dontShow.checked) {
      try { window.localStorage.setItem(KEY, VERSION); } catch (e) {}
    }
  });

  // „Zgłoś błąd" — zamknij to okno i otwórz modal zgłoszenia błędu (jeśli jest).
  if (bugBtn) {
    bugBtn.addEventListener('click', function(){
      try { window.localStorage.setItem(KEY, VERSION); } catch (e) {}
      var inst = bootstrap.Modal.getInstance(el);
      var bug  = document.getElementById('bugReportModal');
      if (bug) {
        el.addEventListener('hidden.bs.modal', function once(){
          el.removeEventListener('hidden.bs.modal', once);
          try { (bootstrap.Modal.getOrCreateInstance(bug)).show(); } catch (e) {}
        });
      }
      if (inst) inst.hide();
    });
  }
})();
</script>
