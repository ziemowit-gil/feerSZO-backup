<?php
/**
 * tasks/includes/index_modals.php
 * Offcanvas szczegółów zadania + modale (dodaj zadanie, powiadomienia obszaru,
 * usuń obszar) modułu Zadania. Wydzielone z index.php. Oczekuje: $can_add,
 * $workspace, $ws_id, $lists_map, $all_areas, $ws_members_for_assign,
 * $all_org_units, $my_notify_prefs, $my_role, $available_templates.
 */
?>
<!-- ── Offcanvas: szczegóły zadania ─────────────────────────────────────── -->
<div class="offcanvas offcanvas-end shadow-lg"
     tabindex="-1"
     id="taskOffcanvas"
     role="dialog"
     aria-labelledby="taskOffcanvasLabel"
     aria-modal="true">
  <div class="offcanvas-header">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body"
       id="taskOffcanvasBody"
       aria-live="polite"
       aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: dodaj zadanie ─────────────────────────────────────────────── -->
<?php if ($can_add && $workspace): ?>
<div class="modal fade" id="addTaskModal" tabindex="-1"
     aria-labelledby="addTaskModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <div class="modal-content at-modal">
      <div class="modal-header">
        <div>
          <div class="at-kicker"><?= $workspace ? h($workspace['name']) : '' ?></div>
          <h2 class="modal-title" id="addTaskModalLabel">Nowe zadanie</h2>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                aria-label="Zamknij formularz dodawania zadania"></button>
      </div>
      <div class="modal-body">
        <label class="visually-hidden" for="at-title">Tytuł (wymagane)</label>
        <input type="text" id="at-title" class="at-title-input"
               placeholder="Co trzeba zrobić?" maxlength="255" required aria-required="true">
        <label class="visually-hidden" for="at-desc">Opis (opcjonalnie)</label>
        <textarea id="at-desc" class="at-in at-desc" rows="3" placeholder="Opis, szczegóły, linki… (opcjonalnie)"></textarea>

        <div class="at-grid">
          <div>
            <label class="at-l" for="at-due">Termin</label>
            <input type="date" id="at-due" class="at-in">
          </div>
          <div>
            <label class="at-l" for="at-priority">Priorytet</label>
            <select id="at-priority" class="at-in">
              <option value="1">Niski</option>
              <option value="2" selected>Normalny</option>
              <option value="3">Wysoki</option>
              <option value="4">Krytyczny</option>
            </select>
          </div>
        </div>

        <?php if ($ws_members_for_assign || $all_org_units): ?>
        <div class="at-assign">
          <div class="at-assign-head">
            <span class="at-l mb-0" id="at-assign-lbl">Przypisz do</span>
            <div class="at-seg" role="group" aria-labelledby="at-assign-lbl">
              <input type="radio" class="btn-check" name="at-assign-mode" id="at-mode-person" value="person" checked onchange="atToggleMode('person')">
              <label for="at-mode-person"><i class="bi bi-person" aria-hidden="true"></i>Osoby</label>
              <input type="radio" class="btn-check" name="at-assign-mode" id="at-mode-unit" value="unit" onchange="atToggleMode('unit')">
              <label for="at-mode-unit"><i class="bi bi-diagram-3" aria-hidden="true"></i>Jednostka</label>
            </div>
          </div>
          <div id="at-person-panel">
            <?php if ($ws_members_for_assign): ?>
            <select id="at-user-select" multiple placeholder="Wyszukaj osobę… (puste = do wzięcia przez chętnych)" aria-label="Wybierz osoby">
              <?php foreach ($ws_members_for_assign as $m): ?>
              <option value="<?= (int)$m['user_id'] ?>"><?= h($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <p class="text-muted small mb-0">Brak użytkowników w obszarze.</p>
            <?php endif; ?>
          </div>
          <div id="at-unit-panel" class="d-none">
            <?php if ($all_org_units): ?>
            <select id="at-unit" class="at-in" aria-label="Jednostka organizacyjna">
              <option value="0">— brak przypisania do jednostki —</option>
              <?php foreach ($all_org_units as $ou): ?>
              <option value="<?= $ou['id'] ?>"><?= h($ou['name']) ?><?= $ou['short_name'] ? ' (' . h($ou['short_name']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <p class="text-muted small mb-0">Brak jednostek organizacyjnych.</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <details class="at-more" id="at-more">
          <summary>Więcej opcji <span>kolumna<?= $all_areas ? ', obszar tematyczny' : '' ?></span></summary>
          <div class="at-grid">
            <div>
              <label class="at-l" for="at-list">Kolumna</label>
              <select id="at-list" class="at-in">
                <?php $_at_def = array_values(array_filter($lists_map, fn($l) => empty($l['is_done_state'])))[0] ?? (array_values($lists_map)[0] ?? null); ?>
                <option value="0">Domyślna<?= $_at_def ? ' (' . h($_at_def['name']) . ')' : ' — utworzymy „Do zrobienia”' ?></option>
                <?php foreach ($lists_map as $l): ?>
                <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($all_areas): ?>
            <div>
              <label class="at-l" for="at-area">Obszar tematyczny</label>
              <select id="at-area" class="at-in">
                <option value="0">— brak —</option>
                <?php foreach ($all_areas as $ar): ?>
                <option value="<?= $ar['id'] ?>"><?= h($ar['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          </div>
        </details>
        <div id="at-error" class="alert alert-danger py-2 small d-none mt-3 mb-0" role="alert"></div>
      </div>
      <div class="modal-footer">
        <span class="at-hint">Enter — dodaj</span>
        <button type="button" class="at-btn at-btn--ghost" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="at-btn" id="at-submit" onclick="submitAddTask()">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: zastosuj szablon zadań ────────────────────────────────────────── -->
<?php if ($can_add && $workspace && $available_templates && $lists_map): ?>
<div class="modal fade" id="templateApplyModal" tabindex="-1"
     aria-labelledby="templateApplyModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="templateApplyModalLabel">
          <i class="bi bi-list-check me-1 text-primary" aria-hidden="true"></i>Zastosuj szablon
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="ta-template">Szablon</label>
          <select id="ta-template" class="form-select form-select-sm">
            <?php foreach ($available_templates as $tpl): ?>
            <option value="<?= (int)$tpl['id'] ?>"><?= h($tpl['name']) ?> (<?= (int)$tpl['item_count'] ?> poz.)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="ta-list">Utwórz zadania w kolumnie</label>
          <select id="ta-list" class="form-select form-select-sm">
            <?php foreach ($lists_map as $l): ?>
            <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="text-muted small mb-0">Utworzy nowe zadania — nie wpłynie na już istniejące ani na sam szablon.</p>
        <div id="ta-error" class="alert alert-danger py-2 small mt-2 d-none" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="ta-submit" onclick="submitApplyTemplate()">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zastosuj
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Modal: Powiadomienia dla obszaru ───────────────────────────────────── -->
<?php if ($ws_id && $my_role): ?>
<div class="modal fade" id="notifyPrefModal" tabindex="-1"
     aria-labelledby="notifyPrefModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="notifyPrefModalLabel">
          <i class="bi bi-bell me-1 text-warning"></i>Powiadomienia
          <span class="text-muted fw-normal small">— <?= h($workspace['name'] ?? '') ?></span>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted small mb-3">Wybierz kanały powiadomień dla tego obszaru roboczego.</p>
        <div class="np-row">
          <span class="np-icon text-primary"><i class="bi bi-envelope-fill"></i></span>
          <div class="np-label">
            E-mail
            <small>Powiadomienia o zmianach zadań</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-email" role="switch"
                   <?= $my_notify_prefs['notify_email'] ? 'checked' : '' ?>>
          </div>
        </div>
        <div class="np-row">
          <span class="np-icon text-success"><i class="bi bi-phone-fill"></i></span>
          <div class="np-label">
            SMS
            <small>Krótkie powiadomienia tekstowe</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-sms" role="switch"
                   <?= $my_notify_prefs['notify_sms'] ? 'checked' : '' ?>>
          </div>
        </div>
        <div class="np-row">
          <span class="np-icon text-secondary"><i class="bi bi-bell-fill"></i></span>
          <div class="np-label">
            Push
            <small class="text-warning-emphasis">Wkrótce dostępne</small>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="np-push" role="switch"
                   <?= $my_notify_prefs['notify_push'] ? 'checked' : '' ?> disabled>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">Zamknij</button>
        <button type="button" class="btn btn-primary btn-sm" id="np-save" onclick="saveNotifyPrefs()">
          <i class="bi bi-check-lg me-1"></i>Zapisz
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($can_add && $workspace): ?>
<!-- ── Modal: Usuń obszar (niestandardowy, bez Bootstrapa — wymaga wpisania nazwy) ── -->
<div id="del-ws-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
            z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="closeDeleteWsModal()"></div>

<div id="del-ws-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="del-ws-title"
     aria-describedby="del-ws-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;
            transform:translate(-50%,-50%);
            z-index:9600;width:380px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.25);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #fee2e2;background:#fef2f2">
    <h2 id="del-ws-title"
        style="font-size:.92rem;font-weight:700;margin:0;color:#dc2626;
               display:flex;align-items:center;gap:.4rem">
      <i class="bi bi-trash3-fill" aria-hidden="true"></i>
      Usuń obszar roboczy
    </h2>
    <button type="button" class="btn-close"
            id="del-ws-close-btn"
            onclick="closeDeleteWsModal()"
            aria-label="Anuluj usuwanie obszaru"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.95rem 1.1rem">
    <p id="del-ws-desc" style="font-size:.85rem;color:#374151;margin-bottom:.6rem;line-height:1.5">
      Usuwasz obszar roboczy:<br>
      <strong style="font-size:.95rem;color:#0f172a"><?= h($workspace['name']) ?></strong>
    </p>

    <!-- Liczniki -->
    <?php
    $ws_task_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM tasks WHERE workspace_id=? AND deleted_at IS NULL",
        [$ws_id]
    )['n'] ?? 0);
    $ws_member_count = (int)(db_one(
        "SELECT COUNT(*) AS n FROM task_workspace_members WHERE workspace_id=?",
        [$ws_id]
    )['n'] ?? 0);
    ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;
                padding:.65rem .85rem;margin-bottom:.75rem;font-size:.82rem">
      <div style="display:flex;justify-content:space-between;padding:.2rem 0;border-bottom:1px solid #f1f5f9">
        <span style="color:#64748b">Zadania</span>
        <strong style="color:<?= $ws_task_count > 0 ? '#dc2626' : '#64748b' ?>">
          <?= $ws_task_count ?>
        </strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.2rem 0">
        <span style="color:#64748b">Członkowie</span>
        <strong style="color:#64748b"><?= $ws_member_count ?></strong>
      </div>
    </div>

    <?php if ($ws_task_count > 0): ?>
    <div style="display:flex;gap:.5rem;align-items:flex-start;padding:.5rem .65rem;
                background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;
                font-size:.79rem;color:#92400e;margin-bottom:.75rem">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
      <span>Wszystkie <strong><?= $ws_task_count ?> zadań</strong> zostaną trwale usunięte. Tej operacji nie można cofnąć.</span>
    </div>
    <?php endif; ?>

    <!-- Potwierdzenie -->
    <div style="margin-bottom:.65rem">
      <label for="del-ws-confirm"
             style="font-size:.79rem;font-weight:600;display:block;margin-bottom:.3rem;color:#374151">
        Wpisz nazwę obszaru aby potwierdzić:
        <code style="background:#f1f5f9;padding:.05rem .3rem;border-radius:.25rem;
                     font-size:.82rem;color:#dc2626"><?= h($workspace['name']) ?></code>
      </label>
      <input type="text"
             id="del-ws-confirm"
             class="form-control form-control-sm"
             autocomplete="off"
             autocorrect="off"
             spellcheck="false"
             placeholder="wpisz dokładną nazwę…"
             aria-required="true"
             oninput="delWsCheckConfirm(this)"
             onkeydown="if(event.key==='Enter'){event.preventDefault();delWsSubmit()}">
    </div>

    <div id="del-ws-err" class="alert alert-danger small py-2 d-none" role="alert"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="closeDeleteWsModal()">Anuluj</button>
    <button type="button"
            class="btn btn-danger btn-sm"
            id="del-ws-submit-btn"
            disabled
            onclick="delWsSubmit()"
            aria-label="Potwierdź i usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Usuń bezpowrotnie
    </button>
  </div>
</div>
<?php endif; ?>
