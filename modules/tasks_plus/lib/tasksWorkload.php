<?php
/**
 * modules/tasks_plus/lib/tasksWorkload.php — obciążenie zespołu: zadania i godziny per osoba.
 *
 * Zakres widoczności: administrator — wszystkie obszary; lider — obszary, w których jest liderem
 * (wszyscy wykonawcy zadań tych obszarów); pozostali — tylko własne obciążenie.
 * Godziny szacowane zadania z kilkoma wykonawcami dzielone są po równo.
 */
require_once __DIR__ . '/tasksPlus.php';

const TASKS_WORKLOAD_WEEKS = 6;

/**
 * Raport. $f: ws (0 = wszystkie w zakresie), from, to (okres dla „zalogowane” i „ukończone”).
 * @return array{people: array, weeks: array, scope: string, wsIds: array}
 */
function tasksWorkloadReport(int $uid, array $f): array
{
    task_extras_schema_heal();
    $all = tasksPlusWorkspaces($uid);
    $lead = array_filter($all, fn($w) => in_array($w['my_role'], ['admin', 'editor'], true));
    $scope = $lead ? 'team' : 'self';
    $wsIds = array_keys($scope === 'team' ? $lead : $all);
    $wsId = (int)($f['ws'] ?? 0);
    if ($wsId) $wsIds = array_values(array_intersect($wsIds, [$wsId]));
    $res = ['people' => [], 'weeks' => tasksWorkloadWeeks(), 'scope' => $scope, 'wsIds' => $wsIds];
    if (!$wsIds) return $res;

    $in = tasksPlusIn($wsIds);
    $userCond = $scope === 'self' ? ' AND a.user_id=' . (int)$uid : '';
    $from = $f['from'];
    $to = $f['to'];

    // Otwarte zadania z wykonawcami (podział szacunku)
    $open = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date, t.due_time, t.estimated_hours, t.workspace_id, a.user_id,
                tl.name AS list_name, w.name AS ws_name,
                (SELECT COUNT(*) FROM task_assignments x WHERE x.task_id=t.id) AS n_assignees
         FROM tasks t
         JOIN task_assignments a ON a.task_id=t.id
         JOIN task_lists tl ON tl.id=t.list_id
         JOIN task_workspaces w ON w.id=t.workspace_id
         WHERE t.deleted_at IS NULL AND t.archived_at IS NULL AND t.completed_at IS NULL AND tl.is_done_state=0
           AND t.workspace_id IN ($in) $userCond
         ORDER BY t.due_date IS NULL, t.due_date, t.priority DESC",
        $wsIds
    );
    $weekStart = $res['weeks'][0]['from'];
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $people = [];
    $blank = fn() => ['open' => 0, 'overdue' => 0, 'this_week' => 0, 'no_due' => 0, 'est' => 0.0, 'no_est' => 0,
                      'logged' => 0.0, 'done' => 0, 'weeks' => array_fill(0, TASKS_WORKLOAD_WEEKS, ['n' => 0, 'h' => 0.0]), 'tasks' => []];
    foreach ($open as $t) {
        $p = &$people[(int)$t['user_id']];
        $p ??= $blank();
        $share = $t['estimated_hours'] !== null ? (float)$t['estimated_hours'] / max(1, (int)$t['n_assignees']) : null;
        $p['open']++;
        if (task_is_overdue($t)) $p['overdue']++;
        if (empty($t['due_date'])) $p['no_due']++;
        elseif ($t['due_date'] >= $weekStart && $t['due_date'] <= $weekEnd) $p['this_week']++;
        if ($share === null) $p['no_est']++; else $p['est'] += $share;
        // Rozkład na tygodnie wg terminu (po terminie → bieżący tydzień)
        if (!empty($t['due_date'])) {
            $i = $t['due_date'] < $weekStart ? 0 : intdiv((int)((strtotime($t['due_date']) - strtotime($weekStart)) / 86400), 7);
            if ($i < TASKS_WORKLOAD_WEEKS) {
                $p['weeks'][$i]['n']++;
                $p['weeks'][$i]['h'] += (float)$share;
            }
        }
        $t['share'] = $share;
        $p['tasks'][] = $t;
        unset($p);
    }

    // Zalogowany czas w okresie (wpisy zakończone, w zadaniach z obszarów w zakresie)
    $logged = db_all(
        "SELECT l.user_id, SUM(l.duration_seconds) AS secs FROM task_time_logs l JOIN tasks t ON t.id=l.task_id
         WHERE t.workspace_id IN ($in) AND l.duration_seconds IS NOT NULL
           AND date(COALESCE(l.ended_at, l.started_at)) BETWEEN ? AND ?"
           . ($scope === 'self' ? ' AND l.user_id=' . (int)$uid : '') . " GROUP BY l.user_id",
        array_merge($wsIds, [$from, $to])
    );
    foreach ($logged as $r) {
        $people[(int)$r['user_id']] ??= $blank();
        $people[(int)$r['user_id']]['logged'] = round((int)$r['secs'] / 3600, 2);
    }

    // Ukończone w okresie (jako wykonawca)
    $done = db_all(
        "SELECT a.user_id, COUNT(*) AS n FROM tasks t JOIN task_assignments a ON a.task_id=t.id
         WHERE t.deleted_at IS NULL AND t.workspace_id IN ($in) AND date(t.completed_at) BETWEEN ? AND ? $userCond
         GROUP BY a.user_id",
        array_merge($wsIds, [$from, $to])
    );
    foreach ($done as $r) {
        $people[(int)$r['user_id']] ??= $blank();
        $people[(int)$r['user_id']]['done'] = (int)$r['n'];
    }

    if ($people) {
        $names = db_all("SELECT id, name, is_active FROM users WHERE id IN (" . tasksPlusIn(array_keys($people)) . ")", array_keys($people));
        foreach ($names as $n) {
            $people[(int)$n['id']]['name'] = $n['name'];
            $people[(int)$n['id']]['active'] = (int)$n['is_active'] === 1;
        }
        foreach ($people as $id => &$p) {
            $p['id'] = $id;
            $p['name'] ??= 'Użytkownik #' . $id;
            $p['active'] ??= false;
            $p['est'] = round($p['est'], 2);
        }
        unset($p);
        uasort($people, fn($a, $b) => [$b['overdue'], $b['est'], $b['open']] <=> [$a['overdue'], $a['est'], $a['open']]);
    }
    $res['people'] = array_values($people);
    return $res;
}

/** Tygodnie od bieżącego poniedziałku. */
function tasksWorkloadWeeks(): array
{
    $mon = strtotime('monday this week');
    $out = [];
    for ($i = 0; $i < TASKS_WORKLOAD_WEEKS; $i++) {
        $s = strtotime('+' . ($i * 7) . ' days', $mon);
        $out[] = ['from' => date('Y-m-d', $s), 'label' => date('d.m', $s) . '–' . date('d.m', strtotime('+6 days', $s))];
    }
    return $out;
}

/** Eksport CSV (UTF-8 z BOM, średnik — otwiera się poprawnie w Excelu). */
function tasksWorkloadCsv(array $rep, array $f): string
{
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");
    $head = ['Osoba', 'Otwarte', 'Po terminie', 'Termin w tym tygodniu', 'Bez terminu', 'Szacowane godz. (otwarte)',
             'Bez szacunku', 'Zalogowane godz. ' . $f['from'] . '–' . $f['to'], 'Ukończone ' . $f['from'] . '–' . $f['to']];
    foreach ($rep['weeks'] as $w) $head[] = 'Tydz. ' . $w['label'] . ' (zad./godz.)';
    fputcsv($fh, $head, ';', '"', '');
    $num = fn($v) => str_replace('.', ',', (string)round((float)$v, 2));
    foreach ($rep['people'] as $p) {
        $row = [$p['name'], $p['open'], $p['overdue'], $p['this_week'], $p['no_due'], $num($p['est']), $p['no_est'],
                $num($p['logged']), $p['done']];
        foreach ($p['weeks'] as $w) $row[] = $w['n'] . ' / ' . $num($w['h']);
        fputcsv($fh, $row, ';', '"', '');
    }
    rewind($fh);
    return (string)stream_get_contents($fh);
}
