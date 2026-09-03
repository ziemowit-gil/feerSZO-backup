<?php
/**
 * tasks/settings/includes/workspaces_tab_teams.php
 * Zakładka "Zespoły" — przypisywanie zespołów do obszaru (task_workspace_teams).
 * Wydzielone z workspaces.php. Oczekuje: $linked_teams, $linkable_teams, $active_ws_id.
 */
?>
<p class="text-muted small">
  Zespół przypisany tutaj daje dostęp do obszaru WSZYSTKIM swoim członkom,
  dodatkowo obok osób dodanych pojedynczo w zakładce „Członkowie".
  Zarządzanie samymi zespołami (tworzenie, członkowie) — w
  <a href="<?= APP_URL ?>/tasks/settings/teams.php">ustawieniach Zespołów</a>.
</p>
<div class="card border-0 shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3 small">Zespół</th>
          <th class="small">Rola w obszarze</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="ws-teams-tbody">
        <?php if (!$linked_teams): ?>
        <tr id="ws-teams-empty-row"><td colspan="3" class="text-muted small ps-3 py-3 text-center">
          Brak przypisanych zespołów.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($linked_teams as $t): ?>
        <tr data-team-id="<?= (int)$t['id'] ?>">
          <td class="ps-3 small">
            <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= h($t['color']) ?>;margin-right:.4rem"></span>
            <span class="fw-semibold"><?= h($t['name']) ?></span>
            <span class="text-muted" style="font-size:.71rem"> · <?= (int)$t['member_count'] ?> os.</span>
          </td>
          <td>
            <span class="badge bg-<?= $t['role']==='admin'?'danger':($t['role']==='editor'?'primary':($t['role']==='member'?'info':'secondary')) ?>">
              <?= h($t['role']) ?>
            </span>
          </td>
          <td class="text-end pe-3">
            <button type="button" class="btn btn-outline-danger" style="padding:.15rem .4rem;font-size:.72rem"
                    onclick="wsUnlinkTeam(<?= (int)$t['id'] ?>, this)">
              <i class="bi bi-x-lg"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($linkable_teams): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white small fw-semibold py-2">Przypisz zespół do obszaru</div>
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1">Zespół</label>
        <select id="ws-link-team-select" class="form-select form-select-sm">
          <?php foreach ($linkable_teams as $t): ?>
          <option value="<?= (int)$t['id'] ?>" data-name="<?= h($t['name']) ?>" data-color="<?= h($t['color']) ?>" data-members="<?= (int)$t['member_count'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Rola</label>
        <select id="ws-link-team-role" class="form-select form-select-sm">
          <option value="member" selected>Member</option>
          <option value="editor">Editor</option>
          <option value="viewer">Viewer</option>
          <option value="admin">Admin obszaru</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="button" class="btn btn-sm btn-primary" onclick="wsLinkTeam(<?= (int)$active_ws_id ?>)">
          <i class="bi bi-plus-lg me-1"></i>Przypisz
        </button>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<p class="text-muted small">Wszystkie aktywne zespoły są już przypisane do tego obszaru, albo nie istnieje żaden zespół — <a href="<?= APP_URL ?>/tasks/settings/teams.php">utwórz zespół</a>.</p>
<?php endif; ?>
