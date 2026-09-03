<!--
 tasks/includes/detail_header.php — wydzielone z tasks/detail.php.
 Nagłówek: tytuł, statusy, belka przycisków akcji, panel Przekaż. Wymaga: $task, $my_role, $can_edit, $is_done, $is_confirmed, $is_rejected, $overdue, $can_confirm, $can_reject, $_unit_members, $id.
-->
<div id="td-root" data-task-id="<?= $id ?>">

<!-- ══ NAGŁÓWEK ════════════════════════════════════════════════════════════ -->
<div class="td-header">

  <!-- Tytuł -->
  <?php if (task_field_visible('title', $my_role)): ?>
  <div class="mb-2">
    <?php if (task_field_editable('title', $my_role)): ?>
    <input type="text"
           id="td-title"
           class="td-title-input form-control"
           value="<?= h($task['title']) ?>"
           aria-label="Tytuł zadania"
           onblur="tdPatch({title:this.value.trim()||<?= json_encode($task['title']) ?>})"
           onkeydown="if(event.key==='Enter')this.blur()">
    <?php else: ?>
    <div class="td-title-static"><?= h($task['title']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Statusy inline -->
  <div class="td-status-row mb-2" aria-label="Status zadania">
    <?= task_priority_badge((int)$task['priority']) ?>
    <?php if ($is_done): ?>
    <span class="badge bg-success">
      <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Ukończone<?= $task['completed_at'] ? ' ' . substr($task['completed_at'],0,10) : '' ?>
    </span>
    <?php endif; ?>
    <?php if ($is_confirmed): ?>
    <span class="badge" style="background:#7c3aed">
      <i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Potwierdzone <?= substr($task['confirmed_at'],0,10) ?>
    </span>
    <?php endif; ?>
    <?php if ($is_rejected): ?>
    <span class="badge bg-danger" title="Powód: <?= h($task['rejection_reason'] ?? '') ?>">
      <i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>Odrzucone <?= substr($task['rejected_at'],0,10) ?>
    </span>
    <?php endif; ?>
    <?php if ($overdue): ?>
    <span class="badge bg-danger">
      <i class="bi bi-alarm me-1" aria-hidden="true"></i>Po terminie
    </span>
    <?php endif; ?>
    <span class="badge bg-light text-secondary border">
      <i class="bi bi-columns-gap me-1" aria-hidden="true"></i><?= h($task['list_name']) ?>
    </span>
  </div>

  <?php if ($is_rejected && !$is_confirmed): ?>
  <div class="alert alert-danger py-2 px-3 mb-2" style="font-size:.82rem" role="alert">
    <i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>
    <strong>Odrzucono wykonanie</strong> — powód: <?= nl2br(h($task['rejection_reason'] ?? '')) ?>
  </div>
  <?php endif; ?>

  <!-- ── Belka przycisków ──────────────────────────────────────────────── -->
  <div class="td-action-bar" role="toolbar" aria-label="Akcje zadania">

    <!-- Zakończ / Wznów — główna akcja -->
    <?php if ($can_edit): ?>
    <?php if (!$is_done): ?>
    <button type="button"
            class="td-ab-btn td-ab-primary"
            id="td-btn-done"
            onclick="tdMarkDone()"
            aria-label="Oznacz zadanie jako ukończone">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      <span>Zakończ</span>
    </button>
    <?php else: ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdReopen()"
            aria-label="Wznów zadanie">
      <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
      <span>Wznów</span>
    </button>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Potwierdź / odrzuć wykonanie — lider obszaru -->
    <?php if ($can_confirm): ?>
    <button type="button"
            class="td-ab-btn td-ab-confirm"
            id="td-btn-confirm"
            onclick="tdConfirm()"
            aria-label="Potwierdź wykonanie zadania">
      <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
      <span>Potwierdź wykonanie</span>
    </button>
    <?php endif; ?>
    <?php if ($can_reject): ?>
    <button type="button"
            class="td-ab-btn td-ab-danger"
            id="td-reject-open-btn"
            onclick="tdOpenRejectModal()"
            aria-haspopup="dialog"
            aria-label="Odrzuć wykonanie zadania i podaj powód">
      <i class="bi bi-x-octagon" aria-hidden="true"></i>
      <span>Odrzuć</span>
    </button>
    <?php endif; ?>

    <!-- Przekaż zadanie — lider/admin + komórka -->
    <?php if ($can_edit && !$is_done && $_unit_members): ?>
    <div class="td-takeover-wrap" id="td-takeover-wrap">
      <button type="button"
              class="td-ab-btn td-ab-ghost"
              id="td-takeover-btn"
              onclick="tdToggleTakeover()"
              aria-expanded="false"
              aria-controls="td-takeover-panel"
              aria-haspopup="true"
              aria-label="Przekaż zadanie osobie z komórki organizacyjnej">
        <i class="bi bi-person-up" aria-hidden="true"></i>
        <span>Przekaż</span>
      </button>

      <!-- Panel wyboru osoby -->
      <div id="td-takeover-panel"
           class="td-takeover-panel"
           role="dialog"
           aria-modal="false"
           aria-label="Wybierz osobę do przekazania zadania">
        <div class="td-tp-head">
          <p class="td-tp-title">Przekaż zadanie</p>
          <label class="visually-hidden" for="td-tp-search">Szukaj osoby</label>
          <input type="search" id="td-tp-search" class="td-tp-search"
                 placeholder="Szukaj po imieniu…" autocomplete="off"
                 oninput="tdTpFilter(this.value)">
        </div>
        <div class="td-tp-selected" id="td-tp-selected">
          <span id="td-tp-sel-av" class="td-tp-av" aria-hidden="true"></span>
          <span id="td-tp-sel-name" style="flex:1;font-weight:600"></span>
          <button type="button" class="btn-close" style="font-size:.55rem"
                  onclick="tdTpClearSelection()" aria-label="Anuluj wybór"></button>
        </div>
        <div class="td-tp-list" id="td-tp-list" role="listbox" aria-label="Osoby z komórki">
          <?php foreach ($_members_by_unit as $unit_name => $members): ?>
          <div class="td-tp-group" role="presentation"><?= h($unit_name) ?></div>
          <?php foreach ($members as $m):
            $initials = '';
            foreach (preg_split('/\s+/', trim($m['name'])) as $w) $initials .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
            $initials = mb_substr($initials,0,2,'UTF-8') ?: '?';
            $colors   = ['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2'];
            $bg       = $colors[crc32($m['name']) % count($colors)];
          ?>
          <button type="button" class="td-tp-person" role="option"
                  data-uid="<?= $m['id'] ?>" data-name="<?= h($m['name']) ?>"
                  data-initials="<?= h($initials) ?>" data-bg="<?= h($bg) ?>"
                  data-search="<?= h(mb_strtolower($m['name'],'UTF-8')) ?>"
                  onclick="tdTpSelect(this)"
                  aria-label="<?= h($m['name']) ?><?= $m['position_name'] ? ', '.h($m['position_name']) : '' ?>">
            <span class="td-tp-av" style="background:<?= h($bg) ?>" aria-hidden="true"><?= h($initials) ?></span>
            <span class="td-tp-info">
              <span class="td-tp-name"><?= h($m['name']) ?></span>
              <?php if ($m['position_name'] || $m['unit_name']): ?>
              <span class="td-tp-pos"><?= h($m['position_name'] ?: $m['unit_name']) ?></span>
              <?php endif; ?>
            </span>
          </button>
          <?php endforeach; endforeach; ?>
          <div id="td-tp-no-results" class="td-tp-empty" style="display:none">Nie znaleziono osoby.</div>
        </div>
        <div class="td-tp-msg" id="td-tp-send-wrap" style="display:none">
          <label class="visually-hidden" for="td-tp-msg-ta">Wiadomość (opcjonalnie)</label>
          <textarea id="td-tp-msg-ta" rows="2"
                    placeholder="Dodaj wiadomość (opcjonalnie)…"
                    maxlength="500"></textarea>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-primary btn-sm flex-grow-1"
                    id="td-tp-send-btn" onclick="tdTpSend()">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="tdTpClearSelection()">Anuluj</button>
          </div>
          <div id="td-tp-ok"  class="alert alert-success  small py-1 mt-2 mb-0 d-none" role="status"></div>
          <div id="td-tp-err" class="alert alert-danger   small py-1 mt-2 mb-0 d-none" role="alert"></div>
        </div>
      </div><!-- /td-takeover-panel -->
    </div>
    <?php endif; ?>

    <!-- Duplikuj -->
    <?php if ($can_edit): ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdDuplicate()"
            aria-label="Duplikuj to zadanie">
      <i class="bi bi-copy" aria-hidden="true"></i>
      <span>Duplikuj</span>
    </button>
    <?php endif; ?>

    <!-- Nowy obszar ze struktury — tylko lider/admin -->
    <?php if ($_actor_is_sys_admin || $_tsk_is_leader_here): ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdOpenNewWsModal()"
            aria-label="Utwórz nowy obszar roboczy na podstawie tego zadania"
            aria-haspopup="dialog">
      <i class="bi bi-grid-plus" aria-hidden="true"></i>
      <span>Nowy obszar</span>
    </button>
    <?php endif; ?>

    <!-- Powiadom lidera — dostępne dla wszystkich -->
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            id="td-notify-open-btn"
            onclick="tdOpenNotifyModal()"
            aria-haspopup="dialog"
            aria-label="Zgłoś problem liderowi obszaru">
      <i class="bi bi-megaphone" aria-hidden="true"></i>
      <span>Problem</span>
    </button>

    <!-- Otwórz zadanie — pełny widok w module Zadania (ukryty, gdy podgląd
         otwarto już z giełdy zadań: index.php dokleja in_tasks=1) -->
    <?php if (empty($_GET['in_tasks'])): ?>
    <a class="td-ab-btn td-ab-ghost"
       href="<?= APP_URL ?>/tasks/index.php?task=<?= $id ?>"
       aria-label="Otwórz zadanie w module Zadania">
      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
      <span>Otwórz zadanie</span>
    </a>
    <?php endif; ?>

    <!-- Separator -->
    <div class="td-ab-sep" aria-hidden="true"></div>

    <!-- Usuń — tylko lider/admin -->
    <?php if ($can_edit): ?>
    <button type="button"
            class="td-ab-btn td-ab-danger"
            onclick="tdDelete()"
            aria-label="Usuń to zadanie bezpowrotnie">
      <i class="bi bi-trash" aria-hidden="true"></i>
      <span>Usuń</span>
    </button>
    <?php endif; ?>

  </div><!-- /td-action-bar -->

</div>

