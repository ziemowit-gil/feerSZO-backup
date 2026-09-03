<?php
/**
 * tasks/settings/includes/workspaces_tab_settings.php
 * Zakładka "Ustawienia" — dane obszaru + role widoczności/edycji + usuwanie.
 * Wydzielone z workspaces.php. Oczekuje: $active_ws, $active_ws_id, $ws_task_count.
 */
$all_roles_for_ws = db_all("SELECT name, display_name FROM roles ORDER BY display_name");
$ws_visible_roles = json_decode($active_ws['visible_roles'] ?? '', true) ?: [];
$ws_edit_roles    = json_decode($active_ws['edit_roles']    ?? '', true) ?: [];
?>
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="save_workspace">
      <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
      <div class="col-sm-8">
        <label class="form-label small fw-semibold">Nazwa *</label>
        <input type="text" name="name" class="form-control form-control-sm"
               value="<?= h($active_ws['name']) ?>" required maxlength="120">
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold">Kolor</label>
        <input type="color" name="color" class="form-control form-control-sm form-control-color"
               value="<?= h($active_ws['color']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold">Opis</label>
        <textarea name="description" class="form-control form-control-sm" rows="2"><?= h($active_ws['description']) ?></textarea>
      </div>
      <div class="col-sm-6">
        <label class="form-label small fw-semibold">Ikona (bez bi-)</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi <?= h($active_ws['icon']) ?>" id="ws-icon-preview"></i></span>
          <input type="text" name="icon" id="ws-icon-input" class="form-control form-control-sm"
                 value="<?= h(ltrim($active_ws['icon'],'bi-')) ?>" placeholder="kanban">
        </div>
      </div>

      <?php if ($all_roles_for_ws): ?>
      <!-- ── Role widoczności / edycji ── -->
      <div class="col-12">
        <hr class="my-1">
        <div class="fw-semibold small mb-2">
          <i class="bi bi-shield-lock me-1 text-primary"></i>Uprawnienia ról systemowych
        </div>
        <p class="text-muted small mb-2">
          Puste = brak ograniczeń (każda rola z dostępem do obszaru).
          Administratorzy systemu mają zawsze pełny dostęp.
        </p>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label small fw-semibold text-secondary">
              <i class="bi bi-eye me-1"></i>Może widzieć obszar (<code>visible_roles</code>)
            </label>
            <div class="border rounded p-2" style="max-height:160px;overflow-y:auto;background:#fafafa">
              <?php foreach ($all_roles_for_ws as $r): ?>
              <div class="form-check form-check-sm mb-1">
                <input class="form-check-input" type="checkbox"
                       name="visible_roles[]" value="<?= h($r['name']) ?>"
                       id="vr_<?= h($r['name']) ?>"
                       <?= in_array($r['name'], $ws_visible_roles) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="vr_<?= h($r['name']) ?>">
                  <?= h($r['display_name']) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold text-secondary">
              <i class="bi bi-pencil-square me-1"></i>Może edytować (<code>edit_roles</code>)
            </label>
            <div class="border rounded p-2" style="max-height:160px;overflow-y:auto;background:#fafafa">
              <?php foreach ($all_roles_for_ws as $r): ?>
              <div class="form-check form-check-sm mb-1">
                <input class="form-check-input" type="checkbox"
                       name="edit_roles[]" value="<?= h($r['name']) ?>"
                       id="er_<?= h($r['name']) ?>"
                       <?= in_array($r['name'], $ws_edit_roles) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="er_<?= h($r['name']) ?>">
                  <?= h($r['display_name']) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-check2 me-1"></i>Zapisz zmiany
        </button>
        <form method="post" class="d-inline mb-0">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="toggle_workspace">
          <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
          <button type="submit" class="btn btn-sm <?= $active_ws['is_active']?'btn-outline-warning':'btn-outline-success' ?>">
            <i class="bi bi-<?= $active_ws['is_active']?'pause':'play' ?> me-1"></i>
            <?= $active_ws['is_active']?'Dezaktywuj':'Aktywuj' ?>
          </button>
        </form>
        <button type="button" class="btn btn-sm btn-outline-danger ms-auto"
                data-bs-toggle="modal" data-bs-target="#deleteWsModal"
                onclick="prepareDeleteWs('<?= h(addslashes($active_ws['name'])) ?>',<?= $active_ws_id ?>,<?= $ws_task_count ?>)">
          <i class="bi bi-trash3 me-1"></i>Usuń obszar
        </button>
      </div>
    </form>
  </div>
</div>
