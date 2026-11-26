<?php
declare(strict_types=1);

/**
 * org/teams.php — Struktura Organizacyjna › Zespoły (lista). Karta zespołu: org/team.php?id=.
 * Dane i uprawnienia: modules/teams/lib/TeamService.php; zmiany: org/team_api.php.
 * Filtry: q, type, status (domyślnie bez zarchiwizowanych), unit, mine=1 („Moje zespoły”).
 * Podzespoły pokazujemy pod zespołem nadrzędnym (wcięcie), gdy nie ma filtrów zawężających.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_once dirname(__DIR__) . '/modules/teams/lib/TeamService.php';

require_login();
require_module_enabled('org_enabled', 'Moduł struktury organizacyjnej');
TeamService::migrate();

$me     = current_user();
$f = [
    'q'       => trim((string)($_GET['q'] ?? '')),
    'type'    => (string)($_GET['type'] ?? ''),
    'status'  => (string)($_GET['status'] ?? ''),
    'unit_id' => (int)($_GET['unit'] ?? 0),
];
$mine = !empty($_GET['mine']);
if ($mine) $f['ids'] = TeamService::getUserTeamIds((int)$me['id']);
$teams = TeamService::list($f);
if ($f['status'] === '') $teams = array_values(array_filter($teams, fn($t) => $t['status'] !== 'archived'));
$archivedCount = (int)(db_one("SELECT COUNT(*) c FROM task_teams WHERE status='archived'")['c'] ?? 0);
$filtered = $f['q'] !== '' || $f['type'] !== '' || $f['status'] !== '' || $f['unit_id'] || $mine;

// Drzewo: rodzic → dzieci (tylko bez filtrów; z filtrami płaska lista).
$rows = [];
if (!$filtered) {
    $byParent = [];
    $ids = array_column($teams, 'id');
    foreach ($teams as $t) {
        $p = (int)($t['parent_id'] ?? 0);
        $byParent[in_array($p, $ids) ? $p : 0][] = $t;
    }
    $walk = function (int $pid, int $depth) use (&$walk, &$rows, $byParent) {
        foreach ($byParent[$pid] ?? [] as $t) {
            $t['_depth'] = $depth;
            $rows[] = $t;
            if ($depth < 8) $walk((int)$t['id'], $depth + 1);
        }
    };
    $walk(0, 0);
} else {
    $rows = array_map(fn($t) => $t + ['_depth' => 0], $teams);
}
$myIds = array_flip(TeamService::getUserTeamIds((int)$me['id'], false));

$units = org_units_all('active');
$TF_TEAM = null; $TF_UNITS = $units; $TF_TEAMS = TeamService::list();

$PAGE_TITLE = 'Zespoły — Struktura Organizacyjna';
include dirname(__DIR__) . '/includes/header.php';
$statusBadge = ['active' => 'bg-success', 'suspended' => 'bg-warning text-dark', 'archived' => 'bg-secondary'];
?>
<style>
.team-tbl td, .team-tbl th { vertical-align: middle; }
.team-dot { width: 1.9rem; height: 1.9rem; border-radius: .5rem; display: inline-flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.team-tbl a:focus-visible, .team-icon-pick .btn:focus-visible, .btn-check:focus-visible + .btn { outline: 3px solid #1E6DFF; outline-offset: 2px; }
</style>

<nav aria-label="Ścieżka" class="small mb-1"><a href="<?= APP_URL ?>/org/index.php">Struktura organizacyjna</a> › <span aria-current="page">Zespoły</span></nav>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-people-fill text-primary me-2" aria-hidden="true"></i>Zespoły</h1>
  <?php if (TeamService::canCreate()): ?>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#teamFormModal">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy zespół
  </button>
  <?php endif; ?>
</div>
<?php $ORG_NAV_ACTIVE = 'zespoly'; include __DIR__ . '/includes/org_nav.php'; ?>
<?= flash_html() ?>
<div class="visually-hidden" role="status" aria-live="polite" data-team-live></div>

<form method="get" class="row g-2 align-items-end mb-3" aria-label="Filtry zespołów">
  <div class="col-sm-6 col-lg-3">
    <label class="form-label small mb-1" for="f-q">Szukaj</label>
    <input type="search" id="f-q" name="q" class="form-control form-control-sm" value="<?= h($f['q']) ?>" placeholder="Nazwa albo kod">
  </div>
  <div class="col-6 col-lg-2">
    <label class="form-label small mb-1" for="f-type">Typ</label>
    <select id="f-type" name="type" class="form-select form-select-sm">
      <option value="">Wszystkie</option>
      <?php foreach (TeamService::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-lg-2">
    <label class="form-label small mb-1" for="f-status">Status</label>
    <select id="f-status" name="status" class="form-select form-select-sm">
      <option value="">Aktywne i zawieszone</option>
      <?php foreach (TeamService::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-6 col-lg-3">
    <label class="form-label small mb-1" for="f-unit">Jednostka</label>
    <select id="f-unit" name="unit" class="form-select form-select-sm">
      <option value="0">Wszystkie</option>
      <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['unit_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <div class="form-check mb-1">
      <input class="form-check-input" type="checkbox" id="f-mine" name="mine" value="1"<?= $mine ? ' checked' : '' ?>>
      <label class="form-check-label small" for="f-mine">Moje zespoły</label>
    </div>
  </div>
  <div class="col-auto d-flex gap-1">
    <button type="submit" class="btn btn-sm btn-primary">Filtruj</button>
    <?php if ($filtered): ?><a href="<?= APP_URL ?>/org/teams.php" class="btn btn-sm btn-outline-secondary">Wyczyść</a><?php endif; ?>
  </div>
</form>

<?php if (!$rows): ?>
<div class="border rounded-3 bg-white p-4 text-center text-muted">
  <i class="bi bi-people fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
  <?= $filtered ? 'Żaden zespół nie pasuje do filtrów.' : 'Nie ma jeszcze zespołów.' ?>
</div>
<?php else: ?>
<div class="table-responsive bg-white border rounded-3">
  <table class="table table-hover mb-0 team-tbl">
    <caption class="visually-hidden">Zespoły (<?= count($rows) ?>)<?= $filtered ? ' — po filtrach' : '' ?></caption>
    <thead class="table-light small">
      <tr>
        <th scope="col">Zespół</th>
        <th scope="col">Typ</th>
        <th scope="col">Status</th>
        <th scope="col">Jednostka</th>
        <th scope="col" class="text-end">Osoby</th>
        <th scope="col" class="text-end">Umowy</th>
        <th scope="col" class="text-end">Obszary zadań</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $t): ?>
      <tr<?= $t['status'] === 'archived' ? ' class="text-muted"' : '' ?>>
        <td>
          <div class="d-flex align-items-center gap-2" style="padding-left:<?= (int)$t['_depth'] * 1.5 ?>rem">
            <?php if ($t['_depth']): ?><span class="text-muted" aria-hidden="true">↳</span><?php endif; ?>
            <span class="team-dot" style="background:<?= h($t['color'] ?: '#1E6DFF') ?>" aria-hidden="true"><i class="bi <?= h($t['icon'] ?: 'bi-people-fill') ?>"></i></span>
            <div>
              <a href="<?= APP_URL ?>/org/team.php?id=<?= (int)$t['id'] ?>" class="fw-semibold"><?= h($t['name']) ?></a>
              <?php if ($t['_depth']): ?><span class="visually-hidden">(podzespół)</span><?php endif; ?>
              <?php if (!empty($t['code'])): ?><span class="badge bg-light text-dark border font-monospace ms-1"><?= h($t['code']) ?></span><?php endif; ?>
              <?php if (isset($myIds[(int)$t['id']])): ?><span class="badge bg-primary-subtle text-primary-emphasis ms-1">Mój</span><?php endif; ?>
              <?php if ($t['type'] === 'project' && ($t['starts_at'] || $t['ends_at'])): ?>
              <div class="small text-muted"><?= $t['starts_at'] ? date('d.m.Y', strtotime($t['starts_at'])) : '…' ?> – <?= $t['ends_at'] ? date('d.m.Y', strtotime($t['ends_at'])) : '…' ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td class="small"><?= h(TeamService::TYPES[$t['type']] ?? $t['type']) ?></td>
        <td><span class="badge <?= $statusBadge[$t['status']] ?? 'bg-secondary' ?>"><?= h(TeamService::STATUSES[$t['status']] ?? $t['status']) ?></span></td>
        <td class="small"><?= $t['unit_name'] ? '<a href="' . APP_URL . '/org/units/view.php?id=' . (int)$t['unit_id'] . '">' . h($t['unit_name']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end"><?= (int)$t['member_count'] ?></td>
        <td class="text-end"><?= (int)$t['contract_count'] ?></td>
        <td class="text-end"><?= (int)$t['workspace_count'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php if ($f['status'] === '' && $archivedCount): ?>
<p class="small text-muted mt-2"><a href="<?= APP_URL ?>/org/teams.php?status=archived">Zarchiwizowane zespoły (<?= $archivedCount ?>)</a></p>
<?php endif; ?>

<?php if (TeamService::canCreate()) include __DIR__ . '/includes/team_form_modal.php'; ?>
<script>window.ORG_TEAMS = { csrf: <?= json_encode(csrf_token()) ?>, api: <?= json_encode(APP_URL . '/org/team_api.php') ?> };</script>
<script src="<?= APP_URL ?>/assets/js/org-teams.js?v=1" defer></script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
