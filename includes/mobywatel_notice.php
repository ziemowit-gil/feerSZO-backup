<?php
/**
 * includes/mobywatel_notice.php — Modal informacyjny „Niebawem: mObywatel".
 * Dołączany w nagłówku, PO welcome_notice.php.
 * Zapowiedź: weryfikacja tożsamości + eZawieranie umów przez mObywatel.
 * Pokazuje się raz na przeglądarkę (localStorage), z opcją „nie pokazuj ponownie".
 * Wymaga: current_user(), APP_URL, Bootstrap 5 JS, ikony Bootstrap.
 *
 * a11y: role=dialog (Bootstrap), aria-labelledby + aria-describedby,
 *       focus trap i obsługa Esc po stronie Bootstrapa, btn-close z aria-label,
 *       poszanowanie prefers-reduced-motion (wyłączenie animacji fade).
 */
$_mo_user = current_user();
if (!$_mo_user) return;

// Wersja komunikatu — podbij, gdy chcesz pokazać modal ponownie wszystkim.
$_mo_version = '2026-07-mobywatel';
?>
<div class="modal fade" id="mobywatelNoticeModal" tabindex="-1"
     aria-labelledby="mobywatelNoticeLabel"
     aria-describedby="mobywatelNoticeBody" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden">

      <div class="modal-header border-0 text-white py-3"
           style="background:linear-gradient(135deg,#0b7285 0%,#1971c2 100%)">
        <h5 class="modal-title fw-semibold d-flex align-items-center"
            id="mobywatelNoticeLabel" style="font-size:1.05rem">
          <i class="bi bi-phone-fill me-2" aria-hidden="true"></i>
          Niebawem: mObywatel
        </h5>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal" aria-label="Zamknij okno informacyjne"></button>
      </div>

      <div class="modal-body px-4 pt-3 pb-2" id="mobywatelNoticeBody">
        <p class="mb-3" style="font-size:.92rem;line-height:1.55">
          Pracujemy nad integracją z aplikacją <strong>mObywatel</strong>. Wkrótce
          udostępnimy w systemie dwie nowe możliwości:
        </p>

        <div class="d-flex align-items-start gap-2 p-3 mb-2 rounded"
             style="background:#e7f5ff;border:1px solid #a5d8ff">
          <i class="bi bi-person-badge-fill text-primary flex-shrink-0"
             style="font-size:1.15rem;line-height:1.4" aria-hidden="true"></i>
          <div style="font-size:.88rem;line-height:1.5">
            <strong class="d-block mb-1">Weryfikacja tożsamości</strong>
            Szybkie i bezpieczne potwierdzenie tożsamości przy użyciu
            danych z aplikacji mObywatel.
          </div>
        </div>

        <div class="d-flex align-items-start gap-2 p-3 mb-2 rounded"
             style="background:#e7f5ff;border:1px solid #a5d8ff">
          <i class="bi bi-file-earmark-check-fill text-primary flex-shrink-0"
             style="font-size:1.15rem;line-height:1.4" aria-hidden="true"></i>
          <div style="font-size:.88rem;line-height:1.5">
            <strong class="d-block mb-1">eZawieranie umów</strong>
            Zawieranie i podpisywanie umów w pełni elektronicznie,
            bez konieczności drukowania i skanowania dokumentów.
          </div>
        </div>

        <p class="mb-0 mt-3" style="font-size:.82rem;color:#64748b;line-height:1.5">
          Poinformujemy Cię, gdy funkcje będą gotowe do użycia.
        </p>

        <div class="form-check mt-3 mb-1">
          <input class="form-check-input" type="checkbox" id="mobywatelNoticeDontShow">
          <label class="form-check-label" for="mobywatelNoticeDontShow"
                 style="font-size:.82rem;color:#64748b">
            Nie pokazuj tego okna ponownie
          </label>
        </div>
      </div>

      <div class="modal-footer border-0 px-4 pb-3 pt-1 d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary ms-auto"
                data-bs-dismiss="modal">
          Rozumiem
        </button>
      </div>

    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('mobywatelNoticeModal');
  if (!el || typeof bootstrap === 'undefined') return;

  var KEY = 'feer_mobywatel_notice_dismissed';
  var VERSION = <?= json_encode($_mo_version) ?>;
  var dontShow = document.getElementById('mobywatelNoticeDontShow');

  // Wyłącz animację, gdy użytkownik woli ograniczony ruch (a11y).
  try {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      el.classList.remove('fade');
    }
  } catch (e) {}

  // Nie nakładaj się na modal powitalny — pokaż dopiero, gdy tamten zniknie.
  function showThis(){
    var seen;
    try { seen = window.localStorage.getItem(KEY); } catch (e) { seen = null; }
    if (seen === VERSION) return;
    try { new bootstrap.Modal(el).show(); } catch (e) {}
  }

  var welcome = document.getElementById('welcomeNoticeModal');
  if (welcome && welcome.classList.contains('show')) {
    welcome.addEventListener('hidden.bs.modal', function once(){
      welcome.removeEventListener('hidden.bs.modal', once);
      showThis();
    });
  } else {
    showThis();
  }

  // Zapamiętaj wybór „nie pokazuj ponownie" przy zamknięciu.
  el.addEventListener('hide.bs.modal', function(){
    if (dontShow && dontShow.checked) {
      try { window.localStorage.setItem(KEY, VERSION); } catch (e) {}
    }
  });
})();
</script>
