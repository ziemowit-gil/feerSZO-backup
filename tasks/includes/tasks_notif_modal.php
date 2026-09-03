<?php
/**
 * tasks/includes/tasks_notif_modal.php
 * Modal "Ustawienia powiadomień" (ładowany AJAX-em z notification_settings.php)
 * — wydzielony z header_tasks.php. Nie wymaga żadnych zmiennych PHP.
 */
?>
<div class="modal fade" id="tskNotifSettingsModal" tabindex="-1"
     aria-labelledby="tskNotifSettingsModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <div class="modal-header py-2 px-3" style="background:#1e40af;border-bottom:none">
        <h5 class="modal-title h6 fw-bold mb-0 text-white" id="tskNotifSettingsModalLabel">
          <i class="bi bi-bell-fill me-2" aria-hidden="true"></i>Ustawienia powiadomień
        </h5>
        <button type="button" class="btn-close btn-close-white btn-sm"
                data-bs-dismiss="modal" aria-label="Zamknij ustawienia powiadomień"></button>
      </div>
      <div class="modal-body p-0 overflow-auto" id="tskNotifSettingsModalBody" style="max-height:82vh">
        <div class="text-center py-5 text-muted">
          <div class="spinner-border spinner-border-sm" role="status">
            <span class="visually-hidden">Ładowanie…</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
