<?php
/**
 * tasks/lib/tasksRules.php — reguły automatyzacji obszaru („gdy … → zrób …”).
 *
 * Wyzwalacze: created (nowe zadanie, też z szablonu), moved (zadanie trafiło do kolumny; opcjonalnie konkretnej),
 * completed (zadanie ukończone). Warunek opcjonalny: priorytet.
 * Akcje: assign (przypisz osobę), set_priority, notify (osoba / liderzy / wykonawcy — dzwonek + e-mail),
 * move (przenieś do kolumny), set_due (termin za N dni, tylko gdy brak terminu), watch (dodaj obserwującego),
 * tag (dodaj tag).
 * Zmiany wykonane przez regułę NIE uruchamiają kolejnych reguł (bez kaskad i pętli). Każde zadziałanie
 * zapisuje się w historii zadania jako „Reguła automatyzacji”.
 * Wpięcie: task_rules_fire() w includes/tasks.php (task_move, szablony), tasks/api/task.php, tasks/api/bulk.php.
 */
require_once __DIR__ . '/tasksPlus.php';

const TASKS_RULE_TRIGGERS = [
    'created'   => 'Utworzono zadanie',
    'moved'     => 'Zadanie trafiło do kolumny',
    'completed' => 'Zadanie ukończono',
];
const TASKS_RULE_ACTIONS = [
    'assign'       => 'Przypisz osobę',
    'set_priority' => 'Ustaw priorytet',
    'notify'       => 'Powiadom',
    'move'         => 'Przenieś do kolumny',
    'set_due'      => 'Ustaw termin (jeśli brak)',
    'watch'        => 'Dodaj obserwującego',
    'tag'          => 'Dodaj tag',
];

function tasksRulesMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS task_rules (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id    INTEGER NOT NULL REFERENCES task_workspaces(id) ON DELETE CASCADE,
        name            TEXT NOT NULL,
        trigger_type    TEXT NOT NULL,
        trigger_list_id INTEGER REFERENCES task_lists(id) ON DELETE CASCADE,
        cond_priority   INTEGER NOT NULL DEFAULT 0,
        action_type     TEXT NOT NULL,
        action_value    TEXT NOT NULL DEFAULT '',
        is_active       INTEGER NOT NULL DEFAULT 1,
        fire_count      INTEGER NOT NULL DEFAULT 0,
        last_fired_at   TEXT,
        created_by      INTEGER NOT NULL REFERENCES users(id),
        created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_task_rules_ws ON task_rules(workspace_id, trigger_type, is_active)");
}

function tasksRulesList(int $wsId): array
{
    tasksRulesMigrate();
    return db_all("SELECT r.*, l.name AS trigger_list_name FROM task_rules r LEFT JOIN task_lists l ON l.id=r.trigger_list_id
                   WHERE r.workspace_id=? ORDER BY r.id", [$wsId]);
}

/** Słowniki do formularza i opisów: kolumny, osoby, tagi obszaru. */
function tasksRulesOptions(int $wsId): array
{
    task_teams_migrate();
    return [
        'lists' => db_all("SELECT id, name FROM task_lists WHERE workspace_id=? ORDER BY position", [$wsId]),
        'people' => db_all(
            "SELECT id, name FROM users WHERE is_active=1 AND id IN (
                 SELECT user_id FROM task_workspace_members WHERE workspace_id=?
                 UNION SELECT tm.user_id FROM task_workspace_teams wt JOIN task_team_members tm ON tm.team_id=wt.team_id WHERE wt.workspace_id=?
             ) ORDER BY name", [$wsId, $wsId]),
        'tags' => db_all("SELECT id, name FROM task_tags WHERE is_active=1 AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name", [$wsId]),
    ];
}

/** Walidacja i zapis nowej reguły. */
function tasksRulesCreate(int $wsId, array $in, int $byUid): int
{
    tasksRulesMigrate();
    $name = trim((string)($in['name'] ?? ''));
    $trigger = (string)($in['trigger_type'] ?? '');
    $action = (string)($in['action_type'] ?? '');
    if (!isset(TASKS_RULE_TRIGGERS[$trigger])) throw new \RuntimeException('Wybierz wyzwalacz.');
    if (!isset(TASKS_RULE_ACTIONS[$action])) throw new \RuntimeException('Wybierz akcję.');
    $opt = tasksRulesOptions($wsId);
    $listIds = array_map('intval', array_column($opt['lists'], 'id'));
    $listId = (int)($in['trigger_list_id'] ?? 0) ?: null;
    if ($trigger !== 'moved') $listId = null;
    if ($listId && !in_array($listId, $listIds, true)) throw new \RuntimeException('Nieznana kolumna wyzwalacza.');
    $prio = max(0, min(4, (int)($in['cond_priority'] ?? 0)));

    $value = trim((string)($in['value_' . $action] ?? ''));
    $ok = match ($action) {
        'assign', 'watch' => in_array((int)$value, array_map('intval', array_column($opt['people'], 'id')), true),
        'notify'          => in_array($value, ['leaders', 'assignees'], true)
                             || in_array((int)$value, array_map('intval', array_column($opt['people'], 'id')), true),
        'set_priority'    => in_array((int)$value, [1, 2, 3, 4], true),
        'move'            => in_array((int)$value, $listIds, true) && !($trigger === 'moved' && (int)$value === $listId),
        'set_due'         => ctype_digit($value) && (int)$value >= 0 && (int)$value <= 365,
        'tag'             => in_array((int)$value, array_map('intval', array_column($opt['tags'], 'id')), true),
    };
    if (!$ok) throw new \RuntimeException('Uzupełnij poprawnie parametr akcji „' . TASKS_RULE_ACTIONS[$action] . '”.');
    if ($name === '') $name = TASKS_RULE_TRIGGERS[$trigger] . ' → ' . TASKS_RULE_ACTIONS[$action];

    return db_insert('task_rules', [
        'workspace_id' => $wsId, 'name' => mb_substr($name, 0, 120), 'trigger_type' => $trigger,
        'trigger_list_id' => $listId, 'cond_priority' => $prio, 'action_type' => $action, 'action_value' => $value,
        'is_active' => 1, 'created_by' => $byUid, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** Czytelny opis reguły: „Gdy … → …”. */
function tasksRulesDescribe(array $r, array $opt): string
{
    $name = fn(array $rows, $id) => (string)(array_column($rows, 'name', 'id')[(int)$id] ?? '#' . $id);
    $when = $r['trigger_type'] === 'moved'
        ? ($r['trigger_list_id'] ? 'Zadanie trafiło do kolumny „' . ($r['trigger_list_name'] ?? '') . '”' : 'Zadanie zmieniło kolumnę')
        : TASKS_RULE_TRIGGERS[$r['trigger_type']] ?? $r['trigger_type'];
    if ((int)$r['cond_priority']) $when .= ' (priorytet: ' . tasksPlusPriorityLabel((int)$r['cond_priority']) . ')';
    $v = $r['action_value'];
    $then = match ($r['action_type']) {
        'assign'       => 'przypisz: ' . $name($opt['people'], $v),
        'watch'        => 'dodaj obserwującego: ' . $name($opt['people'], $v),
        'notify'       => 'powiadom: ' . ($v === 'leaders' ? 'liderów obszaru' : ($v === 'assignees' ? 'wykonawców' : $name($opt['people'], $v))),
        'set_priority' => 'ustaw priorytet: ' . tasksPlusPriorityLabel((int)$v),
        'move'         => 'przenieś do kolumny: ' . $name($opt['lists'], $v),
        'set_due'      => 'ustaw termin za ' . (int)$v . ' dni (gdy brak)',
        'tag'          => 'dodaj tag: ' . $name($opt['tags'], $v),
        default        => $r['action_type'],
    };
    return $when . ' → ' . $then;
}

/**
 * Uruchamia reguły obszaru dla zdarzenia. $ctx['list_id'] — kolumna docelowa (moved).
 * Akcje wykonane przez regułę nie uruchamiają kolejnych reguł.
 */
function tasksRulesFire(string $trigger, int $taskId, int $byUid, array $ctx = []): void
{
    static $depth = 0;
    if ($depth > 0 || !isset(TASKS_RULE_TRIGGERS[$trigger])) return;
    tasksRulesMigrate();
    $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$taskId]);
    if (!$task) return;
    $rules = db_all("SELECT * FROM task_rules WHERE workspace_id=? AND trigger_type=? AND is_active=1 ORDER BY id",
        [(int)$task['workspace_id'], $trigger]);
    if (!$rules) return;

    $depth++;
    try {
        foreach ($rules as $r) {
            if ($trigger === 'moved' && $r['trigger_list_id'] && (int)$r['trigger_list_id'] !== (int)($ctx['list_id'] ?? 0)) continue;
            $task = db_one("SELECT * FROM tasks WHERE id=? AND deleted_at IS NULL", [$taskId]);
            if (!$task) break;
            if ((int)$r['cond_priority'] && (int)$r['cond_priority'] !== (int)$task['priority']) continue;
            try {
                $what = tasksRulesApply($r, $task, $byUid);
                if ($what === null) continue; // nic do zrobienia (np. już przypisany)
                task_log($taskId, $byUid, 'rule_applied', null, $r['name'] . ': ' . $what, ['rule_id' => (int)$r['id']]);
                db()->prepare("UPDATE task_rules SET fire_count=fire_count+1, last_fired_at=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
            } catch (\Throwable $e) {
                error_log('[task_rules] reguła #' . $r['id'] . ' zadanie #' . $taskId . ': ' . $e->getMessage());
            }
        }
    } finally {
        $depth--;
    }
}

/** Wykonuje akcję; zwraca opis zmiany albo null, gdy nic nie zmieniono. */
function tasksRulesApply(array $r, array $task, int $byUid): ?string
{
    $tid = (int)$task['id'];
    $ws = (int)$task['workspace_id'];
    $v = (string)$r['action_value'];
    $now = date('Y-m-d H:i:s');
    require_once dirname(__DIR__, 2) . '/includes/task_notify.php';

    switch ($r['action_type']) {
        case 'assign':
            $u = db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [(int)$v]);
            if (!$u) return null;
            $st = db()->prepare("INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,?)");
            $st->execute([$tid, (int)$u['id'], $byUid, $now]);
            if (!$st->rowCount()) return null;
            db()->prepare("INSERT OR IGNORE INTO task_workspace_members (workspace_id, user_id, role, added_by, added_at)
                           VALUES (?, ?, 'member', ?, datetime('now','localtime'))")->execute([$ws, (int)$u['id'], $byUid]);
            task_log($tid, $byUid, 'assigned', null, $u['name']);
            try { task_notify_assigned($tid, (int)$u['id'], $byUid); } catch (\Throwable $e) {}
            return 'przypisano ' . $u['name'];

        case 'set_priority':
            $p = max(1, min(4, (int)$v));
            if ((int)$task['priority'] === $p) return null;
            db()->prepare("UPDATE tasks SET priority=?, updated_at=? WHERE id=?")->execute([$p, $now, $tid]);
            task_log($tid, $byUid, 'priority_changed', tasksPlusPriorityLabel((int)$task['priority']), tasksPlusPriorityLabel($p));
            return 'priorytet ' . tasksPlusPriorityLabel($p);

        case 'move':
            if ((int)$task['list_id'] === (int)$v) return null;
            $list = db_one("SELECT id, name FROM task_lists WHERE id=? AND workspace_id=?", [(int)$v, $ws]);
            if (!$list) return null;
            $pos = (float)(db_one("SELECT MAX(position) AS m FROM tasks WHERE list_id=? AND deleted_at IS NULL", [(int)$v])['m'] ?? 0) + 1;
            task_move($tid, (int)$v, $pos, $byUid);
            return 'przeniesiono do „' . $list['name'] . '”';

        case 'set_due':
            if (!empty($task['due_date'])) return null;
            $due = date('Y-m-d', strtotime('+' . (int)$v . ' days'));
            db()->prepare("UPDATE tasks SET due_date=?, updated_at=? WHERE id=?")->execute([$due, $now, $tid]);
            task_log($tid, $byUid, 'due_changed', null, $due);
            return 'termin ' . date('d.m.Y', strtotime($due));

        case 'watch':
            $u = db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [(int)$v]);
            if (!$u || in_array((int)$u['id'], array_map('intval', array_column(task_watchers($tid), 'id')), true)) return null;
            task_watch_set($tid, (int)$u['id'], true);
            return 'obserwuje ' . $u['name'];

        case 'tag':
            $tag = db_one("SELECT id, name FROM task_tags WHERE id=?", [(int)$v]);
            if (!$tag) return null;
            $st = db()->prepare("INSERT OR IGNORE INTO task_task_tags (task_id, tag_id, assigned_by, assigned_at) VALUES (?,?,?,?)");
            $st->execute([$tid, (int)$tag['id'], $byUid, $now]);
            if (!$st->rowCount()) return null;
            task_log($tid, $byUid, 'tag_added', null, $tag['name']);
            return 'tag ' . $tag['name'];

        case 'notify':
            $to = match ($v) {
                'leaders'   => tasksPlusWorkspaceLeaders($ws),
                'assignees' => _tn_assignees_except($tid, 0),
                default     => db_all("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [(int)$v]),
            };
            $full = _tn_task($tid);
            if (!$to || !$full) return null;
            $title = 'Reguła „' . $r['name'] . '”: ' . $full['title'];
            $n = 0;
            foreach ($to as $u) {
                $uid = (int)$u['id'];
                if ($uid === $byUid) continue;
                $n++;
                _tn_inapp($uid, $title, 'Zadanie w kolumnie „' . $full['list_name'] . '”.', $tid);
                $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                    . '<p>Reguła automatyzacji obszaru <strong>' . htmlspecialchars($r['name']) . '</strong> powiadamia o zadaniu:</p>'
                    . _tn_task_card($full);
                _tn_email($uid, $u['email'], 'rule_notify', $tid, $title, _tn_tpl('Reguła automatyzacji', $title, $content, _tn_task_url($tid)));
            }
            return $n ? 'powiadomiono (' . $n . ')' : null;
    }
    return null;
}
