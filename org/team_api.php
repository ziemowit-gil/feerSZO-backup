<?php
declare(strict_types=1);

/**
 * org/team_api.php — zmiany w Zespołach Struktury Organizacyjnej (modules/teams/lib/TeamService.php).
 * POST JSON { _csrf, action, … } → { ok, data } / { ok:false, error }.
 *
 * Uprawnienia: nowy zespół — administrator; reszta — TeamService::canManage (administrator, lider zespołu
 * lub zespołu nadrzędnego, zastępca lidera w okresie zastępstwa). Zarchiwizowany zespół: tylko odczyt
 * (administrator może zmienić status). Obszar zadań: dodatkowo rola admin/editor w tym obszarze.
 * Każda zmiana trafia do team_audit_logs i audit_log().
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/teams/lib/TeamService.php';

require_login();
require_module_enabled('org_enabled', 'Moduł struktury organizacyjnej');
TeamService::migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') team_fail('Metoda niedozwolona.', 405);
$body = task_parse_json_body();
task_csrf_check($body);
if (function_exists('system_block_writes')) system_block_writes();

$me     = current_user();
$uid    = (int)$me['id'];
$action = (string)($body['action'] ?? '');
$teamId = (int)($body['team_id'] ?? 0);
$now    = date('Y-m-d H:i:s');

/** Błąd JSON; $errors = [pole => komunikat] dla błędów przy polach formularza. */
function team_fail(string $message, int $code = 400, array $extra = []): never
{
    task_api_json(['ok' => false, 'error' => $message] + $extra, $code);
}

/** Data Y-m-d albo null; zła data → błąd z nazwą pola. */
function team_date(array $b, string $key, string $label): ?string
{
    $v = trim((string)($b[$key] ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) team_fail("Pole „{$label}” musi być datą (RRRR-MM-DD).", 422);
    return $v;
}

function team_require_manage(int $teamId): array
{
    $team = TeamService::get($teamId);
    if (!$team) team_fail('Zespół nie istnieje.', 404);
    if (!TeamService::canManage($teamId)) team_fail('Nie możesz zmieniać tego zespołu.', 403);
    if ($team['status'] === 'archived') team_fail('Zespół jest zarchiwizowany — tylko do odczytu.', 409);
    return $team;
}

function team_user_exists(int $id): bool
{
    return $id > 0 && (bool)db_one("SELECT 1 FROM users WHERE id=? AND COALESCE(is_active,1)=1", [$id]);
}

/** Umowa (rodzaj + id) — musi istnieć; zwraca dane bez kwot. */
function team_contract(array $b): array
{
    $type = (string)($b['contract_type'] ?? '');
    $id   = (int)($b['contract_id'] ?? 0);
    $info = TeamService::contractInfo($type, $id);
    if (!$info) team_fail('Nie ma takiej umowy.', 404);
    return $info;
}

// ── Zespół: zapis (nowy / zmiana) ─────────────────────────────────────────────
if ($action === 'team_save') {
    $isNew = $teamId === 0;
    if ($isNew) {
        if (!TeamService::canCreate()) team_fail('Nowy zespół zakłada administrator.', 403);
        $before = null;
    } else {
        $before = team_require_manage($teamId);
    }
    $errors = [];
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') $errors['name'] = 'Podaj nazwę zespołu.';
    elseif (mb_strlen($name) > 100) $errors['name'] = 'Nazwa może mieć najwyżej 100 znaków.';
    $code = strtoupper(trim((string)($body['code'] ?? '')));
    if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/', $code)) {
        $errors['code'] = 'Kod: wielkie litery, cyfry, „-” i „_”, do 40 znaków.';
    } elseif ($code !== '' && db_one("SELECT 1 FROM task_teams WHERE code=? AND id<>?", [$code, $teamId])) {
        $errors['code'] = 'Ten kod ma już inny zespół.';
    }
    $type = (string)($body['type'] ?? 'permanent');
    if (!isset(TeamService::TYPES[$type])) $type = 'permanent';
    $status = (string)($body['status'] ?? ($before['status'] ?? 'active'));
    if (!isset(TeamService::STATUSES[$status])) $status = 'active';
    if ($status !== ($before['status'] ?? 'active') && !is_admin() && $status === 'archived') {
        $errors['status'] = 'Archiwizuje administrator.';
    }
    $unitId = (int)($body['unit_id'] ?? 0);
    if ($unitId && !db_one("SELECT 1 FROM org_units WHERE id=?", [$unitId])) $unitId = 0;
    $parentId = (int)($body['parent_id'] ?? 0);
    if ($parentId) {
        if (!db_one("SELECT 1 FROM task_teams WHERE id=?", [$parentId])) $parentId = 0;
        elseif (!$isNew && TeamService::isDescendant($parentId, $teamId)) $errors['parent_id'] = 'Zespół nie może być podzespołem samego siebie ani swojego podzespołu.';
    }
    $startsAt = team_date($body, 'starts_at', 'Początek');
    $endsAt   = team_date($body, 'ends_at', 'Koniec');
    if ($startsAt && $endsAt && $endsAt < $startsAt) $errors['ends_at'] = 'Koniec nie może być przed początkiem.';
    if ($type === 'permanent') { $startsAt = null; $endsAt = null; }
    $icon = preg_replace('/[^a-z0-9\-]/', '', (string)($body['icon'] ?? 'people-fill')) ?: 'people-fill';
    if (!in_array($icon, TeamService::ICONS, true)) $icon = 'people-fill';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($body['color'] ?? '')) ? (string)$body['color'] : '#1E6DFF';
    if ($errors) team_fail(reset($errors), 422, ['errors' => $errors]);

    $data = [
        'name' => $name, 'code' => $code !== '' ? $code : null, 'type' => $type, 'status' => $status,
        'is_active' => $status === 'active' ? 1 : 0, 'unit_id' => $unitId ?: null, 'parent_id' => $parentId ?: null,
        'description' => trim((string)($body['description'] ?? '')), 'goal' => trim((string)($body['goal'] ?? '')),
        'starts_at' => $startsAt, 'ends_at' => $endsAt, 'color' => $color, 'icon' => 'bi-' . $icon, 'updated_at' => $now,
    ];
    if ($isNew) {
        $teamId = db_insert('task_teams', $data + ['created_by' => $uid, 'created_at' => $now]);
        TeamService::audit($teamId, 'team_created', null, null, $data);
    } else {
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($data)));
        db()->prepare("UPDATE task_teams SET $sets WHERE id=?")->execute([...array_values($data), $teamId]);
        $changed = array_filter($data, fn($v, $k) => $k !== 'updated_at' && (string)($before[$k] ?? '') !== (string)($v ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            TeamService::audit($teamId, isset($changed['status']) && count($changed) <= 2 ? 'team_status' : 'team_updated', null,
                array_intersect_key($before, $changed), $changed);
        }
    }
    task_api_ok(['team_id' => $teamId, 'url' => APP_URL . '/org/team.php?id=' . $teamId]);
}

// ── Zmiana statusu (archiwizacja: administrator; wyjście z archiwum też administrator) ───────────
if ($action === 'team_status') {
    $team = TeamService::get($teamId);
    if (!$team) team_fail('Zespół nie istnieje.', 404);
    $status = (string)($body['status'] ?? '');
    if (!isset(TeamService::STATUSES[$status])) team_fail('Nieznany status.', 422);
    $adminOnly = $status === 'archived' || $team['status'] === 'archived';
    if ($adminOnly ? !is_admin() : !TeamService::canManage($teamId)) team_fail('Nie możesz zmienić statusu.', 403);
    db()->prepare("UPDATE task_teams SET status=?, is_active=?, updated_at=? WHERE id=?")
        ->execute([$status, $status === 'active' ? 1 : 0, $now, $teamId]);
    TeamService::audit($teamId, 'team_status', null, ['status' => $team['status']], ['status' => $status]);
    task_api_ok(['status' => $status]);
}

if ($action === 'team_delete') {
    if (!is_admin()) team_fail('Usuwa administrator.', 403);
    $team = TeamService::get($teamId);
    if (!$team) team_fail('Zespół nie istnieje.', 404);
    if (TeamService::children($teamId)) team_fail('Zespół ma podzespoły — najpierw je przenieś albo usuń.', 409);
    TeamService::audit($teamId, 'team_deleted', null, $team, null);
    db()->prepare("UPDATE team_member_history SET left_at=? WHERE team_id=? AND left_at IS NULL")->execute([date('Y-m-d'), $teamId]);
    db()->prepare("DELETE FROM task_teams WHERE id=?")->execute([$teamId]);
    db()->prepare("DELETE FROM task_team_members WHERE team_id=?")->execute([$teamId]);
    db()->prepare("DELETE FROM task_workspace_teams WHERE team_id=?")->execute([$teamId]);
    db()->prepare("DELETE FROM contract_teams WHERE team_id=?")->execute([$teamId]);
    task_api_ok(['url' => APP_URL . '/org/teams.php']);
}

// ── Podpowiedzi umów osoby (do wyboru podstawy udziału) ─────────────────────────
if ($action === 'person_contracts') {
    team_require_manage($teamId);
    $pid = (int)($body['user_id'] ?? 0);
    $list = array_map(fn($c) => [
        'contract_type' => $c['contract_type'], 'contract_id' => (int)$c['id'],
        'label' => $c['contract_type_label'] . ' ' . ($c['nr_rejestru'] ?: $c['numer_umowy'] ?: '#' . $c['id'])
            . ($c['data_zakonczenia'] ? ' (do ' . date('d.m.Y', strtotime((string)$c['data_zakonczenia'])) . ')' : ''),
        'health' => $c['health'],
    ], TeamService::getPersonActiveContracts($pid));
    task_api_ok(['contracts' => $list]);
}

// ── Skład ─────────────────────────────────────────────────────────────────────
if (in_array($action, ['member_add', 'member_update'], true)) {
    team_require_manage($teamId);
    $pid  = (int)($body['user_id'] ?? 0);
    if (!team_user_exists($pid)) team_fail('Wybierz osobę z listy.', 422, ['errors' => ['user_id' => 'Wybierz osobę z listy.']]);
    $role = (string)($body['role'] ?? 'member');
    if (!isset(TeamService::ROLES[$role])) $role = 'member';
    $ctype = null; $cid = null;
    if (!empty($body['contract_id'])) {
        $c = team_contract($body);
        $ctype = $c['contract_type']; $cid = (int)$c['id'];
    }
    $before = db_one("SELECT role, contract_type, contract_id FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, $pid]);
    if ($action === 'member_add' && $before) team_fail('Ta osoba już jest w zespole.', 409);
    if ($action === 'member_update' && !$before) team_fail('Tej osoby nie ma w zespole.', 404);
    $after = ['role' => $role, 'contract_type' => $ctype, 'contract_id' => $cid];
    if ($before) {
        db()->prepare("UPDATE task_team_members SET role=?, contract_type=?, contract_id=? WHERE team_id=? AND user_id=?")
            ->execute([$role, $ctype, $cid, $teamId, $pid]);
        TeamService::audit($teamId, 'member_updated', $pid, $before, $after);
    } else {
        db()->prepare("INSERT INTO task_team_members (team_id, user_id, added_by, added_at, role, contract_type, contract_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$teamId, $pid, $uid, $now, $role, $ctype, $cid]);
        TeamService::audit($teamId, 'member_added', $pid, null, $after);
    }
    TeamService::historyTouch($teamId, $pid, $after);
    task_api_ok(['user_id' => $pid]);
}

if ($action === 'member_remove') {
    team_require_manage($teamId);
    $pid = (int)($body['user_id'] ?? 0);
    $before = db_one("SELECT role, contract_type, contract_id FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, $pid]);
    if (!$before) team_fail('Tej osoby nie ma w zespole.', 404);
    if ($before['role'] === 'leader' && !is_admin()
        && (int)db_one("SELECT COUNT(*) c FROM task_team_members WHERE team_id=? AND role='leader'", [$teamId])['c'] <= 1) {
        team_fail('To jedyny lider — najpierw wskaż innego.', 409);
    }
    db()->prepare("DELETE FROM task_team_members WHERE team_id=? AND user_id=?")->execute([$teamId, $pid]);
    TeamService::historyTouch($teamId, $pid, null);
    TeamService::audit($teamId, 'member_removed', $pid, $before, null);
    task_api_ok(['user_id' => $pid]);
}

if ($action === 'deputy_set') {
    team_require_manage($teamId);
    $pid = (int)($body['user_id'] ?? 0);
    $dep = (int)($body['deputy_user_id'] ?? 0);
    $before = db_one("SELECT deputy_user_id, deputy_from, deputy_to, deputy_note FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, $pid]);
    if (!$before) team_fail('Tej osoby nie ma w zespole.', 404);
    if (!team_user_exists($dep)) team_fail('Wybierz zastępcę z listy.', 422, ['errors' => ['deputy_user_id' => 'Wybierz zastępcę z listy.']]);
    if ($dep === $pid) team_fail('Osoba nie może zastępować samej siebie.', 422, ['errors' => ['deputy_user_id' => 'Wybierz inną osobę.']]);
    $from = team_date($body, 'deputy_from', 'Od');
    $to   = team_date($body, 'deputy_to', 'Do');
    if ($from && $to && $to < $from) team_fail('Data „do” nie może być przed „od”.', 422, ['errors' => ['deputy_to' => 'Data „do” nie może być przed „od”.']]);
    $after = ['deputy_user_id' => $dep, 'deputy_from' => $from, 'deputy_to' => $to, 'deputy_note' => trim((string)($body['deputy_note'] ?? ''))];
    db()->prepare("UPDATE task_team_members SET deputy_user_id=?, deputy_from=?, deputy_to=?, deputy_note=? WHERE team_id=? AND user_id=?")
        ->execute([$dep, $from, $to, $after['deputy_note'], $teamId, $pid]);
    TeamService::audit($teamId, 'deputy_set', $pid, $before, $after);
    task_api_ok([]);
}

if ($action === 'deputy_clear') {
    team_require_manage($teamId);
    $pid = (int)($body['user_id'] ?? 0);
    $before = db_one("SELECT deputy_user_id, deputy_from, deputy_to, deputy_note FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, $pid]);
    if (!$before) team_fail('Tej osoby nie ma w zespole.', 404);
    db()->prepare("UPDATE task_team_members SET deputy_user_id=NULL, deputy_from=NULL, deputy_to=NULL, deputy_note='' WHERE team_id=? AND user_id=?")
        ->execute([$teamId, $pid]);
    TeamService::audit($teamId, 'deputy_cleared', $pid, $before, null);
    task_api_ok([]);
}

// ── Umowy zespołu ───────────────────────────────────────────────────────────────
if ($action === 'contract_link') {
    team_require_manage($teamId);
    $c = team_contract($body);
    $zakres = mb_substr(trim((string)($body['zakres'] ?? '')), 0, 255);
    db()->prepare("INSERT INTO contract_teams (contract_type, contract_id, team_id, zakres, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)
                   ON CONFLICT(contract_type, contract_id, team_id) DO UPDATE SET zakres=excluded.zakres")
        ->execute([$c['contract_type'], (int)$c['id'], $teamId, $zakres, $uid, $now]);
    TeamService::audit($teamId, 'contract_linked', null, null,
        ['contract_type' => $c['contract_type'], 'contract_id' => (int)$c['id'], 'numer' => $c['nr_rejestru'] ?: $c['numer_umowy'], 'zakres' => $zakres]);
    // Wykonawca umowy: konto SZO o tym samym e-mailu — propozycja (albo od razu, gdy add_person=1) dopisania do składu.
    $person = null;
    $email = strtolower(trim((string)($c['email'] ?? '')));
    if ($email !== '') {
        $person = db_one("SELECT id, name FROM users WHERE LOWER(email)=? AND COALESCE(is_active,1)=1", [$email]);
    }
    $added = false;
    if ($person && !db_one("SELECT 1 FROM task_team_members WHERE team_id=? AND user_id=?", [$teamId, (int)$person['id']])) {
        if (!empty($body['add_person'])) {
            $after = ['role' => 'member', 'contract_type' => $c['contract_type'], 'contract_id' => (int)$c['id']];
            db()->prepare("INSERT INTO task_team_members (team_id, user_id, added_by, added_at, role, contract_type, contract_id)
                           VALUES (?, ?, ?, ?, 'member', ?, ?)")->execute([$teamId, (int)$person['id'], $uid, $now, $c['contract_type'], (int)$c['id']]);
            TeamService::historyTouch($teamId, (int)$person['id'], $after);
            TeamService::audit($teamId, 'member_added', (int)$person['id'], null, $after + ['z_umowy' => true]);
            $added = true;
        }
    } else {
        $person = null;
    }
    task_api_ok(['suggest_person' => $person && !$added ? ['id' => (int)$person['id'], 'name' => $person['name']] : null, 'person_added' => $added]);
}

if ($action === 'contract_unlink') {
    team_require_manage($teamId);
    $type = (string)($body['contract_type'] ?? '');
    $cid  = (int)($body['contract_id'] ?? 0);
    $row = db_one("SELECT zakres FROM contract_teams WHERE team_id=? AND contract_type=? AND contract_id=?", [$teamId, $type, $cid]);
    if (!$row) team_fail('Ta umowa nie jest przypięta do zespołu.', 404);
    db()->prepare("DELETE FROM contract_teams WHERE team_id=? AND contract_type=? AND contract_id=?")->execute([$teamId, $type, $cid]);
    TeamService::audit($teamId, 'contract_unlinked', null, ['contract_type' => $type, 'contract_id' => $cid, 'zakres' => $row['zakres']], null);
    task_api_ok([]);
}

// ── Obszary zadań (task_workspace_teams) ─────────────────────────────────────────
if (in_array($action, ['workspace_link', 'workspace_unlink'], true)) {
    team_require_manage($teamId);
    $wsId = (int)($body['workspace_id'] ?? 0);
    $ws = db_one("SELECT id, name FROM task_workspaces WHERE id=? AND is_active=1", [$wsId]);
    if (!$ws) team_fail('Nie ma takiego obszaru zadań.', 404);
    if (!in_array(task_workspace_role($wsId, $uid), ['admin', 'editor'], true)) {
        team_fail('Do zmiany potrzebujesz roli administratora albo edytora w tym obszarze.', 403);
    }
    if ($action === 'workspace_link') {
        $role = in_array($body['role'] ?? '', TASK_WS_ROLES, true) ? (string)$body['role'] : 'member';
        $before = db_one("SELECT role FROM task_workspace_teams WHERE workspace_id=? AND team_id=?", [$wsId, $teamId]);
        db()->prepare("INSERT INTO task_workspace_teams (workspace_id, team_id, role, added_by, added_at) VALUES (?, ?, ?, ?, ?)
                       ON CONFLICT(workspace_id, team_id) DO UPDATE SET role=excluded.role")->execute([$wsId, $teamId, $role, $uid, $now]);
        TeamService::audit($teamId, 'workspace_linked', null, $before ? ['obszar' => $ws['name'], 'rola' => $before['role']] : null, ['obszar' => $ws['name'], 'rola' => $role]);
    } else {
        db()->prepare("DELETE FROM task_workspace_teams WHERE workspace_id=? AND team_id=?")->execute([$wsId, $teamId]);
        TeamService::audit($teamId, 'workspace_unlinked', null, ['obszar' => $ws['name']], null);
    }
    task_api_ok([]);
}

if ($action === 'workspace_create') {
    $team = team_require_manage($teamId);
    $name = trim((string)($body['name'] ?? '')) ?: (string)$team['name'];
    $base = preg_replace('/-+/', '-', trim(preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($name)), '-')) ?: 'obszar';
    $slug = $base; $n = 1;
    while (db_one("SELECT id FROM task_workspaces WHERE slug=?", [$slug])) $slug = $base . '-' . $n++;
    $wsId = db_insert('task_workspaces', [
        'slug' => $slug, 'name' => $name, 'description' => 'Obszar zespołu „' . $team['name'] . '”',
        'color' => (string)($team['color'] ?: '#1E6DFF'), 'icon' => 'bi-kanban', 'is_active' => 1,
        'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach ([['Nowe', 1, 0, '#e2e8f0'], ['W trakcie', 2, 0, '#2563eb'], ['Do weryfikacji', 3, 0, '#f59e0b'], ['Gotowe', 4, 1, '#16a34a']] as [$ln, $pos, $done, $lc]) {
        db_insert('task_lists', ['workspace_id' => $wsId, 'name' => $ln, 'position' => $pos, 'color' => $lc, 'is_done_state' => $done, 'created_at' => $now, 'updated_at' => $now]);
    }
    db()->prepare("INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at) VALUES (?, ?, 'admin', ?, ?)")
        ->execute([$wsId, $uid, $uid, $now]);
    db()->prepare("INSERT INTO task_workspace_teams (workspace_id, team_id, role, added_by, added_at) VALUES (?, ?, 'editor', ?, ?)")
        ->execute([$wsId, $teamId, $uid, $now]);
    TeamService::audit($teamId, 'workspace_created', null, null, ['obszar' => $name, 'rola' => 'editor']);
    task_api_ok(['workspace_id' => $wsId, 'url' => APP_URL . '/tasks/index.php?ws=' . $wsId]);
}

team_fail('Nieznana akcja.', 400);
