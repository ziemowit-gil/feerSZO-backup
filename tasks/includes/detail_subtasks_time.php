<!--
 tasks/includes/detail_subtasks_time.php — wydzielone z tasks/detail.php.
 Podzadania + Czas pracy + Powtarzalność. Wymaga: $task, $my_role, $can_edit, $subtasks, $st_total, $st_done, $st_pct, $id.
-->
<!-- ══ PODZADANIA ══════════════════════════════════════════════════════════ -->
<div class="td-section" id="td-subtasks-section">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="td-label mb-0">
      <i class="bi bi-check2-square" aria-hidden="true"></i>Podzadania
      <span id="td-st-counter" class="fw-normal ms-1" aria-live="polite">
        <?= $st_total ? "($st_done/$st_total)" : '' ?>
      </span>
    </div>
  </div>

  <!-- Pasek postępu -->
  <div class="td-progress-wrap <?= !$st_total ? 'd-none' : '' ?>"
       id="td-st-progress-wrap"
       aria-hidden="<?= $st_total ? 'false' : 'true' ?>">
    <div class="td-progress-track"
         role="progressbar"
         aria-valuenow="<?= $st_pct ?>"
         aria-valuemin="0" aria-valuemax="100"
         aria-label="Postęp podzadań">
      <div id="td-st-progress"
           class="td-progress-fill <?= $st_done===$st_total && $st_total>0 ? 'bg-success' : 'bg-primary' ?>"
           style="width:<?= $st_pct ?>%"></div>
    </div>
    <span id="td-st-pct" class="td-pct-label"><?= $st_total ? $st_pct . '%' : '' ?></span>
  </div>

  <!-- Lista podzadań -->
  <div id="td-st-list" role="list" aria-label="Lista podzadań">
    <?php foreach ($subtasks as $st): ?>
    <div class="td-st-row <?= $st['is_done'] ? 'td-st-done' : '' ?>"
         id="strow-<?= $st['id'] ?>"
         role="listitem">
      <?php if ($can_edit): ?>
      <input type="checkbox"
             class="form-check-input flex-shrink-0 mt-0"
             <?= $st['is_done'] ? 'checked' : '' ?>
             onchange="tdStToggle(<?= $st['id'] ?>, this)"
             style="cursor:pointer;width:15px;height:15px"
             aria-label="<?= h($st['title']) ?>">
      <?php else: ?>
      <i class="bi bi-<?= $st['is_done'] ? 'check-square-fill text-success' : 'square text-muted' ?> flex-shrink-0"
         aria-hidden="true"></i>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <span class="flex-grow-1 small td-st-title"
            style="line-height:1.4;cursor:text"
            role="button" tabindex="0"
            aria-label="Edytuj podzadanie: <?= h($st['title']) ?>"
            onclick="tdStStartEdit(this,<?= (int)$st['id'] ?>)"
            onkeydown="if(event.key==='Enter'){event.preventDefault();tdStStartEdit(this,<?= (int)$st['id'] ?>)}">
        <?= h($st['title']) ?>
      </span>
      <?php else: ?>
      <span class="flex-grow-1 small td-st-title" style="line-height:1.4"><?= h($st['title']) ?></span>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <button type="button"
              class="btn-close flex-shrink-0"
              onclick="tdStDelete(<?= (int)$st['id'] ?>)"
              aria-label="Usuń podzadanie: <?= h($st['title']) ?>"
              style="font-size:.5rem;opacity:.5"></button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$subtasks): ?>
    <p class="text-muted small mb-1" id="td-st-empty">Brak podzadań.</p>
    <?php endif; ?>
  </div>

  <?php if ($can_edit): ?>
  <div class="d-flex gap-2 mt-2" id="td-st-add-row">
    <label class="visually-hidden" for="td-st-input">Nowe podzadanie</label>
    <input type="text"
           id="td-st-input"
           class="form-control form-control-sm"
           placeholder="Nowe podzadanie… (Enter aby dodać)"
           onkeydown="if(event.key==='Enter'){event.preventDefault();tdStAdd()}">
    <button type="button"
            class="btn btn-sm btn-outline-secondary px-2"
            onclick="tdStAdd()"
            aria-label="Dodaj podzadanie">
      <i class="bi bi-plus-lg" aria-hidden="true"></i>
    </button>
  </div>
  <?php endif; ?>

</div>

<!-- ══ CZAS PRACY ══════════════════════════════════════════════════════════ -->
<div class="td-section" id="td-time-section">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="td-label mb-0">
      <i class="bi bi-stopwatch" aria-hidden="true"></i>Czas pracy
      <span id="td-time-total" class="ms-1 fw-normal text-muted" style="font-size:.75rem"></span>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <!-- Szacowany czas -->
      <?php if (task_field_visible('estimated_hours', $my_role)): ?>
        <?php if (task_field_editable('estimated_hours', $my_role)): ?>
        <div class="d-flex align-items-center gap-1">
          <label for="td-est-hours" class="text-muted" style="font-size:.72rem;white-space:nowrap">
            Szacunek:
          </label>
          <input type="number" id="td-est-hours"
                 class="form-control form-control-sm"
                 style="width:70px;font-size:.78rem"
                 min="0" max="999" step="0.5"
                 value="<?= h($task['estimated_hours'] ?? '') ?>"
                 placeholder="godz."
                 aria-label="Szacowana liczba godzin"
                 onblur="tdPatch({estimated_hours:parseFloat(this.value)||null})">
        </div>
        <?php elseif ($task['estimated_hours']): ?>
        <span class="text-muted" style="font-size:.76rem">
          Szacunek: <?= h($task['estimated_hours']) ?>h
        </span>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Timer start/stop -->
      <button type="button"
              class="btn btn-sm"
              id="td-timer-btn"
              onclick="tdTimerToggle()"
              aria-label="Uruchom lub zatrzymaj timer czasu pracy"
              style="font-size:.74rem;white-space:nowrap">
        <i class="bi bi-play-fill me-1" id="td-timer-icon" aria-hidden="true"></i>
        <span id="td-timer-label">Start</span>
      </button>
    </div>
  </div>

  <!-- Aktywny timer display -->
  <div id="td-timer-running" class="d-none mb-2 p-2 rounded"
       style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.8rem;
              display:none!important;align-items:center;gap:.5rem">
    <span class="spinner-grow spinner-grow-sm text-success flex-shrink-0" aria-hidden="true"></span>
    <span>Timer aktywny: <strong id="td-timer-elapsed">00:00</strong></span>
    <input type="text" id="td-timer-note"
           class="form-control form-control-sm ms-auto"
           style="max-width:160px;font-size:.76rem"
           placeholder="Notatka (opcjonalnie)"
           aria-label="Notatka do wpisu czasu">
  </div>

  <!-- Log wpisów -->
  <div id="td-time-log" class="td-time-log" role="list" aria-label="Wpisy czasu pracy">
    <!-- ładowane przez JS -->
    <div class="text-muted text-center py-2" style="font-size:.8rem" id="td-time-loading">
      <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Ładowanie…
    </div>
  </div>

  <!-- Ręczny wpis -->
  <?php if ($can_edit): ?>
  <details class="mt-2" id="td-manual-time">
    <summary class="text-muted" style="font-size:.76rem;cursor:pointer">
      <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj ręczny wpis
    </summary>
    <div class="row g-2 mt-1">
      <div class="col-5">
        <label class="visually-hidden" for="td-mt-start">Początek</label>
        <input type="datetime-local" id="td-mt-start" class="form-control form-control-sm"
               aria-label="Czas rozpoczęcia pracy">
      </div>
      <div class="col-5">
        <label class="visually-hidden" for="td-mt-end">Koniec</label>
        <input type="datetime-local" id="td-mt-end" class="form-control form-control-sm"
               aria-label="Czas zakończenia pracy">
      </div>
      <div class="col-12">
        <label class="visually-hidden" for="td-mt-note">Notatka</label>
        <input type="text" id="td-mt-note" class="form-control form-control-sm"
               placeholder="Notatka (opcjonalnie)" maxlength="200"
               aria-label="Notatka do ręcznego wpisu">
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="tdManualTime()">
          <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz wpis
        </button>
      </div>
    </div>
  </details>
  <?php endif; ?>
</div>

<!-- ══ POWTARZALNOŚĆ ══════════════════════════════════════════════════════ -->
<?php if (task_field_visible('recurrence', $my_role)): ?>
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Powtarzanie
  </div>
  <?php if (task_field_editable('recurrence', $my_role)): ?>
  <div class="row g-2 align-items-end">
    <div class="col-sm-6">
      <label class="form-label small fw-semibold text-muted mb-1" for="td-recurrence">
        Cykl powtarzania
      </label>
      <select id="td-recurrence" class="form-select form-select-sm"
              onchange="tdPatch({recurrence:this.value||null})"
              aria-label="Wybierz cykl powtarzania zadania">
        <option value="" <?= !$task['recurrence']?'selected':'' ?>>Jednorazowe (brak)</option>
        <option value="daily"   <?= $task['recurrence']==='daily'  ?'selected':'' ?>>Codziennie</option>
        <option value="weekly"  <?= $task['recurrence']==='weekly' ?'selected':'' ?>>Co tydzień</option>
        <option value="monthly" <?= $task['recurrence']==='monthly'?'selected':'' ?>>Co miesiąc</option>
        <option value="yearly"  <?= $task['recurrence']==='yearly' ?'selected':'' ?>>Co rok</option>
      </select>
    </div>
    <?php if ($task['recurrence'] && task_field_editable('recurrence_end_date', $my_role)): ?>
    <div class="col-sm-6">
      <label class="form-label small fw-semibold text-muted mb-1" for="td-rec-end">
        Zakończ powtarzanie
        <span class="fw-normal">(opcjonalnie)</span>
      </label>
      <input type="date" id="td-rec-end" class="form-control form-control-sm"
             value="<?= h($task['recurrence_end_date'] ?? '') ?>"
             onchange="tdPatch({recurrence_end_date:this.value||null})"
             aria-label="Data zakończenia powtarzania">
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($task['recurrence']): ?>
  <div class="small text-muted">
    <?= ['daily'=>'Codziennie','weekly'=>'Co tydzień','monthly'=>'Co miesiąc','yearly'=>'Co rok'][$task['recurrence']] ?? h($task['recurrence']) ?>
    <?php if ($task['recurrence_end_date']): ?> — do <?= date_pl($task['recurrence_end_date']) ?><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($task['recurrence']): ?>
  <p class="text-muted small mt-2 mb-0">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    Po ukończeniu zadania system automatycznie utworzy kolejną instancję
    z przesuniętym terminem.
    <?php if ($task['recurrence_parent_id']): ?>
    <br><span class="badge bg-secondary" style="font-size:.68rem">
      <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
      Kopia zadania #<?= (int)$task['recurrence_parent_id'] ?>
    </span>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

