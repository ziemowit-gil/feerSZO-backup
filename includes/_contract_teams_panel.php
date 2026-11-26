<?php
/**
 * includes/_contract_teams_panel.php — sekcja „Zespoły” na karcie umowy (wszystkie rodzaje, obok „Sprawa EZD”).
 * Wymaga w zasięgu: $TYPE (rodzaj umowy), $id (id umowy). Dane: modules/teams/lib/TeamService.php.
 *   • zespoły przypięte do umowy (contract_teams, z zakresem) — przypięcie/odpięcie: lider zespołu albo administrator;
 *   • zespoły, w których umowa jest podstawą udziału członka (task_team_members.contract_*).
 * Przy przypięciu strona proponuje dopisanie wykonawcy umowy (konto SZO o tym samym e-mailu) do składu.
 */
if (!module_enabled('org_enabled')) return;
require_once dirname(__DIR__) . '/modules/teams/lib/TeamService.php';

try {
    TeamService::migrate();
    $_ct_type  = (string)$TYPE;
    $_ct_id    = (int)$id;
    $_ct_teams = TeamService::getContractTeams($_ct_id, $_ct_type);
    $_ct_basis = db_all(
        "SELECT t.id, t.name, t.status, m.role, u.name AS person FROM task_team_members m
         JOIN task_teams t ON t.id=m.team_id JOIN users u ON u.id=m.user_id
         WHERE m.contract_type=? AND m.contract_id=? ORDER BY t.name", [$_ct_type, $_ct_id]);
    $_ct_linked = array_flip(array_map('intval', array_column($_ct_teams, 'id')));
    $_ct_options = array_values(array_filter(TeamService::list(),
        fn($t) => $t['status'] !== 'archived' && !isset($_ct_linked[(int)$t['id']]) && TeamService::canManage((int)$t['id'])));
} catch (\Throwable $e) {
    return;
}
$_ct_roles = TeamService::ROLES;
?>
<div class="card mb-3" id="contract-teams-panel">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-people-fill text-primary" aria-hidden="true"></i>
    <h2 class="h6 mb-0 fw-bold">Zespoły</h2>
    <a href="<?= APP_URL ?>/org/teams.php" class="small ms-auto">Wszystkie zespoły</a>
  </div>
  <div class="card-body">
    <div class="visually-hidden" role="status" aria-live="polite" data-team-live></div>
    <?php if (!$_ct_teams && !$_ct_basis): ?>
    <p class="text-muted small mb-2">Umowa nie jest przypięta do żadnego zespołu.</p>
    <?php endif; ?>
    <?php if ($_ct_teams): ?>
    <ul class="list-unstyled mb-2">
      <?php foreach ($_ct_teams as $t): $canT = $t['status'] !== 'archived' && TeamService::canManage((int)$t['id']); ?>
      <li class="d-flex align-items-center gap-2 flex-wrap py-1 border-bottom">
        <a href="<?= APP_URL ?>/org/team.php?id=<?= (int)$t['id'] ?>&amp;tab=umowy" class="fw-semibold"><?= h($t['name']) ?></a>
        <?php if ($t['status'] !== 'active'): ?><span class="badge bg-secondary"><?= h(TeamService::STATUSES[$t['status']] ?? '') ?></span><?php endif; ?>
        <?php if ($t['zakres'] !== ''): ?><span class="small text-muted"><?= h($t['zakres']) ?></span><?php endif; ?>
        <?php if ($canT): ?>
        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-team-action="contract_unlink" data-team-id="<?= (int)$t['id'] ?>"
                data-contract-type="<?= h($_ct_type) ?>" data-contract-id="<?= $_ct_id ?>" data-confirm="Odpiąć umowę od zespołu „<?= h($t['name']) ?>”?"
                aria-label="Odepnij od zespołu <?= h($t['name']) ?>">Odepnij</button>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($_ct_basis): ?>
    <p class="small mb-2"><strong>Podstawa udziału w zespole:</strong>
      <?php foreach ($_ct_basis as $i => $b): ?><?= $i ? ', ' : '' ?><a href="<?= APP_URL ?>/org/team.php?id=<?= (int)$b['id'] ?>"><?= h($b['name']) ?></a> (<?= h($b['person']) ?>, <?= h(mb_strtolower($_ct_roles[$b['role'] ?: 'member'] ?? '')) ?>)<?php endforeach; ?>
    </p>
    <?php endif; ?>
    <?php if ($_ct_options): ?>
    <form id="ctTeamLink" data-team-form="contract_link" class="row g-2 align-items-end" novalidate>
      <input type="hidden" name="contract_key" value="<?= h($_ct_type . ':' . $_ct_id) ?>">
      <div class="team-form-error alert alert-danger py-2 small col-12" role="alert" tabindex="-1" hidden></div>
      <div class="col-sm-5">
        <label class="form-label small mb-1" for="ct-team">Przypnij do zespołu</label>
        <select id="ct-team" name="team_id" class="form-select form-select-sm" required>
          <option value="">— wybierz zespół —</option>
          <?php foreach ($_ct_options as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?><?= $t['code'] ? ' (' . h($t['code']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-5">
        <label class="form-label small mb-1" for="ct-zakres">Zakres / wymiar <span class="text-muted">(opcjonalnie)</span></label>
        <input type="text" id="ct-zakres" name="zakres" class="form-control form-control-sm" maxlength="255">
      </div>
      <div class="col-sm-2"><button type="submit" class="btn btn-outline-primary btn-sm w-100">Przypnij</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>window.ORG_TEAMS = window.ORG_TEAMS || { csrf: <?= json_encode(csrf_token()) ?>, api: <?= json_encode(APP_URL . '/org/team_api.php') ?> };</script>
<script src="<?= APP_URL ?>/assets/js/org-teams.js?v=1" defer></script>
