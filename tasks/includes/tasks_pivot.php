<?php
/**
 * tasks/includes/tasks_pivot.php
 * Pasek sekcji modułu Zadania (Metro „pivot”) — zastępuje dawne boczne menu
 * (offcanvas #tskNav z tasks_sidebar.php). Sekcje w poziomym pasku pod app-barem,
 * ustawienia w rozwijanym „Ustawienia” po prawej. Wylogowanie jest w menu
 * użytkownika w tasks_topbar.php.
 * Oczekuje zmiennych z header_tasks.php ($_uri, $_ws_id, $_open_count,
 * $_my_count, $_inbox_unread, $_due_soon, $_tsk_is_leader, $_tsk_open_problems,
 * $_tsk_is_any_leader, $_is_admin, $_tsk_notif_setup_needed, $_tu).
 */
$_tsk_wsq = $_ws_id ? '?ws=' . (int)$_ws_id : '';
$_tsk_all_active = str_contains($_uri, '/tasks/index')
    || (str_contains($_uri, '/tasks/') && !_tsk_active('/tasks/dashboard') && !_tsk_active('/tasks/inbox')
        && !_tsk_active('/tasks/archive') && !_tsk_active('/tasks/notification') && !_tsk_active('/tasks/settings')
        && !_tsk_active('/tasks/problems') && !_tsk_active('/tasks/files') && !_tsk_active('/tasks/moje')
        && !_tsk_active('/tasks/charts') && !_tsk_active('/tasks/nozbe') && !_tsk_active('/tasks/router.php'));

// [ścieżka do _tsk_active, etykieta, ikona, href, licznik, klasa licznika]
$_tsk_pivot = [
    ['/tasks/dashboard', 'Dashboard', 'bi-speedometer2', '/tasks/dashboard.php', 0, ''],
    ['/tasks/inbox', 'Skrzynka', 'bi-inbox', '/tasks/inbox.php', (int)$_inbox_unread, 'is-alert'],
    [null, 'Wszystkie zadania', 'bi-table', '/tasks/index.php' . $_tsk_wsq, (int)$_open_count, ''],
    ['/tasks/moje', 'Moje zadania', 'bi-person-check', '/tasks/moje.php', (int)$_my_count, ''],
    ['/tasks/router.php/kalendarz', 'Kalendarz', 'bi-calendar3', '/tasks/router.php/kalendarz' . $_tsk_wsq, 0, ''],
    ['/tasks/files', 'Pliki', 'bi-folder2-open', '/tasks/files.php' . $_tsk_wsq, 0, ''],
    ['/tasks/archive', 'Archiwum', 'bi-archive', '/tasks/archive.php' . $_tsk_wsq, 0, ''],
    ['/tasks/charts', 'Wykresy', 'bi-bar-chart-line', '/tasks/charts.php' . $_tsk_wsq, 0, ''],
];
if ($_tsk_is_leader) {
    $_tsk_pivot[] = ['/tasks/problems', 'Problemy', 'bi-megaphone', '/tasks/problems.php', (int)$_tsk_open_problems, 'is-warn'];
    $_tsk_pivot[] = ['/tasks/router.php/obciazenie', 'Obciążenie', 'bi-people', '/tasks/router.php/obciazenie' . $_tsk_wsq, 0, ''];
    $_tsk_pivot[] = ['/tasks/router.php/sla', 'SLA', 'bi-alarm', '/tasks/router.php/sla' . $_tsk_wsq, 0, ''];
}

require_once dirname(dirname(__DIR__)) . '/includes/task_nozbe.php';
$_tsk_nozbe_connected = task_nozbe_configured((int)$_tu['id']);
$_tsk_set_active = _tsk_active('/tasks/settings') || _tsk_active('/tasks/notification') || _tsk_active('/tasks/nozbe')
    || _tsk_active('/tasks/router.php/reguly') || _tsk_active('/tasks/router.php/skrzynka-obszaru');
?>
<nav class="tsk-pivot" aria-label="Sekcje modułu Zadania">
  <div class="container-xl tsk-pivot-inner">
    <ul class="tsk-pivot-list">
      <?php foreach ($_tsk_pivot as [$_tp_path, $_tp_label, $_tp_icon, $_tp_href, $_tp_cnt, $_tp_cls]):
          $_tp_on = $_tp_path === null ? $_tsk_all_active : _tsk_active($_tp_path); ?>
      <li>
        <a class="tsk-pivot-link <?= $_tp_on ? 'active' : '' ?>" href="<?= APP_URL . $_tp_href ?>"
           <?= $_tp_on ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= $_tp_icon ?>" aria-hidden="true"></i><span><?= $_tp_label ?></span>
          <?php if ($_tp_cnt > 0): ?><span class="tsk-pivot-cnt <?= $_tp_cls ?>"><?= $_tp_cnt ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; unset($_tp_path, $_tp_label, $_tp_icon, $_tp_href, $_tp_cnt, $_tp_cls, $_tp_on); ?>
    </ul>

    <div class="dropdown tsk-pivot-more">
      <button type="button" class="tsk-pivot-link <?= $_tsk_set_active ? 'active' : '' ?>"
              data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true">
        <i class="bi bi-gear" aria-hidden="true"></i><span>Ustawienia</span>
        <?php if ($_tsk_notif_setup_needed): ?><span class="tsk-pivot-cnt is-warn" aria-label="powiadomienia do skonfigurowania">!</span><?php endif; ?>
        <i class="bi bi-chevron-down tsk-pivot-chev" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end tsk-pivot-menu">
        <li><h6 class="dropdown-header">Moje</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/notification_settings.php"
               onclick="if(!event.ctrlKey&&!event.metaKey){event.preventDefault();tskOpenNotifSettings();}">
          <i class="bi bi-bell" aria-hidden="true"></i>Powiadomienia — ustawienia
          <?php if ($_tsk_notif_setup_needed): ?><span class="tsk-pivot-cnt is-warn">!</span><?php endif; ?></a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/notifications.php">
          <i class="bi bi-clock-history" aria-hidden="true"></i>Historia powiadomień
          <?php if ($_due_soon > 0): ?><span class="tsk-pivot-cnt is-alert"><?= (int)$_due_soon ?></span><?php endif; ?></a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/nozbe_settings.php">
          <i class="bi bi-check2-square" aria-hidden="true"></i>Połączenie z Nozbe
          <?php if (!$_tsk_nozbe_connected): ?><span class="tsk-pivot-cnt is-off">niepołączone</span><?php endif; ?></a></li>
        <?php if ($_tsk_is_any_leader): ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Lider</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/workspaces.php"><i class="bi bi-sliders" aria-hidden="true"></i>Obszary i listy</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/tags.php"><i class="bi bi-tags" aria-hidden="true"></i>Tagi</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/router.php/reguly<?= $_tsk_wsq ?>"><i class="bi bi-magic" aria-hidden="true"></i>Reguły automatyzacji</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/router.php/skrzynka-obszaru<?= $_tsk_wsq ?>"><i class="bi bi-envelope-arrow-down" aria-hidden="true"></i>Skrzynka obszaru (e-mail → zadania)</a></li>
        <?php endif; ?>
        <?php if ($_is_admin): ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Administracja</h6></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/teams.php"><i class="bi bi-people" aria-hidden="true"></i>Zespoły</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/templates.php"><i class="bi bi-list-check" aria-hidden="true"></i>Szablony zadań</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/areas.php"><i class="bi bi-layers" aria-hidden="true"></i>Obszary zadań</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/roles.php"><i class="bi bi-shield-lock" aria-hidden="true"></i>Uprawnienia ról</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/tasks/settings/fields.php"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i>Uprawnienia pól</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/admin/tasks_cleanup.php"
               aria-label="Wyczyść moduł zadań — operacja nieodwracalna"><i class="bi bi-trash3" aria-hidden="true"></i>Wyczyść moduł</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
