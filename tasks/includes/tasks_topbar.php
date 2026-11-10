<?php
/**
 * tasks/includes/tasks_topbar.php
 * Górny pasek nawigacji modułu Zadania — wydzielony z header_tasks.php.
 * Oczekuje zmiennych ustawionych w header_tasks.php ($_tu, $_org_name,
 * $_tsk_workspaces, $_ws_id, $_tsk_notif_unread, $_tsk_notif_latest, $_tu_name,
 * $_tu_initials, $TASKS_FILES_VIEW).
 */
?>
<header class="tsk-navbar" role="banner">
  <div class="container-xl d-flex align-items-center gap-2 py-2">

    <a href="<?= APP_URL ?>/tasks/dashboard.php" class="tsk-brand"
       aria-label="Zadania — strona główna modułu">
      <span class="tsk-brand-icon" aria-hidden="true"><i class="bi bi-table"></i></span>
      <span class="d-flex flex-column">
        <span>Zadania</span>
        <?php if ($_org_name): ?>
        <span class="tsk-brand-sub" title="<?= h($_org_name) ?>"><?= h($_org_name) ?></span>
        <?php endif; ?>
      </span>
    </a>

    <?php /* Wybór obszaru tylko w bocznej kolumnie („Obszary” na liście zadań, na stronie Pliki nad folderami) —
             przełącznik w górnym pasku usunięty na życzenie użytkownika (2026-11-10). */ ?>

    <nav class="ms-auto d-flex align-items-center gap-2" aria-label="Akcje użytkownika">

      <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
      <button type="button"
              class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
              data-bs-toggle="modal" data-bs-target="#bugReportModal"
              title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd">
        <i class="bi bi-bug-fill" aria-hidden="true"></i>
        <span class="d-none d-md-inline">Zgłoś błąd</span>
      </button>
      <?php endif; ?>

      <?php $msw_active='tasks'; $msw_dark=true; /* ciemny pasek Metro — przełącznik modułów w wersji na ciemne tło */ require_once dirname(dirname(__DIR__)).'/includes/module_switcher.php'; ?>

      <!-- Powiadomienia -->
      <div class="dropdown" id="tsk-notif-wrap">
        <button type="button" class="tsk-notif-btn" id="tsk-notif-btn"
                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                aria-label="Powiadomienia zadań — <?= $_tsk_notif_unread ?> nieprzeczytanych">
          <i class="bi bi-bell-fill" aria-hidden="true"></i>
          <span class="tsk-notif-badge <?= $_tsk_notif_unread ? '' : 'd-none' ?>" id="tsk-notif-count">
            <?= $_tsk_notif_unread > 99 ? '99+' : $_tsk_notif_unread ?>
          </span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow" id="tsk-notif-menu"
             style="width:320px;max-height:420px;overflow-y:auto" role="menu"
             aria-label="Lista powiadomień modułu Zadania">
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="fw-semibold" style="font-size:.85rem">Powiadomienia — Zadania</span>
            <button type="button" class="btn btn-link btn-sm p-0 text-muted <?= $_tsk_notif_unread ? '' : 'd-none' ?>"
                    id="tsk-notif-mark-all" style="font-size:.75rem">Oznacz przeczytane</button>
          </div>
          <div id="tsk-notif-list">
            <?php if (!$_tsk_notif_latest): ?>
            <div class="text-center py-4 text-muted" style="font-size:.82rem" id="tsk-notif-empty">
              <i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3" aria-hidden="true"></i>
              Brak powiadomień
            </div>
            <?php else: foreach ($_tsk_notif_latest as $n): ?>
            <a href="<?= h($n['url'] ?: APP_URL . '/tasks/notifications.php') ?>"
               class="dropdown-item py-2 px-3 tsk-notif-item <?= $n['is_read'] ? '' : 'fw-semibold' ?>"
               style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9"
               data-notif-id="<?= (int)$n['id'] ?>">
              <div class="d-flex gap-2 align-items-start">
                <i class="bi bi-kanban-fill mt-1 flex-shrink-0" style="color:#8B5CF6;font-size:.9rem" aria-hidden="true"></i>
                <div class="flex-grow-1">
                  <div><?= h($n['title']) ?></div>
                  <?php if ($n['body']): ?>
                  <div class="text-muted fw-normal" style="font-size:.74rem"><?= h($n['body']) ?></div>
                  <?php endif; ?>
                  <div class="text-muted fw-normal" style="font-size:.72rem"><?= h(substr($n['created_at'], 0, 16)) ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                <span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;margin-top:5px;background:var(--tsk-green)" aria-hidden="true"></span>
                <?php endif; ?>
              </div>
            </a>
            <?php endforeach; endif; ?>
          </div>
          <div class="px-3 py-2 border-top d-flex gap-2">
            <a href="<?= APP_URL ?>/tasks/notifications.php" class="btn btn-sm flex-fill"
               style="background:#f1f5f9;color:#374151;font-size:.8rem">
              <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia
            </a>
            <button type="button" class="btn btn-sm flex-fill"
                    style="background:#fffbeb;color:#92400e;font-size:.8rem;border:1px solid #fcd34d"
                    onclick="tskOpenNotifSettings()">
              <i class="bi bi-gear-fill me-1" aria-hidden="true"></i>Ustawienia
            </button>
          </div>
        </div>
      </div>

      <!-- User dropdown -->
      <div class="dropdown">
        <button type="button" class="tsk-avatar"
                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                aria-label="Menu użytkownika <?= h($_tu_name) ?>">
          <?= h($_tu_initials) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.88rem;min-width:200px">
          <li class="px-3 py-2 border-bottom">
            <div class="fw-bold"><?= h($_tu_name) ?></div>
            <div class="text-muted small"><?= h($_tu['email'] ?? '') ?></div>
          </li>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/index.php"><i class="bi bi-house-door me-2" aria-hidden="true"></i>Mój panel</a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php"
               onclick="return confirm('Wylogować się?')">
              <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się
            </a>
          </li>
        </ul>
      </div>

    </nav>
  </div>
  <?php require __DIR__ . '/tasks_pivot.php'; ?>
</header>
