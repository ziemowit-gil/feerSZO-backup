<!--
 tasks/includes/detail_properties.php — wydzielone z tasks/detail.php.
 Siatka właściwości (priorytet/termin/jednostka) + Przypisani. Wymaga: $task, $my_role, $can_edit, $assignees, $all_users, $_actor_is_sys_admin, $_actor_unit_ids.
-->
<!-- ══ WŁAŚCIWOŚCI ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-sliders" aria-hidden="true"></i>Właściwości
  </div>

  <div class="td-props-grid">
    <?php if (task_field_visible('due_date', $my_role)): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-due">
        <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Termin
      </label>
      <?php if (task_field_editable('due_date', $my_role)): ?>
      <div class="d-flex gap-1">
        <input type="date" id="td-due" class="form-control form-control-sm"
               value="<?= h($task['due_date'] ?? '') ?>"
               onchange="tdPatch({due_date:this.value||null}); document.getElementById('td-due-time').disabled = !this.value"
               aria-label="Data terminu zadania">
        <input type="time" id="td-due-time" class="form-control form-control-sm" style="max-width:7rem"
               value="<?= h(task_normalize_due_time($task['due_time'] ?? '') ?? '') ?>"
               <?= empty($task['due_date']) ? 'disabled' : '' ?>
               onchange="tdPatch({due_time:this.value||null})"
               title="Godzina (opcjonalnie) — bez godziny termin mija z końcem dnia"
               aria-label="Godzina terminu (opcjonalnie)">
      </div>
      <?php else: ?>
      <div class="small text-muted"><?= $task['due_date'] ? date_pl($task['due_date']) . (($dt = task_normalize_due_time($task['due_time'] ?? '')) ? ', ' . h($dt) : '') : '—' ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (task_field_visible('start_date', $my_role)): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-start">
        <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Start
      </label>
      <?php if (task_field_editable('start_date', $my_role)): ?>
      <input type="date" id="td-start" class="form-control form-control-sm"
             value="<?= h($task['start_date'] ?? '') ?>"
             onchange="tdPatch({start_date:this.value||null})"
             aria-label="Data rozpoczęcia zadania">
      <?php else: ?>
      <div class="small text-muted"><?= $task['start_date'] ? date_pl($task['start_date']) : '—' ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (task_field_visible('priority', $my_role)): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-priority">
        <i class="bi bi-flag me-1" aria-hidden="true"></i>Priorytet
      </label>
      <?php if (task_field_editable('priority', $my_role)): ?>
      <select id="td-priority" class="form-select form-select-sm"
              onchange="tdPatch({priority:parseInt(this.value)})"
              aria-label="Priorytet zadania">
        <option value="1" <?= $task['priority']==1?'selected':'' ?>>Niski</option>
        <option value="2" <?= $task['priority']==2?'selected':'' ?>>Normalny</option>
        <option value="3" <?= $task['priority']==3?'selected':'' ?>>Wysoki</option>
        <option value="4" <?= $task['priority']==4?'selected':'' ?>>Krytyczny</option>
      </select>
      <?php else: ?>
      <div class="small"><?= task_priority_badge((int)$task['priority']) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($can_edit): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-list">
        <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Kolumna
      </label>
      <select id="td-list" class="form-select form-select-sm"
              onchange="tdMoveToList(parseInt(this.value))"
              aria-label="Przenieś zadanie do kolumny">
        <?php foreach ($lists_in_ws as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $l['id']==$task['list_id']?'selected':'' ?>><?= h($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php
      $_all_units_det = [];
      try { $_all_units_det = db_all("SELECT id, name, short_name FROM org_units WHERE status='active' ORDER BY name"); }
      catch (\Throwable $e) {}
    ?>
    <?php if ($_all_units_det && task_field_visible('unit_id', $my_role)): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-unit">
        <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Jednostka org
      </label>
      <?php if (task_field_editable('unit_id', $my_role)): ?>
      <select id="td-unit" class="form-select form-select-sm"
              onchange="tdSetUnit(this)"
              data-prev="<?= (int)($task['unit_id'] ?? 0) ?>"
              aria-label="Przypisz zadanie do jednostki organizacyjnej">
        <option value="0">— brak —</option>
        <?php foreach ($_all_units_det as $ou): ?>
        <option value="<?= $ou['id'] ?>" <?= (int)($task['unit_id']??0)==$ou['id']?'selected':'' ?>>
          <?= h($ou['name']) ?><?= $ou['short_name'] ? ' (' . h($ou['short_name']) . ')' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <div class="small text-muted"><?= task_unit_badge((int)$task['unit_id']) ?: '—' ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

<!-- ══ PRZYPISANI ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-people" aria-hidden="true"></i>Przypisani
  </div>
  <?php if ($all_users): ?>
  <select id="td-users-select" multiple placeholder="Wyszukaj i dodaj osobę…"
          aria-label="Przypisani użytkownicy" <?= $can_edit ? '' : 'disabled' ?>>
    <?php foreach ($all_users as $u): ?>
    <option value="<?= (int)$u['id'] ?>" <?= in_array($u['id'], $assign_ids, true) ? 'selected' : '' ?>><?= h($u['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?>
  <p class="text-muted small mb-0">Brak użytkowników w systemie.</p>
  <?php endif; ?>

  <!-- Obserwujący: powiadomienia o zadaniu bez przypisania -->
  <div class="td-watch mt-2" id="td-watch">
    <button type="button" id="td-watch-btn"
            class="btn btn-sm <?= $i_watch ? 'btn-secondary' : 'btn-outline-secondary' ?> py-0"
            aria-pressed="<?= $i_watch ? 'true' : 'false' ?>"
            onclick="tdToggleWatch()">
      <i class="bi <?= $i_watch ? 'bi-eye-fill' : 'bi-eye' ?> me-1" aria-hidden="true"></i><span><?= $i_watch ? 'Obserwujesz' : 'Obserwuj' ?></span>
    </button>
    <span class="small text-muted" id="td-watch-list">
      <?php if ($watchers): ?>
      Obserwują: <?= h(implode(', ', array_column($watchers, 'name'))) ?>
      <?php else: ?>
      Nikt nie obserwuje — obserwujący dostają powiadomienia o komentarzach, plikach i zmianie statusu.
      <?php endif; ?>
    </span>
  </div>
</div>

