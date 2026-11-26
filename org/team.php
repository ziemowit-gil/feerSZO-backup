<?php
declare(strict_types=1);

/**
 * org/team.php?id=&tab= — karta zespołu: Struktura organizacyjna › Zespoły › [nazwa].
 * Zakładki: czlonkowie (Członkowie i zastępstwa), umowy (Umowy zespołu), obszary (Obszary zadań), historia.
 * Podgląd: każdy zalogowany; zmiany: TeamService::canManage (przyciski ukryte, API i tak sprawdza).
 * Umowy pokazujemy BEZ kwot (rodzaj, numer, strona, status, daty).
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

$id   = (int)($_GET['id'] ?? 0);
$team = TeamService::get($id);
if (!$team) { flash_set('error', 'Nie ma takiego zespołu.'); header('Location: ' . APP_URL . '/org/teams.php'); exit; }

$me       = current_user();
$uid      = (int)$me['id'];
$canEdit  = TeamService::canManage($id);
$archived = $team['status'] === 'archived';
$TABS = ['czlonkowie' => 'Członkowie i zastępstwa', 'umowy' => 'Umowy zespołu', 'obszary' => 'Obszary zadań', 'historia' => 'Historia zmian'];
$tab  = isset($TABS[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'czlonkowie';

$members   = TeamService::members($id);
$warnings  = TeamService::memberWarnings($team, $members);
$children  = TeamService::children($id);
$ancestors = array_reverse(TeamService::ancestors($id));
$allUsers  = $canEdit ? db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY name") : [];
$memberIds = array_flip(array_map('intval', array_column($members, 'user_id')));

$PAGE_TITLE = $team['name'] . ' — Zespoły';
include dirname(__DIR__) . '/includes/header.php';

$statusBadge = ['active' => 'bg-success', 'suspended' => 'bg-warning text-dark', 'archived' => 'bg-secondary'];
$healthBadge = ['active' => 'bg-success', 'expiring' => 'bg-warning text-dark', 'ended' => 'bg-secondary', 'draft' => 'bg-light text-dark border', 'cancelled' => 'bg-danger', 'blocked' => 'bg-danger'];
$fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';
$contractLabel = fn(array $c) => $c['contract_type_label'] . ' ' . ($c['nr_rejestru'] ?: ($c['numer_umowy'] ?: '#' . $c['id']));
?>
<style>
.team-dot { width: 2.4rem; height: 2.4rem; border-radius: .6rem; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 1.2rem; flex-shrink: 0; }
.team-card a:focus-visible, .team-card button:focus-visible, .team-card .nav-link:focus-visible, .btn-check:focus-visible + .btn { outline: 3px solid #1E6DFF; outline-offset: 2px; }
.team-warn { color: #92400e; }
.team-meta dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
.team-meta dd { margin-bottom: .6rem; }
</style>
<div class="team-card">
<nav aria-label="Ścieżka" class="small mb-2">
  <a href="<?= APP_URL ?>/org/index.php">Struktura organizacyjna</a> ›
  <a href="<?= APP_URL ?>/org/teams.php">Zespoły</a> ›
  <?php foreach ($ancestors as $a): ?><a href="<?= APP_URL ?>/org/team.php?id=<?= (int)$a['id'] ?>"><?= h($a['name']) ?></a> › <?php endforeach; ?>
  <span aria-current="page"><?= h($team['name']) ?></span>
</nav>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
  <div class="d-flex align-items-center gap-3">
    <span class="team-dot" style="background:<?= h($team['color'] ?: '#1E6DFF') ?>" aria-hidden="true"><i class="bi <?= h($team['icon'] ?: 'bi-people-fill') ?>"></i></span>
    <div>
      <h1 class="h4 fw-bold mb-1"><?= h($team['name']) ?>
        <?php if ($team['code']): ?><span class="badge bg-light text-dark border font-monospace fs-6 align-middle"><?= h($team['code']) ?></span><?php endif; ?>
      </h1>
      <div class="d-flex flex-wrap gap-1">
        <span class="badge <?= $statusBadge[$team['status']] ?? 'bg-secondary' ?>"><?= h(TeamService::STATUSES[$team['status']] ?? '') ?></span>
        <span class="badge bg-light text-dark border"><?= h(TeamService::TYPES[$team['type']] ?? '') ?></span>
        <?php if ($archived): ?><span class="badge bg-light text-dark border"><i class="bi bi-lock me-1" aria-hidden="true"></i>Tylko do odczytu</span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($canEdit && !$archived): ?>
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#teamFormModal"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</button>
    <?php endif; ?>
    <?php if (is_admin()): ?>
      <?php if ($archived): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-team-action="team_status" data-team-id="<?= $id ?>" data-status="active"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć</button>
      <?php else: ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-team-action="team_status" data-team-id="<?= $id ?>" data-status="archived"
              data-confirm="Zarchiwizować zespół „<?= h($team['name']) ?>”? Zostanie tylko do odczytu, historia zostaje."><i class="bi bi-archive me-1" aria-hidden="true"></i>Archiwizuj</button>
      <?php endif; ?>
    <?php endif; ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1" aria-hidden="true"></i>Eksport</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/org/team_export.php?id=<?= $id ?>&amp;format=csv">Skład i umowy — CSV</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/org/team_export.php?id=<?= $id ?>&amp;format=print" target="_blank" rel="noopener">Do druku / PDF<span class="visually-hidden"> (otwiera nowe okno)</span></a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/org/team_export.php?id=<?= $id ?>&amp;format=form">Skład na wybrany dzień…</a></li>
      </ul>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <?php if ($team['description'] || $team['goal']): ?>
    <div class="bg-white border rounded-3 p-3 h-100">
      <?php if ($team['description']): ?><h2 class="h6 fw-bold">Zakres odpowiedzialności</h2><p class="mb-2"><?= nl2br(h($team['description'])) ?></p><?php endif; ?>
      <?php if ($team['goal']): ?><h2 class="h6 fw-bold">Cel i założenia</h2><p class="mb-0"><?= nl2br(h($team['goal'])) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <dl class="bg-white border rounded-3 p-3 mb-0 team-meta">
      <dt>Jednostka</dt><dd><?= $team['unit_name'] ? '<a href="' . APP_URL . '/org/units/view.php?id=' . (int)$team['unit_id'] . '">' . h($team['unit_name']) . '</a>' : '—' ?></dd>
      <dt>Zespół nadrzędny</dt><dd><?= $team['parent_name'] ? '<a href="' . APP_URL . '/org/team.php?id=' . (int)$team['parent_id'] . '">' . h($team['parent_name']) . '</a>' : '—' ?></dd>
      <?php if ($team['type'] === 'project'): ?><dt>Okres</dt><dd><?= $fmt($team['starts_at']) ?: '…' ?> – <?= $fmt($team['ends_at']) ?: '…' ?></dd><?php endif; ?>
      <dt>Podzespoły</dt>
      <dd class="mb-0"><?php if ($children): ?><ul class="list-unstyled mb-0"><?php foreach ($children as $c): ?><li><a href="<?= APP_URL ?>/org/team.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?= $c['status'] !== 'active' ? ' <span class="small text-muted">(' . h(mb_strtolower(TeamService::STATUSES[$c['status']] ?? '')) . ')</span>' : '' ?></li><?php endforeach; ?></ul><?php else: ?>—<?php endif; ?></dd>
    </dl>
  </div>
</div>

<div class="visually-hidden" role="status" aria-live="polite" data-team-live></div>

<nav aria-label="Zakładki zespołu">
  <ul class="nav nav-tabs mb-3">
    <?php foreach ($TABS as $k => $l): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="?id=<?= $id ?>&amp;tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= h($l) ?><?php if ($k === 'czlonkowie'): ?> <span class="badge bg-secondary"><?= count($members) ?></span><?php endif; ?><?php if ($k === 'czlonkowie' && $warnings): ?> <i class="bi bi-exclamation-triangle-fill team-warn" aria-hidden="true"></i><span class="visually-hidden">, ostrzeżenia: <?= count($warnings) ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<?php if ($tab === 'czlonkowie'): ?>
<!-- ═══ Członkowie i zastępstwa ═══ -->
<?php if ($warnings): ?>
<div class="alert alert-warning py-2" role="note">
  <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><strong>Podstawa formalna:</strong> <?= count($warnings) ?> <?= count($warnings) === 1 ? 'osoba wymaga' : 'osoby wymagają' ?> uwagi — szczegóły w kolumnie „Umowa-podstawa”.
</div>
<?php endif; ?>
<?php if ($canEdit && !$archived): ?>
<details class="bg-white border rounded-3 p-3 mb-3">
  <summary class="fw-semibold">Dodaj osobę do zespołu</summary>
  <form id="memberAdd" data-team-form="member_add" class="row g-2 align-items-end mt-2" novalidate>
    <input type="hidden" name="team_id" value="<?= $id ?>">
    <div class="team-form-error alert alert-danger py-2 small col-12" role="alert" tabindex="-1" hidden></div>
    <div class="col-md-4">
      <label class="form-label small" for="ma-user">Osoba</label>
      <select id="ma-user" name="user_id" class="form-select form-select-sm" data-person-contracts="#ma-contract" data-team-id="<?= $id ?>" aria-describedby="memberAdd-err-user_id" required>
        <option value="">— wybierz —</option>
        <?php foreach ($allUsers as $u): if (isset($memberIds[(int)$u['id']])) continue; ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
      <div id="memberAdd-err-user_id" class="team-field-error invalid-feedback d-block" hidden></div>
    </div>
    <div class="col-md-2">
      <label class="form-label small" for="ma-role">Rola w zespole</label>
      <select id="ma-role" name="role" class="form-select form-select-sm">
        <?php foreach (TeamService::ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'member' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small" for="ma-contract">Umowa-podstawa</label>
      <select id="ma-contract" name="contract_key" class="form-select form-select-sm" aria-describedby="ma-contract-hint"><option value="">— bez umowy —</option></select>
      <div id="ma-contract-hint" class="form-text" aria-live="polite"></div>
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj</button></div>
  </form>
</details>
<?php endif; ?>

<?php if (!$members): ?>
<p class="text-muted">W zespole nie ma jeszcze nikogo.</p>
<?php else: ?>
<div class="table-responsive bg-white border rounded-3">
  <table class="table mb-0 align-middle">
    <caption class="visually-hidden">Skład zespołu</caption>
    <thead class="table-light small"><tr>
      <th scope="col">Osoba</th><th scope="col">Rola</th><th scope="col">Umowa-podstawa</th><th scope="col">Zastępstwo</th>
      <?php if ($canEdit && !$archived): ?><th scope="col"><span class="visually-hidden">Działania</span></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($members as $m):
        $c = $m['contract_id'] ? TeamService::contractInfo((string)$m['contract_type'], (int)$m['contract_id']) : null;
        $w = $warnings[(int)$m['user_id']] ?? [];
        $depOn = TeamService::deputyActive($m);
        $fill = json_encode(['user_id' => (int)$m['user_id'], 'person_name' => $m['name'], 'role' => $m['role'] ?: 'member',
            'contract_key' => $m['contract_id'] ? $m['contract_type'] . ':' . $m['contract_id'] : '',
            'deputy_user_id' => $m['deputy_user_id'] ? (int)$m['deputy_user_id'] : '', 'deputy_from' => $m['deputy_from'] ?? '',
            'deputy_to' => $m['deputy_to'] ?? '', 'deputy_note' => $m['deputy_note'] ?? ''], JSON_UNESCAPED_UNICODE);
    ?>
      <tr>
        <th scope="row" class="fw-semibold"><?= h($m['name']) ?><?php if (!$m['user_active']): ?> <span class="badge bg-secondary">konto wyłączone</span><?php endif; ?></th>
        <td><?= h(TeamService::ROLES[$m['role'] ?: 'member'] ?? $m['role']) ?></td>
        <td>
          <?php if ($c): ?>
            <a href="<?= h($c['url']) ?>"><?= h($contractLabel($c)) ?></a>
            <span class="badge <?= $healthBadge[$c['health']['code']] ?? 'bg-secondary' ?>"><?= h($c['health']['label']) ?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          <?php foreach ($w as $wi): ?>
          <div class="small team-warn"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span class="visually-hidden">Ostrzeżenie: </span><?= h($wi['text']) ?></div>
          <?php endforeach; ?>
        </td>
        <td class="small">
          <?php if ($m['deputy_user_id']): ?>
            <?= h($m['deputy_name']) ?> <span class="text-muted"><?= $fmt($m['deputy_from']) ?: '…' ?> – <?= $fmt($m['deputy_to']) ?: '…' ?></span>
            <?php if ($depOn): ?><span class="badge bg-info text-dark">trwa</span><?php endif; ?>
            <?php if ($m['deputy_note']): ?><div class="text-muted"><?= h($m['deputy_note']) ?></div><?php endif; ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <?php if ($canEdit && !$archived): ?>
        <td class="text-end text-nowrap">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#memberEditModal" data-fill='<?= h($fill) ?>' aria-label="Zmień rolę lub umowę: <?= h($m['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#deputyModal" data-fill='<?= h($fill) ?>' aria-label="Zastępstwo: <?= h($m['name']) ?>"><i class="bi bi-person-fill-gear" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-outline-danger btn-sm" data-team-action="member_remove" data-team-id="<?= $id ?>" data-user-id="<?= (int)$m['user_id'] ?>"
                  data-confirm="Usunąć <?= h($m['name']) ?> z zespołu?" aria-label="Usuń z zespołu: <?= h($m['name']) ?>"><i class="bi bi-person-dash" aria-hidden="true"></i></button>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($canEdit && !$archived): ?>
<div class="modal fade" id="memberEditModal" tabindex="-1" aria-labelledby="memberEditTitle" aria-modal="true" role="dialog">
  <div class="modal-dialog"><form class="modal-content" id="memberEdit" data-team-form="member_update" novalidate>
    <div class="modal-header py-2"><h2 class="h6 modal-title" id="memberEditTitle">Rola i umowa — <span data-fill-text="person_name"></span></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <input type="hidden" name="team_id" value="<?= $id ?>">
      <div class="team-form-error alert alert-danger py-2 small" role="alert" tabindex="-1" hidden></div>
      <select name="user_id" class="d-none" data-person-contracts="#me-contract" data-team-id="<?= $id ?>" aria-hidden="true" tabindex="-1">
        <?php foreach ($members as $m): ?><option value="<?= (int)$m['user_id'] ?>"></option><?php endforeach; ?>
      </select>
      <label class="form-label small" for="me-role">Rola w zespole</label>
      <select id="me-role" name="role" class="form-select mb-3"><?php foreach (TeamService::ROLES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
      <label class="form-label small" for="me-contract">Umowa-podstawa</label>
      <select id="me-contract" name="contract_key" class="form-select" aria-describedby="me-contract-hint"><option value="">— bez umowy —</option></select>
      <div id="me-contract-hint" class="form-text" aria-live="polite"></div>
    </div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button><button type="submit" class="btn btn-primary btn-sm">Zapisz</button></div>
  </form></div>
</div>
<div class="modal fade" id="deputyModal" tabindex="-1" aria-labelledby="deputyTitle" aria-modal="true" role="dialog">
  <div class="modal-dialog"><form class="modal-content" id="deputyForm" data-team-form="deputy_set" novalidate>
    <div class="modal-header py-2"><h2 class="h6 modal-title" id="deputyTitle">Zastępstwo za: <span data-fill-text="person_name"></span></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <input type="hidden" name="team_id" value="<?= $id ?>"><input type="hidden" name="user_id">
      <div class="team-form-error alert alert-danger py-2 small" role="alert" tabindex="-1" hidden></div>
      <label class="form-label small" for="dp-user">Zastępca</label>
      <select id="dp-user" name="deputy_user_id" class="form-select mb-1" aria-describedby="deputyForm-err-deputy_user_id">
        <option value="">— wybierz —</option>
        <?php foreach ($allUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
      <div id="deputyForm-err-deputy_user_id" class="team-field-error invalid-feedback d-block" hidden></div>
      <div class="row g-2 mt-1">
        <div class="col-6"><label class="form-label small" for="dp-from">Od</label><input type="date" id="dp-from" name="deputy_from" class="form-control" aria-describedby="deputyForm-err-deputy_from"><div id="deputyForm-err-deputy_from" class="team-field-error invalid-feedback d-block" hidden></div></div>
        <div class="col-6"><label class="form-label small" for="dp-to">Do</label><input type="date" id="dp-to" name="deputy_to" class="form-control" aria-describedby="deputyForm-err-deputy_to"><div id="deputyForm-err-deputy_to" class="team-field-error invalid-feedback d-block" hidden></div></div>
      </div>
      <p class="form-text">Puste daty = bez ograniczenia. Zastępca lidera ma w tym czasie jego uprawnienia.</p>
      <label class="form-label small" for="dp-note">Notatka</label>
      <textarea id="dp-note" name="deputy_note" class="form-control" rows="2"></textarea>
    </div>
    <div class="modal-footer py-2 justify-content-between">
      <button type="button" class="btn btn-outline-danger btn-sm" id="deputyClear">Zakończ zastępstwo</button>
      <div><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button> <button type="submit" class="btn btn-primary btn-sm">Zapisz</button></div>
    </div>
  </form></div>
</div>
<script>
document.addEventListener('click', function (e) {
  if (e.target.closest('#deputyClear')) {
    const f = document.getElementById('deputyForm');
    const b = e.target.closest('#deputyClear');
    b.setAttribute('data-team-action', 'deputy_clear');
    b.setAttribute('data-team-id', f.team_id.value);
    b.setAttribute('data-user-id', f.user_id.value);
  }
}, true);
</script>
<?php endif; ?>

<?php elseif ($tab === 'umowy'):
    $contracts = TeamService::getTeamContracts($id);
    $linkable = [];
    if ($canEdit && !$archived) {
        $linked = array_flip(array_map(fn($c) => $c['contract_type'] . ':' . $c['id'], array_filter($contracts, fn($c) => $c['source'] === 'zespol')));
        foreach (all_contracts_registry() as $r) {
            $k = $r['contract_type'] . ':' . $r['id'];
            if (!isset($linked[$k]) && TeamService::allowsContract((string)$r['contract_type'])
                && contractStatusGroup((string)$r['status']) !== 'cancelled') $linkable[] = $r;
        }
    }
?>
<!-- ═══ Umowy zespołu ═══ -->
<?php if ($canEdit && !$archived): ?>
<details class="bg-white border rounded-3 p-3 mb-3">
  <summary class="fw-semibold">Przypnij umowę do zespołu</summary>
  <form id="contractLink" data-team-form="contract_link" class="row g-2 align-items-end mt-2" novalidate>
    <input type="hidden" name="team_id" value="<?= $id ?>">
    <div class="team-form-error alert alert-danger py-2 small col-12" role="alert" tabindex="-1" hidden></div>
    <div class="col-md-6">
      <label class="form-label small" for="cl-contract">Umowa zlecenia lub wolontariacka (z Rejestru Umów)</label>
      <select id="cl-contract" name="contract_key" class="form-select form-select-sm" required>
        <option value="">— wybierz —</option>
        <?php foreach ($linkable as $r): ?><option value="<?= h($r['contract_type'] . ':' . $r['id']) ?>"><?= h($r['nr_rejestru'] . ' · ' . $r['contract_type_label'] . ' · ' . $r['strona']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small" for="cl-zakres">Zakres / wymiar <span class="text-muted">(opcjonalnie)</span></label>
      <input type="text" id="cl-zakres" name="zakres" class="form-control form-control-sm" maxlength="255" placeholder="np. koordynacja, 20 h/mies.">
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Przypnij</button></div>
  </form>
</details>
<?php endif; ?>
<?php if (!$contracts): ?>
<p class="text-muted">Do zespołu nie przypięto umów, a członkowie nie mają wskazanej umowy-podstawy.</p>
<?php else: ?>
<div class="table-responsive bg-white border rounded-3">
  <table class="table mb-0 align-middle">
    <caption class="visually-hidden">Umowy zespołu (bez kwot)</caption>
    <thead class="table-light small"><tr>
      <th scope="col">Umowa</th><th scope="col">Strona umowy</th><th scope="col">Stan</th><th scope="col">Okres</th><th scope="col">Zakres</th><th scope="col">Skąd</th>
      <?php if ($canEdit && !$archived): ?><th scope="col"><span class="visually-hidden">Działania</span></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($contracts as $c): ?>
      <tr>
        <th scope="row"><a href="<?= h($c['url']) ?>"><?= h($contractLabel($c)) ?></a></th>
        <td><?= h($c['strona']) ?></td>
        <td><span class="badge <?= $healthBadge[$c['health']['code']] ?? 'bg-secondary' ?>"><?= h($c['health']['label']) ?></span>
          <?php if ($team['type'] === 'project' && $team['ends_at'] && $c['data_zakonczenia'] && $c['data_zakonczenia'] < $team['ends_at'] && in_array($c['health']['code'], ['active', 'expiring'], true)): ?>
          <div class="small team-warn"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span class="visually-hidden">Ostrzeżenie: </span>kończy się przed końcem zespołu</div>
          <?php endif; ?>
        </td>
        <td class="small text-nowrap"><?= $fmt($c['data_rozpoczecia']) ?: '…' ?> – <?= $fmt($c['data_zakonczenia']) ?: '…' ?></td>
        <td class="small"><?= h($c['zakres']) ?></td>
        <td class="small"><?= $c['source'] === 'zespol' ? 'przypięta do zespołu' : 'podstawa: ' . h($c['member']) ?></td>
        <?php if ($canEdit && !$archived): ?>
        <td class="text-end"><?php if ($c['source'] === 'zespol'): ?>
          <button type="button" class="btn btn-outline-danger btn-sm" data-team-action="contract_unlink" data-team-id="<?= $id ?>" data-contract-type="<?= h($c['contract_type']) ?>" data-contract-id="<?= (int)$c['id'] ?>"
                  data-confirm="Odpiąć umowę <?= h($contractLabel($c)) ?> od zespołu?" aria-label="Odepnij umowę <?= h($contractLabel($c)) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i> Odepnij</button>
        <?php endif; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted mt-2">Kwot umów tu nie pokazujemy — są na karcie umowy dla osób z dostępem do niej. Lista w Rejestrze Umów: <a href="<?= APP_URL ?>/contracts/rejestr.php?team=<?= $id ?>">umowy tego zespołu</a>.</p>
<?php endif; ?>

<?php elseif ($tab === 'obszary'):
    $linkedWs = db_all("SELECT tw.id, tw.name, tw.color, wt.role FROM task_workspace_teams wt JOIN task_workspaces tw ON tw.id=wt.workspace_id
                        WHERE wt.team_id=? AND tw.is_active=1 ORDER BY tw.name", [$id]);
    $linkedIds = array_flip(array_map('intval', array_column($linkedWs, 'id')));
    $myWs = array_values(array_filter(task_user_workspaces($uid), fn($w) => !isset($linkedIds[(int)$w['id']])
        && in_array(task_workspace_role((int)$w['id'], $uid), ['admin', 'editor'], true)));
    $roleLabels = ['admin' => 'Administrator', 'editor' => 'Edytor', 'member' => 'Członek', 'viewer' => 'Podgląd'];
?>
<!-- ═══ Obszary zadań ═══ -->
<p class="small text-muted">Członkowie zespołu dostają w przypiętym obszarze wskazaną rolę (obserwator zespołu — najwyżej podgląd). Zmiana składu zespołu od razu zmienia dostęp w Zadaniach i grupę zespołu w chmurze.</p>
<?php if ($canEdit && !$archived): ?>
<div class="bg-white border rounded-3 p-3 mb-3">
  <form id="wsLink" data-team-form="workspace_link" class="row g-2 align-items-end" novalidate>
    <input type="hidden" name="team_id" value="<?= $id ?>">
    <div class="team-form-error alert alert-danger py-2 small col-12" role="alert" tabindex="-1" hidden></div>
    <div class="col-md-5">
      <label class="form-label small" for="ws-id">Obszar zadań</label>
      <select id="ws-id" name="workspace_id" class="form-select form-select-sm" required aria-describedby="ws-id-hint">
        <option value="">— wybierz —</option>
        <?php foreach ($myWs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= h($w['name']) ?></option><?php endforeach; ?>
      </select>
      <div id="ws-id-hint" class="form-text">Lista: obszary, w których masz rolę administratora albo edytora.</div>
    </div>
    <div class="col-md-3">
      <label class="form-label small" for="ws-role">Rola zespołu w obszarze</label>
      <select id="ws-role" name="role" class="form-select form-select-sm"><?php foreach ($roleLabels as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'member' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Przypnij</button></div>
    <div class="col-md-2"><button type="button" class="btn btn-outline-primary btn-sm w-100" data-team-action="workspace_create" data-team-id="<?= $id ?>" data-success="url"
        data-confirm="Utworzyć w Zadaniach nowy obszar „<?= h($team['name']) ?>” dla tego zespołu (rola zespołu: edytor)?"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowy obszar</button></div>
  </form>
</div>
<?php endif; ?>
<?php if (!$linkedWs): ?>
<p class="text-muted">Zespół nie ma przypiętych obszarów zadań.</p>
<?php else: ?>
<ul class="list-group">
  <?php foreach ($linkedWs as $w): $wsRole = task_workspace_role((int)$w['id'], $uid); $wsCan = $canEdit && !$archived && in_array($wsRole, ['admin', 'editor'], true); ?>
  <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-kanban" style="color:<?= h($w['color'] ?: '#1E6DFF') ?>" aria-hidden="true"></i>
    <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= (int)$w['id'] ?>" class="fw-semibold me-auto"><?= h($w['name']) ?></a>
    <?php if ($wsCan): ?>
    <form id="wsRole<?= (int)$w['id'] ?>" data-team-form="workspace_link" class="d-flex gap-1 align-items-center">
      <input type="hidden" name="team_id" value="<?= $id ?>"><input type="hidden" name="workspace_id" value="<?= (int)$w['id'] ?>">
      <label class="visually-hidden" for="wsr-<?= (int)$w['id'] ?>">Rola zespołu w obszarze <?= h($w['name']) ?></label>
      <select id="wsr-<?= (int)$w['id'] ?>" name="role" class="form-select form-select-sm w-auto"><?php foreach ($roleLabels as $k => $l): ?><option value="<?= $k ?>"<?= $w['role'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
      <button type="submit" class="btn btn-outline-secondary btn-sm">Zmień</button>
    </form>
    <button type="button" class="btn btn-outline-danger btn-sm" data-team-action="workspace_unlink" data-team-id="<?= $id ?>" data-workspace-id="<?= (int)$w['id'] ?>"
            data-confirm="Odpiąć zespół od obszaru „<?= h($w['name']) ?>”? Członkowie stracą dostęp nadany przez zespół." aria-label="Odepnij obszar <?= h($w['name']) ?>">Odepnij</button>
    <?php else: ?>
    <span class="badge bg-light text-dark border"><?= h($roleLabels[$w['role']] ?? $w['role']) ?></span>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php else:
    $log = TeamService::auditList($id);
    $show = function (?string $json): string {
        if ($json === null || $json === '') return '';
        $d = json_decode($json, true);
        if (!is_array($d)) return h($json);
        $skip = ['updated_at', 'is_active', 'icon', 'color'];
        $parts = [];
        foreach ($d as $k => $v) {
            if (in_array($k, $skip, true) || is_array($v)) continue;
            $parts[] = h($k) . ': ' . h($v === null || $v === '' ? '—' : (string)$v);
        }
        return implode('; ', $parts);
    };
?>
<!-- ═══ Historia zmian ═══ -->
<?php if (!$log): ?>
<p class="text-muted">Brak zapisanych zmian.</p>
<?php else: ?>
<div class="table-responsive bg-white border rounded-3">
  <table class="table table-sm mb-0 align-middle small">
    <caption class="visually-hidden">Historia zmian zespołu, od najnowszych</caption>
    <thead class="table-light"><tr><th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Zdarzenie</th><th scope="col">Dotyczy</th><th scope="col">Przed</th><th scope="col">Po</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr>
        <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></td>
        <td><?= h($l['actor_name'] ?: 'system') ?></td>
        <td><?= h(TeamService::EVENTS[$l['event']] ?? $l['event']) ?></td>
        <td><?= h($l['target_name'] ?? '') ?></td>
        <td class="text-muted"><?= $show($l['before_json']) ?></td>
        <td><?= $show($l['after_json']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if ($canEdit && !$archived) { $TF_TEAM = $team; $TF_UNITS = org_units_all('active'); $TF_TEAMS = TeamService::list(); include __DIR__ . '/includes/team_form_modal.php'; } ?>
<script>window.ORG_TEAMS = { csrf: <?= json_encode(csrf_token()) ?>, api: <?= json_encode(APP_URL . '/org/team_api.php') ?> };</script>
<script src="<?= APP_URL ?>/assets/js/org-teams.js?v=1" defer></script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
