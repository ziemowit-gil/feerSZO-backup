<?php
/**
 * modules/tasks_plus/lib/tasksSla.php — SLA zadań i automatyczne eskalacje.
 *
 * Polityka (task_sla_policies) per obszar i priorytet (priority 0 = każdy priorytet bez własnej reguły):
 *   resolve_hours   — czas na realizację od utworzenia, gdy zadanie NIE ma terminu (NULL = tylko zadania z terminem),
 *   grace_hours     — ile godzin po przekroczeniu terminu czekać z eskalacją do liderów (poziom 1),
 *   level2_hours    — ile godzin po poziomie 1 eskalować do wskazanej osoby (poziom 2; NULL = brak),
 *   level2_user_id  — adresat poziomu 2 (np. kierownik).
 * Termin efektywny: termin zadania (data + godzina) albo utworzenie + resolve_hours.
 * Eskalacje zapisywane w task_sla_escalations (raz na poziom); przesunięcie terminu na później
 * kasuje zapisane eskalacje — zadanie wraca pod nadzór od nowa.
 */
require_once __DIR__ . '/tasksPlus.php';

const TASKS_SLA_RISK_HOURS = 24;

function tasksSlaMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS task_sla_policies (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id   INTEGER NOT NULL REFERENCES task_workspaces(id) ON DELETE CASCADE,
        priority       INTEGER NOT NULL DEFAULT 0,
        resolve_hours  REAL,
        grace_hours    REAL NOT NULL DEFAULT 0,
        level2_hours   REAL,
        level2_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        is_active      INTEGER NOT NULL DEFAULT 1,
        updated_by     INTEGER,
        updated_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(workspace_id, priority)
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS task_sla_escalations (
        task_id      INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
        level        INTEGER NOT NULL,
        deadline_at  TEXT NOT NULL,
        escalated_at TEXT NOT NULL,
        notified     TEXT,
        PRIMARY KEY (task_id, level)
    )");
}

/** Polityki obszaru: [priority => wiersz]. */
function tasksSlaPolicies(int $wsId): array
{
    tasksSlaMigrate();
    $out = [];
    foreach (db_all("SELECT * FROM task_sla_policies WHERE workspace_id=?", [$wsId]) as $p) $out[(int)$p['priority']] = $p;
    return $out;
}

/** Zapis polityk obszaru z formularza: $rows[priority] = [active, resolve_hours, grace_hours, level2_hours, level2_user_id]. */
function tasksSlaSavePolicies(int $wsId, array $rows, int $byUid): void
{
    tasksSlaMigrate();
    $num = fn($v) => ($v = trim(str_replace(',', '.', (string)$v))) === '' ? null : max(0, round((float)$v, 2));
    $st = db()->prepare("INSERT INTO task_sla_policies (workspace_id, priority, resolve_hours, grace_hours, level2_hours, level2_user_id, is_active, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON CONFLICT(workspace_id, priority) DO UPDATE SET resolve_hours=excluded.resolve_hours, grace_hours=excluded.grace_hours,
          level2_hours=excluded.level2_hours, level2_user_id=excluded.level2_user_id, is_active=excluded.is_active,
          updated_by=excluded.updated_by, updated_at=excluded.updated_at");
    $existing = null;
    foreach ([0, 1, 2, 3, 4] as $p) {
        $r = (array)($rows[$p] ?? []);
        $active = !empty($r['active']);
        $level2User = (int)($r['level2_user_id'] ?? 0) ?: null;
        $level2Hours = $num($r['level2_hours'] ?? '');
        if ($level2Hours !== null && !$level2User) throw new \RuntimeException('Poziom 2 wymaga wskazania osoby (' . tasksSlaPriorityName($p) . ').');
        $existing = $existing ?? tasksSlaPolicies($wsId);
        $empty = $num($r['resolve_hours'] ?? '') === null && $num($r['grace_hours'] ?? '') === null && $level2Hours === null;
        if (!$active && $empty && !isset($existing[$p])) continue; // nic nie skonfigurowano — bez pustych wierszy
        $st->execute([$wsId, $p, $num($r['resolve_hours'] ?? ''), $num($r['grace_hours'] ?? '') ?? 0, $level2Hours,
                      $level2User, $active ? 1 : 0, $byUid, date('Y-m-d H:i:s')]);
    }
}

function tasksSlaPriorityName(int $p): string
{
    return $p === 0 ? 'Każdy priorytet' : tasksPlusPriorityLabel($p);
}

/** Polityka dla zadania: własna dla priorytetu albo ogólna (0); tylko aktywne. */
function tasksSlaPolicyFor(array $policies, int $priority): ?array
{
    foreach ([$priority, 0] as $p) {
        if (isset($policies[$p]) && (int)$policies[$p]['is_active'] === 1) return $policies[$p];
    }
    return null;
}

/** Termin efektywny (timestamp) albo null, gdy polityka go nie wyznacza. */
function tasksSlaDeadline(array $task, ?array $policy): ?int
{
    if ($ts = task_due_timestamp($task)) return $ts;
    if ($policy && $policy['resolve_hours'] !== null && $policy['resolve_hours'] !== '') {
        return strtotime((string)$task['created_at']) + (int)round((float)$policy['resolve_hours'] * 3600);
    }
    return null;
}

/**
 * Otwarte zadania pod nadzorem SLA w podanych obszarach, z wyliczonym stanem:
 * ok | risk (mniej niż 24 h) | breach (po terminie) | escalated (wysłano eskalację).
 */
function tasksSlaBoard(array $wsIds): array
{
    tasksSlaMigrate();
    task_extras_schema_heal();
    if (!$wsIds) return [];
    $policies = [];
    foreach ($wsIds as $id) $policies[$id] = tasksSlaPolicies((int)$id);
    $tasks = db_all(
        "SELECT t.*, tl.name AS list_name, w.name AS ws_name, w.color AS ws_color,
                (SELECT GROUP_CONCAT(u.name, ', ') FROM task_assignments a JOIN users u ON u.id=a.user_id WHERE a.task_id=t.id) AS assignees,
                (SELECT MAX(level) FROM task_sla_escalations e WHERE e.task_id=t.id) AS esc_level
         FROM tasks t JOIN task_lists tl ON tl.id=t.list_id JOIN task_workspaces w ON w.id=t.workspace_id
         WHERE t.deleted_at IS NULL AND t.archived_at IS NULL AND t.completed_at IS NULL AND tl.is_done_state=0
           AND t.workspace_id IN (" . tasksPlusIn($wsIds) . ")",
        array_values($wsIds)
    );
    $now = time();
    $out = [];
    foreach ($tasks as $t) {
        $pol = tasksSlaPolicyFor($policies[(int)$t['workspace_id']] ?? [], (int)$t['priority']);
        if (!$pol) continue;
        $dl = tasksSlaDeadline($t, $pol);
        if ($dl === null) continue;
        $t['sla_deadline'] = $dl;
        $t['sla_from_due'] = (bool)task_due_timestamp($t);
        $t['sla_left_h'] = round(($dl - $now) / 3600, 1);
        $t['sla_state'] = $t['esc_level'] ? 'escalated' : ($dl < $now ? 'breach' : (($dl - $now) < TASKS_SLA_RISK_HOURS * 3600 ? 'risk' : 'ok'));
        $out[] = $t;
    }
    usort($out, fn($a, $b) => $a['sla_deadline'] <=> $b['sla_deadline']);
    return $out;
}

/**
 * Przebieg eskalacji (cron co godzinę albo „Sprawdź teraz”). $wsIds = null → wszystkie obszary z polityką.
 * Zwraca listę wykonanych eskalacji [task_id, level, to].
 */
function tasksSlaRun(?array $wsIds = null, bool $dryRun = false): array
{
    tasksSlaMigrate();
    if ($wsIds === null) {
        $wsIds = array_map('intval', array_column(db_all(
            "SELECT DISTINCT p.workspace_id FROM task_sla_policies p JOIN task_workspaces w ON w.id=p.workspace_id
             WHERE p.is_active=1 AND w.is_active=1"), 'workspace_id'));
    }
    $done = [];
    $now = time();
    foreach (tasksSlaBoard($wsIds) as $t) {
        $tid = (int)$t['id'];
        $pol = tasksSlaPolicyFor(tasksSlaPolicies((int)$t['workspace_id']), (int)$t['priority']);
        $dl = (int)$t['sla_deadline'];
        $esc = [];
        foreach (db_all("SELECT * FROM task_sla_escalations WHERE task_id=?", [$tid]) as $e) $esc[(int)$e['level']] = $e;

        // Termin przesunięty na później niż przy eskalacji → nadzór od nowa
        if ($esc && strtotime((string)$esc[min(array_keys($esc))]['deadline_at']) < $dl) {
            if (!$dryRun) db()->prepare("DELETE FROM task_sla_escalations WHERE task_id=?")->execute([$tid]);
            $esc = [];
            if ($dl > $now) continue;
        }

        if (!isset($esc[1]) && $now >= $dl + (int)round((float)$pol['grace_hours'] * 3600)) {
            $to = array_map(fn($u) => (int)$u['id'], tasksPlusWorkspaceLeaders((int)$t['workspace_id']));
            if (!$to) $to = [(int)$t['created_by']];
            if (!$dryRun) tasksSlaEscalate($t, 1, $dl, $to);
            $done[] = ['task_id' => $tid, 'level' => 1, 'to' => $to];
            continue;
        }
        if (isset($esc[1]) && !isset($esc[2]) && $pol['level2_hours'] !== null && (int)$pol['level2_user_id'] > 0
            && $now >= strtotime((string)$esc[1]['escalated_at']) + (int)round((float)$pol['level2_hours'] * 3600)) {
            $to = [(int)$pol['level2_user_id']];
            if (!$dryRun) tasksSlaEscalate($t, 2, $dl, $to);
            $done[] = ['task_id' => $tid, 'level' => 2, 'to' => $to];
        }
    }
    return $done;
}

/** Zapis eskalacji + historia zadania + powiadomienia (dzwonek zawsze, e-mail wg preferencji). */
function tasksSlaEscalate(array $t, int $level, int $deadline, array $toUids): void
{
    $tid = (int)$t['id'];
    $ins = db()->prepare("INSERT OR IGNORE INTO task_sla_escalations (task_id, level, deadline_at, escalated_at, notified) VALUES (?,?,?,?,?)");
    $ins->execute([$tid, $level, date('Y-m-d H:i:s', $deadline), date('Y-m-d H:i:s'), json_encode(array_values($toUids))]);
    if (!$ins->rowCount()) return; // równoległy przebieg już eskalował

    $late = tasksSlaLateLabel(time() - $deadline);
    task_log($tid, (int)$t['created_by'], 'sla_escalated', null, 'Poziom ' . $level . ' — po terminie ' . $late,
        ['level' => $level, 'deadline' => date('Y-m-d H:i', $deadline), 'notified' => array_values($toUids)]);

    require_once dirname(__DIR__, 3) . '/includes/task_notify.php';
    $task = _tn_task($tid);
    if (!$task) return;
    $title = 'Eskalacja SLA (poziom ' . $level . '): ' . $task['title'];
    $body = 'Termin minął ' . date('d.m.Y H:i', $deadline) . ' (' . $late . ' temu), zadanie nadal otwarte.';
    foreach (array_unique($toUids) as $uid) {
        $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$uid]);
        if (!$u) continue;
        _tn_inapp($uid, $title, $body, $tid);
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p>Zadanie przekroczyło termin SLA i <strong>nie zostało ukończone</strong>. '
            . 'Termin: <strong>' . date('d.m.Y H:i', $deadline) . '</strong> (' . htmlspecialchars($late) . ' temu).'
            . ($t['assignees'] ? ' Wykonawcy: ' . htmlspecialchars((string)$t['assignees']) . '.' : ' Zadanie nie ma wykonawcy.')
            . '</p>' . _tn_task_card($task)
            . '<p style="color:#64748b;font-size:13px">To automatyczna eskalacja poziomu ' . $level . ' (obszar: '
            . htmlspecialchars((string)$t['ws_name']) . ').</p>';
        _tn_email($uid, $u['email'], 'sla_escalated', $tid, $title, _tn_tpl('Eskalacja SLA', $title, $content, _tn_task_url($tid)));
    }
    // Wykonawcy dostają informację w dzwonku (bez e-maila), że zadanie zostało eskalowane
    foreach (_tn_assignees_except($tid, 0) as $a) {
        if (in_array((int)$a['id'], $toUids, true)) continue;
        _tn_inapp((int)$a['id'], 'Zadanie po terminie eskalowane: ' . $task['title'], $body, $tid);
    }
}

/** „3 dni 4 godz.” / „5 godz.” / „20 min”. */
function tasksSlaLateLabel(int $secs): string
{
    $secs = abs($secs);
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    if ($d) return $d . ' ' . ($d === 1 ? 'dzień' : 'dni') . ($h ? ' ' . $h . ' godz.' : '');
    if ($h) return $h . ' godz.';
    return max(1, intdiv($secs, 60)) . ' min';
}
