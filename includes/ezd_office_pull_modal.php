<?php
/* Współdzielony modal „Zapisz zmiany z Office Online" — pyta, czy zapisać
   bieżącą treść pliku edytowanego w Office Online jako nową wersję (domyślnie,
   oryginał zachowany) czy zastąpić nią oryginał w miejscu (nieodwracalne).
   Przyciski .ezd-oop-btn z data-zal (id załącznika EZD) + data-name (nazwa
   pliku do komunikatu). Guard: render raz na stronę. */
if (defined('EZD_OFFICE_PULL_MODAL_RENDERED')) return;
define('EZD_OFFICE_PULL_MODAL_RENDERED', 1);
?>
<div class="modal fade" id="officeOnlinePullModal" tabindex="-1" aria-labelledby="officeOnlinePullModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/ezd/office_online_pull.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" id="oop-id" value="">
        <div class="modal-header py-2">
          <h2 class="modal-title h6 mb-0" id="officeOnlinePullModalLabel"><i class="bi bi-cloud-arrow-down text-success me-2" aria-hidden="true"></i>Zapisz zmiany z Office Online</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3" style="font-size:.85rem">
            Plik <strong id="oop-name" class="font-monospace"></strong> mógł zostać zmieniony podczas edycji w Office Online.
            Wybierz, jak zapisać bieżącą treść:
          </p>
          <div class="form-check mb-2 p-2 border rounded-3">
            <input class="form-check-input" type="radio" name="mode" value="version" id="oop-mode-version" checked>
            <label class="form-check-label" for="oop-mode-version">
              <strong>Zapisz jako nową wersję</strong> <span class="badge bg-success bg-opacity-15 text-success">zalecane</span>
              <div class="text-muted" style="font-size:.76rem">Oryginał zostaje zachowany — zmiany trafiają jako kolejna wersja pliku, dostępna w historii.</div>
            </label>
          </div>
          <div class="form-check p-2 border rounded-3">
            <input class="form-check-input" type="radio" name="mode" value="replace" id="oop-mode-replace">
            <label class="form-check-label" for="oop-mode-replace">
              <strong>Zastąp oryginalny plik</strong>
              <div class="text-muted" style="font-size:.76rem">Nadpisuje bieżący plik bez zachowania poprzedniej treści — nieodwracalne.</div>
            </label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var idInput   = document.getElementById('oop-id');
  var nameLabel = document.getElementById('oop-name');
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.ezd-oop-btn');
    if (!btn) return;
    idInput.value = btn.dataset.zal || '';
    nameLabel.textContent = btn.dataset.name || '';
  });
})();
</script>
