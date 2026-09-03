<?php
/**
 * tasks/includes/tasks_sidebar.php
 * Offcanvas nawigacji modułu Zadania — wydzielony z header_tasks.php.
 * Oczekuje zmiennych ustawionych w header_tasks.php ($_ws_id, $_open_count,
 * $_my_count, $_inbox_unread, $_due_soon, $_tsk_is_leader, $_tsk_open_problems,
 * $_tsk_is_any_leader, $_is_admin, $_tsk_notif_setup_needed).
 */
?>
<div class="offcanvas offcanvas-start tsk-offcanvas" tabindex="-1" id="tskNav"
     aria-label="Nawigacja modułu Zadania">
  <div class="offcanvas-header">
    <span class="offcanvas-title fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-table" aria-hidden="true"></i>Zadania
    </span>
    <button type="button" class="btn-close btn-close-white"
            data-bs-dismiss="offcanvas" aria-label="Zamknij menu"></button>
  </div>
  <nav class="offcanvas-body" aria-label="Sekcje modułu Zadania">

    <span class="tsk-nav-label">Przegląd</span>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/dashboard') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/dashboard.php"
       aria-current="<?= _tsk_active('/tasks/dashboard') ? 'page' : 'false' ?>">
      <i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/inbox') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/inbox.php"
       aria-current="<?= _tsk_active('/tasks/inbox') ? 'page' : 'false' ?>">
      <i class="bi bi-inbox" aria-hidden="true"></i>Skrzynka
      <?php if ($_inbox_unread > 0): ?>
      <span class="tsk-nav-badge" style="background:#fee2e2;color:#dc2626"
            aria-label="<?= $_inbox_unread ?> nieprzeczytanych"><?= $_inbox_unread ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= (str_contains($_uri,'/tasks/index') || (str_contains($_uri,'/tasks/') && !_tsk_active('/tasks/dashboard') && !_tsk_active('/tasks/inbox') && !_tsk_active('/tasks/archive') && !_tsk_active('/tasks/notification') && !_tsk_active('/tasks/settings') && !_tsk_active('/tasks/problems'))) ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/index.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= str_contains($_uri,'/tasks/index') ? 'page' : 'false' ?>">
      <i class="bi bi-table" aria-hidden="true"></i>Wszystkie zadania
      <?php if ($_open_count > 0): ?>
      <span class="tsk-nav-badge" aria-label="<?= $_open_count ?> wolnych"><?= $_open_count ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/files') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/files.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/files') ? 'page' : 'false' ?>">
      <i class="bi bi-folder2-open" aria-hidden="true"></i>Pliki
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/archive') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/archive.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/archive') ? 'page' : 'false' ?>">
      <i class="bi bi-archive" aria-hidden="true"></i>Archiwum zadań
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/moje') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/moje.php"
       aria-current="<?= _tsk_active('/tasks/moje') ? 'page' : 'false' ?>">
      <i class="bi bi-person-check" aria-hidden="true"></i>Moje zadania
      <?php if ($_my_count > 0): ?>
      <span class="tsk-nav-badge" aria-label="<?= $_my_count ?> zadań"><?= $_my_count ?></span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/charts') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/charts.php<?= $_ws_id ? '?ws='.$_ws_id : '' ?>"
       aria-current="<?= _tsk_active('/tasks/charts') ? 'page' : 'false' ?>">
      <i class="bi bi-bar-chart-line" aria-hidden="true"></i>Wykresy
    </a>

    <?php if ($_tsk_is_leader): ?>
    <div class="tsk-nav-sep" role="separator"></div>
    <span class="tsk-nav-label">Lider</span>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/problems') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/problems.php"
       aria-current="<?= _tsk_active('/tasks/problems') ? 'page' : 'false' ?>">
      <i class="bi bi-megaphone" aria-hidden="true"></i>Zgłoszone problemy
      <?php if ($_tsk_open_problems > 0): ?>
      <span class="tsk-nav-badge" style="background:#fef9c3;color:#92400e"
            aria-label="<?= $_tsk_open_problems ?> otwartych"><?= $_tsk_open_problems ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <div class="tsk-nav-sep" role="separator"></div>
    <span class="tsk-nav-label">Ustawienia</span>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/notification_settings') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/notification_settings.php"
       aria-current="<?= _tsk_active('/tasks/notification_settings') ? 'page' : 'false' ?>"
       onclick="if(!event.ctrlKey&&!event.metaKey){event.preventDefault();tskOpenNotifSettings();}">
      <i class="bi bi-bell-fill" aria-hidden="true"></i>Powiadomienia — ustawienia
      <?php if ($_tsk_notif_setup_needed): ?>
      <span class="tsk-nav-badge" style="background:#f59e0b;color:#fff" aria-label="do skonfigurowania">!</span>
      <?php endif; ?>
    </a>

    <a class="tsk-nav-link <?= _tsk_active('/tasks/notifications.php') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/notifications.php"
       aria-current="<?= _tsk_active('/tasks/notifications.php') ? 'page' : 'false' ?>">
      <i class="bi bi-clock-history" aria-hidden="true"></i>Historia powiadomień
      <?php if ($_due_soon > 0): ?>
      <span class="tsk-nav-badge" style="background:#fee2e2;color:#dc2626"
            aria-label="<?= $_due_soon ?> zadań z bliskim terminem"><?= $_due_soon ?></span>
      <?php endif; ?>
    </a>

    <?php if ($_tsk_is_any_leader): ?>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/workspaces') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/workspaces.php">
      <i class="bi bi-sliders" aria-hidden="true"></i>Obszary i listy
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/tags') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/tags.php">
      <i class="bi bi-tags" aria-hidden="true"></i>Tagi
    </a>
    <?php endif; ?>
    <?php if ($_is_admin): ?>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/teams') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/teams.php">
      <i class="bi bi-people-fill" aria-hidden="true"></i>Zespoły
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/templates') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/templates.php">
      <i class="bi bi-list-check" aria-hidden="true"></i>Szablony zadań
    </a>
    <?php endif; ?>
    <?php if ($_is_admin): ?>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/areas') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/areas.php">
      <i class="bi bi-layers" aria-hidden="true"></i>Obszary zadań
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/roles') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/roles.php">
      <i class="bi bi-shield-lock" aria-hidden="true"></i>Uprawnienia ról
    </a>
    <a class="tsk-nav-link <?= _tsk_active('/tasks/settings/fields') ? 'active' : '' ?>"
       href="<?= APP_URL ?>/tasks/settings/fields.php">
      <i class="bi bi-ui-checks-grid" aria-hidden="true"></i>Uprawnienia pól
    </a>
    <div class="tsk-nav-sep" role="separator"></div>
    <a class="tsk-nav-link tsk-danger"
       href="<?= APP_URL ?>/admin/tasks_cleanup.php"
       aria-label="Wyczyść moduł zadań — operacja nieodwracalna">
      <i class="bi bi-trash3" aria-hidden="true"></i>Wyczyść moduł
    </a>
    <?php endif; ?>

    <div class="tsk-sidebar-bottom">
      <a href="<?= APP_URL ?>/auth/logout.php"
         class="tsk-nav-link"
         style="color:#dc2626"
         onclick="return confirm('Wylogować się?')">
        <i class="bi bi-box-arrow-right" aria-hidden="true" style="color:#dc2626"></i>Wyloguj się
      </a>
    </div>

  </nav>
</div>
