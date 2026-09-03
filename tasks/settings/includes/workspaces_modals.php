<?php
/**
 * tasks/settings/includes/workspaces_modals.php
 * Modale: Nowy obszar, Kolumna, Usuń obszar. Wydzielone z workspaces.php.
 * Nie wymaga żadnych dodatkowych zmiennych poza csrf_token()/APP_URL.
 */
?>
<!-- Modal: Nowy obszar -->
<div class="modal fade" id="wsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_workspace">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold">Nowy obszar roboczy</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="name" id="wsModalName" class="form-control form-control-sm" required maxlength="120" placeholder="np. Projekt 2026">
          </div>
          <div class="row g-2 mb-3">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor</label>
              <input type="color" name="color" class="form-control form-control-sm form-control-color" value="#2563eb">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Ikona (bez bi-)</label>
              <input type="text" name="icon" class="form-control form-control-sm" value="kanban" placeholder="kanban">
            </div>
          </div>

          <?php if (ws_available()): ?>
          <hr class="my-2">
          <div class="mb-1">
            <label class="form-label small fw-semibold mb-1">
              <i class="bi bi-folder2-open me-1 text-primary"></i>Foldery w SharePoint
              <span class="text-muted fw-normal">(opcjonalnie)</span>
            </label>
            <div id="wsFolderList" class="d-flex flex-column gap-1 mb-1"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="wsAddFolder" style="font-size:.8rem">
              <i class="bi bi-folder-plus me-1"></i>Dodaj folder
            </button>
            <div class="form-text" style="font-size:.72rem">Foldery zostaną automatycznie utworzone w SharePoint po zapisaniu obszaru.</div>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Utwórz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Kolumna -->
<div class="modal fade" id="listModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save_list">
        <input type="hidden" name="ws_id"   id="lm-ws-id"   value="">
        <input type="hidden" name="list_id" id="lm-list-id" value="">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold" id="lm-title">Kolumna</h6>
          <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nazwa *</label>
            <input type="text" name="lname" id="lm-name" class="form-control form-control-sm" required maxlength="120">
          </div>
          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label small fw-semibold">Kolor paska</label>
              <input type="color" name="lcolor" id="lm-color" class="form-control form-control-sm form-control-color" value="#e2e8f0">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">Limit WIP</label>
              <input type="number" name="wip_limit" id="lm-wip" class="form-control form-control-sm" min="0" placeholder="Brak">
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_done_state" value="1" id="lm-done">
            <label class="form-check-label small" for="lm-done">Kolumna „ukończone"</label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Usuń obszar -->
<div class="modal fade" id="deleteWsModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content border-danger border-opacity-50">
      <form method="post">
        <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"      value="delete_workspace">
        <input type="hidden" name="ws_id"        id="del-ws-id"   value="">
        <input type="hidden" name="force_delete" value="1">
        <div class="modal-header py-2 bg-danger bg-opacity-10">
          <h6 class="modal-title fw-bold text-danger"><i class="bi bi-trash3 me-1"></i>Usuń obszar</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small mb-2">Usuwasz obszar: <strong id="del-ws-name"></strong></p>
          <div id="del-ws-warn" class="alert alert-warning small py-2 mb-2 d-none">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Obszar zawiera <strong id="del-ws-cnt"></strong> zadań — zostaną usunięte bezpowrotnie.
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="del-ws-chk" required>
            <label class="form-check-label small fw-semibold text-danger" for="del-ws-chk">
              Rozumiem, usuń bezpowrotnie
            </label>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash3 me-1"></i>Usuń</button>
        </div>
      </form>
    </div>
  </div>
</div>
