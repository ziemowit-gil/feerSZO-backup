<?php
/**
 * tasks/settings/includes/workspaces_tab_members.php
 * Zakładka "Członkowie" — wydzielone z workspaces.php.
 * Oczekuje: $members, $active_ws_id, $all_users, $member_ids.
 */
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3 small">Użytkownik</th>
          <th class="small">Rola</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$members): ?>
        <tr><td colspan="3" class="text-muted small ps-3 py-3 text-center">
          Brak przypisanych członków. Administratorzy systemu mają dostęp zawsze.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($members as $m): ?>
        <tr>
          <td class="ps-3 small">
            <div class="fw-semibold"><?= h($m['name']) ?></div>
            <div class="text-muted" style="font-size:.71rem"><?= h($m['email']) ?></div>
          </td>
          <td>
            <span class="badge bg-<?= $m['role']==='admin'?'danger':($m['role']==='editor'?'primary':($m['role']==='member'?'info':'secondary')) ?>">
              <?= h($m['role']) ?>
            </span>
          </td>
          <td class="text-end pe-3">
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"    value="remove_member">
              <input type="hidden" name="ws_id"      value="<?= $active_ws_id ?>">
              <input type="hidden" name="member_uid" value="<?= $m['user_id'] ?>">
              <button class="btn btn-outline-danger" style="padding:.15rem .4rem;font-size:.72rem">
                <i class="bi bi-person-dash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white small fw-semibold py-2">Dodaj użytkownika do obszaru</div>
  <div class="card-body py-3">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="add_member">
      <input type="hidden" name="ws_id"   value="<?= $active_ws_id ?>">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Użytkownik</label>
        <select name="member_uid" class="form-select form-select-sm" required>
          <option value="">— wybierz —</option>
          <?php foreach ($all_users as $u):
            if (!in_array($u['id'], $member_ids)): ?>
          <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
          <?php endif; endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Rola</label>
        <select name="member_role" class="form-select form-select-sm">
          <option value="member" selected>Member</option>
          <option value="editor">Editor</option>
          <option value="viewer">Viewer</option>
          <option value="admin">Admin obszaru</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-person-plus me-1"></i>Dodaj
        </button>
      </div>
    </form>
  </div>
</div>
