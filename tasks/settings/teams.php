<?php
/**
 * tasks/settings/teams.php
 * Zarządzanie zespołami — grupami użytkowników niezależnymi od jednostek
 * organizacyjnych, przypisywalnymi do obszarów roboczych (task_workspace_teams).
 * Dostęp: tylko is_admin() — zespoły są konceptem systemowym, jak Obszary zadań/Tagi.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

if (!is_admin()) {
    flash_set('error', 'Brak uprawnień do zarządzania zespołami.');
    header('Location: ' . APP_URL . '/tasks/dashboard.php'); exit;
}

$teams     = task_get_teams(false);
$all_users = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name");

$team_members = [];
foreach ($teams as $t) {
    $team_members[$t['id']] = db_all(
        "SELECT u.id, u.name FROM task_team_members tm JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = ? ORDER BY u.name",
        [$t['id']]
    );
}

$PAGE_TITLE       = 'Zespoły';
$TASKS_BREADCRUMB = 'Zespoły';
require_once dirname(__DIR__) . '/includes/header_tasks.php';
?>

<style type="text/tailwindcss">
.tm-card {
  @apply tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-p-4 tw-flex tw-flex-col tw-gap-3;
}
.tm-card.tm-inactive { @apply tw-opacity-55; }
.tm-dot { @apply tw-w-8 tw-h-8 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-text-white tw-text-sm tw-shrink-0; }
.tm-stat { @apply tw-inline-flex tw-items-center tw-gap-1 tw-text-[.76rem] tw-text-slate-500; }
.tm-member-chip {
  @apply tw-inline-flex tw-items-center tw-gap-1 tw-bg-slate-100 tw-text-slate-700 tw-text-[.74rem]
         tw-font-medium tw-py-[.15rem] tw-px-2 tw-rounded-full;
}
</style>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h1 class="tw-text-lg tw-font-bold tw-mb-0 tw-flex tw-items-center tw-gap-2">
      <i class="bi bi-people-fill tw-text-blue-600" aria-hidden="true"></i>Zespoły
    </h1>
    <p class="tw-text-slate-500 tw-text-sm tw-mb-0">Grupy użytkowników, które można przypisać do obszaru roboczego zamiast dodawać osoby pojedynczo.</p>
  </div>
  <button type="button" class="btn btn-primary btn-sm" onclick="tmOpenTeamModal()">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy zespół
  </button>
</div>

<?php if (!$teams): ?>
<div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-p-8 tw-text-center tw-text-slate-500">
  <i class="bi bi-people tw-text-3xl tw-block tw-mb-2 tw-opacity-40" aria-hidden="true"></i>
  Brak zespołów. Utwórz pierwszy, aby móc przypisywać go do obszarów roboczych.
</div>
<?php else: ?>
<div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 lg:tw-grid-cols-3 tw-gap-3" id="tm-team-grid">
  <?php foreach ($teams as $t): ?>
  <div class="tm-card <?= $t['is_active'] ? '' : 'tm-inactive' ?>" data-team-id="<?= (int)$t['id'] ?>">
    <div class="tw-flex tw-items-start tw-gap-3">
      <span class="tm-dot" style="background:<?= h($t['color']) ?>" aria-hidden="true"><i class="bi <?= h($t['icon']) ?>"></i></span>
      <div class="tw-flex-1 tw-min-w-0">
        <div class="tw-font-semibold tw-text-slate-900 tw-truncate"><?= h($t['name']) ?></div>
        <?php if ($t['description']): ?>
        <div class="tw-text-[.78rem] tw-text-slate-500 tw-mt-[.1rem]"><?= h($t['description']) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!$t['is_active']): ?>
      <span class="badge bg-secondary" style="font-size:.65rem">Nieaktywny</span>
      <?php endif; ?>
    </div>

    <div class="tw-flex tw-gap-3">
      <span class="tm-stat"><i class="bi bi-person-fill" aria-hidden="true"></i><?= (int)$t['member_count'] ?> os.</span>
      <span class="tm-stat"><i class="bi bi-kanban" aria-hidden="true"></i><?= (int)$t['workspace_count'] ?> obszar(y)</span>
    </div>

    <div class="tw-flex tw-flex-wrap tw-gap-1" data-member-chips>
      <?php foreach (array_slice($team_members[$t['id']], 0, 6) as $m): ?>
      <span class="tm-member-chip"><?= h($m['name']) ?></span>
      <?php endforeach; ?>
      <?php if (count($team_members[$t['id']]) > 6): ?>
      <span class="tm-member-chip">+<?= count($team_members[$t['id']]) - 6 ?></span>
      <?php endif; ?>
      <?php if (!$team_members[$t['id']]): ?>
      <span class="tw-text-[.76rem] tw-text-slate-400">Brak członków</span>
      <?php endif; ?>
    </div>

    <div class="tw-flex tw-gap-2 tw-pt-2 tw-border-t tw-border-slate-100">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick='tmOpenMembersModal(<?= json_encode(["id"=>(int)$t["id"],"name"=>$t["name"],"members"=>$team_members[$t["id"]]]) ?>)'>
        <i class="bi bi-people me-1" aria-hidden="true"></i>Członkowie
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick='tmOpenTeamModal(<?= json_encode($t) ?>)'>
        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tmToggleTeam(<?= (int)$t['id'] ?>)">
        <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>-fill me-1" aria-hidden="true"></i><?= $t['is_active'] ? 'Wyłącz' : 'Włącz' ?>
      </button>
      <button type="button" class="btn btn-outline-danger btn-sm tw-ml-auto"
              onclick="tmDeleteTeam(<?= (int)$t['id'] ?>, <?= json_encode($t['name']) ?>, <?= (int)$t['workspace_count'] ?>)">
        <i class="bi bi-trash3" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal: dodaj/edytuj zespół ─────────────────────────────────────────── -->
<div class="modal fade" id="tmTeamModal" tabindex="-1" aria-labelledby="tmTeamModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="tmTeamModalLabel"><i class="bi bi-people-fill me-1 text-primary" aria-hidden="true"></i><span id="tm-team-modal-title">Nowy zespół</span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tm-team-id" value="0">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="tm-name">Nazwa <span class="text-danger">*</span></label>
          <input type="text" id="tm-name" class="form-control" maxlength="100" placeholder="np. Zespół komunikacji">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="tm-desc">Opis <span class="text-muted fw-normal">(opcjonalnie)</span></label>
          <textarea id="tm-desc" class="form-control" rows="2"></textarea>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label fw-semibold small" for="tm-color">Kolor</label>
            <input type="color" id="tm-color" class="form-control form-control-color" value="#2563eb">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small" for="tm-icon">Ikona (bootstrap-icons)</label>
            <input type="text" id="tm-icon" class="form-control" value="people-fill" placeholder="people-fill">
          </div>
        </div>
        <div id="tm-team-error" class="alert alert-danger py-2 small mt-3 d-none" role="alert"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="tm-team-save" onclick="tmSaveTeam()">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: członkowie zespołu ───────────────────────────────────────────── -->
<div class="modal fade" id="tmMembersModal" tabindex="-1" aria-labelledby="tmMembersModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="h6 modal-title fw-bold mb-0" id="tmMembersModalLabel"><i class="bi bi-people me-1 text-primary" aria-hidden="true"></i>Członkowie — <span id="tm-members-team-name"></span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tm-members-team-id" value="0">
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="tm-add-user-select">Dodaj osobę</label>
          <select id="tm-add-user-select" placeholder="Wyszukaj osobę…" aria-label="Wybierz osobę do dodania">
            <option value="">— wybierz —</option>
            <?php foreach ($all_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tw-text-[.7rem] tw-font-bold tw-uppercase tw-tracking-wide tw-text-slate-400 tw-mb-1">Obecni członkowie</div>
        <ul class="list-group" id="tm-members-list"></ul>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.TSK_TEAMS = { csrf: <?= json_encode(csrf_token()) ?>, base: <?= json_encode(rtrim(APP_URL, '/')) ?> };
</script>
<script src="<?= APP_URL ?>/assets/js/tasks-settings-teams.js" defer></script>

<?php require_once dirname(__DIR__) . '/includes/footer_tasks.php'; ?>
